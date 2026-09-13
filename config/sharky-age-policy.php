<?php

declare(strict_types=1);

/**
 * Central age authority for Sharky enrollment paths.
 *
 * sharky_edad_minima already belongs to the normal Sharky configuration set.
 * sharky_edad_maxima is read directly from configuracion so this guard remains
 * effective while the admin/default surface is being migrated. Invalid or
 * missing values fail closed to the approved 12–65 policy.
 */
function hache_sharky_age_policy(PDO $pdo,array $business=[]): array
{
    $min=is_numeric($business['sharky_edad_minima']??null)?(int)$business['sharky_edad_minima']:12;
    $max=is_numeric($business['sharky_edad_maxima']??null)?(int)$business['sharky_edad_maxima']:65;
    $min=max(1,min(99,$min));$max=max(1,min(120,$max));
    try{
        $st=$pdo->query("SELECT clave,valor FROM configuracion WHERE clave IN ('sharky_edad_minima','sharky_edad_maxima')");
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $key=(string)($row['clave']??'');$value=trim((string)($row['valor']??''));
            if(!ctype_digit($value))continue;$n=(int)$value;
            if($key==='sharky_edad_minima'&&$n>=1&&$n<=99)$min=$n;
            if($key==='sharky_edad_maxima'&&$n>=1&&$n<=120)$max=$n;
        }
    }catch(Throwable $e){
        error_log('[sharky-age-policy] configuration read failed');
    }
    if($max<$min)$max=$min;
    return ['min'=>$min,'max'=>$max];
}

function hache_sharky_age_policy_validate(string $birthdate,array $policy,?string $today=null): array
{
    $birth=DateTimeImmutable::createFromFormat('!Y-m-d',$birthdate);
    if(!$birth||$birth->format('Y-m-d')!==$birthdate)throw new HacheSharkyBusinessException('La fecha de nacimiento no es válida.','INVALID_BIRTHDATE');
    $now=new DateTimeImmutable($today?:'today');
    if($birth>$now)throw new HacheSharkyBusinessException('La fecha de nacimiento no es válida.','INVALID_BIRTHDATE');
    $age=$birth->diff($now)->y;$min=max(1,(int)($policy['min']??12));$max=max($min,(int)($policy['max']??65));
    if($age<$min)throw new HacheSharkyBusinessException('La persona no cumple la edad mínima para este servicio.','MIN_AGE');
    if($age>$max)throw new HacheSharkyBusinessException('La persona supera la edad máxima atendida por este servicio.','MAX_AGE');
    return ['birthdate'=>$birthdate,'age'=>$age];
}

/** Apply the same central DOB range immediately before any enrollment Flow is queued. */
function hache_sharky_age_policy_apply_flow_bounds(PDO $pdo,array $payload,?string $today=null): array
{
    if(($payload['type']??'')!=='interactive'||($payload['interactive']['type']??'')!=='flow')return $payload;
    $screen=(string)($payload['interactive']['action']['parameters']['flow_action_payload']['screen']??'');
    if(!in_array($screen,['ENROLLMENT','REGULAR_ENROLLMENT'],true))return $payload;
    if(!is_array($payload['interactive']['action']['parameters']['flow_action_payload']['data']??null))return $payload;
    $policy=hache_sharky_age_policy($pdo);$tz=new DateTimeZone('America/Cancun');
    $todayObj=DateTimeImmutable::createFromFormat('!Y-m-d',$today?:'', $tz);
    if(!$todayObj)$todayObj=new DateTimeImmutable('today',$tz);
    $min=(int)$policy['min'];$max=(int)$policy['max'];
    $payload['interactive']['action']['parameters']['flow_action_payload']['data']['min_birthdate']=$todayObj->modify('-'.($max+1).' years')->modify('+1 day')->format('Y-m-d');
    $payload['interactive']['action']['parameters']['flow_action_payload']['data']['max_birthdate']=$todayObj->modify('-'.$min.' years')->format('Y-m-d');
    return $payload;
}
