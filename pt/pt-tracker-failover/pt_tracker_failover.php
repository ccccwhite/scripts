<?php
/**
 * PT Tracker Failover for Unraid 6.12 / qBittorrent 5.3 / Transmission 4.1.
 * Only GET APIs and unauthenticated direct HTTPS reachability probes in preview.
 * In apply mode, only changes tracker URLs, never torrents or payloads.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');

function say(string $s): void { echo '[' . date('Y-m-d H:i:s') . '] ' . $s . PHP_EOL; }
function jsonFile(string $p): array {
    if (!is_file($p) || is_link($p)) throw new RuntimeException('JSON file missing or unsafe: ' . $p);
    $x=json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($x)) throw new RuntimeException('Invalid JSON: ' . $p);
    return $x;
}
function saveState(string $path,array $state): void {
    if (is_link($path)) throw new RuntimeException('State file is symlink');
    $tmp=$path.'.tmp.'.getmypid();
    $data=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    $f=fopen($tmp,'x'); if (!$f) throw new RuntimeException('Cannot create state temp file');
    chmod($tmp,0600);
    if (fwrite($f,$data)!==strlen($data)) { fclose($f); @unlink($tmp); throw new RuntimeException('Short state write'); }
    fclose($f);
    if (!rename($tmp,$path)) { @unlink($tmp); throw new RuntimeException('State rename failed'); }
}
function endpointHost(string $url): string {
    return strtolower(rtrim((string)(parse_url($url,PHP_URL_HOST) ?: ''),'.'));
}
function baseParts(string $base): array {
    $p=parse_url($base);
    if (!$p || !in_array(strtolower($p['scheme']??''),['http','https'],true)
        || empty($p['host']) || isset($p['user']) || isset($p['pass'])
        || !empty($p['query']) || !empty($p['fragment'])
        || !in_array($p['path']??'',['','/'],true))
        throw new RuntimeException('Tracker config must be origin http(s)://hostname[:port]: '.$base);
    $scheme=strtolower($p['scheme']); $host=strtolower($p['host']);
    if (!preg_match('/^[a-z0-9.-]+$/D',$host)) throw new RuntimeException('Invalid tracker host');
    $port=isset($p['port'])?':'.$p['port']:'';
    return [$host,$scheme.'://'.$host.$port];
}
function switchOrigin(string $url,string $targetOrigin): string {
    if (!preg_match('~^https?://[^/?#]+(?=/|[?#]|$)~i',$url)) throw new RuntimeException('Unsupported original tracker URL');
    return (string)preg_replace('~^https?://[^/?#]+~i',$targetOrigin,$url,1);
}
function httpReq(string $url,string $verb='GET',?string $payload=null,array $headers=[],
                 string $username='',string $password='',?string $cookie=null): array {
    $ch=curl_init($url);
    if ($ch===false) throw new RuntimeException('Cannot create cURL');
    $rh=[];
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
        CURLOPT_CUSTOMREQUEST=>$verb,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_HEADERFUNCTION=>function($c,string $line)use(&$rh):int {
            $i=strpos($line,':');
            if ($i!==false) $rh[strtolower(trim(substr($line,0,$i)))]=trim(substr($line,$i+1));
            return strlen($line);
        }
    ]);
    if ($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$payload);
    if ($username!=='') curl_setopt($ch,CURLOPT_USERPWD,$username.':'.$password);
    if ($cookie!==null) { curl_setopt($ch,CURLOPT_COOKIEJAR,$cookie);curl_setopt($ch,CURLOPT_COOKIEFILE,$cookie); }
    $body=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);curl_close($ch);
    if ($body===false) throw new RuntimeException('HTTP connection failed: '.$err);
    return [$code,(string)$body,$rh];
}

final class Qbit {
    private string $base;
    private ?string $cookie=null;
    private array $headers=[];
    public function __construct(array $c) {
        $this->base=rtrim((string)($c['url']??''),'/');
        if (!preg_match('~^https?://[a-zA-Z0-9.:-]+$~D',$this->base)) throw new RuntimeException('Invalid qBit URL');
        $key=trim((string)($c['api_key']??''));
        if ($key!=='') {
            $this->headers=['Authorization: Bearer '.$key];
            try { $this->get('torrents/info?filter=all'); return; }
            catch(Throwable $e) { $this->headers=[]; }
        }
        $user=(string)($c['username']??$c['user']??'');
        $pass=(string)($c['password']??$c['pass']??'');
        if ($user==='' || $pass==='') throw new RuntimeException('qBit credentials missing');
        $this->cookie=tempnam(sys_get_temp_dir(),'pttrack-');
        if ($this->cookie===false) throw new RuntimeException('Cannot create qBit cookie file');
        chmod($this->cookie,0600);
        [$code,$body]=httpReq($this->base.'/api/v2/auth/login','POST',
            http_build_query(['username'=>$user,'password'=>$pass]),['Content-Type: application/x-www-form-urlencoded','Referer: '.$this->base.'/'],'','',$this->cookie);
        if (!(($code===204)||($code===200&&trim($body)==='Ok.')))
            throw new RuntimeException('qBit login rejected HTTP '.$code);
        $this->get('torrents/info?filter=all'); // Verify authenticated read, including HTTP 204 login.
    }
    public function __destruct() { if ($this->cookie!==null) @unlink($this->cookie); }
    private function request(string $method,string $endpoint,?array $fields=null): string {
        $data=$fields===null?null:http_build_query($fields,'','&',PHP_QUERY_RFC3986);
        [$code,$body]=httpReq($this->base.'/api/v2/'.$endpoint,$method,$data,
            array_merge(['Referer: '.$this->base.'/','Origin: '.$this->base,
                         'Content-Type: application/x-www-form-urlencoded'],$this->headers),
            '','',$this->cookie);
        $allowed=$method==='GET'?[200]:[200,204];
        if (!in_array($code,$allowed,true)||trim($body)==='Fails.')
            throw new RuntimeException('qBit '.$endpoint.' HTTP '.$code.' rejected');
        return $body;
    }
    public function get(string $ep): array {
        $v=json_decode($this->request('GET',$ep),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($v)) throw new RuntimeException('Invalid qBit API response');
        return $v;
    }
    public function edit(string $hash,string $old,string $new): void {
        // Confirmed against the user's running qBittorrent 5.3.0.
        $this->request('POST','torrents/editTracker',['hash'=>$hash,'url'=>$old,'newUrl'=>$new]);
        $list=$this->get('torrents/trackers?hash='.rawurlencode($hash));
        foreach($list as $tr) if (($tr['url']??'')===$new) return;
        throw new RuntimeException('qBit edit not confirmed by read-back');
    }
}
final class Transmission {
    private string $url,$user,$pass,$session='';
    private int $id=0;
    public function __construct(array $c) {
        $this->url=(string)($c['rpc_url']??'');
        $this->user=(string)($c['username']??$c['user']??'');
        $this->pass=(string)($c['password']??$c['pass']??'');
        if (!preg_match('~^https?://[a-zA-Z0-9.:-]+/transmission/rpc/?$~D',$this->url))
            throw new RuntimeException('Invalid Transmission RPC URL');
    }
    public function rpc(string $method,array $params): array {
        $id=++$this->id;
        $body=json_encode(['jsonrpc'=>'2.0','method'=>$method,'params'=>$params,'id'=>$id],JSON_THROW_ON_ERROR);
        for($n=0;$n<3;$n++){
            $headers=['Content-Type: application/json'];
            if ($this->session!=='') $headers[]='X-Transmission-Session-Id: '.$this->session;
            [$code,$data,$rh]=httpReq($this->url,'POST',$body,$headers,$this->user,$this->pass);
            if ($code===409) {
                $this->session=$rh['x-transmission-session-id']??'';
                if ($this->session==='') throw new RuntimeException('Transmission missing session ID');
                continue;
            }
            if ($code!==200) throw new RuntimeException('Transmission RPC HTTP '.$code);
            $answer=json_decode($data,true,512,JSON_THROW_ON_ERROR);
            if (isset($answer['error'])) throw new RuntimeException('Transmission RPC error');
            if (($answer['id']??null)!==$id || !is_array($answer['result']??null))
                throw new RuntimeException('Unexpected Transmission RPC response');
            return $answer['result'];
        }
        throw new RuntimeException('Transmission session handshake failed');
    }
    public function list(): array {
        $r=$this->rpc('torrent_get',['fields'=>['hash_string','name','tracker_list']]);
        $map=[];
        foreach($r['torrents']??[] as $t) {
            $h=strtolower((string)($t['hash_string']??''));
            if (preg_match('/^[a-f0-9]{40}$/D',$h)) $map[$h]=$t;
        }
        return $map;
    }
    public function update(string $hash,string $newList): void {
        $this->rpc('torrent_set',['ids'=>[$hash],'tracker_list'=>$newList]);
        $check=$this->rpc('torrent_get',['ids'=>[$hash],'fields'=>['hash_string','tracker_list']]);
        $one=$check['torrents'][0]??null;
        if (!is_array($one)||strtolower((string)($one['hash_string']??''))!==$hash
            || rtrim((string)($one['tracker_list']??''))!==rtrim($newList))
            throw new RuntimeException('Transmission tracker_list read-back differs');
    }
}
/** Replace configured old-host URLs in Transmission while preserving all tiers,
 * existing credentials, tracker ordering, and unrelated trackers. */
function transPlan(array $t,string $oldHost,string $targetOrigin): array {
    if (!isset($t['tracker_list']) || !is_string($t['tracker_list']))
        throw new RuntimeException('Transmission tracker_list not returned');
    $source=$t['tracker_list'];
    $lines=preg_split('/\r\n|\n|\r/',$source);
    $newLines=[]; $replacements=0;
    $targetHost=endpointHost($targetOrigin);
    foreach($lines as $line){
        $s=trim($line);
        if ($s!=='' && endpointHost($s)===$oldHost) {
            $line=switchOrigin($s,$targetOrigin);
            $replacements++;
        }
        $newLines[]=$line;
    }
    $new=implode("\n",$newLines);
    if ($replacements>0 && preg_match('~^https?://~i',$new)!==1)
        throw new RuntimeException('Transmission invalid tracker list');
    // If already on the target or none of the source URLs are present, do not rewrite.
    $already=false;
    foreach ($lines as $line) if (endpointHost(trim($line))===$targetHost) $already=true;
    if ($replacements===0 && !$already)
        throw new RuntimeException('Transmission same hash has no source/target Tracker; manual inspection required');
    return [$new,$replacements];
}
/** Exact origin probe within qBit container (no passkey sent; no proxy).
 * 404/403 is acceptable as a TLS connectivity proof, NOT a successful announce. */
function directProbe(string $origin,int $timeout): bool {
    foreach (['-4','-6'] as $family) {
        $cmd=['docker','exec','qbittorrent','curl',$family,
              '--proxy','','--noproxy','*','--connect-timeout',(string)$timeout,
              '--max-time',(string)($timeout+2),'-sS','-o','/dev/null',
              '-w','%{http_code}',$origin.'/'];
        $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($p)) throw new RuntimeException('Docker probe failed to start');
        fclose($pipes[0]);$out=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);
        stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
        if ($exit===0 && preg_match('/^[1-5][0-9]{2}$/D',$out)) return true;
    }
    return false;
}
function configuredSites(array $cfg): array {
    $sites=[];
    foreach($cfg['sites']??[] as $name=>$site){
        $arr=$site['trackers']??[];
        if (!is_array($arr)||count($arr)<2) throw new RuntimeException('Site '.$name.' requires at least two configured tracker origins');
        $origins=[];$hosts=[];
        foreach($arr as $base){
            if (!is_string($base)) throw new RuntimeException('Invalid tracker origin');
            [$host,$origin]=baseParts($base);
            if (isset($hosts[$host])) throw new RuntimeException('Duplicate host in site '.$name);
            $hosts[$host]=true;$origins[]=['host'=>$host,'origin'=>$origin];
        }
        $cats=$site['categories']??[];
        if (!is_array($cats)) throw new RuntimeException('Invalid categories for '.$name);
        $sites[$name]=['categories'=>$cats,'trackers'=>$origins];
    }
    return $sites;
}
function torrentSite(array $torrent,string $host,array $sites): ?string {
    $found=[];
    foreach($sites as $name=>$site){
        if (!in_array($torrent['category']??'',$site['categories'],true)) continue;
        foreach($site['trackers'] as $opt) if($opt['host']===$host){$found[]=$name;break;}
    }
    return count($found)===1?$found[0]:null;
}

try {
    if (!extension_loaded('curl')) throw new RuntimeException('PHP curl extension required');
    $mode=$argv[1]??'--preview';
    if (!in_array($mode,['--preview','--apply','--status'],true))
        throw new RuntimeException('Usage: script [--preview|--apply|--status]');
    $configPath=$argv[2]??__DIR__.'/config.json';
    $cfg=jsonFile($configPath);
    if (!is_bool($cfg['dry_run']??null)) throw new RuntimeException('dry_run must be boolean');
    $dry=$cfg['dry_run'];
    if ($mode==='--apply' && $dry) throw new RuntimeException('dry_run=true; refusing --apply');
    $statePath=(string)($cfg['pending_file']??__DIR__.'/pending_sync.json');
    $lockPath=(string)($cfg['lock_file']??__DIR__.'/run.lock');
    if (is_link($lockPath)) throw new RuntimeException('Symlink lock path');
    $lock=fopen($lockPath,'c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { say('Another instance is running, skipped');exit(0); }
    $state=is_file($statePath)?jsonFile($statePath):[];
    if ($mode==='--status'){ say('pending='.count($state).'; dry_run='.($dry?'true':'false'));exit(0); }
    $creds=jsonFile((string)($cfg['credentials_file']??''));
    $sites=configuredSites($cfg);
    $qb=new Qbit($creds['qbittorrent']??[]);
    $trans=new Transmission($creds['transmission']??[]);
    // Fail closed if Transmission is offline; never change qBit without synchronization access.
    $transMap=$trans->list();
    $qTorrents=$qb->get('torrents/info?filter=all');
    $qMap=[];
    foreach($qTorrents as $t) {
        $h=strtolower((string)($t['hash']??''));
        if (preg_match('/^[a-f0-9]{40}$/D',$h)) $qMap[$h]=$t;
    }
    say('qBit='.count($qMap).' Transmission='.count($transMap).' pending='.count($state).' mode='.$mode);
    $apply=$mode==='--apply';
    $max=max(1,min(100,(int)($cfg['max_changes_per_run']??5)));
    $probeTime=max(2,min(10,(int)($cfg['probe_timeout_seconds']??4)));
    $delay=max(0,min(30,(int)($cfg['delay_seconds']??3)));
    $probeCache=[];$changes=0;$errors=0;$candidates=0;
    // Process persisted, partially synchronized edits before starting new changes.
    foreach($state as $hash=>$entry){
        if (!isset($qMap[$hash])) {say('PENDING '.$hash.' qBit absent, manual inspection');$errors++;continue;}
        $to=(string)($entry['target_origin']??'');
        $from=(string)($entry['source_host']??'');
        $targetHost=endpointHost($to);
        if ($targetHost===''||$from==='') {say('PENDING '.$hash.' invalid state');$errors++;continue;}
        $qTracks=$qb->get('torrents/trackers?hash='.$hash);
        $onTarget=false;
        foreach($qTracks as $tr) if(endpointHost((string)($tr['url']??''))===$targetHost) $onTarget=true;
        if (!$onTarget) {say('PENDING '.$hash.' qBit not on target; manual inspection');$errors++;continue;}
        if (!isset($transMap[$hash])) {unset($state[$hash]);if($apply)saveState($statePath,$state);continue;}
        try {
            [$newList,$repl]=transPlan($transMap[$hash],$from,$to);
            if ($repl===0) {say('PENDING '.$hash.' Transmission already consistent'); if ($apply){unset($state[$hash]);saveState($statePath,$state);}continue;}
            say('PENDING '.$hash.' Transmission needs sync'.($apply?'':' [preview]'));
            if ($apply) {
                $trans->update($hash,$newList);
                unset($state[$hash]);saveState($statePath,$state);$changes++;
            }
        }catch(Throwable $e){say('PENDING '.$hash.' sync failed: '.$e->getMessage());$errors++;}
    }
    if ($errors>0) throw new RuntimeException('Pending sync error; refusing new changes');
    // Sort download failures first to restore stalled downloads before touching seeding.
    usort($qTorrents,fn($a,$b)=>
        (int)in_array(($b['state']??''),['stalledDL','downloading'],true) <=>
        (int)in_array(($a['state']??''),['stalledDL','downloading'],true));
    foreach($qTorrents as $t){
        if ($apply && $changes >= $max) break;
        $hash=strtolower((string)($t['hash']??''));
        if (!isset($qMap[$hash])||isset($state[$hash])) continue;
        $tracks=$qb->get('torrents/trackers?hash='.$hash);
        $eligible=[];
        foreach($tracks as $tr) {
            if ((int)($tr['status']??-1)!==4) continue;
            $old=(string)($tr['url']??'');$host=endpointHost($old);
            $site=torrentSite($t,$host,$sites);
            if ($site!==null) $eligible[]=['old'=>$old,'host'=>$host,'site'=>$site];
        }
        if (count($eligible)!==1) continue; // No configured site, or ambiguous multiple trackers.
        $item=$eligible[0];$site=$sites[$item['site']];
        $target=null;
        foreach($site['trackers'] as $opt){
            if ($opt['host']===$item['host']) continue;
            $key=$opt['origin'];
            if (!array_key_exists($key,$probeCache)) {
                $probeCache[$key]=directProbe($key,$probeTime);
                say('Probe '.$key.' => '.($probeCache[$key]?'reachable':'unreachable'));
            }
            if ($probeCache[$key]) {$target=$opt['origin'];break;}
        }
        if ($target===null){say('SKIP '.($t['name']??$hash).' no reachable alternative');continue;}
        $old=$item['old'];$new=switchOrigin($old,$target);
        if (strlen($new)>4096) {say('SKIP '.$hash.' URL too long');continue;}
        $trNew=null;$replaceCount=0;
        if (isset($transMap[$hash])) {
            try {[$trNew,$replaceCount]=transPlan($transMap[$hash],$item['host'],$target);}
            catch(Throwable $e) {say('SKIP '.$hash.' Transmission preflight: '.$e->getMessage());$errors++;continue;}
        }
        $candidates++;
        say(($apply?'CHANGE ':'PLAN ').($t['name']??$hash).' ['.($t['category']??'').'] '.
            $item['host'].' -> '.endpointHost($target).' Transmission='.
            (isset($transMap[$hash])?($replaceCount>0?'sync':'already ok'):'not present'));
        if (!$apply) continue;
        // Durable write-ahead record (contains no Passkeys) for interrupted Transmission sync.
        $state[$hash]=['site'=>$item['site'],'source_host'=>$item['host'],
                        'target_origin'=>$target,'time'=>date('c')];
        saveState($statePath,$state);
        try {
            $qb->edit($hash,$old,$new);
            if (isset($transMap[$hash]) && $replaceCount>0) $trans->update($hash,(string)$trNew);
            unset($state[$hash]);saveState($statePath,$state);
            $changes++;say('PASS '.$hash.' qBit changed; Transmission synchronized / not present');
            if ($delay>0) sleep($delay);
        }catch(Throwable $e){
            say('FAIL '.$hash.' '.$e->getMessage().' ; pending record retained');
            $errors++;
            break; // Do not continue batch with an inconsistent state.
        }
    }
    say('DONE candidates='.$candidates.' changes='.$changes.' errors='.$errors.' pending='.count($state));
    if ($errors>0) exit(1);
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
