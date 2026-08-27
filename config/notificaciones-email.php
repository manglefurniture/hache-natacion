<?php

declare(strict_types=1);

/**
 * Envía alertas de inscripción mediante la API HTTPS de Resend.
 *
 * Variables requeridas en producción:
 * HACHE_ALERT_EMAIL_TO
 * HACHE_RESEND_API_KEY
 * HACHE_EMAIL_FROM
 *
 * Opcional:
 * HACHE_EMAIL_FROM_NAME (Hache Natación)
 */
function hache_construir_alerta_nueva_inscripcion(array $alumno,string $tipoIngreso,array $detalle=[]):array
{
    $nombre = trim((string)($alumno['nombre'] ?? 'Alumno'));
    $sede = trim((string)($alumno['sede_nombre'] ?? $alumno['sede_clave'] ?? ''));
    $whatsapp = trim((string)($alumno['whatsapp'] ?? ''));
    $correo = trim((string)($alumno['correo'] ?? ''));
    $fechaInicio = trim((string)($alumno['fecha_inicio'] ?? ''));
    $plan = trim((string)($alumno['plan_nombre'] ?? ''));
    $esIntensivo=strtoupper($tipoIngreso)==='INTENSIVO';
    $tipo = $esIntensivo ? 'Curso intensivo' : 'Clases regulares';
    $horario = trim((string)($detalle['horario'] ?? ''));
    $cursoInicio = $esIntensivo ? trim((string)($detalle['curso_inicio'] ?? '')) : '';

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

    return ['subject'=>$subject,'body'=>$body];
}

function hache_notificar_nueva_inscripcion(array $alumno, string $tipoIngreso, array $detalle = []): bool
{
    $to = trim((string)(getenv('HACHE_ALERT_EMAIL_TO') ?: ''));
    $apiKey = trim((string)(getenv('HACHE_RESEND_API_KEY') ?: ''));
    $from = trim((string)(getenv('HACHE_EMAIL_FROM') ?: ''));
    $fromName = trim((string)(getenv('HACHE_EMAIL_FROM_NAME') ?: 'Hache Natación'));

    if ($to === '' || $apiKey === '' || $from === '') {
        error_log('[notificaciones-email] Configuración Resend incompleta; alerta omitida.');
        return false;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('[notificaciones-email] Dirección de correo inválida; alerta omitida.');
        return false;
    }

    $alerta=hache_construir_alerta_nueva_inscripcion($alumno,$tipoIngreso,$detalle);
    try {
        return hache_resend_send($apiKey, $from, $fromName, $to, $alerta['subject'], $alerta['body']);
    } catch (Throwable $e) {
        error_log('[notificaciones-email] No se pudo enviar alerta: ' . $e->getMessage());
        return false;
    }
}

function hache_resend_send(string $apiKey, string $from, string $fromName, string $to, string $subject, string $body): bool
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extensión cURL es necesaria para enviar correos.');
    }

    $payload = json_encode([
        'from' => $fromName . ' <' . $from . '>',
        'to' => [$to],
        'subject' => $subject,
        'text' => $body,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $ch = curl_init('https://api.resend.com/emails');
    if ($ch === false) {
        throw new RuntimeException('No se pudo iniciar la conexión con Resend.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Resend no respondió: ' . $curlError);
    }

    $decoded = json_decode((string)$response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = is_array($decoded)
            ? (string)($decoded['message'] ?? $decoded['name'] ?? 'Error desconocido')
            : 'Respuesta HTTP ' . $httpCode;
        throw new RuntimeException('Resend: ' . $message);
    }

    if (!is_array($decoded) || trim((string)($decoded['id'] ?? '')) === '') {
        throw new RuntimeException('Resend no devolvió un identificador de correo.');
    }

    return true;
}
