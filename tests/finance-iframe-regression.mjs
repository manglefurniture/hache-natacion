import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(new URL(`../${p}`, import.meta.url), 'utf8');
const bootstrap = read('config/backend-bootstrap.php');
const finanzas = read('public/finanzas.php');
const sedeContext = read('public/assets/sede-context.js');

assert.match(bootstrap, /X-Frame-Options:\s*SAMEORIGIN/);
assert.doesNotMatch(bootstrap, /X-Frame-Options:\s*DENY/);
assert.match(finanzas, /<iframe class="frame" src="\/reportes\.php\?sede=.*embedded=1/);
assert.match(finanzas, /data-src="\/resumen-financiero\.php\?sede=.*embedded=1/);
assert.match(finanzas, /data-src="\/conciliacion-proa\.php\?embedded=1/);
assert.match(sedeContext, /get\('embedded'\)===['"]1['"]\)return/);

console.log('OK: el centro financiero permite iframes internos y evita selectores de sede duplicados.');
