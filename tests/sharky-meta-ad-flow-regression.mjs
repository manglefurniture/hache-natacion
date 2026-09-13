import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');
const ok = (condition, message) => {
  if (!condition) {
    console.error(`SHARKY META FLOW FAIL: ${message}`);
    process.exit(1);
  }
};

const entry = read('config/sharky-entry-guidance.php');
const meta = read('config/sharky-meta-ad-flow.php');
const regular = read('config/sharky-regular-enrollment.php');
const worker = read('config/sharky-lab-worker.php');
const webhook = read('public/api/whatsapp-orchestrator-lab.php');
const agePolicy = read('config/sharky-age-policy.php');
const spec = read('docs/SHARKY-3-META-FLOW.md');

ok(entry.includes("($state['commercial_context']['entry_source']??'')==='meta_ad'"), 'Meta bootstrap must be gated by entry_source=meta_ad.');
ok(entry.includes("'meta_ad_onboarding'"), 'Meta referrals must bootstrap the dedicated Sharky 3.0 state machine.');
ok(entry.includes("'prospect_onboarding'"), 'Web/direct prospects must retain the legacy onboarding path.');

ok(meta.includes("const HACHE_SHARKY_META_FLOW='meta_ad_onboarding'"), 'Meta state machine name must remain stable.');
ok(meta.includes("'meta:program:learn'" ) && meta.includes("'meta:program:regular'"), 'Initial product selector must expose both approved controls.');
ok(meta.includes("hache_sharky_meta_program_ui(false)"), 'Product retries must not repeat images.');
ok(meta.includes("hache_sharky_meta_venue_ui(false)"), 'Venue retries must not repeat images.');
ok(meta.includes("'meta:regular:no'" ) && meta.includes("'background']='no_formal'"), 'Regular/no-training path must route to the basic intensive path.');
ok(meta.includes("'meta:venue:other'"), 'Venue detail must preserve the Ver otra sede control.');
ok(meta.includes("'meta:register:intensive'" ) && meta.includes("hache_sharky_whatsapp_registration_form_from_context"), 'Intensive registration must reuse the existing enrollment flow.');
ok(meta.includes("'meta:register:regular'" ) && meta.includes("hache_sharky_meta_regular_form"), 'Regular registration must launch the dedicated Flow.');
ok(meta.includes("HACHE_SHARKY_META_IMAGE_LEARN") && meta.includes("HACHE_SHARKY_META_IMAGE_REGULAR") && meta.includes("HACHE_SHARKY_META_IMAGE_MONTEVERDE") && meta.includes("HACHE_SHARKY_META_IMAGE_PALAPAS"), 'All four approved visual assets must remain wired into the Meta funnel.');

ok(regular.includes("const HACHE_SHARKY_REGULAR_FLOW_KIND='regular_enrollment'"), 'Regular Flow kind must remain explicit.');
ok(regular.includes("['submit','cancel']"), 'Regular Flow must support safe submit and cancel actions.');
ok(regular.includes("'PENDIENTE'"), 'Regular enrollment must remain pending for human payment coordination.');
ok(regular.includes("hache_sharky_takeover_mark($contact,'regular_enrollment_payment'"), 'Completed regular enrollment must hand off to a human for payment.');
ok(regular.includes("$expected!==$submitted"), 'Regular Flow must reject venue mismatch.');
ok(regular.includes("in_array($profile,['intermediate','advanced'],true)"), 'Regular Flow must verify an intermediate/advanced profile.');

ok(webhook.includes('hache_sharky_regular_flow_extract_events($payload)'), 'Webhook must normalize regular enrollment Flow events.');
ok(webhook.includes('hache_sharky_regular_enrollment_process($pdo,$event,$business,$minAge,$maxAge)'), 'Webhook must process regular enrollment Flow events with centralized age limits.');
ok(webhook.includes('hache_sharky_regular_flow_prime($payload'), 'Webhook must provision/publish the regular enrollment Flow.');

ok(worker.includes('$metaDeterministic='), 'Worker must detect deterministic Meta turns before Brain post-processing.');
ok(worker.includes("(string)($result['code']??'')==='META_AD_ONBOARDING'"), 'Worker must lock Meta results even when the pre-turn state is not yet sufficient.');
ok(worker.includes('if(!$metaResultLocked)') && worker.includes('hache_sharky_brain_2ba_apply'), 'Brain 2B-A must remain available only outside locked Meta turns.');

ok(agePolicy.includes('sharky_edad_maxima') && agePolicy.includes("'max'=>65"), 'Central age policy must retain maximum age 65.');
ok(spec.includes('web') && spec.includes('WhatsApp directo') && spec.includes('12 a 65'), 'Normative Meta spec must preserve source separation and age range.');

console.log('SHARKY META FLOW REGRESSION OK');
