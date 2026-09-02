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
assert.match(styles,/\.comment-replies/);
assert.match(styles,/\.notification-toggle/);
assert.match(styles,/:focus-visible/,'Los controles nuevos deben conservar foco visible');

assert.match(notifications,/historias_confirm_token/);
assert.match(notifications,/historias_cancel_token/);
assert.match(notifications,/hash_hmac\('sha256'/,'Los enlaces deben estar firmados');
assert.match(notifications,/notificacion_estado='ENVIANDO'/,'El envío debe reclamarse antes de llamar al proveedor');
assert.match(notifications,/notificacion_intentos<3/,'Los reintentos deben tener límite');
assert.match(notifications,/c\.estado='APROBADO'/,'Una respuesta solo puede notificar después de aprobarse');
assert.match(notifications,/s\.estado='ACTIVA'/,'Solo un opt-in confirmado puede recibir respuesta');
assert.match(notifications,/no te suscriben a promociones ni newsletters/);
assert.match(notifications,/Dejar de recibir avisos de este comentario/);
assert.doesNotMatch(notifications,/error_log\([^\n]*(?:email|correo|body)/i,'No se deben registrar cuerpos o correos en logs');

assert.match(notificationPage,/X-Robots-Tag: noindex, nofollow, noarchive/);
assert.match(notificationPage,/hash\('sha256',\$token\)/,'La confirmación debe buscar por hash y no por token en claro');
assert.match(notificationPage,/hash_equals\(\$expected,\$token\)/,'La cancelación debe validar el token en tiempo constante');
assert.match(notificationPage,/estado='ACTIVA'/);
assert.match(notificationPage,/estado='CANCELADA'/);
assert.match(notificationPage,/confirm_expires_at/,'La confirmación debe expirar');

assert.match(mailer,/function hache_enviar_correo_transaccional/,'Debe existir un transporte transaccional común');
assert.match(mailer,/https:\/\/api\.resend\.com\/emails/,'El transporte debe seguir el patrón HTTPS de Hache Base');
assert.match(mailer,/HACHE_RESEND_API_KEY/);

assert.match(moderation,/page_require\(\['ADMIN','VERIFICADOR'\]\)/);
assert.match(moderation,/Respuesta a \$\{item\.reply_to_autor/,'Moderación debe mostrar contexto de la respuesta');
assert.match(moderation,/avisos: \$\{String\(item\.aviso_estado\)/,'Moderación puede mostrar estado de avisos sin mostrar correo');
assert.doesNotMatch(moderation,/item\.email|item\.correo/,'Moderación no debe renderizar el correo privado');
assert.match(moderationApi,/auth_require\(\['ADMIN','VERIFICADOR'\]\)/);
assert.match(moderationApi,/auth_csrf_validate/,'La moderación debe validar CSRF');
assert.match(moderationApi,/historias_notificar_respuesta_aprobada/,'La notificación debe dispararse desde la aprobación');
assert.doesNotMatch(moderationApi,/s\.email/,'La API de moderación no debe devolver el correo privado');
for(const action of ['APROBAR','RECHAZAR','OCULTAR','ELIMINAR','BLOQUEAR_ORIGEN','DESBLOQUEAR_ORIGEN'])assert.ok(moderationApi.includes(action),`Falta acción de moderación ${action}`);

assert.match(privacy,/Avisos de respuestas en Historias Hache/);
assert.match(privacy,/no se muestra públicamente/);
assert.match(privacy,/no se utiliza para suscribirla a promociones o newsletters/);
assert.match(menu,/\/historias-moderacion\.php/,'Admin/supervisor debe tener acceso visible a moderación');
assert.match(env,/HACHE_PUBLIC_INTERACTION_SALT=/);
assert.match(env,/firmar enlaces de confirmación\/cancelación/);

console.log('✓ regresiones de Historias verificadas');
