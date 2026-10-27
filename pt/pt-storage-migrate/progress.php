<?php
declare(strict_types=1);
/** Read-only allocation observer. Allocated space is NOT copy progress or hash verification. */
define('PT_STORAGE_LIBRARY_ONLY',true);
require __DIR__.'/migrate.php';
function fmtBytes(float $bytes): string {return sprintf('%.2f GiB',$bytes/1073741824);}
$stateFile=null;$configFile=__DIR__.'/config.json';$watch=false;$interval=10;$waitState=false;$quietEmpty=false;
foreach(array_slice($argv,1) as $arg){
    if($arg==='--watch')$watch=true;
    elseif($arg==='--wait-state')$waitState=true;
    elseif($arg==='--quiet-empty')$quietEmpty=true;
    elseif(preg_match('/^--interval=(\d+)$/',$arg,$m))$interval=max(1,min(60,(int)$m[1]));
    elseif(str_starts_with($arg,'--config='))$configFile=substr($arg,9);
    elseif(!str_starts_with($arg,'--')&&$stateFile===null)$stateFile=$arg;
    else {fwrite(STDERR,'Unknown option: '.$arg.PHP_EOL);exit(1);}
}
try {
    $cfg=jsonArr((string)file_get_contents($configFile));
    $stateFile=$stateFile??($cfg['state_file']??dirname($configFile).'/state.json');
    // Verification progress comes from Transmission RPC, never from disk allocation.
    $tr=null;$verifySession='';
    try {
        $creds=jsonArr((string)file_get_contents($cfg['credentials_file']));
        $tr=$creds['transmission']??null;
        if(!is_array($tr)||empty($tr['rpc_url']))$tr=null;
    }catch(Throwable $e){/* Keep the read-only file observer functional. */}
    $previous=[];
    do {
        clearstatcache();
        if(!is_file($stateFile)&&!$waitState)throw new RuntimeException('Missing state file: '.$stateFile);
        $state=is_file($stateFile)?jsonArr((string)file_get_contents($stateFile)):[];$now=microtime(true);
        if(!$state&&!$quietEmpty)say('No pending migrations.');
        $verifyInfo=[];$verifyError=null;
        $verifyHashes=[];
        foreach($state as $hash=>$entry){
            if(is_array($entry)&&(($entry['phase']??'')==='verifying'||!empty($entry['verify_requested'])))
                $verifyHashes[]=(string)$hash;
        }
        if($verifyHashes){
            try {
                if($tr===null)throw new RuntimeException('Transmission credentials unavailable');
                $items=rpc($tr,'torrent_get',[
                    'ids'=>$verifyHashes,
                    'fields'=>['hash_string','status','recheck_progress','error','error_string']
                ],$verifySession)['torrents']??[];
                foreach($items as $item)if(isset($item['hash_string']))
                    $verifyInfo[strtolower((string)$item['hash_string'])]=$item;
            }catch(Throwable $e){$verifyError=$e->getMessage();}
        }
        foreach($state as $hash=>$entry){
            try {
                if(!is_array($entry))throw new RuntimeException('Invalid pending state');
                $phase=$entry['phase']??(!empty($entry['verify_requested'])?'verifying':'moving/legacy');
                if($phase==='verifying'||!empty($entry['verify_requested'])){
                    $info=$verifyInfo[strtolower((string)$hash)]??null;
                    if($verifyError!==null){
                        say(substr((string)$hash,0,12).' | Hash 校验进度暂不可读：'.$verifyError);
                    }elseif($info===null){
                        say(substr((string)$hash,0,12).' | 校验状态未知：Transmission 未返回该任务');
                    }else{
                        $status=(int)($info['status']??-1);
                        $error=(int)($info['error']??0);
                        if($status===2){
                            $progress=$info['recheck_progress']??null;
                            if(is_numeric($progress)){
                                $percent=100*max(0,min(1,(float)$progress));
                                say(sprintf('%s | Hash 校验中 %.1f%% | 尚未完成',substr((string)$hash,0,12),$percent));
                            }else{
                                say(substr((string)$hash,0,12).' | Hash 校验中，百分比暂不可用');
                            }
                        }elseif($status===1){
                            say(substr((string)$hash,0,12).' | Hash 校验排队中');
                        }elseif($error===3){
                            say(substr((string)$hash,0,12).' | Hash 校验/本地数据错误：'.(string)($info['error_string']??'unknown'));
                        }else{
                            say(substr((string)$hash,0,12).' | Hash 校验未运行（Transmission status='.$status.'），等待主脚本确认结果与收尾');
                        }
                    }
                    if(!empty($entry['last_error']))say('Last error: '.$entry['last_error']);
                    continue;
                }
                assertMounted($cfg);$root=destinationRoot($entry,$cfg);
                $files=manifest(['files'=>$entry['files']??[]]);
                $expected=0;$allocated=0;$visible=0;$found=0;
                foreach($files as $name=>$length){
                    $expected+=$length;$path=safeHostPath($root,$name);
                    clearstatcache(true,$path);
                    if(!is_file($path))continue;
                    $st=stat($path);if($st===false)continue;
                    $found++;$visible+=min($length,(int)$st['size']);
                    $allocated+=min($length,(int)$st['blocks']*512);
                }
                $old=$previous[$hash]??null;
                $rate=$old!==null&&$now>$old['at']?max(0,($allocated-$old['bytes'])/($now-$old['at'])):0;
                $previous[$hash]=['at'=>$now,'bytes'=>$allocated];
                printf("[%s] %s | phase %s | allocation ~%.1f%% (%s / %s) | allocation rate %.1f MiB/s | logical %s | files %d/%d | NOT completion\n",
                    date('H:i:s'),substr((string)$hash,0,12),$phase,$expected>0?100*$allocated/$expected:0,
                    fmtBytes($allocated),fmtBytes($expected),$rate/1048576,fmtBytes($visible),$found,count($files));
                if(!empty($entry['last_error']))say('Last error: '.$entry['last_error']);
            }catch(Throwable $e){say(substr((string)$hash,0,12).' observer unavailable: '.$e->getMessage());}
        }
        $previous=array_intersect_key($previous,$state);
        if(!$watch)break;
        sleep($interval);
    } while(true);
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
