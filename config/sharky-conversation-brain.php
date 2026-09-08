<?php

declare(strict_types=1);

/**
 * Sharky 3.0 Conversation Brain — shadow policy v3.
 *
 * This module is deliberately pure: it does not send messages, persist state,
 * call OpenAI, touch payments or execute business actions. It receives the
 * durable before/after state plus explicit signals from the live pipeline and
 * returns the next-best-action that SHOULD win according to one precedence
 * table. During shadow mode the live observer and Quality compare this
 * recommendation against existing production behaviour before the Brain is
 * allowed to route live.
 *
 * v3 adds the current member-service reality: identified students are served by
 * Sharky when member-ops is available, Palapas is restricted, pending records do
 * not receive active-student controls, and teacher-owned turns stay deterministic.
 */

const HACHE_SHARKY_BRAIN_VERSION = '3.0-shadow-v3';

/** @return list<string> */
function hache_sharky_brain_precedence(): array
{
    return [
        'wait_for_human',
        'serve_teacher',
        'serve_pending_student',
        'serve_palapas_restricted',
        'serve_known_student',
        'handoff_known_student',
        'close_age_scope',
        'pause_commercial_intent',
        'handoff_policy_exception',
        'answer_side_question',
        'continue_controlled_flow',
        'preserve_deterministic_decision',
        'start_guided_qualification',
        'show_commercial_menu',
        'ask_identity',
        'continue_discovery',
        'answer_user',
    ];
}

function hache_sharky_brain_commercial_ready(array $state): bool
{
    if (($state['identity']['kind'] ?? '') !== 'prospect') return false;
    $commercial = is_array($state['commercial_context'] ?? null) ? $state['commercial_context'] : [];
    return in_array(($commercial['program'] ?? null), ['intensive', 'regular'], true)
        && in_array(($commercial['sede_clave'] ?? null), ['MONTEVERDE', 'PALAPAS'], true);
}

function hache_sharky_brain_followup_paused(array $state): bool
{
    $commercial = is_array($state['commercial_context'] ?? null) ? $state['commercial_context'] : [];
    $followup = is_array($commercial['_idle_followup'] ?? null) ? $commercial['_idle_followup'] : [];
    return ($followup['status'] ?? '') === 'completed_optout';
}

/** @return array<string,mixed> */
function hache_sharky_brain_snapshot(array $state): array
{
    $identity = is_array($state['identity'] ?? null) ? $state['identity'] : [];
    $commercial = is_array($state['commercial_context'] ?? null) ? $state['commercial_context'] : [];
    $flow = is_array($state['flow'] ?? null) ? $state['flow'] : [];

    $kind = (string)($identity['kind'] ?? 'unknown');
    if (!in_array($kind, ['unknown', 'prospect', 'student'], true)) $kind = 'unknown';
    $program = in_array(($commercial['program'] ?? null), ['intensive', 'regular'], true)
        ? (string)$commercial['program'] : null;
    $venue = in_array(($commercial['sede_clave'] ?? null), ['MONTEVERDE', 'PALAPAS'], true)
        ? (string)$commercial['sede_clave'] : null;
    $identityVenue = strtoupper(trim((string)($identity['sede_clave'] ?? '')));
    if (!in_array($identityVenue, ['MONTEVERDE', 'PALAPAS'], true)) $identityVenue = null;
    $identityStatus = strtoupper(trim((string)($identity['status'] ?? '')));
    if ($identityStatus === '') $identityStatus = null;
    $swim = in_array(($commercial['swim_level'] ?? null), ['beginner', 'swims'], true)
        ? (string)$commercial['swim_level'] : null;
    $age = is_int($commercial['age'] ?? null) ? (int)$commercial['age'] : null;
    $flowName = trim((string)($flow['name'] ?? '')) ?: null;
    $flowStep = trim((string)($flow['step'] ?? '')) ?: null;

    $phase = 'identity';
    if ($kind === 'student') $phase = 'student';
    elseif ($flowName !== null) $phase = 'controlled_flow';
    elseif (hache_sharky_brain_commercial_ready($state)) $phase = 'commercial_ready';
    elseif ($kind === 'prospect') $phase = 'discovery';

    return [
        'version' => HACHE_SHARKY_BRAIN_VERSION,
        'phase' => $phase,
        'identity_kind' => $kind,
        'identity_verified' => ($identity['verified'] ?? false) === true,
        'identity_sede_clave' => $identityVenue,
        'identity_status' => $identityStatus,
        'program' => $program,
        'sede_clave' => $venue,
        'age' => $age,
        'swim_level' => $swim,
        'flow_name' => $flowName,
        'flow_step' => $flowStep,
        'commercial_ready' => hache_sharky_brain_commercial_ready($state),
        'followup_paused' => hache_sharky_brain_followup_paused($state),
    ];
}

/** @return array{action:string,reason:string,route:string,version:string,before:array<string,mixed>,after:array<string,mixed>} */
function hache_sharky_brain_next_best_action(array $beforeState, array $afterState, array $event = [], array $signals = []): array
{
    $before = hache_sharky_brain_snapshot($beforeState);
    $after = hache_sharky_brain_snapshot($afterState);
    $decisionKind = trim((string)($signals['decision_kind'] ?? 'conversation'));
    $directChat = ($signals['direct_chat'] ?? true) === true;
    $knownStudent = ($signals['known_student'] ?? false) === true || ($after['identity_kind'] ?? '') === 'student';
    $memberService = ($signals['member_service_available'] ?? false) === true;

    $select = static function (string $action, string $reason, string $route) use ($before, $after): array {
        return [
            'action' => $action,
            'reason' => $reason,
            'route' => $route,
            'version' => HACHE_SHARKY_BRAIN_VERSION,
            'before' => $before,
            'after' => $after,
        ];
    };

    if (($signals['human_takeover_active'] ?? false) === true) {
        return $select('wait_for_human', 'human_takeover_active', 'silent');
    }

    // Member service is a protected deterministic lane. Brain chooses WHO owns
    // the turn; the existing member modules remain the only executors of money,
    // attendance, absence and teacher mutations.
    if ($memberService && ($signals['teacher_member_event'] ?? false) === true) {
        return $select('serve_teacher', 'teacher_owned_member_event', 'member');
    }
    if ($memberService && $knownStudent && ($signals['member_pending'] ?? false) === true) {
        return $select('serve_pending_student', 'identified_registration_is_pending', 'member');
    }
    if ($memberService && $knownStudent && ($signals['palapas_restricted'] ?? false) === true) {
        return $select('serve_palapas_restricted', 'palapas_red_light_policy', 'member');
    }
    if ($memberService && $knownStudent) {
        return $select('serve_known_student', 'known_student_self_service_available', 'member');
    }

    // Safe fallback: if the member-service lane is unavailable, an identified
    // student may still be handed to a person instead of being treated as a lead.
    if ($directChat && $knownStudent) {
        return $select('handoff_known_student', 'known_student_member_service_unavailable', 'human');
    }

    // Preserve the established prospect-policy precedence from v2. v3 changes
    // member ownership, not the already-tested commercial rules below it.
    if (($signals['family_age_unavailable'] ?? false) === true) {
        return $select('close_age_scope', 'baby_or_maternal_swim_out_of_scope', 'deterministic');
    }
    if (($signals['pause_eligible'] ?? false) === true) {
        return $select('pause_commercial_intent', 'user_requested_eligible_pause', 'deterministic');
    }
    if (($signals['policy_handoff_required'] ?? false) === true) {
        return $select('handoff_policy_exception', 'business_policy_requires_human', 'human');
    }

    // A side-question may interrupt a controlled flow only when the live turn is
    // explicitly conversational. A heuristic alone can never override a concrete
    // deterministic decision.
    if ($decisionKind === 'conversation' && ($signals['side_question'] ?? false) === true) {
        return $select('answer_side_question', 'informational_interrupt_preserves_flow', 'conversation');
    }

    if (($after['flow_name'] ?? null) !== null) {
        return $select('continue_controlled_flow', 'controlled_flow_is_active', 'deterministic');
    }

    // Outside a controlled flow, a concrete orchestrator decision is already
    // safer/more specific than any conversational heuristic and remains protected.
    if ($decisionKind !== '' && $decisionKind !== 'conversation' && $decisionKind !== 'member_route') {
        return $select('preserve_deterministic_decision', 'orchestrator_decision_is_authoritative', 'deterministic');
    }

    if (($before['identity_kind'] ?? 'unknown') === 'unknown'
        && ($after['identity_kind'] ?? 'unknown') === 'prospect') {
        return $select('start_guided_qualification', 'identity_transitioned_to_prospect', 'guided');
    }

    if (($after['commercial_ready'] ?? false) === true) {
        if (($signals['low_information'] ?? false) === true) {
            return $select('show_commercial_menu', 'commercial_context_ready_on_reengagement', 'commercial');
        }
        if (($before['commercial_ready'] ?? false) !== true
            && ($signals['turn_discovery_only'] ?? false) === true) {
            return $select('show_commercial_menu', 'commercial_context_just_completed', 'commercial');
        }
    }

    if (($after['identity_kind'] ?? 'unknown') === 'unknown') {
        if ($directChat && ($signals['default_prospect_if_unmatched'] ?? false) === true) {
            return $select('start_guided_qualification', 'unmatched_direct_contact_defaults_to_prospect', 'guided');
        }
        return $select('ask_identity', 'identity_is_unknown', 'guided');
    }

    if (($after['identity_kind'] ?? 'unknown') === 'prospect'
        && ($after['commercial_ready'] ?? false) !== true) {
        return $select('continue_discovery', 'prospect_context_incomplete', 'guided');
    }

    return $select('answer_user', 'no_higher_priority_policy_matched', 'conversation');
}

function hache_sharky_brain_shadow_matches(string $liveAction, array $brainDecision): bool
{
    return trim($liveAction) !== '' && trim($liveAction) === (string)($brainDecision['action'] ?? '');
}
