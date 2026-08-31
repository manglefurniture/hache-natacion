import fs from 'node:fs';
import assert from 'node:assert/strict';

const bootstrap=fs.readFileSync(new URL('../config/backend-bootstrap.php',import.meta.url),'utf8');
const fixes=fs.readFileSync(new URL('../public/assets/backend-theme-review-fixes.css',import.meta.url),'utf8');
const sesiones=fs.readFileSync(new URL('../public/sesiones.php',import.meta.url),'utf8');
const resumen=fs.readFileSync(new URL('../public/resumen-financiero.php',import.meta.url),'utf8');

assert.match(bootstrap,/backend-theme-review-fixes\.css\?v=20260831-1/);
const themePos=bootstrap.indexOf("['/assets/backend-theme.css'");
const fixesPos=bootstrap.indexOf("['/assets/backend-theme-review-fixes.css'");
assert.ok(themePos>=0&&fixesPos>themePos,'los fixes finales deben cargar después del theme principal');

// P1 Codex: estado neutral de clases, sin tocar variantes como .estado.cancel.
assert.match(sesiones,/\.estado\{[^}]*background:#e2e8f0/);
assert.match(sesiones,/class="estado \$\{cancel\?'cancel':''\}"/);
assert.match(fixes,/\.clase \.estado\[class="estado"\]/);
assert.doesNotMatch(fixes,/\.estado\.cancel\s*\{/);

// P2 Codex: spans secundarios del resumen, limitados a sus paneles únicos.
assert.match(resumen,/\.row span\{font-size:13px;color:#64748b\}/);
assert.match(fixes,/:where\(#actual,#siguiente,#historial\) \.row>span/);
assert.doesNotMatch(fixes,/html\[data-theme="dark"\] \.row>span\s*\{/);

console.log('BACKEND_DARK_MODE_FINAL_REVIEW_OK');
