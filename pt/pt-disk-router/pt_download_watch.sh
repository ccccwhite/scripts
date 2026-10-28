#!/bin/bash
# Event-driven host watcher for PT Disk Router on Unraid 6.12+.
# Only the qBittorrent "torrent added" hook writes to the queue; this
# watcher responds immediately and retries pending events after tag edits.
set -uo pipefail

BASE="/boot/config/plugins/user.scripts/pt-disk-router"
QUEUE="/mnt/user/appdata/qbittorrent/pt-router/queue"
LOCK="/var/run/pt-disk-router-watch.lock"
RETRY="${PT_DISK_WATCH_RETRY:-30}"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
if ! [[ "$RETRY" =~ ^[0-9]+$ ]] || (( RETRY < 5 || RETRY > 600 )); then
    log "ERROR: PT_DISK_WATCH_RETRY must be 5..600 seconds"
    exit 1
fi
if ! command -v inotifywait >/dev/null || ! command -v flock >/dev/null; then
    log "ERROR: inotifywait or flock is missing"
    exit 1
fi
if ! [[ -r "$BASE/config.json" && -r "$BASE/pt_download_router.php" && -r "$BASE/script" ]]; then
    log "ERROR: router files not installed in $BASE"
    exit 1
fi

# Prevent two watchers; PHP has its own independent lock for each route run.
exec 9>"$LOCK"
if ! flock -n 9; then
    log "Watcher already active; exiting duplicate instance"
    exit 0
fi

trap 'log "Watcher exiting"' EXIT
trap 'exit 0' TERM INT

has_pending() {
    local f hash
    for f in "$QUEUE"/*; do
        [[ -f "$f" && ! -L "$f" ]] || continue
        hash="${f##*/}"
        [[ "$hash" =~ ^([a-f0-9]{40}|[a-f0-9]{64})$ ]] && return 0
    done
    return 1
}

run_pending() {
    # Never run while the array share is absent or qBittorrent is stopped.
    [[ -d /mnt/user/qbit && -d "$QUEUE" ]] || return 0
    has_pending || return 0
    [[ "$(docker inspect -f '{{.State.Running}}' qbittorrent 2>/dev/null)" == "true" ]] || return 0
    log "Pending torrent event(s); invoking router --apply"
    if ! /bin/bash "$BASE/script" --apply; then
        log "WARN: router returned an error; retaining queue for retry"
    fi
}

log "Watcher started; event-triggered routing, pending retry every ${RETRY}s"
while :; do
    if [[ ! -d "$QUEUE" ]]; then
        sleep 5
        continue
    fi

    # Process any events left across restarts or while the watcher was busy.
    run_pending

    # Event arrives quickly when on-add.sh creates a new hash file. A timeout
    # also allows later tag edits (which have no qBit on-add event) to retry.
    if inotifywait -q -t "$RETRY" \
        -e create -e moved_to -e close_write \
        "$QUEUE" >/dev/null 2>&1; then
        # Give qBit time to finish saving the new torrent metadata and tags.
        sleep 2
    else
        rc=$?
        if (( rc != 2 )); then
            log "WARN: inotify watch ended with code $rc; re-arming"
            sleep 3
        fi
    fi
done
