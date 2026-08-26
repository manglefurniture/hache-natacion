<?php

declare(strict_types=1);

/**
 * Envía alertas de inscripción por SMTP sin dependencias externas.
 *
 * Variables requeridas en producción:
 * HACHE_ALERT_EMAIL_TO
 * HACHE_SMTP_HOST
 * HACHE_SMTP_USER
 * HACHE_SMTP_PASS
 *
 * Opcionales:
 * HACHE_SMTP_PORT (587)
 * HACHE_SMTP_FROM (igual a HACHE_SMTP_USER)
 * HACHE_SMTP_FROM_NAME (Hache Natación)
 */
function hache_notificar_nueva_inscripcion(array $alumno, string $tipoIngreso, array $detalle = []): bool
{
    $to = trim((string)(getenv('HACHE_ALERT_EMAIL_TO') ?: ''));
    $host = trim((string)(getenv('HACHE_SMTP_HOST') ?: ''));
    $user = trim((string)(getenv('HACHE_SMTP_USER') ?: ''));
    $pass = (string)(getenv('HACHE_SMTP_PASS') ?: '');
    $port = (int)(getenv('HACHE_SMTP_PORT') ?: 587);
    $from = trim((string)(getenv('HACHE_SMTP_FROM') ?: $user));
    $fromName = trim((string)(getenv('HACHE_SMTP_FROM_NAME') ?: 'Hache Natación'));

    if ($to === '' || $host === '' || $user === '' || $pass === '' || $from === '') {
        error_log('[notificaciones-email] Configuración SMTP incompleta; alerta omitida.');
        return false;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('[notificaciones-email] Dirección de correo inválida; alerta omitida.');
        return false;
    }

    $nombre = trim((string)($alumno['nombre'] ?? 'Alumno'));
    $sede = trim((string)($alumno['sede_nombre'] ?? $alumno['sede_clave'] ?? ''));
    $whatsapp = trim((string)($alumno['whatsapp'] ?? ''));
    $correo = trim((string)($alumno['correo'] ?? ''));
    $fechaInicio = trim((string)($alumno['fecha_inicio'] ?? ''));
    $plan = trim((string)($alumno['plan_nombre'] ?? ''));
    $tipo = strtoupper($tipoIngreso) === 'INTENSIVO' ? 'Curso intensivo' : 'Clases regulares';
    $horario = trim((string)($detalle['horario'] ?? ''));
    $cursoInicio = trim((string)($detalle['curso_inicio'] ?? ''));

    $subject = 'Nueva inscripción · ' . $tipo . ($sede !== '' ? ' · ' . $sede : '');
    $lines = [
        'Nueva inscripción en Hache Natación',
        '',
        'Alumno: ' . $nombre,
        'Modalidad: ' . $tipo,
        'Sede: ' . ($sede !== '' ? $sede : '—'),
        'WhatsApp: ' . ($whatsapp !== '' ? $whatsapp : '—'),
        'Correo: ' . ($correo !== '' ? $correo : '—'),
        'Fecha de inicio: ' . ($fechaInicio !== '' ? $fechaInicio : '—'),
    ];
    if ($plan !== '') $lines[] = 'Plan: ' . $plan;
    if ($horario !== '') $lines[] = 'Horario: ' . $horario;
    if ($cursoInicio !== '') $lines[] = 'Inicio del intensivo: ' . $cursoInicio;
    $lines[] = '';
    $lines[] = 'Estado administrativo: ' . (string)($alumno['estado_administrativo'] ?? 'PENDIENTE');
    $body = implode("\r\n", $lines);

    try {
        return hache_smtp_send($host, $port, $user, $pass, $from, $fromName, $to, $subject, $body);
    } catch (Throwable $e) {
        error_log('[notificaciones-email] No se pudo enviar alerta: ' . $e->getMessage());
        return false;
    }
}

function hache_smtp_send(string $host, int $port, string $user, string $pass, string $from, string $fromName, string $to, string $subject, string $body): bool
{
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 8);
    if (!$socket) throw new RuntimeException('No se pudo conectar al SMTP: ' . $errstr);
    stream_set_timeout($socket, 8);

    $read = static function () use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $response;
    };
    $cmd = static function (string $command, array $expected) use ($socket, $read): string {
        fwrite($socket, $command . "\r\n");
        $response = $read();
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) throw new RuntimeException('SMTP rechazó comando con código ' . $code);
        return $response;
    };

    $hello = php_uname('n') ?: 'hnatacion.com';
    $greeting = $read();
    if ((int)substr($greeting, 0, 3) !== 220) throw new RuntimeException('SMTP no respondió con saludo válido');
    $cmd('EHLO ' . $hello, [250]);
    $cmd('STARTTLS', [220]);
    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('No se pudo iniciar TLS');
    $cmd('EHLO ' . $hello, [250]);
    $cmd('AUTH LOGIN', [334]);
    $cmd(base64_encode($user), [334]);
    $cmd(base64_encode($pass), [235]);
    $cmd('MAIL FROM:<' . $from . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);

    $safeSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $safeFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = [
        'From: ' . $safeFromName . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $safeSubject,
        'Date: ' . date(DATE_RFC2822),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $payload = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/(?m)^\./', '..', $body) . "\r\n.";
    $cmd($payload, [250]);
    $cmd('QUIT', [221]);
    fclose($socket);
    return true;
}
