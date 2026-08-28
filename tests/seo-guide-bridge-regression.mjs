import assert from 'node:assert/strict';
import fs from 'node:fs';

const metodologia = fs.readFileSync(new URL('../public/metodologia.php', import.meta.url), 'utf8');

assert.match(metodologia, /href="\/guias\/"/, 'Metodología debe enlazar al hub de guías para evitar un cluster huérfano');
assert.match(metodologia, /href="\/guias\/aprender-a-nadar-de-adulto\/"/, 'Metodología debe enlazar una guía principal de aprendizaje adulto');
assert.match(metodologia, /href="\/guias\/curso-intensivo-o-clases-regulares\/"/, 'Metodología debe enlazar la guía comparativa de programas');

console.log('✓ puente interno hacia guías verificado');
