<?php
declare(strict_types=1);
// SSD-only category path correction. Dry run unless --apply is set.
// Never performs a hash check or a cross-device move.
function failMove(string $m): never { throw new RuntimeException($m); }
function api(string $ep, ?array $form=null): array|string {
    $ch=curl_init('http://127.0.0.1:8080/api/v2/'.$ep);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*']);
    if($form!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($form));}
    $body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($body===false||!in_array($code,[200,204],true)||trim((string)$body)==='Fails.') failMove("API $ep rejected ($code)");
    if($form!==null)return (string)$body;
    $data=json_decode((string)$body,true);if(!is_array($data))failMove("API $ep returned non-JSON");return $data;
}
function torrent(string $hash): array {
    foreach(api('torrents/info?filter=all') as $t)if(strtolower((string)($t['hash']??''))===$hash)return $t;
    failMove('Torrent not found');
}
function stopped(string $state):bool{return str_starts_with($state,'stopped')||str_starts_with($state,'paused');}
function onDisk(string $path):string {
    $real=realpath($path);
    if($real===false)failMove("Missing directory: $path");
    if($real!=='/mnt/download'&&!str_starts_with($real,'/mnt/download/'))failMove('Path outside dedicated SSD');
    return $real;
}
try{
    $hash=strtolower($argv[1]??'');
    $apply=in_array('--apply',$argv,true);
    if(!preg_match('/^[a-f0-9]{40}$/D',$hash))failMove('Usage: php ssd_category_fix.php HASH [--apply]');
    // In this environment /temp is a bind mount of /mnt/download.
    $mounts=json_decode((string)shell_exec("docker inspect qbittorrent --format '{{json .Mounts}}'"),true);
    $mapped=false;
    foreach($mounts??[] as $m)if(($m['Destination']??'')==='/temp'&&($m['Source']??'')==='/mnt/download')$mapped=true;
    if(!$mapped)failMove('Unexpected Docker /temp mapping');
    $t=torrent($hash);
    $cat=(string)($t['category']??'');
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D',$cat))failMove('Invalid category');
    if((bool)($t['auto_tmm']??true))failMove('Auto torrent management enabled');
    $old=rtrim((string)($t['save_path']??''),'/');
    $new='/temp/qbit/'.$cat;
    if($old===$new){echo "ALREADY CORRECT $new\n";exit;}
    if($old!=='/temp'&&!str_starts_with($old,'/temp/qbit/'))failMove('Not an SSD category path');
    $source=onDisk('/mnt/download'.substr($old,5));
    $dest=onDisk('/mnt/download/qbit/'.$cat);
    if(is_link('/mnt/download/qbit')||is_link('/mnt/download/qbit/'.$cat))failMove('Symlink at destination');
    if(stat($source)['dev']!==stat($dest)['dev'])failMove('Cross-filesystem move prohibited');
    $files=api('torrents/files?hash='.$hash);
    if(!$files)failMove('No file manifest');
    $present=0;
    foreach($files as $f){
        $rel=(string)($f['name']??'');
        if($rel===''||str_starts_with($rel,'/')||str_contains($rel,'\\'))failMove('Unsafe file name');
        foreach(explode('/',$rel) as $part)if($part===''||$part==='.'||$part==='..')failMove('Unsafe component');
        if(file_exists($dest.'/'.$rel)||is_link($dest.'/'.$rel))failMove('Destination collision: '.$rel);
        if(!is_file($source.'/'.$rel))failMove('Missing source file: '.$rel);
        $present++;
    }
    $before=(string)($t['state']??'');
    echo "HASH=$hash CATEGORY=$cat FROM=$old TO=$new FILES=$present STATE=$before\n";
    if(!$apply){echo "DRY RUN; use --apply for one torrent\n";exit;}
    $resume=!stopped($before);
    if($resume)api('torrents/stop',['hashes'=>$hash]);
    $deadline=time()+45;
    do{$t=torrent($hash);if(stopped((string)$t['state']))break;usleep(500000);}while(time()<$deadline);
    if(!stopped((string)$t['state']))failMove('Stop timeout; no move requested');
    if(rtrim((string)$t['save_path'],'/')!==$old)failMove('Path changed before move');
    api('torrents/setLocation',['hashes'=>$hash,'location'=>$new]);
    $deadline=time()+120;
    $confirmed=false;
    do{
        usleep(500000);$t=torrent($hash);
        $state=(string)($t['state']??'');
        if(rtrim((string)$t['save_path'],'/')===$new&&$state!=='moving'&&stopped($state)){$confirmed=true;break;}
        if($state==='error'||$state==='missingFiles')break;
    }while(time()<$deadline);
    if(!$confirmed)failMove('Move not confirmed; torrent remains stopped for review');
    foreach($files as $f)if(!is_file($dest.'/'.$f['name']))failMove('Moved file missing; torrent remains stopped');
    if($resume){api('torrents/start',['hashes'=>$hash]);echo "MOVED; original active state restored (start requested)\n";}
    else echo "MOVED; original stopped state preserved\n";
}catch(Throwable $e){fwrite(STDERR,"FIX ERROR: ".$e->getMessage()."\n");exit(1);}
