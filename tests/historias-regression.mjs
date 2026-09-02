import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=(file)=>fs.readFileSync(path.join(root,file),'utf8');

const migration=read('database/migrations/20260828_historias_interacciones.sql');
const repliesMigration=read('database/migrations/20260902_historias_respuestas_notificaciones.sql');
const migrator=read('bin/migrate-historias.php');
const publicApi=read('public/historias/interacciones.php');
const client=read('public/assets/historias-interacciones.js');
const styles=read('public/assets/historias-publicas.css');
const story=read('public/historias/maria-del-carmen.php');
const notifications=read('config/historias-notificaciones.php');
const notificationPage=read('public/historias/notificaciones.php');
const mailer=read('config/notificaciones-email.php');
const moderation=read('public/historias-moderacion.php');
const moderationApi=read('public/historias-moderacion-api.php');
const privacy=read('public/privacidad/index.php');
const menu=read('public/assets/backend-menu.js');
const env=read('.env.example');

for(const table of ['historia_comentarios','historia_reacciones','historia_bloqueos']){
  assert.match(migration,new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`),`Falta tabla ${table}`);
}
assert.match(migration,/estado ENUM\('PENDIENTE','APROBADO','RECHAZADO','OCULTO','ELIMINADO'\)/);
assert.match(migration,/UNIQUE KEY uq_historia_reaccion_visitante \(historia_slug,visitante_hash\)/);
assert.match(migration,/UNIQUE KEY uq_historia_bloqueo_origen \(origen_hash\)/);
assert.ok(!/\b(?:ip|email|correo|telefono)\s+(?:VARCHAR|CHAR|TEXT)/i.test(migration),'La capa original de Historias no debe almacenar datos de contacto en claro');

for(const table of ['historia_respuestas','historia_comentario_suscripciones']){
  assert.match(repliesMigration,new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`),`Falta tabla ${table}`);
}
assert.match(repliesMigration,/parent_id CHAR\(36\) NOT NULL/,'Las respuestas deben conservar el comentario raíz');
assert.match(repliesMigration,/reply_to_id CHAR\(36\) NOT NULL/,'Las respuestas deben conservar el destinatario directo');
assert.match(repliesMigration,/email VARCHAR\(254\) NOT NULL/,'El correo opcional debe vivir en la tabla privada de suscripciones');
assert.match(repliesMigration,/estado ENUM\('PENDIENTE','ACTIVA','CANCELADA'\)/,'El consentimiento debe ser confirmable y cancelable');
assert.match(repliesMigration,/confirm_token_hash CHAR\(64\) NOT NULL/,'El token de confirmación debe persistirse solo como hash');
assert.match(repliesMigration,/notificacion_estado ENUM\('NO_APLICA','PENDIENTE','ENVIANDO','ENVIADA','FALLO'\)/,'El envío debe tener un estado persistente/idempotente');
assert.match(repliesMigration,/confirmacion_estado ENUM\('PENDIENTE','ENVIANDO','ENVIADA','FALLO'\)/,'El double opt-in también debe reclamarse idempotentemente');
assert.match(migrator,/20260902_historias_respuestas_notificaciones\.sql/,'El migrador de Historias debe aplicar la extensión nueva');

assert.match(publicApi,/const HISTORIAS_PUBLICAS=\['maria-del-carmen'\]/);
assert.match(publicApi,/hash_hmac\('sha256'/,'Origen y visitante deben anonimizarse con HMAC');
assert.match(publicApi,/HACHE_PUBLIC_INTERACTION_SALT/);
assert.match(publicApi,/mismo_origen\(\)/,'Las mutaciones públicas deben validar origen');
assert.match(publicApi,/parse_url\(\$raw,PHP_URL_HOST\)/,'Origin/Referer opacos o inválidos no deben saltarse el control');
assert.match(publicApi,/Tipo de contenido no permitido/,'Las mutaciones públicas deben exigir JSON');
assert.match(publicApi,/security_rate_limit_record/,'Historias debe usar el limitador atómico compartido');
assert.match(publicApi,/historias-comentarios-cooldown/);
assert.match(publicApi,/historias-comentarios-ventana/);
assert.match(publicApi,/historias-reacciones/);
assert.match(publicApi,/origen_bloqueado/);
assert.match(publicApi,/VALUES\(:id,:historia,:autor,:comentario,'PENDIENTE'/,'Comentarios y respuestas nuevos deben quedar pendientes');
assert.match(publicApi,/INSERT INTO historia_respuestas/,'Una respuesta debe quedar asociada a su comentario');
assert.match(publicApi,/INSERT INTO historia_comentario_suscripciones/,'El opt-in debe persistirse separado del comentario público');
assert.match(publicApi,/\$pdo->commit\(\);\$baseMessage=/,'Los efectos por correo deben ocurrir después del commit');
assert.match(publicApi,/salida_con_tarea/,'La respuesta HTTP debe poder terminar antes del correo transaccional');
assert.match(publicApi,/estado='APROBADO'/,'Solo comentarios aprobados deben salir en la lectura pública');
assert.match(publicApi,/respuestas'=>\[\]/,'La lectura pública debe exponer respuestas agrupadas');
assert.match(publicApi,/r\.parent_id IN \(/,'Las respuestas deben limitarse a los hilos raíz realmente mostrados antes del LIMIT');
assert.match(publicApi,/ORDER BY c\.created_at DESC LIMIT 250/,'El tope global debe conservar primero las respuestas más recientes');
assert.match(publicApi,/array_reverse\(\$st->fetchAll\(\)\)/,'Las respuestas recientes seleccionadas deben volver a mostrarse en orden cronológico');
assert.match(publicApi,/comentario_objetivo/,'El permalink debe poder pedir explícitamente su hilo');
assert.match(publicApi,/\$targetRootId!==null&&!isset\(\$positions\[\$targetRootId\]\)/,'La raíz enlazada debe cargarse aunque quede fuera de las 50 recientes');
assert.match(publicApi,/\$targetReplyId!==null/,'La respuesta enlazada debe poder cargarse aunque quede fuera del tope global');
assert.match(publicApi,/respuestas_habilitadas/,'La API debe declarar si la migración nueva está disponible');
assert.match(publicApi,/information_schema\.TABLES/,'El código nuevo debe degradar con seguridad antes de aplicar la migración');
assert.doesNotMatch(publicApi,/SELECT[^;]+s\.email[^;]+salida/si,'La API pública no debe exponer correos de suscripción');
assert.match(publicApi,/demasiados enlaces/,'Debe existir filtro básico de enlaces');
assert.match(publicApi,/lenguaje_revisar/,'Debe existir señal básica para revisión de lenguaje');
assert.match(publicApi,/nombre_publico/,'La respuesta pública debe reducir la identidad visible');

assert.match(story,/data-story-community data-story="maria-del-carmen"/);
assert.match(story,/Correo electrónico \(opcional\)/);
assert.match(story,/name="notificar_respuestas"/);
assert.match(story,/no te suscribe a promociones/);
assert.match(story,/Mostramos únicamente el primer nombre/);
for(const reaction of ['CORAZON','APLAUSOS','INSPIRA','FUERZA','SONRISA'])assert.match(story,new RegExp(`data-reaction="${reaction}"`));

assert.match(client,/localStorage\.getItem\(key\)/);
assert.match(client,/body\.textContent = item\.comentario/,'Los comentarios públicos deben renderizarse como texto, no HTML');
assert.ok(!client.includes('innerHTML'),'El cliente de Historias no debe inyectar HTML');
assert.match(client,/responder_a: replyTo/,'El cliente debe enviar el comentario objetivo');
assert.match(client,/notificar_respuestas: formData\.get\('notificar_respuestas'\) === '1'/,'El opt-in debe ser explícito');
assert.match(client,/email\.required = notify\.checked/,'El correo debe ser obligatorio solo al pedir avisos');
assert.match(client,/reply-form-wrap/,'Debe existir formulario de respuesta contextual');
assert.match(client,/comentario-\$\{item\.id\}/,'Los comentarios necesitan anclas estables para el enlace del correo');
assert.match(client,/location\.hash\.match/,'El cliente debe extraer el comentario objetivo desde el permalink');
assert.match(client,/params\.set\('comentario_objetivo', target\)/,'El cliente debe solicitar explícitamente el hilo enlazado');
assert.match(client,/requestAnimationFrame\(focusPermalinkTarget\)/,'El permalink debe enfocarse después del render asíncrono');
assert.match(client,/setFeatureAvailability\(data\.respuestas_habilitadas === true\)/,'La UI debe ocultar replies/avisos mientras falte la migración');
assert.match(styles,/\.comment-replies/);
assert.match(styles,/\.notification-toggle/);
assert.match(styles,/:focus-visible/,'Los controles nuevos deben conservar foco visible');

assert.match(notifications,/historias_confirm_token/);
assert.match(notifications,/historias_cancel_token/);
assert.match(notifications,/hash_hmac\('sha256'/,'Los enlaces deben estar firmados');
assert.match(notifications,/confirmacion_estado='ENVIANDO'/,'La confirmación debe reclamarse antes de contactar al proveedor');
assert.match(notifications,/notificacion_estado='ENVIANDO'/,'El envío debe reclamarse antes de llamar al proveedor');
assert.match(notifications,/notificacion_intentos<3/,'Los reintentos deben tener límite');
assert.match(notifications,/EXISTS\(SELECT 1 FROM historia_comentarios root WHERE root\.id=historia_respuestas\.parent_id AND root\.estado='APROBADO'\)/,'El claim debe exigir que la raíz siga pública');
assert.match(notifications,/SELECT id,estado FROM historia_comentarios WHERE id IN \(:root,:target,:reply\) ORDER BY id FOR UPDATE/,'El envío debe serializar cambios de visibilidad del hilo');
assert.match(notifications,/SELECT email FROM historia_comentario_suscripciones WHERE comentario_id=:id AND estado='ACTIVA' FOR UPDATE/,'La baja debe serializarse con cualquier envío pendiente');
assert.match(notifications,/historias_reintentar_correo_comentario/,'Debe existir una ruta explícita para reintentar fallos transitorios');
assert.match(notifications,/historias-confirmacion\//,'La confirmación debe reutilizar una clave idempotente estable');
assert.match(notifications,/historias-respuesta\//,'El aviso de respuesta debe reutilizar una clave idempotente estable');
assert.match(notifications,/\$viewUrl=historias_url_comentario\(\(string\)\$row\['historia_slug'\],\$respuestaId\)/,'El aviso debe enlazar la respuesta aprobada que originó el correo');
assert.match(notifications,/\(\$states\[\$respuestaId\]\?\?null\)!=='APROBADO'/,'La respuesta debe seguir aprobada justo antes del envío');
assert.match(notifications,/s\.estado='ACTIVA'/,'Solo un opt-in confirmado puede alcanzar el claim de respuesta');
assert.match(notifications,/no te suscriben a promociones ni newsletters/);
assert.match(notifications,/Dejar de recibir avisos de este comentario/);
assert.doesNotMatch(notifications,/error_log\([^\n]*(?:email|correo|body)/i,'No se deben registrar cuerpos o correos en logs');

assert.match(notificationPage,/X-Robots-Tag: noindex, nofollow, noarchive/);
assert.match(notificationPage,/hash\('sha256',\$token\)/,'La confirmación debe buscar por hash y no por token en claro');
assert.match(notificationPage,/hash_equals\(\$expected,\$token\)/,'La cancelación debe validar el token en tiempo constante');
assert.match(notificationPage,/method="post" action="\/historias\/notificaciones\.php"/,'Confirmar o cancelar debe requerir un POST explícito');
assert.match(notificationPage,/estado='ACTIVA'/);
assert.match(notificationPage,/estado='CANCELADA'/);
assert.match(notificationPage,/confirm_expires_at/,'La confirmación debe expirar');

assert.match(mailer,/function hache_enviar_correo_transaccional/,'Debe existir un transporte transaccional común');
assert.match(mailer,/https:\/\/api\.resend\.com\/emails/,'El transporte debe seguir el patrón HTTPS de Hache Base');
assert.match(mailer,/HACHE_RESEND_API_KEY/);
assert.match(mailer,/Idempotency-Key:/,'Los reintentos deben propagarse a Resend con Idempotency-Key');
assert.match(mailer,/strlen\(\$idempotencyKey\)>256/,'La clave de idempotencia debe respetar el límite del proveedor');
assert.match(mailer,/str_contains\(\$idempotencyKey,"\\r"\)/,'La clave de idempotencia no debe permitir inyección de headers');

assert.match(moderation,/page_require\(\['ADMIN','VERIFICADOR'\]\)/);
assert.match(moderation,/Respuesta a \$\{item\.reply_to_autor/,'Moderación debe mostrar contexto de la respuesta');
assert.match(moderation,/avisos: \$\{String\(item\.aviso_estado\)/,'Moderación puede mostrar estado de avisos sin mostrar correo');
assert.match(moderation,/REINTENTAR_CORREO/,'Moderación debe ofrecer el reintento explícito cuando corresponda');
assert.doesNotMatch(moderation,/item\.(?:email|correo)(?![_a-zA-Z0-9])/,'Moderación no debe renderizar el correo privado');
assert.match(moderationApi,/auth_require\(\['ADMIN','VERIFICADOR'\]\)/);
assert.match(moderationApi,/auth_csrf_validate/,'La moderación debe validar CSRF');
assert.match(moderationApi,/historias_notificar_respuesta_aprobada/,'La notificación debe dispararse desde la aprobación');
assert.match(moderationApi,/REINTENTAR_CORREO/,'La API de moderación debe soportar el reintento explícito');
assert.match(moderationApi,/s\.confirm_expires_at>=NOW\(\)/,'Un retry de confirmación solo debe mostrarse mientras el enlace siga vigente');
assert.match(moderationApi,/confirm_claim_reintentable/,'La elegibilidad de confirmación debe reutilizar el predicado del claim real');
assert.match(moderationApi,/correo_reintentable_detalle/,'La acción POST debe revalidar que todavía exista un reintento posible');
assert.match(moderationApi,/information_schema\.TABLES/,'La moderación debe seguir funcionando antes de aplicar la migración');
assert.doesNotMatch(moderationApi,/s\.email/,'La API de moderación no debe devolver el correo privado');
for(const action of ['APROBAR','RECHAZAR','OCULTAR','ELIMINAR','BLOQUEAR_ORIGEN','DESBLOQUEAR_ORIGEN'])assert.ok(moderationApi.includes(action),`Falta acción de moderación ${action}`);

assert.match(privacy,/Avisos de respuestas en Historias Hache/);
assert.match(privacy,/no se muestra públicamente/);
assert.match(privacy,/no se utiliza para suscribirla a promociones o newsletters/);
assert.match(menu,/\/historias-moderacion\.php/,'Admin/supervisor debe tener acceso visible a moderación');
assert.match(env,/HACHE_PUBLIC_INTERACTION_SALT=/);
assert.match(env,/firmar enlaces de confirmación\/cancelación/);

console.log('✓ regresiones de Historias verificadas');