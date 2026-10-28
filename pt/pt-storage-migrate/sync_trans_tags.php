<?php
declare(strict_types=1);

/**
 * Transmission storage-label normalization only. No torrent moving,
 * deleting, verifying, starting, stopping, or qBittorrent API writes.
 *
 * Run: php sync_trans_tags.php [--apply]
 * Safe by default: --apply must be explicitly passed.
 */
define('PT_STORAGE_LIBRARY_ONLY', true);
require_once __DIR__ . '/migrate.php';

function labelSyncFail(string $message): never { throw new RuntimeException($message); }

/**
 * Infer the physical disk ONLY from an explicit Transmission Docker bind mount.
 * User shares (/mnt/user) and unmapped paths are intentionally ambiguous.
 */
function recognizedHostStorage(string $hostPath): ?array {
    $p = norm($hostPath);
    if (within($p, '/mnt/download')) return ['storage:ssd', '/mnt/download'];
    foreach (['disk2', 'disk3', 'disk6'] as $disk) {
        $mount = '/mnt/' . $disk;
        if (within($p, $mount)) return ['storage:' . $disk, $mount];
    }
    return null;
}

function transDockerMappings(): array {
    $raw = shell_exec("docker inspect transmission --format '{{json .Mounts}}' 2>/dev/null");
    if (!is_string($raw) || trim($raw) === '') labelSyncFail('Cannot inspect Transmission Docker mounts');
    $items = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($items)) labelSyncFail('Invalid Docker mounts');
    $result = [];
    foreach ($items as $item) {
        if (($item['Type'] ?? '') !== 'bind') continue;
        $src = (string)($item['Source'] ?? '');
        $dst = (string)($item['Destination'] ?? '');
        if (!str_starts_with($src, '/mnt/') || !str_starts_with($dst, '/')) continue;
        $matched = recognizedHostStorage($src);
        if ($matched === null) continue;
        [$tag, $mountPoint] = $matched;
        $mount = trim((string)shell_exec('findmnt -rn -M ' . escapeshellarg($mountPoint) . ' -o TARGET 2>/dev/null'));
        if ($mount !== $mountPoint) labelSyncFail('Physical storage is not mounted: ' . $mountPoint);
        $result[] = ['host' => norm($src), 'container' => norm($dst), 'tag' => $tag];
    }
    usort($result, fn($a, $b) => strlen($b['container']) <=> strlen($a['container']));
    return $result;
}

function physicalTagForTransPath(string $downloadDir, array $mappings): ?string {
    if (!str_starts_with($downloadDir, '/')) return null;
    $path = norm($downloadDir);
    foreach ($mappings as $map) {
        if (!within($path, $map['container'])) continue;
        $relative = $path === $map['container'] ? '' : substr($path, strlen($map['container']));
        // Ensure no traversal is smuggled into a container path.
        if (preg_match('~(^|/)\\.{1,2}(/|$)~', $relative)) return null;
        $host = $map['host'] . $relative;
        $resolved = realpath($host);
        if ($resolved === false || !is_dir($resolved)) return null;
        $root = recognizedHostStorage($resolved);
        if ($root === null || $root[0] !== $map['tag']) return null;
        return $map['tag'];
    }
    return null;
}

function normalizedTransTags(array $labels, string $target): array {
    $out = [];
    foreach ($labels as $label) {
        if (!is_string($label)) continue;
        if (str_starts_with($label, 'storage:') ||
            preg_match('/^disk:[0-9]+$/D', $label) ||
            $label === 'route:ready') continue;
        $out[] = $label;
    }
    $out[] = $target;
    return array_values(array_unique($out));
}

try {
    if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--apply'))
        labelSyncFail('Usage: php sync_trans_tags.php [--apply]');
    $apply = in_array('--apply', $argv, true);
    if (!extension_loaded('curl')) labelSyncFail('PHP cURL required');

    $base = __DIR__;
    $cfg = jsonArr((string)file_get_contents($base . '/config.json'));
    $credentials = jsonArr((string)file_get_contents((string)$cfg['credentials_file']));
    $tr = $credentials['transmission'] ?? null;
    if (!is_array($tr) || !isset($tr['rpc_url'])) labelSyncFail('Transmission credentials missing');

    // Use the migration lock to avoid conflicting tag writes during a move.
    $statePath = (string)($cfg['state_file'] ?? ($base . '/state.json'));
    $fp = fopen(dirname($statePath) . '/run.lock', 'c');
    if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB))
        labelSyncFail('Storage migration is running; retry after it completes');

    $mappings = transDockerMappings();
    if (!$mappings) labelSyncFail('No unambiguous Transmission disk mounts available');
    echo "===== Verified Transmission disk mappings =====\n";
    foreach ($mappings as $m)
        echo $m['container'], ' -> ', $m['host'], ' (', $m['tag'], ")\n";

    $session = '';
    $items = transmissionItems($tr, $session);
    $updates = 0; $skips = 0;
    echo 'Mode=', ($apply ? 'APPLY' : 'PREVIEW'), '; torrents=', count($items), "\n";
    foreach ($items as $t) {
        $hash = strtolower((string)($t['hash_string'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40}$|^[a-f0-9]{64}$/D', $hash)) { $skips++; continue; }
        $dir = (string)($t['download_dir'] ?? '');
        $tag = physicalTagForTransPath($dir, $mappings);
        if ($tag === null) {
            echo 'SKIP ', substr($hash, 0, 12), ' path unresolved: ', $dir, "\n";
            $skips++; continue;
        }
        $before = array_values(array_filter((array)($t['labels'] ?? []), 'is_string'));
        $after = normalizedTransTags($before, $tag);
        if (sameSet($before, $after)) continue;
        echo ($apply ? 'UPDATE ' : 'PREVIEW '), substr($hash, 0, 12),
            ' ', implode(',', $before), ' => ', implode(',', $after), "\n";
        if ($apply) {
            // Refresh by immutable info-hash; preserve any user labels changed
            // between our initial read and this update.
            $fresh = rpc($tr, 'torrent_get', ['ids' => [$hash],
                'fields' => ['id', 'hash_string', 'download_dir', 'labels']], $session)['torrents'] ?? [];
            if (count($fresh) !== 1 ||
                strtolower((string)($fresh[0]['hash_string'] ?? '')) !== $hash ||
                physicalTagForTransPath((string)($fresh[0]['download_dir'] ?? ''), $mappings) !== $tag) {
                echo 'SKIP ', substr($hash, 0, 12), " changed during update\n";
                $skips++; continue;
            }
            $latest = array_values(array_filter((array)($fresh[0]['labels'] ?? []), 'is_string'));
            $targetTags = normalizedTransTags($latest, $tag);
            if (!sameSet($latest, $targetTags))
                rpc($tr, 'torrent_set', ['ids' => [$hash], 'labels' => $targetTags], $session);
        }
        $updates++;
    }
    echo 'DONE: ', ($apply ? 'updated=' : 'would_update='), $updates,
        '; skipped_unknown_path=', $skips, "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
