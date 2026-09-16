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

// La obligación de intensivo pertenece al par alumno + curso. Dos alumnos del
// mismo curso no pueden compartir identidad y el enlace conserva ambos datos.
$origenIntensivo = centro_pendientes_origen_intensivo('curso-1', 'alumno-1');
pendientes_ok($origenIntensivo === 'curso-1:alumno-1', 'El origen del saldo intensivo debe conservar curso y alumno');
pendientes_ok(centro_pendientes_parse_origen_intensivo($origenIntensivo) === ['curso_id'=>'curso-1','alumno_id'=>'alumno-1'], 'El origen del saldo intensivo debe poder revalidarse');
pendientes_ok(centro_pendientes_parse_origen_intensivo('invalido') === null, 'Un origen intensivo incompleto no debe revalidarse');
$intensivoAlumno1 = centro_pendientes_identidad('SALDO_INTENSIVO_PENDIENTE', 'CURSO_INTENSIVO_ALUMNO', centro_pendientes_origen_intensivo('curso-1', 'alumno-1'), '2026-09-01', '2026-09-21');
$intensivoAlumno2 = centro_pendientes_identidad('SALDO_INTENSIVO_PENDIENTE', 'CURSO_INTENSIVO_ALUMNO', centro_pendientes_origen_intensivo('curso-1', 'alumno-2'), '2026-09-01', '2026-09-21');
pendientes_ok($intensivoAlumno1 !== $intensivoAlumno2, 'Dos alumnos del mismo intensivo deben conservar pendientes independientes');
pendientes_ok(centro_pendientes_url('SALDO_INTENSIVO_PENDIENTE', 'alumno-1', 'curso-1') === '/pagos.php?alumno_id=alumno-1&tipo=INTENSIVO&curso_intensivo_id=curso-1', 'El saldo intensivo debe abrir directamente el tipo, alumno y curso correctos en pagos');

// Atender no toca la causa y conserva responsable, fecha y nota.
$causa = ['identidad'=>$septiembre, 'tipo'=>'MENSUALIDAD_REGULAR_SIN_COBERTURA', 'origen_id'=>'alumno-1'];
$copiaCausa = $causa;
$atencion = centro_pendientes_gestion_atendida($causa, ['id'=>'admin-1','usuario'=>'admin'], 'Se revisó el comprobante.', '2026-09-15 10:30:00');
pendientes_ok($causa === $copiaCausa, 'Atender no debe modificar el registro o causa original');
pendientes_ok($atencion['estado'] === 'ATENDIDO', 'Atender debe guardar el estado de gestión');
pendientes_ok($atencion['atendido_por'] === 'admin-1' && $atencion['atendido_por_nombre'] === 'admin', 'Atender debe conservar al responsable');
pendientes_ok($atencion['atendido_at'] === '2026-09-15 10:30:00', 'Atender debe conservar la fecha');

// La acción ATENDER solo corresponde a una causa activa cuyo estado efectivo
// sigue siendo PENDIENTE. ATENDIDO y RESUELTO no pueden ofrecerla otra vez.
$vista = file_get_contents(__DIR__.'/../public/pendientes.php');
$condicionAtender = "puedeGestionar&&x.causa_activa&&s==='PENDIENTE'";
pendientes_ok(is_string($vista) && str_contains($vista, "if({$condicionAtender})acciones.push"), 'La vista debe condicionar ATENDER al estado efectivo PENDIENTE');
$puedeMostrarAtender = static fn (string $estadoEfectivo, bool $causaActiva, bool $puedeGestionar): bool => $puedeGestionar && $causaActiva && $estadoEfectivo === 'PENDIENTE';
pendientes_ok($puedeMostrarAtender('PENDIENTE', true, true), 'Un caso PENDIENTE con causa activa puede mostrar la acción ATENDER');
pendientes_ok(!$puedeMostrarAtender('ATENDIDO', true, true), 'Un caso ATENDIDO no puede volver a mostrar la acción ATENDER');
pendientes_ok(!$puedeMostrarAtender('RESUELTO', true, true), 'Un caso RESUELTO no puede mostrar la acción ATENDER');
pendientes_ok(is_string($vista) && !str_contains($vista, 'no incluye saldos de intensivos'), 'La vista no debe seguir declarando diferidos los saldos de intensivos');

// Una resolución solo es coherente cuando la fuente confirma que ya no aplica.
pendientes_ok(!centro_pendientes_puede_resolver(true), 'No puede resolverse mientras la causa original siga vigente');
pendientes_ok(centro_pendientes_puede_resolver(false), 'Puede resolverse cuando la fuente deja de aplicar');
pendientes_ok(centro_pendientes_estado_efectivo($atencion, true) === 'ATENDIDO', 'Atendido no equivale a causa resuelta');

// Un pago posterior deja de hacer aplicable la causa sin crear movimientos; una
// invalidación posterior la vuelve aplicable según el mismo origen.
pendientes_ok(centro_pendientes_estado_efectivo($atencion, false) === 'RESUELTO', 'Un pago posterior debe reflejar la causa cubierta');
pendientes_ok(centro_pendientes_estado_efectivo($atencion, true) === 'ATENDIDO', 'Una invalidación debe volver a hacer aplicable el asunto sin inventar pagos');
pendientes_ok(centro_pendientes_estado_efectivo(['estado'=>'RESUELTO'], true) === 'PENDIENTE', 'Una causa reactivada tras una resolución previa debe volver a requerir atención');

// Ningún pendiente puede escapar de su sede. F2 habilita ahora el saldo de
// intensivo; los demás tipos diferidos siguen fuera hasta su fase correspondiente.
pendientes_ok(centro_pendientes_mismo_alcance(['sede_id'=>'sede-monteverde'], 'sede-monteverde'), 'La sede propia debe poder consultar su pendiente');
pendientes_ok(!centro_pendientes_mismo_alcance(['sede_id'=>'sede-monteverde'], 'sede-palapas'), 'La sede ajena no debe poder consultar ni gestionar el pendiente');
pendientes_ok(CENTRO_PENDIENTES_TIPOS_HABILITADOS === [
    'MENSUALIDAD_REGULAR_SIN_COBERTURA',
    'INSCRIPCION_REGULAR_SIN_COBERTURA',
    'REPOSICION_REGULAR_DISPONIBLE',
    'SALDO_INTENSIVO_PENDIENTE',
], 'Solo los cuatro tipos con regla disponible deben quedar habilitados');

// La fuente de intensivos reutiliza la obligación registrada en el curso y solo
// resta pagos VALIDOS del mismo alumno + curso, incluidos abonos múltiples.
$fuentes = pendientes_function_source('centro_pendientes_fuentes_activas');
pendientes_ok(str_contains($fuentes, "p.intensivo_id=ci.id AND p.alumno_id=cia.alumno_id AND p.tipo='INTENSIVO'"), 'El saldo intensivo debe aislar pagos por alumno + curso');
pendientes_ok(str_contains($fuentes, "CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END"), 'Solo pagos VALIDOS pueden reducir el saldo intensivo');
pendientes_ok(str_contains($fuentes, "+0.009<ci.precio"), 'El pendiente intensivo debe desaparecer al liquidar el precio registrado');
$revalidacion = pendientes_function_source('centro_pendientes_causa_activa');
pendientes_ok(str_contains($revalidacion, "SALDO_INTENSIVO_PENDIENTE"), 'La resolución debe revalidar también el saldo intensivo');
pendientes_ok(str_contains($revalidacion, "CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END"), 'La revalidación intensiva debe ignorar pagos invalidados');

// La carga de fuentes y la rama GET son de lectura: no crean ni modifican pagos,
// asistencias, alumnos, inscripciones o reposiciones al abrir o recargar la vista.
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
