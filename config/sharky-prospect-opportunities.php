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

function hache_sharky_prospect_opportunity_state_id(array $state): ?string
{
    $id=strtolower(trim((string)($state['commercial_context']['f6_opportunity_id']??'')));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',$id)===1?$id:null;
}

function hache_sharky_prospect_opportunity_conversion_schema_ready(PDO $pdo): bool
{
    try{
        $st=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sharky_prospect_opportunities' AND column_name='conversion_action_hash'");
        return (int)$st->fetchColumn()===1;
    }catch(Throwable $e){
        return false;
    }
}

/**
 * Link one exact F6 opportunity to one durable Sharky registration action.
 *
 * The audit row is the authority for conversion: only a COMPLETED
 * register_intensive/register_regular action can close an opportunity. The
 * opportunity UUID is mandatory so multiple OPEN opportunities for the same
 * contact are never guessed or collapsed.
 */
function hache_sharky_prospect_opportunity_link_completed_registration(PDO $pdo,string $auditKey,string $opportunityId): bool
{
    $auditKey=strtolower(trim($auditKey));
    $opportunityId=strtolower(trim($opportunityId));
    if(
        preg_match('/^[0-9a-f]{64}$/',$auditKey)!==1
        ||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',$opportunityId)!==1
        ||!hache_sharky_prospect_opportunity_conversion_schema_ready($pdo)
    )return false;

    try{
        $meta=$pdo->prepare("SELECT contact_hash FROM sharky_action_audit
            WHERE idempotency_key=:audit
              AND status='COMPLETED'
              AND action_type IN ('register_intensive','register_regular')
            LIMIT 1");
        $meta->execute([':audit'=>$auditKey]);
        $contactHash=strtolower(trim((string)($meta->fetchColumn()?:'')));
        if(preg_match('/^[0-9a-f]{64}$/',$contactHash)!==1)return false;

        $st=$pdo->prepare("UPDATE sharky_prospect_opportunities
            SET status='CONVERTED',
                conversion_action_hash=:audit,
                closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),
                updated_at=UTC_TIMESTAMP()
            WHERE id=:id
              AND contact_hash=:contact_hash
              AND (
                (status='OPEN' AND conversion_action_hash IS NULL)
                OR (status='CONVERTED' AND conversion_action_hash=:same_audit)
              )");
        $st->execute([
            ':audit'=>$auditKey,
            ':same_audit'=>$auditKey,
            ':id'=>$opportunityId,
            ':contact_hash'=>$contactHash,
        ]);

        $check=$pdo->prepare("SELECT status,conversion_action_hash
            FROM sharky_prospect_opportunities
            WHERE id=:id AND contact_hash=:contact_hash
            LIMIT 1");
        $check->execute([':id'=>$opportunityId,':contact_hash'=>$contactHash]);
        $row=$check->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            &&(string)($row['status']??'')==='CONVERTED'
            &&hash_equals($auditKey,strtolower(trim((string)($row['conversion_action_hash']??''))));
    }catch(Throwable $e){
        error_log('[sharky-opportunity] completed registration link failed');
        return false;
    }
}

/**
 * Exclude exactly one provisional F6 opportunity when durable identity proves
 * that this WhatsApp contact is already an existing student.
 *
 * No legacy/contact-only fallback is allowed here: exclusion changes the
 * denominator, so it requires the UUID preserved in encrypted Sharky state.
 * The student id is evidence only and is deliberately not stored in F6.
 */
function hache_sharky_prospect_opportunity_exclude_durable_student(PDO $pdo,string $contactHash,array $state,array $identityEvidence): bool
{
    $contactHash=strtolower(trim($contactHash));
    $studentId=trim((string)($identityEvidence['student_id']??''));
    $durable=(($identityEvidence['found']??false)===true)||(($identityEvidence['verified']??false)===true);
    $opportunityId=hache_sharky_prospect_opportunity_state_id($state);

    if(!$durable||$studentId==='')return true;
    if($opportunityId===null)return true;
    if(preg_match('/^[0-9a-f]{64}$/',$contactHash)!==1)return false;
    if(!hache_sharky_prospect_opportunity_schema_ready($pdo)){
        throw new RuntimeException('F6 opportunity storage unavailable for durable-student exclusion');
    }

    try{
        $st=$pdo->prepare("UPDATE sharky_prospect_opportunities
            SET status='EXCLUDED',
                closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),
                updated_at=UTC_TIMESTAMP()
            WHERE id=:id
              AND contact_hash=:contact_hash
              AND status='OPEN'
              AND conversion_action_hash IS NULL");
        $st->execute([':id'=>$opportunityId,':contact_hash'=>$contactHash]);

        $check=$pdo->prepare("SELECT status,conversion_action_hash
            FROM sharky_prospect_opportunities
            WHERE id=:id AND contact_hash=:contact_hash
            LIMIT 1");
        $check->execute([':id'=>$opportunityId,':contact_hash'=>$contactHash]);
        $row=$check->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)){
            throw new RuntimeException('Expected F6 opportunity is unavailable for durable-student exclusion');
        }

        $status=(string)($row['status']??'');
        if($status==='EXCLUDED'&&(string)($row['conversion_action_hash']??'')==='')return true;
        if($status==='CONVERTED')return true;

        throw new RuntimeException('Unable to persist durable-student exclusion');
    }catch(RuntimeException $e){
        throw $e;
    }catch(Throwable $e){
        throw new RuntimeException('Unable to persist durable-student exclusion',0,$e);
    }
}

/**
 * Reconcile a durable existing-student identity before any member/commerce
 * router can finish the inbound event without reaching the generic adapter.
 *
 * This function never creates an opportunity. Unknown contacts are a no-op;
 * only durable student evidence can close the exact UUID already in state.
 */
function hache_sharky_prospect_opportunity_reconcile_durable_student(PDO $pdo,array $event,?array $identityEvidence=null): bool
{
    if(trim((string)($event['group_id']??''))!=='')return true;
    if((string)($event['kind']??'')==='echo')return true;

    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';
    if($contact==='')return true;

    if($identityEvidence===null){
        try{$identityEvidence=hache_sharky_business_identity_by_whatsapp($pdo,$contact);}
        catch(Throwable $e){
            error_log('[sharky-opportunity] durable identity lookup failed before routing');
            return false;
        }
    }
    if(($identityEvidence['found']??false)!==true)return true;

    try{
        $state=hache_sharky_db_state_load($pdo,$contact);
        return hache_sharky_prospect_opportunity_exclude_durable_student(
            $pdo,
            hache_sharky_orchestrator_contact_hash($contact),
            $state,
            $identityEvidence
        );
    }catch(Throwable $e){
        error_log('[sharky-opportunity] durable student exclusion failed; receipt remains pending');
        return false;
    }
}

/**
 * Resolve the OPEN opportunity represented by the current encrypted Sharky
 * state. Conversations created before this micro-step may lack the internal id;
 * in that compatibility case we only proceed when exactly one OPEN opportunity
 * exists for the contact, never by guessing between multiple historical rows.
 */
function hache_sharky_prospect_opportunity_resolve_open_id(PDO $pdo,string $contactHash,array $state): ?string
{
    $stateId=hache_sharky_prospect_opportunity_state_id($state);
    if($stateId!==null){
        $st=$pdo->prepare("SELECT id FROM sharky_prospect_opportunities WHERE id=:id AND contact_hash=:contact_hash AND status='OPEN' LIMIT 1");
        $st->execute([':id'=>$stateId,':contact_hash'=>$contactHash]);
        $id=trim((string)($st->fetchColumn()?:''));
        return $id!==''?$id:null;
    }

    $st=$pdo->prepare("SELECT id FROM sharky_prospect_opportunities WHERE contact_hash=:contact_hash AND status='OPEN' ORDER BY opened_at DESC,id DESC LIMIT 2");
    $st->execute([':contact_hash'=>$contactHash]);
    $ids=array_values(array_filter(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN))));
    return count($ids)===1?$ids[0]:null;
}

/**
 * Enrich only the currently represented OPEN opportunity with a venue that was
 * already confirmed by Sharky's structured controls. This never infers a venue,
 * creates an opportunity, changes lifecycle status or rewrites cohort time.
 */
function hache_sharky_prospect_opportunity_enrich_sede(PDO $pdo,string $contactHash,array $state): bool
{
    if(($state['identity']['kind']??'unknown')!=='prospect')return true;
    $contactHash=strtolower(trim($contactHash));
    $sede=hache_sharky_prospect_opportunity_sede($state);
    if($sede===null)return true;
    if(preg_match('/^[0-9a-f]{64}$/',$contactHash)!==1)return false;
    if(!hache_sharky_prospect_opportunity_schema_ready($pdo)){
        throw new RuntimeException('F6 opportunity storage unavailable for structured venue enrichment');
    }

    $stateId=hache_sharky_prospect_opportunity_state_id($state);
    try{
        $id=hache_sharky_prospect_opportunity_resolve_open_id($pdo,$contactHash,$state);
        if($id===null){
            if($stateId!==null){
                throw new RuntimeException('Expected OPEN opportunity is unavailable for structured venue enrichment');
            }
            error_log('[sharky-opportunity] ambiguous legacy OPEN opportunity; venue enrichment skipped without guessing');
            return false;
        }

        $st=$pdo->prepare("UPDATE sharky_prospect_opportunities
            SET sede_clave=:sede,updated_at=UTC_TIMESTAMP()
            WHERE id=:id AND contact_hash=:contact_hash AND status='OPEN'");
        $st->execute([':sede'=>$sede,':id'=>$id,':contact_hash'=>$contactHash]);

        $q=$pdo->prepare("SELECT sede_clave FROM sharky_prospect_opportunities WHERE id=:id AND contact_hash=:contact_hash AND status='OPEN' LIMIT 1");
        $q->execute([':id'=>$id,':contact_hash'=>$contactHash]);
        if(strtoupper(trim((string)($q->fetchColumn()?:'')))!==$sede){
            throw new RuntimeException('Structured venue enrichment was not persisted');
        }
        return true;
    }catch(Throwable $e){
        error_log('[sharky-opportunity] retryable venue enrichment failure');
        throw new RuntimeException('Unable to persist structured opportunity venue',0,$e);
    }
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
    if(($identityBefore['found']??false)===true){
        return hache_sharky_prospect_opportunity_reconcile_durable_student($pdo,$event,$identityBefore);
    }

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

        if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
        $state['commercial_context']['f6_opportunity_id']=$opportunityId;
        hache_sharky_db_state_save($pdo,$contact,$state,86400);
        return true;
    }catch(Throwable $e){
        error_log('[sharky-entry] No se pudo asumir prospecto para contacto no identificado.');
        return false;
    }finally{
        hache_sharky_orchestrator_unlock($deliveryLock);
    }
}
