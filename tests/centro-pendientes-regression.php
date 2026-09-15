<?php
declare(strict_types=1);

require_once __DIR__.'/../config/centro-pendientes.php';

function pendientes_ok(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function pendientes_function_source(string $name): string
{
    $reflection = new ReflectionFunction($name);
    $lines = file($reflection->getFileName());
    return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
}

// El mismo hecho conserva una identidad estable; una obligación de otro período
// es otro asunto aunque tenga el mismo alumno y tipo.
$septiembre = centro_pendientes_identidad('MENSUALIDAD_REGULAR_SIN_COBERTURA', 'ALUMNO_REGULAR', 'alumno-1', '2026-09-01', '2026-09-30');
$septiembreOtraConsulta = centro_pendientes_identidad('MENSUALIDAD_REGULAR_SIN_COBERTURA', 'ALUMNO_REGULAR', 'alumno-1', '2026-09-01', '2026-09-30');
$octubre = centro_pendientes_identidad('MENSUALIDAD_REGULAR_SIN_COBERTURA', 'ALUMNO_REGULAR', 'alumno-1', '2026-10-01', '2026-10-31');
pendientes_ok($septiembre === $septiembreOtraConsulta, 'La misma causa no debe cambiar de identidad al recargar');
pendientes_ok($septiembre !== $octubre, 'La obligación de otro período debe conservar identidad propia');
$sinDuplicados = centro_pendientes_indizar([
    ['identidad'=>$septiembre, 'tipo'=>'MENSUALIDAD_REGULAR_SIN_COBERTURA'],
    ['identidad'=>$septiembre, 'tipo'=>'MENSUALIDAD_REGULAR_SIN_COBERTURA'],
]);
pendientes_ok(count($sinDuplicados) === 1, 'La misma causa consultada dos veces no debe duplicarse');

// Atender no toca la causa y conserva responsable, fecha y nota.
$causa = ['identidad'=>$septiembre, 'tipo'=>'MENSUALIDAD_REGULAR_SIN_COBERTURA', 'origen_id'=>'alumno-1'];
$copiaCausa = $causa;
$atencion = centro_pendientes_gestion_atendida($causa, ['id'=>'admin-1','usuario'=>'admin'], 'Se revisó el comprobante.', '2026-09-15 10:30:00');
pendientes_ok($causa === $copiaCausa, 'Atender no debe modificar el registro o causa original');
pendientes_ok($atencion['estado'] === 'ATENDIDO', 'Atender debe guardar el estado de gestión');
pendientes_ok($atencion['atendido_por'] === 'admin-1' && $atencion['atendido_por_nombre'] === 'admin', 'Atender debe conservar al responsable');
pendientes_ok($atencion['atendido_at'] === '2026-09-15 10:30:00', 'Atender debe conservar la fecha');

// Una resolución solo es coherente cuando la fuente confirma que ya no aplica.
pendientes_ok(!centro_pendientes_puede_resolver(true), 'No puede resolverse mientras la causa original siga vigente');
pendientes_ok(centro_pendientes_puede_resolver(false), 'Puede resolverse cuando la fuente deja de aplicar');
pendientes_ok(centro_pendientes_estado_efectivo($atencion, true) === 'ATENDIDO', 'Atendido no equivale a causa resuelta');

// Un pago posterior deja de hacer aplicable la causa sin crear movimientos; una
// invalidación posterior la vuelve aplicable según el mismo origen.
pendientes_ok(centro_pendientes_estado_efectivo($atencion, false) === 'RESUELTO', 'Un pago posterior debe reflejar la causa cubierta');
pendientes_ok(centro_pendientes_estado_efectivo($atencion, true) === 'ATENDIDO', 'Una invalidación debe volver a hacer aplicable el asunto sin inventar pagos');
pendientes_ok(centro_pendientes_estado_efectivo(['estado'=>'RESUELTO'], true) === 'PENDIENTE', 'Una causa reactivada tras una resolución previa debe volver a requerir atención');

// Ningún pendiente puede escapar de su sede y los tipos que requieren otras
// fases no pueden aparecer por accidente en esta primera versión.
pendientes_ok(centro_pendientes_mismo_alcance(['sede_id'=>'sede-monteverde'], 'sede-monteverde'), 'La sede propia debe poder consultar su pendiente');
pendientes_ok(!centro_pendientes_mismo_alcance(['sede_id'=>'sede-monteverde'], 'sede-palapas'), 'La sede ajena no debe poder consultar ni gestionar el pendiente');
pendientes_ok(CENTRO_PENDIENTES_TIPOS_HABILITADOS === [
    'MENSUALIDAD_REGULAR_SIN_COBERTURA',
    'INSCRIPCION_REGULAR_SIN_COBERTURA',
    'REPOSICION_REGULAR_DISPONIBLE',
], 'Solo los tres tipos con regla existente deben quedar habilitados');

// La carga de fuentes y la rama GET son de lectura: no crean ni modifican pagos,
// asistencias, alumnos, inscripciones o reposiciones al abrir o recargar la vista.
$fuentes = pendientes_function_source('centro_pendientes_fuentes_activas');
pendientes_ok(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $fuentes), 'Consultar las fuentes de pendientes debe ser solo lectura');
$api = file_get_contents(__DIR__.'/../api/pendientes.php');
pendientes_ok(is_string($api), 'La API del centro de pendientes debe existir');
$getStart = strpos($api, 'if ($method === \'GET\')');
$postStart = strpos($api, 'if ($method !== \'POST\')');
pendientes_ok($getStart !== false && $postStart !== false, 'La API debe separar consulta y gestión');
$getBranch = substr($api, $getStart, $postStart - $getStart);
pendientes_ok(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $getBranch), 'Recargar el centro no debe crear registros de gestión ni modificar fuentes');
pendientes_ok(str_contains($api, "auth_require(['ADMIN','VERIFICADOR'])"), 'La consulta debe conservar los roles administrativos existentes');
pendientes_ok(str_contains($api, '($me[\'rol\'] ?? \'\') !== \'ADMIN\''), 'La gestión no debe ampliar permisos de VERIFICADOR');

// La migración debe entrar al release antes de publicar el SHA aprobado; el
// runner queda como única ruta versionada e idempotente para el esquema.
$runner = file_get_contents(__DIR__.'/../bin/migrate-centro-pendientes.php');
$deploy = file_get_contents(__DIR__.'/../ops/production-readiness/deploy-hache-natacion');
pendientes_ok(is_string($runner) && str_contains($runner, 'CENTRO_PENDIENTES_MIGRATION_OK'), 'La migración debe tener un runner CLI versionado');
pendientes_ok(is_string($deploy) && str_contains($deploy, 'bin/migrate-centro-pendientes.php'), 'El deploy debe ejecutar la migración del Centro de pendientes');
$migrationCall = is_string($deploy) ? strpos($deploy, 'php "$centro_pendientes"') : false;
$publishedSha = is_string($deploy) ? strpos($deploy, 'publish_deployed_sha "$deployed"') : false;
pendientes_ok($migrationCall !== false && $publishedSha !== false && $migrationCall < $publishedSha, 'La migración debe completar antes de publicar el SHA desplegado');

fwrite(STDOUT, "CENTRO_PENDIENTES_REGRESSION_OK\n");
