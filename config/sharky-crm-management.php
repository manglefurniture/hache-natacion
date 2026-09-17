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

/** @return array{contact_hash:string,last_contact_at:string,last_contact_type:string} */
function hache_sharky_crm_management_snapshot(PDO $pdo,string $contactHash): array
{
    $contactHash=strtolower(trim($contactHash));
    if(!preg_match('/^[a-f0-9]{64}$/',$contactHash))throw new InvalidArgumentException('Contacto inválido');

    $sql="SELECT c.contact_hash,c.last_seen_at,
        (SELECT MAX(r.received_at) FROM sharky_message_receipts r WHERE r.contact_hash=:receipt_contact) inbound_at,
        (SELECT MAX(o.sent_at) FROM sharky_outbox o WHERE o.contact_hash=:outbox_contact AND o.status='SENT') outbound_at
        FROM sharky_contacts c
        WHERE c.contact_hash=:contact
          AND (c.role='PROSPECT' OR EXISTS(
            SELECT 1 FROM sharky_action_audit aa
            WHERE aa.contact_hash=:audit_contact
              AND aa.action_type IN ('register_intensive','register_regular')
              AND aa.status='COMPLETED'
          ))
        LIMIT 1";
    $st=$pdo->prepare($sql);
    $st->execute([
        ':contact'=>$contactHash,
        ':receipt_contact'=>$contactHash,
        ':outbox_contact'=>$contactHash,
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
    ];
}

/** @return array{id:string,contact_hash:string,admin_user_id:string,managed_at:string,observed_last_contact_at:string} */
function hache_sharky_crm_record_management(PDO $pdo,string $contactHash,string $adminUserId): array
{
    if(!hache_sharky_crm_management_schema_ready($pdo))throw new RuntimeException('Registro de gestión CRM no disponible');
    $adminUserId=trim($adminUserId);
    if($adminUserId===''||mb_strlen($adminUserId)>36)throw new InvalidArgumentException('Usuario ADMIN inválido');

    $snapshot=hache_sharky_crm_management_snapshot($pdo,$contactHash);
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    if($id==='')throw new RuntimeException('No se pudo generar el identificador de la gestión');

    $st=$pdo->prepare("INSERT INTO sharky_crm_managements(id,contact_hash,admin_user_id,managed_at,observed_last_contact_at)
        VALUES(:id,:contact_hash,:admin_user_id,NOW(),:observed_last_contact_at)");
    $st->execute([
        ':id'=>$id,
        ':contact_hash'=>$snapshot['contact_hash'],
        ':admin_user_id'=>$adminUserId,
        ':observed_last_contact_at'=>$snapshot['last_contact_at'],
    ]);

    $st=$pdo->prepare('SELECT id,contact_hash,admin_user_id,managed_at,observed_last_contact_at FROM sharky_crm_managements WHERE id=:id LIMIT 1');
    $st->execute([':id'=>$id]);
    $saved=$st->fetch(PDO::FETCH_ASSOC);
    if(!is_array($saved))throw new RuntimeException('No se pudo confirmar el registro de la gestión');

    return [
        'id'=>(string)$saved['id'],
        'contact_hash'=>(string)$saved['contact_hash'],
        'admin_user_id'=>(string)$saved['admin_user_id'],
        'managed_at'=>(string)$saved['managed_at'],
        'observed_last_contact_at'=>(string)$saved['observed_last_contact_at'],
    ];
}
