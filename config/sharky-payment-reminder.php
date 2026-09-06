<?php

declare(strict_types=1);

/**
 * Recordatorio único de comprobante de pago para registros intensivos creados
 * por Sharky. Reutiliza el outbox cifrado/durable: no crea PII ni estado nuevo
 * en texto plano y valida de nuevo justo antes de enviar.
 */
const HACHE_SHARKY_PAYMENT_REMINDER_DELAY_SECONDS = 3600;
const HACHE_SHARKY_PAYMENT_REMINDER_MAX_AGE_SECONDS = 86400;
const HACHE_SHARKY_PAYMENT_REMINDER_TIMEZONE = 'America/Cancun';
const HACHE_SHARKY_PAYMENT_REMINDER_START_HOUR = 8;
const HACHE_SHARKY_PAYMENT_REMINDER_END_HOUR = 22;

function hache_sharky_payment_reminder_payload_body(array $payload): string
{
    if (($payload['type'] ?? '') !== 'text') return '';
    return trim((string)($payload['text']['body'] ?? ''));
}

function hache_sharky_payment_reminder_is_registration_payload(array $payload): bool
{
    if (($payload['_sharky_group'] ?? false) === true) return false;
    $body = hache_sharky_payment_reminder_payload_body($payload);
    if ($body === '') return false;
    return str_contains($body, '✅ Registro recibido')
        && str_contains($body, 'pendiente de confirmación/pago');
}

/** @return array{student_id:string,course_id:string}|null */
function hache_sharky_payment_reminder_registration_candidate(PDO $pdo, string $contact): ?array
{
    $digits = preg_replace('/\D+/', '', $contact) ?: '';
    if ($digits === '') return null;
    try {
        $st = $pdo->prepare(
            "SELECT a.id AS student_id, cia.curso_intensivo_id AS course_id
             FROM alumnos a
             JOIN curso_intensivo_alumnos cia ON cia.alumno_id=a.id
             WHERE a.whatsapp=:w AND a.estado_administrativo='PENDIENTE'
             LIMIT 1"
        );
        $st->execute([':w' => '+'.$digits]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $studentId = trim((string)($row['student_id'] ?? ''));
        $courseId = trim((string)($row['course_id'] ?? ''));
        return $studentId !== '' && $courseId !== '' ? ['student_id'=>$studentId,'course_id'=>$courseId] : null;
    } catch (Throwable $e) {
        error_log('[sharky-payment-reminder] registration lookup failed');
        return null;
    }
}

function hache_sharky_payment_reminder_prepare_registration_outbound(
    PDO $pdo,
    string $contact,
    array $payload,
    string $dedupeSeed,
    ?int $now = null
): array {
    if (is_array($payload['_sharky_payment_reminder_arm'] ?? null)
        || is_array($payload['_sharky_payment_reminder'] ?? null)
        || !hache_sharky_payment_reminder_is_registration_payload($payload)) return $payload;

    $candidate = hache_sharky_payment_reminder_registration_candidate($pdo, $contact);
    if (!is_array($candidate)) return $payload;
    $now ??= time();
    $token = substr(hash('sha256', implode('|', [
        'payment-proof-reminder-v1',
        hache_sharky_orchestrator_contact_hash($contact),
        $candidate['student_id'],
        $candidate['course_id'],
        $dedupeSeed,
    ])), 0, 40);
    $payload['_sharky_payment_reminder_arm'] = [
        'token' => $token,
        'student_id' => $candidate['student_id'],
        'course_id' => $candidate['course_id'],
        'watch_from' => $now,
    ];
    return $payload;
}

function hache_sharky_payment_reminder_next_allowed_at(int $timestamp): int
{
    $tz = new DateTimeZone(HACHE_SHARKY_PAYMENT_REMINDER_TIMEZONE);
    $local = (new DateTimeImmutable('@'.$timestamp))->setTimezone($tz);
    $hour = (int)$local->format('G');
    if ($hour < HACHE_SHARKY_PAYMENT_REMINDER_START_HOUR) {
        return $local->setTime(HACHE_SHARKY_PAYMENT_REMINDER_START_HOUR, 0, 0)->getTimestamp();
    }
    if ($hour >= HACHE_SHARKY_PAYMENT_REMINDER_END_HOUR) {
        return $local->modify('+1 day')->setTime(HACHE_SHARKY_PAYMENT_REMINDER_START_HOUR, 0, 0)->getTimestamp();
    }
    return $timestamp;
}

function hache_sharky_payment_reminder_send_allowed_now(int $timestamp): bool
{
    $local = (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone(HACHE_SHARKY_PAYMENT_REMINDER_TIMEZONE));
    $hour = (int)$local->format('G');
    return $hour >= HACHE_SHARKY_PAYMENT_REMINDER_START_HOUR && $hour < HACHE_SHARKY_PAYMENT_REMINDER_END_HOUR;
}

function hache_sharky_payment_reminder_payload(string $contact, array $meta): array
{
    return [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $contact,
        'type' => 'text',
        'text' => [
            'body' => '💳 Si ya realizaste tu pago, por favor comparte la captura o comprobante en este chat para que podamos confirmar tu inscripción. 😊',
        ],
        '_sharky_payment_reminder' => $meta,
    ];
}

function hache_sharky_payment_reminder_after_registration_sent(PDO $pdo, string $contact, array $meta, ?int $now = null): void
{
    $token = trim((string)($meta['token'] ?? ''));
    $studentId = trim((string)($meta['student_id'] ?? ''));
    $courseId = trim((string)($meta['course_id'] ?? ''));
    $watchFrom = (int)($meta['watch_from'] ?? 0);
    if ($token === '' || $studentId === '' || $courseId === '') return;
    $now ??= time();
    if ($watchFrom <= 0) $watchFrom = $now;
    $due = hache_sharky_payment_reminder_next_allowed_at($now + HACHE_SHARKY_PAYMENT_REMINDER_DELAY_SECONDS);
    $reminderMeta = [
        'token' => $token,
        'student_id' => $studentId,
        'course_id' => $courseId,
        'watch_from' => $watchFrom,
        'registration_sent_at' => $now,
        'due_at' => $due,
    ];
    $payload = hache_sharky_payment_reminder_payload($contact, $reminderMeta);
    if (!hache_sharky_outbox_enqueue_raw($pdo, $contact, $payload, 'payment-proof-reminder|'.$token, $due)) {
        error_log('[sharky-payment-reminder] schedule failed');
    }
}

function hache_sharky_payment_reminder_registration_pending(PDO $pdo, string $studentId, string $courseId): bool
{
    try {
        $st = $pdo->prepare(
            "SELECT 1
             FROM alumnos a
             JOIN curso_intensivo_alumnos cia ON cia.alumno_id=a.id
             WHERE a.id=:a AND cia.curso_intensivo_id=:c AND a.estado_administrativo='PENDIENTE'
             LIMIT 1"
        );
        $st->execute([':a'=>$studentId, ':c'=>$courseId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function hache_sharky_payment_reminder_payment_confirmed(PDO $pdo, string $studentId, string $courseId): bool
{
    try {
        $st = $pdo->prepare(
            "SELECT 1 FROM pagos
             WHERE alumno_id=:a AND intensivo_id=:c AND tipo='INTENSIVO' AND estado='VALIDO'
             LIMIT 1"
        );
        $st->execute([':a'=>$studentId, ':c'=>$courseId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function hache_sharky_payment_reminder_proof_received(PDO $pdo, string $contact, int $watchFrom): bool
{
    if ($watchFrom <= 0) return false;
    try {
        $st = $pdo->prepare(
            "SELECT 1 FROM sharky_message_receipts
             WHERE contact_hash=:c
               AND message_type IN ('image','document')
               AND received_at>=FROM_UNIXTIME(:w)
             LIMIT 1"
        );
        $st->execute([
            ':c'=>hache_sharky_orchestrator_contact_hash($contact),
            ':w'=>$watchFrom,
        ]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array{ok:bool,reason?:string,reschedule_at?:int} */
function hache_sharky_payment_reminder_validate_before_send(PDO $pdo, string $contact, array $meta, ?int $now = null): array
{
    $now ??= time();
    $token = trim((string)($meta['token'] ?? ''));
    $studentId = trim((string)($meta['student_id'] ?? ''));
    $courseId = trim((string)($meta['course_id'] ?? ''));
    $watchFrom = (int)($meta['watch_from'] ?? 0);
    $registrationSentAt = (int)($meta['registration_sent_at'] ?? 0);
    if ($token === '' || $studentId === '' || $courseId === '' || $watchFrom <= 0 || $registrationSentAt <= 0) {
        return ['ok'=>false, 'reason'=>'INVALID_PAYMENT_REMINDER_META'];
    }
    if ($now > $registrationSentAt + HACHE_SHARKY_PAYMENT_REMINDER_MAX_AGE_SECONDS) {
        return ['ok'=>false, 'reason'=>'PAYMENT_REMINDER_EXPIRED'];
    }
    if (!hache_sharky_payment_reminder_registration_pending($pdo, $studentId, $courseId)) {
        return ['ok'=>false, 'reason'=>'REGISTRATION_RESOLVED'];
    }
    if (hache_sharky_payment_reminder_payment_confirmed($pdo, $studentId, $courseId)) {
        return ['ok'=>false, 'reason'=>'PAYMENT_ALREADY_CONFIRMED'];
    }
    if (hache_sharky_payment_reminder_proof_received($pdo, $contact, $watchFrom)) {
        return ['ok'=>false, 'reason'=>'PAYMENT_PROOF_RECEIVED'];
    }
    if (!hache_sharky_payment_reminder_send_allowed_now($now)) {
        return [
            'ok'=>false,
            'reason'=>'PAYMENT_REMINDER_QUIET_HOURS',
            'reschedule_at'=>hache_sharky_payment_reminder_next_allowed_at($now),
        ];
    }
    return ['ok'=>true];
}
