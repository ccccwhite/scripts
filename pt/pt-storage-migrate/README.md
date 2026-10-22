# PT Storage Migration
Run in Unraid User Scripts. Both downloaders must map /mnt/download:/temp and /mnt/disk3:/hdd.
Default dry_run=true and enable_move=false. Shared credentials read from pt-common/credentials.json. Never commit actual credentials.
The script reconciles storage:ssd/storage:hdd for qBittorrent and Transmission, then previews move:hdd Transmission torrents. Real moves require dry_run=false and enable_move=true, after reviewing dry-run results. Only completed, unshared, unambiguous single-site torrents qualify. No deletion of torrent tasks or source files outside Transmission's own RPC move.
