<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-whatsapp-batching.php';
require_once __DIR__.'/../config/sharky-brain-live-router.php';

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
    hache_sharky_whatsapp_deferred_close_request('Lo reviso y te digo'),
    'Natural review-and-reply close must remain recognized.'
);
human_edge_ok(
    !hache_sharky_whatsapp_deferred_close_request('Déjame ver los horarios'),
    'Information request must not become deferred close.'
);
human_edge_ok(
    !hache_sharky_whatsapp_deferred_close_request('Déjame revisar los horarios'),
    'Ambiguous review-with-object must stay out of the text-only deferred-close detector.'
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

fwrite(STDOUT,"SHARKY_BRAIN_HUMAN_EDGES_OK\n");
