#!/usr/bin/env php
<?php

declare(strict_types=1);

final class SmokeFailure extends RuntimeException {}

$root = dirname(__DIR__);
$realRoot = realpath($root) ?: $root;
if ($realRoot === '/var/www/hache-natacion') {
    throw new SmokeFailure('SEGURIDAD: el arnés no puede ejecutarse desde el directorio estable de producción.');
}

$config = require $root . '/config/database.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/^hache_natacion_audit_[0-9]{8}$/', $database)) {
    throw new SmokeFailure('SEGURIDAD: la base debe ser un clon hache_natacion_audit_YYYYMMDD; se recibió ' . $database);
}

if (!extension_loaded('curl')) throw new SmokeFailure('La extensión PHP cURL es obligatoria para los smoke tests HTTP.');
if (!function_exists('proc_open')) throw new SmokeFailure('proc_open no está disponible para iniciar el servidor aislado.');

$createdVendorLink = false;
$vendorAutoload = $root . '/vendor/autoload.php';
if (!is_file($vendorAutoload)) {
    $stableVendor = '/var/www/hache-natacion/vendor';
    if (is_file($stableVendor . '/autoload.php') && !file_exists($root . '/vendor')) {
        if (!symlink($stableVendor, $root . '/vendor')) throw new SmokeFailure('No se pudo enlazar vendor para probar PDFs.');
        $createdVendorLink = true;
    }
}
if (!is_file($vendorAutoload)) throw new SmokeFailure('No existe vendor/autoload.php; no es posible validar la generación de PDF.');

$run = 'smk' . (new DateTimeImmutable('now', new DateTimeZone('America/Cancun')))->format('ymdHis') . bin2hex(random_bytes(2));
$password = 'Smk!' . bin2hex(random_bytes(8)) . 'Aa7';
$socket = stream_socket_server('tcp://127.0.0.1:0', $socketErrorNumber, $socketErrorMessage);
if ($socket === false) throw new SmokeFailure('No se pudo reservar un puerto local: ' . $socketErrorMessage);
$socketAddress = (string)stream_socket_get_name($socket, false);
fclose($socket);
$port = (int)substr(strrchr($socketAddress, ':'), 1);
if ($port < 1) throw new SmokeFailure('No se pudo resolver el puerto local de prueba.');
$baseUrl = 'http://127.0.0.1:' . $port;
$logFile = sys_get_temp_dir() . '/hache-runtime-smoke-' . $run . '.log';
$cookies = [];
$server = null;

function smokeOk(string $message): void { echo "[OK] {$message}\n"; }
function smokeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new SmokeFailure($message);
    smokeOk($message);
}
function smokeUuid(PDO $pdo): string { return (string)$pdo->query('SELECT UUID()')->fetchColumn(); }
function smokeJson(array $response, array $statuses, string $label): array
{
    $decoded = json_decode($response['body'], true);
    if (!in_array($response['status'], $statuses, true)) {
        $detail = is_array($decoded) && is_string($decoded['error'] ?? null)
            ? ' · ' . $decoded['error']
            : ' · respuesta: ' . preg_replace('/\s+/', ' ', substr($response['body'], 0, 240));
        throw new SmokeFailure($label . ' · HTTP ' . $response['status'] . $detail);
    }
    if (!is_array($decoded)) throw new SmokeFailure($label . ' devolvió JSON inválido: ' . substr($response['body'], 0, 240));
    return $decoded;
}
function smokeRequest(string $baseUrl, string $method, string $path, ?string $cookieFile = null, mixed $body = null, bool $form = false): array
{
    $headers = [];
    $ch = curl_init($baseUrl . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            return $length;
        },
    ];
    if ($cookieFile !== null) {
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    if ($body !== null) {
        if ($form) {
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
            $options[CURLOPT_POSTFIELDS] = http_build_query($body);
        } else {
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($responseBody === false || $error !== '') throw new SmokeFailure("HTTP {$method} {$path}: {$error}");
    return ['status' => $status, 'body' => (string)$responseBody, 'headers' => $headers];
}
function smokeLogin(string $baseUrl, string $cookie, string $username, string $password, string $role): array
{
    $response = smokeRequest($baseUrl, 'POST', '/api/login.php', $cookie, ['usuario' => $username, 'password' => $password]);
    $json = smokeJson($response, [200], "Login {$role}");
    smokeAssert(($json['ok'] ?? false) === true && ($json['usuario']['rol'] ?? '') === $role, "Sesión {$role} válida");
    return $json;
}
function smokeSwitchSite(string $baseUrl, string $cookie, string $site): void
{
    $json = smokeJson(smokeRequest($baseUrl, 'POST', '/api/sesion.php', $cookie, ['accion' => 'SET_SEDE', 'sede' => $site]), [200], "Cambiar a {$site}");
    smokeAssert(($json['sede_activa'] ?? '') === $site, "Sede activa {$site}");
}
function smokeFindStudentSession(array $payload, string $studentId): ?array
{
    foreach (($payload['sesiones'] ?? []) as $session) {
        foreach (($session['alumnos'] ?? []) as $student) {
            if (($student['id'] ?? '') === $studentId) return $session;
        }
    }
    return null;
}
function smokeNextWeekday(): DateTimeImmutable
{
    $date = new DateTimeImmutable('tomorrow', new DateTimeZone('America/Cancun'));
    while ((int)$date->format('N') >= 6) $date = $date->modify('+1 day');
    return $date;
}
function smokeFreeMonday(PDO $pdo, string $siteId): DateTimeImmutable
{
    $date = new DateTimeImmutable('next monday', new DateTimeZone('America/Cancun'));
    $statement = $pdo->prepare('SELECT COUNT(*) FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:f');
    for ($i = 0; $i < 30; $i++) {
        $statement->execute([':s' => $siteId, ':f' => $date->format('Y-m-d')]);
        if ((int)$statement->fetchColumn() === 0) return $date;
        $date = $date->modify('+7 days');
    }
    throw new SmokeFailure('No se encontró un lunes libre para el intensivo de prueba.');
}
function smokeFreeClosePeriod(PDO $pdo, string $siteId): string
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM cierres_mensuales WHERE sede_id=:s AND periodo=:p');
    for ($year = 2090; $year <= 2099; $year++) {
        for ($month = 1; $month <= 12; $month++) {
            $period = sprintf('%04d-%02d', $year, $month);
            $statement->execute([':s' => $siteId, ':p' => $period . '-01']);
            if ((int)$statement->fetchColumn() === 0) return $period;
        }
    }
    throw new SmokeFailure('No se encontró un periodo libre para probar cierres.');
}

try {
    $siteRows = $pdo->query("SELECT id,clave,nombre FROM sedes WHERE clave IN ('MONTEVERDE','PALAPAS') AND activo=1 ORDER BY clave")->fetchAll();
    $sites = [];
    foreach ($siteRows as $site) $sites[$site['clave']] = $site;
    smokeAssert(isset($sites['MONTEVERDE'], $sites['PALAPAS']), 'Las dos sedes activas existen en el clon');

    $fixtures = [];
    $phoneSeed = random_int(1000000, 8999990);
    foreach (['MONTEVERDE', 'PALAPAS'] as $index => $siteKey) {
        $site = $sites[$siteKey];
        $planStatement = $pdo->prepare('SELECT id,nombre,sesiones_semana,precio FROM planes WHERE sede_id=:s AND activo=1 AND sesiones_semana=3 ORDER BY precio LIMIT 1');
        $planStatement->execute([':s' => $site['id']]);
        $plan = $planStatement->fetch();
        smokeAssert((bool)$plan, "{$siteKey}: existe plan activo de 3 sesiones");
        $regularStatement = $pdo->prepare('SELECT id,hora_inicio,hora_fin FROM horarios WHERE sede_id=:s AND activo=1 AND regular=1 ORDER BY hora_inicio LIMIT 1');
        $regularStatement->execute([':s' => $site['id']]);
        $regular = $regularStatement->fetch();
        smokeAssert((bool)$regular, "{$siteKey}: existe horario regular activo");
        $intensiveStatement = $pdo->prepare('SELECT id,hora_inicio,hora_fin FROM horarios WHERE sede_id=:s AND activo=1 AND intensivo=1 ORDER BY hora_inicio DESC LIMIT 1');
        $intensiveStatement->execute([':s' => $site['id']]);
        $intensive = $intensiveStatement->fetch();
        smokeAssert((bool)$intensive, "{$siteKey}: existe horario intensivo activo");

        $regularStudent = smokeUuid($pdo);
        $intensiveStudent = smokeUuid($pdo);
        $studentInsert = $pdo->prepare("INSERT INTO alumnos(id,sede_id,nombre,fecha_nacimiento,whatsapp,correo,fecha_inicio,horario_preferido_id,plan_actual_id,ciclo_pago,estado_administrativo,observaciones) VALUES(:id,:s,:n,NULL,:w,:c,CURDATE(),:h,:p,:ciclo,'PENDIENTE',:o)");
        $studentInsert->execute([
            ':id' => $regularStudent, ':s' => $site['id'], ':n' => "ZZ SMOKE {$run} {$siteKey} REGULAR",
            ':w' => '52990' . (string)($phoneSeed + $index * 10), ':c' => "{$run}.{$index}.regular@example.invalid",
            ':h' => $regular['id'], ':p' => $plan['id'], ':ciclo' => $siteKey === 'PALAPAS' ? 'P1' : null,
            ':o' => 'Fixture automatizado de preproducción; base clonada.',
        ]);
        $studentInsert->execute([
            ':id' => $intensiveStudent, ':s' => $site['id'], ':n' => "ZZ SMOKE {$run} {$siteKey} INTENSIVO",
            ':w' => '52990' . (string)($phoneSeed + $index * 10 + 1), ':c' => "{$run}.{$index}.intensivo@example.invalid",
            ':h' => $regular['id'], ':p' => $plan['id'], ':ciclo' => $siteKey === 'PALAPAS' ? 'P1' : null,
            ':o' => 'Fixture automatizado de preproducción; base clonada.',
        ]);
        $fixtures[$siteKey] = [
            'site' => $site, 'plan' => $plan, 'regular_schedule' => $regular, 'intensive_schedule' => $intensive,
            'regular_student' => $regularStudent, 'intensive_student' => $intensiveStudent,
        ];
    }

    $userInsert = $pdo->prepare('INSERT INTO usuarios(id,usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id,sede_id) VALUES(:id,:u,:p,:r,1,0,:a,:s)');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $adminUser = "{$run}_admin";
    $userInsert->execute([':id' => smokeUuid($pdo), ':u' => $adminUser, ':p' => $hash, ':r' => 'ADMIN', ':a' => null, ':s' => null]);
    foreach (['MONTEVERDE', 'PALAPAS'] as $siteKey) {
        $fixtures[$siteKey]['verifier_user'] = strtolower("{$run}_ver_{$siteKey}");
        $fixtures[$siteKey]['student_user'] = strtolower("{$run}_alu_{$siteKey}");
        $userInsert->execute([':id' => smokeUuid($pdo), ':u' => $fixtures[$siteKey]['verifier_user'], ':p' => $hash, ':r' => 'VERIFICADOR', ':a' => null, ':s' => $fixtures[$siteKey]['site']['id']]);
        $userInsert->execute([':id' => smokeUuid($pdo), ':u' => $fixtures[$siteKey]['student_user'], ':p' => $hash, ':r' => 'ALUMNO', ':a' => $fixtures[$siteKey]['regular_student'], ':s' => null]);
    }
    smokeOk('Fixtures aislados creados en ' . $database . ' con prefijo ' . $run);

    $descriptor = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];
    $server = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $root . '/tests/runtime-smoke-router.php'],
        $descriptor,
        $pipes,
        $root
    );
    if (!is_resource($server)) throw new SmokeFailure('No se pudo iniciar el servidor HTTP aislado.');

    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        try {
            $health = smokeRequest($baseUrl, 'GET', '/api/health.php');
            if ($health['status'] === 200) { $ready = true; break; }
        } catch (Throwable $ignored) {}
        usleep(125000);
    }
    smokeAssert($ready, 'Servidor HTTP aislado y health check operativos');

    $anonymous = smokeRequest($baseUrl, 'GET', '/api/dashboard.php');
    smokeAssert($anonymous['status'] === 401, 'API privada rechaza solicitudes sin sesión');

    $adminCookie = tempnam(sys_get_temp_dir(), 'hache-smk-admin-');
    if ($adminCookie === false) throw new SmokeFailure('No se pudo crear cookie temporal ADMIN.');
    $cookies[] = $adminCookie;
    smokeLogin($baseUrl, $adminCookie, $adminUser, $password, 'ADMIN');

    $classDate = smokeNextWeekday();
    $payments = [];
    $intensives = [];
    foreach (['MONTEVERDE', 'PALAPAS'] as $siteKey) {
        $fixture = $fixtures[$siteKey];
        smokeSwitchSite($baseUrl, $adminCookie, $siteKey);
        $dashboard = smokeJson(smokeRequest($baseUrl, 'GET', '/api/dashboard.php', $adminCookie), [200], "{$siteKey}: dashboard ADMIN");
        smokeAssert(($dashboard['ok'] ?? false) === true, "{$siteKey}: dashboard responde correctamente");

        $registration = smokeJson(smokeRequest($baseUrl, 'POST', '/api/pagos-smart.php', $adminCookie, [
            'alumno_id' => $fixture['regular_student'], 'tipo' => 'INSCRIPCION',
            'importe' => 500, 'metodo' => 'EFECTIVO',
            'fecha' => $classDate->format('Y-m-d') . ' 11:55:00',
            'observacion' => 'Inscripción de smoke test autenticado', 'sede' => $siteKey,
        ]), [200], "{$siteKey}: registrar inscripción");
        smokeAssert(($registration['ok'] ?? false) === true && !empty($registration['pago']['id']), "{$siteKey}: inscripción válida creada");

        $payPayload = [
            'alumno_id' => $fixture['regular_student'], 'tipo' => 'MENSUALIDAD',
            'importe' => (float)$fixture['plan']['precio'], 'metodo' => 'EFECTIVO',
            'fecha' => $classDate->format('Y-m-d') . ' 12:00:00', 'observacion' => 'Smoke test autenticado',
            'periodo_mes' => (int)$classDate->format('n'), 'periodo_anio' => (int)$classDate->format('Y'), 'sede' => $siteKey,
        ];
        $payment = smokeJson(smokeRequest($baseUrl, 'POST', '/api/pagos-smart.php', $adminCookie, $payPayload), [200], "{$siteKey}: registrar mensualidad");
        smokeAssert(($payment['ok'] ?? false) === true && !empty($payment['pago']['id']), "{$siteKey}: pago válido creado");
        $payments[$siteKey] = $payment['pago'];

        $attendance = smokeJson(smokeRequest($baseUrl, 'GET', '/api/asistencia.php?fecha=' . $classDate->format('Y-m-d') . '&sede=' . $siteKey, $adminCookie), [200], "{$siteKey}: generar/listar sesiones");
        $session = smokeFindStudentSession($attendance, $fixture['regular_student']);
        smokeAssert($session !== null, "{$siteKey}: alumno regular aparece en su sesión");
        $absence = smokeJson(smokeRequest($baseUrl, 'POST', '/api/asistencia.php', $adminCookie, [
            'accion' => 'ASISTENCIA', 'sede' => $siteKey, 'sesion_id' => $session['id'],
            'alumno_id' => $fixture['regular_student'], 'estado' => 'AUSENTE_JUSTIFICADA', 'observacion' => 'Smoke reposición',
        ]), [200], "{$siteKey}: ausencia justificada");
        smokeAssert(($absence['reposicion']['generada'] ?? false) === true, "{$siteKey}: reposición regular generada");
        $closeSession = smokeJson(smokeRequest($baseUrl, 'POST', '/api/asistencia.php', $adminCookie, [
            'accion' => 'CERRAR', 'sede' => $siteKey, 'sesion_id' => $session['id'],
        ]), [200], "{$siteKey}: cerrar sesión");
        smokeAssert(($closeSession['ok'] ?? false) === true, "{$siteKey}: sesión cerrada");

        $closePeriod = smokeFreeClosePeriod($pdo, $fixture['site']['id']);
        $monthlyClose = smokeJson(smokeRequest($baseUrl, 'POST', '/api/cierres-mensuales.php', $adminCookie, [
            'periodo' => $closePeriod, 'observacion' => 'Cierre vacío de smoke test en clon',
        ]), [201], "{$siteKey}: cierre mensual");
        smokeAssert(($monthlyClose['ok'] ?? false) === true, "{$siteKey}: cierre mensual persistido");

        $monday = smokeFreeMonday($pdo, $fixture['site']['id']);
        $course = smokeJson(smokeRequest($baseUrl, 'POST', '/api/intensivos.php', $adminCookie, [
            'sede' => $siteKey, 'fecha_inicio' => $monday->format('Y-m-d'), 'precio' => 1200,
            'observaciones' => 'Curso de smoke test en clon',
        ]), [201], "{$siteKey}: crear intensivo");
        $courseId = (string)($course['intensivo']['id'] ?? '');
        smokeAssert($courseId !== '', "{$siteKey}: intensivo creado con ID");
        $enrollment = smokeJson(smokeRequest($baseUrl, 'POST', '/api/intensivo-alumnos.php', $adminCookie, [
            'curso_intensivo_id' => $courseId, 'alumno_id' => $fixture['intensive_student'],
            'horario_id' => $fixture['intensive_schedule']['id'], 'observaciones' => 'Inscripción smoke',
        ]), [201], "{$siteKey}: agregar alumno a intensivo");
        smokeAssert(($enrollment['ok'] ?? false) === true, "{$siteKey}: alumno inscrito al intensivo");
        $intensivePayment = smokeJson(smokeRequest($baseUrl, 'POST', '/api/pagos-smart.php', $adminCookie, [
            'alumno_id' => $fixture['intensive_student'], 'tipo' => 'INTENSIVO', 'importe' => 1200,
            'metodo' => 'TRANSFERENCIA', 'fecha' => (new DateTimeImmutable('now', new DateTimeZone('America/Cancun')))->format('Y-m-d H:i:s'),
            'observacion' => 'Pago intensivo smoke', 'curso_intensivo_id' => $courseId, 'sede' => $siteKey,
        ]), [200], "{$siteKey}: pagar intensivo");
        smokeAssert(($intensivePayment['ok'] ?? false) === true, "{$siteKey}: pago intensivo válido");
        $intensives[$siteKey] = ['id' => $courseId, 'date' => $monday, 'payment' => $intensivePayment['pago']];

        $intensiveAttendance = smokeJson(smokeRequest($baseUrl, 'GET', '/api/asistencia.php?fecha=' . $monday->format('Y-m-d') . '&sede=' . $siteKey, $adminCookie), [200], "{$siteKey}: sesión de intensivo");
        $intensiveSession = smokeFindStudentSession($intensiveAttendance, $fixture['intensive_student']);
        smokeAssert($intensiveSession !== null, "{$siteKey}: alumno intensivo aparece en sesión");
        $intensiveAbsence = smokeJson(smokeRequest($baseUrl, 'POST', '/api/asistencia.php', $adminCookie, [
            'accion' => 'ASISTENCIA', 'sede' => $siteKey, 'sesion_id' => $intensiveSession['id'],
            'alumno_id' => $fixture['intensive_student'], 'estado' => 'AUSENTE_JUSTIFICADA', 'observacion' => 'Smoke reposición intensivo',
        ]), [200], "{$siteKey}: ausencia de intensivo");
        smokeAssert(($intensiveAbsence['reposicion']['tipo'] ?? '') === 'INTENSIVO' && ($intensiveAbsence['reposicion']['generada'] ?? false) === true, "{$siteKey}: reposición intensiva contabilizada");

        $pdf = smokeRequest($baseUrl, 'GET', '/api/reporte-personalizado-pdf.php?tipo=intensivo&curso_id=' . rawurlencode($courseId) . '&sede=' . $siteKey, $adminCookie);
        smokeAssert($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-') && strlen($pdf['body']) > 1000, "{$siteKey}: PDF de intensivo generado");
    }

    foreach (['MONTEVERDE', 'PALAPAS'] as $siteKey) {
        $otherSite = $siteKey === 'MONTEVERDE' ? 'PALAPAS' : 'MONTEVERDE';
        $verifierCookie = tempnam(sys_get_temp_dir(), 'hache-smk-ver-');
        if ($verifierCookie === false) throw new SmokeFailure('No se pudo crear cookie temporal VERIFICADOR.');
        $cookies[] = $verifierCookie;
        $login = smokeLogin($baseUrl, $verifierCookie, $fixtures[$siteKey]['verifier_user'], $password, 'VERIFICADOR');
        smokeAssert(($login['usuario']['sede_clave'] ?? '') === $siteKey, "{$siteKey}: verificador ligado a su sede");
        $list = smokeJson(smokeRequest($baseUrl, 'GET', '/api/alumnos.php?sede=' . $otherSite, $verifierCookie), [200], "{$siteKey}: listado de verificador");
        $encoded = json_encode($list, JSON_UNESCAPED_UNICODE);
        smokeAssert(str_contains((string)$encoded, $fixtures[$siteKey]['regular_student']), "{$siteKey}: verificador ve alumno propio");
        smokeAssert(!str_contains((string)$encoded, $fixtures[$otherSite]['regular_student']), "{$siteKey}: verificador no ve alumno de {$otherSite}");
        $deniedSwitch = smokeRequest($baseUrl, 'POST', '/api/sesion.php', $verifierCookie, ['accion' => 'SET_SEDE', 'sede' => $otherSite]);
        smokeAssert($deniedSwitch['status'] === 403, "{$siteKey}: verificador no puede cambiar a {$otherSite}");
        $deniedPayment = smokeRequest($baseUrl, 'POST', '/api/pagos-smart.php', $verifierCookie, ['alumno_id' => $fixtures[$siteKey]['regular_student']]);
        smokeAssert($deniedPayment['status'] === 403, "{$siteKey}: verificador no puede registrar pagos");

        $studentCookie = tempnam(sys_get_temp_dir(), 'hache-smk-alu-');
        if ($studentCookie === false) throw new SmokeFailure('No se pudo crear cookie temporal ALUMNO.');
        $cookies[] = $studentCookie;
        smokeLogin($baseUrl, $studentCookie, $fixtures[$siteKey]['student_user'], $password, 'ALUMNO');
        $portal = smokeJson(smokeRequest($baseUrl, 'GET', '/api/portal-alumno.php', $studentCookie), [200], "{$siteKey}: portal alumno");
        smokeAssert(($portal['alumno']['id'] ?? '') === $fixtures[$siteKey]['regular_student'], "{$siteKey}: portal devuelve únicamente la ficha vinculada");
        $noticeDate = (new DateTimeImmutable('+10 days', new DateTimeZone('America/Cancun')))->format('Y-m-d');
        $notice = smokeJson(smokeRequest($baseUrl, 'POST', '/api/portal-alumno.php', $studentCookie, [
            'accion' => 'AVISO', 'fecha_desde' => $noticeDate, 'fecha_hasta' => $noticeDate, 'motivo' => 'Aviso smoke alumno',
        ]), [200], "{$siteKey}: aviso desde portal");
        smokeAssert(!empty($notice['id']), "{$siteKey}: aviso de ausencia creado por alumno");
        $cancelNotice = smokeJson(smokeRequest($baseUrl, 'POST', '/api/portal-alumno.php', $studentCookie, [
            'accion' => 'CANCELAR_AVISO', 'id' => $notice['id'],
        ]), [200], "{$siteKey}: cancelar aviso desde portal");
        smokeAssert(($cancelNotice['ok'] ?? false) === true, "{$siteKey}: aviso cancelado por su propietario");
        $studentDenied = smokeRequest($baseUrl, 'GET', '/api/alumnos.php', $studentCookie);
        smokeAssert($studentDenied['status'] === 403, "{$siteKey}: alumno no accede al padrón administrativo");
    }

    foreach (['MONTEVERDE', 'PALAPAS'] as $index => $siteKey) {
        $publicCookie = tempnam(sys_get_temp_dir(), 'hache-smk-public-');
        if ($publicCookie === false) throw new SmokeFailure('No se pudo crear cookie temporal pública.');
        $cookies[] = $publicCookie;
        $form = smokeRequest($baseUrl, 'GET', '/registro.php?sede=' . $siteKey . '&tipo=REGULAR', $publicCookie);
        smokeAssert($form['status'] === 200 && preg_match('/name="csrf" value="([^"]+)"/', $form['body'], $match) === 1, "{$siteKey}: formulario público y CSRF disponibles");
        $publicName = "ZZ Registro {$run} {$siteKey}";
        $publicPhone = '990' . str_pad((string)($phoneSeed + 100 + $index), 7, '0', STR_PAD_LEFT);
        $submit = smokeRequest($baseUrl, 'POST', '/registro.php?sede=' . $siteKey . '&tipo=REGULAR', $publicCookie, [
            'csrf' => $match[1], 'website' => '', 'nombre' => $publicName, 'whatsapp' => $publicPhone,
            'whatsapp_pais' => 'MX', 'horario_id' => $fixtures[$siteKey]['regular_schedule']['id'], 'fecha_inicio' => '',
        ], true);
        smokeAssert($submit['status'] === 200 && str_contains($submit['body'], 'Registro recibido'), "{$siteKey}: registro público regular completado");
        $verifyPublic = $pdo->prepare("SELECT COUNT(*) FROM registros_publicos rp JOIN alumnos a ON a.id=rp.alumno_id WHERE rp.sede_id=:s AND rp.tipo='REGULAR' AND a.nombre=:n");
        $verifyPublic->execute([':s' => $fixtures[$siteKey]['site']['id'], ':n' => $publicName]);
        smokeAssert((int)$verifyPublic->fetchColumn() === 1, "{$siteKey}: registro público persistido en sede correcta");
        $intensiveForm = smokeRequest($baseUrl, 'GET', '/registro.php?sede=' . $siteKey . '&tipo=INTENSIVO', $publicCookie);
        smokeAssert($intensiveForm['status'] === 200 && str_contains($intensiveForm['body'], 'Curso intensivo'), "{$siteKey}: formulario público intensivo disponible");
    }

    foreach (['MONTEVERDE', 'PALAPAS'] as $siteKey) {
        smokeSwitchSite($baseUrl, $adminCookie, $siteKey);
        $payment = $payments[$siteKey];
        $edit = smokeJson(smokeRequest($baseUrl, 'POST', '/api/editar-pago.php', $adminCookie, [
            'pago_id' => $payment['id'], 'motivo' => 'Validación de edición en smoke test',
            'importe' => (float)$payment['importe'], 'metodo' => 'TRANSFERENCIA', 'fecha' => substr((string)$payment['fecha'], 0, 19),
            'observacion' => 'Editado por smoke test en clon',
        ]), [200], "{$siteKey}: editar pago");
        smokeAssert(($edit['pago']['metodo'] ?? '') === 'TRANSFERENCIA', "{$siteKey}: edición de pago persistida");
        $invalidate = smokeJson(smokeRequest($baseUrl, 'POST', '/api/invalidar-pago.php', $adminCookie, [
            'pago_id' => $payment['id'], 'motivo' => 'Invalidación esperada del smoke test',
        ]), [200], "{$siteKey}: invalidar pago");
        smokeAssert(($invalidate['ok'] ?? false) === true, "{$siteKey}: invalidación aplicada y estado recalculado");
    }

    $currentDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    smokeAssert($currentDatabase === $database && str_starts_with($database, 'hache_natacion_audit_'), 'Todas las mutaciones permanecieron en el clon aislado');
    $auditCount = (int)$pdo->query("SELECT COUNT(*) FROM auditoria_eventos WHERE usuario_nombre LIKE " . $pdo->quote($run . '%'))->fetchColumn();
    smokeAssert($auditCount > 0, 'Las mutaciones HTTP dejaron trazabilidad en auditoria_eventos');

    echo "\nSMOKE_RUNTIME_OK\n";
    echo "DB_CLON={$database}\n";
    echo "PREFIJO_FIXTURES={$run}\n";
    echo "PRUEBAS_HTTP_AUTENTICADAS=ADMIN,VERIFICADOR_MONTEVERDE,VERIFICADOR_PALAPAS,ALUMNO_MONTEVERDE,ALUMNO_PALAPAS\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nSMOKE_RUNTIME_FAIL: " . $e->getMessage() . "\n");
    if (is_file($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
        fwrite(STDERR, "--- últimas líneas del servidor aislado ---\n" . implode("\n", array_slice($lines, -30)) . "\n");
    }
    exit(1);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        for ($i = 0; $i < 20; $i++) {
            $status = proc_get_status($server);
            if (!$status['running']) break;
            usleep(50000);
        }
        proc_close($server);
    }
    foreach ($cookies as $cookie) if (is_string($cookie) && is_file($cookie)) unlink($cookie);
    if ($createdVendorLink && is_link($root . '/vendor')) unlink($root . '/vendor');
}
