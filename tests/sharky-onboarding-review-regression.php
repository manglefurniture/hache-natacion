<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-lab-worker.php';

function onboarding_review_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY ONBOARDING REVIEW FAIL: $message\n");exit(1);}
}

foreach(['No entendí','No entiendo','Quiero información','Necesito información','Hola'] as $phrase){
    onboarding_review_ok(hache_sharky_prospect_onboarding_name($phrase)===null,'Conversational phrase must not become a name: '.$phrase);
}
onboarding_review_ok(hache_sharky_prospect_onboarding_name('Roberto')==='Roberto','A normal first name must remain valid.');
onboarding_review_ok(hache_sharky_prospect_onboarding_name('José Luis')==='José Luis','A normal compound name must remain valid.');

foreach(['No sé nadar','No se nadar','Nada de nada','De cero','De ceros','Quiero aprender a nadar'] as $phrase){
    onboarding_review_ok(hache_sharky_prospect_onboarding_level($phrase)==='beginner','Documented beginner phrase must be recognized: '.$phrase);
}
onboarding_review_ok(hache_sharky_prospect_onboarding_level('Intermedio')==='intermediate','Intermediate free text must remain supported.');
onboarding_review_ok(hache_sharky_prospect_onboarding_level('Avanzado')==='advanced','Advanced free text must remain supported.');

onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('Sí','onboarding:self:yes','participant')===true,'Current participant Yes button must be accepted.');
onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('No','onboarding:self:no','participant')===false,'Current participant No button must be accepted.');
onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('Sí','onboarding:background:yes','participant')===null,'Training-history button must not answer participant step.');
onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('Sí','onboarding:self:yes','background')===null,'Stale participant button must not assert formal training.');
onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('Sí','onboarding:background:yes','background')===true,'Current background Yes button must be accepted.');
onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('No','onboarding:background:no','background')===false,'Current background No button must be accepted.');

fwrite(STDOUT,"SHARKY_ONBOARDING_REVIEW_OK\n");
