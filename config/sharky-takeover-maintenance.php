<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-runtime.php';

const HACHE_SHARKY_TAKEOVER_TIMEZONE='America/Cancun';

function hache_sharky_takeover_local_date(array $takeover): ?string
{
    $raw=trim((string)($takeover['activated_at']??''));
    if($raw==='')return null;
    try{
        $date=new DateTimeImmutable($raw);
        return $date->setTimezone(new DateTimeZone(HACHE_SHARKY_TAKEOVER_TIMEZONE))->format('Y-m-d');
    }catch(Throwable $e){
        return null;
    }
}

function hache_sharky_takeover_is_stale_for_date(array $takeover,string $today): bool
{
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$today)!==1)return false;
    $activatedDate=hache_sharky_takeover_local_date($takeover);
    // Legacy/corrupt markers have no trustworthy activation date. They must not
    // survive indefinitely, so the daily reset treats them as stale.
    return $activatedDate===null||$activatedDate<$today;
}

function hache_sharky_takeover_midnight_marker_path(): string
{
    $dir=hache_sharky_writable_dir('maintenance');
    return $dir===''?'':$dir.'/takeover-midnight-release.date';
}

/**
 * Idempotent daily maintenance. It is safe to call every minute from the
 * existing Sharky inbox timer. Only takeovers activated before the current
 * Cancun calendar day are released, so a takeover created just after midnight
 * is preserved until the following midnight.
 *
 * @return array{ok:bool,date:string,previous_date:?string,released:int,kept_today:int,failed:int,initialized:bool}
 */
function hache_sharky_takeover_midnight_tick(?DateTimeImmutable $now=null): array
{
    $tz=new DateTimeZone(HACHE_SHARKY_TAKEOVER_TIMEZONE);
    $now=$now?->setTimezone($tz)??new DateTimeImmutable('now',$tz);
    $today=$now->format('Y-m-d');
    $path=hache_sharky_takeover_midnight_marker_path();
    $result=['ok'=>false,'date'=>$today,'previous_date'=>null,'released'=>0,'kept_today'=>0,'failed'=>0,'initialized'=>false];
    if($path==='')return $result;

    $handle=@fopen($path,'c+');
    if(!$handle||!flock($handle,LOCK_EX)){
        if(is_resource($handle))fclose($handle);
        return $result;
    }

    try{
        rewind($handle);$previous=trim((string)stream_get_contents($handle));
        $result['previous_date']=$previous!==''?$previous:null;
        if($previous===$today){$result['ok']=true;return $result;}

        foreach(hache_sharky_takeover_list() as $takeover){
            if(!is_array($takeover))continue;
            if(!hache_sharky_takeover_is_stale_for_date($takeover,$today)){
                $result['kept_today']++;
                continue;
            }
            $hash=trim((string)($takeover['contact_hash']??''));
            if($hash!==''&&hache_sharky_takeover_resume_hash($hash))$result['released']++;
            else $result['failed']++;
        }

        // A failed unlink is retried on the next minute; do not advance the
        // marker until every stale takeover was successfully released.
        if($result['failed']>0)return $result;

        ftruncate($handle,0);rewind($handle);
        if(fwrite($handle,$today."\n")===false)return $result;
        fflush($handle);
        $result['initialized']=$previous==='';
        $result['ok']=true;
    }finally{
        flock($handle,LOCK_UN);fclose($handle);
    }

    if($result['released']>0)hache_sharky_metric_increment('takeovers_midnight_released',$result['released']);
    return $result;
}
