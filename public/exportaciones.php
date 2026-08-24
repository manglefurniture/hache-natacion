<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN','VERIFICADOR']);
$sede = auth_active_sede_clave();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Exportaciones — Franky</title>
  <style>*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#172033;font-family:Manrope,Arial,sans-serif}.wrap{max-width:860px;margin:auto;padding:82px 14px 32px}h1{margin:0}.sub{color:#64748b;margin:6px 0 18px}.card{background:#fff;border:1px solid #e5eaf0;border-radius:16px;padding:15px;margin-bottom:10px}.row{display:flex;justify-content:space-between;gap:12px;align-items:center}.title{font-weight:900}.det{font-size:12px;color:#64748b;margin-top:4px}.btn{display:inline-block;text-decoration:none;background:#172033;color:#fff;font-weight:900;padding:10px 12px;border-radius:10px}.month{font:inherit;padding:10px;border:1px solid #cbd5e1;border-radius:10px;margin-bottom:12px}@media(max-width:650px){.row{align-items:flex-start;flex-direction:column}.btn{width:100%;text-align:center}}</style>
</head>
<body><main class="wrap">
  <h1>Exportaciones</h1><div class="sub">Archivos CSV de la sede <?=htmlspecialchars($sede,ENT_QUOTES,'UTF-8')?>, listos para Excel o archivo administrativo.</div>
  <input id="p" class="month" type="month">
  <div class="card"><div class="row"><div><div class="title">Alumnos por horario</div><div class="det">Alumno, estado, plan, precio, horario y WhatsApp de la sede activa.</div></div><a class="btn" href="/api/exportar-alumnos-horarios.php">Descargar</a></div></div>
  <div class="card"><div class="row"><div><div class="title">Liquidación de la sede</div><div class="det">Distribución mensual conforme al convenio configurado para la sede activa.</div></div><a id="liq" class="btn">Descargar</a></div></div>
  <div class="card"><div class="row"><div><div class="title">Detalle de pagos</div><div class="det">Movimientos individuales del mes seleccionado, sin mezclar sedes.</div></div><a id="pag" class="btn">Descargar</a></div></div>
</main><script>
const p=document.getElementById('p'),liq=document.getElementById('liq'),pag=document.getElementById('pag');p.value=new Date().toISOString().slice(0,7);
function upd(){const d=p.value+'-01',h=new Date(Number(p.value.slice(0,4)),Number(p.value.slice(5,7)),0).toISOString().slice(0,10);liq.href='/api/exportar-liquidacion.php?periodo='+encodeURIComponent(p.value);pag.href='/api/reportes-exportar.php?desde='+encodeURIComponent(d)+'&hasta='+encodeURIComponent(h)}
p.onchange=upd;upd();
</script></body></html>
