import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const sharkyWrapper = read('api/sharky.php');
const whatsappWrapper = read('api/whatsapp-webhook.php');
const sharky = read('public/api/sharky-v2.php');
const webhook = read('public/api/whatsapp-webhook-v2.php');
const runtime = read('config/sharky-runtime.php');
const validation = read('config/sharky-validation.php');
const database = read('config/database.php');
const transfer = read('config/transferencia-publica.php');
const adminApi = read('api/sharky-admin.php');
const genericConfigApi = read('api/configuracion.php');
const publicRegistration = read('public/registro.php');
const adminPage = read('public/sharky-admin.php');
const configPage = read('public/configuracion.php');

assert.match(sharkyWrapper, /sharky-v2\.php/);
assert.match(whatsappWrapper, /whatsapp-webhook-v2\.php/);
assert.match(database, /\$publicApi\s*=\s*\[[\s\S]*'\/api\/sharky\.php'[\s\S]*'\/api\/whatsapp-webhook\.php'/);
assert.match(database, /\$skipAudit\s*=\s*\[[\s\S]*'\/api\/sharky\.php'[\s\S]*'\/api\/whatsapp-webhook\.php'/);

assert.match(sharky, /CANAL ACTUAL: WHATSAPP/);
assert.match(sharky, /NUNCA le des al cliente el número de WhatsApp ni un enlace wa\.me/);
assert.match(sharky, /hache_sharky_dynamic_context/);
assert.match(sharky, /sharky-internal-whatsapp/);
assert.match(sharky, /'channel'\s*=>\s*\$channel/);
assert.match(sharky, /El intensivo NO cobra inscripción en ninguna sede/);
assert.match(sharky, /al menos 50%/);
assert.match(sharky, /se reintegra el 100%/);
assert.match(sharky, /penalización de \$400 MXN/);
assert.match(sharky, /una familia que se inscribe paga una sola inscripción/);
assert.match(sharky, /NO es obligatorio comprarlos con Hache/);
assert.match(sharky, /Sharky SÍ puede conducir el cierre comercial del intensivo/);
assert.match(sharky, /NO cierra la inscripción de clases regulares/);
assert.match(sharky, /max_output_tokens'\s*=>\s*600/);

assert.match(runtime, /require_once __DIR__\.['"]\/intensivos-estado\.php['"]/);
assert.match(runtime, /require_once __DIR__\.['"]\/sharky-validation\.php['"]/);
assert.match(runtime, /'sharky_precio_intensivo'\s*=>\s*\[\s*'valor'\s*=>\s*'1200'/);
assert.match(runtime, /hache_sharky_config_value_valid\(\$key, \$value\)/);
assert.doesNotMatch(runtime, /\$values\['sharky_precio_intensivo'\]\s*=\s*\(string\)\$defaults/);
assert.match(runtime, /'sharky_inscripcion_monteverde'\s*=>\s*\[\s*'valor'\s*=>\s*'500'/);
assert.match(runtime, /'sharky_kit_gorro_goggles'\s*=>\s*\[\s*'valor'\s*=>\s*'300'/);
assert.match(runtime, /'sharky_link_registro_monteverde'/);
assert.match(runtime, /'sharky_link_registro_palapas'/);
assert.match(runtime, /'sharky_maps_monteverde'/);
assert.match(runtime, /'sharky_maps_palapas'/);
assert.match(runtime, /'sharky_pago_clabe'/);
assert.doesNotMatch(runtime, /sharky_cupo_maximo_intensivo/);
assert.doesNotMatch(runtime, /COUNT\(cia\.id\)|lugares calculados|total_alumnos/);
assert.doesNotMatch(runtime, /cursos_intensivos[\s\S]{0,500}LIMIT\s+8/i);
assert.match(runtime, /\$selectableDates\s*=\s*intensivo_lunes_registro\(10\)/);
assert.match(runtime, /ci\.fecha_inicio IN \(\$marks\)/);
assert.match(runtime, /Fechas de inicio que el registro público acepta actualmente/);
assert.match(runtime, /ORDER BY ci\.fecha_inicio ASC,s\.nombre ASC/);
assert.match(runtime, /function hache_sharky_capacity_request/);
assert.match(runtime, /function hache_sharky_human_request/);
assert.match(runtime, /function hache_sharky_frustration/);
assert.match(runtime, /function hache_sharky_takeover_resume_hash/);
assert.match(runtime, /function hache_sharky_metric_increment/);

assert.match(validation, /function hache_sharky_config_value_valid/);
assert.match(validation, /go\.hnatacion\.com/);
assert.match(validation, /maps\.app\.goo\.gl/);
assert.match(validation, /\^\\d\{18\}\$/);

assert.match(publicRegistration, /intensivo_lunes_registro\(10\)/);
assert.match(publicRegistration, /hache_sharky_business_values\(\$pdo\)/);
assert.match(publicRegistration, /\$intensivoPrecioGeneral=.*sharky_precio_intensivo/);
assert.match(publicRegistration, /\$intensivoPrecio=\$intensivoPrecioGeneral/);
assert.doesNotMatch(publicRegistration, /\$intensivoPrecio\s*=\s*1200\.0/);
assert.match(publicRegistration, /solo puede incorporarse hasta el martes/);

assert.match(transfer, /hache_sharky_business_values/);
assert.match(transfer, /sharky_pago_institucion/);
assert.match(transfer, /sharky_pago_beneficiario/);
assert.match(transfer, /sharky_pago_clabe/);

assert.match(webhook, /'channel'\s*=>\s*'whatsapp'/);
assert.match(webhook, /hache_sharky_human_request/);
assert.match(webhook, /requested_human/);
assert.match(webhook, /hache_sharky_frustration/);
assert.match(webhook, /unresolved/);
assert.match(webhook, /audio\/transcriptions/);
assert.match(webhook, /gpt-4o-mini-transcribe/);
assert.match(webhook, /sharky_escalado_intentos/);
assert.match(webhook, /messages_skipped_takeover/);

assert.match(adminApi, /'RESUME'/);
assert.match(adminApi, /'CONFIG'/);
assert.match(adminApi, /auth_require\(\['ADMIN'\]\)/);
assert.match(adminApi, /ON DUPLICATE KEY UPDATE/);
assert.match(adminApi, /hache_sharky_config_value_valid\(\$key, \$value\)/);
assert.doesNotMatch(adminApi, /sharky_admin_valid_value|sharky_admin_valid_https_url/);

assert.match(genericConfigApi, /array_filter\([\s\S]{0,220}str_starts_with\([\s\S]{0,100}'sharky_'/);
assert.match(genericConfigApi, /if \(str_starts_with\(\$clave, 'sharky_'\)\)/);
assert.match(genericConfigApi, /únicamente desde Sharky Admin/);

assert.match(adminPage, /page_require\(\['ADMIN'\]\)/);
assert.match(adminPage, /Reactivar Sharky/);
assert.match(adminPage, /Fuente de verdad comercial/);
assert.match(adminPage, /Últimos 7 días/);
assert.match(configPage, /href="\/sharky-admin\.php"/);

console.log('Sharky operations suite regressions: OK');
