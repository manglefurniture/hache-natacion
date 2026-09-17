<?php
declare(strict_types=1);

require_once __DIR__.'/dashboard-tiempo.php';
require_once __DIR__.'/sharky-crm-management.php';

const HACHE_INTERNAL_PROSPECT_FOLLOWUP_THRESHOLD_SECONDS = 86400;

function hache_internal_prospect_followup_paused(array $state): bool
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $followup=is_array($commercial['_idle_followup']??null)?$commercial['_idle_followup']:[];
    return in_array((string)($followup['status']??''),['completed_optout','completed_registration'],true);
}

function hache_internal_prospect_followup_due(array $row,array $state,?DateTimeImmutable $now=null): bool
{
    // Si el estado estructurado ya no está disponible no se puede verificar la
    // exclusión de seguimiento pausado. La alerta falla cerrada en ese caso.
    if(!$state)return false;
    if(($row['gestion_disponible']??false)!==true)return false;
    if(!in_array((string)($row['gestion_estado']??''),['SIN_GESTION','ACTIVIDAD_POSTERIOR'],true))return false;
    if(hache_internal_prospect_followup_paused($state))return false;

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
 * no descifra nombres/teléfonos, no escribe CRM y no dispara mensajes.
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
    $latest=hache_sharky_crm_latest_managements($pdo,$hashes);
    $inbound=hache_sharky_crm_bulk_contact_times($pdo,$scope,$params,'sharky_message_receipts','received_at');
    $outbound=hache_sharky_crm_bulk_contact_times($pdo,$scope,$params,'sharky_outbox','sent_at',"status='SENT'");
    $inboundCounts=hache_sharky_crm_bulk_activity_counts($pdo,$scope,$params,'sharky_message_receipts');
    $outboundCounts=hache_sharky_crm_bulk_activity_counts($pdo,$scope,$params,'sharky_outbox',"status='SENT'");
    $now=hache_instante_operativo($now);
    $out=[];

    foreach($contacts as $contact){
        $hash=(string)$contact['contact_hash'];
        $state=$states[$hash]??[];
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
        ];
        if(!hache_internal_prospect_followup_due($ruleRow,is_array($state)?$state:[],$now))continue;

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
