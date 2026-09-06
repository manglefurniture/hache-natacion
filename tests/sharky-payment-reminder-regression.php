<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-payment-reminder.php';

function payment_reminder_expect(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$registrationPayload = [
    'messaging_product'=>'whatsapp',
    'recipient_type'=>'individual',
    'to'=>'529981473867',
    'type'=>'text',
    'text'=>['body'=>"✅ Registro recibido\nTu inscripción quedó pendiente de confirmación/pago.\n\n💰 Pago"],
];
payment_reminder_expect(
    hache_sharky_payment_reminder_is_registration_payload($registrationPayload),
    'The confirmed intensive registration payload must arm the payment-proof reminder.'
);

$ordinaryPayload = $registrationPayload;
$ordinaryPayload['text']['body'] = 'Aquí tienes los horarios disponibles.';
payment_reminder_expect(
    !hache_sharky_payment_reminder_is_registration_payload($ordinaryPayload),
    'Ordinary Sharky replies must not arm payment reminders.'
);

$groupPayload = $registrationPayload;
$groupPayload['_sharky_group'] = true;
payment_reminder_expect(
    !hache_sharky_payment_reminder_is_registration_payload($groupPayload),
    'Group traffic must never arm an individual payment reminder.'
);

$meta = [
    'token'=>'abc123',
    'student_id'=>'student-1',
    'course_id'=>'course-1',
    'watch_from'=>1788735600,
    'registration_sent_at'=>1788735600,
    'due_at'=>1788739200,
];
$reminder = hache_sharky_payment_reminder_payload('529981473867', $meta);
$body = (string)($reminder['text']['body'] ?? '');
payment_reminder_expect(str_contains($body, 'Si ya realizaste tu pago'), 'Reminder copy must stay conditional, not assume non-payment.');
payment_reminder_expect(str_contains($body, 'captura o comprobante'), 'Reminder must explicitly request the payment proof.');
payment_reminder_expect(($reminder['_sharky_payment_reminder']['token'] ?? '') === 'abc123', 'Reminder metadata must travel inside the encrypted outbound payload.');

$tz = new DateTimeZone(HACHE_SHARKY_PAYMENT_REMINDER_TIMEZONE);
$daytime = (new DateTimeImmutable('2026-09-06 15:00:00', $tz))->getTimestamp();
payment_reminder_expect(
    hache_sharky_payment_reminder_next_allowed_at($daytime + HACHE_SHARKY_PAYMENT_REMINDER_DELAY_SECONDS) === $daytime + HACHE_SHARKY_PAYMENT_REMINDER_DELAY_SECONDS,
    'A +60 minute reminder inside contact hours must keep the exact due time.'
);
$late = (new DateTimeImmutable('2026-09-06 21:30:00', $tz))->getTimestamp();
$nextMorning = (new DateTimeImmutable('2026-09-07 08:00:00', $tz))->getTimestamp();
payment_reminder_expect(
    hache_sharky_payment_reminder_next_allowed_at($late + HACHE_SHARKY_PAYMENT_REMINDER_DELAY_SECONDS) === $nextMorning,
    'A reminder falling after 22:00 must defer to 08:00 the next morning.'
);

$source = file_get_contents(__DIR__.'/../config/sharky-payment-reminder.php') ?: '';
payment_reminder_expect(str_contains($source, "message_type IN ('image','document')"), 'Image/PDF proof must cancel the reminder before send.');
payment_reminder_expect(str_contains($source, "tipo='INTENSIVO' AND estado='VALIDO'"), 'A valid recorded intensive payment must cancel the reminder.');
payment_reminder_expect(str_contains($source, "estado_administrativo='PENDIENTE'"), 'Resolved registrations must not receive the reminder.');
payment_reminder_expect(str_contains($source, 'HACHE_SHARKY_PAYMENT_REMINDER_DELAY_SECONDS = 3600'), 'The payment-proof delay must remain 60 minutes.');
payment_reminder_expect(str_contains($source, 'HACHE_SHARKY_PAYMENT_REMINDER_MAX_AGE_SECONDS = 86400'), 'Stale reminders must expire instead of sending days later.');

$outbox = file_get_contents(__DIR__.'/../config/sharky-outbox.php') ?: '';
payment_reminder_expect(str_contains($outbox, "require_once __DIR__.'/sharky-payment-reminder.php'"), 'Outbox must load the payment-reminder policy.');
$preparePos = strpos($outbox, 'hache_sharky_payment_reminder_prepare_registration_outbound');
$followupPos = strpos($outbox, 'hache_sharky_followup_prepare_normal_outbound');
payment_reminder_expect($preparePos !== false && $followupPos !== false && $preparePos < $followupPos, 'Registration reminder arming must happen before generic idle follow-up arming.');
payment_reminder_expect(str_contains($outbox, "!is_array(\$payload['_sharky_payment_reminder_arm']??null)"), 'Registration payloads must not also arm the generic sales follow-up.');
$gatePos = strpos($outbox, 'hache_sharky_payment_reminder_validate_before_send');
$sendPos = strpos($outbox, '$sendResult=false;');
payment_reminder_expect($gatePos !== false && $sendPos !== false && $gatePos < $sendPos, 'Payment/proof state must be revalidated immediately before delivery.');
$sentPos = strpos($outbox, 'hache_sharky_outbox_mark_sent');
$schedulePos = strpos($outbox, 'hache_sharky_payment_reminder_after_registration_sent');
payment_reminder_expect($sentPos !== false && $schedulePos !== false && $sentPos < $schedulePos, 'The +60 minute reminder may only be scheduled after the registration message is durably marked sent.');
payment_reminder_expect(str_contains($source, "'payment-proof-reminder|'.\$token"), 'Reminder scheduling must be idempotent so only one reminder is queued.');

echo "OK sharky payment reminder regression\n";
