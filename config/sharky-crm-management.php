<?php
declare(strict_types=1);

require_once __DIR__.'/sharky-crm.php';

function hache_sharky_crm_management_schema_ready(PDO $pdo): bool
{
    try {
        $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sharky_crm_managements'");
        return (int)$st->fetchColumn()===1;
    } catch (Throwable $e) {
        return false;
    }
}

function hache_sharky_crm_management_state(?array $management,?string $lastContactAt,?int $currentInboundCount=null,?int $currentOutboundCount=null): string
{
    if(!is_array($management))return 'SIN_GESTION';
    $anchor=trim((string)($management['observed_last_contact_at']??''));
    $last=trim((string)$lastContactAt);
    if($anchor!==''&&$last!==''&&strcmp($last,$anchor)>0)return 'ACTIVIDAD_POSTERIOR';

    $observedInbound=$management['observed_inbound_count']??null;
    $observedOutbound=$management['observed_outbound_count']??null;
    if(is_numeric($observedInbound)&&is_numeric($observedOutbound)&&$currentInboundCount!==null&&$currentOutboundCount!==null){
        if($currentInboundCount>(int)$observedInbound||$currentOutboundCount>(int)$observedOutbound)return 'ACTIVIDAD_POSTERIOR';
        return 'GESTIONADO';
    }

    // Las gestiones creadas antes del ancla monotónica no pueden ordenar dos eventos
    // que compartan el mismo segundo. Se consideran conservadoramente con actividad
    // posterior hasta que un ADMIN vuelva a registrarlas con los contadores actuales.
    if($anchor!==''&&$last!==''&&strcmp($last,$anchor)>=0)return 'ACTIVIDAD_POSTERIOR';
    return 'GESTIONADO';
}

function hache_sharky_crm_management_state_label(string $state): string
{
    return match($state){
        'GESTIONADO'=>'Gestionado',
        'ACTIVIDAD_POSTERIOR'=>'Actividad posterior',
        default=>'Sin gestión',
    };
}

function hache_sharky_crm_bulk_activity_counts(PDO $pdo,string $scope,array $params,string $table,string $where=''): array
{
    if(!in_array($table,['sharky_message_receipts','sharky_outbox'],true))return [];
    $sql="SELECT contact_hash,COUNT(*) activity_count FROM $table WHERE contact_hash IN ($scope)".($where!==''?' AND '.$where:'').' GROUP BY contact_hash';
    $st=$pdo->prepare($sql);
    $st->execute($params);
    $out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[(string)$row['contact_hash']]=(int)($row['activity_count']??0);
    return $out;
}

function hache_sharky_crm_latest_managements(PDO $pdo,array $contactHashes): array
{
    if(!$contactHashes||!hache_sharky_crm_management_schema_ready($pdo))return [];
    [$scope,$params]=hache_sharky_crm_hash_scope(array_values(array_unique(array_map('strval',$contactHashes))));
    if($scope==='')return [];
    $st=$pdo->prepare("SELECT m.contact_hash,m.admin_user_id,m.managed_at,m.observed_last_contact_at,m.observed_inbound_count,m.observed_outbound_count,u.usuario admin_usuario
        FROM sharky_crm_managements m
        LEFT JOIN usuarios u ON u.id=m.admin_user_id
        WHERE m.contact_hash IN ($scope)
        ORDER BY m.contact_hash,m.managed_at DESC,m.id DESC");
    $st->execute($params);
    $out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $hash=(string)($row['contact_hash']??'');
        if($hash!==''&&!isset($out[$hash]))$out[$hash]=$row;
    }
    return $out;
}

function hache_sharky_crm_attach_managements(PDO $pdo,array $rows): array
{
    if(!$rows)return [];
    $available=hache_sharky_crm_management_schema_ready($pdo);
    $hashes=array_map(static fn(array $row):string=>(string)($row['contact_hash']??''),$rows);
    $latest=$available?hache_sharky_crm_latest_managements($pdo,$hashes):[];
    $inboundCounts=[];$outboundCounts=[];
    if($available){
        [$scope,$params]=hache_sharky_crm_hash_scope(array_values(array_unique($hashes)));
        if($scope!==''){
            $inboundCounts=hache_sharky_crm_bulk_activity_counts($pdo,$scope,$params,'sharky_message_receipts');
            $outboundCounts=hache_sharky_crm_bulk_activity_counts($pdo,$scope,$params,'sharky_outbox',"status='SENT'");
        }
    }
    foreach($rows as &$row){
        $hash=(string)($row['contact_hash']??'');
        $management=$latest[$hash]??null;
        if(!$available){
            $row['gestion_disponible']=false;
            $row['gestion_estado']=null;
            $row['gestion_estado_etiqueta']='No disponible';
            $row['gestion_fecha']=null;
            $row['gestion_responsable']=null;
            $row['gestion_ancla']=null;
            continue;
        }
        $state=hache_sharky_crm_management_state(
            is_array($management)?$management:null,
            (string)($row['ultimo_contacto']??''),
            (int)($inboundCounts[$hash]??0),
            (int)($outboundCounts[$hash]??0)
        );
        $row['gestion_disponible']=true;
        $row['gestion_estado']=$state;
        $row['gestion_estado_etiqueta']=hache_sharky_crm_management_state_label($state);
        $row['gestion_fecha']=is_array($management)?((string)($management['managed_at']??'')?:null):null;
        $row['gestion_responsable']=is_array($management)?((string)($management['admin_usuario']??'')?:null):null;
        $row['gestion_ancla']=is_array($management)?((string)($management['observed_last_contact_at']??'')?:null):null;
    }
    unset($row);
    return $rows;
}

/** @return array{contact_hash:string,last_contact_at:string,last_contact_type:string,inbound_count:int,outbound_count:int} */
function hache_sharky_crm_management_snapshot(PDO $pdo,string $contactHash): array
{
    $contactHash=strtolower(trim($contactHash));
    if(!preg_match('/^[a-f0-9]{64}$/',$contactHash))throw new InvalidArgumentException('Contacto inválido');

    $sql="SELECT c.contact_hash,c.last_seen_at,
        (SELECT MAX(r.received_at) FROM sharky_message_receipts r WHERE r.contact_hash=:receipt_contact) inbound_at,
        (SELECT COUNT(*) FROM sharky_message_receipts r WHERE r.contact_hash=:receipt_count_contact) inbound_count,
        (SELECT MAX(o.sent_at) FROM sharky_outbox o WHERE o.contact_hash=:outbox_contact AND o.status='SENT') outbound_at,
        (SELECT COUNT(*) FROM sharky_outbox o WHERE o.contact_hash=:outbox_count_contact AND o.status='SENT') outbound_count
        FROM sharky_contacts c
        WHERE c.contact_hash=:contact
          AND (c.role='PROSPECT' OR EXISTS(
            SELECT 1 FROM sharky_action_audit aa
            WHERE aa.contact_hash=:audit_contact
              AND aa.action_type IN ('register_intensive','register_regular')
          ))
        LIMIT 1";
    $st=$pdo->prepare($sql);
    $st->execute([
        ':contact'=>$contactHash,
        ':receipt_contact'=>$contactHash,
        ':receipt_count_contact'=>$contactHash,
        ':outbox_contact'=>$contactHash,
        ':outbox_count_contact'=>$contactHash,
        ':audit_contact'=>$contactHash,
    ]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new OutOfBoundsException('El contacto no pertenece al CRM de prospectos');

    $last=hache_sharky_crm_last_contact(
        (string)($row['inbound_at']??''),
        (string)($row['outbound_at']??''),
        (string)($row['last_seen_at']??'')
    );
    $lastAt=trim((string)($last['at']??''));
    if($lastAt==='')throw new RuntimeException('No existe un último contacto verificable para registrar la gestión');

    return [
        'contact_hash'=>$contactHash,
        'last_contact_at'=>$lastAt,
        'last_contact_type'=>(string)($last['direction']??''),
        'inbound_count'=>(int)($row['inbound_count']??0),
        'outbound_count'=>(int)($row['outbound_count']??0),
    ];
}

/** @return array{id:string,contact_hash:string,admin_user_id:string,managed_at:string,observed_last_contact_at:string,observed_inbound_count:int,observed_outbound_count:int} */
function hache_sharky_crm_record_management(PDO $pdo,string $contactHash,string $adminUserId): array
{
    if(!hache_sharky_crm_management_schema_ready($pdo))throw new RuntimeException('Registro de gestión CRM no disponible');
    $adminUserId=trim($adminUserId);
    if($adminUserId===''||mb_strlen($adminUserId)>36)throw new InvalidArgumentException('Usuario ADMIN inválido');

    $snapshot=hache_sharky_crm_management_snapshot($pdo,$contactHash);
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    if($id==='')throw new RuntimeException('No se pudo generar el identificador de la gestión');

    $st=$pdo->prepare("INSERT INTO sharky_crm_managements(id,contact_hash,admin_user_id,managed_at,observed_last_contact_at,observed_inbound_count,observed_outbound_count)
        VALUES(:id,:contact_hash,:admin_user_id,NOW(),:observed_last_contact_at,:observed_inbound_count,:observed_outbound_count)");
    $st->execute([
        ':id'=>$id,
        ':contact_hash'=>$snapshot['contact_hash'],
        ':admin_user_id'=>$adminUserId,
        ':observed_last_contact_at'=>$snapshot['last_contact_at'],
        ':observed_inbound_count'=>$snapshot['inbound_count'],
        ':observed_outbound_count'=>$snapshot['outbound_count'],
    ]);

    $st=$pdo->prepare('SELECT id,contact_hash,admin_user_id,managed_at,observed_last_contact_at,observed_inbound_count,observed_outbound_count FROM sharky_crm_managements WHERE id=:id LIMIT 1');
    $st->execute([':id'=>$id]);
    $saved=$st->fetch(PDO::FETCH_ASSOC);
    if(!is_array($saved))throw new RuntimeException('No se pudo confirmar el registro de la gestión');

    return [
        'id'=>(string)$saved['id'],
        'contact_hash'=>(string)$saved['contact_hash'],
        'admin_user_id'=>(string)$saved['admin_user_id'],
        'managed_at'=>(string)$saved['managed_at'],
        'observed_last_contact_at'=>(string)$saved['observed_last_contact_at'],
        'observed_inbound_count'=>(int)$saved['observed_inbound_count'],
        'observed_outbound_count'=>(int)$saved['observed_outbound_count'],
    ];
}
