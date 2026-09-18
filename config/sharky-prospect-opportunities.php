<?php
declare(strict_types=1);

/**
 * F6 / P-06 durable prospect-opportunity producer.
 *
 * This sidecar stores only hashes and structured attribution. It never stores
 * the raw WhatsApp number, message id, name or message content.
 */
function hache_sharky_prospect_opportunity_schema_ready(PDO $pdo): bool
{
    static $ready=[];
    $key=spl_object_id($pdo);
    if(isset($ready[$key]))return true;
    try{
        $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sharky_prospect_opportunities'");
        if((int)$st->fetchColumn()!==1)return false;
        $ready[$key]=true;
        return true;
    }catch(Throwable $e){
        return false;
    }
}

function hache_sharky_prospect_opportunity_source(array $state): ?string
{
    $source=strtolower(trim((string)($state['commercial_context']['entry_source']??'')));
    return in_array($source,['meta_ad','web','direct','referral'],true)?$source:null;
}

function hache_sharky_prospect_opportunity_sede(array $state): ?string
{
    $sede=strtoupper(trim((string)($state['commercial_context']['sede_clave']??'')));
    return in_array($sede,['MONTEVERDE','PALAPAS'],true)?$sede:null;
}

/**
 * Open exactly the opportunity represented by one first inbound event.
 *
 * The caller owns the "first unmatched prospect turn" decision. Replaying that
 * same event is idempotent through origin_message_hash. A later first turn after
 * conversational state expiry may legitimately open another opportunity for
 * the same contact_hash.
 */
function hache_sharky_prospect_opportunity_open(PDO $pdo,string $contactHash,string $originMessageId,array $state): ?string
{
    if(($state['identity']['kind']??'unknown')!=='prospect')return null;
    $contactHash=strtolower(trim($contactHash));
    $originMessageId=trim($originMessageId);
    if(
        preg_match('/^[0-9a-f]{64}$/',$contactHash)!==1
        ||$originMessageId===''
        ||!hache_sharky_prospect_opportunity_schema_ready($pdo)
    )return null;

    $originHash=hash('sha256',$originMessageId);
    $source=hache_sharky_prospect_opportunity_source($state);
    $sede=hache_sharky_prospect_opportunity_sede($state);

    try{
        $st=$pdo->prepare("INSERT IGNORE INTO sharky_prospect_opportunities
            (id,contact_hash,origin_message_hash,entry_source,sede_clave,status,opened_at,closed_at,updated_at)
            VALUES(UUID(),:contact_hash,:origin_hash,:source,:sede,'OPEN',UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP())");
        $st->execute([
            ':contact_hash'=>$contactHash,
            ':origin_hash'=>$originHash,
            ':source'=>$source,
            ':sede'=>$sede,
        ]);

        $q=$pdo->prepare("SELECT id FROM sharky_prospect_opportunities WHERE origin_message_hash=:origin_hash LIMIT 1");
        $q->execute([':origin_hash'=>$originHash]);
        $id=trim((string)($q->fetchColumn()?:''));
        return $id!==''?$id:null;
    }catch(Throwable $e){
        error_log('[sharky-opportunity] unable to open durable prospect opportunity');
        return null;
    }
}


/**
 * Shared first-turn boundary for live webhook and durable inbox recovery.
 *
 * Returns false only when this turn must remain pending so the durable inbox can
 * retry it. All "not a new unmatched prospect" cases return true.
 */
function hache_sharky_prospect_opportunity_prepare_unmatched(PDO $pdo,array $event,?array $identityBefore=null): bool
{
    if(trim((string)($event['group_id']??''))!=='')return true;
    if((string)($event['kind']??'')==='echo')return true;

    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';
    if($contact==='')return true;

    if($identityBefore===null){
        try{$identityBefore=hache_sharky_business_identity_by_whatsapp($pdo,$contact);}
        catch(Throwable $e){return false;}
    }
    if(($identityBefore['found']??false)===true)return true;

    $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);
    if(!is_resource($deliveryLock)){
        error_log('[sharky-opportunity] unable to acquire first-turn delivery lock');
        return false;
    }

    try{
        $teacher=hache_sharky_member_teacher_by_whatsapp($pdo,$contact);
        if(($teacher['found']??false)===true)return true;

        $state=hache_sharky_db_state_load($pdo,$contact);
        if(($state['identity']['kind']??'unknown')!=='unknown')return true;

        $state['identity']=array_replace(is_array($state['identity']??null)?$state['identity']:[],[
            'kind'=>'prospect',
            'verified'=>false,
            'source'=>'whatsapp_unmatched',
            'student_id'=>null,
            'name'=>null,
            'sede_clave'=>null,
            'status'=>null,
        ]);

        $now=time();
        $referral=hache_sharky_orchestrator_referral($event,$now);
        if($referral)$state=hache_sharky_orchestrator_capture_referral($state,$referral);
        $state=hache_sharky_entry_guided_first_prospect($state,(string)($event['text']??''),$now);
        $state['updated_at']=$now;

        $opportunityId=hache_sharky_prospect_opportunity_open(
            $pdo,
            hache_sharky_orchestrator_contact_hash($contact),
            (string)($event['id']??''),
            $state
        );
        if($opportunityId===null){
            error_log('[sharky-opportunity] first-turn persistence unavailable; receipt remains pending');
            return false;
        }

        hache_sharky_db_state_save($pdo,$contact,$state,86400);
        return true;
    }catch(Throwable $e){
        error_log('[sharky-entry] No se pudo asumir prospecto para contacto no identificado.');
        return false;
    }finally{
        hache_sharky_orchestrator_unlock($deliveryLock);
    }
}
