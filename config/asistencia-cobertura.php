<?php
declare(strict_types=1);

function hache_asistencia_cobertura_guardar(PDO $pdo,string $sesionId,int $esperados,int $marcados,string $usuarioId): bool
{
    $esperados=max(0,$esperados);
    $marcados=max(0,min($esperados,$marcados));
    $completa=$esperados>0 && $marcados===$esperados ? 1 : 0;

    try{
        $st=$pdo->prepare("INSERT INTO sesion_asistencia_cobertura(sesion_id,expected_count,marked_count,complete,captured_by)
            VALUES(:s,:e,:m,:c,:u)
            ON DUPLICATE KEY UPDATE
              expected_count=VALUES(expected_count),
              marked_count=VALUES(marked_count),
              complete=VALUES(complete),
              captured_by=VALUES(captured_by),
              captured_at=NOW()");
        $st->execute([':s'=>$sesionId,':e'=>$esperados,':m'=>$marcados,':c'=>$completa,':u'=>$usuarioId]);
        return true;
    }catch(Throwable $e){
        error_log('[asistencia-cobertura] '.$e->getMessage());
        return false;
    }
}
