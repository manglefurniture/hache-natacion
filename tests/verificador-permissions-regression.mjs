import fs from 'node:fs';
import assert from 'node:assert/strict';

const auth=fs.readFileSync(new URL('../config/auth.php',import.meta.url),'utf8');
const menu=fs.readFileSync(new URL('../public/assets/backend-menu.js',import.meta.url),'utf8');

assert.match(auth,/function auth_verificador_override\(array \$roles\): bool/);
assert.match(auth,/\['conciliacion-proa\.php','conciliacion-proa-pdf\.php','comisiones-proa\.php'\]/);
assert.match(auth,/\['asistencia\.php','sesiones\.php'\].*\$accion==='ASISTENCIA'/s);
assert.match(auth,/\$script==='ausencias-programadas\.php'.*\['CREAR','CANCELAR'\]/s);
assert.match(auth,/\['\/conciliacion-proa\.php','\/comisiones-proa\.php'\]/);
assert.match(auth,/function auth_active_sede_clave\(\): string[\s\S]*VERIFICADOR[\s\S]*sede_clave/);

assert.match(menu,/Modo supervisor/);
assert.match(menu,/Conciliación PROA','MONTEVERDE'/);
assert.match(menu,/Comisiones PROA','MONTEVERDE'/);
assert.match(menu,/path==='\/conciliacion-proa\.php'/);
assert.match(menu,/path==='\/comisiones-proa\.php'/);
assert.match(menu,/path==='\/ausencias\.php'.*data-cancel/s);

console.log('verificador permissions regression: ok');
