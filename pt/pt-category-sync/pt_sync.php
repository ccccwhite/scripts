<?php
/** qBittorrent category -> Transmission 4.1 tags; Unraid PHP + cURL */
declare(strict_types=1);
function logmsg(string $m): void { echo '['.date('Y-m-d H:i:s').'] '.$m.PHP_EOL; }
function req(string $url, string $method='GET', ?string $data=null, array $headers=[], string $user='', string $pass='', ?string $cookie=null): array {
    $c=curl_init($url); $rh=[];
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_TIMEOUT=>30,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$rh){$i=strpos($line,':');if($i!==false)$rh[strtolower(trim(substr($line,0,$i)))]=trim(substr($line,$i+1));return strlen($line);}]);
    if($data!==null)curl_setopt($c,CURLOPT_POSTFIELDS,$data);
    if($user!=='')curl_setopt($c,CURLOPT_USERPWD,$user.':'.$pass);
    if($cookie!==null){curl_setopt($c,CURLOPT_COOKIEJAR,$cookie);curl_setopt($c,CURLOPT_COOKIEFILE,$cookie);}
    $body=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$err=curl_error($c);curl_close($c);
    if($body===false)throw new RuntimeException('HTTP request failed: '.$err);
    return [$status,(string)$body,$rh];
}
function asjson(string $s): array { $a=json_decode($s,true);if(!is_array($a))throw new RuntimeException('Invalid JSON');return $a; }
function qbget(string $base,string $endpoint,?string $cookie,array $authHeaders=[]): array {
    [$code,$data]=req(rtrim($base,'/').'/api/v2/'.$endpoint,'GET',null,$authHeaders,'','',$cookie);
    if($code!==200)throw new RuntimeException('qBittorrent API '.$endpoint.' HTTP '.$code);
    return asjson($data);
}
function rpc(string $url,string $user,string $pass,string $method,array $params,string &$session): array {
    static $n=0;$id=++$n;
    $payload=json_encode(['jsonrpc'=>'2.0','method'=>$method,'params'=>$params,'id'=>$id],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    for($try=0;$try<3;$try++){
        $h=['Content-Type: application/json'];if($session!=='')$h[]='X-Transmission-Session-Id: '.$session;
        [$code,$data,$rh]=req($url,'POST',$payload,$h,$user,$pass);
        if($code===409){$session=$rh['x-transmission-session-id']??'';if($session==='')throw new RuntimeException('Missing Transmission session ID');continue;}
        if($code!==200)throw new RuntimeException('Transmission RPC HTTP '.$code);
        $r=asjson($data);
        if(isset($r['error']))throw new RuntimeException('Transmission RPC error: '.json_encode($r['error'],JSON_UNESCAPED_UNICODE));
        if(($r['id']??null)!==$id||!isset($r['result'])||!is_array($r['result']))throw new RuntimeException('Unexpected Transmission JSON-RPC response');
        return $r['result'];
    }
    throw new RuntimeException('Transmission session handshake failed');
}
function hostof(string $url): string {
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    return rtrim($host,'.');
}
function matchRule(string $host,array $rules): array {
    $found=[];
    foreach($rules as $category=>$domains){
        if(!is_array($domains))continue;
        foreach($domains as $d){
            $d=strtolower(trim((string)$d,'. '));
            if($d!==''&&($host===$d||substr($host,-strlen('.'.$d))==='.'. $d)){$found[$category]=true;break;}
        }
    }
    return array_keys($found);
}
try {
    if(!extension_loaded('curl'))throw new RuntimeException('PHP curl extension required');
    $path=$argv[1]??__DIR__.'/config.json';
    $cfg=asjson((string)file_get_contents($path));
    $qb=$cfg['qbittorrent'];$tr=$cfg['transmission'];
    $dry=(bool)($cfg['dry_run']??true);
    $cachePath=$cfg['cache_file']??dirname($path).'/cache.json';
    $cache=['hash_category'=>[],'tracker_category'=>[]];
    if(($cfg['use_cache']??true)&&is_file($cachePath)){
        $read=asjson((string)file_get_contents($cachePath));
        $cache=array_merge($cache,$read);
    }
    $mode=$qb['auth_mode']??'password';
    if(!in_array($mode,['password','api_key'],true))throw new RuntimeException('Invalid qbittorrent auth_mode');
    $cookie=null;$authHeaders=[];
    if($mode==='api_key'){
        $key=trim((string)($qb['api_key']??''));
        if(!preg_match('/^qbt_[A-Za-z0-9]{28}$/',$key))throw new RuntimeException('Invalid qBittorrent API key format');
        $authHeaders=['Authorization: Bearer '.$key];
    } else {
        $cookie=tempnam(sys_get_temp_dir(),'qbcat');
        if($cookie===false)throw new RuntimeException('Unable to create temporary cookie file');
    }
    try {
        if($mode==='password'){
            $base=rtrim($qb['url'],'/');
            [$code,$response]=req($base.'/api/v2/auth/login','POST',http_build_query(['username'=>$qb['username']??'','password'=>$qb['password']??'']),['Content-Type: application/x-www-form-urlencoded','Referer: '.$base.'/'],'','',$cookie);
            if($code!==200||trim($response)!=='Ok.')throw new RuntimeException('qBittorrent authentication failed');
        }
        $categories=qbget($qb['url'],'torrents/categories',$cookie,$authHeaders);
        $torrents=qbget($qb['url'],'torrents/info?filter=all',$cookie,$authHeaders);
        logmsg('qBittorrent: '.count($categories).' categories, '.count($torrents).' torrents');
        $valid=array_fill_keys(array_keys($categories),true);
        $learn=[];$hashMap=[];$max=max(0,(int)($cfg['max_qbit_tracker_lookups']??500));$calls=0;
        foreach($torrents as $t){
            $cat=trim((string)($t['category']??''));$hash=strtolower((string)($t['hash']??''));
            if($cat===''||!isset($valid[$cat])||$hash==='')continue;
            $hashMap[$hash]=$cat;
            if(!($cfg['learn_from_qbit_trackers']??true)||$calls>=$max)continue;
            $calls++;
            [$st,$body]=req(rtrim($qb['url'],'/').'/api/v2/torrents/trackers?hash='.rawurlencode($hash),'GET',null,$authHeaders,'','',$cookie);
            if($st!==200)continue;
            $tracks=asjson($body);
            foreach($tracks as $track){
                $host=hostof((string)($track['url']??''));
                if($host!=='')$learn[$host][$cat]=true;
            }
        }
    } finally { if($cookie!==null) @unlink($cookie); }
    foreach($hashMap as $h=>$cat)$cache['hash_category'][$h]=$cat;
    foreach($learn as $host=>$cats){
        if(count($cats)===1)$cache['tracker_category'][$host]=array_key_first($cats);
        else unset($cache['tracker_category'][$host]);
    }
    if($cfg['use_cache']??true){
        $dir=dirname($cachePath);if(!is_dir($dir))mkdir($dir,0700,true);
        $tmp=tempnam($dir,'.cache-');if($tmp===false)throw new RuntimeException('Cache file error');
        chmod($tmp,0600);
        file_put_contents($tmp,json_encode($cache,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
        rename($tmp,$cachePath);
    }
    $session='';
    $res=rpc($tr['rpc_url'],$tr['username']??'',$tr['password']??'','torrent_get',['fields'=>['id','hash_string','labels','trackers']],$session);
    $items=$res['torrents']??[];
    $changed=0;$skipped=0;
    foreach($items as $item){
        $hash=strtolower((string)($item['hash_string']??''));
        $existing=array_values(array_filter((array)($item['labels']??[]),'is_string'));
        $candidates=[];
        if(isset($hashMap[$hash]))$candidates[$hashMap[$hash]]=true;
        elseif(isset($cache['hash_category'][$hash]))$candidates[$cache['hash_category'][$hash]]=true;
        foreach((array)($item['trackers']??[]) as $tracker){
            $host=hostof((string)($tracker['announce']??''));
            if($host==='')continue;
            $matches=matchRule($host,(array)($cfg['tracker_domain_rules']??[]));
            if(count($matches)===0&&isset($learn[$host])&&count($learn[$host])===1)$matches=[array_key_first($learn[$host])];
            if(count($matches)===0&&isset($cache['tracker_category'][$host]))$matches=[$cache['tracker_category'][$host]];
            foreach($matches as $cat)$candidates[$cat]=true;
        }
        if(count($candidates)!==1){$skipped++;continue;}
        $cat=array_key_first($candidates);
        if(($cfg['only_current_qbit_categories']??false)&&!isset($valid[$cat]))continue;
        if(in_array($cat,$existing,true))continue;
        $new=array_values(array_unique(array_merge($existing,[$cat])));
        logmsg(($dry?'PREVIEW':'UPDATE').' torrent '.substr($hash,0,12).' add label '.$cat);
        if(!$dry)rpc($tr['rpc_url'],$tr['username']??'',$tr['password']??'','torrent_set',['ids'=>[(int)$item['id']],'labels'=>$new],$session);
        $changed++;
    }
    logmsg('Done. '.($dry?'Preview changes':'Updated').': '.$changed.', unmatched/ambiguous: '.$skipped);
} catch(Throwable $e) {fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
