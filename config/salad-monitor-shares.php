<?php
declare(strict_types=1);
/**
 * Shares observados por el monitor: NO son shares acreditados por Kryptex.
 * Solo se atribuyen incrementos al mismo grupo/instancia con una única réplica.
 */
function hache_salad_shares_report(int $days=2, ?DateTimeImmutable $now=null): array {
    $days=max(1,min(7,$days));
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $local=$now->setTimezone(new DateTimeZone('America/Cancun'));
    $dir=hache_salad_history_dir();
    $previous=[]; $summary=[]; $latestEnd=0; $latestDelta=0; $measured=0; $ambiguous=0;
    for($day=$days-1;$day>=0;$day--){
        $date=$local->modify("-$day days")->format('Y-m-d');
        $file=$dir.'/'.$date.'.jsonl';
        if(is_link($file)||!is_file($file)) continue;
        if(filesize($file)>5*1024*1024) throw new RuntimeException('Histórico demasiado grande');
        $handle=fopen($file,'rb'); if(!$handle) throw new RuntimeException('No se pudo leer histórico');
        try {
            while(($line=fgets($handle))!==false){
                $event=json_decode($line,true);
                if(!is_array($event)||!is_array($event['groups']??null))continue;
                foreach($event['groups'] as $row){
                    if(!is_array($row))continue;
                    $group=(string)($row['group']??'');
                    $id=(string)($row['instance_id']??'');
                    $at=strtotime((string)($row['at']??'')); 
                    if($group===''||$at===false)continue;
                    if(!isset($summary[$group]))$summary[$group]=['group'=>$group,'gpu'=>(string)($row['gpu']??''),'accepted_observed'=>0,'last_5m'=>null,'measured_intervals'=>0,'ambiguous_samples'=>0,'coverage_minutes'=>0];
                    $s=&$summary[$group];
                    $s['gpu']=(string)($row['gpu']??$s['gpu']);
                    $count=$row['accepted_shares_snapshot']??null;
                    $valid=($row['active']??0)===1 && $id!=='' && !($row['stale']??false) && $row['hashrate_ths']!==null && is_numeric($count);
                    if(!$valid){
                        if(($row['active']??0)>1){$s['ambiguous_samples']++;$ambiguous++;}
                        unset($previous[$group]);unset($s);continue;
                    }
                    $count=(int)$count;
                    if(isset($previous[$group])){
                        $p=$previous[$group];$gap=$at-$p['at'];
                        if($p['id']===$id && $gap>=60 && $gap<=660 && $count>=$p['count']){
                            $delta=$count-$p['count'];
                            $s['accepted_observed']+=$delta;
                            $s['measured_intervals']++;
                            $s['coverage_minutes']+=round($gap/60,2);
                            $measured++;
                            if($at>$latestEnd){$latestEnd=$at;}
                        }
                    }
                    $previous[$group]=['id'=>$id,'at'=>$at,'count'=>$count];
                    unset($s);
                }
            }
        } finally {fclose($handle);}
    }
    // Intervalo global más reciente: solo grupos con una pareja de lecturas válida
    // terminada en los últimos cinco minutos; no es un contador exacto del pool.
    $windowStart=$now->getTimestamp()-300;
    $last=[]; $lastEnd=[];
    foreach($summary as $key=>&$s){$s['last_5m']=null;}unset($s);
    // El total diario conserva exclusivamente incrementos observados. El campo
    // last_5m se calcula en una consulta posterior con muestreo de timestamps.
    return ['days'=>$days,'groups'=>array_values($summary),'measured_intervals'=>$measured,'ambiguous_samples'=>$ambiguous,'note'=>'Incrementos de contadores SRBMiner entre observaciones de una misma instancia; cobertura parcial, no confirmación de Kryptex.'];
}
