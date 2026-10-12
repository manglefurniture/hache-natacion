<?php
declare(strict_types=1);

const HACHE_SHARKY_WA_GLOBAL_KEY = 'sharky_whatsapp_global_takeover';
const HACHE_SHARKY_WA_GLOBAL_LOCK = 'hache_sharky_whatsapp_global_takeover';

function hache_sharky_wa_global_state(PDO $pdo): array
{
    $st=$pdo->prepare('SELECT valor,updated_by,updated_at FROM configuracion WHERE clave=? LIMIT 1');
    $st->execute([HACHE_SHARKY_WA_GLOBAL_KEY]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))return ['active'=>false,'updated_by'=>null,'updated_at'=>null];
    if(!in_array((string)$row['valor'],['0','1'],true))throw new RuntimeException('Invalid takeover config');
    return ['active'=>(string)$row['valor']==='1','updated_by'=>$row['updated_by'],'updated_at'=>$row['updated_at']];
}

function hache_sharky_wa_global_active(PDO $pdo): bool
{
    return hache_sharky_wa_global_state($pdo)['active']===true;
}

function hache_sharky_wa_global_operational(array $payload): bool
{
    if(is_array($payload['_sharky_payment_reminder']??null)&&($payload['type']??'')==='text')return true;
    if(($payload['type']??'')!=='template')return false;
    return in_array((string)($payload['template']['name']??''),[
        'hache_pago_confirmado','hache_registro_recibido_portal_mx','hache_inicio_curso',
        'hache_clase_cancelada','hache_reposicion_confirmada','hache_cambio_horario'
    ],true);
}

function hache_sharky_wa_global_lock(PDO $pdo,int $timeout=15): bool
{
    $st=$pdo->prepare('SELECT GET_LOCK(?,?)');
    $st->execute([HACHE_SHARKY_WA_GLOBAL_LOCK,$timeout]);
    return (int)$st->fetchColumn()===1;
}
function hache_sharky_wa_global_unlock(PDO $pdo): void
{
    try{$st=$pdo->prepare('SELECT RELEASE_LOCK(?)');$st->execute([HACHE_SHARKY_WA_GLOBAL_LOCK]);}
    catch(Throwable $e){error_log('[sharky-wa-global] failed to release lock');}
}

function hache_sharky_wa_global_cancel_pending(PDO $pdo): int
{
    $cursor='';$total=0;
    $select=$pdo->prepare("SELECT id,payload_ciphertext,payload_iv,payload_tag FROM sharky_outbox WHERE status='PENDING' AND id>? ORDER BY id LIMIT 200");
    $cancel=$pdo->prepare("UPDATE sharky_outbox SET status='CANCELLED',lease_until=NULL,owner_token=NULL,last_error='GLOBAL_WHATSAPP_TAKEOVER' WHERE id=? AND status='PENDING'");
    do{
        $select->execute([$cursor]);$rows=$select->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){
            $cursor=(string)$row['id'];
            $payload=hache_sharky_outbox_decrypt($row);
            if(is_array($payload)&&hache_sharky_wa_global_operational($payload))continue;
            $cancel->execute([$cursor]);$total+=$cancel->rowCount();
        }
    }while(count($rows)===200);
    return $total;
}

function hache_sharky_wa_global_set(PDO $pdo,bool $active,string $adminId): array
{
    if(!hache_sharky_wa_global_lock($pdo,20))throw new RuntimeException('Takeover busy');
    try{
        $pdo->beginTransaction();
        try{
            $previous=hache_sharky_wa_global_state($pdo);
            $changed=$previous['active']!==$active;
            if($active){
                $up=$pdo->prepare('INSERT INTO configuracion(clave,valor,descripcion,updated_by,updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE valor=VALUES(valor),updated_by=VALUES(updated_by),updated_at=NOW()');
                $up->execute([HACHE_SHARKY_WA_GLOBAL_KEY,'1','Pausa general de WhatsApp, solo IA',$adminId]);
            }
            $cancelled=0;$discarded=0;
            if($active||$changed){
                $cancelled=hache_sharky_wa_global_cancel_pending($pdo);
                // Human echoes must survive pause changes to retain manual control.
                $st=$pdo->prepare("UPDATE sharky_message_receipts SET processed_at=NOW(),lease_until=NULL,last_error='GLOBAL_WHATSAPP_TAKEOVER' WHERE processed_at IS NULL AND message_type <> 'echo'");
                $st->execute();$discarded=$st->rowCount();
            }
            if(!$active&&$changed){
                $up=$pdo->prepare('INSERT INTO configuracion(clave,valor,descripcion,updated_by,updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE valor=VALUES(valor),updated_by=VALUES(updated_by),updated_at=NOW()');
                $up->execute([HACHE_SHARKY_WA_GLOBAL_KEY,'0','Pausa general de WhatsApp, solo IA',$adminId]);
            }
            if($changed){
                $audit=$pdo->prepare("INSERT INTO auditoria_eventos(usuario_id,usuario_nombre,accion,entidad,detalle,metodo,ruta) VALUES(?,NULL,?,'SHARKY_WHATSAPP',?,'POST','/api/sharky-admin.php')");
                $audit->execute([$adminId,$active?'TAKEOVER_GLOBAL_ACTIVADO':'TAKEOVER_GLOBAL_DESACTIVADO',json_encode(['canal'=>'whatsapp','cancelled'=>$cancelled,'discarded'=>$discarded],JSON_UNESCAPED_UNICODE)]);
            }
            $pdo->commit();
            return ['active'=>$active,'changed'=>$changed,'cancelled'=>$cancelled,'discarded'=>$discarded];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }finally{hache_sharky_wa_global_unlock($pdo);}
}
