<?php
declare(strict_types=1);
/**
 * Offline fixture tests: no Docker, qBit connection, or torrent downloads.
 * Runs actual router planning methods with a disposable temporary filesystem.
 */
define('PT_DISK_ROUTER_TEST', true);
require dirname(__DIR__) . '/router.php';
$base = sys_get_temp_dir() . '/pt-disk-router-fixture-' . getmypid();
$cfg = [
    'disk_roots' => ['disk2' => $base . '/disk2', 'disk3' => $base . '/disk3', 'disk6' => $base . '/disk6'],
    'ssd_root' => $base . '/ssd',
    'share_host_root' => $base . '/share',
];
foreach (array_merge(array_values($cfg['disk_roots']), [$cfg['ssd_root'], $cfg['share_host_root']]) as $dir) {
    if (!mkdir($dir, 0775, true)) throw new RuntimeException('Unable to create fixture root');
}
function check(string $label, callable $fn): void {
    try { $fn(); echo "PASS $label\n"; } catch (Throwable $e) {
        echo "FAIL $label: " . $e->getMessage() . "\n"; exit(1);
    }
}
function equal($actual,$expected): void {
    if ($actual !== $expected)
        throw new RuntimeException('Expected ' . json_encode($expected) . '; got ' . json_encode($actual));
}
function files(array $paths): array {
    return array_map(fn($p) => ['name' => $p, 'size' => 1024], $paths);
}
$hash = str_repeat('a',40);
check('single file -> wrapper', function() use($cfg,$hash) {
    $p = planTorrentLayout(['name'=>'Film.2026.1080p'],files(['Film.mkv']),$cfg,'mteam',$hash);
    equal([$p['layout_mode'],$p['folder'], $p['file_count']],['wrapper','Film.2026.1080p',1]);
});
check('single entry WITH original root -> root reused', function() use($cfg,$hash) {
    $p=planTorrentLayout(['name'=>'Film'],files(['Film/Film.mkv']),$cfg,'mteam',$hash);
    equal([$p['layout_mode'],$p['folder']],['existing_root','Film']);
});
check('multi-file with root -> root reused', function() use($cfg,$hash) {
    $p=planTorrentLayout(['name'=>'Series'],files(['Series/E01.mkv','Series/E02.mkv']),$cfg,'mteam',$hash);
    equal([$p['layout_mode'],$p['folder'], $p['file_count']],['existing_root','Series',2]);
});
check('multi-file with flat files -> wrapper', function() use($cfg,$hash) {
    $p=planTorrentLayout(['name'=>'Film'],files(['Film.mkv','Film.nfo','cover.jpg']),$cfg,'mteam',$hash);
    equal([$p['layout_mode'],$p['folder']],['wrapper','Film']);
});
check('different top-level roots -> wrapper', function() use($cfg,$hash) {
    $p=planTorrentLayout(['name'=>'Collection'],files(['Disc1/a.mkv','Disc2/b.mkv']),$cfg,'mteam',$hash);
    equal([$p['layout_mode'],$p['folder']],['wrapper','Collection']);
});
check('flat duplicate directory -> hash suffix', function() use($cfg,$hash,$base) {
    mkdir($base.'/disk3/qbit/mteam/Film',0775,true);
    $p=planTorrentLayout(['name'=>'Film'],files(['Film.mkv']),$cfg,'mteam',$hash);
    equal($p['folder'],'Film [aaaaaaaaaa]');
});
check('native root duplicate -> REFUSE auto rename', function() use($cfg,$hash) {
    try { planTorrentLayout(['name'=>'Film'],files(['Film/Film.mkv']),$cfg,'mteam',$hash); }
    catch (RuntimeException $e) { return; }
    throw new RuntimeException('Root collision was not rejected');
});
check('reject .. traversal', function() {
    try { torrentManifest(files(['../unsafe.mkv'])); } catch(RuntimeException $e) { return; }
    throw new RuntimeException('Unsafe file accepted');
});
check('reject absolute paths', function() {
    try { torrentManifest(files(['/etc/shadow'])); } catch(RuntimeException $e) { return; }
    throw new RuntimeException('Absolute file accepted');
});
check('reject duplicate entries', function() {
    try { torrentManifest(files(['Same.mkv','Same.mkv'])); } catch(RuntimeException $e) { return; }
    throw new RuntimeException('Duplicate file accepted');
});
check('reject empty metadata', function() {
    try { torrentManifest([]); } catch(RuntimeException $e) { return; }
    throw new RuntimeException('Empty manifest accepted');
});
check('reject multiple targets', function() {
    try { tagTarget(['target:disk2','target:disk6'],['disk2','disk6']); }
    catch(RuntimeException $e) { return; }
    throw new RuntimeException('Multiple disks accepted');
});
echo "PASS 12/12 offline fixture cases\n";
