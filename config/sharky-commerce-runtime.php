<?php

declare(strict_types=1);

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

/**
 * Converts commerce-only scheduling metadata to the established encrypted
 * payment-reminder contract before the payload reaches the outbox.
 */
function hache_sharky_commerce_prepare_payload(array $payload): array
{
    $arm = is_array($payload['_sharky_mp_followup_arm'] ?? null)
        ? $payload['_sharky_mp_followup_arm']
        : null;
    if (is_array($arm)) {
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
            $result = hache_sharky_commerce_handle_event($pdo, $state, $event, $context, $business);
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
