<?php
declare(strict_types=1);
/**
 * Read-only progress observer for Transmission moves.
 * Progress is an estimate from destination allocated disk blocks, not a Transmission RPC percentage.
 * Run: php progress.php [state.json] [--watch] [--interval=3]
 */
$stateFile=$argv[1]??__DIR__.'/state.json';
$watch=in_array('--watch',$argv,true);
$interval=3;
foreach($argv as $arg)if(preg_match('/^--interval=(\d+)$/',$arg,$m))$interval=max(1,min(60,(int)$m[1]));
function fmtBytes(float $bytes): string {return sprintf('%.2f GiB',$bytes/1073741824);}
function safeName(string $name): bool {
    return $name!=='' && !str_starts_with($name,'/') && !str_contains($name,"\\")
        && !preg_match('~(^|/)\.\.(/|$)~',$name);
}
$previous=[];
do {
    if(!is_file($stateFile)){fwrite(STDERR,"Missing state file: $stateFile\n");exit(1);}
    $state=json_decode((string)file_get_contents($stateFile),true);
    if(!is_array($state)){fwrite(STDERR,"Invalid state JSON\n");exit(1);}
    $now=microtime(true);
    if(!$state){echo '['.date('H:i:s')."] No pending migrations.\n";if(!$watch)break;sleep($interval);continue;}
    foreach($state as $hash=>$entry){
        if(!is_array($entry))continue;
        $dest=(string)($entry['destination']??'');
        $files=$entry['files']??[];
        if(!str_starts_with($dest,'/hdd/')||!is_array($files)||!$files){
            echo '['.date('H:i:s')."] ".substr((string)$hash,0,12)." no progress manifest (legacy pending state)\n";
            continue;
        }
        $relative=substr($dest,strlen('/hdd'));
        if(!preg_match('~^/[a-zA-Z0-9_-]+$~',$relative)){
            echo "Unsafe destination; skipped\n";continue;
        }
        $root='/mnt/disk2'.$relative;
        $expected=0;$allocated=0;$visible=0;$found=0;$safe=true;
        foreach($files as $f){
            $name=(string)($f['name']??'');$length=(int)($f['length']??-1);
            if(!safeName($name)||$length<0){$safe=false;break;}
            $expected+=$length;
            $path=$root.'/'.$name;
            // Do not follow symlinks or read file contents.
            $parts=explode('/',$name);$cursor=$root;
            foreach($parts as $part){$cursor.='/'.$part;if(is_link($cursor)){$safe=false;break;}}
            if(!$safe)break;
            if(!is_file($path))continue;
            $st=@stat($path);if($st===false)continue;
            $found++;
            $visible+=min($length,(int)$st['size']);
            $allocated+=min($length,(int)$st['blocks']*512);
        }
        if(!$safe||$expected<=0){echo "Unsafe manifest; skipped\n";continue;}
        // Allocated blocks are more useful than logical file length for sparse/preallocated files.
        $observed=min($expected,$allocated);
        $pct=100*$observed/$expected;
        $old=$previous[$hash]??null;
        $speed=($old!==null&&$now>$old['at'])?max(0,($observed-$old['bytes'])/($now-$old['at'])):0;
        $previous[$hash]=['at'=>$now,'bytes'=>$observed];
        $eta=$speed>0?gmdate('H:i:s',(int)ceil(($expected-$observed)/$speed)):'--';
        printf("[%s] %s | ~%.1f%% | %s / %s allocated | %.1f MiB/s | ETA %s | files %d/%d | %s\n",
            date('H:i:s'),substr((string)$hash,0,12),$pct,fmtBytes($observed),fmtBytes($expected),
            $speed/1048576,$eta,$found,count($files),
            !empty($entry['verify_requested'])?'verification requested':'moving/pending');
    }
    if(!$watch)break;
    sleep($interval);
} while(true);
