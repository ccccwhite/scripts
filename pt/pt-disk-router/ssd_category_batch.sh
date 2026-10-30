#!/bin/bash
set -uo pipefail
BASE="/boot/config/plugins/user.scripts/pt-disk-router"
SINGLE="$BASE/ssd_category_fix.php"
LOCK="$BASE/ssd_category_batch.lock"
APPLY=0
LIMIT=1
if [[ "${1:-}" == "--apply" ]]; then
    APPLY=1
    shift
    if [[ "${1:-}" == "--limit" ]]; then
        LIMIT="${2:-}"
        shift 2
    fi
fi
if (( $# != 0 )); then echo 'Usage: ssd_category_batch.sh [--apply [--limit N]]' >&2; exit 2; fi
if ! [[ "$LIMIT" =~ ^[0-9]+$ ]] || (( LIMIT < 1 || LIMIT > 25 )); then
    echo 'ERROR: limit must be 1..25' >&2; exit 2
fi
[[ -f "$SINGLE" ]] || { echo "ERROR: missing $SINGLE" >&2; exit 1; }
exec 8>"$LOCK"
flock -n 8 || { echo 'ERROR: another batch instance is running' >&2; exit 1; }
TMP=$(mktemp)
trap 'rm -f "$TMP" "$TMP.hashes"' EXIT
# API calls are performed inside qbittorrent: 127.0.0.1:8080 is valid there.
if ! docker exec qbittorrent curl -fsS --max-time 20 \
  'http://127.0.0.1:8080/api/v2/torrents/info?filter=all' >"$TMP"; then
    echo 'ERROR: failed to query qBittorrent' >&2; exit 1
fi
if ! php -r '
    $a=json_decode(stream_get_contents(STDIN),true);
    if(!is_array($a)){fwrite(STDERR,"ERROR: invalid torrent JSON\n");exit(1);}
    foreach($a as $t){
        $path=rtrim((string)($t["save_path"]??""),"/");
        $cat=(string)($t["category"]??"");
        $hash=strtolower((string)($t["hash"]??""));
        if(!preg_match("/^[a-f0-9]{40}$/D",$hash))continue;
        if(!preg_match("/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D",$cat))continue;
        if($path!=="/temp"&&!str_starts_with($path,"/temp/qbit/"))continue;
        if($path==="/temp/qbit/".$cat)continue;
        if(($t["auto_tmm"]??true))continue;
        $tags=array_map("trim",explode(",",(string)($t["tags"]??"")));
        $targets=array_values(array_filter($tags,fn($x)=>str_starts_with($x,"target:")));
        if($targets!==["target:ssd"])continue;
        echo $hash,"\n";
    }
' < "$TMP" > "$TMP.hashes"; then
    echo 'ERROR: cannot parse torrent list' >&2; exit 1
fi
mapfile -t HASHES < "$TMP.hashes"
printf 'MODE=%s CANDIDATES=%d LIMIT=%d\n' "$([[ "$APPLY" == 1 ]] && echo APPLY || echo DRY_RUN)" "${#HASHES[@]}" "$LIMIT"
count=0; failures=0
for hash in "${HASHES[@]}"; do
    echo
    echo "===== $hash ====="
    if ! php "$SINGLE" "$hash"; then
        echo "SKIP: preflight failed for $hash"
        ((failures+=1))
        continue
    fi
    if (( APPLY == 0 )); then continue; fi
    echo 'APPLY: starting single-torrent correction'
    if ! php "$SINGLE" "$hash" --apply; then
        echo "HALT: correction failed for $hash; inspect before any retry" >&2
        exit 1
    fi
    ((count+=1))
    if (( count >= LIMIT )); then break; fi
done
printf '\nCOMPLETED=%d PREFLIGHT_FAILURES=%d\n' "$count" "$failures"
if (( failures > 0 )); then exit 2; fi
