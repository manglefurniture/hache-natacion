<?php
declare(strict_types=1);

function hache_asistencia_cobertura_guardar(
    PDO $pdo,
    string $sesionId,
    int $esperados,
    int $presentes,
    int $justificadas,
    int $injustificadas,
    string $usuarioId
): void {
    $esperados=max(0,$esperados);
    $presentes=max(0,$presentes);
    $justificadas=max(0,$justificadas);
    $injustificadas=max(0,$injustificadas);
    $marcados=$presentes+$justificadas+$injustificadas;
    if($marcados>$esperados)throw new RuntimeException('La cobertura de asistencia excede el padrón esperado de la sesión.');
    $completa=$esperados>0 && $marcados===$esperados ? 1 : 0;

    $st=$pdo->prepare("INSERT INTO sesion_asistencia_cobertura(
            sesion_id,expected_count,marked_count,present_count,justified_count,unjustified_count,complete,captured_by
        ) VALUES(:s,:e,:m,:p,:j,:n,:c,:u)
        ON DUPLICATE KEY UPDATE
          expected_count=VALUES(expected_count),
          marked_count=VALUES(marked_count),
          present_count=VALUES(present_count),
          justified_count=VALUES(justified_count),
          unjustified_count=VALUES(unjustified_count),
          complete=VALUES(complete),
          captured_by=VALUES(captured_by),
          captured_at=NOW()");
    $st->execute([
        ':s'=>$sesionId,
        ':e'=>$esperados,
        ':m'=>$marcados,
        ':p'=>$presentes,
        ':j'=>$justificadas,
        ':n'=>$injustificadas,
        ':c'=>$completa,
        ':u'=>$usuarioId,
    ]);
}
