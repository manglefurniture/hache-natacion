<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN']);

$patterns=[
    [
        'id'=>'GP-001',
        'title'=>'Meta → principiante → intensivo → inscripción → pago',
        'status'=>'Activo',
        'observed'=>'10/09/2026',
        'summary'=>'Un prospecto llegó desde Meta, confirmó que empezaba desde cero, recibió la recomendación del intensivo, eligió sede, horario y fecha, completó la inscripción protegida y avanzó al flujo de pago sin perder el contexto.',
        'preserve'=>[
            'Brain entiende y recomienda; el backend ejecuta inscripción y pago.',
            'Empezar desde cero o no tener clases formales prioriza la recomendación del intensivo.',
            'La preferencia posterior del cliente se respeta; recomendar no significa imponer.',
            'Sede, horario y fecha confirmados permanecen en contexto y no se vuelven a preguntar sin motivo.',
            'Horarios y fechas salen de disponibilidad real del backend.',
            'Los flows protegidos no pueden ser reescritos por Brain.',
            'Una pausa normal entre mensajes no debe reiniciar ni hacer perder el hilo.',
            'Después de una inscripción correcta el recorrido puede continuar al pago.',
        ],
        'canImprove'=>[
            'Acortar mensajes y confirmaciones redundantes.',
            'Mejorar naturalidad y tono.',
            'Elegir mejor cuándo mostrar botones o listas.',
            'Eliminar preguntas que no aportan al siguiente paso.',
        ],
    ],
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Patrones positivos de Sharky — Hache Natación</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f6fa;font-family:Manrope,Arial,sans-serif;color:#172033}.wrap{max-width:980px;margin:auto;padding:74px 14px 40px}.top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.top a{color:#123b5d;font-weight:800;text-decoration:none}.sub{color:#64748b;margin:6px 0 18px;line-height:1.5}.panel{background:#fff;border-radius:16px;padding:18px;box-shadow:0 2px 10px rgba(15,23,42,.04);margin-top:12px}.head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.pill{display:inline-block;padding:5px 9px;border-radius:999px;background:#e8f7ee;color:#176b3a;font-size:12px;font-weight:800}.pattern-id{font-size:12px;font-weight:900;color:#123b5d;text-transform:uppercase;letter-spacing:.04em}.panel h2{margin:3px 0 8px;font-size:20px}.panel h3{font-size:15px;margin:18px 0 8px}.panel p,.panel li{line-height:1.5}.panel ul{margin:8px 0 0;padding-left:21px}.note{padding:12px 14px;border-radius:12px;background:#f8fafc;color:#475569;line-height:1.5;margin-top:14px}.rule{border-left:4px solid #123b5d;padding-left:12px;font-weight:700}.privacy{font-size:12px;color:#64748b}.empty{color:#64748b}.footer-note{margin-top:16px;color:#64748b;font-size:13px;line-height:1.5}
</style>
</head>
<body>
<main class="wrap">
  <div class="top"><div><h1 style="margin:0">Bitácora de patrones positivos</h1><div class="sub">Casos reales que funcionaron bien y que deben tratarse como contratos de experiencia al modificar Sharky.</div></div><div><a href="/sharky-admin.php">← Sharky</a></div></div>

  <div class="note rule">Una conversación problemática descubre un borde. Una conversación exitosa define un contrato. Las correcciones deben resolver el borde sin degradar los contratos positivos ya demostrados.</div>

  <?php foreach($patterns as $pattern): ?>
  <section class="panel">
    <div class="head"><div><div class="pattern-id"><?=htmlspecialchars($pattern['id'])?></div><h2><?=htmlspecialchars($pattern['title'])?></h2></div><span class="pill"><?=htmlspecialchars($pattern['status'])?></span></div>
    <div class="privacy">Observado: <?=htmlspecialchars($pattern['observed'])?> · Caso anonimizado: no se conservan nombre, teléfono, fecha de nacimiento ni capturas del prospecto.</div>
    <p><?=htmlspecialchars($pattern['summary'])?></p>
    <h3>NO ROMPER</h3>
    <ul><?php foreach($pattern['preserve'] as $item): ?><li><?=htmlspecialchars($item)?></li><?php endforeach; ?></ul>
    <h3>Puede mejorar sin romper el patrón</h3>
    <ul><?php foreach($pattern['canImprove'] as $item): ?><li><?=htmlspecialchars($item)?></li><?php endforeach; ?></ul>
  </section>
  <?php endforeach; ?>

  <div class="footer-note">Fuente de detalle técnico: <code>docs/SHARKY-POSITIVE-PATTERNS.md</code>. Antes de cambiar Brain, onboarding, memoria comercial, inscripción, pagos o takeover, revisar esta bitácora y preservar los invariantes aplicables.</div>
</main>
</body>
</html>
