<?php

declare(strict_types=1);

function meta3_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$root=dirname(__DIR__);
$meta=file_get_contents($root.'/config/sharky-meta-ad-flow.php')?:'';
$entry=file_get_contents($root.'/config/sharky-entry-guidance.php')?:'';
$worker=file_get_contents($root.'/config/sharky-lab-worker.php')?:'';
$webhook=file_get_contents($root.'/public/api/whatsapp-orchestrator-lab.php')?:'';
$recovery=file_get_contents($root.'/bin/sharky-inbox-dispatch.php')?:'';
$regular=file_get_contents($root.'/config/sharky-regular-enrollment.php')?:'';
$flowRaw=file_get_contents($root.'/config/whatsapp-flows/regular-enrollment-v1.json')?:'';
$flow=json_decode($flowRaw,true);
$business=file_get_contents($root.'/config/sharky-business-actions.php')?:'';
$agePolicy=file_get_contents($root.'/config/sharky-age-policy.php')?:'';
$outbox=file_get_contents($root.'/config/sharky-outbox.php')?:'';
$spec=file_get_contents($root.'/docs/SHARKY-3-META-FLOW.md')?:'';

meta3_expect(str_contains($entry,"'entry_source'=>'meta_ad'")||str_contains($entry,"'entry_source' => 'meta_ad'"),'Meta referral must be tagged as meta_ad.');
meta3_expect(str_contains($entry,'HACHE_SHARKY_META_FLOW')||str_contains($entry,"'meta_ad_onboarding'"),'Meta referrals must bootstrap the dedicated state machine.');
meta3_expect(str_contains($meta,"(\$state['commercial_context']['entry_source']??'')!=='meta_ad'"),'Meta handler must reject non-Meta sources.');
meta3_expect(str_contains($meta,"(\$state['identity']['kind']??'unknown')!=='prospect'"),'Known/non-prospect identities must not enter the Meta funnel.');
meta3_expect(str_contains($meta,'hache_sharky_meta_program_retry()')&&str_contains($meta,'hache_sharky_meta_venue_retry($state)'),'Closed steps must retry deterministic buttons instead of free-text interpretation.');
meta3_expect(str_contains($meta,'hache_sharky_meta_program_ui(false)')&&str_contains($meta,'hache_sharky_meta_venue_ui(false)'),'Retries must omit visual cards.');
meta3_expect(str_contains($meta,'meta:regular:no')&&str_contains($meta,"recommended_program']='intensive'"),'Regular background No must route directly to intensive.');
meta3_expect(str_contains($meta,'meta:venue:other')&&str_contains($meta,"commercial_context']['program'"),'Venue reselection must preserve the selected program in commercial context.');
meta3_expect(str_contains($meta,'HACHE_SHARKY_META_IMAGE_LEARN')&&str_contains($meta,'HACHE_SHARKY_META_IMAGE_REGULAR')&&str_contains($meta,'HACHE_SHARKY_META_IMAGE_MONTEVERDE')&&str_contains($meta,'HACHE_SHARKY_META_IMAGE_PALAPAS'),'All four approved visual asset routes must be wired.');
meta3_expect(str_contains($outbox,"'_sharky_sequence'")&&str_contains($outbox,"'|sequence|'"),'Image sequences must use per-item deterministic outbox dedupe keys.');

meta3_expect(str_contains($worker,'$metaResultLocked')&&str_contains($worker,'if(!$metaResultLocked)')&&str_contains($worker,'hache_sharky_brain_2ba_apply'),'Brain 2B-A must remain available globally but be bypassed for deterministic Meta results.');
meta3_expect(str_contains($worker,'if(!$metaDeterministic&&$text!=='),'Legacy free-text early handoff policy must not preempt closed Meta steps.');

meta3_expect(str_contains($webhook,'hache_sharky_regular_flow_extract_events($payload)'),'Webhook must extract regular enrollment Flow replies.');
meta3_expect(str_contains($webhook,'===HACHE_SHARKY_REGULAR_FLOW_KIND')&&str_contains($webhook,'hache_sharky_regular_enrollment_process'),'Webhook must process regular enrollment before generic Sharky routing.');
meta3_expect(str_contains($recovery,'===HACHE_SHARKY_REGULAR_FLOW_KIND')&&str_contains($recovery,'hache_sharky_regular_enrollment_process'),'Recovery worker must process durable regular Flow replies.');
meta3_expect(str_contains($webhook,'hache_sharky_regular_flow_prime($payload'),'Regular Flow provisioning must occur post-ACK.');

meta3_expect(is_array($flow),'Regular WhatsApp Flow JSON must be valid.');
meta3_expect(str_contains($flowRaw,'"name": "level_profile"')&&str_contains($flowRaw,'"required": true'),'Regular Flow must require a verifiable intermediate/advanced profile.');
meta3_expect(str_contains($regular,"['intermediate','advanced']")&&str_contains($regular,"'level_profile'"),'Backend must validate and persist the declared regular profile.');
meta3_expect(str_contains($regular,"'regular_enrollment_received'")&&str_contains($regular,"['type'=>'human_takeover']"),'Completed regular enrollment must end in human takeover, not automatic payment.');
meta3_expect(str_contains($regular,"hache_sharky_orchestrator_flow(\$state,HACHE_SHARKY_META_FLOW,'venue_detail'") ,'Cancelling the regular Flow must return to the selected Meta venue context.');

meta3_expect(str_contains($business,"require_once __DIR__.'/sharky-age-policy.php'")&&str_contains($business,'hache_sharky_age_policy_validate'),'Intensive registration must enforce the central age policy transactionally.');
meta3_expect(str_contains($agePolicy,"'max'=>\$max")&&str_contains($agePolicy,"'sharky_edad_maxima'"),'Central age policy must expose the configured maximum age authority.');
meta3_expect(str_contains($agePolicy,"['ENROLLMENT','REGULAR_ENROLLMENT']"),'Both enrollment Flows must use the central DOB bounds.');
meta3_expect(str_contains($spec,'12')&&str_contains($spec,'65'),'Meta specification must retain the approved 12–65 scope.');

echo "Sharky Meta 3.0 regression checks passed\n";
