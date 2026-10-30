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


## Persistent verification tracker (initial release)

`repair_tracker.php` is versioned with the watcher. It tracks **one explicitly
enrolled, completed torrent at a time or several separate completed torrents**.
It does not automatically discover/recover the 23 historical torrents. It does
not move data, delete files or change the original disk-router state.

1. After a native setLocation move and while the torrent is **stopped** at
   `/temp/qbit/CATEGORY`, enroll its hash:
   `php repair_tracker.php --enroll HASH`
2. The existing watcher polls enrolled jobs (or manually run
   `php repair_tracker.php --poll`). It requests a force recheck once.
3. It **must observe** a qBittorrent `checking*` state before accepting the
   end of recheck. Missing a short-lived checking state fails closed.
4. For completed tasks, the checker must return a stopped state, progress
   of 100%, and zero bytes remaining. Otherwise it marks `failed`.
5. The default is **no auto-resume**; you may manually start a verified job.
   Only explicit enrollment with `--resume` allows a verified job to
   auto-resume. Enrollment must happen while the torrent is stopped.
6. `php repair_tracker.php --status` reports recorded state and errors.
   The state file `repair-tracking.json` is intentionally excluded from Git.

The state machine is `ready_to_recheck -> check_requested -> checking ->
verified` (or `done` if `--resume` was explicitly selected). Any
unexpected path, torrent status, missing torrent, missing checking observation,
or verification mismatch goes to `failed` and requires human review.
An observed `stoppedUP` without having observed checking is NOT proof of a
completed hash recheck. Do not manually start a task before a tracked recheck
has reached `verified`.

Because the tracker requires the torrent to be stopped at enrollment, a torrent
that was already resumed after a move must be stopped again if you want to
enroll it. The tracker does not infer that an earlier recheck occurred.
