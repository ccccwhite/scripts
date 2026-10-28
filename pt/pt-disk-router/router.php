<?php
declare(strict_types=1);

/**
 * PT Disk Router for Unraid 6.12 + qBittorrent 5.x.
 * Host-side PHP; run from User Scripts every minute. FAIL CLOSED.
 *
 * The qBittorrent on-add hook only queues hashes. This program never handles
 * torrents unless they have a queue event and exactly one target:* tag.
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
function checkDestination(string $root, string $cat, string $hash, array $cfg, string $disk, bool $allowExisting): string {
    if (!is_dir($root) || !str_starts_with($root, '/mnt/')) fail('Invalid physical root');
    noLinks($root);
    if ($disk !== 'ssd') physicalMount($root);
    $suffix = '/qbit/' . $cat . '/' . $hash;
    foreach ($cfg['disk_roots'] as $d => $r) {
        if ($d === $disk) continue;
        if (file_exists(rtrim($r, '/') . $suffix) || is_link(rtrim($r, '/') . $suffix))
            fail('Same hash folder exists on another disk: ' . $d);
    }
    if ($disk !== 'ssd' && isset($cfg['ssd_root'])) {
        if (file_exists(rtrim($cfg['ssd_root'], '/') . $suffix) || is_link(rtrim($cfg['ssd_root'], '/') . $suffix))
            fail('Same hash folder exists on SSD');
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
    public function __construct(array $creds) {
        $this->base = rtrim((string)($creds['url'] ?? ''), '/');
        if (!preg_match('~^https?://[a-zA-Z0-9.:-]+$~', $this->base)) fail('Invalid qBit URL');
        $this->request('POST', 'auth/login', [
            'username' => (string)($creds['username'] ?? $creds['user'] ?? ''),
            'password' => (string)($creds['password'] ?? $creds['pass'] ?? '')
        ], true);
        if ($this->sid === '') fail('Login cookie missing; check qBit auth');
    }
    public function request(string $verb, string $ep, array $fields = [], bool $login = false): string {
        $url = $this->base . '/api/v2/' . $ep;
        $ch = curl_init($url);
        if ($ch === false) fail('curl_init failed');
        $origin = preg_replace('~/[^/]*$~', '', $this->base);
        $headers = ['Referer: ' . $this->base . '/', 'Origin: ' . $this->base,
            'Content-Type: application/x-www-form-urlencoded'];
        if ($this->sid !== '') $headers[] = 'Cookie: SID=' . $this->sid;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
                if (preg_match('/^Set-Cookie:\s*SID=([^;\s]+)/i', $line, $m)) $this->sid = $m[1];
                return strlen($line);
            }
        ]);
        if ($verb === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)); }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch); curl_close($ch);
        if ($body === false || $code !== 200) fail('qBit ' . $ep . ' failed HTTP ' . $code . ' ' . $err);
        if ($login && trim((string)$body) !== 'Ok.') fail('qBit login rejected');
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
    if ($disk === null) return 'waiting for one target:diskN / target:ssd tag';
    $cat = (string)($t['category'] ?? '');
    if (!validCategory($cat)) fail('Invalid or missing category; set mteam/tm18/etc first');
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
    $path = checkDestination($root, $cat, $hash, $cfg, $disk, $existing !== null);
    $savePath = ($disk === 'ssd' ? rtrim((string)$cfg['ssd_container_root'], '/') :
        rtrim((string)$cfg['share_container_root'], '/')) . '/qbit/' . $cat . '/' . $hash;
    if ($disk !== 'ssd') $savePath = rtrim((string)$cfg['share_container_root'], '/') . '/' . $cat . '/' . $hash;
    if ($existing === null) {
        $free = disk_free_space($root);
        if ($free === false) fail('Cannot determine free space');
        $margin = (int)($cfg['reserve_gib'] ?? 100) * 1073741824;
        $reserved = totalReserved($state, $live, $disk, $hash);
        if ($free < $size + $margin + $reserved)
            fail('Insufficient space; required=' . round(($size + $margin + $reserved) / 1073741824, 1) .
                'GiB free=' . round($free / 1073741824, 1) . 'GiB');
    }
    if (!$apply) return 'DRY RUN: would route ' . $cat . ' to ' . $disk . ' as ' . $savePath;
    if ($existing === null) {
        if (!mkdir($path, 0775, true) && !is_dir($path)) fail('Cannot create disk folder');
        $state['jobs'][$hash] = ['disk' => $disk, 'category' => $cat, 'size' => $size,
            'status' => 'reserved', 'created_at' => time(), 'save_path' => $savePath];
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
    $api->post('torrents/addTags', ['hashes' => $hash, 'tags' => $disk === 'ssd'
        ? 'storage:ssd,route:ready' : 'storage:hdd,disk:' . substr($disk, 4) . ',route:ready']);
    $api->post('torrents/removeTags', ['hashes' => $hash, 'tags' => 'target:' . $disk]);
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
            $result = processOne($hash, $live[$hash], $cfg, $state, $live, $api, $apply);
            out(substr($hash, 0, 12) . ' ' . $result);
            if ($apply && (str_starts_with($result, 'READY') || $result === 'already ready')) unlink($file);
        } catch (Throwable $e) {
            out(substr($hash, 0, 12) . ' SKIP: ' . $e->getMessage());
        }
    }
}
try { main($argv); } catch (Throwable $e) { fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL); exit(1); }
