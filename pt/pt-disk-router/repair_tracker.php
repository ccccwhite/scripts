<?php
declare(strict_types=1);
/**
 * Persistent repair state tracker for manually initiated qBittorrent moves.
 * --enroll HASH [--resume]  register only a STOPPED torrent already at its
 *                           intended target; does not trigger recheck yet.
 * --poll                    advance enrolled jobs (no new moves).
 * --status                  read-only summary.
 * Does NOT move data, delete torrents, or manipulate router's state.json.
 */
date_default_timezone_set('Asia/Shanghai');
function bad(string $m): never { throw new RuntimeException($m); }
function readJ(string $p, bool $optional=false): array {
    if(!is_file($p)){if($optional)return ['jobs'=>[]];bad("Missing $p");}
    $v=json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($v))bad("Bad JSON: $p");return $v;
}
function writeJ(string $p,array $v): void {
    $tmp=$p.'.tmp.'.getmypid();
    $s=json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    if(file_put_contents($tmp,$s,LOCK_EX)===false || !rename($tmp,$p))bad("Cannot save $p");
}
function stopped(string $s):bool{return str_starts_with($s,'stopped')||str_starts_with($s,'paused');}
function checking(string $s):bool{return str_starts_with($s,'checking');}
function active(string $s):bool{return in_array($s,['uploading','stalledUP','queuedUP','forcedUP','downloading','stalledDL','queuedDL','forcedDL','metaDL'],true);}
final class QB {
    private string $url;private string $cookie='';
    function __construct(array $c){
        $this->url=rtrim((string)($c['url']??''),'/');
        if(!preg_match('~^https?://[a-zA-Z0-9.:-]+$~',$this->url))bad('Invalid URL');
        $this->req('auth/login',['username'=>(string)($c['username']??$c['user']??''),'password'=>(string)($c['password']??$c['pass']??'')],true);
        $this->get('torrents/info?filter=all');
    }
    function req(string $ep,?array $fields=null,bool $login=false):string{
        $ch=curl_init($this->url.'/api/v2/'.$ep);if(!$ch)bad('curl init');
        $headers=['Origin: '.$this->url,'Referer: '.$this->url.'/'];
        if($this->cookie!=='')$headers[]='Cookie: '.$this->cookie;
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
            CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_HEADERFUNCTION=>function($ch,string $line):int{
                if(preg_match('/^Set-Cookie:\\s*((?:SID|QBT_SID_[0-9]+)=[^;\\s]+)/i',$line,$m))$this->cookie=$m[1];
                return strlen($line);
            }]);
        if($fields!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($fields));}
        $out=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
        if($out===false||!in_array($status,$login?[200,204]:($fields===null?[200]:[200,204]),true))bad("$ep HTTP $status $err");
        if(trim((string)$out)==='Fails.')bad("$ep rejected");
        if($login&&$status===200&&trim((string)$out)!=='Ok.')bad('Login rejected');
        return (string)$out;
    }
    function get(string $ep):array{$v=json_decode($this->req($ep),true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}
    function post(string $ep,array $f):void{$this->req($ep,$f);}
}
try{
    $base=__DIR__;$cfg=readJ($base.'/config.json');
    $file=$base.'/repair-tracking.json';$lock=fopen($base.'/repair-tracking.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))bad('Tracker locked');
    $state=readJ($file,true);$state['jobs']??=[];
    $mode=$argv[1]??'--status';
    if($mode==='--status'){
        foreach($state['jobs'] as $hash=>$job){
            echo substr($hash,0,12)." ".($job['phase']??'?')." ".($job['destination']??'')." resume=".json_encode($job['resume']??false)." ".($job['error']??'')."\n";
        }
        echo 'tracked='.count($state['jobs'])."\n";exit(0);
    }
    $creds=readJ((string)$cfg['credentials_file']);$q=new QB((array)($creds['qbittorrent']??$creds['qbit']??[]));
    $all=[];
    foreach($q->get('torrents/info?filter=all') as $t)$all[strtolower((string)($t['hash']??''))]=$t;
    if($mode==='--enroll'){
        $hash=strtolower((string)($argv[2]??''));$resume=in_array('--resume',$argv,true);
        if(!preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D',$hash))bad('Usage: --enroll HASH [--resume]');
        if(isset($state['jobs'][$hash]))bad('Already enrolled; inspect --status');
        $t=$all[$hash]??null;if(!$t)bad('Torrent not found');
        $cat=(string)($t['category']??'');
        if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D',$cat))bad('Bad category');
        $tags=array_map('trim',explode(',',(string)($t['tags']??'')));
        if(!in_array('target:ssd',$tags,true))bad('Only explicit target:ssd supported');
        if(count(array_filter($tags,fn($x)=>str_starts_with($x,'target:')))!==1)bad('Ambiguous target');
        $destination=rtrim((string)$cfg['ssd_container_root'],'/').'/qbit/'.$cat;
        if(rtrim((string)($t['save_path']??''),'/')!==$destination)bad('Torrent not at target; move and wait first');
        if(!stopped((string)($t['state']??'')))bad('Stop torrent before enrollment');
        if((bool)($t['auto_tmm']??true))bad('Automatic management enabled');
        if((float)($t['progress']??0)<1.0 || (int)($t['amount_left']??1)!==0)bad('Only 100% completed torrents are enrolled in this verified first release');
        $state['jobs'][$hash]=['phase'=>'ready_to_recheck','destination'=>$destination,'category'=>$cat,
            'resume'=>$resume,'enrolled_at'=>time(),'updated_at'=>time(),'seen_checking'=>false,
            'check_requested_at'=>null,'original_complete'=>(float)($t['progress']??0)>=1.0];
        writeJ($file,$state);echo "ENROLLED $hash; resume=".($resume?'yes':'no')."\n";exit(0);
    }
    if($mode!=='--poll')bad('Modes: --status | --enroll HASH [--resume] | --poll');
    foreach($state['jobs'] as $hash=>&$job){
        $phase=(string)($job['phase']??'');
        if(in_array($phase,['done','failed','verified'],true))continue;
        $t=$all[$hash]??null;
        if(!$t){$job['phase']='failed';$job['error']='Torrent disappeared';}
        elseif(rtrim((string)($t['save_path']??''),'/')!==($job['destination']??'')){
            $job['phase']='failed';$job['error']='Path changed';
        } elseif((string)($t['category']??'')!==($job['category']??'')){
            $job['phase']='failed';$job['error']='Category changed';
        } elseif((bool)($t['auto_tmm']??true)){
            $job['phase']='failed';$job['error']='Automatic management enabled';
        } elseif($phase==='ready_to_recheck'){
            if(!stopped((string)($t['state']??''))){$job['phase']='failed';$job['error']='Torrent started before recheck';}
            else {
                // Persist intention BEFORE asynchronous request: crash never
                // silently issues another check or resumes an unverified task.
                $job['phase']='check_requested';$job['check_requested_at']=time();
                $job['seen_checking']=false;writeJ($file,$state);
                try{
                    $q->post('torrents/recheck',['hashes'=>$hash]);
                    echo substr($hash,0,12)." CHECK REQUESTED; sampling at 0.5s intervals\n";
                    // Observe the asynchronous checking state inside this same
                    // invocation, rather than relying on 30s watcher polling.
                    // No resume from this branch: final verification remains
                    // the job of a subsequent --poll after checking was seen.
                    for($sample=0;$sample<40;$sample++){
                        usleep(500000);
                        $latest=$q->get('torrents/info?filter=all');
                        $current=null;
                        foreach($latest as $item)if(strtolower((string)($item['hash']??''))===$hash){$current=$item;break;}
                        if(!$current)bad('Torrent vanished during check sampling');
                        $currentState=(string)($current['state']??'');
                        if(checking($currentState)){
                            $job['phase']='checking';$job['seen_checking']=true;
                            $job['updated_at']=time();writeJ($file,$state);
                            echo substr($hash,0,12)." CHECKING OBSERVED\n";
                            break;
                        }
                        if(!stopped($currentState))bad('Unexpected state during check sampling: '.$currentState);
                    }
                }catch(Throwable $e){$job['phase']='failed';$job['error']='Recheck start/sampling failed: '.$e->getMessage();}
            }
        } elseif($phase==='check_requested'||$phase==='checking'){
            $s=(string)($t['state']??'');
            if(checking($s)){$job['phase']='checking';$job['seen_checking']=true;}
            elseif(($job['seen_checking']??false)===true){
                // Completion must follow an OBSERVED checking state.
                // For complete torrents require 100% with zero remaining bytes.
                if((float)($t['progress']??0)<1.0 || (int)($t['amount_left']??1)!==0){
                    $job['phase']='failed';$job['error']='Hash verification lost data';
                }elseif(!stopped($s)){
                    $job['phase']='failed';$job['error']='Torrent resumed externally during verification';
                }else {
                    $job['phase']='verified';$job['verified_at']=time();
                    // Only explicitly opted-in tasks may resume, after persist.
                    if(($job['resume']??false)===true){
                        writeJ($file,$state);
                        try{
                            $q->post('torrents/start',['hashes'=>$hash]);
                            $job['phase']='done';$job['resumed_at']=time();
                        }catch(Throwable $e){$job['phase']='failed';$job['error']='Verified but resume failed: '.$e->getMessage();}
                    }
                }
            }elseif(time()-(int)($job['check_requested_at']??0)>120){
                $job['phase']='failed';$job['error']='Checking state never observed; manual review';
            }
        } else {$job['phase']='failed';$job['error']='Unknown phase';}
        $job['updated_at']=time();
        echo substr($hash,0,12).' '.$job['phase'].' '.($job['error']??'')."\n";
        writeJ($file,$state);
    }unset($job);
}catch(Throwable $e){fwrite(STDERR,'TRACKER ERROR: '.$e->getMessage()."\n");exit(1);}
