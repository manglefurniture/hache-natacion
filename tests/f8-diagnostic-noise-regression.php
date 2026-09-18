<?php

declare(strict_types=1);

function f8_noise_expect(bool $condition,string $message):void
{
    if(!$condition){
        fwrite(STDERR,"F8_DIAGNOSTIC_NOISE_FAIL: {$message}\n");
        exit(1);
    }
}

$db=file_get_contents(__DIR__.'/../config/database.php')?:'';
$api=file_get_contents(__DIR__.'/../api/auditoria-unificada.php')?:'';

$skipPos=strpos($db,'$skipAudit = [');
$skipEnd=$skipPos===false?false:strpos($db,'];',$skipPos);
$registerPos=strpos($db,'register_shutdown_function');
f8_noise_expect($skipPos!==false&&$skipEnd!==false&&$registerPos!==false&&$skipPos<$skipEnd&&$skipEnd<$registerPos,'Debe poder delimitarse $skipAudit antes del shutdown de auditoría genérica.');
$skipBlock=substr($db,$skipPos,$skipEnd-$skipPos);
f8_noise_expect(str_contains($skipBlock,"'/api/diagnostico.php'"),'diagnostico.php debe estar explícitamente dentro de $skipAudit.');

f8_noise_expect(str_contains($api,"(ruta IS NULL OR ruta<>:audit_noise_route)"),'La lectura F8 debe excluir telemetría legacy de diagnóstico.');
f8_noise_expect(str_contains($api,"':audit_noise_route'=>'/api/diagnostico.php'"),'La exclusión debe ser explícita y parametrizada.');
f8_noise_expect(str_contains($api,"'telemetria_excluida'=>['/api/diagnostico.php']"),'La respuesta debe declarar transparentemente la telemetría excluida.');
f8_noise_expect(!str_contains($api,'DELETE FROM auditoria_eventos'),'La corrección no debe borrar historia existente.');

fwrite(STDOUT,"F8_DIAGNOSTIC_NOISE_REGRESSION_OK\n");
