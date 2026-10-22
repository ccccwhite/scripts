<?php
declare(strict_types=1);
// Read-only torrent upload inactivity report for Transmission 4.1 JSON-RPC.
function mergeCredentials(array $cfg): array {
    $path=$cfg['credentials_file']??null;
    if($path===null||$path==='')return $cfg; // Backward-compatible existing config.
    if(!is_string($path)||!is_file($path)||!is_readable($path))throw new RuntimeException('Shared credentials file not readable: '.(string)$path);
    $cred=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($cred))throw new RuntimeException('Invalid shared credentials JSON');
    foreach(['qbittorrent','transmission'] as $name){
        if(!isset($cred[$name])||!is_array($cred[$name]))throw new RuntimeException('Shared credentials missing: '.$name);
        $cfg[$name]=$cred[$name];
    }
    return $cfg;
}
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
function qbList(array $cfg): array {
    $base = rtrim($cfg['url'], '/');
    $cookie = null;
    $headers = [];

    $key = trim((string)($cfg['api_key'] ?? ''));
    $hasPassword = !empty($cfg['username']) &&
                   !empty($cfg['password']);

    try {
        if ($key !== '') {
            try {
                $headers = ['Authorization: Bearer '.$key];

                qbget($base, 'torrents/categories', null, $headers);

                return qbget(
                    $base,
                    'torrents/info?filter=all',
                    null,
                    $headers
                );
            } catch (Throwable $e) {
                if (!$hasPassword) throw $e;
                $headers = [];
            }
        }

        if (!$hasPassword) {
            throw new RuntimeException('qBittorrent credentials missing');
        }

        $cookie = tempnam(sys_get_temp_dir(), 'qbcat');

        [$status, $response] = req(
            $base.'/api/v2/auth/login',
            'POST',
            http_build_query([
                'username' => $cfg['username'],
                'password' => $cfg['password']
            ]),
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Referer: '.$base.'/'
            ],
            '',
            '',
            $cookie
        );

        if (!(
            $status === 204 ||
            ($status === 200 && trim($response) === 'Ok.')
        )) {
            throw new RuntimeException(
                'qBittorrent login HTTP '.$status
            );
        }

        qbget($base, 'torrents/categories', $cookie);

        return qbget(
            $base,
            'torrents/info?filter=all',
            $cookie
        );

    } finally {
        if ($cookie !== null) {
            @unlink($cookie);
        }
    }
}

function rpc(array $cfg): array {
    $url=$cfg['rpc_url']; $session='';
    $payload=json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'torrent_get',
        'params'=>['fields'=>['id','hash_string','name','uploaded_ever','activity_date','added_date','done_date','status','percent_done','labels','download_dir','rate_upload','seconds_seeding','upload_ratio','trackers','total_size']]],
        JSON_THROW_ON_ERROR);
    for($attempt=0;$attempt<3;$attempt++) {
        $headers=['Content-Type: application/json'];
        if($session!=='')$headers[]='X-Transmission-Session-Id: '.$session;
        $returned=[];
        $h=curl_init($url);
        curl_setopt_array($h,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>45,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
            CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$returned){
                $p=strpos($line,':');
                if($p!==false)$returned[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));
                return strlen($line);
            },
        ]);
        if(($cfg['username']??'')!=='')curl_setopt($h,CURLOPT_USERPWD,$cfg['username'].':'.($cfg['password']??''));
        $raw=curl_exec($h);$err=curl_error($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);curl_close($h);
        if($raw===false)throw new RuntimeException("Transmission request failed: ".$err);
        if($status===409){$session=$returned['x-transmission-session-id']??'';if($session==='')throw new RuntimeException('Missing session header');continue;}
        if($status!==200)throw new RuntimeException('Transmission HTTP '.$status);
        $obj=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(isset($obj['error']))throw new RuntimeException('Transmission RPC returned error');
        if(!isset($obj['result']['torrents'])||!is_array($obj['result']['torrents']))throw new RuntimeException('Missing torrent list');
        return $obj['result']['torrents'];
    }
    throw new RuntimeException('Transmission session negotiation failed');
}
function csvline($stream,array $fields): void {fputcsv($stream,$fields);}
try {
    if(!extension_loaded('curl'))throw new RuntimeException('PHP cURL is required');
    $path=$argv[1]??__DIR__.'/config.json';
    $cfg=mergeCredentials(json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR));
    $tz=new DateTimeZone((string)($cfg['timezone']??'Asia/Shanghai'));
    $now=time();
    $stateFile=$cfg['state_file']??__DIR__.'/state.json';
    $csvPath=$cfg['csv_file']??__DIR__.'/report.csv';
    $limit=(float)($cfg['minimum_upload_bytes']??0);
    $threshold=max(0,(int)($cfg['inactive_days']??30));
    $old=is_file($stateFile)?json_decode((string)file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR):[];
    if(!is_array($old))$old=[];
    $new=[];$rows=[];
    $trans=rpc($cfg['transmission']);
    foreach($trans as $t){
        $rows[]=['client'=>'Transmission','name'=>(string)($t['name']??''),
            'hash'=>strtolower((string)($t['hash_string']??'')),
            'uploaded'=>(int)($t['uploaded_ever']??0),'ratio'=>(float)($t['upload_ratio']??0),
            'seed_seconds'=>max(0,(int)($t['seconds_seeding']??0)),
            'last_activity'=>(int)($t['activity_date']??0),
            'storage'=>(string)($t['download_dir']??''),
            'labels'=>(array)($t['labels']??[]),
            'status'=>(string)($t['status']??''),'complete'=>(float)($t['percent_done']??0),
            'category'=>'','size'=>(int)($t['total_size']??0)];
    }
    $qbt=qbList($cfg['qbittorrent']);
    foreach($qbt as $t){
        $rows[]=['client'=>'qBittorrent','name'=>(string)($t['name']??''),
            'hash'=>strtolower((string)($t['hash']??'')),
            'uploaded'=>(int)($t['uploaded']??0),'ratio'=>(float)($t['ratio']??0),
            'seed_seconds'=>max(0,(int)($t['seeding_time']??0)),
            'last_activity'=>(int)($t['last_activity']??0),
            'storage'=>(string)($t['save_path']??''),
            'labels'=>array_values(array_filter(array_map('trim',explode(',',(string)($t['tags']??''))))),
            'status'=>(string)($t['state']??''),'complete'=>(float)($t['progress']??0),
            'category'=>(string)($t['category']??''),'size'=>(int)($t['size']??0)];
    }
    $rules=(array)($cfg['hr_rules']??[]);
    foreach($rows as &$r){
        $key=$r['client'].':'.$r['hash'];
        $prev=$old[$key]??null;
        $since=null;$note='首次采样，上传停止时长未知';
        if(is_array($prev)){
            $since=(int)($prev['last_upload_seen']??($prev['first_seen']??$now));
            if($r['uploaded']>(int)($prev['uploaded']??0)+$limit){
                $since=$now;$note='本轮发现上传量增长';
            }elseif($r['uploaded']<(int)($prev['uploaded']??0)){
                $since=null;$note='上传计数减少，重新记录';
            }else{$note='本轮未发现上传增长';}
        }
        $new[$key]=['uploaded'=>$r['uploaded'],'first_seen'=>(int)($prev['first_seen']??$now),
                    'last_upload_seen'=>$since??$now,'observations'=>(int)($prev['observations']??0)+1];
        $r['days']=$since===null?null:($now-$since)/86400;
        $r['note']=$note;
        $sites=[];
        if($r['category']!=='')$sites[]=$r['category'];
        $sites=array_merge($sites,$r['labels']);
        $sites=array_values(array_unique($sites));
        $r['site']='未识别';$r['hr']='规则未配置';
        $matched=array_values(array_filter(array_keys($rules),fn($name)=>in_array($name,$sites,true)));
        if(count($matched)>1)$r['hr']='多个站点匹配，待确认';
        elseif(count($matched)===1){$r['site']=$matched[0];$r['hr']='等待合计判断';}
    }
    unset($r);

    // For the same site + identical Info Hash, combine the counters of both clients.
    // Never add ratio values directly: compute uploaded bytes / total torrent size.
    // Distinct hashes are NOT combined, even if files on disk are shared.
    $groups=[];
    foreach($rows as $r){
        if($r['hash']===''||$r['site']==='未识别')continue;
        $key=$r['site'].'|'.$r['hash'];
        if(!isset($groups[$key]))$groups[$key]=['uploaded'=>0,'seconds'=>0,'sizes'=>[],'clients'=>[],'count'=>0];
        $groups[$key]['uploaded']+=$r['uploaded'];
        $groups[$key]['seconds']+=$r['seed_seconds'];
        if($r['size']>0)$groups[$key]['sizes'][]=$r['size'];
        $groups[$key]['clients'][$r['client']]=true;
        $groups[$key]['count']++;
    }
    foreach($rows as &$r){
        $r['combined_hours']=null;$r['combined_ratio']=null;
        if($r['hash']===''||$r['site']==='未识别')continue;
        $g=$groups[$r['site'].'|'.$r['hash']];
        $r['combined_hours']=$g['seconds']/3600;
        $sizes=array_unique($g['sizes']);
        $r['combined_ratio']=count($sizes)===1 && (float)reset($sizes)>0 ? $g['uploaded']/(float)reset($sizes) : null;
        $rule=$rules[$r['site']]??null;
        if(!is_array($rule))continue;
        $minH=(float)($rule['min_seeding_hours']??0);
        $minR=(float)($rule['min_ratio']??0);
        $logic=(string)($rule['logic']??'all');
        if($minH<=0&&$minR<=0){$r['hr']='规则未配置';continue;}
        if($r['complete']<1){$r['hr']='未完成，待判断';continue;}
        $tests=[];
        if($minH>0)$tests[]=$r['combined_hours']>=$minH;
        if($minR>0&&$r['combined_ratio']!==null)$tests[]=$r['combined_ratio']>=$minR;
        if($minR>0&&$r['combined_ratio']===null){
            $r['hr']='文件大小缺失/不一致，无法估算合并分享率';continue;
        }
        $pass=$logic==='any'?in_array(true,$tests,true):!in_array(false,$tests,true);
        $r['hr']=$pass?'合计本地指标达到阈值（非站点确认）':'合计本地指标未达到阈值';
    }
    unset($r);
    usort($rows,fn($a,$b)=>($b['days']??-1)<=>($a['days']??-1));
    $dir=dirname($stateFile);if(!is_dir($dir))mkdir($dir,0700,true);
    $tmp=tempnam($dir,'.activity-');
    if($tmp===false)throw new RuntimeException('Cannot create state file');
    chmod($tmp,0600);
    file_put_contents($tmp,json_encode($new,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    rename($tmp,$stateFile);
    $csvDir=dirname($csvPath);if(!is_dir($csvDir))mkdir($csvDir,0700,true);
    $out=fopen($csvPath,'wb');
    if(!$out)throw new RuntimeException('Cannot open CSV');
    fwrite($out,"\xEF\xBB\xBF");
    csvline($out,['下载器','站点','种子名称','Hash','未观察到上传天数','本客户端做种小时','本客户端分享率','同站同Hash合计做种小时','同站同Hash合计分享率估算','H&R本地估算','状态说明','最近数据传输时间','累计上传字节','完成度','分类或标签','保存路径']);
    $fmt=function(?int $ts)use($tz){return $ts?((new DateTimeImmutable('@'.$ts))->setTimezone($tz)->format('Y-m-d H:i:s')):'未知';};
    $qualified=0;$unknown=0;
    foreach($rows as $r){
        $days=$r['days'];if($days===null)$unknown++;
        $flag=($days!==null&&$days>=$threshold&&$r['complete']>=1);
        if($flag)$qualified++;
        csvline($out,[$r['client'],$r['site'],$r['name'],$r['hash'],$days===null?'未知':round($days,2),round($r['seed_seconds']/3600,2),round($r['ratio'],4),$r['combined_hours']===null?'未知':round($r['combined_hours'],2),$r['combined_ratio']===null?'未知':round($r['combined_ratio'],4),$r['hr'],$r['note'],
            $fmt($r['last_activity']),$r['uploaded'],$r['complete'],implode('|',$r['labels']),$r['storage']]);
        if($flag)echo sprintf("[>= %d 天无观察到上传] %.1f 天 | %s | %s\n",$threshold,$days,$r['client'].' / '.$r['name'],substr($r['hash'],0,12));
    }
    fclose($out);
    printf("qBittorrent: %d, Transmission: %d, 达到 %d 天阈值: %d, 历史不足: %d\n",count($qbt),count($trans),$threshold,$qualified,$unknown);
    echo "CSV 报告: $csvPath\n";
    echo "注意：H&R 是依据手动规则做本地估算，不是站点实际判罚/免 H&R 状态；首次运行只建立上传基线。\n";
} catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}