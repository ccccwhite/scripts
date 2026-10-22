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
function location(string $p,string $ssd,string $hdd): ?string {
    if(within($p,$ssd))return 'ssd';
    if(within($p,$hdd))return 'hdd';
    return null;
}
function changeLabels(array $labels,?string $loc,array $cfg): array {
    if($loc===null)return $labels;
    $out=array_values(array_filter($labels,fn($x)=>$x!==$cfg['ssd_label']&&$x!==$cfg['hdd_label']));
    $out[]=$loc==='ssd'?$cfg['ssd_label']:$cfg['hdd_label'];
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
function saveState(string $path,array $state): void {
    $tmp=tempnam(dirname($path),'.storage-');
    if($tmp===false)throw new RuntimeException('Cannot create state file');
    chmod($tmp,0600);
    file_put_contents($tmp,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    if(!rename($tmp,$path))throw new RuntimeException('Cannot save state');
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
    $ssd=norm($cfg['ssd_path']);$hdd=norm($cfg['hdd_path']);
    if($ssd===$hdd||$ssd==='/'||$hdd==='/')throw new RuntimeException('Unsafe storage roots');
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
        $titems=rpc($tr,'torrent_get',['fields'=>['id','hash_string','name','labels','download_dir','percent_done','files','status','error','error_string']],$session)['torrents']??[];
        say('qBittorrent '.count($qitems).', Transmission '.count($titems).', mode '.($dry?'DRY RUN':'WRITE').', move '.($moveEnabled?'enabled':'disabled'));
        $qpaths=[];$qchanges=0;$tchanges=0;$candidates=0;
        foreach($qitems as $q) {
            $hash=strtolower((string)($q['hash']??''));$save=norm((string)($q['save_path']??'/'));
            $content=norm((string)($q['content_path']??'/'));
            $loc=location($save,$ssd,$hdd);
            if($loc===null||($content!=='/'&&location($content,$ssd,$hdd)!==$loc)) {
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
            $dir=norm((string)($t['download_dir']??'/'));$loc=location($dir,$ssd,$hdd);
            $old=array_values(array_filter((array)($t['labels']??[]),'is_string'));
            if(isset($state[$hash])){
                [$ok,$message]=verifyDestination($t,$state[$hash],$cfg);
                if($ok){
                    $fixed=array_values(array_unique(array_merge(array_values(array_diff($old,[$cfg['move_label'],$cfg['ssd_label'],$cfg['hdd_label']])),[$cfg['hdd_label']])));
                    say(($dry?'PREVIEW COMPLETE':'COMPLETE').' '.substr($hash,0,12).' : '.$message);
                    if(!$dry){
                        if(!sameSet($old,$fixed))rpc($tr,'torrent_set',['ids'=>[$id],'labels'=>$fixed],$session);
                        unset($state[$hash]);saveState($statePath,$state);
                    }
                }else{
                    $err=(int)($t['error']??0);
                    say('PENDING '.substr($hash,0,12).' : '.$message.'; status='.(string)($t['status']??'?').'; error='.$err);
                    if($err!==0&&empty($state[$hash]['verify_requested'])){
                        say(($dry?'PREVIEW VERIFY':'VERIFY').' '.substr($hash,0,12).' : '.(string)($t['error_string']??''));
                        if(!$dry){
                            rpc($tr,'torrent_verify',['ids'=>[$id]],$session);
                            $state[$hash]['verify_requested']=true;saveState($statePath,$state);
                        }
                    }
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
            $sites=[];
            foreach($cfg['site_labels'] as $site)foreach($old as $label)if(strcasecmp((string)$site,$label)===0)$sites[strtolower((string)$site)]=true;
            if(count($sites)!==1)$reason='site label missing or ambiguous';
            $site=array_key_first($sites);
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
            foreach($qpaths as $q) {
                foreach($files as $f) {
                    if(pathsOverlap($f,$q['path'])||($q['path']===$q['save']&&within($f,$q['save']))) {
                        $reason='qBittorrent may share files ('.substr($q['hash'],0,12).')';break 2;
                    }
                }
            }
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
            if(!is_dir($host.'/'.$folder)&&!mkdir($host.'/'.$folder,0775,true)&&!is_dir($host.'/'.$folder))throw new RuntimeException('Cannot create site directory');
            // Transmission RPC move=true moves data and changes its download directory.
            $state[$hash]=['destination'=>$dest,'source'=>$dir,'requested_at'=>date(DATE_ATOM),'verify_requested'=>false];
            saveState($statePath,$state);
            rpc($tr,'torrent_set_location',['ids'=>[$id],'location'=>$dest,'move'=>true],$session);
            say('MOVE REQUESTED '.$hash.' ; check again on next run');
        }
        say('Summary: qBit label changes '.$qchanges.', Transmission label changes '.$tchanges.', marked move candidates '.$candidates);
        say('Pending moves: '.count($state).'; file size checks are not full hash verification.');
    } finally {if($cookie!==null)@unlink($cookie);}
} catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
