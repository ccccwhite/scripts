<?php
declare(strict_types=1);
/**
 * One-time M-Team Tracker migration for Unraid.
 * Preview by default. --apply --limit=N changes at most N torrents per client.
 * Only tracker.m-team.cc => https://tra99.manfuz.co/announce.
 * Preserves each torrent's original query (credential/passkey). No reannounce.
 */
date_default_timezone_set('Asia/Shanghai');
define('PT_STORAGE_LIBRARY_ONLY', true);
require dirname(__DIR__).'/pt-storage-migrate/migrate.php';

function convertMteamUrl(string $url): ?string {
    $parts = parse_url($url);
    if (!is_array($parts)) return null;
    if (strtolower((string)($parts['scheme']??'')) !== 'https'
        || strtolower((string)($parts['host']??'')) !== 'tracker.m-team.cc'
        || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['fragment'])) return null;
    // Do not discard unknown path components: they may contain torrent credentials.
    if (!in_array((string)($parts['path']??''), ['/announce','/announce.php'], true)) return null;
    if (!isset($parts['query']) || (string)$parts['query'] === '') return null;
    return 'https://tra99.manfuz.co/announce?'.$parts['query'];
}

function oldMteamHost(string $url): bool {
    $p=parse_url($url);
    return is_array($p) && strtolower((string)($p['host']??''))==='tracker.m-team.cc';
}

function qbitAuth(array $qb): array {
    $cookie=null;$headers=[];
    $key=trim((string)($qb['api_key']??''));
    if ($key!=='') {
        $headers=['Authorization: Bearer '.$key];
        try {
            qreq($qb,'torrents/info?filter=all',null,$headers);
            return [$cookie,$headers];
        } catch(Throwable $e) {$headers=[];}
    }
    $username=(string)($qb['username']??$qb['user']??'');
    $password=(string)($qb['password']??$qb['pass']??'');
    if ($username==='' || $password==='') throw new RuntimeException('qBit login credentials missing');
    $cookie=tempnam(sys_get_temp_dir(),'mteam-switch-');
    if ($cookie===false) throw new RuntimeException('Unable to allocate qBit auth cookie');
    chmod($cookie,0600);
    [$code,$body]=httpReq(
        rtrim($qb['url'],'/').'/api/v2/auth/login','POST',
        http_build_query(['username'=>$username,'password'=>$password]),
        ['Content-Type: application/x-www-form-urlencoded','Referer: '.rtrim($qb['url'],'/').'/'],
        '','',$cookie);
    if (!($code===204 || ($code===200 && trim($body)==='Ok.')))
        throw new RuntimeException('qBit login failed HTTP '.$code);
    return [$cookie,$headers];
}

function trackerLines(string $list): array {
    return preg_split('/(\r\n|\n|\r)/', $list, -1, PREG_SPLIT_DELIM_CAPTURE);
}

$apply=in_array('--apply',$argv,true);
$preview=in_array('--preview',$argv,true);
if ($apply && $preview) {fwrite(STDERR,"Choose one mode\n");exit(1);}
$limit=1;
foreach(array_slice($argv,1) as $arg) {
    if (preg_match('/^--limit=([1-9][0-9]*)$/',$arg,$m)) $limit=(int)$m[1];
    elseif (!in_array($arg,['--apply','--preview'],true)) {fwrite(STDERR,"Unknown option\n");exit(1);}
}
if ($limit>20) {fwrite(STDERR,"Limit must be 1..20 per client\n");exit(1);}
$cfg=jsonArr((string)file_get_contents('/boot/config/plugins/user.scripts/pt-common/credentials.json'));
$qb=$cfg['qbittorrent']??null;
$tr=$cfg['transmission']??null;
if (!is_array($qb) || !is_array($tr)) throw new RuntimeException('Shared credentials missing');
[$cookie,$headers]=qbitAuth($qb);
$session='';
$stats=['qbit'=>['eligible'=>0,'changed'=>0,'skipped'=>0,'failed'=>0],
        'trans'=>['eligible'=>0,'changed'=>0,'skipped'=>0,'failed'=>0]];
echo ($apply?'APPLY':'PREVIEW')." M-Team tracker.m-team.cc -> tra99.manfuz.co (credential/query retained)\n";
try {
    // qBit API edits a single tracker URL per torrent, not a torrent's files or state.
    $qitems=qreq($qb,'torrents/info?filter=all',$cookie,$headers);
    foreach($qitems as $t) {
        $hash=strtolower((string)($t['hash']??''));
        if (!preg_match('/^[a-f0-9]{40}$/D',$hash)) continue;
        try {
            $rows=qreq($qb,'torrents/trackers?hash='.$hash,$cookie,$headers);
            $have=array_column($rows,'url');
            $edits=[];
            foreach($have as $url) {
                if (!is_string($url) || !oldMteamHost($url)) continue;
                $new=convertMteamUrl($url);
                if ($new===null || in_array($new,$have,true)) {$stats['qbit']['skipped']++;continue;}
                $edits[$url]=$new;
            }
            if (!$edits) continue;
            $stats['qbit']['eligible']++;
            echo 'qBit '.substr($hash,0,12).' eligible ('.count($edits).' tracker URL'.(count($edits)>1?'s':'').")\n";
            if (!$apply || $stats['qbit']['changed'] >= $limit) continue;
            $allOk=true;
            foreach($edits as $old=>$new) {
                $fresh=qreq($qb,'torrents/trackers?hash='.$hash,$cookie,$headers);
                if (!in_array($old,array_column($fresh,'url'),true)) { $allOk=false;break; }
                qwrite($qb,'torrents/editTracker',['hash'=>$hash,'url'=>$old,'newUrl'=>$new],$cookie,$headers);
                $after=qreq($qb,'torrents/trackers?hash='.$hash,$cookie,$headers);
                $newUrls=array_column($after,'url');
                if (!in_array($new,$newUrls,true) || in_array($old,$newUrls,true)) {
                    throw new RuntimeException('qBit tracker edit not confirmed');
                }
            }
            if (!$allOk) {echo "qBit changed during read; skipped\n";$stats['qbit']['skipped']++;continue;}
            $stats['qbit']['changed']++;
            echo 'qBit '.substr($hash,0,12)." UPDATED\n";
            sleep(10); // Avoid an announce burst if the client announces after editing.
        } catch(Throwable $e) {
            $stats['qbit']['failed']++;
            echo 'qBit '.substr($hash,0,12).' FAILED (review client/API status; no secrets printed)'."\n";
        }
    }
    // Transmission 4.1 tracker_list preserves other trackers and original tier separators.
    $list=rpc($tr,'torrent_get',['fields'=>['hash_string','tracker_list']],$session);
    foreach(($list['torrents']??[]) as $t) {
        $hash=strtolower((string)($t['hash_string']??''));
        if (!preg_match('/^[a-f0-9]{40}$/D',$hash)) continue;
        $raw=(string)($t['tracker_list']??'');
        $lines=trackerLines($raw);$count=0;
        foreach($lines as $i=>$line) {
            if (($i%2)!==0 || !oldMteamHost($line)) continue;
            $new=convertMteamUrl($line);
            if ($new===null) {$stats['trans']['skipped']++;continue;}
            $lines[$i]=$new;$count++;
        }
        if ($count===0) continue;
        $stats['trans']['eligible']++;
        echo 'Trans '.substr($hash,0,12).' eligible ('.$count." tracker URL)\n";
        if (!$apply || $stats['trans']['changed'] >= $limit) continue;
        try {
            $fresh=rpc($tr,'torrent_get',['ids'=>[$hash],'fields'=>['hash_string','tracker_list']],$session);
            $items=(array)($fresh['torrents']??[]);
            if (count($items)!==1 || strtolower((string)($items[0]['hash_string']??''))!==$hash
                || (string)($items[0]['tracker_list']??'')!==$raw) {
                echo 'Trans '.substr($hash,0,12)." changed during read; skipped\n";
                $stats['trans']['skipped']++;continue;
            }
            $wanted=implode('',$lines);
            rpc($tr,'torrent_set',['ids'=>[$hash],'tracker_list'=>$wanted],$session);
            $check=rpc($tr,'torrent_get',['ids'=>[$hash],'fields'=>['hash_string','tracker_list']],$session);
            if ((string)($check['torrents'][0]['tracker_list']??'')!==$wanted)
                throw new RuntimeException('Transmission tracker_list edit not confirmed');
            $stats['trans']['changed']++;
            echo 'Trans '.substr($hash,0,12)." UPDATED\n";
            sleep(10);
        } catch(Throwable $e) {
            $stats['trans']['failed']++;
            echo 'Trans '.substr($hash,0,12).' FAILED (review client/API status; no secrets printed)'."\n";
        }
    }
} finally {
    if ($cookie!==null) @unlink($cookie);
}
foreach($stats as $name=>$v)echo $name.' eligible='.$v['eligible'].' changed='.$v['changed']
    .' skipped='.$v['skipped'].' failed='.$v['failed']."\n";
echo "No forced reannounce, file moves, deletions or proxy changes.\n";
