#!/bin/bash
#name=pt-disk-router-watch-start
# Unraid User Scripts entry: pt-disk-router-watch-start
set -euo pipefail
BASE="/boot/config/plugins/user.scripts/pt-disk-router"
QUEUE="/mnt/user/appdata/qbittorrent/pt-router/queue"
LOG="/mnt/user/appdata/qbittorrent/pt-router/watch.log"
for i in $(seq 1 30); do
    [[ -d "$QUEUE" ]] && break
    sleep 2
done
[[ -d "$QUEUE" && -r "$BASE/pt_download_watch.sh" ]] || exit 1
nohup /bin/bash "$BASE/pt_download_watch.sh" >>"$LOG" 2>&1 </dev/null &
