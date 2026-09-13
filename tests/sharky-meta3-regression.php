<?php

declare(strict_types=1);

function meta3_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$root=dirname(__DIR__);
$meta=file_get_contents($root.'/config/sharky-meta-ad-flow.php')?:'';
$entry=file_get_contents($root.'/config/sharky-entry-guidance.php')?:'';
$language=file_get_contents($root.'/config/sharky-language-guide.php')?:'';
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

meta3_expect(str_contains($entry,"['source'=>'meta_ad'")||str_contains($entry,"['source' => 'meta_ad'"),'Meta referral must be tagged as meta_ad.');
meta3_expect(str_contains($entry,'HACHE_SHARKY_META_FLOW')||str_contains($entry,"'meta_ad_onboarding'"),'Meta referrals must bootstrap the dedicated state machine.');
meta3_expect(str_contains($meta,"(\$state['commercial_context']['entry_source']??'')!=='meta_ad'"),'Meta handler must reject non-Meta sources.');
meta3_expect(str_contains($meta,"(\$state['identity']['kind']??'unknown')!=='prospect'"),'Known/non-prospect identities must not enter the Meta funnel.');
meta3_expect(str_contains($meta,'hache_sharky_meta_program_retry()')&&str_contains($meta,'hache_sharky_meta_venue_retry($state)'),'Closed steps must retry deterministic buttons instead of free-text interpretation.');
meta3_expect(str_contains($meta,'hache_sharky_meta_program_ui(false)')&&str_contains($meta,'hache_sharky_meta_venue_ui(false)'),'Retries must omit visual cards.');
meta3_expect(str_contains($language,"\$event['interactive_id']='meta:free_text'")&&str_contains($language,'hache_sharky_meta_active($state)'),'Typed Meta replies must be tagged before legacy side-question shortcuts so closed steps remain deterministic.');
meta3_expect(str_contains($meta,'meta:regular:no')&&str_contains($meta,"recommended_program']='intensive'"),'Regular background No must route directly to intensive.');
meta3_expect(str_contains($meta,'meta:venue:other')&&str_contains($meta,"commercial_context']['program'"),'Venue reselection must preserve the selected program in commercial context.');
meta3_expect(str_contains($meta,'hache_sharky_meta_restore_expired')&&str_contains($meta,"commercial_context']['meta_step'"),'Expired Meta state must recover its closed deterministic step instead of falling into legacy routing.');
meta3_expect(str_contains($meta,'HACHE_SHARKY_META_IMAGE_LEARN')&&str_contains($meta,'HACHE_SHARKY_META_IMAGE_REGULAR')&&str_contains($meta,'HACHE_SHARKY_META_IMAGE_MONTEVERDE')&&str_contains($meta,'HACHE_SHARKY_META_IMAGE_PALAPAS'),'All four approved visual asset routes must be wired.');
meta3_expect(!str_contains($meta,'/assets/sharky/meta-aprende-a-nadar.jpg')&&!str_contains($meta,'/assets/sharky/sede-palapas.jpg'),'Meta visual routes must not reference the rejected invalid JPG blobs.');
$approvedAssets=[
    'public/assets/Aprende a nadar en tres semanas.png',
    'public/assets/Clases regulares de natación nocturna.png',
    'public/assets/Sede Monteverde.png',
    'public/assets/SEDE Palapas PROTUDEC.png',
];
foreach($approvedAssets as $asset){
    $path=$root.'/'.$asset;
    meta3_expect(is_file($path),"Approved visual asset missing: {$asset}");
    meta3_expect(@getimagesize($path)!==false,"Approved visual asset is not a decodable image: {$asset}");
}
meta3_expect(str_contains($outbox,"'_sharky_sequence'")&&str_contains($outbox,"'|sequence|'"),'Image sequences must use per-item deterministic outbox dedupe keys.');
meta3_expect(str_contains($outbox,'hache_sharky_outbox_sequence_predecessor_key')&&str_contains($outbox,"'_sharky_sequence_predecessor'"),'Visual sequence children after the first must persist their predecessor dependency.');
meta3_expect(str_contains($outbox,'hache_sharky_outbox_sequence_predecessor_status')&&str_contains($outbox,'SEQUENCE_WAITING_'),'Outbox dispatch must defer a sequence child until its predecessor was sent.');
meta3_expect(str_contains($outbox,"['INVALID','MISSING','DEAD','CANCELLED']"),'Broken visual sequence predecessors must fail closed instead of sending controls out of order.');

meta3_expect(str_contains($worker,'$metaResultLocked')&&str_contains($worker,'if(!$metaResultLocked)')&&str_contains($worker,'hache_sharky_brain_2ba_apply'),'Brain 2B-A must remain available globally but be bypassed for deterministic Meta results.');
meta3_expect(str_contains($worker,'if(!$metaDeterministic&&$text!=='),'Legacy free-text early handoff policy must not preempt closed Meta steps.');

meta3_expect(str_contains($webhook,'hache_sharky_regular_flow_extract_events($payload)'),'Webhook must extract regular enrollment Flow replies.');
meta3_expect(str_contains($webhook,'===HACHE_SHARKY_REGULAR_FLOW_KIND')&&str_contains($webhook,'hache_sharky_regular_enrollment_process'),'Webhook must process regular enrollment before generic Sharky routing.');
meta3_expect(str_contains($recovery,'===HACHE_SHARKY_REGULAR_FLOW_KIND')&&str_contains($recovery,'hache_sharky_regular_enrollment_process'),'Recovery worker must process durable regular Flow replies.');
meta3_expect(str_contains($webhook,'hache_sharky_regular_flow_prime($payload'),'Regular Flow provisioning must occur post-ACK.');

meta3_expect(is_array($flow),'Regular WhatsApp Flow JSON must be valid.');
meta3_expect(str_contains($flowRaw,'"name": "level_profile"')&&str_contains($flowRaw,'"required": true'),'Regular Flow must require a verifiable intermediate/advanced profile.');
meta3_expect(str_contains($flowRaw,'"age_helper"')&&str_contains($flowRaw,'"helper-text": "${data.age_helper}"'),'Regular Flow age copy must be supplied dynamically by the central policy.');
meta3_expect(str_contains($meta,"'age_helper'=>'Clases de '.\$minAge.' a '.\$maxAge.' años'")&&str_contains($meta,'hache_sharky_age_policy($pdo,$business)'),'Meta welcome and Flow data must derive displayed age scope from the central policy.');
meta3_expect(str_contains($meta,'MIN(p.precio) min_price')&&str_contains($meta,'hache_sharky_meta_regular_plan_lines'),'Regular price presentation must use venue-backed plan data and avoid contradictory global quotes.');
meta3_expect(str_contains($regular,"['intermediate','advanced']")&&str_contains($regular,"'level_profile'"),'Backend must validate and persist the declared regular profile.');
meta3_expect(str_contains($regular,"'regular_enrollment_received'")&&str_contains($regular,"['type'=>'human_takeover']"),'Completed regular enrollment must end in human takeover, not automatic payment.');
meta3_expect(str_contains($regular,"hache_sharky_orchestrator_flow(\$state,HACHE_SHARKY_META_FLOW,'venue_detail'") ,'Cancelling the regular Flow must return to the selected Meta venue context.');
meta3_expect(str_contains($regular,'hache_sharky_action_recovery_claim')&&str_contains($regular,'hache_sharky_action_recovery_finish'),'Regular registration must persist an idempotent action result before its delivery boundary.');
meta3_expect(str_contains($regular,'hache_sharky_regular_registration_recover_locked')&&str_contains($regular,"PHONE_ALREADY_REGISTERED"),'A retry after a committed regular registration must reconcile the exact Sharky-created record instead of reporting a false failure.');
meta3_expect(str_contains($regular,'hache_sharky_db_state_defer_begin()')&&str_contains($regular,'hache_sharky_db_state_defer_take()'),'Regular Flow state changes must remain deferred until outbox and inbox receipt durability are secured.');
meta3_expect(substr_count($regular,'hache_sharky_lab_mark_handoff_pending($pdo,$eventId)')>=3&&str_contains($regular,"if(!hache_sharky_takeover_mark(\$contact,'regular_enrollment_payment'"),'Regular handoff paths must persist handoff-pending/takeover before confirming human continuation.');
meta3_expect(str_contains($regular,'hache_sharky_age_policy($pdo,$business)'),'Regular enrollment must reload the central configured age policy instead of trusting a stale fallback.');

meta3_expect(str_contains($business,"require_once __DIR__.'/sharky-age-policy.php'")&&str_contains($business,'hache_sharky_age_policy_validate'),'Intensive registration must enforce the central age policy transactionally.');
meta3_expect(str_contains($agePolicy,"'max'=>\$max")&&str_contains($agePolicy,"'sharky_edad_maxima'"),'Central age policy must expose the configured maximum age authority.');
meta3_expect(str_contains($agePolicy,"['ENROLLMENT','REGULAR_ENROLLMENT']"),'Both enrollment Flows must use the central DOB bounds.');
meta3_expect(str_contains($outbox,'hache_sharky_age_policy_apply_flow_bounds($pdo,$payload)'),'Every enrollment Flow must receive central DOB bounds before durable outbox encryption.');
meta3_expect(str_contains($spec,'12')&&str_contains($spec,'65'),'Meta specification must retain the approved 12–65 scope.');

echo "Sharky Meta 3.0 regression checks passed\n";
