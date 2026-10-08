<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN']);
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
$today=(new DateTimeImmutable('today',new DateTimeZone('America/Cancun')))->format('Y-m-d');
?><!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Caja minería — Hache</title>
<style>
:root{color-scheme:light;--ink:#162638;--muted:#61758a;--line:#dbe4eb;--bg:#f3f6fa;--accent:#145a80;--surface:#fff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 system-ui,-apple-system,Segoe UI,Arial,sans-serif}
main{width:min(1020px,94%);margin:auto;padding:32px 0 60px}
a{color:var(--accent)}nav{display:flex;gap:18px;flex-wrap:wrap;font-size:14px;margin-bottom:18px}
h1{font-size:clamp(26px,4vw,34px);letter-spacing:-.03em;margin:0}h2{font-size:18px;margin:0 0 14px}.sub{color:var(--muted);margin:4px 0 22px}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.card,section{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 3px 14px #0c2c4508}
.label{font-size:12px;color:var(--muted)}.big{font-weight:800;font-size:clamp(21px,2.8vw,28px);font-variant-numeric:tabular-nums;overflow-wrap:anywhere;margin:7px 0 3px}
section{margin-top:14px}#estimated{background:#eef5fa;border-radius:10px;padding:11px;margin-top:12px;font-size:14px}
.hint{font-size:13px;color:var(--muted)}.warn{color:#884c0a;background:#fff6e9;border:1px solid #eed6ad;padding:11px;border-radius:10px;margin-top:12px}
.row{display:grid;grid-template-columns:1.1fr .7fr .8fr;gap:12px}.field{display:flex;flex-direction:column;gap:5px}
input,select,button{font:inherit}input,select{border:1px solid #b8c9d5;background:#fff;border-radius:9px;min-height:44px;padding:10px;color:var(--ink);width:100%}
input:focus,select:focus{outline:2px solid #8bbed8;outline-offset:1px}
.field small{color:var(--muted)}.field.wide{grid-column:1/-1}
button{border:0;border-radius:10px;background:#145a80;color:white;padding:12px 20px;cursor:pointer;font-weight:700;min-height:44px}button:disabled{opacity:.6;cursor:wait}
.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px}.actions a{font-size:14px}
#status{min-height:20px}#status.error{color:#a61e28}
.tablewrap{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:13px;min-width:640px}th,td{text-align:left;padding:10px 9px;border-bottom:1px solid var(--line)}th{color:var(--muted);font-size:12px}td:last-child,th:last-child{text-align:right;font-variant-numeric:tabular-nums}
.empty{color:var(--muted);text-align:center;padding:16px}
@media(max-width:750px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.row{grid-template-columns:1fr 1fr}}
@media(max-width:440px){.cards{grid-template-columns:1fr 1fr}.card{padding:12px}.row{grid-template-columns:1fr}.field.wide{grid-column:auto}}
</style></head><body><main>
<nav><a href="/salad-monitor.php">← Monitor Salad</a><a href="/dashboard.php">Dashboard</a></nav>
<h1>Caja de minería PRL</h1><p class="sub">Registro privado en USD · independiente de Hache Natación · cortes reales y proyecciones por separado</p>
<div class="cards">
<div class="card"><div class="label">Billetera (USD digitales)</div><div class="big" id="wallet">—</div><div class="hint">Saldo registrado</div></div>
<div class="card"><div class="label">Créditos Salad</div><div class="big" id="salad">—</div><div class="hint">No es gasto hasta consumirse</div></div>
<div class="card"><div class="label">Caja conjunta</div><div class="big" id="combined">—</div><div class="hint">Sin contar PRL sin vender</div></div>
<div class="card"><div class="label">Resultado operativo</div><div class="big" id="operating">—</div><div class="hint">Ventas − consumo − comisiones</div></div>
</div>
<div class="warn" id="baseline" role="status">Cargando datos…</div>
<section aria-labelledby="estimate-heading">
<h2 id="estimate-heading">Producción y costo operativo estimado</h2>
<p class="hint">Lectura automática del monitor SaladCloud cada 5 minutos. El costo proyectado NO genera un asiento ni sustituye la factura.</p>
<div id="estimated" role="status">Consultando…</div>
</section>
<section aria-labelledby="entry-heading">
<h2 id="entry-heading">Registrar movimiento confirmado</h2>
<form id="form">
<div class="row">
<label class="field">Movimiento<select id="type" required></select></label>
<label class="field">Importe USD<input id="amount" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" placeholder="20.00" required></label>
<label class="field">Fecha (Cancún)<input type="date" id="date" value="<?php echo htmlspecialchars($today,ENT_QUOTES,'UTF-8'); ?>" required></label>
<label class="field wide">Referencia o detalle<input id="note" maxlength="280" placeholder="Ej.: USDT de SafeTrade, recarga Salad, factura" autocomplete="off"></label>
</div>
<p class="hint">Registra el importe que realmente llegó. Las transferencias a Salad no son gastos; registra «Consumo real» solamente con el cargo comprobado. Para iniciar, anota los saldos actuales una sola vez como apertura.</p>
<div class="actions"><button type="submit" id="save">Guardar asiento</button><span id="status" role="status" aria-live="polite"></span></div>
</form>
</section>
<section aria-labelledby="ledger-heading">
<div class="actions" style="justify-content:space-between;margin:0 0 10px"><h2 id="ledger-heading" style="margin:0">Historial de movimientos</h2><button type="button" id="export">Exportar CSV</button></div>
<div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Movimiento</th><th>Detalle</th><th>Importe USD</th></tr></thead><tbody id="rows"><tr><td colspan="4" class="empty">Cargando…</td></tr></tbody></table></div>
<p class="hint">El registro es de solo adición para preservar la trazabilidad. Los saldos y el resultado solo representan lo contabilizado desde la apertura, sin incluir movimientos anteriores.</p>
</section>
</main><script>
'use strict';
const $=id=>document.getElementById(id),money=c=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'USD'}).format(Number(c||0)/100);
let csrf='',types={},entries=[],requestId='';
const newId=()=>{if(globalThis.crypto?.randomUUID)return crypto.randomUUID().replace(/-/g,'');const a=new Uint8Array(24);crypto.getRandomValues(a);return [...a].map(x=>x.toString(16).padStart(2,'0')).join('')};
function csvEscape(v){const s=String(v??'');return '"'+s.replace(/"/g,'""')+'"'}
function setStatus(msg,error=false){$('status').textContent=msg;$('status').className=error?'error':''}
async function load(){
  const response=await fetch('/api/mineria-caja.php',{cache:'no-store',credentials:'same-origin'});
  const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'No disponible');
  csrf=data.csrf_token;types=data.types;entries=data.entries;
  $('type').replaceChildren(...Object.entries(types).map(([id,label])=>{const option=document.createElement('option');option.value=id;option.textContent=label;return option}));
  const s=data.summary;
  $('wallet').textContent=money(s.wallet_cents);$('salad').textContent=money(s.salad_cents);
  $('combined').textContent=money(s.combined_cents);$('operating').textContent=money(s.operating_result_cents);
  const base=(s.has_opening_wallet&&s.has_opening_salad);
  $('baseline').hidden=base;
  $('baseline').textContent=base?'':'Saldos incompletos: registra una apertura de billetera y una de Salad, incluso si su importe es bajo. No interpretes los saldos anteriores como reales.';
  const o=data.operational;
  if(!o.available){$('estimated').textContent='Sin estimación fiable: '+(o.reason||'Datos no disponibles');}
  else{
    const hours=o.hourly_usd_estimate>0 ? Math.max(0,s.salad_cents/100/o.hourly_usd_estimate):null;
    $('estimated').textContent=o.running_4070_low+' RTX 4070 Ti Super Low activas · '+new Intl.NumberFormat('es-MX',{style:'currency',currency:'USD'}).format(o.hourly_usd_estimate)+'/h · '+new Intl.NumberFormat('es-MX',{style:'currency',currency:'USD'}).format(o.daily_usd_estimate)+'/24 h'+(base&&hours!==null?' · Autonomía contable aproximada: '+hours.toFixed(1)+' h':'')+' · '+o.reason;
  }
  const tbody=$('rows');tbody.replaceChildren();
  for(const e of entries){
    const tr=document.createElement('tr');
    for(const value of [e.date,types[e.type]||e.type,e.note||'—',money(e.amount_cents)]){
      const td=document.createElement('td');td.textContent=value;tr.appendChild(td);
    }tbody.appendChild(tr);
  }
  if(!entries.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=4;td.className='empty';td.textContent='Sin movimientos todavía';tr.appendChild(td);tbody.appendChild(tr);}
}
$('form').addEventListener('submit',async ev=>{
 ev.preventDefault();$('save').disabled=true;setStatus('Guardando…');
 if(!requestId)requestId=newId();
 const body={type:$('type').value,amount:$('amount').value,date:$('date').value,note:$('note').value,request_id:requestId};
 try{
  const r=await fetch('/api/mineria-caja.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});
  const d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'No se pudo guardar');
  requestId='';$('amount').value='';$('note').value='';
  await load();setStatus('Movimiento registrado correctamente.');
 }catch(e){setStatus(e.message,true)}finally{$('save').disabled=false;}
});
for(const id of ['type','amount','date','note'])$(id).addEventListener('input',()=>{requestId='';});
$('export').addEventListener('click',()=>{
 const rows=[['fecha','tipo','importe_usd','nota','id','registrado_utc'],...entries.map(e=>[e.date,e.type,(e.amount_cents/100).toFixed(2),e.note,e.id,e.created_at])];
 const csv='\uFEFF'+rows.map(r=>r.map(csvEscape).join(',')).join('\r\n');
 const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
 const a=document.createElement('a');a.href=url;a.download='mineria-caja.csv';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),5000);
});
async function refresh(){try{await load()}catch(e){$('baseline').hidden=false;$('baseline').textContent='No se pudo cargar la caja. '+e.message;}}
refresh();setInterval(refresh,300000);
</script></body></html>
