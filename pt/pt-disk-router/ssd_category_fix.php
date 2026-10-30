<?php
declare(strict_types=1);
// SSD-only category path correction. Dry run unless --apply is set.
// Never performs a hash check or a cross-device move.
function failMove(string $m): never { throw new RuntimeException($m); }
final class QbitRepair {
    private string $url;
    private string $cookie='';
    public function __construct(array $c) {
        $this->url=rtrim((string)($c['url']??''),'/');
        if(!preg_match('~^https?://[a-zA-Z0-9.:-]+$~',$this->url)) failMove('Invalid qBit URL');
        $r=$this->call('auth/login',[
            'username'=>(string)($c['username']??$c['user']??''),
            'password'=>(string)($c['password']??$c['pass']??'')
        ],true);
        if($r!=='' && $r!=='Ok.') failMove('qBit authentication failed');
    }
    public function call(string $ep,?array $post=null,bool $login=false): string {
        $h=curl_init($this->url.'/api/v2/'.$ep);
        if(!$h) failMove('curl_init failed');
        $headers=['Origin: '.$this->url,'Referer: '.$this->url.'/'];
        if($this->cookie!=='')$headers[]='Cookie: '.$this->cookie;
        curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HEADERFUNCTION=>function($ch,string $line):int{
                if(preg_match('/^Set-Cookie:\\s*((?:SID|QBT_SID_[0-9]+)=[^;\\s]+)/i',$line,$m))
                    $this->cookie=$m[1];
                return strlen($line);
            }]);
        if($post!==null){curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,http_build_query($post));}
        $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);
        $error=curl_error($h);curl_close($h);
        if($body===false||!in_array($status,$login?[200,204]:($post===null?[200]:[200,204]),true))
            failMove("qBit $ep HTTP $status: $error");
        if(trim((string)$body)==='Fails.')failMove("qBit rejected $ep");
        return (string)$body;
    }
    public function get(string $ep): array {
        $v=json_decode($this->call($ep),true,512,JSON_THROW_ON_ERROR);
        return is_array($v)?$v:[];
    }
}
function api(string $ep, ?array $form=null): array|string {
    global $q;
    if(!$q instanceof QbitRepair)failMove('qBit client not initialized');
    return $form===null ? $q->get($ep) : $q->call($ep,$form);
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
    $cfg=json_decode((string)file_get_contents(__DIR__.'/config.json'),true,512,JSON_THROW_ON_ERROR);
    $creds=json_decode((string)file_get_contents((string)$cfg['credentials_file']),true,512,JSON_THROW_ON_ERROR);
    $q=new QbitRepair((array)($creds['qbittorrent']??$creds['qbit']??[]));
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
