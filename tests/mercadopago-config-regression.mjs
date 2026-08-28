import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const helper = read('config/pasarelas.php');
const api = read('api/mercadopago-config.php');
const panel = read('public/configuracion.php');
const migration = read('database/migrations/20260828_payment_gateway_config.sql');
const gate = read('config/database.php');
const env = read('.env.example');

assert.match(helper, /HACHE_PAYMENT_CONFIG_KEY/);
assert.match(helper, /aes-256-gcm/);
assert.match(helper, /random_bytes\(12\)/);
assert.match(helper, /function pasarela_mercadopago_credenciales\(PDO \$pdo\)/);
assert.match(helper, /function pasarela_mercadopago_publica\(PDO \$pdo\)/);

assert.match(api, /auth_require\(\['ADMIN'\]\)/);
assert.match(api, /access_token_configurado/);
assert.match(api, /webhook_secret_configurado/);
assert.match(api, /pasarela_cifrar\(\$accessToken\)/);
assert.match(api, /https:\/\/api\.mercadopago\.com\/users\/me/);
assert.doesNotMatch(api, /['"]access_token['"]\s*=>\s*\$credentials\[['"]access_token['"]\]/);
assert.doesNotMatch(api, /['"]webhook_secret['"]\s*=>\s*\$credentials\[['"]webhook_secret['"]\]/);

assert.match(panel, /id="mp-access-token" type="password"/);
assert.match(panel, /id="mp-webhook-secret" type="password"/);
assert.match(panel, /Déjalo vacío para conservar el token guardado/);
assert.match(panel, /\/api\/mercadopago-config\.php/);

assert.match(migration, /access_token_enc TEXT/);
assert.match(migration, /webhook_secret_enc TEXT/);
assert.match(migration, /proveedor VARCHAR\(32\) PRIMARY KEY/);
assert.ok(!gate.includes("'/api/mercadopago-config.php'"), 'La API de Mercado Pago no debe estar en la lista de endpoints públicos');
assert.match(env, /HACHE_PAYMENT_CONFIG_KEY=/);

console.log('✓ Mercado Pago: configuración dinámica cifrada y no expuesta al frontend');
