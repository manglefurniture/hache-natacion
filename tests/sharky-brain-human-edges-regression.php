<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-whatsapp-batching.php';
require_once __DIR__.'/../config/sharky-brain-live-router.php';
require_once __DIR__.'/../config/sharky-followup.php';
require_once __DIR__.'/../config/sharky-post-pr72.php';

function human_edge_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY HUMAN EDGE FAIL: {$message}\n");exit(1);}
}

$pauseCases=[
    'Déjame pensarlo',
    'Lo voy a analizar',
    'Deje revisar esto por favor',
    'Permíteme checar esto',
    'Deje analizar distancia hogar, trabajo y horarios por favor',
];
foreach($pauseCases as $text){
    human_edge_ok(
        hache_sharky_brain_conversational_explicit_pause($text),
        'Must remain an unequivocal pause: '.$text
    );
}

$notPauseCases=[
    'Déjame ver los horarios',
    'Permíteme ver las opciones',
    'Quiero ver precios',
    'No puedo hoy',
    'No sé todavía',
    'No quiero intensivo, prefiero regular',
    'No, mejor Monteverde',
    'jajaja no, sí quiero',
    'gracias',
    'Perfecto',
    '👍',
    '¿Puedo ir mañana?',
];
foreach($notPauseCases as $text){
    human_edge_ok(
        !hache_sharky_brain_conversational_explicit_pause($text),
        'Must not become a pause by accident: '.$text
    );
}

$localNegations=[
    'No puedo hoy',
    'No en la mañana',
    'No sé todavía',
    'No, mejor Monteverde',
    'No quiero intensivo, prefiero regular',
];
foreach($localNegations as $text){
    human_edge_ok(
        !hache_sharky_whatsapp_now_not_request(['text'=>$text,'interactive_id'=>'']),
        'Local negation must not become global now-not pause: '.$text
    );
    human_edge_ok(
        !hache_sharky_followup_user_opted_out($text),
        'Local negation must not become a follow-up opt-out: '.$text
    );
}

human_edge_ok(
    hache_sharky_whatsapp_now_not_request(['text'=>'Ahora no','interactive_id'=>'']),
    'Explicit “Ahora no” must remain a pause.'
);
human_edge_ok(
    hache_sharky_whatsapp_now_not_request(['text'=>'Por ahora no','interactive_id'=>'']),
    'Explicit “Por ahora no” must remain a pause.'
);

human_edge_ok(
    hache_sharky_whatsapp_deferred_close_request('Te confirmo mañana'),
    'Explicit deferred close must remain recognized.'
);
human_edge_ok(
    hache_sharky_followup_user_deferred('Te confirmo mañana'),
    'Follow-up authority must also recognize the same deferred close.'
);
human_edge_ok(
    hache_sharky_whatsapp_deferred_close_request('Lo reviso y te digo'),
    'Natural review-and-reply close must remain recognized.'
);
human_edge_ok(
    hache_sharky_followup_user_deferred('Lo reviso y te digo'),
    'Follow-up authority must also recognize natural review-and-reply close.'
);
human_edge_ok(
    !hache_sharky_whatsapp_deferred_close_request('Déjame ver los horarios'),
    'Information request must not become deferred close.'
);
human_edge_ok(
    !hache_sharky_followup_user_deferred('Déjame ver los horarios'),
    'Follow-up authority must not convert an information request into a deferred close.'
);
human_edge_ok(
    !hache_sharky_whatsapp_deferred_close_request('Déjame revisar los horarios'),
    'Ambiguous review-with-object must stay out of the text-only deferred-close detector.'
);
human_edge_ok(
    hache_sharky_followup_user_opted_out('gracias'),
    'A simple thanks must suppress automated commercial follow-up for the active session.'
);
human_edge_ok(
    hache_sharky_followup_user_opted_out('solo quería información'),
    'A clear soft close must suppress automated commercial follow-up.'
);
human_edge_ok(
    hache_sharky_followup_user_opted_out('no me escribas más'),
    'A strong stop-contact request must remain protected by the follow-up authority.'
);

$clean=hache_sharky_brain_conversational_strip_opening_filler("Hola 👋\n\nClaro\n\n¿Ya sabes nadar?");
human_edge_ok(
    $clean==='¿Ya sabes nadar?',
    'Standalone greeting/filler lines must be removable without touching the useful question.'
);

$meaningful='Perfecto, el curso intensivo cuesta $1,200 MXN.';
human_edge_ok(
    hache_sharky_brain_conversational_strip_opening_filler($meaningful)===$meaningful,
    'A meaningful sentence beginning with “Perfecto,” must never be stripped.'
);

$meaningfulClaro='Claro que sí, te explico cómo funciona.';
human_edge_ok(
    hache_sharky_brain_conversational_strip_opening_filler($meaningfulClaro)===$meaningfulClaro,
    'A meaningful sentence beginning with “Claro” must never be stripped as a standalone filler.'
);

$prospect=hache_sharky_orchestrator_state(null,1788886800);
$prospect['identity']=array_replace($prospect['identity'],[
    'kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched',
]);
$prospect=hache_sharky_whatsapp_apply_natural_swim_level($prospect,'Estoy empezando desde cero, nunca he tomado clases');
human_edge_ok(
    ($prospect['commercial_context']['swim_level']??null)==='beginner'
    &&($prospect['commercial_context']['program']??null)==='intensive',
    'A true beginner must retain the existing controlled intensive recommendation in structured state.'
);

$policy=hache_sharky_post72_whatsapp_style_policy();
foreach([
    'prioriza conocer el nivel antes de pedir que elija programa',
    'curso intensivo es la recomendación primaria de Hache Natación',
    'respeta su decisión y continúa con regulares sin insistir',
    'no asumas que esa es su elección final',
    'Una negación se aplica a su objeto inmediato',
    'domina la corrección final',
    'no vuelvas a preguntar algo que ya vino en el mismo mensaje o burst',
    '“ambas”, “los dos”, “las dos” o “todos”',
    'No termines cada respuesta con una pregunta por costumbre',
    'No pidas colonia, zona, domicilio, ubicación de casa o trabajo',
    'Cuando quieras seguimos con horarios o precios',
    'responde como máximo con un cierre breve o no agregues otra pregunta comercial',
] as $needle){
    human_edge_ok(
        str_contains($policy,$needle),
        'Global Brain/WhatsApp policy must retain human-edge rule: '.$needle
    );
}

fwrite(STDOUT,"SHARKY_BRAIN_HUMAN_EDGES_OK\n");
