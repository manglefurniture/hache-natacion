<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';
require_once __DIR__.'/sharky-commerce-flows.php';

/**
 * Only normalized commerce Flow replies and the explicit fallback payment buttons
 * enter this path. General student traffic keeps the existing human-handoff rule.
 */
function hache_sharky_commerce_event_candidate(array $event): bool
{
    if (is_array($event['commerce'] ?? null)) return true;
    $id = strtolower(trim((string)($event['interactive_id'] ?? '')));
    return str_starts_with($id, 'commerce:pay:');
}

function hache_sharky_commerce_retry_path(string $key): string
{
    $dir = hache_sharky_whatsapp_birthdate_flow_cache_dir();
    $safe = preg_replace('/[^a-z0-9_-]+/i', '-', $key) ?: '';
    return $dir === '' || $safe === '' ? '' : $dir.'/commerce-'.$safe.'.retry';
}

function hache_sharky_commerce_retry_allowed(string $key, int $now): bool
{
    $path = hache_sharky_commerce_retry_path($key);
    if ($path === '' || !is_file($path)) return true;
    $mtime = (int)@filemtime($path);
    return $mtime <= 0 || $mtime <= $now - 900;
}

function hache_sharky_commerce_mark_retry(string $key, int $now): void
{
    $path = hache_sharky_commerce_retry_path($key);
    if ($path === '') return;
    @file_put_contents($path, (string)$now, LOCK_EX);
    @touch($path, $now);
    @chmod($path, 0600);
}

function hache_sharky_commerce_clear_retry(string $key): void
{
    $path = hache_sharky_commerce_retry_path($key);
    if ($path !== '') @unlink($path);
}

/**
 * Post-ACK Flow provisioning with bounded latency. Cached/pinned Flows are free;
 * at most one unresolved Flow may touch Graph per webhook. A failed key sleeps
 * for 15 minutes so missing management permission cannot starve message handling.
 */
function hache_sharky_commerce_flows_prime_throttled(
    array $payload,
    ?callable $secretResolver = null,
    ?int $now = null
): array {
    $waba = hache_sharky_whatsapp_birthdate_flow_first_waba($payload);
    if ($waba === '') return [];
    $secretResolver ??= static fn(string $name):string => hache_sharky_whatsapp_flow_secret($name);
    $now ??= time();
    $ready = [];
    $networkAttempted = false;

    foreach (hache_sharky_commerce_flow_specs() as $key=>$spec) {
        $configured = preg_replace('/\D+/', '', (string)$secretResolver((string)($spec['env'] ?? ''))) ?: '';
        if ($configured !== '') {
            $ready[(string)$key] = $configured;
            hache_sharky_commerce_clear_retry((string)$key);
            continue;
        }
        $cached = hache_sharky_commerce_flow_cached_id((string)$key);
        if ($cached !== null) {
            $ready[(string)$key] = $cached;
            hache_sharky_commerce_clear_retry((string)$key);
            continue;
        }
        if ($networkAttempted || !hache_sharky_commerce_retry_allowed((string)$key, $now)) continue;

        $networkAttempted = true;
        try {
            $id = hache_sharky_commerce_flow_ensure((string)$key, $waba, $secretResolver);
            if ($id !== null) {
                $ready[(string)$key] = $id;
                hache_sharky_commerce_clear_retry((string)$key);
            } else {
                hache_sharky_commerce_mark_retry((string)$key, $now);
            }
        } catch (Throwable $e) {
            hache_sharky_commerce_mark_retry((string)$key, $now);
            error_log('[sharky-commerce-flow] provisioning unavailable key='.(string)$key);
        }
    }
    return $ready;
}

/**
 * Normalize legacy and bound fallback payment buttons into the same commerce
 * shape as a WhatsApp Flow nfm_reply. Bound ids are URL-encoded to preserve case.
 */
function hache_sharky_commerce_bind_fallback_event(array $event): array
{
    if (is_array($event['commerce'] ?? null)) return $event;
    $raw = trim((string)($event['interactive_id'] ?? ''));
    if (preg_match('/^commerce:pay:(transfer|card|cash)(?::([^:]+):([^:]+))?$/i', $raw, $m) !== 1) return $event;
    $commerce = [
        'flow_kind'=>'payment_method',
        'method'=>strtolower((string)$m[1]),
    ];
    if (isset($m[2], $m[3]) && $m[2] !== '' && $m[3] !== '') {
        $commerce['student_id'] = rawurldecode((string)$m[2]);
        $commerce['course_id'] = rawurldecode((string)$m[3]);
    }
    $event['commerce'] = $commerce;
    return $event;
}

/**
 * null = legacy unbound fallback button already sent before this hardening;
 * true = exact current registration; false = stale/malformed selector.
 */
function hache_sharky_commerce_payment_binding_matches(PDO $pdo, string $contact, array $event): ?bool
{
    $commerce = is_array($event['commerce'] ?? null) ? $event['commerce'] : [];
    if (strtolower(trim((string)($commerce['flow_kind'] ?? ''))) !== 'payment_method') return null;
    $studentId = trim((string)($commerce['student_id'] ?? ''));
    $courseId = trim((string)($commerce['course_id'] ?? ''));
    if ($studentId === '' && $courseId === '') {
        // Only an explicit fallback button id gets legacy compatibility. An
        // nfm_reply without its server-injected binding is malformed/stale and
        // must never be allowed to select a method for whatever registration is
        // currently pending under the same phone number.
        return trim((string)($event['interactive_id'] ?? '')) !== '' ? null : false;
    }
    if ($studentId === '' || $courseId === '') return false;
    $current = hache_sharky_commerce_pending_registration($pdo, $contact);
    if (!is_array($current)) return false;
    return hash_equals((string)$current['student_id'], $studentId)
        && hash_equals((string)$current['course_id'], $courseId);
}

function hache_sharky_commerce_stale_payment_result(PDO $pdo, string $contact, array $state, array $business): array
{
    $current = hache_sharky_commerce_pending_registration($pdo, $contact);
    if (is_array($current)) {
        $message = 'Ese selector de pago pertenece a otra inscripción. No hice ningún cambio; te muestro las opciones vigentes.';
        $decision = hache_sharky_orchestrator_decision('payment_method_stale', $message);
        return [
            'state'=>$state,
            'decision'=>$decision,
            'payload'=>hache_sharky_commerce_payment_method_payload($contact, $current, $business, false),
            'action_result'=>null,
        ];
    }
    $message = 'Ese selector de pago ya no coincide de forma segura con una inscripción pendiente. Te dejo con el equipo para revisarlo.';
    $decision = hache_sharky_orchestrator_decision('payment_method_stale', $message, [], ['type'=>'human_takeover']);
    return [
        'state'=>$state,
        'decision'=>$decision,
        'payload'=>hache_sharky_whatsapp_text_payload($contact, $message),
        'action_result'=>['ok'=>true,'code'=>'HANDOFF'],
    ];
}

function hache_sharky_commerce_bound_button_id(string $method, string $studentId, string $courseId): string
{
    return 'commerce:pay:'.$method.':'.rawurlencode($studentId).':'.rawurlencode($courseId);
}

/**
 * Converts commerce-only scheduling metadata to the established encrypted
 * payment-reminder contract before the payload reaches the outbox. It also binds
 * payment selectors to the exact pending registration that created them.
 */
function hache_sharky_commerce_prepare_payload(array $payload): array
{
    $session = is_array($payload['_sharky_payment_session'] ?? null)
        ? $payload['_sharky_payment_session']
        : null;
    $studentId = trim((string)($session['student_id'] ?? ''));
    $courseId = trim((string)($session['course_id'] ?? ''));

    if ($studentId !== '' && $courseId !== '' && ($payload['type'] ?? '') === 'interactive') {
        $interactiveType = (string)($payload['interactive']['type'] ?? '');
        if ($interactiveType === 'flow'
            && (string)($payload['interactive']['action']['parameters']['flow_action_payload']['screen'] ?? '') === 'PAYMENT_METHOD') {
            if (!is_array($payload['interactive']['action']['parameters']['flow_action_payload']['data'] ?? null)) {
                $payload['interactive']['action']['parameters']['flow_action_payload']['data'] = [];
            }
            $payload['interactive']['action']['parameters']['flow_action_payload']['data']['student_id'] = $studentId;
            $payload['interactive']['action']['parameters']['flow_action_payload']['data']['course_id'] = $courseId;
        } elseif ($interactiveType === 'button') {
            foreach (($payload['interactive']['action']['buttons'] ?? []) as $index=>$button) {
                $id = trim((string)($button['reply']['id'] ?? ''));
                if (preg_match('/^commerce:pay:(transfer|card|cash)$/i', $id, $m) !== 1) continue;
                $payload['interactive']['action']['buttons'][$index]['reply']['id'] = hache_sharky_commerce_bound_button_id(
                    strtolower((string)$m[1]),
                    $studentId,
                    $courseId
                );
            }
        }
    }

    $arm = is_array($payload['_sharky_mp_followup_arm'] ?? null)
        ? $payload['_sharky_mp_followup_arm']
        : null;
    if (is_array($arm)) {
        $armStudent = trim((string)($arm['student_id'] ?? ''));
        $armCourse = trim((string)($arm['course_id'] ?? ''));
        $contact = preg_replace('/\D+/', '', (string)($payload['to'] ?? '')) ?: '';
        if ($contact !== '' && $armStudent !== '' && $armCourse !== '') {
            // One follow-up per registration, even if the user opens card twice and
            // Mercado Pago returns a second preference id.
            $arm['token'] = substr(hash('sha256', implode('|', [
                'mp-followup-v2',
                hache_sharky_orchestrator_contact_hash($contact),
                $armStudent,
                $armCourse,
            ])), 0, 40);
        }
        $arm['mode'] = 'mp_card';
        $payload['_sharky_payment_reminder_arm'] = $arm;
        unset($payload['_sharky_mp_followup_arm']);
    }

    // This marker is useful only while building the commerce response; Meta must
    // never receive Sharky-internal metadata.
    unset($payload['_sharky_payment_session']);
    return $payload;
}

/** Final network-bound cleanup. */
function hache_sharky_commerce_finalize_payload(array $payload): array
{
    unset($payload['_sharky_payment_session'], $payload['_sharky_mp_followup_arm']);
    return $payload;
}

/**
 * Durable commerce-event processor. It intentionally runs before the normal
 * "known student -> human" shortcut because a newly registered prospect is now
 * a known student precisely when the payment Flow reply arrives.
 */
function hache_sharky_commerce_process_event(
    PDO $pdo,
    array $event,
    array $business,
    ?int $minAge = null
): bool {
    if (!hache_sharky_commerce_event_candidate($event)) return false;
    $event = hache_sharky_commerce_bind_fallback_event($event);

    $contact = preg_replace('/\D+/', '', (string)($event['from'] ?? '')) ?: '';
    $eventId = trim((string)($event['id'] ?? ''));
    if ($contact === '' || $eventId === '') return false;

    $configured = function_exists('hache_sharky_lab_secret')
        ? hache_sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID')
        : '';
    $eventPhoneId = trim((string)($event['phone_number_id'] ?? ''));
    if ($configured !== '' && $eventPhoneId !== '' && !hash_equals($configured, $eventPhoneId)) {
        if (function_exists('hache_sharky_lab_claim_early')
            && hache_sharky_lab_claim_early($pdo, $event, $contact, (string)($event['kind'] ?? 'commerce_flow'))) {
            hache_sharky_orchestrator_mark_processed($pdo, $eventId);
        }
        return true;
    }

    // Commerce Flows are direct-chat only. A copied/historical group event is
    // acknowledged fail-closed and never reaches OpenAI or business logic.
    if (trim((string)($event['group_id'] ?? '')) !== '') {
        if (hache_sharky_lab_claim_early($pdo, $event, $contact, (string)($event['kind'] ?? 'commerce_flow'))) {
            hache_sharky_orchestrator_mark_processed($pdo, $eventId);
        }
        return true;
    }

    $deliveryLock = hache_sharky_orchestrator_delivery_lock($contact);
    if (!is_resource($deliveryLock)) return false;

    $brainBefore = null;
    try {
        try { $brainBefore = hache_sharky_db_state_load($pdo, $contact); }
        catch (Throwable $e) {
            if (function_exists('hache_sharky_metric_increment')) {
                hache_sharky_metric_increment('brain_shadow_error');
                hache_sharky_metric_increment('brain_diag_error');
            }
            error_log('[sharky-commerce] unable to load pre-turn state');
        }

        $handoffPending = function_exists('hache_sharky_inbox_handoff_pending')
            ? hache_sharky_inbox_handoff_pending($pdo, $eventId)
            : false;
        if (function_exists('hache_sharky_takeover_active')
            && hache_sharky_takeover_active($contact)
            && !$handoffPending) {
            if (!hache_sharky_lab_claim_early($pdo, $event, $contact, (string)($event['kind'] ?? 'commerce_flow'))) return false;
            return hache_sharky_orchestrator_mark_processed($pdo, $eventId);
        }

        $minAge ??= function_exists('hache_sharky_config_int')
            ? hache_sharky_config_int($business, 'sharky_edad_minima', 12, 1, 99)
            : 12;
        $today = function_exists('hache_sharky_lab_today')
            ? hache_sharky_lab_today()
            : (new DateTimeImmutable('today', new DateTimeZone('America/Cancun')))->format('Y-m-d');
        $now = time();

        hache_sharky_db_state_defer_begin();
        try {
            if (!hache_sharky_lab_claim_early($pdo, $event, $contact, (string)($event['kind'] ?? 'commerce_flow'))) {
                hache_sharky_db_state_defer_cancel();
                return false;
            }
            $state = hache_sharky_db_state_load($pdo, $contact);
            $state = hache_sharky_orchestrator_expire_flow($state, $now);
            $context = hache_sharky_whatsapp_context($pdo, $contact, [
                'min_age'=>$minAge,
                'today'=>$today,
                'now'=>$now,
            ]);
            $binding = hache_sharky_commerce_payment_binding_matches($pdo, $contact, $event);
            $result = $binding === false
                ? hache_sharky_commerce_stale_payment_result($pdo, $contact, $state, $business)
                : hache_sharky_commerce_handle_event($pdo, $state, $event, $context, $business);
            if (!is_array($result)) {
                hache_sharky_db_state_defer_cancel();
                return hache_sharky_orchestrator_mark_processed($pdo, $eventId);
            }
            $state = is_array($result['state'] ?? null) ? $result['state'] : $state;
            hache_sharky_db_state_save($pdo, $contact, $state);
            $deferredState = hache_sharky_db_state_defer_take();
        } catch (Throwable $e) {
            hache_sharky_db_state_defer_cancel();
            throw $e;
        }

        if (is_array($brainBefore) && function_exists('hache_sharky_brain_shadow_observe')) {
            hache_sharky_brain_shadow_observe($brainBefore, $state, $event, $result, true);
        }

        $decision = is_array($result['decision'] ?? null) ? $result['decision'] : [];
        $action = is_array($decision['action'] ?? null) ? $decision['action'] : null;
        $shouldTakeover = is_array($action) && ($action['type'] ?? '') === 'human_takeover';
        $out = is_array($result['payload'] ?? null) ? $result['payload'] : null;

        if ($shouldTakeover) {
            if (!hache_sharky_lab_mark_handoff_pending($pdo, $eventId)) return false;
            if (!hache_sharky_takeover_mark($contact, 'commerce_flow_handoff', 'Sharky commerce Flow requested human takeover')) {
                error_log('[sharky-commerce] takeover persistence failed');
                return false;
            }
            if (is_array($out)) $out = hache_sharky_outbox_allow_during_takeover($out);
        }

        if (is_array($out)) {
            $out = hache_sharky_commerce_prepare_payload($out);
            $payloadHash = hash('sha256', json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '');
            return hache_sharky_lab_queue_and_complete(
                $pdo,
                $contact,
                $out,
                $eventId.'|'.(string)($decision['kind'] ?? 'commerce').'|'.$payloadHash,
                $eventId,
                [],
                $deferredState
            );
        }
        return hache_sharky_lab_complete_without_outbox($pdo, $eventId, [], $deferredState);
    } catch (Throwable $e) {
        error_log('[sharky-commerce] event processing failed');
        return false;
    } finally {
        hache_sharky_lab_release_delivery_lock($deliveryLock);
    }
}
