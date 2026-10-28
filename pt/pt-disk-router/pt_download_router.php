<?php
declare(strict_types=1);

/**
 * PT Disk Router for Unraid 6.12 + qBittorrent 5.x.
 * Host-side PHP; triggered by inotify watcher. FAIL CLOSED.
 *
 * The qBittorrent on-add hook records the original target tag. This program
 * never handles torrents without an add-time snapshot and matching current tag.
 */
date_default_timezone_set('Asia/Shanghai');

function out(string $msg): void { echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL; }
function fail(string $msg): never { throw new RuntimeException($msg); }
function readJson(string $file): array {
    if (!is_file($file)) fail('Missing JSON: ' . $file);
    $v = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($v)) fail('Invalid JSON: ' . $file);
    return $v;
}
function saveJson(string $file, array $data): void {
    $tmp = $file . '.tmp.' . getmypid();
    $bytes = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $bytes . "\n") === false || !rename($tmp, $file)) fail('Cannot persist state');
}
function validHash(string $v): bool { return (bool)preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $v); }
function validCategory(string $v): bool { return (bool)preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $v); }
function parts(string $tags): array { return array_values(array_filter(array_map('trim', explode(',', $tags)), 'strlen')); }
function tagTarget(array $tags, array $allowed): ?string {
    $found = [];
    foreach ($tags as $t) if (str_starts_with($t, 'target:')) {
        $disk = substr($t, 7);
        if (!in_array($disk, $allowed, true)) fail('Unsupported target tag: ' . $t);
        $found[] = $disk;
    }
    if (count($found) > 1) fail('Multiple target disks selected');
    return $found[0] ?? null;
}
/** Preserve the human-readable torrent name, changing only unsafe path characters. */
function torrentFolder(string $name): string {
    if (!preg_match('//u', $name)) fail('Invalid UTF-8 torrent name');
    $clean = preg_replace('/[\/\\\\\x00-\x1F\x7F<>:"|?*]/u', '_', trim($name));
    if ($clean === null) fail('Cannot normalize torrent name');
    $clean = rtrim(trim($clean), " .");
    if ($clean === '' || $clean === '.' || $clean === '..') fail('Empty/unsafe torrent name');
    // Keep a reasonable single-directory-name byte length without breaking UTF-8.
    if (strlen($clean) > 180) {
        preg_match_all('/./us', $clean, $chars);
        $short = '';
        foreach ($chars[0] as $ch) {
            if (strlen($short . $ch) > 180) break;
            $short .= $ch;
        }
        $clean = rtrim($short, " .");
    }
    if ($clean === '') fail('Torrent name became empty');
    return $clean;
}
/** Guard against any path already present anywhere in the aggregate share. */
/**
 * Enumerate ALL array disks, not only the selectable disk_roots map.
 * A user share can be configured to include additional disks.
 */
function allArrayDisks(array $cfg): array {
    $disks = array_values((array)$cfg['disk_roots']);
    foreach (glob('/mnt/disk[0-9]*', GLOB_ONLYDIR) ?: [] as $p) {
        if (preg_match('~^/mnt/disk[0-9]+$~D', $p)) $disks[] = $p;
    }
    return array_values(array_unique($disks));
}
function occupiedFolder(string $cat, string $folder, array $cfg): bool {
    $suffix = '/qbit/' . $cat . '/' . $folder;
    foreach (allArrayDisks($cfg) as $root) {
        $p = rtrim((string)$root, '/') . $suffix;
        if (file_exists($p) || is_link($p)) return true;
    }
    $ssd = rtrim((string)$cfg['ssd_root'], '/') . $suffix;
    if (file_exists($ssd) || is_link($ssd)) return true;
    $shared = rtrim((string)$cfg['share_host_root'], '/') . '/' . $cat . '/' . $folder;
    return file_exists($shared) || is_link($shared);
}
function chooseFolder(string $name, string $cat, string $hash, array $cfg): string {
    $folder = torrentFolder($name);
    if (!occupiedFolder($cat, $folder, $cfg)) return $folder;
    // A wrapper directory is under our control: disambiguation is safe.
    foreach ([10, 16, strlen($hash)] as $n) {
        $suffix = ' [' . substr($hash, 0, $n) . ']';
        $prefix = $folder;
        while (strlen($prefix . $suffix) > 220) {
            $prefix = preg_replace('/.$/us', '', $prefix);
            if ($prefix === null || $prefix === '') fail('Cannot shorten torrent folder');
        }
        $candidate = rtrim($prefix, ' .') . $suffix;
        if (!occupiedFolder($cat, $candidate, $cfg)) return $candidate;
    }
    fail('All candidate paths are occupied; manual review required');
}
/** Verify the qBit API's actual *relative file paths*, without rewriting them. */
function torrentManifest(array $files): array {
    if (!$files) fail('Torrent metadata not yet available');
    if (count($files) > 200000) fail('Excessive torrent file count');
    $manifest = [];
    foreach ($files as $f) {
        if (!is_array($f)) fail('Invalid qBit torrent file entry');
        $name = $f['name'] ?? null;
        $size = $f['size'] ?? null;
        if (!is_string($name) || !is_int($size) || $size < 0 || $name === '' ||
            !preg_match('//u', $name) || str_contains($name, '\\') ||
            str_starts_with($name, '/') || preg_match('/[\x00-\x1F\x7F]/', $name))
            fail('Unsafe torrent file path from qBit API');
        $segments = explode('/', $name);
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || strlen($seg) > 255)
                fail('Unsafe torrent directory component');
        }
        if (isset($manifest[$name])) fail('Duplicate file paths in torrent');
        $manifest[$name] = $size;
    }
    ksort($manifest, SORT_STRING);
    return $manifest;
}
/**
 * If ALL torrent files already have the same original top-level directory,
 * use category as qBit save_path and precreate that root on the selected disk.
 *
 * Otherwise provide a single human-readable wrapper folder for the flat files.
 * Never rename torrent-internal paths and never create a redundant root/root.
 */
function planTorrentLayout(array $torrent, array $files, array $cfg, string $cat, string $hash): array {
    $manifest = torrentManifest($files);
    $first = null;
    $sharedRoot = true;
    foreach (array_keys($manifest) as $relative) {
        $segments = explode('/', $relative);
        if (count($segments) < 2) { $sharedRoot = false; break; }
        if ($first === null) $first = $segments[0];
        elseif ($first !== $segments[0]) { $sharedRoot = false; break; }
    }
    if ($sharedRoot && $first !== null) {
        // The root folder is owned by the torrent's content layout and cannot
        // be transparently renamed without changing the torrent file paths.
        $folder = $first;
        if (occupiedFolder($cat, $folder, $cfg))
            fail('Existing torrent root path collides with data on disk: ' . $cat . '/' . $folder .
                '; do not auto-rename the original root (manual review required)');
        $mode = 'existing_root';
    } else {
        $folder = chooseFolder((string)($torrent['name'] ?? ''), $cat, $hash, $cfg);
        $mode = 'wrapper';
    }
    return [
        'layout_version' => 2, 'layout_mode' => $mode, 'folder' => $folder,
        'manifest_sha256' => hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        'file_count' => count($manifest),
    ];
}

function noLinks(string $path): void {
    $s = '';
    foreach (explode('/', trim($path, '/')) as $piece) {
        $s .= '/' . $piece;
        if (is_link($s)) fail('Symlink in destination path: ' . $s);
    }
}
function physicalMount(string $path): void {
    if (!is_dir($path)) fail('Disk unavailable: ' . $path);
    $actual = trim((string)shell_exec('findmnt -rn -M ' . escapeshellarg($path) . ' -o TARGET 2>/dev/null'));
    if ($actual !== $path) fail('Not a mounted physical disk: ' . $path);
}
function assertDockerMapping(string $container, string $host, string $target): void {
    $raw = shell_exec('docker inspect ' . escapeshellarg($container) .
        " --format '{{json .Mounts}}' 2>/dev/null");
    if (!is_string($raw) || trim($raw) === '') fail('docker inspect failed: ' . $container);
    $mounts = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    foreach ($mounts as $m) {
        if (rtrim((string)($m['Destination'] ?? ''), '/') === rtrim($target, '/') &&
            rtrim((string)($m['Source'] ?? ''), '/') === rtrim($host, '/')) return;
    }
    fail('Missing Docker mapping: ' . $host . ' -> ' . $target);
}
function checkDestination(string $root, string $cat, string $folder, array $cfg, string $disk, bool $allowExisting): string {
    if (!is_dir($root) || !str_starts_with($root, '/mnt/')) fail('Invalid physical root');
    noLinks($root);
    if ($disk !== 'ssd') physicalMount($root);
    $suffix = '/qbit/' . $cat . '/' . $folder;
    foreach (allArrayDisks($cfg) as $r) {
        $d = basename($r);
        if ($r === $root) continue;
        if (file_exists(rtrim($r, '/') . $suffix) || is_link(rtrim($r, '/') . $suffix))
            fail('Same torrent destination exists on another disk: ' . $d);
    }
    if ($disk !== 'ssd' && isset($cfg['ssd_root'])) {
        if (file_exists(rtrim($cfg['ssd_root'], '/') . $suffix) || is_link(rtrim($cfg['ssd_root'], '/') . $suffix))
            fail('Same torrent destination exists on SSD');
    }
    $path = rtrim($root, '/') . $suffix;
    noLinks($path);
    if ((file_exists($path) || is_link($path)) && !$allowExisting)
        fail('Destination already exists; refusing overwrite: ' . $path);
    if ($allowExisting && !is_dir($path)) fail('Reserved destination disappeared');
    return $path;
}
function totalReserved(array $state, array $live, string $disk, string $exclude): int {
    $bytes = 0;
    foreach (($state['jobs'] ?? []) as $h => $job) {
        if ($h === $exclude || ($job['disk'] ?? '') !== $disk) continue;
        if (!in_array($job['status'] ?? '', ['reserved', 'ready'], true)) continue;
        $t = $live[$h] ?? null;
        if ($t !== null) $bytes += max(0, (int)($t['amount_left'] ?? 0));
        elseif (($job['status'] ?? '') === 'reserved') $bytes += (int)($job['size'] ?? 0);
    }
    return $bytes;
}
function hasNoContent(array $t): bool {
    $state = (string)($t['state'] ?? '');
    return (str_starts_with($state, 'stopped') || str_starts_with($state, 'paused'))
        && (int)($t['downloaded'] ?? -1) === 0
        && (float)($t['progress'] ?? 1) === 0.0;
}
class Qbit {
    private string $base;
    private string $sid = '';
    private string $cookieName = '';
    public function __construct(array $creds) {
        $this->base = rtrim((string)($creds['url'] ?? ''), '/');
        if (!preg_match('~^https?://[a-zA-Z0-9.:-]+$~', $this->base)) fail('Invalid qBit URL');
        $this->request('POST', 'auth/login', [
            'username' => (string)($creds['username'] ?? $creds['user'] ?? ''),
            'password' => (string)($creds['password'] ?? $creds['pass'] ?? '')
        ], true);
        // A successful qBit 5.2 login may be 204 (No Content).
        // Authentication bypass can omit a cookie, so verify real API access.
        $this->get('torrents/info?filter=all');
    }
    public function request(string $verb, string $ep, array $fields = [], bool $login = false): string {
        $url = $this->base . '/api/v2/' . $ep;
        $ch = curl_init($url);
        if ($ch === false) fail('curl_init failed');
        $headers = ['Referer: ' . $this->base . '/', 'Origin: ' . $this->base,
            'Content-Type: application/x-www-form-urlencoded'];
        if ($this->sid !== '' && $this->cookieName !== '')
            $headers[] = 'Cookie: ' . $this->cookieName . '=' . $this->sid;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
                if (preg_match('/^Set-Cookie:\s*(SID|QBT_SID_[0-9]+)=([^;\s]+)/i', $line, $m)) {
                    $this->cookieName = $m[1];
                    $this->sid = $m[2];
                }
                return strlen($line);
            }
        ]);
        if ($verb === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)); }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch); curl_close($ch);
        $validStatus = $login
            ? in_array($code, [200, 204], true)
            : ($verb === 'POST' ? in_array($code, [200, 204], true) : $code === 200);
        if ($body === false || !$validStatus) fail('qBit ' . $ep . ' failed HTTP ' . $code . ' ' . $err);
        if ($login && $code === 200 && trim((string)$body) !== 'Ok.') fail('qBit login rejected');
        if (!$login && trim((string)$body) === 'Fails.') fail('qBit rejected request: ' . $ep);
        return (string)$body;
    }
    public function get(string $ep): array {
        $v = json_decode($this->request('GET', $ep), true, 512, JSON_THROW_ON_ERROR);
        return is_array($v) ? $v : [];
    }
    public function post(string $ep, array $data): void { $this->request('POST', $ep, $data); }
}
function processOne(string $hash, array $t, array $cfg, array &$state, array $live, Qbit $api, bool $apply): string {
    $tags = parts((string)($t['tags'] ?? ''));
    if (in_array('route:ready', $tags, true)) return 'already ready';
    $allowed = array_merge(array_keys($cfg['disk_roots']), ['ssd']);
    $disk = tagTarget($tags, $allowed);
    if ($disk === null) return 'SKIP_NO_TARGET';
    $cat = (string)($t['category'] ?? '');
    if (!validCategory($cat)) fail('Invalid or missing category; set mteam/tm18/etc first');
    // New routes need no route:ready tag: readiness is persisted in state.json.
    // A leftover/replayed event must not modify a previously completed task.
    $readyJob = $state['jobs'][$hash] ?? null;
    if (is_array($readyJob) && ($readyJob['status'] ?? '') === 'ready') {
        if (($readyJob['category'] ?? '') !== $cat || ($readyJob['disk'] ?? '') !== $disk ||
            rtrim((string)($readyJob['save_path'] ?? ''), '/') !== rtrim((string)($t['save_path'] ?? ''), '/'))
            fail('Recorded ready route conflicts with current torrent; manual review required');
        return 'already ready';
    }
    $epoch = (int)($cfg['activation_epoch'] ?? 0);
    if ($epoch < 1 || (int)($t['added_on'] ?? 0) < $epoch)
        fail('Not a new task created after activation_epoch');
    if (!hasNoContent($t)) fail('Torrent must be STOPPED with zero progress and zero downloaded bytes');
    $size = max((int)($t['size'] ?? 0), (int)($t['total_size'] ?? 0));
    if ($size <= 0) return 'waiting for torrent metadata/size';
    $root = $disk === 'ssd' ? (string)$cfg['ssd_root'] : (string)$cfg['disk_roots'][$disk];
    if ($disk === 'ssd') {
        assertDockerMapping('qbittorrent', $root, (string)$cfg['ssd_container_root']);
    } else {
        assertDockerMapping('qbittorrent', (string)$cfg['share_host_root'], (string)$cfg['share_container_root']);
        if (!is_dir((string)$cfg['share_host_root'])) fail('User share not created');
        // The host disk path is pre-created below, then qBit writes through /mnt/user/qbit.
    }
    $existing = $state['jobs'][$hash] ?? null;
    if ($existing !== null && (
        ($existing['category'] ?? '') !== $cat || ($existing['disk'] ?? '') !== $disk ||
        (int)($existing['size'] ?? 0) !== $size))
        fail('Existing reservation conflicts with requested category/disk/size');
    // Read actual qBit file paths, including any root folder from the torrent layout.
    // Only target tasks that are stopped and have zero progress / downloaded bytes.
    $files = $api->get('torrents/files?hash=' . rawurlencode($hash));
    $manifest = torrentManifest($files);
    if ($existing !== null) {
        if (($existing['layout_version'] ?? 0) !== 2) fail('Old reservation format; manual review required');
        $plan = [
            'layout_version' => 2,
            'layout_mode' => (string)$existing['layout_mode'],
            'folder' => (string)$existing['folder'],
            'manifest_sha256' => (string)$existing['manifest_sha256'],
            'file_count' => (int)$existing['file_count']
        ];
        $signature = hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($signature !== $plan['manifest_sha256'] || count($manifest) !== $plan['file_count'])
            fail('Torrent file list changed after reservation');
    } else {
        $plan = planTorrentLayout($t, $files, $cfg, $cat, $hash);
    }
    $folder = $plan['folder'];
    if ($folder === '' || str_contains($folder, '/') || in_array($folder, ['.','..'], true))
        fail('Invalid stored torrent root; manual review required');
    $path = checkDestination($root, $cat, $folder, $cfg, $disk, $existing !== null);
    $base = $disk === 'ssd'
        ? rtrim((string)$cfg['ssd_container_root'], '/') . '/qbit/' . $cat
        : rtrim((string)$cfg['share_container_root'], '/') . '/' . $cat;
    $savePath = $plan['layout_mode'] === 'existing_root'
        ? $base
        : $base . '/' . $folder;
    if ($existing === null) {
        $free = disk_free_space($root);
        if ($free === false) fail('Cannot determine free space');
        $margin = (int)($cfg['reserve_gib'] ?? 100) * 1073741824;
        $reserved = totalReserved($state, $live, $disk, $hash);
        if ($free < $size + $margin + $reserved)
            fail('Insufficient space; required=' . round(($size + $margin + $reserved) / 1073741824, 1) .
                'GiB free=' . round($free / 1073741824, 1) . 'GiB');
    }
    if (!$apply) return 'DRY RUN: would route ' . $cat . ' to ' . $disk . ' [' . $plan['layout_mode'] . ', ' . $plan['file_count'] . ' files] as ' . $savePath . ' (physical root: ' . $path . ')';
    if ($existing === null) {
        if (!mkdir($path, 0775, true) && !is_dir($path)) fail('Cannot create disk folder');
        // qBittorrent linuxserver runs as PUID=99, PGID=100. New files must be writable.
        // Only touch the newly reserved hash directory; never change existing content ownership.
        if (!chown($path, 99) || !chgrp($path, 100) || !chmod($path, 0775))
            fail('Cannot set qBit writable permissions on hash folder');
        $state['jobs'][$hash] = ['disk' => $disk, 'category' => $cat, 'size' => $size,
            'status' => 'reserved', 'created_at' => time(), 'folder' => $folder,
            'layout_mode' => $plan['layout_mode'], 'layout_version' => 2,
            'file_count' => $plan['file_count'], 'manifest_sha256' => $plan['manifest_sha256'],
            'save_path' => $savePath];
        saveJson((string)$cfg['state_file'], $state); // intent BEFORE qBit write
    }
    $api->post('torrents/setAutoManagement', ['hashes' => $hash, 'enable' => 'false']);
    if (rtrim((string)($t['save_path'] ?? ''), '/') !== $savePath)
        $api->post('torrents/setLocation', ['hashes' => $hash, 'location' => $savePath]);
    $confirmed = false;
    for ($n = 0; $n < 5; $n++) {
        $current = $api->get('torrents/info?filter=all');
        foreach ($current as $item) if (strtolower((string)($item['hash'] ?? '')) === $hash &&
            rtrim((string)($item['save_path'] ?? ''), '/') === $savePath &&
            ($item['auto_tmm'] ?? true) === false) $confirmed = true;
        if ($confirmed) break;
        usleep(250000);
    }
    if (!$confirmed) fail('qBit save_path not confirmed; reservation preserved; torrent must remain stopped');
    // After moving the empty task, verify qBittorrent did not silently change
    // its effective torrent-relative paths, which would alter the content layout.
    $afterFiles = torrentManifest($api->get('torrents/files?hash=' . rawurlencode($hash)));
    $afterSignature = hash('sha256', json_encode($afterFiles, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    if ($afterSignature !== $plan['manifest_sha256'])
        fail('File layout changed during location update; manual review required');
    $fresh = $api->get('torrents/info?filter=all');
    $confirmedTorrent = null;
    foreach ($fresh as $item)
        if (strtolower((string)($item['hash'] ?? '')) === $hash) $confirmedTorrent = $item;
    if ($confirmedTorrent === null || !hasNoContent($confirmedTorrent))
        fail('Torrent started or received data during routing; DO NOT mark ready');
    $expectedRoot = $base . '/' . $folder;
    $cp = rtrim((string)($confirmedTorrent['content_path'] ?? ''), '/');
    if ($cp !== '' && $cp !== $expectedRoot && !str_starts_with($cp, $expectedRoot . '/'))
        fail('Reported content_path does not match selected root; manual review: ' . $cp);
    // Publish exactly one persistent storage label; leave unrelated site/user tags alone.
    // Do not expose the internal ready state as an additional qBit UI tag.
    $storageTag = 'storage:' . $disk;
    $api->post('torrents/addTags', ['hashes' => $hash, 'tags' => $storageTag]);
    $obsolete = [];
    foreach ($tags as $tag) {
        if (($tag !== $storageTag && str_starts_with($tag, 'storage:')) ||
            preg_match('/^disk:[0-9]+$/D', $tag) || $tag === 'route:ready' ||
            $tag === 'target:' . $disk) $obsolete[] = $tag;
    }
    if ($obsolete) $api->post('torrents/removeTags', [
        'hashes' => $hash, 'tags' => implode(',', array_values(array_unique($obsolete)))
    ]);
    $state['jobs'][$hash]['status'] = 'ready';
    $state['jobs'][$hash]['ready_at'] = time();
    saveJson((string)$cfg['state_file'], $state);
    return 'READY ' . $cat . ' -> ' . $disk . ' -> ' . $savePath . ' (still stopped)';
}
function main(array $argv): void {
    if (count($argv) < 2) fail('Usage: php router.php /path/config.json [--apply|--status]');
    $cfg = readJson($argv[1]);
    foreach (['queue_dir','state_file','lock_file','share_host_root','share_container_root',
        'disk_roots','ssd_root','ssd_container_root','credentials_file'] as $key)
        if (!isset($cfg[$key])) fail('Config missing: ' . $key);
    $stateDir = dirname((string)$cfg['state_file']);
    if (!is_dir($stateDir)) fail('State directory missing: ' . $stateDir);
    $lock = fopen((string)$cfg['lock_file'], 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) fail('Another router instance is active');
    $state = is_file((string)$cfg['state_file']) ? readJson((string)$cfg['state_file']) : ['jobs' => []];
    if (in_array('--status', $argv, true)) {
        out('Queued=' . count(glob(rtrim($cfg['queue_dir'], '/') . '/*') ?: []) .
            '; recorded=' . count($state['jobs'] ?? []) . '; dry_run=' . json_encode($cfg['dry_run'] ?? true));
        return;
    }
    $apply = in_array('--apply', $argv, true) && ($cfg['dry_run'] ?? true) === false;
    if (($cfg['dry_run'] ?? true) !== false && ($cfg['dry_run'] ?? true) !== true) fail('dry_run must be boolean');
    if ($apply && (int)($cfg['activation_epoch'] ?? 0) < 1) fail('Set activation_epoch before applying');
    if (!extension_loaded('curl')) fail('PHP curl extension is required');
    $creds = readJson((string)$cfg['credentials_file']);
    $q = $creds['qbittorrent'] ?? $creds['qbit'] ?? null;
    if (!is_array($q)) fail('No qbittorrent credentials in local credentials file');
    $api = new Qbit($q);
    $prefs = $api->get('app/preferences');
    if (($prefs['temp_path_enabled'] ?? false) === true)
        fail('Disable qBit "Keep incomplete torrents in" separate directory first');
    $torrents = $api->get('torrents/info?filter=all');
    $live = [];
    foreach ($torrents as $t) { $h = strtolower((string)($t['hash'] ?? '')); if (validHash($h)) $live[$h] = $t; }
    $queue = glob(rtrim((string)$cfg['queue_dir'], '/') . '/*') ?: [];
    out('Mode=' . ($apply ? 'APPLY' : 'DRY-RUN') . '; queued=' . count($queue));
    foreach ($queue as $file) {
        $hash = strtolower(basename($file));
        if (!validHash($hash) || !is_file($file) || is_link($file)) continue;
        try {
            if (!isset($live[$hash])) { out(substr($hash, 0, 12) . ' not in qBit; retaining event'); continue; }
            // Events must contain an add-time target snapshot. Legacy empty
            // files (from the previous hook) must NEVER become eligible if a
            // tag is added later. Preserve a separate temporary config for tests.
            $snapshot = trim((string)file_get_contents($file));
            if (!preg_match('/^target:(ssd|disk2|disk3|disk6)$/D', $snapshot)) {
                out(substr($hash, 0, 12) . ' DROP: missing/invalid add-time target snapshot');
                if ($apply) unlink($file);
                continue;
            }
            $currentTags = parts((string)($live[$hash]['tags'] ?? ''));
            $currentTarget = tagTarget($currentTags, array_merge(array_keys($cfg['disk_roots']), ['ssd']));
            if ($currentTarget === null || 'target:' . $currentTarget !== $snapshot) {
                out(substr($hash, 0, 12) . ' DROP: target changed/removed after add; no routing');
                if ($apply) unlink($file);
                continue;
            }
            $result = processOne($hash, $live[$hash], $cfg, $state, $live, $api, $apply);
            out(substr($hash, 0, 12) . ' ' . $result);
            if ($apply && (str_starts_with($result, 'READY') || $result === 'already ready' || $result === 'SKIP_NO_TARGET')) unlink($file);
        } catch (Throwable $e) {
            out(substr($hash, 0, 12) . ' SKIP: ' . $e->getMessage());
        }
    }
}
try { main($argv); } catch (Throwable $e) { fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL); exit(1); }
