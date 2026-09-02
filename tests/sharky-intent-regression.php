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
    '¿Qué capacidad tiene el grupo?',
    '¿Cuál es la capacidad máxima del intensivo?',
    '¿Tienen lugares disponibles en el curso?',
    '¿Hay disponibilidad para el intensivo?',
    '¿Hay disponibilidad para el intensivo en el horario de las 8?',
    '¿Tienen disponibilidad de cupo en el horario de las 8?',
    '¿Hay disponibilidad de horarios y cuántos alumnos hay por carril?',
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
    'Quiero inscribirme a clases regulares',
    '¿Cómo me registro en regular?',
    'Quiero darme de alta en mensualidad',
    'Ya quiero empezar con las clases regulares',
    'Quiero inscribir a mi hijo a clases regulares',
    '¿Puedo registrar a mi esposa en regular?',
    'Necesito anotar a mi hija en clases regulares',
    'Para clases regulares quiero inscribir a mi esposo',
    '¿Cómo puedo dar de alta a mi mamá en mensualidad?',
    'Voy a inscribirlo a regular',
    'Mi esposa quiere inscribirse a clases regulares',
    'Mi hijo desea registrarse en regular',
    'Ella quiere darse de alta en mensualidad',
    'Mis hijos quieren anotarse a clases regulares',
    '¿Cómo puede registrarse mi esposa a clases regulares?',
];
$mustStayWithSharky = [
    'Quiero hablar de precios',
    'Necesito información del curso intensivo',
    '¿Puedo hablar de los horarios?',
    'Quiero saber si tienen goggles',
    '¿Qué horarios están disponibles?',
    '¿Qué fechas tienen disponibles?',
    '¿Tienen disponibilidad de horarios?',
    '¿Hay disponibilidad de fechas para septiembre?',
    '¿Hay disponibilidad para el horario de las 8?',
    '¿Tienen disponibilidad de horario por la noche?',
    '¿Tienen disponibilidad de horarios para una persona?',
    '¿Hay disponibilidad de fechas para alumnos nuevos?',
    '¿Está disponible el horario de las 8?',
    '¿Hay horarios disponibles en Monteverde?',
    '¿Cuáles son las fechas disponibles del intensivo?',
    '¿Qué días están disponibles para empezar?',
    'Quiero inscribirme al intensivo',
    '¿Cómo me registro al intensivo?',
    'Quiero inscribir a mi hijo al intensivo',
    '¿Puedo registrar a mi esposa en el intensivo?',
    'Mi esposa quiere inscribirse al intensivo',
    'Mi hijo desea registrarse al intensivo',
    'Quiero información de clases regulares',
    '¿Cuánto cuestan las clases regulares?',
    '¿Qué horarios hay de regular?',
    '¿Dónde queda Monteverde?',
    '¿Necesito cierta capacidad física para entrar al curso?',
    '¿Qué capacidad física necesito para aprender a nadar?',
    'Quiero mejorar mi capacidad pulmonar nadando',
    '¿Se necesita alguna capacidad de aprendizaje especial?',
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
