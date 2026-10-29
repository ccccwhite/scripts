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
}

/**
 * Monitor BOTH qBittorrent 5.x and Transmission 4.1 Tracker statuses.
 * Dynamically redirect only domain resolution (Docker /etc/hosts), NOT torrent URLs.
 * Running /etc/hosts overrides are repaired after container recreation.
 */
const IP_MONITOR_TAG = '# PT-TRACKER-IP-MONITOR';
function hasNetError(string $message): bool {
    return (bool)preg_match('/timed?\s*out|timeout|could not connect|connection (refused|reset|failed)|unreachable|stream truncated|network error|connection error|couldn.t resolve|name resolution|no route/i',$message);
}
function qbitStats(Qbit $qb,string $host,array $categories): array {
    $counts=['ok'=>0,'bad'=>0,'unknown'=>0,'checked'=>0];
    foreach($qb->get('torrents/info?filter=all') as $t) {
        if(!in_array(strtolower((string)($t['category']??'')),$categories,true)
            && endpointHost((string)($t['tracker']??''))!==$host)continue;
        $hash=(string)($t['hash']??'');
        if(!preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/Di',$hash))continue;
        foreach($qb->get('torrents/trackers?hash='.rawurlencode($hash)) as $tr){
            if(endpointHost((string)($tr['url']??''))!==$host)continue;
            $counts['checked']++;$s=(int)($tr['status']??-1);
            if($s===2)$counts['ok']++;
            elseif($s===4&&hasNetError((string)($tr['msg']??'')))$counts['bad']++;
            else $counts['unknown']++;
        }
    }
    return $counts;
}
function transStats(Transmission $tr,string $host): array {
    $r=$tr->rpc('torrent_get',['fields'=>['hash_string','tracker_stats']]);
    $counts=['ok'=>0,'bad'=>0,'unknown'=>0,'checked'=>0];
    foreach($r['torrents']??[] as $t)foreach($t['tracker_stats']??[] as $v){
        if(endpointHost((string)($v['announce']??''))!==$host)continue;
        $counts['checked']++;
        if(($v['has_announced']??false)!==true){$counts['unknown']++;continue;}
        if(($v['last_announce_succeeded']??false)===true)$counts['ok']++;
        elseif(($v['last_announce_timed_out']??false)===true||
               hasNetError((string)($v['last_announce_result']??'')))$counts['bad']++;
        else $counts['unknown']++;
    }
    return $counts;
}
function dockerCmd(string $container,array $cmd,?string $stdin=null): string {
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,100}$/D',$container))
        throw new RuntimeException('Invalid Docker container');
    $args=array_merge(['docker','exec','-u','0'], $stdin===null?[]:['-i'], [$container],$cmd);
    $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Cannot execute docker');
    if($stdin!==null)fwrite($pipes[0],$stdin);
    fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    if(proc_close($proc)!==0)throw new RuntimeException('Docker '.$container.': '.substr(trim($err),0,200));
    return (string)$out;
}
function probeIP(string $container,string $host,string $ip,int $timeout): array {
    // No passkey, no announce, no proxy. 403/404 proves only TLS and certificate.
    try {
        $out=trim(dockerCmd($container,['curl','-4','--proxy','','--noproxy','*',
            '--resolve',$host.':443:'.$ip,'--connect-timeout',(string)$timeout,
            '--max-time',(string)($timeout+2),'-sS','-o','/dev/null',
            '-w','%{http_code} %{time_appconnect}','https://'.$host.'/']));
    }catch(Throwable $e){return [false,999.0];}
    if(!preg_match('/^([1-5][0-9]{2}) ([0-9.]+)$/D',$out,$m))return [false,999.0];
    return [(float)$m[2]>0.0,(float)$m[2]];
}
function candidates(array $cfg): array {
    $all=[];
    foreach($cfg['candidate_ipv4']??[] as $ip)$all[]=$ip;
    foreach($cfg['dns_sources']??[] as $name){
        if(!is_string($name)||!preg_match('/^[a-zA-Z0-9.-]+$/D',$name))continue;
        foreach(@dns_get_record($name,DNS_A)?:[] as $row)$all[]=$row['ip']??'';
    }
    $max=max(1,min(24,(int)($cfg['max_candidates']??12)));$out=[];
    foreach(array_unique($all) as $ip){
        if(!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))continue;
        $out[]=$ip;if(count($out)>=$max)break;
    }
    return $out;
}
function stateSave(string $file,array $state): void {
    if(is_link($file))throw new RuntimeException('Refusing symlink state');
    $tmp=$file.'.tmp.'.getmypid();
    $json=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    $fp=fopen($tmp,'x');if(!$fp)throw new RuntimeException('Cannot create state');
    chmod($tmp,0600);
    if(fwrite($fp,$json)!==strlen($json)){fclose($fp);@unlink($tmp);throw new RuntimeException('State write incomplete');}
    fclose($fp);
    if(!rename($tmp,$file)){@unlink($tmp);throw new RuntimeException('State rename failed');}
}
function plannedHosts(string $old,string $host,string $ip): string {
    $lines=preg_split('/\r?\n/',$old);$out=[];$managed=0;
    foreach($lines as $line){
        if(str_contains($line,IP_MONITOR_TAG)){
            $managed++;
            $words=preg_split('/\s+/',trim($line));
            if($managed>1||($words[1]??'')!==$host)
                throw new RuntimeException('Unexpected managed hosts entry');
            continue;
        }
        $body=trim(explode('#',$line,2)[0]);
        if($body!==''){
            $fields=preg_split('/\s+/',$body);
            if(in_array($host,array_slice($fields,1),true))
                throw new RuntimeException('Existing unmanaged hosts/extra_hosts mapping for '.$host);
        }
        if($line!=='')$out[]=$line;
    }
    $out[]=$ip."\t".$host."\t".IP_MONITOR_TAG;
    return implode("\n",$out)."\n";
}
function writeHosts(string $container,string $hosts): void {
    // /etc/hosts is Docker bind mount; sed -i/mv would fail (device busy).
    dockerCmd($container,['sh','-c','cat > /etc/hosts'],$hosts);
    if(dockerCmd($container,['cat','/etc/hosts'])!==$hosts)
        throw new RuntimeException('Hosts read-back failed in '.$container);
}
function syncHosts(array $containers,string $host,string $ip,bool $apply): bool {
    $old=[];$target=[];$changed=false;
    // Preflight both clients before writing either.
    foreach($containers as $container){
        $old[$container]=dockerCmd($container,['cat','/etc/hosts']);
        $target[$container]=plannedHosts($old[$container],$host,$ip);
        if($old[$container]!==$target[$container])$changed=true;
    }
    if(!$changed)return false;
    logmsg(($apply?'APPLY ':'PREVIEW ').'hosts '.$host.' -> '.$ip.' in '.implode(', ',$containers));
    if(!$apply)return true;
    $written=[];
    try{
        foreach($containers as $container)if($old[$container]!==$target[$container]){
            $written[]=$container; // Include partial write in rollback.
            writeHosts($container,$target[$container]);
        }
    }catch(Throwable $e){
        foreach(array_reverse($written) as $container){
            try{writeHosts($container,$old[$container]);}
            catch(Throwable $rollback){logmsg('ROLLBACK FAILED '.$container.': '.$rollback->getMessage());}
        }
        throw $e;
    }
    return true;
}
try{
    $mode=$argv[1]??'--preview';
    if(!in_array($mode,['--preview','--status','--apply'],true))
        throw new RuntimeException('Usage: script --preview|--status|--apply');
    $cfg=asjson((string)file_get_contents($argv[2]??__DIR__.'/config.json'));
    if(!is_bool($cfg['dry_run']??null))throw new RuntimeException('dry_run must be boolean');
    $apply=$mode==='--apply';
    if($apply&&$cfg['dry_run'])throw new RuntimeException('dry_run=true: refusing --apply');
    $host=strtolower((string)($cfg['tracker_domain']??''));
    if(!preg_match('/^[a-z0-9.-]+$/D',$host))throw new RuntimeException('Invalid tracker domain');
    $containers=[(string)($cfg['qbittorrent_container']??'qbittorrent'),(string)($cfg['transmission_container']??'transmission')];
    if(count(array_unique($containers))!==2)throw new RuntimeException('Container names must differ');
    $lockPath=(string)($cfg['lock_file']??__DIR__.'/run.lock');
    if(is_link($lockPath))throw new RuntimeException('Symlink run.lock');
    $lock=fopen($lockPath,'c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){logmsg('Already running, skipping');exit(0);}
    $stateFile=(string)($cfg['state_file']??__DIR__.'/state.json');
    $state=is_file($stateFile)?asjson((string)file_get_contents($stateFile)):
        ['bad_runs'=>0,'current_ip'=>'','last_switch'=>0];
    $current=(string)($state['current_ip']??'');
    if($mode==='--status'){
        logmsg('domain='.$host.' current_ip='.($current?:'none').
            ' bad_runs='.($state['bad_runs']??0).' last_switch='.($state['last_switch']??0));
        exit(0);
    }
    $creds=asjson((string)file_get_contents((string)($cfg['credentials_file']??'')));
    $qb=new Qbit($creds['qbittorrent']??[]);
    $tr=new Transmission($creds['transmission']??[]);
    $q=qbitStats($qb,$host,(array)($cfg['categories']??[]));
    $t=transStats($tr,$host);
    foreach(['qBittorrent'=>$q,'Transmission'=>$t] as $name=>$x)
        logmsg($name.' checked='.$x['checked'].' ok='.$x['ok'].' network_fail='.$x['bad'].' unknown='.$x['unknown']);
    $min=max(2,(int)($cfg['min_failed_trackers']??3));
    $ratio=max(0.1,min(1,(float)($cfg['failed_ratio']??0.7)));
    $bad=fn(array $a)=>$a['bad']>=$min&&($a['bad']+$a['ok'])>=$min
        &&$a['bad']/($a['bad']+$a['ok']) >= $ratio;
    $ill=$bad($q)||$bad($t);
    $runs=$ill?(int)($state['bad_runs']??0)+1:0;
    logmsg('Health '.($ill?'FAIL':'OK').' consecutive_bad_runs='.$runs);
    // Container recreation removes runtime hosts: heal saved mapping in every apply.
    if($current!==''&&filter_var($current,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))
        syncHosts($containers,$host,$current,$apply);
    $required=max(1,(int)($cfg['consecutive_bad_runs']??2));
    $cooldown=max(600,(int)($cfg['min_switch_interval_seconds']??3600));
    if(!$ill||$runs<$required){
        logmsg('No switch (healthy or waiting for consecutive failures)');
        if($apply){$state['bad_runs']=$runs;stateSave($stateFile,$state);}exit(0);
    }
    if(time()-(int)($state['last_switch']??0)<$cooldown){
        logmsg('No switch (cooldown)');
        if($apply){$state['bad_runs']=$runs;stateSave($stateFile,$state);}exit(0);
    }
    $best='';$score=INF;$timeout=max(2,min(10,(int)($cfg['probe_timeout_seconds']??3)));
    foreach(candidates($cfg) as $ip){
        if($ip===$current)continue;
        $ok=true;$sum=0;$details=[];
        foreach($containers as $container){
            [$good,$duration]=probeIP($container,$host,$ip,$timeout);
            $details[]=$container.'='.($good?'PASS':'FAIL');
            if(!$good)$ok=false;
            $sum+=$duration;
        }
        logmsg('Probe '.$ip.' '.implode(' ',$details).($ok?' TLS_total='.round($sum,3).'s':''));
        if($ok&&$sum<$score){$best=$ip;$score=$sum;}
    }
    if($best===''){
        logmsg('No IP passed TLS from both clients; keeping mappings unchanged');
        if($apply){$state['bad_runs']=$runs;stateSave($stateFile,$state);}exit(1);
    }
    logmsg('Preferred IPv4 '.$best.' (TLS proof only; announce not guaranteed)');
    syncHosts($containers,$host,$best,$apply);
    if($apply){
        $state['current_ip']=$best;$state['last_switch']=time();$state['bad_runs']=0;
        stateSave($stateFile,$state);
        logmsg('Updated both containers without changing any torrent URL. Await natural announce.');
    }else logmsg('Preview only: no changes made');
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
