<?php
/** PT Tracker IP monitor: reannounce <=2 sample torrents per domain across both clients; confirm fresh results before IP switch. */
declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');

function say(string $s): void { echo '[' . date('Y-m-d H:i:s') . '] ' . $s . PHP_EOL; }
function asjson(string $s): array { $v=json_decode($s,true,512,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException('Invalid JSON');return $v; }
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
    public function reannounce(string $hash,string $url): void {
        // Web API 5.x supports selecting one tracker URL; never announce all torrents.
        $this->request('POST','torrents/reannounce',['hashes'=>$hash,'urls'=>$url]);
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
    public function reannounce(string $hash): void {
        // Transmission 4.1 RPC: ids is mandatory to avoid all-torrents reannounce.
        $this->rpc('torrent_reannounce',['ids'=>[$hash]]);
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

/** Health statistics are based on ACTIVE torrents only; stopped torrents carry stale failures. */
function qbitAudit(Qbit $qb,string $host,array $cats): array {
    $stats=['ok'=>0,'bad'=>0,'unknown'=>0,'checked'=>0,'stopped'=>0];$samples=[];
    foreach($qb->get('torrents/info?filter=all') as $t){
        if(!in_array(strtolower((string)($t['category']??'')),$cats,true)
           && endpointHost((string)($t['tracker']??''))!==$host)continue;
        $hash=strtolower((string)($t['hash']??''));
        if(!preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D',$hash))continue;
        $state=(string)($t['state']??'');
        foreach($qb->get('torrents/trackers?hash='.$hash) as $tr){
            if(endpointHost((string)($tr['url']??''))!==$host)continue;
            if(str_starts_with($state,'stopped')){$stats['stopped']++;continue;}
            $stats['checked']++;$code=(int)($tr['status']??-1);
            if($code===2)$stats['ok']++;
            elseif(in_array($code,[4,5,6],true)&&hasNetError((string)($tr['msg']??''))){
                $stats['bad']++;
                $samples[]=['client'=>'qbit','hash'=>$hash,'name'=>(string)($t['name']??''),
                    'tracker_url'=>(string)($tr['url']??''),
                    'baseline_next'=>(int)($tr['next_announce']??0),
                    'rank'=>str_ends_with($state,'DL')?0:1];
            }else $stats['unknown']++;
        }
    }
    usort($samples,fn($x,$y)=>$x['rank']<=>$y['rank']);
    return [$stats,$samples];
}
function transAudit(Transmission $tr,string $host): array {
    $data=$tr->rpc('torrent_get',['fields'=>['hash_string','name','status','tracker_stats']]);
    $stats=['ok'=>0,'bad'=>0,'unknown'=>0,'checked'=>0,'stopped'=>0];$samples=[];
    foreach($data['torrents']??[] as $t){
        $hash=strtolower((string)($t['hash_string']??''));
        if(!preg_match('/^[a-f0-9]{40}$/D',$hash))continue;
        $trackers=(array)($t['tracker_stats']??[]);
        foreach($trackers as $v){
            if(endpointHost((string)($v['announce']??''))!==$host)continue;
            if((int)($t['status']??0)===0){$stats['stopped']++;continue;}
            $stats['checked']++;
            if(!($v['has_announced']??false)){$stats['unknown']++;continue;}
            if(($v['last_announce_succeeded']??false)===true)$stats['ok']++;
            elseif(($v['last_announce_timed_out']??false)===true||
                   hasNetError((string)($v['last_announce_result']??''))){
                $stats['bad']++;
                // Transmission reannounce targets every tracker for ONE torrent.
                // Only select single-host tracker torrents.
                $other=false;
                foreach($trackers as $entry){
                    $h=endpointHost((string)($entry['announce']??''));
                    if($h!==''&&$h!==$host){$other=true;break;}
                }
                if(!$other)$samples[]=['client'=>'transmission','hash'=>$hash,
                    'name'=>(string)($t['name']??''),
                    'baseline_last'=>(int)($v['last_announce_time']??0)];
            }else $stats['unknown']++;
        }
    }
    return [$stats,$samples];
}
function selectSamples(array $q,array $t,int $max): array {
    $out=[];
    if($q)$out[]=$q[0];
    if(count($out)<$max&&$t)$out[]=$t[0];
    foreach([$q,$t] as $arr)for($i=1;$i<count($arr)&&count($out)<$max;$i++)$out[]=$arr[$i];
    return array_slice($out,0,$max);
}
/** A stale failed status is NOT proof of a fresh failed reannounce. */
function probeResults(array $samples,Qbit $qb,Transmission $tr,string $host,int $settle): array {
    $ok=0;$fail=0;$pending=0;$details=[];
    $transMap=[];
    if(array_filter($samples,fn($x)=>($x['client']??'')==='transmission')){
        $data=$tr->rpc('torrent_get',['fields'=>['hash_string','tracker_stats']]);
        foreach($data['torrents']??[] as $t)$transMap[strtolower((string)($t['hash_string']??''))]=$t;
    }
    foreach($samples as $s){
        $client=(string)($s['client']??'');$hash=(string)($s['hash']??'');
        $start=(int)($s['started']??0);$result='pending';
        if($start>0&&time()-$start>=$settle){
            if($client==='qbit'){
                $data=$qb->get('torrents/trackers?hash='.rawurlencode($hash));
                foreach($data as $v)if(endpointHost((string)($v['url']??''))===$host){
                    $code=(int)($v['status']??-1);
                    $updating=$code===3;
                    foreach($v['endpoints']??[] as $e)if(($e['updating']??false)===true)$updating=true;
                    // next_announce is an epoch timestamp on the user's 5.3 client.
                    $fresh=(int)($v['next_announce']??0)>0 &&
                           (int)($v['next_announce']??0)!==(int)($s['baseline_next']??0);
                    if($fresh&&!$updating&&$code===2)$result='ok';
                    elseif($fresh&&!$updating&&in_array($code,[4,5,6],true)&&
                          hasNetError((string)($v['msg']??'')))$result='fail';
                    break;
                }
            }elseif($client==='transmission'){
                foreach(($transMap[$hash]['tracker_stats']??[]) as $v){
                    if(endpointHost((string)($v['announce']??''))!==$host)continue;
                    $last=(int)($v['last_announce_time']??0);
                    $fresh=$last>(int)($s['baseline_last']??0)&&$last>=$start-5;
                    if($fresh&&($v['last_announce_succeeded']??false)===true)$result='ok';
                    elseif($fresh&&(($v['last_announce_timed_out']??false)===true||
                         hasNetError((string)($v['last_announce_result']??''))))$result='fail';
                    break;
                }
            }
        }
        $details[]=$client.' '.substr($hash,0,10).' '.$result;
        if($result==='ok')$ok++;elseif($result==='fail')$fail++;else $pending++;
    }
    return [$ok,$fail,$pending,$details];
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
    say(($apply?'APPLY ':'PREVIEW ').'hosts '.$host.' -> '.$ip.' in '.implode(', ',$containers));
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
            catch(Throwable $rollback){say('ROLLBACK FAILED '.$container.': '.$rollback->getMessage());}
        }
        throw $e;
    }
    return true;
}
try{
    $mode=$argv[1]??'--preview';
    if(!in_array($mode,['--preview','--status','--apply'],true))
        throw new RuntimeException('Usage: script --preview|--status|--apply');
    $cfg=jsonFile($argv[2]??__DIR__.'/config.json');
    if(!is_bool($cfg['dry_run']??null))throw new RuntimeException('dry_run must be boolean');
    $apply=$mode==='--apply';
    if($apply&&$cfg['dry_run'])throw new RuntimeException('dry_run=true: refusing --apply');
    $host=strtolower((string)($cfg['tracker_domain']??''));
    if(!preg_match('/^[a-z0-9.-]+$/D',$host))throw new RuntimeException('Invalid tracker domain');
    $containers=[(string)($cfg['qbittorrent_container']??'qbittorrent'),
                 (string)($cfg['transmission_container']??'transmission')];
    if(count(array_unique($containers))!==2)throw new RuntimeException('Container names must differ');
    $lockPath=(string)($cfg['lock_file']??__DIR__.'/run.lock');
    if(is_link($lockPath))throw new RuntimeException('Symlink run.lock');
    $lock=fopen($lockPath,'c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){say('Already running; skipped');exit(0);}
    $stateFile=(string)($cfg['state_file']??__DIR__.'/state.json');
    $state=is_file($stateFile)?jsonFile($stateFile):
        ['current_ip'=>'','last_switch'=>0,'last_probe'=>0,'samples'=>[]];
    $state+=['current_ip'=>'','last_switch'=>0,'last_probe'=>0,'samples'=>[]];
    $current=(string)$state['current_ip'];
    if($mode==='--status'){
        say('domain='.$host.' current_ip='.($current?:'none').
            ' pending_samples='.count((array)$state['samples']).
            ' last_probe='.$state['last_probe'].' last_switch='.$state['last_switch']);
        exit(0);
    }
    $creds=jsonFile((string)($cfg['credentials_file']??''));
    $qb=new Qbit($creds['qbittorrent']??[]);
    $tr=new Transmission($creds['transmission']??[]);
    [$q,$qSamples]=qbitAudit($qb,$host,(array)($cfg['categories']??[]));
    [$t,$tSamples]=transAudit($tr,$host);
    foreach(['qBittorrent'=>$q,'Transmission'=>$t] as $name=>$x)
        say($name.' active='.$x['checked'].' ok='.$x['ok'].
            ' network_fail='.$x['bad'].' unknown='.$x['unknown'].
            ' stopped_skipped='.$x['stopped']);
    // Repair saved mapping if Docker recreated the two containers.
    if($current!==''&&filter_var($current,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))
        syncHosts($containers,$host,$current,$apply);

    $min=max(1,(int)($cfg['min_failed_trackers']??1));
    $ratio=max(0.1,min(1.0,(float)($cfg['failed_ratio']??0.7)));
    $isBad=fn($v)=>$v['bad']>=$min&&
        $v['bad']/max(1,$v['bad']+$v['ok']) >= $ratio;
    $suspected=$isBad($q)||$isBad($t);
    $samples=(array)$state['samples'];
    $settle=max(60,min(1800,(int)($cfg['probe_settle_seconds']??180)));
    if(!$suspected) {
        say('Tracker healthy; no reannounce or IP switch');
        if($apply){$state['samples']=[];stateSave($stateFile,$state);}
        exit(0);
    }
    if(!$samples){
        // At most 1-2 total samples per Tracker, preferably one per client.
        $sampleMax=max(1,min(2,(int)($cfg['sample_torrents_per_tracker']??2)));
        $samples=selectSamples($qSamples,$tSamples,$sampleMax);
        say('Potential network problem: '.($q['bad']+$t['bad']).
            ' failed active trackers. Selected '.count($samples).' sample(s), NOT all torrents');
        foreach($samples as $s)say('SAMPLE '.$s['client'].' '.substr($s['hash'],0,12).' '.substr(str_replace(["\r","\n"],' ',(string)$s['name']),0,80));
        if(!$samples){say('No active failed sample available; cannot confirm error');exit(0);}
        $gap=max(900,(int)($cfg['min_reannounce_interval_seconds']??1800));
        if(time()-(int)$state['last_probe']<$gap){
            say('Reannounce cooldown active; no action');exit(0);
        }
        if(!$apply){say('Preview only: next --apply reannounces <=2 samples');exit(0);}
        // Save write-ahead intent and lock out repeats before sending any announce.
        $state['last_probe']=time();$state['samples']=[];
        stateSave($stateFile,$state);
        $sent=[];
        try{
            foreach($samples as $s){
                $s['started']=time();
                // Store no URL/Passkey on disk.
                if($s['client']==='qbit')
                    $qb->reannounce($s['hash'],$s['tracker_url']);
                else $tr->reannounce($s['hash']);
                unset($s['tracker_url'],$s['name'],$s['rank']);
                $sent[]=$s;
                $state['samples']=$sent;
                stateSave($stateFile,$state);
                say('REANNOUNCE '.$s['client'].' '.substr($s['hash'],0,12).' requested');
            }
        }catch(Throwable $e){
            say('Reannounce request error: '.$e->getMessage().
                ' ; sent samples saved for later verification');
            exit(1);
        }
        say('Will verify fresh results on next scheduled check; no IP changed now');
        exit(0);
    }
    [$ok,$fail,$pending,$details]=probeResults($samples,$qb,$tr,$host,$settle);
    foreach($details as $detail)say('VERIFY '.$detail);
    if($pending>0){say('Awaiting fresh tracker replies; no IP change');exit(0);}
    if($ok>0){
        say('At least one sample recovered; no IP change');
        if($apply){$state['samples']=[];stateSave($stateFile,$state);}
        exit(0);
    }
    if($fail===0){say('No confirmed sample failure; no IP change');exit(0);}
    say('CONFIRMED: '.$fail.' fresh sample announce failure(s). Testing alternate IPs.');
    $cooldown=max(600,(int)($cfg['min_switch_interval_seconds']??3600));
    if(time()-(int)($state['last_switch']??0)<$cooldown){
        say('IP switch cooldown; keeping current IP');exit(0);
    }
    $best='';$score=INF;$timeout=max(2,min(10,(int)($cfg['probe_timeout_seconds']??3)));
    foreach(candidates($cfg) as $ip){
        if($ip===$current)continue;
        $pass=true;$sum=0;$details=[];
        foreach($containers as $container){
            [$good,$duration]=probeIP($container,$host,$ip,$timeout);
            $details[]=$container.'='.($good?'PASS':'FAIL');
            if(!$good)$pass=false;
            $sum+=$duration;
        }
        say('PROBE '.$ip.' '.implode(' ',$details).($pass?' TLS_total='.round($sum,3).'s':''));
        if($pass&&$sum<$score){$best=$ip;$score=$sum;}
    }
    if($best===''){say('No alternate IP passed TLS; current IP unchanged');exit(1);}
    say('Preferred IP='.$best.' (TLS only; announce verification still needed)');
    syncHosts($containers,$host,$best,$apply);
    if($apply){
        $state['current_ip']=$best;$state['last_switch']=time();$state['samples']=[];
        stateSave($stateFile,$state);
        say('Updated both containers. Future runs will sample announce again if failures persist.');
    }else say('Preview only; no IP changed');
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
