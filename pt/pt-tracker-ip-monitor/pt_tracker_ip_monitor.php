<?php
/** PT Tracker IP monitor: reannounce <=2 sample torrents per domain across both clients; confirm fresh results before IP switch. */
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

function validHost(string $url): string {
    $p=parse_url($url);
    if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'
       ||(isset($p['port'])&&(int)$p['port']!==443))return '';
    $h=strtolower(rtrim((string)($p['host']??''),'.'));
    return preg_match('/^[a-z0-9.-]+$/D',$h)?$h:'';
}
function qbitAuditAll(Qbit $qb): array {
    $out=[];
    foreach($qb->get('torrents/info?filter=all') as $t) {
        $hash=strtolower((string)($t['hash']??''));
        if(!preg_match('/^[a-f0-9]{40}$/D',$hash))continue;
        foreach($qb->get('torrents/trackers?hash='.$hash) as $v) {
            $host=validHost((string)($v['url']??''));
            if($host==='')continue;
            $out[$host]??=['ok'=>0,'bad'=>0,'unknown'=>0,'samples'=>[]];
            if(str_starts_with((string)($t['state']??''),'stopped'))continue;
            $code=(int)($v['status']??-1);
            if($code===2)$out[$host]['ok']++;
            elseif($code===4&&hasNetError((string)($v['msg']??''))){
                $out[$host]['bad']++;
                $out[$host]['samples'][]=['client'=>'qbit','hash'=>$hash,
                    'baseline'=>(int)($v['next_announce']??0),'url'=>(string)$v['url']];
            }else $out[$host]['unknown']++;
        }
    }
    return $out;
}
function transAuditAll(Transmission $tr): array {
    $out=[];$data=$tr->rpc('torrent_get',['fields'=>['hash_string','status','tracker_stats']]);
    foreach($data['torrents']??[] as $t) {
        $hash=strtolower((string)($t['hash_string']??''));
        if(!preg_match('/^[a-f0-9]{40}$/D',$hash))continue;
        $rows=(array)($t['tracker_stats']??[]);$hosts=[];
        foreach($rows as $v){$h=validHost((string)($v['announce']??''));if($h!=='')$hosts[$h]=true;}
        foreach($rows as $v){
            $host=validHost((string)($v['announce']??''));if($host==='')continue;
            $out[$host]??=['ok'=>0,'bad'=>0,'unknown'=>0,'samples'=>[]];
            if((int)($t['status']??0)===0)continue;
            if(!($v['has_announced']??false)){$out[$host]['unknown']++;continue;}
            if(($v['last_announce_succeeded']??false)===true)$out[$host]['ok']++;
            elseif(($v['last_announce_timed_out']??false)===true||
                   hasNetError((string)($v['last_announce_result']??''))){
                $out[$host]['bad']++;
                if(count($hosts)===1)$out[$host]['samples'][]=[
                    'client'=>'transmission','hash'=>$hash,
                    'baseline'=>(int)($v['last_announce_time']??0)];
            }else $out[$host]['unknown']++;
        }
    }
    return $out;
}
function selectSamples(array $q,array $t,int $limit): array {
    $out=[];$seen=[];
    foreach(array_merge(array_slice($q,0,1),array_slice($t,0,1),$q,$t) as $v){
        $key=$v['client'].':'.$v['hash'];if(isset($seen[$key]))continue;
        $seen[$key]=true;$out[]=$v;if(count($out)>=$limit)break;
    }
    return $out;
}
function verifySamples(array $samples,Qbit $qb,Transmission $tr,string $host,int $settle): array {
    $ok=0;$bad=0;$pending=0;
    $map=[];
    if(array_filter($samples,fn($x)=>($x['client']??'')==='transmission')){
        $data=$tr->rpc('torrent_get',['fields'=>['hash_string','tracker_stats']]);
        foreach($data['torrents']??[] as $v)$map[strtolower((string)($v['hash_string']??''))]=$v;
    }
    foreach($samples as $s){
        $result='pending';$started=(int)($s['started']??0);
        if($started>0 && time()-$started >= $settle){
            if($s['client']==='qbit'){
                foreach($qb->get('torrents/trackers?hash='.rawurlencode($s['hash'])) as $v){
                    if(validHost((string)($v['url']??''))!==$host)continue;
                    $fresh=(int)($v['next_announce']??0)>0 &&
                           (int)($v['next_announce']??0)!==(int)($s['baseline']??0);
                    $code=(int)($v['status']??-1);
                    if($fresh&&$code===2)$result='ok';
                    elseif($fresh&&$code===4&&hasNetError((string)($v['msg']??'')))$result='bad';
                    break;
                }
            }else{
                foreach(($map[$s['hash']]['tracker_stats']??[]) as $v){
                    if(validHost((string)($v['announce']??''))!==$host)continue;
                    $last=(int)($v['last_announce_time']??0);
                    if($last>(int)($s['baseline']??0) && $last >= $started-5){
                        if(($v['last_announce_succeeded']??false)===true)$result='ok';
                        elseif(($v['last_announce_timed_out']??false)===true||
                               hasNetError((string)($v['last_announce_result']??'')))$result='bad';
                    }
                    break;
                }
            }
        }
        say('VERIFY '.$host.' '.$s['client'].' '.substr($s['hash'],0,10).' '.$result);
        if($result==='ok')$ok++;elseif($result==='bad')$bad++;else $pending++;
    }
    return [$ok,$bad,$pending];
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

function sourceHosts(string $host,array $common): array {
    $sources=[$host];
    foreach($common['sites']??[] as $site) {
        if(!in_array($host,$site['trackers']??[],true))continue;
        $group=(string)($site['dns_group']??'');
        foreach($common['dns_groups'][$group]??[] as $name)$sources[]=$name;
        break;
    }
    return array_values(array_unique($sources));
}
function candidates(string $host,array $common,int $max): array {
    $ips=[];
    foreach(sourceHosts($host,$common) as $name){
        if(!is_string($name)||!preg_match('/^[a-z0-9.-]+$/D',$name))continue;
        $records=@dns_get_record($name,DNS_A)?:[];
        say('Fresh DNS '.$host.' source='.$name.' records='.count($records));
        foreach($records as $r)$ips[]=$r['ip']??'';
    }
    $result=[];
    foreach(array_unique($ips) as $ip){
        if(is_string($ip)&&filter_var($ip,FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))$result[]=$ip;
        if(count($result)>=$max)break;
    }
    return $result;
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
    if(!in_array($mode,['--preview','--apply','--status'],true))throw new RuntimeException('Usage: --preview|--apply|--status');
    $cfg=jsonFile($argv[2]??__DIR__.'/config.json');
    if(!is_bool($cfg['dry_run']??null))throw new RuntimeException('dry_run must be a boolean');
    $apply=$mode==='--apply';
    if($apply&&$cfg['dry_run'])throw new RuntimeException('dry_run=true; refusing --apply');
    $lockPath=(string)($cfg['lock_file']??__DIR__.'/run.lock');
    if(is_link($lockPath))throw new RuntimeException('Unsafe lock');
    $lock=fopen($lockPath,'c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){say('Already running; skipped');exit(0);}
    $statePath=(string)($cfg['state_file']??__DIR__.'/state.json');
    $state=is_file($statePath)?jsonFile($statePath):[];
    $common=jsonFile((string)($cfg['common_tracker_file']??''));
    $containers=[(string)($cfg['qbittorrent_container']??'qbittorrent'),
                 (string)($cfg['transmission_container']??'transmission')];
    if($containers[0]===$containers[1])throw new RuntimeException('Container names overlap');
    if($mode==='--status'){
        foreach($state as $host=>$entry)say($host.' IP='.($entry['current_ip']??'-').
           ' pending='.count((array)($entry['samples']??[])));
        if(!$state)say('No tracked domains yet');
        exit(0);
    }
    $creds=jsonFile((string)($cfg['credentials_file']??''));
    $qb=new Qbit($creds['qbittorrent']??[]);
    $tr=new Transmission($creds['transmission']??[]);
    $q=qbitAuditAll($qb);$t=transAuditAll($tr);
    $hosts=array_values(array_unique(array_merge(array_keys($q),array_keys($t),array_keys($state))));
    sort($hosts,SORT_STRING);
    say('Discovered HTTPS Tracker hosts: '.count($hosts));
    $maxSamples=max(1,min(2,(int)($cfg['sample_torrents_per_tracker']??2)));
    $settle=max(60,min(1800,(int)($cfg['probe_settle_seconds']??180)));
    $probeGap=max(900,(int)($cfg['min_reannounce_interval_seconds']??1800));
    $switchGap=max(600,(int)($cfg['min_switch_interval_seconds']??3600));
    $maxDomains=max(1,min(10,(int)($cfg['max_domains_per_run']??2)));
    $processed=0;
    foreach($hosts as $host){
        $qv=$q[$host]??['ok'=>0,'bad'=>0,'unknown'=>0,'samples'=>[]];
        $tv=$t[$host]??['ok'=>0,'bad'=>0,'unknown'=>0,'samples'=>[]];
        $bad=$qv['bad']+$tv['bad'];
        $entry=$state[$host]??['current_ip'=>'','last_switch'=>0,'last_probe'=>0,'samples'=>[]];
        say($host.' qBit(ok='.$qv['ok'].' bad='.$qv['bad'].') trans(ok='.$tv['ok'].' bad='.$tv['bad'].')');
        if(($entry['current_ip']??'')!==''){
            syncHosts($containers,$host,(string)$entry['current_ip'],$apply);
        }
        if($bad===0){
            if($apply&&($entry['samples']??[])){ $entry['samples']=[];$state[$host]=$entry;stateSave($statePath,$state); }
            continue;
        }
        $samples=(array)($entry['samples']??[]);
        if(!$samples){
            $chosen=selectSamples($qv['samples'],$tv['samples'],$maxSamples);
            if(!$chosen){say('SKIP '.$host.' no active sample');continue;}
            say('SUSPECT '.$host.' sample_count='.count($chosen));
            if(time()-(int)($entry['last_probe']??0)<$probeGap){say('SKIP '.$host.' reannounce cooldown');continue;}
            if(!$apply){say('PREVIEW '.$host.' no reannounce');continue;}
            if($processed >= $maxDomains){say('SKIP '.$host.' per-run domain limit');continue;}
            $entry['last_probe']=time();$entry['samples']=[];$state[$host]=$entry;stateSave($statePath,$state);
            foreach($chosen as $sample){
                try{
                    if($sample['client']==='qbit')$qb->reannounce($sample['hash'],$sample['url']);
                    else $tr->reannounce($sample['hash']);
                    unset($sample['url']);
                    $sample['started']=time();$entry['samples'][]=$sample;
                    $state[$host]=$entry;stateSave($statePath,$state);
                    say('REANNOUNCE '.$host.' '.$sample['client'].' '.substr($sample['hash'],0,10));
                }catch(Throwable $e){say('FAIL reannounce '.$host.': '.$e->getMessage());break;}
            }
            $processed++;continue;
        }
        [$ok,$fail,$pending]=verifySamples($samples,$qb,$tr,$host,$settle);
        if($pending>0){say('WAIT '.$host.' fresh results');continue;}
        if($ok>0){
            say('HEALTHY '.$host.' sample recovered');
            if($apply){$entry['samples']=[];$state[$host]=$entry;stateSave($statePath,$state);}
            continue;
        }
        if($fail===0){say('WAIT '.$host.' no fresh failed result');continue;}
        if(time()-(int)($entry['last_switch']??0)<$switchGap){say('SKIP '.$host.' switch cooldown');continue;}
        if($processed >= $maxDomains){say('SKIP '.$host.' per-run domain limit');continue;}
        $best='';$score=INF;$current=(string)($entry['current_ip']??'');
        foreach(candidates($host,$common,max(1,min(64,(int)($cfg['max_candidates']??20)))) as $ip){
            if($ip===$current)continue;
            $passed=true;$elapsed=0.0;
            foreach($containers as $container){
                [$pass,$latency]=probeIP($container,$host,$ip,max(2,min(10,(int)($cfg['probe_timeout_seconds']??3))));
                if(!$pass)$passed=false;
                $elapsed+=$latency;
            }
            say('IPCHECK '.$host.' '.$ip.' '.($passed?'TLS_PASS':'FAIL'));
            if($passed&&$elapsed<$score){$score=$elapsed;$best=$ip;}
        }
        if($best===''){say('SKIP '.$host.' no reachable IP');continue;}
        say('CHOICE '.$host.' '.$best.' (TLS only, NOT confirmed announce)');
        syncHosts($containers,$host,$best,$apply);
        if($apply){
            $entry['current_ip']=$best;$entry['last_switch']=time();$entry['samples']=[];
            $state[$host]=$entry;stateSave($statePath,$state);
        }
        $processed++;
    }
    say('DONE processed_domains='.$processed.' mode='.$mode);
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
