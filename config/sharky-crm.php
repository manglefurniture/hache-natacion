<?php
declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-db.php';
require_once __DIR__.'/sharky-contact-book.php';

function hache_sharky_crm_schema_ready(PDO $pdo): bool
{
    try{
        foreach(['sharky_contacts','sharky_message_receipts','sharky_outbox','sharky_action_audit','sharky_referrals','sharky_conversation_state'] as $table){
            $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
            $st->execute([':t'=>$table]);
            if((int)$st->fetchColumn()!==1)return false;
        }
        return true;
    }catch(Throwable $e){return false;}
}

function hache_sharky_crm_state_from_row(?array $row): array
{
    if(!is_array($row))return [];
    if(trim((string)($row['state_ciphertext']??''))!==''){
        try{
            $decoded=hache_sharky_db_state_decrypt($row);
            return is_array($decoded)?$decoded:[];
        }catch(Throwable $e){return [];}
    }
    $legacy=trim((string)($row['state_json']??''));
    if($legacy==='')return [];
    $decoded=json_decode($legacy,true);
    return is_array($decoded)?$decoded:[];
}

function hache_sharky_crm_source(array $state,?array $referral): ?string
{
    $source=strtolower(trim((string)($state['commercial_context']['entry_source']??'')));
    if(in_array($source,['meta_ad','web','direct','referral'],true))return $source;
    if(!is_array($referral))return null;
    $sourceType=strtolower(trim((string)($referral['source_type']??'')));
    $ctwa=trim((string)($referral['ctwa_clid']??''));
    if($sourceType==='ad'||$ctwa!=='')return 'meta_ad';
    return 'referral';
}

function hache_sharky_crm_stage(array $state,?array $registration): string
{
    if(is_array($registration)&&($registration['status']??'')==='COMPLETED')return 'INSCRITO';
    $flow=is_array($state['flow']??null)?$state['flow']:[];
    if(in_array((string)($flow['name']??''),['register_intensive','register_regular'],true))return 'INSCRIPCION_INICIADA';
    return 'PROSPECTO';
}

function hache_sharky_crm_human_source(?string $source): string
{
    return match($source){
        'meta_ad'=>'Meta Ads',
        'web'=>'Web',
        'direct'=>'WhatsApp directo',
        'referral'=>'Referencia',
        default=>'Sin fuente persistente',
    };
}

function hache_sharky_crm_human_program(?string $program): string
{
    return match($program){
        'intensive'=>'Curso intensivo',
        'regular'=>'Clases regulares',
        default=>'Sin producto confirmado',
    };
}

function hache_sharky_crm_latest_referral(PDO $pdo,string $hash): ?array
{
    $st=$pdo->prepare('SELECT source_type,source_id,ctwa_clid,headline,body,captured_at FROM sharky_referrals WHERE contact_hash=:c ORDER BY captured_at DESC,id DESC LIMIT 1');
    $st->execute([':c'=>$hash]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function hache_sharky_crm_current_state(PDO $pdo,string $hash): array
{
    $st=$pdo->prepare('SELECT state_json,state_ciphertext,state_iv,state_tag,updated_at,expires_at FROM sharky_conversation_state WHERE contact_hash=:c AND expires_at>=NOW() LIMIT 1');
    $st->execute([':c'=>$hash]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return hache_sharky_crm_state_from_row(is_array($row)?$row:null);
}

function hache_sharky_crm_latest_registration(PDO $pdo,string $hash): ?array
{
    $st=$pdo->prepare("SELECT action_type,status,alumno_id,created_at,completed_at FROM sharky_action_audit WHERE contact_hash=:c AND action_type IN ('register_intensive','register_regular') ORDER BY created_at DESC,id DESC LIMIT 1");
    $st->execute([':c'=>$hash]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function hache_sharky_crm_last_contact(PDO $pdo,string $hash,string $fallback): array
{
    $st=$pdo->prepare('SELECT MAX(received_at) FROM sharky_message_receipts WHERE contact_hash=:c');
    $st->execute([':c'=>$hash]);$inbound=(string)($st->fetchColumn()?:'');
    $st=$pdo->prepare("SELECT MAX(sent_at) FROM sharky_outbox WHERE contact_hash=:c AND status='SENT'");
    $st->execute([':c'=>$hash]);$outbound=(string)($st->fetchColumn()?:'');
    $candidates=[];
    if($inbound!=='')$candidates[]=['at'=>$inbound,'direction'=>'ENTRANTE'];
    if($outbound!=='')$candidates[]=['at'=>$outbound,'direction'=>'SALIENTE'];
    if($fallback!=='')$candidates[]=['at'=>$fallback,'direction'=>'VISTO'];
    usort($candidates,fn(array $a,array $b):int=>strcmp($b['at'],$a['at']));
    return $candidates[0]??['at'=>null,'direction'=>null];
}

function hache_sharky_crm_list(PDO $pdo,int $limit=300): array
{
    if(!hache_sharky_crm_schema_ready($pdo))throw new RuntimeException('CRM de Sharky no disponible');
    $limit=max(1,min(500,$limit));
    $sql="SELECT c.contact_hash,c.contact_ciphertext,c.contact_iv,c.contact_tag,c.role,c.alumno_id,c.first_seen_at,c.last_seen_at
          FROM sharky_contacts c
          WHERE c.role='PROSPECT'
             OR EXISTS(SELECT 1 FROM sharky_action_audit aa WHERE aa.contact_hash=c.contact_hash AND aa.action_type IN ('register_intensive','register_regular'))
          ORDER BY c.last_seen_at DESC
          LIMIT ".$limit;
    $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out=[];
    foreach($rows as $row){
        $hash=(string)$row['contact_hash'];
        $payload=hache_sharky_contact_book_decrypt($row)??[];
        $state=hache_sharky_crm_current_state($pdo,$hash);
        $referral=hache_sharky_crm_latest_referral($pdo,$hash);
        $registration=hache_sharky_crm_latest_registration($pdo,$hash);
        $source=hache_sharky_crm_source($state,$referral);
        $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
        $program=in_array(($commercial['program']??null),['intensive','regular'],true)?(string)$commercial['program']:null;
        $sede=in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true)?(string)$commercial['sede_clave']:null;
        $last=hache_sharky_crm_last_contact($pdo,$hash,(string)$row['last_seen_at']);
        $studentId=trim((string)($registration['alumno_id']??$row['alumno_id']??''));
        $name=trim((string)($payload['base_name']??''));
        if($name==='')$name=trim((string)($payload['managed_name']??''));
        $out[]=[
            'contact_hash'=>$hash,
            'nombre'=>$name!==''?$name:'Prospecto sin nombre confirmado',
            'whatsapp'=>(string)($payload['e164']??''),
            'rol_actual'=>(string)$row['role'],
            'alumno_id'=>$studentId!==''?$studentId:null,
            'fuente'=>$source,
            'fuente_etiqueta'=>hache_sharky_crm_human_source($source),
            'campana'=>trim((string)($referral['headline']??''))?:null,
            'producto'=>$program,
            'producto_etiqueta'=>hache_sharky_crm_human_program($program),
            'sede'=>$sede,
            'estado_crm'=>hache_sharky_crm_stage($state,$registration),
            'registro_estado'=>$registration['status']??null,
            'registro_tipo'=>$registration['action_type']??null,
            'primer_contacto'=>(string)$row['first_seen_at'],
            'ultimo_contacto'=>$last['at'],
            'ultimo_contacto_tipo'=>$last['direction'],
            'estado_conversacion_disponible'=>!empty($state),
        ];
    }
    return $out;
}
