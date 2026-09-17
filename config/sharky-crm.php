<?php
declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-db.php';
require_once __DIR__.'/sharky-contact-book.php';

function hache_sharky_crm_schema_ready(PDO $pdo): bool
{
    static $ready=[];
    $key=spl_object_id($pdo);
    if(($ready[$key]??false)===true)return true;
    try{
        $tables=['sharky_contacts','sharky_message_receipts','sharky_outbox','sharky_action_audit','sharky_referrals','sharky_conversation_state'];
        $quoted="'".implode("','",$tables)."'";
        $st=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($quoted)");
        $found=array_fill_keys(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN)),true);
        foreach($tables as $table)if(!isset($found[$table]))return false;
        return $ready[$key]=true;
    }catch(Throwable $e){return false;}
}

function hache_sharky_crm_state_from_row(?array $row): array
{
    if(!is_array($row))return [];
    if(trim((string)($row['state_ciphertext']??''))!==''){
        try{$decoded=hache_sharky_db_state_decrypt($row);return is_array($decoded)?$decoded:[];}
        catch(Throwable $e){return [];}
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
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $program=in_array(($commercial['program']??null),['intensive','regular'],true);
    $sede=in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true);
    if($program&&$sede)return 'SEDE_CONFIRMADA';
    if($program)return 'PRODUCTO_CONFIRMADO';
    return 'PROSPECTO';
}

function hache_sharky_crm_stage_evidence(string $stage): string
{
    return match($stage){
        'INSCRITO'=>'Alta COMPLETED registrada',
        'INSCRIPCION_INICIADA'=>'Flow de inscripción vigente',
        'SEDE_CONFIRMADA'=>'Producto y sede en estado estructurado',
        'PRODUCTO_CONFIRMADO'=>'Producto en estado estructurado',
        default=>'Contacto sin otro hito comercial verificable',
    };
}

function hache_sharky_crm_product_evidence(array $state,?string $program): ?string
{
    if(!in_array($program,['intensive','regular'],true))return null;
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $choice=(string)($commercial['program_button_choice']??'');
    if(($choice==='learn'&&$program==='intensive')||($choice==='regular'&&$program==='regular'))return 'EXPLICITO';
    if($choice==='regular'&&$program==='intensive')return 'ELEGIBILIDAD';
    return 'ESTRUCTURADO';
}

function hache_sharky_crm_human_product_evidence(?string $evidence): string
{
    return match($evidence){
        'EXPLICITO'=>'Selección explícita del prospecto',
        'ELEGIBILIDAD'=>'Resuelto por regla de elegibilidad',
        'ESTRUCTURADO'=>'Contexto estructurado vigente',
        default=>'Sin evidencia de producto disponible',
    };
}

function hache_sharky_crm_human_source(?string $source): string
{
    return match($source){'meta_ad'=>'Meta Ads','web'=>'Web','direct'=>'WhatsApp directo','referral'=>'Referencia',default=>'Sin fuente persistente'};
}

function hache_sharky_crm_human_program(?string $program): string
{
    return match($program){'intensive'=>'Curso intensivo','regular'=>'Clases regulares',default=>'Sin producto confirmado'};
}

/** @return array{0:string,1:array<string,string>} */
function hache_sharky_crm_hash_scope(array $hashes): array
{
    $params=[];$parts=[];
    foreach(array_values($hashes) as $i=>$hash){$key=':h'.$i;$parts[]=$key;$params[$key]=(string)$hash;}
    return [implode(',',$parts),$params];
}

function hache_sharky_crm_bulk_states(PDO $pdo,string $scope,array $params): array
{
    $st=$pdo->prepare("SELECT contact_hash,state_json,state_ciphertext,state_iv,state_tag FROM sharky_conversation_state WHERE expires_at>=NOW() AND contact_hash IN ($scope)");
    $st->execute($params);$out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[(string)$row['contact_hash']]=hache_sharky_crm_state_from_row($row);
    return $out;
}

function hache_sharky_crm_bulk_referrals(PDO $pdo,string $scope,array $params): array
{
    $st=$pdo->prepare("SELECT contact_hash,source_type,source_id,ctwa_clid,headline,body,captured_at,id FROM sharky_referrals WHERE contact_hash IN ($scope) ORDER BY contact_hash,captured_at DESC,id DESC");
    $st->execute($params);$out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$hash=(string)$row['contact_hash'];if(!isset($out[$hash]))$out[$hash]=$row;}
    return $out;
}

function hache_sharky_crm_bulk_registrations(PDO $pdo,string $scope,array $params): array
{
    // Cualquier alta COMPLETED conserva la conversión aunque exista después otro intento fallido/cancelado.
    $st=$pdo->prepare("SELECT contact_hash,action_type,status,alumno_id,result_json,created_at,completed_at,id FROM sharky_action_audit WHERE contact_hash IN ($scope) AND action_type IN ('register_intensive','register_regular') ORDER BY contact_hash,(status='COMPLETED') DESC,created_at DESC,id DESC");
    $st->execute($params);$out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $hash=(string)$row['contact_hash'];if(isset($out[$hash]))continue;
        $studentId=trim((string)($row['alumno_id']??''));
        if($studentId===''&&($row['status']??'')==='COMPLETED'){
            $result=json_decode((string)($row['result_json']??''),true);
            if(is_array($result))$studentId=trim((string)($result['student_id']??''));
        }
        $row['resolved_alumno_id']=$studentId!==''?$studentId:null;$out[$hash]=$row;
    }
    return $out;
}

function hache_sharky_crm_bulk_contact_times(PDO $pdo,string $scope,array $params,string $table,string $field,string $where=''): array
{
    if(!in_array($table,['sharky_message_receipts','sharky_outbox'],true)||!in_array($field,['received_at','sent_at'],true))return [];
    $sql="SELECT contact_hash,MAX($field) last_at FROM $table WHERE contact_hash IN ($scope)".($where!==''?' AND '.$where:'').' GROUP BY contact_hash';
    $st=$pdo->prepare($sql);$st->execute($params);$out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[(string)$row['contact_hash']]=(string)($row['last_at']??'');
    return $out;
}

function hache_sharky_crm_last_contact(string $inbound,string $outbound,string $fallback): array
{
    $candidates=[];
    if($inbound!=='')$candidates[]=['at'=>$inbound,'direction'=>'ENTRANTE'];
    if($outbound!=='')$candidates[]=['at'=>$outbound,'direction'=>'SALIENTE'];
    if($fallback!=='')$candidates[]=['at'=>$fallback,'direction'=>'VISTO'];
    usort($candidates,fn(array $a,array $b):int=>strcmp($b['at'],$a['at']));
    return $candidates[0]??['at'=>null,'direction'=>null];
}

function hache_sharky_crm_contact_query_match(array $row,string $query): bool
{
    if($query==='')return true;
    $payload=hache_sharky_contact_book_decrypt($row)??[];
    $name=mb_strtolower(trim((string)($payload['base_name']??$payload['managed_name']??'')),'UTF-8');
    $needle=mb_strtolower($query,'UTF-8');
    if($name!==''&&str_contains($name,$needle))return true;
    $digits=preg_replace('/\D+/','',$query)?:'';$phone=preg_replace('/\D+/','',(string)($payload['e164']??''))?:'';
    return $digits!==''&&str_contains($phone,$digits);
}

function hache_sharky_crm_project_rows(PDO $pdo,array $rows): array
{
    if(!$rows)return [];
    $hashes=array_map(static fn(array $row):string=>(string)$row['contact_hash'],$rows);
    [$scope,$params]=hache_sharky_crm_hash_scope($hashes);
    $states=hache_sharky_crm_bulk_states($pdo,$scope,$params);
    $referrals=hache_sharky_crm_bulk_referrals($pdo,$scope,$params);
    $registrations=hache_sharky_crm_bulk_registrations($pdo,$scope,$params);
    $inbound=hache_sharky_crm_bulk_contact_times($pdo,$scope,$params,'sharky_message_receipts','received_at');
    $outbound=hache_sharky_crm_bulk_contact_times($pdo,$scope,$params,'sharky_outbox','sent_at',"status='SENT'");
    $out=[];
    foreach($rows as $row){
        $hash=(string)$row['contact_hash'];$payload=hache_sharky_contact_book_decrypt($row)??[];
        $state=$states[$hash]??[];$referral=$referrals[$hash]??null;$registration=$registrations[$hash]??null;
        $source=hache_sharky_crm_source($state,is_array($referral)?$referral:null);
        $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
        $program=in_array(($commercial['program']??null),['intensive','regular'],true)?(string)$commercial['program']:null;
        $programEvidence=hache_sharky_crm_product_evidence($state,$program);
        $sede=in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true)?(string)$commercial['sede_clave']:null;
        $stage=hache_sharky_crm_stage($state,is_array($registration)?$registration:null);
        $last=hache_sharky_crm_last_contact((string)($inbound[$hash]??''),(string)($outbound[$hash]??''),(string)$row['last_seen_at']);
        $studentId=trim((string)($registration['resolved_alumno_id']??$row['alumno_id']??''));
        $name=trim((string)($payload['base_name']??''));if($name==='')$name=trim((string)($payload['managed_name']??''));
        $out[]=['contact_hash'=>$hash,'nombre'=>$name!==''?$name:'Prospecto sin nombre confirmado','whatsapp'=>(string)($payload['e164']??''),'rol_actual'=>(string)$row['role'],'alumno_id'=>$studentId!==''?$studentId:null,'fuente'=>$source,'fuente_etiqueta'=>hache_sharky_crm_human_source($source),'campana'=>trim((string)($referral['headline']??''))?:null,'producto'=>$program,'producto_etiqueta'=>hache_sharky_crm_human_program($program),'producto_evidencia'=>$programEvidence,'producto_evidencia_etiqueta'=>hache_sharky_crm_human_product_evidence($programEvidence),'sede'=>$sede,'estado_crm'=>$stage,'estado_crm_evidencia'=>hache_sharky_crm_stage_evidence($stage),'registro_estado'=>$registration['status']??null,'registro_tipo'=>$registration['action_type']??null,'primer_contacto'=>(string)$row['first_seen_at'],'ultimo_contacto'=>$last['at'],'ultimo_contacto_tipo'=>$last['direction'],'estado_conversacion_disponible'=>!empty($state)];
    }
    return $out;
}

/** @return array{rows:array,total:int,total_all:int,page:int,per_page:int,pages:int} */
function hache_sharky_crm_page(PDO $pdo,int $page=1,int $perPage=50,string $query=''): array
{
    if(!hache_sharky_crm_schema_ready($pdo))throw new RuntimeException('CRM de Sharky no disponible');
    $page=max(1,$page);$perPage=max(10,min(100,$perPage));$query=mb_substr(trim($query),0,120);
    $where="(c.role='PROSPECT' OR EXISTS(SELECT 1 FROM sharky_action_audit aa WHERE aa.contact_hash=c.contact_hash AND aa.action_type IN ('register_intensive','register_regular')))";
    $totalAll=(int)$pdo->query("SELECT COUNT(*) FROM sharky_contacts c WHERE $where")->fetchColumn();
    $select="SELECT c.contact_hash,c.contact_ciphertext,c.contact_iv,c.contact_tag,c.role,c.alumno_id,c.first_seen_at,c.last_seen_at FROM sharky_contacts c WHERE $where ORDER BY c.last_seen_at DESC";
    if($query===''){
        $total=$totalAll;$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$offset=($page-1)*$perPage;
        $rows=$pdo->query($select.' LIMIT '.$perPage.' OFFSET '.$offset)->fetchAll(PDO::FETCH_ASSOC);
    }else{
        // Nombre y teléfono están cifrados: una búsqueda explícita descifra solo en memoria, nunca crea un índice PII paralelo.
        $matches=[];
        foreach($pdo->query($select)->fetchAll(PDO::FETCH_ASSOC) as $row)if(hache_sharky_crm_contact_query_match($row,$query))$matches[]=$row;
        $total=count($matches);$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$rows=array_slice($matches,($page-1)*$perPage,$perPage);
    }
    return ['rows'=>hache_sharky_crm_project_rows($pdo,$rows),'total'=>$total,'total_all'=>$totalAll,'page'=>$page,'per_page'=>$perPage,'pages'=>$pages];
}
