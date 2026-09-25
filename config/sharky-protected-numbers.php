<?php
declare(strict_types=1);

require_once __DIR__.'/sharky-contact-book.php';

/** Uses the existing contact-book encryption and the same canonical digits for all lookups. */
function hache_sharky_protected_normalize(string $phone): ?array
{
    $phone=trim($phone);
    if($phone===''||!preg_match('/^\+?[0-9().\s-]+$/D',$phone))return null;
    $digits=telefono_digitos($phone);
    if(strlen($digits)===13&&str_starts_with($digits,'521'))$digits='52'.substr($digits,3);
    if(strlen($digits)===10)$digits='52'.$digits;
    if(!telefono_es_e164('+'.$digits))return null;
    return ['digits'=>$digits,'e164'=>'+'.$digits];
}

function hache_sharky_protected_hash(string $phone): ?string
{
    $normalized=hache_sharky_protected_normalize($phone);
    return $normalized===null?null:hache_sharky_orchestrator_contact_hash($normalized['digits']);
}

function hache_sharky_protected_schema_ready(PDO $pdo): bool
{
    static $ready=[];
    $id=spl_object_id($pdo);
    if(($ready[$id]??false)===true)return true;
    try{
        $st=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sharky_protected_numbers'");
        $columns=array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN),true);
        foreach(['contact_hash','payload_ciphertext','payload_iv','payload_tag','created_at'] as $column)if(!isset($columns[$column]))return false;
        return $ready[$id]=true;
    }catch(Throwable $e){return false;}
}

function hache_sharky_is_protected_number(PDO $pdo,string $phone): bool
{
    $hash=hache_sharky_protected_hash($phone);
    if($hash===null)return false;
    if(!hache_sharky_protected_schema_ready($pdo))throw new RuntimeException('Protected numbers migration incomplete');
    // A DB failure must not silently allow an automated response or contact change.
    $st=$pdo->prepare('SELECT 1 FROM sharky_protected_numbers WHERE contact_hash=:h LIMIT 1');
    $st->execute([':h'=>$hash]);
    return (bool)$st->fetchColumn();
}

function hache_sharky_protected_add(PDO $pdo,string $phone,string $label=''): bool
{
    $normalized=hache_sharky_protected_normalize($phone);
    if($normalized===null)throw new InvalidArgumentException('Número inválido');
    $label=hache_sharky_contact_book_clean_name($label);
    $sealed=hache_sharky_contact_book_encrypt(['phone'=>$normalized['e164'],'label'=>$label]);
    $st=$pdo->prepare('INSERT IGNORE INTO sharky_protected_numbers(contact_hash,payload_ciphertext,payload_iv,payload_tag) VALUES(:h,:p,:iv,:tag)');
    $st->execute([':h'=>hache_sharky_protected_hash($phone),':p'=>$sealed['ciphertext'],':iv'=>$sealed['iv'],':tag'=>$sealed['tag']]);
    return $st->rowCount()===1;
}

function hache_sharky_protected_remove(PDO $pdo,string $phone): bool
{
    $hash=hache_sharky_protected_hash($phone);
    if($hash===null)throw new InvalidArgumentException('Número inválido');
    $st=$pdo->prepare('DELETE FROM sharky_protected_numbers WHERE contact_hash=:h');
    $st->execute([':h'=>$hash]);
    return $st->rowCount()===1;
}

function hache_sharky_protected_list(PDO $pdo): array
{
    $rows=$pdo->query('SELECT payload_ciphertext AS contact_ciphertext,payload_iv AS contact_iv,payload_tag AS contact_tag,created_at FROM sharky_protected_numbers ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    $result=[];
    foreach($rows as $row){
        $payload=hache_sharky_contact_book_decrypt($row);
        if(!is_array($payload))throw new RuntimeException('No se pudo leer un número protegido');
        $result[]=['phone'=>$payload['phone'],'label'=>$payload['label'],'created_at'=>$row['created_at']];
    }
    return $result;
}
