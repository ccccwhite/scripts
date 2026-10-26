<?php
declare(strict_types=1);
/** Unraid PHP PT storage label sync and conservative Transmission -> HDD mover. */
function httpReq(string $url,string $method='GET',?string $body=null,array $headers=[],string $user='',string $pass='',?string $cookie=null): array {
    $h=[];$c=curl_init($url);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_TIMEOUT=>45,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$h){$p=strpos($line,':');if($p!==false)$h[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));return strlen($line);}]);
    if($body!==null)curl_setopt($c,CURLOPT_POSTFIELDS,$body);
    if($user!=='')curl_setopt($c,CURLOPT_USERPWD,$user.':'.$pass);
    if($cookie!==null){curl_setopt($c,CURLOPT_COOKIEJAR,$cookie);curl_setopt($c,CURLOPT_COOKIEFILE,$cookie);}
    $res=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$err=curl_error($c);curl_close($c);
    if($res===false)throw new RuntimeException('HTTP error: '.$err);
    return [$status,(string)$res,$h];
}
function jsonArr(string $s): array {$v=json_decode($s,true,512,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException('Invalid JSON');return $v;}
function rpc(array $tr,string $method,array $params,string &$session): array {
    static $n=0;$id=++$n;$url=$tr['rpc_url'];
    $body=json_encode(['jsonrpc'=>'2.0','method'=>$method,'params'=>$params,'id'=>$id],JSON_THROW_ON_ERROR);
    for($i=0;$i<3;$i++){
        $headers=['Content-Type: application/json'];if($session!=='')$headers[]='X-Transmission-Session-Id: '.$session;
        [$status,$response,$rh]=httpReq($url,'POST',$body,$headers,$tr['username']??'',$tr['password']??'');
        if($status===409){$session=$rh['x-transmission-session-id']??'';if($session==='')throw new RuntimeException('Transmission session ID missing');continue;}
        if($status!==200)throw new RuntimeException('Transmission HTTP '.$status);
        $result=jsonArr($response);if(isset($result['error']))throw new RuntimeException('Transmission: '.json_encode($result['error']));
        if(($result['id']??null)!==$id||!is_array($result['result']??null))throw new RuntimeException('Invalid Transmission RPC result');
        return $result['result'];
    }
    throw new RuntimeException('Transmission session handshake failed');
}
function norm(string $s): string {return '/'.trim(preg_replace('~/+~','/',$s),'/');}
function within(string $path,string $root): bool {$path=norm($path);$root=norm($root);return $path===$root||str_starts_with($path,$root.'/');}
function location(string $p,string $ssd,string $hdd,string $download): ?string {
    if(within($p,$ssd))return 'ssd';
    if(within($p,$download))return 'download';
    if(within($p,$hdd))return 'hdd';
    return null;
}
function changeLabels(array $labels,?string $loc,array $cfg): array {
    if($loc===null)return $labels;
    $out=array_values(array_filter($labels,fn($x)=>!in_array($x,[$cfg['ssd_label'],$cfg['download_label'],$cfg['hdd_label']],true)));
    $out[]=match($loc){'ssd'=>$cfg['ssd_label'],'download'=>$cfg['download_label'],'hdd'=>$cfg['hdd_label']};
    return array_values(array_unique($out));
}
function sameSet(array $a,array $b): bool {sort($a);sort($b);return $a===$b;}
function say(string $s): void {echo '['.date('H:i:s').'] '.$s.PHP_EOL;}
function qreq(array $qb,string $endpoint,?string $cookie,array $headers,string $method='GET',?array $form=null): array {
    $url=rtrim($qb['url'],'/').'/api/v2/'.$endpoint;
    [$status,$body]=httpReq($url,$method,$form===null?null:http_build_query($form),$form===null?$headers:array_merge($headers,['Content-Type: application/x-www-form-urlencoded']),'','',$cookie);
    if($status!==200&&$status!==204)throw new RuntimeException('qBittorrent '.$endpoint.' HTTP '.$status);
    return $body===''?[]:jsonArr($body);
}
function qwrite(array $qb,string $endpoint,array $form,?string $cookie,array $headers): void {
    $url=rtrim($qb['url'],'/').'/api/v2/'.$endpoint;
    [$status,$body]=httpReq($url,'POST',http_build_query($form),array_merge($headers,['Content-Type: application/x-www-form-urlencoded']),'','',$cookie);
    if($status!==200&&$status!==204)throw new RuntimeException('qBittorrent write '.$endpoint.' HTTP '.$status);
}
function pathsOverlap(string $a,string $b): bool {return within($a,$b)||within($b,$a);}

function qbitStopped(array $q): bool {
    $state=(string)($q['state']??'');
    return in_array($state,['stoppedUP','stoppedDL','pausedUP','pausedDL'],true);
}
function qbitComplete(array $q): bool {
    return (float)($q['progress']??0)>=0.999999 && (int)($q['amount_left']??-1)===0;
}
function qbitPath(array $q): array {
    $save=norm((string)($q['save_path']??'/'));
    $content=norm((string)($q['content_path']??'/'));
    return ['path'=>$content!=='/'?$content:$save,'save'=>$save];
}
function qbitMoveGuards(array $qitems,array $files,string $hash): array {
    $toRemove=[];
    foreach($qitems as $q){
        $qh=strtolower((string)($q['hash']??''));
        $p=qbitPath($q);
        $overlap=false;
        foreach($files as $f){
            if(pathsOverlap($f,$p['path'])||($p['path']===$p['save']&&within($f,$p['save']))){$overlap=true;break;}
        }
        if(!$overlap)continue;
        if($qh!==$hash)return [null,'shared with other qBittorrent task '.substr($qh,0,12)];
        if(!qbitStopped($q))return [null,'matching qBittorrent task is not paused/stopped ('.($q['state']??'?').')'];
        if(!qbitComplete($q))return [null,'matching qBittorrent task is incomplete'];
        $toRemove[$qh]=true;
    }
    return [array_keys($toRemove),null];
}
function qbitRemoveTask(array $qb,string $hash,?string $cookie,array $headers): void {
    // deleteFiles=false is essential: remove only qBittorrent metadata, never the data.
    qwrite($qb,'torrents/delete',['hashes'=>$hash,'deleteFiles'=>'false'],$cookie,$headers);
}
function inferSite(array $t,array $qitems,array $cfg): array {
    $dir=norm((string)($t['download_dir']??'/'));
    $hash=strtolower((string)($t['hash_string']??''));
    $ignored=array_map('strtolower',[$cfg['ssd_label'],$cfg['download_label'],$cfg['hdd_label'],$cfg['move_label']]);
    $generic=['qbit','qbittorrent','trans','transmission','download','downloads','complete','completed','incomplete','temp','hdd','ssd'];
    $hints=[];
    foreach((array)($t['labels']??[]) as $label){
        $v=strtolower(trim((string)$label));
        if($v!==''&&!in_array($v,$ignored,true)&&!in_array($v,$generic,true)&&!str_contains($v,':')&&preg_match('/^[a-z0-9_-]+$/',$v))$hints[$v]=true;
    }
    foreach($qitems as $q){
        if(strtolower((string)($q['hash']??''))!==$hash)continue;
        $v=strtolower(trim((string)($q['category']??'')));
        if($v!==''&&!in_array($v,$generic,true)&&preg_match('/^[a-z0-9_-]+$/',$v))$hints[$v]=true;
    }
    $parts=[];
    foreach([norm($cfg['ssd_path']),norm($cfg['download_path']),norm($cfg['hdd_path'])] as $root){
        if(within($dir,$root)&&$dir!==$root){
            $parts=array_values(array_filter(explode('/',trim(substr($dir,strlen($root)),'/')),'strlen'));
            break;
        }
    }
    // Explicit downloader labels/category must agree. Path components may contain generic client folders.
    if(count($hints)>1)return [null,'conflicting site labels/categories: '.implode(',',array_keys($hints))];
    if(count($hints)===1){
        $site=array_key_first($hints);
        // If the path has a non-generic final directory, it must agree with the explicit site.
        $last=strtolower((string)end($parts));
        if($last!==''&&!in_array($last,$generic,true)&&$last!==$site&&count($parts)>0)
            return [null,'site path conflicts with label/category: '.$last.' vs '.$site];
        return [$site,null];
    }
    // Without labels, accept only a single direct site directory, not nested downloader paths.
    if(count($parts)===1){
        $site=strtolower($parts[0]);
        if(!in_array($site,$generic,true)&&preg_match('/^[a-z0-9_-]+$/',$site))return [$site,null];
    }
    return [null,'site missing or ambiguous'];
}
function saveState(string $path,array $state): void {
    $tmp=tempnam(dirname($path),'.storage-');
    if($tmp===false)throw new RuntimeException('Cannot create state file');
    try {
        $data=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        $fp=fopen($tmp,'wb');if($fp===false)throw new RuntimeException('Cannot open state temporary file');
        try {
            if(fwrite($fp,$data)!==strlen($data)||!fflush($fp))throw new RuntimeException('Cannot write complete state');
            if(function_exists('fsync')&&!fsync($fp))throw new RuntimeException('Cannot flush state to disk');
        } finally {fclose($fp);}
        @chmod($tmp,0600);
        if(!rename($tmp,$path))throw new RuntimeException('Cannot save state');
    } finally {if(is_file($tmp))@unlink($tmp);}
}
function observeVerification(array $tr,int $id,string &$session,int $seconds=12): bool {
    // A fast verification may finish between scheduled runs. Observe it immediately.
    $until=microtime(true)+$seconds;
    do {
        $items=rpc($tr,'torrent_get',['ids'=>[$id],'fields'=>['id','status']],$session)['torrents']??[];
        if(count($items)===1 && in_array((int)($items[0]['status']??-1),[1,2],true))return true;
        usleep(250000);
    } while(microtime(true)<$until);
    return false; // Fail closed: do not auto-delete qBittorrent metadata.
}
function verifiedData(array $t): bool {
    $total=(int)($t['size_when_done']??0);
    return $total>0
        && (int)($t['have_valid']??-1)===$total
        && (int)($t['have_unchecked']??-1)===0
        && (int)($t['left_until_done']??-1)===0
        && (float)($t['percent_done']??0)>=0.999999
        && !in_array((int)($t['status']??-1),[1,2],true);
}
function completionReady(array $t,bool $fullRecheck): bool {
    // A native move preserves Transmission's existing piece state. Do not claim a new hash check.
    return in_array((int)($t['error']??-1),[0,1,2],true)
        && (int)($t['size_when_done']??0)>0
        && (int)($t['left_until_done']??-1)===0
        && (float)($t['percent_done']??0)>=0.999999
        && in_array((int)($t['status']??-1),[0,3,4,5,6],true)
        && (!$fullRecheck||verifiedData($t));
}
function verifyDestination(array $torrent,array $pending,array $cfg): array {
    try {
        assertMounted($cfg);pendingMatches($torrent,$pending,$cfg);
        $root=destinationRoot($pending,$cfg);
        if(norm((string)($torrent['download_dir']??'/'))!==norm($pending['destination']))return [false,'save path not updated'];
        foreach(manifest($torrent) as $name=>$length){
            $path=safeHostPath($root,$name);
            if(!is_file($path))return [false,'missing file: '.$name];
            clearstatcache(true,$path);
            if(filesize($path)!==$length)return [false,'size mismatch: '.$name];
        }
        return [true,'path and file sizes confirmed'];
    }catch(Throwable $e){return [false,$e->getMessage()];}
}
function safeRelative(string $name): bool {
    return $name!=='' && !str_starts_with($name,'/') && !str_contains($name,"\\")
        && !str_contains($name,"\0") && !preg_match('~(^|/)\.{1,2}(/|$)|//~',$name);
}
function absolutePath(string $path): string {
    if(!str_starts_with($path,'/')||str_contains($path,"\0")||str_contains($path,"\\")||preg_match('~(^|/)\.{1,2}(/|$)~',$path))
        throw new RuntimeException('Unsafe absolute path');
    return norm($path);
}
function safeHostPath(string $root,string $relative): string {
    $root=absolutePath($root);
    if($root==='/'||!safeRelative($relative))throw new RuntimeException('Unsafe destination path');
    $cursor='';
    $parts=explode('/',trim($root.'/'.$relative,'/'));
    foreach($parts as $i=>$part){
        $cursor.='/'.$part;
        clearstatcache(true,$cursor);
        if(is_link($cursor))throw new RuntimeException('Symlink in destination: '.$cursor);
        if($i<count($parts)-1&&file_exists($cursor)&&!is_dir($cursor))
            throw new RuntimeException('Destination parent is not a directory: '.$cursor);
    }
    return $root.'/'.$relative;
}
function destinationRoot(array $entry,array $cfg): string {
    $dest=absolutePath((string)$entry['destination']);$hdd=absolutePath($cfg['hdd_path']);
    if(!within($dest,$hdd)||$dest===$hdd)throw new RuntimeException('Pending destination outside HDD');
    if(isset($entry['hdd_host_path'])&&absolutePath($entry['hdd_host_path'])!==absolutePath($cfg['hdd_host_path']))
        throw new RuntimeException('HDD mapping changed during pending migration');
    if(isset($entry['hdd_path'])&&absolutePath($entry['hdd_path'])!==$hdd)
        throw new RuntimeException('Container HDD mapping changed during pending migration');
    $relative=substr($dest,strlen($hdd)+1);
    if(!preg_match('/^[a-zA-Z0-9_-]+$/',$relative))throw new RuntimeException('Unsafe site folder');
    return safeHostPath($cfg['hdd_host_path'],$relative);
}
function assertMounted(array $cfg): void {
    $host=absolutePath($cfg['hdd_host_path']);
    safeHostPath($host,'mount-check');
    if(!is_dir($host)||!is_writable($host))throw new RuntimeException('HDD destination unavailable');
    $mount=trim((string)shell_exec('findmnt -rn -M '.escapeshellarg($host).' -o TARGET 2>/dev/null'));
    if($mount!==$host)throw new RuntimeException('HDD is not mounted at '.$host);
}
function manifest(array $torrent): array {
    $files=[];
    foreach((array)($torrent['files']??[]) as $f){
        $name=(string)($f['name']??'');$length=$f['length']??-1;
        if(!safeRelative($name)||!is_int($length)||$length<0||isset($files[$name]))throw new RuntimeException('Unsafe/duplicate file manifest');
        $files[$name]=$length;
    }
    if(!$files)throw new RuntimeException('Empty file manifest');
    ksort($files);return $files;
}
function pendingMatches(array $t,array $entry,array $cfg): void {
    $source=absolutePath((string)$entry['source']);destinationRoot($entry,$cfg);
    if(!within($source,absolutePath($cfg['ssd_path']))&&!within($source,absolutePath($cfg['download_path']??'/download-hdd')))
        throw new RuntimeException('Pending source outside configured download roots');
    if(isset($entry['files'])&&manifest(['files'=>$entry['files']])!==manifest($t))
        throw new RuntimeException('Pending file manifest changed; manual review required');
}
function preflightDestination(array $t,array $entry,array $cfg): void {
    assertMounted($cfg);$root=destinationRoot($entry,$cfg);$bytes=0;
    foreach(manifest($t) as $name=>$length){
        $target=safeHostPath($root,$name);
        if(file_exists($target)||is_link($target))throw new RuntimeException('Destination already exists: '.$target);
        $bytes+=$length;
    }
    clearstatcache();$free=disk_free_space($cfg['hdd_host_path']);
    if($free===false||$free<$bytes+($cfg['_reserved_bytes']??0)+max(1073741824,(int)ceil($bytes*0.05)))throw new RuntimeException('Insufficient HDD free space');
    if(!is_dir($root)&&!mkdir($root,0775,true)&&!is_dir($root))throw new RuntimeException('Cannot create site directory');
    destinationRoot($entry,$cfg);
}
function transmissionItems(array $tr,string &$session): array {
    return rpc($tr,'torrent_get',['fields'=>['id','hash_string','name','labels','download_dir','percent_done','files','status','error','error_string','have_valid','have_unchecked','size_when_done','left_until_done']],$session)['torrents']??[];
}
function findTorrent(array $items,string $hash): array {
    $found=array_values(array_filter($items,fn($t)=>strtolower((string)($t['hash_string']??''))===$hash));
    if(count($found)!==1)throw new RuntimeException('Transmission task disappeared or is ambiguous: '.$hash);
    return $found[0];
}
function torrentPaths(array $t,?string $dir=null): array {
    $root=absolutePath($dir??(string)$t['download_dir']);
    return array_map(fn($name)=>$root.'/'.$name,array_keys(manifest($t)));
}
// A path trie detects identical files and file/parent collisions without pairwise file comparisons.
function transmissionIndex(array $items): array {
    $index=[];
    foreach($items as $t)foreach(torrentPaths($t) as $path){
        $node=&$index;
        foreach(explode('/',trim($path,'/')) as $part){$node['children'][$part]??=[];$node=&$node['children'][$part];}
        $node['owners'][(int)$t['id']]=true;unset($node);
    }
    return $index;
}
function otherOwner(array $node,int $id): ?int {
    foreach(($node['owners']??[]) as $owner=>$_)if($owner!==$id)return $owner;
    foreach(($node['children']??[]) as $child){$owner=otherOwner($child,$id);if($owner!==null)return $owner;}
    return null;
}
function sharedTransmission(array $index,array $paths,int $id): ?int {
    foreach($paths as $path){
        $node=$index;$exists=true;
        foreach(explode('/',trim($path,'/')) as $part){
            foreach(($node['owners']??[]) as $owner=>$_)if($owner!==$id)return $owner;
            if(!isset($node['children'][$part])){$exists=false;break;}
            $node=$node['children'][$part];
        }
        if($exists){$owner=otherOwner($node,$id);if($owner!==null)return $owner;}
    }
    return null;
}
function deletionPlan(array $entry,array $qitems,array $remove): array {
    $expected=(array)($entry['qbit_hashes']??[]);$present=[];
    foreach($qitems as $q)$present[strtolower((string)($q['hash']??''))]=true;
    $phase=$entry['phase']??'';
    foreach($remove as $hash)if(!in_array($hash,$expected,true))throw new RuntimeException('Unexpected qBittorrent task; manual review required');
    foreach($expected as $hash){
        if(isset($present[$hash])){
            if($phase==='qbit_removed'||!in_array($hash,$remove,true))throw new RuntimeException('qBittorrent task changed; manual review required');
        }elseif(!in_array($phase,['deleting_qbit','qbit_removed'],true)){
            throw new RuntimeException('qBittorrent task disappeared before recorded deletion intent');
        }
    }
    return $remove;
}
function sendMove(array $tr,array $t,string $hash,array &$state,string $statePath,string &$session): void {
    $state[$hash]['phase']='move_requested';unset($state[$hash]['last_error']);
    saveState($statePath,$state); // Write intent before RPC: a timeout must never trigger an automatic duplicate move.
    try {
        rpc($tr,'torrent_set_location',['ids'=>[(int)$t['id']],'location'=>$state[$hash]['destination'],'move'=>true],$session);
        $state[$hash]['phase']='moving';saveState($statePath,$state);
    }catch(Throwable $e){
        $state[$hash]['last_error']=$e->getMessage();saveState($statePath,$state);throw $e;
    }
}
function reservedBytes(array $state,string $except=''): int {
    $total=0;
    foreach($state as $hash=>$entry)if($hash!==$except)$total+=(int)($entry['total_bytes']??array_sum(manifest(['files'=>$entry['files']??[]])));
    return $total; // Conservative: already allocated blocks are not subtracted from this reservation.
}

if(defined('PT_STORAGE_LIBRARY_ONLY'))return;

try {
    if(!extension_loaded('curl'))throw new RuntimeException('PHP cURL required');
    $configFile=$argv[1]??__DIR__.'/config.json';
    $cfg=jsonArr((string)file_get_contents($configFile));
    $statePath=$cfg['state_file']??dirname($configFile).'/state.json';
    $lockPath=dirname($statePath).'/run.lock';
    if(is_link($lockPath))throw new RuntimeException('Unsafe lock file');
    $runLock=fopen($lockPath,'c');
    if($runLock===false)throw new RuntimeException('Cannot open migration lock');
    if(!flock($runLock,LOCK_EX|LOCK_NB)){say('Another migration process is running; skipped');exit(0);}
    // Hold this handle until process exit. Direct PHP invocations use the same lock as the wrapper.
    $maxPending=$cfg['max_pending_moves']??1;
    if(!is_int($maxPending)||$maxPending<1)throw new RuntimeException('max_pending_moves must be a positive integer');
    $retryHash='';$recheckHash='';$acceptHash='';
    foreach(array_slice($argv,2) as $arg){
        if(!preg_match('/^--(retry-move|recheck|accept-verified)=([a-fA-F0-9]{40}|[a-fA-F0-9]{64})$/',$arg,$match))
            throw new RuntimeException('Unknown option: '.$arg);
        $target=strtolower($match[2]);
        if($match[1]==='retry-move')$retryHash=$target;
        elseif($match[1]==='recheck')$recheckHash=$target;
        else $acceptHash=$target;
    }
    $credFile=$cfg['credentials_file']??'';
    if(!is_file($credFile))throw new RuntimeException('Local credentials missing');
    $cred=jsonArr((string)file_get_contents($credFile));
    $qb=$cred['qbittorrent'];$tr=$cred['transmission'];
    foreach(['dry_run','enable_move','delete_qbit_after_move','verify_after_move'] as $key)
        if(isset($cfg[$key])&&!is_bool($cfg[$key]))throw new RuntimeException($key.' must be a JSON boolean');
    $dry=$cfg['dry_run']??true;$moveEnabled=$cfg['enable_move']??false;
    $cfg['download_path']=$cfg['download_path']??'/download-hdd';
    $cfg['download_label']=$cfg['download_label']??'storage:download-hdd';
    $ssd=absolutePath($cfg['ssd_path']);$hdd=absolutePath($cfg['hdd_path']);$download=absolutePath($cfg['download_path']);
    $cfg['hdd_host_path']=absolutePath($cfg['hdd_host_path']);
    $roots=[$ssd,$download,$hdd];
    foreach($roots as $i=>$root){
        if($root==='/')throw new RuntimeException('Unsafe storage root');
        foreach($roots as $j=>$other)if($i!==$j&&within($root,$other))
            throw new RuntimeException('Overlapping storage roots: '.$root.' and '.$other);
    }
    $state=is_file($statePath)?jsonArr((string)file_get_contents($statePath)):[];
    foreach([$retryHash,$recheckHash,$acceptHash] as $requested)
        if($requested!==''&&!isset($state[$requested]))throw new RuntimeException('No pending migration for option hash: '.$requested);
    $session='';$cookie=null;$headers=[];
    $qKey=trim((string)($qb['api_key']??''));
    try {
        if($qKey!=='') {
            $headers=['Authorization: Bearer '.$qKey];
            try {$qitems=qreq($qb,'torrents/info?filter=all',null,$headers);}
            catch(Throwable $e){$headers=[];$qKey='';}
        }
        if($qKey==='') {
            $cookie=tempnam(sys_get_temp_dir(),'qbstore');
            if($cookie===false)throw new RuntimeException('Cannot create cookie');
            [$status,$body]=httpReq(rtrim($qb['url'],'/').'/api/v2/auth/login','POST',http_build_query(['username'=>$qb['username']??'','password'=>$qb['password']??'']),['Content-Type: application/x-www-form-urlencoded','Referer: '.rtrim($qb['url'],'/').'/'],'','',$cookie);
            if(!($status===204||($status===200&&trim($body)==='Ok.')))throw new RuntimeException('qBittorrent login failed: '.$status);
            $qitems=qreq($qb,'torrents/info?filter=all',$cookie,[]);
        }
        $titems=transmissionItems($tr,$session);
        $liveHashes=array_fill_keys(array_map(fn($t)=>strtolower((string)$t['hash_string']),$titems),true);
        foreach($state as $pendingHash=>$_)if(!isset($liveHashes[$pendingHash]))
            say('PENDING '.$pendingHash.' : Transmission task missing; keep state for manual review');
        say('qBittorrent '.count($qitems).', Transmission '.count($titems).', mode '.($dry?'DRY RUN':'WRITE').', move '.($moveEnabled?'enabled':'disabled'));
        $qchanges=0;$tchanges=0;$candidates=0;
        foreach($qitems as $q) {
            $hash=strtolower((string)($q['hash']??''));$save=norm((string)($q['save_path']??'/'));
            $content=norm((string)($q['content_path']??'/'));
            $loc=location($save,$ssd,$hdd,$download);
            if($loc===null||($content!=='/'&&location($content,$ssd,$hdd,$download)!==$loc)) {
                say('WARN qBit '.$hash.' ambiguous/outside storage; skip labels');
            } else {
                $old=array_values(array_filter(array_map('trim',explode(',',(string)($q['tags']??''))),'strlen'));
                $new=changeLabels($old,$loc,$cfg);
                if(!sameSet($old,$new)) {
                    say(($dry?'PREVIEW':'UPDATE').' qBit '.substr($hash,0,12).' storage:'.$loc);
                    if(!$dry){
                        $remove=array_values(array_diff($old,$new));$add=array_values(array_diff($new,$old));
                        if($remove)qwrite($qb,'torrents/removeTags',['hashes'=>$hash,'tags'=>implode(',',$remove)],$cookie,$headers);
                        if($add)qwrite($qb,'torrents/addTags',['hashes'=>$hash,'tags'=>implode(',',$add)],$cookie,$headers);
                    }
                    $qchanges++;
                }
            }
        }
        $tindex=transmissionIndex($titems);
        foreach($titems as $t) {
            $id=(int)$t['id'];$hash=strtolower((string)($t['hash_string']??''));$name=(string)($t['name']??'');
            $dir=norm((string)($t['download_dir']??'/'));$loc=location($dir,$ssd,$hdd,$download);
            $old=array_values(array_filter((array)($t['labels']??[]),'is_string'));
            if(isset($state[$hash])){
                // Re-read by hash so deleted/re-added tasks cannot reuse a stale numeric ID.
                $t=findTorrent(transmissionItems($tr,$session),$hash);
                $id=(int)$t['id'];$old=(array)($t['labels']??[]);
                pendingMatches($t,$state[$hash],$cfg);
                if($retryHash===$hash){
                    if(!empty($state[$hash]['verify_requested'])||norm($t['download_dir'])!==norm($state[$hash]['source']))
                        throw new RuntimeException('Retry requires original source path and no verification request');
                    $qitems=qreq($qb,'torrents/info?filter=all',$cookie,$headers);
                    $fresh=transmissionItems($tr,$session);$t=findTorrent($fresh,$hash);$id=(int)$t['id'];
                    if(norm($t['download_dir'])!==norm($state[$hash]['source'])||!completionReady($t,false))
                        throw new RuntimeException('Retry source path/completeness changed');
                    $paths=torrentPaths($t);
                    $shared=sharedTransmission(transmissionIndex($fresh),$paths,$id);
                    if($shared!==null)throw new RuntimeException('Retry source shared with Transmission task '.$shared);
                    [$remove,$error]=qbitMoveGuards($qitems,$paths,$hash);
                    if($error!==null)throw new RuntimeException($error);
                    deletionPlan($state[$hash],$qitems,$remove);
                    if($remove&&!($cfg['delete_qbit_after_move']??false))throw new RuntimeException('Retry requires delete_qbit_after_move=true');
                    if($dry||!$moveEnabled){say('PREVIEW RETRY '.$hash);continue;}
                    $retryCfg=$cfg;$retryCfg['_reserved_bytes']=reservedBytes($state,$hash);
                    preflightDestination($t,$state[$hash],$retryCfg);
                    sendMove($tr,$t,$hash,$state,$statePath,$session);
                    say('RETRY ACCEPTED '.$hash);continue;
                }
                if($recheckHash===$hash){
                    [$valid,$why]=verifyDestination($t,$state[$hash],$cfg);
                    if(!$valid)throw new RuntimeException('Cannot recheck: '.$why);
                    if(in_array((int)$t['status'],[1,2],true))throw new RuntimeException('Verification already running; wait');
                    if(in_array($state[$hash]['phase']??'',['deleting_qbit','qbit_removed'],true))throw new RuntimeException('Deletion already started; resume finalization instead');
                    if(!$dry&&$moveEnabled){
                        $state[$hash]['force_verify']=true;
                        $state[$hash]['verify_requested']=false;$state[$hash]['seen_verifying']=false;
                        unset($state[$hash]['manual_verification']);saveState($statePath,$state);
                    }else{say('PREVIEW RECHECK '.$hash);continue;}
                }
                if($acceptHash===$hash){
                    [$valid,$why]=verifyDestination($t,$state[$hash],$cfg);
                    if(!$valid||empty($state[$hash]['verify_requested'])||!verifiedData($t))
                        throw new RuntimeException('Manual review requires requested and completed verification plus valid destination: '.$why);
                    if(!$dry&&$moveEnabled){
                        $state[$hash]['force_verify']=true;
                        $state[$hash]['manual_verification']=date(DATE_ATOM);saveState($statePath,$state);
                    }else{say('PREVIEW ACCEPT VERIFIED '.$hash);continue;}
                }
                [$ok,$message]=verifyDestination($t,$state[$hash],$cfg);
                if($ok){
                    $fullRecheck=($cfg['verify_after_move']??false)||!empty($state[$hash]['force_verify']);
                    // Native move needs no extra hash pass. Request one only when explicitly enabled.
                    if($fullRecheck&&empty($state[$hash]['verify_requested'])&&!in_array((int)($t['status']??-1),[1,2],true)){
                        say(($dry?'PREVIEW VERIFY':'VERIFY').' '.substr($hash,0,12).' : destination sizes OK; require hash recheck');
                        if(!$dry&&$moveEnabled){
                            // Persist request intent before RPC; after interruption re-request explicitly with --recheck.
                            $state[$hash]['verify_requested']=true;$state[$hash]['phase']='verifying';
                            saveState($statePath,$state);
                            rpc($tr,'torrent_verify',['ids'=>[$id]],$session);
                            $state[$hash]['seen_verifying']=observeVerification($tr,$id,$session);
                            saveState($statePath,$state);
                            say('VERIFY OBSERVATION '.substr($hash,0,12).' : '.($state[$hash]['seen_verifying']?'verification phase observed':'phase not observed; manual review required'));
                        }
                        continue;
                    }
                    if(in_array((int)($t['status']??-1),[1,2],true)){
                        if(empty($state[$hash]['seen_verifying'])&&!$dry&&$moveEnabled){
                            $state[$hash]['seen_verifying']=true;
                            saveState($statePath,$state);
                        }
                        say('PENDING '.substr($hash,0,12).' : Transmission verifying');
                        continue;
                    }
                    // Require observed verification, or an explicit audited manual confirmation.
                    if($fullRecheck&&empty($state[$hash]['seen_verifying'])&&empty($state[$hash]['manual_verification'])){
                        say('PENDING '.substr($hash,0,12).' : verification phase not observed; use --recheck=HASH or documented manual review');
                        continue;
                    }
                    // Tracker errors (e.g. HTTP 404) do not imply corrupted local data.
                    if(!completionReady($t,$fullRecheck)){
                        say('PENDING '.substr($hash,0,12).' : local task data incomplete, local error, or verification unfinished');
                        continue;
                    }
                    $t=findTorrent(transmissionItems($tr,$session),$hash);$id=(int)$t['id'];
                    [$freshValid,$why]=verifyDestination($t,$state[$hash],$cfg);
                    if(!$freshValid||!completionReady($t,$fullRecheck))throw new RuntimeException('Destination changed before deletion: '.$why);
                    // The matching qBittorrent task must still be stopped and complete.
                    // Re-evaluate all overlapping qBittorrent paths immediately before deletion.
                    $sourceFiles=[];
                    foreach((array)($t['files']??[]) as $file){
                        $fn=(string)($file['name']??'');
                        if($fn===''||str_starts_with($fn,'/')||preg_match('~(^|/)\.\.(/|$)~',$fn))throw new RuntimeException('Unsafe pending file name');
                        $sourceFiles[]=norm($state[$hash]['source'].'/'.$fn);
                    }
                    $qitems=qreq($qb,'torrents/info?filter=all',$cookie,$headers);
                    [$qremove,$qerror]=qbitMoveGuards($qitems,array_merge($sourceFiles,torrentPaths($t)),$hash);
                    if($qerror!==null){
                        say('PENDING '.substr($hash,0,12).' : qBittorrent safety check: '.$qerror);
                        continue;
                    }
                    $qremove=deletionPlan($state[$hash],$qitems,$qremove);
                    if($qremove&&!($cfg['delete_qbit_after_move']??false)){
                        say('PENDING '.substr($hash,0,12).' : qBittorrent removal requires delete_qbit_after_move=true');
                        continue;
                    }
                    if($qremove){
                        say(($dry||!$moveEnabled?'PREVIEW DELETE qBittorrent':'DELETE qBittorrent task only').' '.implode(',',$qremove));
                        if(!$dry&&$moveEnabled){
                            $state[$hash]['phase']='deleting_qbit';saveState($statePath,$state);
                            foreach($qremove as $qh)qbitRemoveTask($qb,$qh,$cookie,$headers);
                            $current=qreq($qb,'torrents/info?filter=all',$cookie,$headers);
                            foreach($current as $item)if(in_array(strtolower((string)($item['hash']??'')),$qremove,true))
                                throw new RuntimeException('qBittorrent task still present after delete; will retry safely');
                        }
                    }
                    if($dry||!$moveEnabled){
                        say('PREVIEW COMPLETE '.substr($hash,0,12).' : destination and task removal requirements satisfied');
                        continue;
                    }
                    $state[$hash]['phase']='qbit_removed';saveState($statePath,$state);
                    // Preserve labels added by the user while this run was processing other tasks.
                    $t=findTorrent(transmissionItems($tr,$session),$hash);$id=(int)$t['id'];
                    [$stillValid,$why]=verifyDestination($t,$state[$hash],$cfg);
                    if(!$stillValid||!completionReady($t,$fullRecheck))throw new RuntimeException('Destination changed before finalization: '.$why);
                    $old=(array)($t['labels']??[]);
                    $fixed=array_values(array_unique(array_merge(array_values(array_diff($old,[$cfg['move_label'],$cfg['ssd_label'],$cfg['download_label'],$cfg['hdd_label']])),[$cfg['hdd_label']])));
                    say(($dry?'PREVIEW COMPLETE':'COMPLETE').' '.substr($hash,0,12).' : '.$message.($fullRecheck?'; full hash recheck confirmed':'; native move confirmed, no extra hash recheck'));
                    if(!$dry&&$moveEnabled){
                        if(!sameSet($old,$fixed))rpc($tr,'torrent_set',['ids'=>[$id],'labels'=>$fixed],$session);
                        unset($state[$hash]);saveState($statePath,$state);
                    }
                }else{
                    $err=(int)($t['error']??0);
                    say('PENDING '.substr($hash,0,12).' : '.$message.'; status='.(string)($t['status']??'?').'; error='.$err);
                    say('RECOVERY: inspect source/destination; --retry-move=HASH only accepts original source and no existing destination files');
                    // Do not trigger verification on tracker errors or incomplete moves.
                    // Wait for the destination path and physical files to be confirmed.
                }
                continue;
            }
            $new=changeLabels($old,$loc,$cfg);
            if($loc===null)say('WARN Transmission '.substr($hash,0,12).' outside storage; skip labels');
            elseif(!sameSet($old,$new)){
                say(($dry?'PREVIEW':'UPDATE').' Transmission '.substr($hash,0,12).' storage:'.$loc);
                if(!$dry)rpc($tr,'torrent_set',['ids'=>[$id],'labels'=>$new],$session);
                $tchanges++;
            }
            if(!in_array($cfg['move_label'],$old,true))continue;
            $candidates++;
            $reason=null;
            if($loc===null)$reason='unrecognized storage';
            elseif($loc==='hdd')$reason='already HDD';
            elseif(!completionReady($t,false))$reason='not complete, verifying, or local error';
            [$site,$siteError]=inferSite($t,$qitems,$cfg);
            if($siteError!==null)$reason=$siteError;
            $files=[];$invalid=false;
            foreach(($t['files']??[]) as $f) {
                $fn=(string)($f['name']??'');
                if($fn===''||str_starts_with($fn,'/')||preg_match('~(^|/)\.\.(/|$)~',$fn)){$invalid=true;break;}
                $files[]=norm($dir.'/'.$fn);
            }
            if($invalid||!$files)$reason='invalid/empty file list';
            $shared=sharedTransmission($tindex,$files,$id);
            if($shared!==null)$reason='shared with Transmission task '.$shared;
            [$qremove,$qerror]=qbitMoveGuards($qitems,$files,$hash);
            if($qerror!==null)$reason=$qerror;
            if($qremove&&!($cfg['delete_qbit_after_move']??false))$reason='matching qBittorrent task needs delete_qbit_after_move=true';
            if(count($state)>=$maxPending)$reason='pending migration limit reached ('.count($state).'/'.$maxPending.')';
            if($reason!==null){say('SKIP '.substr($hash,0,12).' '.$name.' : '.$reason);continue;}
            // Explicitly keep actual moves disabled unless both switches are changed.
            $folder=$cfg['site_folder_case']==='lower'?strtolower($site):$site;
            if(!preg_match('/^[a-zA-Z0-9_-]+$/',$folder)) {say('SKIP '.$hash.' unsafe site folder');continue;}
            $dest=norm($hdd.'/'.$folder);
            say(($dry||!$moveEnabled?'PREVIEW MOVE':'MOVE').' '.substr($hash,0,12).' '.$name.' '.$dir.' -> '.$dest);
            if($dry||!$moveEnabled)continue;
            // Refresh both clients immediately before accepting a move.
            $qitems=qreq($qb,'torrents/info?filter=all',$cookie,$headers);
            $fresh=transmissionItems($tr,$session);$current=findTorrent($fresh,$hash);
            $id=(int)$current['id'];
            if(norm($current['download_dir'])!==$dir||manifest($current)!==manifest($t)
                ||!completionReady($current,false)
                ||!in_array($cfg['move_label'],(array)($current['labels']??[]),true))
                throw new RuntimeException('Transmission candidate changed; rerun safely');
            [$freshSite,$siteError]=inferSite($current,$qitems,$cfg);
            if($siteError!==null||$freshSite!==$site)throw new RuntimeException('Site changed before move');
            $shared=sharedTransmission(transmissionIndex($fresh),torrentPaths($current),$id);
            if($shared!==null)throw new RuntimeException('Files now shared with Transmission task '.$shared);
            [$qremove,$qerror]=qbitMoveGuards($qitems,torrentPaths($current),$hash);
            if($qerror!==null)throw new RuntimeException($qerror);
            if($qremove&&!($cfg['delete_qbit_after_move']??false))throw new RuntimeException('qBittorrent removal is disabled');
            $manifest=[];$totalBytes=0;
            foreach(manifest($current) as $fileName=>$fileLength){
                $manifest[]=['name'=>$fileName,'length'=>$fileLength];$totalBytes+=$fileLength;
            }
            $entry=['phase'=>'prepared','destination'=>$dest,'source'=>$dir,'hdd_path'=>$hdd,'hdd_host_path'=>$cfg['hdd_host_path'],
                'requested_at'=>date(DATE_ATOM),'verify_requested'=>false,'seen_verifying'=>false,'qbit_hashes'=>$qremove,'files'=>$manifest,'total_bytes'=>$totalBytes];
            $moveCfg=$cfg;$moveCfg['_reserved_bytes']=reservedBytes($state);
            preflightDestination($current,$entry,$moveCfg);
            $state[$hash]=$entry;saveState($statePath,$state);
            sendMove($tr,$current,$hash,$state,$statePath,$session);
            say('MOVE ACCEPTED '.substr($hash,0,12).' : request accepted; inspect destination next run');
        }
        say('Summary: qBit label changes '.$qchanges.', Transmission label changes '.$tchanges.', marked move candidates '.$candidates);
        say('Pending moves: '.count($state).'; file size checks are not full hash verification.');
    } finally {if($cookie!==null)@unlink($cookie);}
} catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
