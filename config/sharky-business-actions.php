<?php

declare(strict_types=1);

require_once __DIR__.'/telefono.php';
require_once __DIR__.'/passwords.php';
require_once __DIR__.'/reglas-acceso.php';
require_once __DIR__.'/intensivos-estado.php';

final class HacheSharkyBusinessException extends RuntimeException
{
    public function __construct(string $message, public readonly string $codeName = 'INVALID', public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

function hache_sharky_business_uuid(PDO $pdo): string
{
    return (string) $pdo->query('SELECT UUID()')->fetchColumn();
}

function hache_sharky_business_actor_id(PDO $pdo): string
{
    $st = $pdo->query("SELECT id FROM usuarios WHERE rol='ADMIN' AND activo=1 ORDER BY created_at,id LIMIT 1");
    $id = (string) $st->fetchColumn();
    if ($id === '') throw new HacheSharkyBusinessException('No hay un usuario administrativo disponible para auditar la operación.', 'NO_ACTOR', 503);
    return $id;
}

function hache_sharky_business_slug(string $name): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $ascii = strtolower(preg_replace('/[^a-zA-Z0-9 ]+/', ' ', $ascii) ?? '');
    $parts = array_values(array_filter(preg_split('/\s+/', trim($ascii)) ?: []));
    return substr(($parts[0] ?? 'alumno').(count($parts) > 1 ? '.'.end($parts) : ''), 0, 40);
}

function hache_sharky_business_username(PDO $pdo, string $name): string
{
    $base = hache_sharky_business_slug($name);
    $candidate = $base;
    $i = 2;
    $st = $pdo->prepare('SELECT 1 FROM usuarios WHERE usuario=:u LIMIT 1');
    while (true) {
        $st->execute([':u'=>$candidate]);
        if (!$st->fetchColumn()) return $candidate;
        $candidate = $base.$i++;
    }
}

function hache_sharky_business_identity_by_whatsapp(PDO $pdo, string $contact): array
{
    $digits = preg_replace('/\D+/', '', $contact) ?: '';
    if (strlen($digits) === 13 && str_starts_with($digits, '521')) $digits = '52'.substr($digits, 3);
    $e164 = '+'.$digits;
    if (!telefono_es_e164($e164)) return ['found'=>false, 'reason'=>'invalid_phone'];

    $st = $pdo->prepare("SELECT a.id,a.nombre,a.estado_administrativo,s.clave sede_clave FROM alumnos a JOIN sedes s ON s.id=a.sede_id WHERE a.whatsapp=:w LIMIT 2");
    $st->execute([':w'=>$e164]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['found'=>false, 'phone'=>$e164];
    if (count($rows) > 1) return ['found'=>false, 'conflict'=>true, 'reason'=>'duplicate_phone', 'phone'=>$e164];
    $row = $rows[0];
    return [
        'found'=>true,
        'student_id'=>(string)$row['id'],
        'name'=>(string)$row['nombre'],
        'sede_clave'=>(string)$row['sede_clave'],
        'status'=>(string)$row['estado_administrativo'],
        'phone'=>$e164,
    ];
}

function hache_sharky_business_intensive_options(PDO $pdo, int $weeks = 10): array
{
    $weeks = max(1, min(10, $weeks));
    $dates = intensivo_lunes_registro($weeks);
    $out = [];
    $sites = $pdo->query("SELECT id,clave,nombre FROM sedes WHERE activo=1 AND clave IN ('MONTEVERDE','PALAPAS') ORDER BY clave")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sites as $site) {
        $st = $pdo->prepare("SELECT id,hora_inicio,hora_fin FROM horarios WHERE sede_id=:s AND activo=1 AND intensivo=1 ORDER BY hora_inicio");
        $st->execute([':s'=>$site['id']]);
        $schedules = array_map(static fn(array $h): array => [
            'id'=>(string)$h['id'],
            'label'=>substr((string)$h['hora_inicio'],0,5).'–'.substr((string)$h['hora_fin'],0,5),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        if (!$schedules) continue;

        foreach ($dates as $date) {
            if (!intensivo_inscripcion_abierta($date)) continue;
            $st = $pdo->prepare('SELECT id,estado FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:f LIMIT 1');
            $st->execute([':s'=>$site['id'], ':f'=>$date]);
            $course = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($course && in_array((string)$course['estado'], ['FINALIZADO','CANCELADO'], true)) continue;
            $id = $course ? (string)$course['id'] : 'date:'.(string)$site['clave'].':'.$date;
            $out[] = [
                'id'=>$id,
                'sede_clave'=>(string)$site['clave'],
                'sede_nombre'=>(string)$site['nombre'],
                'fecha_inicio'=>$date,
                'label'=>'Inicio '.date('d/m/Y', strtotime($date)),
                'schedules'=>$schedules,
            ];
        }
    }
    return $out;
}

function hache_sharky_business_validate_birthdate(string $birthdate, int $minAge = 12, ?string $today = null): array
{
    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthdate);
    if (!$birth || $birth->format('Y-m-d') !== $birthdate) throw new HacheSharkyBusinessException('La fecha de nacimiento no es válida.', 'INVALID_BIRTHDATE');
    $now = new DateTimeImmutable($today ?: 'today');
    if ($birth > $now) throw new HacheSharkyBusinessException('La fecha de nacimiento no es válida.', 'INVALID_BIRTHDATE');
    $age = $birth->diff($now)->y;
    if ($age < $minAge) throw new HacheSharkyBusinessException('La persona no cumple la edad mínima para este servicio.', 'MIN_AGE');
    return ['birthdate'=>$birthdate, 'age'=>$age];
}

function hache_sharky_business_create_absence(PDO $pdo, array $action, ?string $actorId = null, ?string $today = null): array
{
    $studentId = trim((string)($action['student_id'] ?? ''));
    $from = trim((string)($action['date_from'] ?? ''));
    $to = trim((string)($action['date_to'] ?? $from));
    $reason = preg_replace('/\s+/u', ' ', trim((string)($action['reason'] ?? ''))) ?? '';
    if ($studentId === '' || $from === '' || $to === '') throw new HacheSharkyBusinessException('Faltan datos para registrar la ausencia.', 'MISSING_DATA');
    if (mb_strlen($reason) > 500) throw new HacheSharkyBusinessException('El motivo no puede exceder 500 caracteres.', 'REASON_TOO_LONG');

    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $h = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (!$d || !$h || $d->format('Y-m-d') !== $from || $h->format('Y-m-d') !== $to || $h < $d) {
        throw new HacheSharkyBusinessException('El rango de fechas no es válido.', 'INVALID_DATE');
    }
    $now = new DateTimeImmutable($today ?: 'today');
    if ($h < $now) throw new HacheSharkyBusinessException('La ausencia debe corresponder a una fecha actual o futura.', 'PAST_DATE');

    $actorId ??= hache_sharky_business_actor_id($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT a.id,a.estado_administrativo,s.clave sede_clave FROM alumnos a JOIN sedes s ON s.id=a.sede_id WHERE a.id=:a LIMIT 1 FOR UPDATE");
        $st->execute([':a'=>$studentId]);
        $student = $st->fetch(PDO::FETCH_ASSOC);
        if (!$student) throw new HacheSharkyBusinessException('Alumno no encontrado.', 'STUDENT_NOT_FOUND', 404);
        if ((string)$student['estado_administrativo'] === 'BAJA') throw new HacheSharkyBusinessException('Este alumno no tiene operaciones activas habilitadas.', 'STUDENT_INACTIVE', 409);

        $st = $pdo->prepare("SELECT id FROM avisos_ausencia WHERE alumno_id=:a AND fecha_desde=:d AND fecha_hasta=:h AND estado='ACTIVO' LIMIT 1");
        $st->execute([':a'=>$studentId, ':d'=>$from, ':h'=>$to]);
        if ($existing = $st->fetchColumn()) {
            $pdo->rollBack();
            return ['ok'=>true, 'duplicate'=>true, 'absence_id'=>(string)$existing, 'code'=>'ALREADY_EXISTS'];
        }

        $id = hache_sharky_business_uuid($pdo);
        $st = $pdo->prepare("INSERT INTO avisos_ausencia(id,alumno_id,fecha_desde,fecha_hasta,motivo,estado,created_by) VALUES(:id,:a,:d,:h,:m,'ACTIVO',:u)");
        $st->execute([':id'=>$id, ':a'=>$studentId, ':d'=>$from, ':h'=>$to, ':m'=>$reason !== '' ? $reason : 'Aviso enviado por WhatsApp mediante Sharky.', ':u'=>$actorId]);
        $pdo->commit();
        return ['ok'=>true, 'duplicate'=>false, 'absence_id'=>$id, 'code'=>'CREATED', 'sede_clave'=>(string)$student['sede_clave']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function hache_sharky_business_register_intensive(PDO $pdo, array $action, ?string $actorId = null, int $minAge = 12, ?string $today = null): array
{
    $sede = strtoupper(trim((string)($action['sede_clave'] ?? '')));
    $date = trim((string)($action['fecha_inicio'] ?? ''));
    $scheduleId = trim((string)($action['schedule_id'] ?? ''));
    $name = preg_replace('/\s+/u', ' ', trim((string)($action['name'] ?? ''))) ?? '';
    $birthdate = trim((string)($action['birthdate'] ?? ''));
    $phoneRaw = trim((string)($action['contact_phone'] ?? ''));
    if (!in_array($sede, ['MONTEVERDE','PALAPAS'], true) || $date === '' || $scheduleId === '' || $phoneRaw === '') {
        throw new HacheSharkyBusinessException('Faltan datos para completar el registro.', 'MISSING_DATA');
    }
    if (mb_strlen($name) < 4 || mb_strlen($name) > 180) throw new HacheSharkyBusinessException('El nombre completo no es válido.', 'INVALID_NAME');
    hache_sharky_business_validate_birthdate($birthdate, $minAge, $today);

    $digits = preg_replace('/\D+/', '', $phoneRaw) ?: '';
    if (strlen($digits) === 13 && str_starts_with($digits, '521')) $digits = '52'.substr($digits, 3);
    $phone = '+'.$digits;
    if (!telefono_es_e164($phone)) throw new HacheSharkyBusinessException('El WhatsApp no es válido.', 'INVALID_PHONE');

    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || !intensivo_inscripcion_abierta($date)) {
        throw new HacheSharkyBusinessException('La inscripción de ese curso ya cerró.', 'REGISTRATION_CLOSED', 409);
    }

    $st = $pdo->prepare('SELECT id,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1');
    $st->execute([':c'=>$sede]);
    $site = $st->fetch(PDO::FETCH_ASSOC);
    if (!$site) throw new HacheSharkyBusinessException('La sede ya no está disponible.', 'SITE_UNAVAILABLE', 409);
    $siteId = (string)$site['id'];
    $actorId ??= hache_sharky_business_actor_id($pdo);

    $business = function_exists('hache_sharky_business_values') ? hache_sharky_business_values($pdo) : [];
    $price = (float)($business['sharky_precio_intensivo'] ?? 1200);

    $pdo->beginTransaction();
    try {
        regla_bloquear_identidades_alumnos($pdo);
        $st = $pdo->prepare('SELECT id FROM alumnos WHERE whatsapp=:w LIMIT 1');
        $st->execute([':w'=>$phone]);
        if ($st->fetchColumn()) throw new HacheSharkyBusinessException('Este WhatsApp ya tiene un registro.', 'PHONE_ALREADY_REGISTERED', 409);

        $st = $pdo->prepare("SELECT id,hora_inicio,hora_fin FROM horarios WHERE id=:h AND sede_id=:s AND activo=1 AND intensivo=1 LIMIT 1 FOR UPDATE");
        $st->execute([':h'=>$scheduleId, ':s'=>$siteId]);
        $schedule = $st->fetch(PDO::FETCH_ASSOC);
        if (!$schedule) throw new HacheSharkyBusinessException('El horario seleccionado dejó de estar disponible.', 'SCHEDULE_UNAVAILABLE', 409);

        $st = $pdo->prepare('SELECT id,estado,precio FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:f LIMIT 1 FOR UPDATE');
        $st->execute([':s'=>$siteId, ':f'=>$date]);
        $course = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($course && in_array((string)$course['estado'], ['FINALIZADO','CANCELADO'], true)) {
            throw new HacheSharkyBusinessException('El curso seleccionado dejó de estar disponible.', 'COURSE_UNAVAILABLE', 409);
        }

        $tempPassword = password_temporal_segura();
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $studentId = hache_sharky_business_uuid($pdo);
        $courseId = $course ? (string)$course['id'] : hache_sharky_business_uuid($pdo);
        if (!$course) {
            $end = (new DateTimeImmutable($date))->modify('+18 days')->format('Y-m-d');
            $state = intensivo_estado_por_fechas($date, $end);
            $st = $pdo->prepare("INSERT INTO cursos_intensivos(id,sede_id,fecha_inicio,fecha_fin,precio,estado,observaciones,created_by) VALUES(:id,:s,:fi,:ff,:p,:e,:o,:u)");
            $st->execute([':id'=>$courseId, ':s'=>$siteId, ':fi'=>$date, ':ff'=>$end, ':p'=>$price, ':e'=>$state, ':o'=>'Creado automáticamente desde registro conversacional de Sharky.', ':u'=>$actorId]);
        } else {
            $price = (float)$course['precio'];
        }

        $st = $pdo->prepare("INSERT INTO alumnos(id,sede_id,nombre,fecha_nacimiento,whatsapp,correo,fecha_inicio,horario_preferido_id,plan_actual_id,estado_administrativo,observaciones) VALUES(:id,:s,:n,:birth,:w,NULL,:f,:h,NULL,'PENDIENTE',:o)");
        $st->execute([':id'=>$studentId, ':s'=>$siteId, ':n'=>$name, ':birth'=>$birthdate, ':w'=>$phone, ':f'=>$date, ':h'=>$scheduleId, ':o'=>'Registro conversacional Sharky INTENSIVO. Pendiente de revisión/confirmación.']);

        $username = hache_sharky_business_username($pdo, $name);
        $userId = hache_sharky_business_uuid($pdo);
        $st = $pdo->prepare("INSERT INTO usuarios(id,usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id) VALUES(:id,:u,:p,'ALUMNO',1,1,:a)");
        $st->execute([':id'=>$userId, ':u'=>$username, ':p'=>$passwordHash, ':a'=>$studentId]);

        $st = $pdo->prepare('INSERT INTO registros_publicos(id,alumno_id,sede_id,tipo,horario_id,fecha_inicio_intensivo) VALUES(:id,:a,:s,\'INTENSIVO\',:h,:f)');
        $st->execute([':id'=>hache_sharky_business_uuid($pdo), ':a'=>$studentId, ':s'=>$siteId, ':h'=>$scheduleId, ':f'=>$date]);

        $st = $pdo->prepare('INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,horario_id,observaciones,created_by) VALUES(:id,:c,:a,:h,:o,:u)');
        $st->execute([':id'=>hache_sharky_business_uuid($pdo), ':c'=>$courseId, ':a'=>$studentId, ':h'=>$scheduleId, ':o'=>'Inscripción automática desde WhatsApp/Sharky. Pendiente de confirmación/pago.', ':u'=>$actorId]);
        $pdo->commit();

        return [
            'ok'=>true,
            'code'=>'CREATED',
            'student_id'=>$studentId,
            'course_id'=>$courseId,
            'username'=>$username,
            'temporary_password'=>$tempPassword,
            'sede_clave'=>$sede,
            'sede_nombre'=>(string)$site['nombre'],
            'fecha_inicio'=>$date,
            'schedule_label'=>substr((string)$schedule['hora_inicio'],0,5).'–'.substr((string)$schedule['hora_fin'],0,5),
            'price'=>$price,
            'status'=>'PENDIENTE',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
