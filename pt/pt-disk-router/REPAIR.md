# PT disk router: started-torrent recovery

The on-add hook records a **snapshot** of `target:ssd` or `target:diskN`.
The event watcher calls `pt_download_router.php`, which routes only stopped,
zero-byte torrents, and leaves successfully routed torrents stopped. This is
intentional; the user may manually resume them.

If a torrent was manually started before routing, the router now reports
`REPAIR_REQUIRED` instead of retrying the unsafe new-torrent path on every
30-second poll. The event is retained; watcher retries unchanged events only
every 300 seconds. No automatic resume or file move is performed.

## Single-torrent opt-in SSD repair

Use `php repair_existing.php HASH` for a read-only preview. It only supports
an existing `/temp` location with one `target:ssd` label and a valid category;
the category folder must exist and there must be **no target file conflicts**.
It refuses HDD repair, unusual paths, and ambiguous targets.

Stop that **one** torrent in qBittorrent first, disable competing automations,
and run `php repair_existing.php HASH --apply` after inspecting the preview.
This sends qBittorrent's native setLocation command. It does **not** resume,
mark a task ready, edit state, remove queue events, or claim that data has
finished moving. Inspect path and contents, perform qBittorrent **Force
Recheck**, and resume manually only after verification.

The recovery intentionally does not automatically resolve queue entries.
After verified repair, review and archive the corresponding queue event
manually; do not change historical job state or route labels until confirmed.

For new torrents, keep qBittorrent's default add-stopped setting enabled,
pass target tags at creation, and avoid manually resuming before route status
is confirmed. The watcher alone cannot prevent a manual start race.
