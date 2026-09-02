import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../public/api/sharky.php', import.meta.url), 'utf8');

assert.match(source, /\$isFirstTurn = !\$hasAssistantHistory/);
assert.match(source, /asistente IA de Hache Natación/);
assert.match(source, /FORMATO DE RESPUESTA/);
assert.match(source, /viñetas con “•”/);
assert.match(source, /PRIMERA RESPUESTA OBLIGATORIA/);
assert.match(source, /No vuelvas a presentarte en los siguientes turnos/);
assert.match(source, /'max_output_tokens' => 320/);

console.log('Sharky presentation static checks: OK');
