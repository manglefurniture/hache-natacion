import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=(file)=>fs.readFileSync(path.join(root,file),'utf8');

const migration=read('database/migrations/20260828_historias_interacciones.sql');
const publicApi=read('public/historias/interacciones.php');
const client=read('public/assets/historias-interacciones.js');
const story=read('public/historias/maria-del-carmen.php');
const moderation=read('public/historias-moderacion.php');
const moderationApi=read('public/historias-moderacion-api.php');
const menu=read('public/assets/backend-menu.js');
const env=read('.env.example');

for(const table of ['historia_comentarios','historia_reacciones','historia_bloqueos']){
  assert.match(migration,new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`),`Falta tabla ${table}`);
}
assert.match(migration,/estado ENUM\('PENDIENTE','APROBADO','RECHAZADO','OCULTO','ELIMINADO'\)/);
assert.match(migration,/UNIQUE KEY uq_historia_reaccion_visitante \(historia_slug,visitante_hash\)/);
assert.match(migration,/UNIQUE KEY uq_historia_bloqueo_origen \(origen_hash\)/);
assert.ok(!/\b(?:ip|email|correo|telefono)\s+(?:VARCHAR|CHAR|TEXT)/i.test(migration),'La capa de Historias no debe almacenar IP, email o teléfono en claro');

assert.match(publicApi,/const HISTORIAS_PUBLICAS=\['maria-del-carmen'\]/);
assert.match(publicApi,/hash_hmac\('sha256'/,'Origen y visitante deben anonimizarse con HMAC');
assert.match(publicApi,/HACHE_PUBLIC_INTERACTION_SALT/);
assert.match(publicApi,/mismo_origen\(\)/,'Las mutaciones públicas deben validar origen');
assert.match(publicApi,/origen_bloqueado/);
assert.match(publicApi,/INTERVAL 30 MINUTE/,'Debe existir rate limit de comentarios');
assert.match(publicApi,/INTERVAL 10 MINUTE/,'Debe existir rate limit de reacciones');
assert.match(publicApi,/VALUES\(:historia,:autor,:comentario,'PENDIENTE'/,'Los comentarios nuevos deben quedar pendientes');
assert.match(publicApi,/estado='APROBADO'/,'Solo comentarios aprobados deben salir en la lectura pública');
assert.match(publicApi,/demasiados enlaces/,'Debe existir filtro básico de enlaces');
assert.match(publicApi,/lenguaje_revisar/,'Debe existir señal básica para revisión de lenguaje');
assert.match(publicApi,/nombre_publico/,'La respuesta pública debe reducir la identidad visible');

assert.match(story,/data-story-community data-story="maria-del-carmen"/);
assert.match(story,/Tu comentario quedará pendiente hasta que un moderador lo apruebe/);
assert.match(story,/Mostramos únicamente el primer nombre/);
assert.match(story,/data-reaction="CORAZON"/);
assert.match(story,/data-reaction="APLAUSOS"/);
assert.match(story,/data-reaction="INSPIRA"/);
assert.match(story,/data-reaction="FUERZA"/);
assert.match(story,/data-reaction="SONRISA"/);

assert.match(client,/localStorage\.getItem\(key\)/);
assert.match(client,/textContent = item\.comentario/,'Los comentarios públicos deben renderizarse como texto, no HTML');
assert.ok(!client.includes('innerHTML'),'El cliente de Historias no debe inyectar HTML');
assert.match(client,/accion: 'COMENTARIO'/);
assert.match(client,/accion: 'REACCION'/);

assert.match(moderation,/page_require\(\['ADMIN','VERIFICADOR'\]\)/);
assert.match(moderationApi,/auth_require\(\['ADMIN','VERIFICADOR'\]\)/);
assert.match(moderationApi,/auth_csrf_validate/,'La moderación debe validar CSRF');
for(const action of ['APROBAR','RECHAZAR','OCULTAR','ELIMINAR','BLOQUEAR_ORIGEN','DESBLOQUEAR_ORIGEN']){
  assert.ok(moderationApi.includes(action),`Falta acción de moderación ${action}`);
}
assert.match(menu,/\/historias-moderacion\.php/,'Admin/supervisor debe tener acceso visible a moderación');
assert.match(env,/HACHE_PUBLIC_INTERACTION_SALT=/);

console.log('✓ regresiones de Historias verificadas');
