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
    $previous=[];
    do {
        clearstatcache();
        if(!is_file($stateFile)&&!$waitState)throw new RuntimeException('Missing state file: '.$stateFile);
        $state=is_file($stateFile)?jsonArr((string)file_get_contents($stateFile)):[];$now=microtime(true);
        if(!$state&&!$quietEmpty)say('No pending migrations.');
        foreach($state as $hash=>$entry){
            try {
                if(!is_array($entry))throw new RuntimeException('Invalid pending state');
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
                $phase=$entry['phase']??(!empty($entry['verify_requested'])?'verifying':'moving/legacy');
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
