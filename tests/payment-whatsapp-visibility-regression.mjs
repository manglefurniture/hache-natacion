import fs from 'node:fs';

function ok(condition,message){
  if(!condition){console.error(`PAYMENT WHATSAPP VISIBILITY FAIL: ${message}`);process.exit(1);}
}

const api=fs.readFileSync('api/pagos.php','utf8');
const page=fs.readFileSync('public/pagos.php','utf8');
const helper=fs.readFileSync('public/assets/pagos-flow-v2.js','utf8');
const backendMenu=fs.readFileSync('public/assets/backend-menu.js','utf8');

ok(api.includes("require __DIR__.'/pagos-smart.php'"),'Payment wrapper must keep delegating financial persistence to pagos-smart.php.');
ok(api.includes("'queued'=>(bool)($notification['queued']??false)"),'Payment API must expose whether the WhatsApp template was queued.');
ok(api.includes("'reason'=>(string)($notification['reason']??'')"),'Payment API must preserve the notification reason for admin diagnostics.');
ok(backendMenu.includes("'/pagos.php':['/assets/pagos-flow-v2.js'"),'The deployed payments page must be covered with its auto-loaded smart helper.');
ok(helper.includes("url.includes('/api/pagos.php')&&method==='POST'"),'The smart helper must enrich the payment POST issued by the page.');
ok(helper.includes('b.periodo_mes=p.mes') && helper.includes('b.periodo_anio=p.anio'),'The helper must keep enriching smart monthly-payment fields.');
ok(helper.includes('b.curso_intensivo_id=chosen'),'The helper must keep enriching the selected intensive course.');
ok(!helper.includes("input='/api/pagos-smart.php'"),'The loaded helper must not bypass /api/pagos.php, because that wrapper enqueues the WhatsApp confirmation.');
ok(page.includes('function estadoNotificacionPago(data)'),'Payment page must interpret WhatsApp enqueue status separately from payment success.');
ok(page.includes('Confirmación de WhatsApp preparada para envío.'),'Queued payment notifications must be visible to the administrator.');
ok(page.includes('Pago registrado correctamente, pero la confirmación de WhatsApp no se pudo preparar'),'A notification failure must not be disguised as generic payment success.');
ok(page.includes("reason==='HISTORICAL_PAYMENT'"),'Historical payments must explain the intentional WhatsApp suppression.');
ok(page.includes("SHARKY_DISABLED:'Sharky/WhatsApp está deshabilitado.'") && page.includes("OUTBOX_UNAVAILABLE:'la cola de salida no pudo aceptar el mensaje.'") && page.includes("PAYMENT_CONTACT_INCOMPLETE:'faltan datos de contacto válidos del alumno.'"),'Common notification failure reasons must have actionable admin text.');
ok(page.includes("const estado=estadoNotificacionPago(data);mostrarMensaje(message,estado.texto,estado.tipo)"),'Successful payment POST must render the notification outcome returned by the API.');
ok(page.includes('.message.warning'),'Notification failure must be visually distinct without presenting the financial payment as failed.');

console.log('PAYMENT_WHATSAPP_VISIBILITY_OK');
