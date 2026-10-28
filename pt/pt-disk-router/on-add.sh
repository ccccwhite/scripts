#!/bin/sh
# qBit on-add hook: pass "%I" "%J"; enqueue hash only.
set -eu
Q=/config/pt-router/queue
HASH=$(printf '%s' "${1:-}" | tr 'A-F' 'a-f')
case "$HASH" in *[!0-9a-f]*|'') HASH='';; esac
[ "${#HASH}" -eq 40 ] || {
 HASH=$(printf '%s' "${2:-}" | tr 'A-F' 'a-f')
 case "$HASH" in *[!0-9a-f]*|'') exit 2;; esac
 [ "${#HASH}" -eq 64 ] || exit 2
}
mkdir -p "$Q"
umask 002
: >> "$Q/$HASH"
