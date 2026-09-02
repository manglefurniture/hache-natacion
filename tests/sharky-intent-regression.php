<?php
declare(strict_types=1);
require __DIR__.'/../config/sharky-runtime.php';

$mustHandoff = [
    'Quiero hablar con una persona',
    'Necesito un asesor',
    '¿Me puedes pasar con alguien del equipo?',
    'Quisiera comunicarme con un humano',
    '¿Hay cupo para el intensivo?',
    '¿Cuántos alumnos hay por carril?',
    '¿Cuál es la capacidad del grupo?',
    '¿Tienen lugares disponibles en el curso?',
    '¿Hay disponibilidad para el intensivo?',
    '¿Quedan lugares?',
    '¿Cuántas personas admite el grupo?',
    'En el curso, ¿cuántos alumnos hay?',
    '¿Hay vacantes?',
    '¿Está lleno el grupo?',
    '¿El grupo ya se llenó?',
    '¿Ya se llenó el curso?',
    '¿Se llenaron los grupos?',
    '¿Hay espacio en el curso del lunes?',
    '¿Está disponible el intensivo de septiembre?',
    '¿Tienen lista de espera?',
];
$mustStayWithSharky = [
    'Quiero hablar de precios',
    'Necesito información del curso intensivo',
    '¿Puedo hablar de los horarios?',
    'Quiero saber si tienen goggles',
    '¿Qué horarios están disponibles?',
    '¿Qué fechas tienen disponibles?',
    '¿Dónde queda Monteverde?',
];

foreach ($mustHandoff as $text) {
    if (!hache_sharky_human_request($text)) {
        fwrite(STDERR, "No detectó handoff: {$text}\n");
        exit(1);
    }
}
foreach ($mustStayWithSharky as $text) {
    if (hache_sharky_human_request($text)) {
        fwrite(STDERR, "Falso positivo de handoff: {$text}\n");
        exit(1);
    }
}

echo "Sharky handoff intent regression: OK\n";
