<?php
declare(strict_types=1);
/**
 * Explicit, single-torrent repair for tasks started before the event router ran.
 * No automatic pausing, resuming, deletion, or queue mutation.
 * Default: dry-run. --apply requires the torrent to be stopped first.
 */
date_default_timezone_set('Asia/Shanghai');
function abortRepair(string $m): never { throw new RuntimeException($m); }
function jsonFile(string $f): array {
    if (!is_file($f)) abortRepair("Missing file: $f");
    $v=json_decode((string)file_get_contents($f),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($v)) abortRepair("Invalid JSON: $f");
    return $v;
}
final class QbitRepair {
    private string $url;
    private string $cookie='';
    public function __construct(array $c) {
        $this->url=rtrim((string)($c['url']??''),'/');
        if(!preg_match('~^https?://[a-zA-Z0-9.:-]+$~',$this->url)) abortRepair('Invalid qBit URL');
        $r=$this->call('auth/login',[
            'username'=>(string)($c['username']??$c['user']??''),
            'password'=>(string)($c['password']??$c['pass']??'')
        ],true);
        if($r!=='' && $r!=='Ok.') abortRepair('qBit authentication failed');
    }
    public function call(string $ep,?array $post=null,bool $login=false): string {
        $h=curl_init($this->url.'/api/v2/'.$ep);
        if(!$h) abortRepair('curl_init failed');
        $headers=['Origin: '.$this->url,'Referer: '.$this->url.'/'];
        if($this->cookie!=='')$headers[]='Cookie: '.$this->cookie;
        curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HEADERFUNCTION=>function($ch,string $line):int{
                if(preg_match('/^Set-Cookie:\\s*((?:SID|QBT_SID_[0-9]+)=[^;\\s]+)/i',$line,$m))
                    $this->cookie=$m[1];
                return strlen($line);
            }]);
        if($post!==null){curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,http_build_query($post));}
        $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);
        $error=curl_error($h);curl_close($h);
        if($body===false||!in_array($status,$login?[200,204]:($post===null?[200]:[200,204]),true))
            abortRepair("qBit $ep HTTP $status: $error");
        if(trim((string)$body)==='Fails.')abortRepair("qBit rejected $ep");
        return (string)$body;
    }
    public function get(string $ep): array {
        $v=json_decode($this->call($ep),true,512,JSON_THROW_ON_ERROR);
        return is_array($v)?$v:[];
    }
}
try {
    $args=$argv;array_shift($args);
    $hash=strtolower((string)($args[0]??''));
    $apply=in_array('--apply',$args,true);
    if(!preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D',$hash))
        abortRepair('Usage: php repair_existing.php HASH [--apply]');
    $base=__DIR__;
    $cfg=jsonFile($base.'/config.json');
    $credentials=jsonFile((string)$cfg['credentials_file']);
    $q=new QbitRepair((array)($credentials['qbittorrent']??$credentials['qbit']??[]));
    $items=$q->get('torrents/info?filter=all');
    $found=array_values(array_filter($items,fn($t)=>strtolower((string)($t['hash']??''))===$hash));
    if(count($found)!==1)abortRepair('Torrent absent or ambiguous');
    $t=$found[0];
    $cat=(string)($t['category']??'');
    if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D',$cat))abortRepair('Invalid category');
    $tags=array_map('trim',explode(',',(string)($t['tags']??'')));
    $targets=array_values(array_filter($tags,fn($x)=>str_starts_with($x,'target:')));
    if(count($targets)!==1)abortRepair('Exactly one target tag required');
    $disk=substr($targets[0],7);
    $allowed=array_merge(['ssd'],array_keys((array)$cfg['disk_roots']));
    if(!in_array($disk,$allowed,true))abortRepair('Unsupported target disk');
    // For historical repair only allow the established /temp source and SSD
    // destination. HDD routing with existing data requires separate physical
    // disk and user-share checks; do not silently move it through /mnt/user.
    if($disk!=='ssd')abortRepair('Existing-data HDD repair is not enabled; manual review needed');
    $current=rtrim((string)($t['save_path']??''),'/');
    $dest=rtrim((string)$cfg['ssd_container_root'],'/').'/qbit/'.$cat;
    if($current===$dest){echo "ALREADY CORRECT $dest\n";exit(0);}
    if($current!=='/temp')abortRepair('Only /temp root tasks supported; current='.$current);
    if(!str_starts_with($dest,'/temp/qbit/'))abortRepair('Unsafe destination');
    $files=$q->get('torrents/files?hash='.rawurlencode($hash));
    if(!$files)abortRepair('Missing file manifest');
    $hostDest=rtrim((string)$cfg['ssd_root'],'/').'/qbit/'.$cat;
    if(!is_dir($hostDest))abortRepair('Destination category directory missing: '.$hostDest);
    if(is_link($hostDest))abortRepair('Destination directory is a symbolic link');
    $names=[];
    foreach($files as $f){
        $name=$f['name']??null;
        if(!is_string($name)||$name===''||str_starts_with($name,'/')||
            str_contains($name,'\\')||preg_match('/[\\x00-\\x1f\\x7f]/',$name))
            abortRepair('Unsafe torrent file path');
        foreach(explode('/',$name) as $part)if($part===''||$part==='.'||$part==='..')
            abortRepair('Unsafe relative segment');
        if(isset($names[$name]))abortRepair('Duplicate relative file: '.$name);
        $names[$name]=true;
        // Check EVERY directory ancestor, including symlinks, before allowing writes.
        $parts=explode('/',$name);$p=$hostDest;
        foreach($parts as $part){
            $p.='/'.$part;
            if(is_link($p))abortRepair('Symlink in destination: '.$p);
        }
        if(file_exists($p))abortRepair('Destination already has data: '.$p);
    }
    $sourceHost=rtrim((string)$cfg['ssd_root'],'/');
    $present=0;
    foreach(array_keys($names) as $name)if(file_exists($sourceHost.'/'.$name))$present++;
    echo "Torrent: ".($t['name']??$hash)."\n";
    echo "Hash: $hash\nCategory: $cat\nCurrent: $current\nTarget: $dest\n";
    echo "Torrent files: ".count($names)."; currently present at source: $present\n";
    echo "State: ".($t['state']??'')."; progress: ".round(100*(float)($t['progress']??0),2)."%\n";
    echo "WARNING: Native setLocation may move data. Snapshot/hash verification required afterwards.\n";
    if(!$apply){echo "DRY RUN ONLY: rerun with --apply after stopping this torrent and inspecting destination.\n";exit(0);}
    if(!preg_match('/^(stopped|paused)/',(string)($t['state']??'')))
        abortRepair('Stop this torrent in qBittorrent before --apply');
    if((bool)($t['auto_tmm']??true))abortRepair('Auto torrent management must be disabled');
    if($present===0)abortRepair('No source content found; refusing potentially incorrect layout');
    // Query state again directly before mutation, reducing the chance of a
    // concurrent manual start. The caller must ensure no other manager resumes.
    $fresh=$q->get('torrents/info?filter=all');
    $again=array_values(array_filter($fresh,fn($x)=>strtolower((string)($x['hash']??''))===$hash));
    if(count($again)!==1||!preg_match('/^(stopped|paused)/',(string)($again[0]['state']??''))||
        rtrim((string)($again[0]['save_path']??''),'/')!==$current)
        abortRepair('Torrent state/path changed; retry dry-run');
    $q->call('torrents/setLocation',['hashes'=>$hash,'location'=>$dest]);
    echo "MOVE REQUEST SENT: inspect qBit state and complete a force recheck before resuming.\n";
    echo "No queue/state files or torrent tags were modified.\n";
} catch(Throwable $e){fwrite(STDERR,'REPAIR ERROR: '.$e->getMessage()."\n");exit(1);}
