<?php
declare(strict_types=1);

require_once __DIR__.'/dashboard-tiempo.php';
require_once __DIR__.'/sharky-crm-management.php';
require_once __DIR__.'/sharky-inbox.php';
require_once __DIR__.'/sharky-followup.php';

const HACHE_INTERNAL_PROSPECT_FOLLOWUP_THRESHOLD_SECONDS = 86400;

function hache_internal_prospect_followup_paused(array $state): bool
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $followup=is_array($commercial['_idle_followup']??null)?$commercial['_idle_followup']:[];
    return in_array((string)($followup['status']??''),['completed_optout','completed_registration'],true);
}

/**
 * Durable fallback for a pause after the short-lived conversation state expires.
 * It mirrors the explicit pause authorities that can produce completed_optout,
 * without changing the conversational or automatic-follow-up behavior.
 */
function hache_internal_prospect_followup_event_paused(array $event): bool
{
    $id=strtolower(trim((string)($event['interactive_id']??'')));
    $text=trim((string)($event['text']??''));
    $normalized=hache_sharky_orchestrator_normalize($text);
    $normalized=preg_replace('/\s+/u',' ',trim($normalized))??trim($normalized);
    $isNowNot=preg_match('/^(?:ahora\s+no|por\s+ahora\s+no|no\s+por\s+ahora|no\s+por\s+el\s+momento|por\s+el\s+momento\s+no|todavia\s+no|aun\s+no)[.! ]*$/u',$normalized)===1;

    if($id==='flow:pause')return true;
    if($id==='flow:no'&&$isNowNot)return true;
    if($id===''&&$isNowNot)return true;
    if(hache_sharky_followup_user_opted_out($text))return true;
    if(str_contains($text,'?')||str_contains($text,'¿'))return false;

    // Brain conversacional tiene algunas pausas explícitas más amplias que el
    // helper legado de follow-up. Se conservan aquí para reconstruir la misma
    // decisión desde el último inbound durable cuando el estado ya expiró.
    if(preg_match('/\b(?:dejame|deje|permiteme)\s+(?:analizar|pensar|revisar|checar)\b/u',$normalized)===1)return true;
    if(preg_match('/\b(?:lo|esto|eso|me\s+lo)\s+voy\s+a\s+(?:analizar|pensar|revisar|checar)\b/u',$normalized)===1)return true;
    return preg_match('/^(?:voy\s+a\s+pensarlo|lo\s+pienso\s+y\s+te\s+(?:digo|aviso|confirmo)|dejame\s+pensarlo)(?:\s+por\s+favor)?[.! ]*$/u',$normalized)===1;
}

/**
 * @param list<string> $hashes
 * @return array<string,array{known:bool,paused:bool,received_at:string}>
 */
function hache_internal_prospect_followup_durable_pause_evidence(PDO $pdo,array $hashes): array
{
    $hashes=array_values(array_unique(array_filter(array_map('strval',$hashes),static fn(string $hash):bool=>$hash!=='')));
    if(!$hashes||!function_exists('hache_sharky_inbox_decrypt'))return [];
    [$scope,$params]=hache_sharky_crm_hash_scope($hashes);
    if($scope==='')return [];

    try{
        // Los receipts `echo` son mensajes salientes del staff reflejados por Meta;
        // nunca deben sustituir el último turno real del prospecto para esta regla.
        $sql="SELECT r.contact_hash,r.message_id,r.received_at,r.payload_ciphertext,r.payload_iv,r.payload_tag
            FROM sharky_message_receipts r
            JOIN (
                SELECT contact_hash,MAX(received_at) latest_at
                FROM sharky_message_receipts
                WHERE contact_hash IN ($scope) AND message_type<>'echo'
                GROUP BY contact_hash
            ) latest ON latest.contact_hash=r.contact_hash AND latest.latest_at=r.received_at
            WHERE r.message_type<>'echo'
            ORDER BY r.contact_hash,r.message_id";
        $st=$pdo->prepare($sql);$st->execute($params);
    }catch(Throwable $e){
        error_log('[alertas] No se pudo leer evidencia durable de pausa: '.$e->getMessage());
        return [];
    }

    $groups=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$groups[(string)$row['contact_hash']][]=$row;
    $out=[];
    foreach($groups as $hash=>$rows){
        $bestEvent=null;$bestRank=PHP_INT_MIN;$bestMessage='';$receivedAt='';$known=true;
        foreach($rows as $row){
            $event=hache_sharky_inbox_decrypt($row);
            if(!is_array($event)){$known=false;break;}
            $arrivalUs=(int)($event['_inbox_arrival_us']??0);
            $timestampMs=(int)($event['timestamp_ms']??0);
            $received=(string)($row['received_at']??'');
            $receivedTs=strtotime($received);
            $rank=$arrivalUs>0?$arrivalUs:($timestampMs>0?$timestampMs*1000:($receivedTs===false?0:$receivedTs*1000000));
            $messageId=(string)($row['message_id']??'');
            if($bestEvent===null||$rank>$bestRank||($rank===$bestRank&&strcmp($messageId,$bestMessage)>0)){
                $bestEvent=$event;$bestRank=$rank;$bestMessage=$messageId;$receivedAt=$received;
            }
        }
        if(!$known||!is_array($bestEvent)){
            $out[$hash]=['known'=>false,'paused'=>false,'received_at'=>''];
            continue;
        }
        $out[$hash]=['known'=>true,'paused'=>hache_internal_prospect_followup_event_paused($bestEvent),'received_at'=>$receivedAt];
    }
    return $out;
}

function hache_internal_prospect_followup_due(array $row,array $state,?DateTimeImmutable $now=null): bool
{
    if(($row['gestion_disponible']??false)!==true)return false;
    if(!in_array((string)($row['gestion_estado']??''),['SIN_GESTION','ACTIVIDAD_POSTERIOR'],true))return false;
    if(($row['seguimiento_pausado']??false)===true)return false;
    if($state){
        if(hache_internal_prospect_followup_paused($state))return false;
    }elseif(($row['pausa_durable_disponible']??false)!==true){
        // Estado expirado + inbound durable ilegible/ausente sigue siendo dato
        // desconocido: no se convierte en una alerta potencialmente falsa.
        return false;
    }

    $last=trim((string)($row['ultimo_contacto']??''));
    if($last==='')return false;
    try{
        $lastAt=new DateTimeImmutable($last,new DateTimeZone('America/Cancun'));
    }catch(Throwable $e){
        return false;
    }
    $now=hache_instante_operativo($now);
    $elapsed=$now->getTimestamp()-$lastAt->getTimestamp();
    return $elapsed>=HACHE_INTERNAL_PROSPECT_FOLLOWUP_THRESHOLD_SECONDS;
}

/**
 * Regla F5 de solo lectura. Devuelve hashes y metadatos operativos mínimos;
 * no escribe CRM ni dispara mensajes. Si el estado efímero ya expiró, descifra
 * solo el último inbound persistido necesario para reconstruir una pausa.
 */
function hache_internal_prospect_followup_candidates(PDO $pdo,?DateTimeImmutable $now=null): array
{
    if(!hache_sharky_crm_schema_ready($pdo)||!hache_sharky_crm_management_schema_ready($pdo))return [];

    $contacts=$pdo->query("SELECT c.contact_hash,c.last_seen_at
        FROM sharky_contacts c
        WHERE c.role='PROSPECT'
          AND NOT EXISTS(
            SELECT 1 FROM sharky_action_audit aa
            WHERE aa.contact_hash=c.contact_hash
              AND aa.action_type IN ('register_intensive','register_regular')
              AND aa.status='COMPLETED'
          )
        ORDER BY c.last_seen_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    if(!$contacts)return [];

    $hashes=array_values(array_unique(array_map(static fn(array $row):string=>(string)$row['contact_hash'],$contacts)));
    [$scope,$params]=hache_sharky_crm_hash_scope($hashes);
    if($scope==='')return [];

    $states=hache_sharky_crm_bulk_states($pdo,$scope,$params);
    $missingStateHashes=array_values(array_filter($hashes,static fn(string $hash):bool=>!isset($states[$hash])||!is_array($states[$hash])||!$states[$hash]));
    $durablePause=hache_internal_prospect_followup_durable_pause_evidence($pdo,$missingStateHashes);
    $latest=hache_sharky_crm_latest_managements($pdo,$hashes);
    $inbound=hache_sharky_crm_bulk_contact_times($pdo,$scope,$params,'sharky_message_receipts','received_at');
    $outbound=hache_sharky_crm_bulk_contact_times($pdo,$scope,$params,'sharky_outbox','sent_at',"status='SENT'");
    $inboundCounts=hache_sharky_crm_bulk_activity_counts($pdo,$scope,$params,'sharky_message_receipts');
    $outboundCounts=hache_sharky_crm_bulk_activity_counts($pdo,$scope,$params,'sharky_outbox',"status='SENT'");
    $now=hache_instante_operativo($now);
    $out=[];

    foreach($contacts as $contact){
        $hash=(string)$contact['contact_hash'];
        $state=is_array($states[$hash]??null)?$states[$hash]:[];
        $pauseEvidence=$durablePause[$hash]??['known'=>false,'paused'=>false,'received_at'=>''];
        $last=hache_sharky_crm_last_contact(
            (string)($inbound[$hash]??''),
            (string)($outbound[$hash]??''),
            (string)($contact['last_seen_at']??'')
        );
        $management=$latest[$hash]??null;
        $managementState=hache_sharky_crm_management_state(
            is_array($management)?$management:null,
            isset($last['at'])?(string)$last['at']:null,
            (int)($inboundCounts[$hash]??0),
            (int)($outboundCounts[$hash]??0)
        );
        $ruleRow=[
            'gestion_disponible'=>true,
            'gestion_estado'=>$managementState,
            'ultimo_contacto'=>$last['at']??null,
            'pausa_durable_disponible'=>$state?true:(($pauseEvidence['known']??false)===true),
            'seguimiento_pausado'=>(($pauseEvidence['paused']??false)===true),
        ];
        if(!hache_internal_prospect_followup_due($ruleRow,$state,$now))continue;

        $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
        $sede=in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true)?(string)$commercial['sede_clave']:null;
        $lastAt=new DateTimeImmutable((string)$last['at'],new DateTimeZone('America/Cancun'));
        $out[]=[
            'contact_hash'=>$hash,
            'ultimo_contacto'=>(string)$last['at'],
            'gestion_estado'=>$managementState,
            'sede'=>$sede,
            'horas_sin_seguimiento'=>(int)floor(max(0,$now->getTimestamp()-$lastAt->getTimestamp())/3600),
        ];
    }
    return $out;
}

function hache_internal_prospect_followup_summary(array $candidates): array
{
    $porSede=['MONTEVERDE'=>0,'PALAPAS'=>0,'SIN_SEDE'=>0];
    foreach($candidates as $candidate){
        $sede=(string)($candidate['sede']??'');
        if(isset($porSede[$sede]))$porSede[$sede]++;
        else $porSede['SIN_SEDE']++;
    }
    return ['total'=>count($candidates),'por_sede'=>$porSede];
}
