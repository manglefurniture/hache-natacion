import fs from 'node:fs';
import assert from 'node:assert/strict';

const relief=fs.readFileSync(new URL('../public/assets/backend-relief.css',import.meta.url),'utf8');

// Los controles claros deben conservar una línea superior visible sin perder el brillo interior.
assert.match(relief,/--hache-control-highlight:rgba\(148,163,184,\.34\);/);
assert.match(relief,/border-top-color:var\(--hache-control-highlight\)!important/);
assert.match(relief,/--hache-control-shadow:inset 0 1px 0 rgba\(255,255,255,\.78\)/);

// Los primarios oscuros mantienen su borde superior claro independiente.
assert.match(relief,/border-top-color:rgba\(255,255,255,\.34\)!important/);

console.log('backend relief top edge checks: OK');
