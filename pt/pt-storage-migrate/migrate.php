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
    return (float)($q['progress']??0)>=0.999999 && (int)($q['amount_left']??0)===0;
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
    $pathMatches=[];
    foreach($parts as $part){
        $v=strtolower($part);
        if(isset($hints[$v]))$pathMatches[$v]=true;
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
    chmod($tmp,0600);
    file_put_contents($tmp,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    if(!rename($tmp,$path))throw new RuntimeException('Cannot save state');
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
function verifyDestination(array $torrent,array $pending,array $cfg): array {
    $dest=norm($pending['destination']);
    if(norm((string)($torrent['download_dir']??'/'))!==$dest)return [false,'save path not updated'];
    $files=(array)($torrent['files']??[]);
    if(!$files)return [false,'empty file list'];
    foreach($files as $f){
        $name=(string)($f['name']??'');
        if($name===''||str_starts_with($name,'/')||preg_match('~(^|/)\.\.(/|$)~',$name))return [false,'unsafe file name'];
        $path=rtrim($cfg['hdd_host_path'],'/').substr($dest,strlen(norm($cfg['hdd_path']))).'/'.$name;
        if(!is_file($path))return [false,'missing file: '.$name];
        if(filesize($path)!==(int)($f['length']??-1))return [false,'size mismatch: '.$name];
    }
    return [true,'path and file sizes confirmed'];
}
try {
    if(!extension_loaded('curl'))throw new RuntimeException('PHP cURL required');
    $configFile=$argv[1]??__DIR__.'/config.json';
    $cfg=jsonArr((string)file_get_contents($configFile));
    $credFile=$cfg['credentials_file']??'';
    if(!is_file($credFile))throw new RuntimeException('Local credentials missing');
    $cred=jsonArr((string)file_get_contents($credFile));
    $qb=$cred['qbittorrent'];$tr=$cred['transmission'];
    $dry=(bool)($cfg['dry_run']??true);$moveEnabled=(bool)($cfg['enable_move']??false);
    $cfg['download_path']=$cfg['download_path']??'/download-hdd';
    $cfg['download_label']=$cfg['download_label']??'storage:download-hdd';
    $ssd=norm($cfg['ssd_path']);$hdd=norm($cfg['hdd_path']);$download=norm($cfg['download_path']);
    $roots=[$ssd,$download,$hdd];
    foreach($roots as $i=>$root){
        if($root==='/')throw new RuntimeException('Unsafe storage root');
        foreach($roots as $j=>$other)if($i!==$j&&within($root,$other))
            throw new RuntimeException('Overlapping storage roots: '.$root.' and '.$other);
    }
    $statePath=$cfg['state_file']??dirname($configFile).'/state.json';
    $state=is_file($statePath)?jsonArr((string)file_get_contents($statePath)):[];
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
        $titems=rpc($tr,'torrent_get',['fields'=>['id','hash_string','name','labels','download_dir','percent_done','files','status','error','error_string','have_valid','have_unchecked','size_when_done','left_until_done']],$session)['torrents']??[];
        say('qBittorrent '.count($qitems).', Transmission '.count($titems).', mode '.($dry?'DRY RUN':'WRITE').', move '.($moveEnabled?'enabled':'disabled'));
        $qpaths=[];$qchanges=0;$tchanges=0;$candidates=0;
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
            // Conservative protection: any qBit data tree potentially overlapping a Transmission move blocks it.
            $qpaths[]=['hash'=>$hash,'path'=>$content!=='/'?$content:$save,'save'=>$save];
        }
        $allT=[];
        foreach($titems as $t) {
            $dir=norm((string)($t['download_dir']??'/'));
            $files=[];
            foreach(($t['files']??[]) as $f) {
                $name=(string)($f['name']??'');
                if($name===''||str_starts_with($name,'/')||preg_match('~(^|/)\.\.(/|$)~',$name))continue;
                $files[]=norm($dir.'/'.$name);
            }
            $allT[]=['id'=>(int)$t['id'],'paths'=>$files,'dir'=>$dir];
        }
        foreach($titems as $t) {
            $id=(int)$t['id'];$hash=strtolower((string)($t['hash_string']??''));$name=(string)($t['name']??'');
            $dir=norm((string)($t['download_dir']??'/'));$loc=location($dir,$ssd,$hdd,$download);
            $old=array_values(array_filter((array)($t['labels']??[]),'is_string'));
            if(isset($state[$hash])){
                [$ok,$message]=verifyDestination($t,$state[$hash],$cfg);
                if($ok){
                    // Do not finalize until Transmission has completed a full hash recheck.
                    if(empty($state[$hash]['verify_requested'])){
                        say(($dry?'PREVIEW VERIFY':'VERIFY').' '.substr($hash,0,12).' : destination sizes OK; require hash recheck');
                        if(!$dry&&$moveEnabled){
                            rpc($tr,'torrent_verify',['ids'=>[$id]],$session);
                            $state[$hash]['verify_requested']=true;
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
                    // Never delete qBittorrent metadata unless a verification phase was actually observed.
                    if(empty($state[$hash]['seen_verifying'])){
                        say('PENDING '.substr($hash,0,12).' : verification phase not observed; manual review required');
                        continue;
                    }
                    // Tracker errors (e.g. HTTP 404) do not imply corrupted local data.
                    if(!verifiedData($t)){
                        say('PENDING '.substr($hash,0,12).' : local hash-verified data incomplete');
                        continue;
                    }
                    // The matching qBittorrent task must still be stopped and complete.
                    // Re-evaluate all overlapping qBittorrent paths immediately before deletion.
                    $sourceFiles=[];
                    foreach((array)($t['files']??[]) as $file){
                        $fn=(string)($file['name']??'');
                        if($fn===''||str_starts_with($fn,'/')||preg_match('~(^|/)\.\.(/|$)~',$fn))throw new RuntimeException('Unsafe pending file name');
                        $sourceFiles[]=norm($state[$hash]['source'].'/'.$fn);
                    }
                    [$qremove,$qerror]=qbitMoveGuards($qitems,$sourceFiles,$hash);
                    if($qerror!==null){
                        say('PENDING '.substr($hash,0,12).' : qBittorrent safety check: '.$qerror);
                        continue;
                    }
                    if(!empty($state[$hash]['qbit_hashes'])){
                        $expected=$state[$hash]['qbit_hashes'];
                        sort($expected);sort($qremove);
                        if($expected!==$qremove){
                            say('PENDING '.substr($hash,0,12).' : matching qBittorrent tasks changed; manual review required');
                            continue;
                        }
                    }
                    if($qremove&&!($cfg['delete_qbit_after_move']??false)){
                        say('PENDING '.substr($hash,0,12).' : qBittorrent removal requires delete_qbit_after_move=true');
                        continue;
                    }
                    if($qremove){
                        say(($dry||!$moveEnabled?'PREVIEW DELETE qBittorrent':'DELETE qBittorrent task only').' '.implode(',',$qremove));
                        if(!$dry&&$moveEnabled){
                            foreach($qremove as $qh)qbitRemoveTask($qb,$qh,$cookie,$headers);
                            $current=qreq($qb,'torrents/info?filter=all',$cookie,$headers);
                            foreach($current as $item)if(in_array(strtolower((string)($item['hash']??'')),$qremove,true))
                                throw new RuntimeException('qBittorrent task still present after delete; will retry safely');
                        }
                    }
                    if($dry||!$moveEnabled){
                        say('PREVIEW COMPLETE '.substr($hash,0,12).' : verification and task removal requirements satisfied');
                        continue;
                    }
                    $fixed=array_values(array_unique(array_merge(array_values(array_diff($old,[$cfg['move_label'],$cfg['ssd_label'],$cfg['download_label'],$cfg['hdd_label']])),[$cfg['hdd_label']])));
                    say(($dry?'PREVIEW COMPLETE':'COMPLETE').' '.substr($hash,0,12).' : '.$message);
                    if(!$dry&&$moveEnabled){
                        if(!sameSet($old,$fixed))rpc($tr,'torrent_set',['ids'=>[$id],'labels'=>$fixed],$session);
                        unset($state[$hash]);saveState($statePath,$state);
                    }
                }else{
                    $err=(int)($t['error']??0);
                    say('PENDING '.substr($hash,0,12).' : '.$message.'; status='.(string)($t['status']??'?').'; error='.$err);
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
            elseif((float)($t['percent_done']??0)<0.999999)$reason='not completed';
            [$site,$siteError]=inferSite($t,$qitems,$cfg);
            if($siteError!==null)$reason=$siteError;
            $files=[];$invalid=false;
            foreach(($t['files']??[]) as $f) {
                $fn=(string)($f['name']??'');
                if($fn===''||str_starts_with($fn,'/')||preg_match('~(^|/)\.\.(/|$)~',$fn)){$invalid=true;break;}
                $files[]=norm($dir.'/'.$fn);
            }
            if($invalid||!$files)$reason='invalid/empty file list';
            // Block any overlap with any other Transmission task, including distinct hashes.
            foreach($allT as $other) {
                if($other['id']===$id)continue;
                foreach($files as $f)foreach($other['paths'] as $o)if(pathsOverlap($f,$o)){$reason='shared with Transmission task '.$other['id'];break 2;}
                if($reason!==null&&str_starts_with($reason,'shared'))break;
            }
            [$qremove,$qerror]=qbitMoveGuards($qitems,$files,$hash);
            if($qerror!==null)$reason=$qerror;
            if($qremove&&!($cfg['delete_qbit_after_move']??false))
                $reason='matching qBittorrent task needs delete_qbit_after_move=true';
            if($reason!==null){say('SKIP '.substr($hash,0,12).' '.$name.' : '.$reason);continue;}
            // Explicitly keep actual moves disabled unless both switches are changed.
            $folder=$cfg['site_folder_case']==='lower'?strtolower($site):$site;
            if(!preg_match('/^[a-zA-Z0-9_-]+$/',$folder)) {say('SKIP '.$hash.' unsafe site folder');continue;}
            $dest=norm($hdd.'/'.$folder);
            say(($dry||!$moveEnabled?'PREVIEW MOVE':'MOVE').' '.substr($hash,0,12).' '.$name.' '.$dir.' -> '.$dest);
            if($dry||!$moveEnabled)continue;
            // Require real HDD mount, not a directory on the Unraid root filesystem.
            $host=$cfg['hdd_host_path'];
            if(!is_dir($host)||!is_writable($host)||!is_dir($host.'/..'))throw new RuntimeException('HDD destination unavailable');
            // Check mountpoint with findmnt, avoiding accidental writes into an unmounted disk directory.
            $mount=trim((string)shell_exec('findmnt -rn -M '.escapeshellarg($host).' -o TARGET 2>/dev/null'));
            if($mount!==$host)throw new RuntimeException('HDD is not mounted at '.$host);
            // Refuse to overwrite existing destination files, including symlinks.
            $bytes=0;
            foreach((array)($t['files']??[]) as $file){
                $fn=(string)($file['name']??'');
                if($fn===''||str_starts_with($fn,'/')||preg_match('~(^|/)\.\.(/|$)~',$fn))
                    throw new RuntimeException('Unsafe file path in torrent');
                $target=$host.'/'.$folder.'/'.$fn;
                if(file_exists($target)||is_link($target))
                    throw new RuntimeException('Destination already exists: '.$target);
                $bytes+=(int)($file['length']??0);
            }
            $free=disk_free_space($host);
            if($free===false||$free<$bytes+max(1073741824,(int)ceil($bytes*0.05)))
                throw new RuntimeException('Insufficient HDD free space for safe move');
            if(!is_dir($host.'/'.$folder)&&!mkdir($host.'/'.$folder,0775,true)&&!is_dir($host.'/'.$folder))throw new RuntimeException('Cannot create site directory');
            // Transmission RPC move=true moves data and changes its download directory.
            $manifest=[];$totalBytes=0;
            foreach((array)($t['files']??[]) as $file){
                $fileName=(string)($file['name']??'');
                $fileLength=(int)($file['length']??-1);
                if($fileName===''||str_starts_with($fileName,'/')||preg_match('~(^|/)\\.\\.(/|$)~',$fileName)||$fileLength<0)
                    throw new RuntimeException('Unsafe file manifest for progress monitoring');
                $manifest[]=['name'=>$fileName,'length'=>$fileLength];
                $totalBytes+=$fileLength;
            }
            $state[$hash]=['destination'=>$dest,'source'=>$dir,'requested_at'=>date(DATE_ATOM),'verify_requested'=>false,'seen_verifying'=>false,'qbit_hashes'=>$qremove,'files'=>$manifest,'total_bytes'=>$totalBytes];
            saveState($statePath,$state);
            rpc($tr,'torrent_set_location',['ids'=>[$id],'location'=>$dest,'move'=>true],$session);
            $post=rpc($tr,'torrent_get',['ids'=>[$id],'fields'=>['id','download_dir','status']],$session)['torrents']??[];
            $postDir=count($post)===1?norm((string)($post[0]['download_dir']??'/')):'/';
            if($postDir===$dest){
                say('MOVE ACCEPTED '.substr($hash,0,12).' : save path updated; verify physical files next run');
            }else{
                say('PENDING MOVE '.substr($hash,0,12).' : save path still '.$postDir.'; no automatic retry to avoid duplicate/partial move');
            }
        }
        say('Summary: qBit label changes '.$qchanges.', Transmission label changes '.$tchanges.', marked move candidates '.$candidates);
        say('Pending moves: '.count($state).'; file size checks are not full hash verification.');
    } finally {if($cookie!==null)@unlink($cookie);}
} catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
