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
