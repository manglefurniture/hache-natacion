<?php

declare(strict_types=1);

function hache_admin_bool(mixed $value): bool
{
    return in_array($value,[true,1,'1','true','on','yes'],true);
}

function hache_admin_historical_note(string $reason,string $extra=''): string
{
    $reason=preg_replace('/\s+/u',' ',trim($reason))??'';
    $extra=preg_replace('/\s+/u',' ',trim($extra))??'';
    $parts=['Corrección histórica administrativa: '.$reason];
    if($extra!=='')$parts[]=$extra;
    return implode(' · ',$parts);
}

function hache_admin_append_relation_observation(?string $existing,string $note): string
{
    $existing=trim((string)$existing);
    $note=trim($note);
    if($existing==='')return $note;
    if($note==='')return $existing;
    return $existing."\n".$note;
}

function hache_admin_history(PDO $pdo,string $studentId,string $type,string $description,string $userId,?string $referenceType=null,?string $referenceId=null): void
{
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $st=$pdo->prepare("INSERT INTO historial(id,alumno_id,tipo,descripcion,usuario_id,referencia_tipo,referencia_id) VALUES(:id,:a,:t,:d,:u,:rt,:ri)");
    $st->execute([
        ':id'=>$id,
        ':a'=>$studentId,
        ':t'=>$type,
        ':d'=>$description,
        ':u'=>$userId,
        ':rt'=>$referenceType,
        ':ri'=>$referenceId,
    ]);
}

function hache_admin_historical_overlap(PDO $pdo,string $studentId,string $siteId,string $courseId,string $from,string $to): ?array
{
    $st=$pdo->prepare("SELECT ci.id,ci.fecha_inicio,ci.fecha_fin FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:a AND ci.sede_id=:s AND ci.id<>:c AND ci.fecha_inicio<=:fin AND ci.fecha_fin>=:ini ORDER BY ci.fecha_inicio LIMIT 1 FOR UPDATE");
    $st->execute([':a'=>$studentId,':s'=>$siteId,':c'=>$courseId,':fin'=>$to,':ini'=>$from]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}
