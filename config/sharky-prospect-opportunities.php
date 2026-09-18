<?php
declare(strict_types=1);

/**
 * F6 analytics ledger for commercial opportunities.
 *
 * One OPEN row represents one prospective participant/opportunity. The raw
 * WhatsApp number, names and other PII are never copied here. contact_hash is
 * only the technical link to Sharky's durable action audit.
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
    }catch(Throwable $e){return false;}
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

function hache_sharky_prospect_opportunity_sync_open(PDO $pdo,string $contactHash,array $state): bool
{
    if(!hache_sharky_prospect_opportunity_schema_ready($pdo)||strlen($contactHash)!==64)return false;
    $source=hache_sharky_prospect_opportunity_source($state);
    $sede=hache_sharky_prospect_opportunity_sede($state);
    if($source===null&&$sede===null)return true;
    try{
        $st=$pdo->prepare("UPDATE sharky_prospect_opportunities
            SET entry_source=COALESCE(:source,entry_source),
                sede_clave=COALESCE(:sede,sede_clave),
                updated_at=UTC_TIMESTAMP()
            WHERE contact_hash=:contact_hash AND open_slot=1");
        $st->execute([':source'=>$source,':sede'=>$sede,':contact_hash'=>$contactHash]);
        return true;
    }catch(Throwable $e){
        error_log('[sharky-opportunity] open sync failed');
        return false;
    }
}

function hache_sharky_prospect_opportunity_ensure(PDO $pdo,string $contactHash,array $state): ?string
{
    if(($state['identity']['kind']??'unknown')!=='prospect')return null;
    if(!hache_sharky_prospect_opportunity_schema_ready($pdo)||strlen($contactHash)!==64)return null;
    $source=hache_sharky_prospect_opportunity_source($state);
    $sede=hache_sharky_prospect_opportunity_sede($state);
    try{
        $st=$pdo->prepare("INSERT IGNORE INTO sharky_prospect_opportunities
            (id,contact_hash,entry_source,sede_clave,status,open_slot,alumno_id,created_at,converted_at,updated_at)
            VALUES(UUID(),:contact_hash,:source,:sede,'OPEN',1,NULL,UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP())");
        $st->execute([':contact_hash'=>$contactHash,':source'=>$source,':sede'=>$sede]);
        hache_sharky_prospect_opportunity_sync_open($pdo,$contactHash,$state);
        $q=$pdo->prepare("SELECT id FROM sharky_prospect_opportunities WHERE contact_hash=:contact_hash AND open_slot=1 LIMIT 1");
        $q->execute([':contact_hash'=>$contactHash]);
        $id=trim((string)($q->fetchColumn()?:''));
        return $id!==''?$id:null;
    }catch(Throwable $e){
        error_log('[sharky-opportunity] ensure failed');
        return null;
    }
}

function hache_sharky_prospect_opportunity_convert(PDO $pdo,string $contactHash,string $studentId,?string $sedeClave=null): bool
{
    $studentId=trim($studentId);
    $sede=strtoupper(trim((string)$sedeClave));
    if(!in_array($sede,['MONTEVERDE','PALAPAS'],true))$sede=null;
    if($studentId===''||strlen($contactHash)!==64||!hache_sharky_prospect_opportunity_schema_ready($pdo))return false;
    try{
        $st=$pdo->prepare("UPDATE sharky_prospect_opportunities
            SET status='CONVERTED',
                open_slot=NULL,
                alumno_id=:alumno_id,
                sede_clave=COALESCE(:sede,sede_clave),
                converted_at=COALESCE(converted_at,UTC_TIMESTAMP()),
                updated_at=UTC_TIMESTAMP()
            WHERE contact_hash=:contact_hash AND open_slot=1
            ORDER BY created_at DESC,id DESC
            LIMIT 1");
        $st->execute([':alumno_id'=>$studentId,':sede'=>$sede,':contact_hash'=>$contactHash]);
        if($st->rowCount()===1)return true;
        $q=$pdo->prepare("SELECT 1 FROM sharky_prospect_opportunities
            WHERE contact_hash=:contact_hash AND alumno_id=:alumno_id AND status='CONVERTED' LIMIT 1");
        $q->execute([':contact_hash'=>$contactHash,':alumno_id'=>$studentId]);
        return (bool)$q->fetchColumn();
    }catch(Throwable $e){
        error_log('[sharky-opportunity] conversion link failed');
        return false;
    }
}
