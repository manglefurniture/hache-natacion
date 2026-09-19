<?php

declare(strict_types=1);

function f9r_expect(bool $condition,string $message): void
{
    if(!$condition){
        fwrite(STDERR,"F9_RESUMEN_DIARIO_FAIL: {$message}\n");
        exit(1);
    }
}

$root=dirname(__DIR__);
$api=file_get_contents($root.'/api/resumen-diario.php')?:'';
$helper=file_get_contents($root.'/config/resumen-diario.php')?:'';

f9r_expect($api!==''&&$helper!=='','No se pudieron leer los archivos F9.1.');
f9r_expect(str_contains($api,"auth_require(['ADMIN','VERIFICADOR'])"),'El endpoint debe conservar ADMIN/VERIFICADOR.');
f9r_expect(str_contains($api,"REQUEST_METHOD']??'GET')!=='GET'"),'El endpoint debe ser GET-only.');
f9r_expect(str_contains($api,"'snapshot'=>false"),'F9.1 debe declarar que no persiste snapshot.');
f9r_expect(str_contains($api,"'tipo_lectura'=>'VIVA_RECONCILIABLE'"),'F9.1 debe declarar lectura viva/reconciliable.');
f9r_expect(str_contains($api,"'pendientes_nuevos'=>["),'El contrato debe exponer explícitamente pendientes nuevos.');
f9r_expect(str_contains($api,"'disponible'=>false")&&str_contains($api,'primera detección'),'Pendientes nuevos deben quedar no disponibles sin autoridad durable.');
f9r_expect(!str_contains($api,"require_once __DIR__.'/sesiones.php'"),'F9 no debe reutilizar el endpoint mutante de sesiones.');
f9r_expect(!str_contains($api,"require_once __DIR__.'/pagos.php'"),'F9 no debe reutilizar el endpoint de pagos con reconciliación.');
f9r_expect(!str_contains($api,'generarSesiones('),'Consultar F9 no debe generar sesiones.');
f9r_expect(!str_contains($api,'regla_reconciliar_sede_una_vez('),'Consultar F9 no debe reconciliar pagos/sede.');

f9r_expect(str_contains($helper,"\$estadoFecha==='PASADO'"),'Clases previstas históricas deben reconocer el límite sin snapshot.');
f9r_expect(str_contains($helper,'No existe snapshot durable de la planificación histórica'),'No se deben reconstruir clases previstas históricas.');
f9r_expect(str_contains($helper,'p.estado'),'Cobros deben conservar estado de pago.');
f9r_expect(str_contains($helper,"\$estado==='VALIDO'"),'Solo pagos VALIDO deben sumar cobro.');
f9r_expect(str_contains($helper,'total_invalidado'),'Pagos invalidados deben permanecer visibles.');
f9r_expect(str_contains($helper,"ae.accion='PAGO_INVALIDADO'"),'Las invalidaciones posteriores deben quedar señaladas por evidencia F8.');
f9r_expect(str_contains($helper,"se.estado='CANCELADA'"),'Incidencias deben distinguir sesiones canceladas.');
f9r_expect(str_contains($helper,"ps.estado='ACTIVA'"),'Sustituciones deben respetar estado explícito.');
f9r_expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\s+(?:INTO\s+)?[a-z_]/i',$helper),'El helper F9.1 no debe contener escrituras SQL.');

fwrite(STDOUT,"F9_RESUMEN_DIARIO_REGRESSION_OK\n");
