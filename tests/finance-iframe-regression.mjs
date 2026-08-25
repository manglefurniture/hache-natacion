import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(new URL(`../${p}`, import.meta.url), 'utf8');
const bootstrap = read('config/backend-bootstrap.php');
const finanzas = read('public/finanzas.php');

assert.match(bootstrap, /X-Frame-Options:\s*SAMEORIGIN/);
assert.doesNotMatch(bootstrap, /X-Frame-Options:\s*DENY/);
assert.match(finanzas, /<iframe class="frame" src="\/reportes\.php\?sede=/);
assert.match(finanzas, /data-src="\/resumen-financiero\.php\?sede=/);

console.log('OK: el centro financiero permite iframes internos sin habilitar framing externo.');
