#!/bin/sh
# qBit "torrent added" hook: capture the ORIGINAL tags at add time.
# Arguments: "%I" "%J" "%G". No target tag at add time = NO queue event.
set -eu
Q=/config/pt-router/queue
HASH=$(printf '%s' "${1:-}" | tr 'A-F' 'a-f')
case "$HASH" in *[!0-9a-f]*|'') HASH='';; esac
[ "${#HASH}" -eq 40 ] || {
 HASH=$(printf '%s' "${2:-}" | tr 'A-F' 'a-f')
 case "$HASH" in *[!0-9a-f]*|'') exit 2;; esac
 [ "${#HASH}" -eq 64 ] || exit 2
}
# Only exact recognized target tags present in %G (the original add event)
# qualify. Never inspect the torrent's current tags to enqueue later.
TAGS="${3:-}"
TARGET=''
COUNT=0
set -f
OLDIFS=$IFS
IFS=,
for tag in $TAGS; do
    # Allow whitespace around comma-separated tags; target itself is ASCII.
    tag=$(printf '%s' "$tag" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    case "$tag" in
        target:*)
            COUNT=$((COUNT + 1))
            case "$tag" in
                target:ssd|target:disk2|target:disk3|target:disk6) TARGET=$tag ;;
                *) exit 0 ;;
            esac
            ;;
    esac
done
IFS=$OLDIFS
[ "$COUNT" -eq 1 ] && [ -n "$TARGET" ] || exit 0

mkdir -p "$Q"
umask 002
# Atomically publish an event file containing the add-time target snapshot.
# Never replace a previous snapshot if the same hash was already queued.
[ ! -e "$Q/$HASH" ] || exit 0
TMP="$Q/.$HASH.$$"
trap 'rm -f "$TMP"' 0 1 2 3 15
printf '%s\n' "$TARGET" > "$TMP"
[ ! -e "$Q/$HASH" ] || exit 0
mv "$TMP" "$Q/$HASH"
