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
assert.match(source, /Monteverde: \$500 MXN/);
assert.match(source, /Kit de gorro \+ goggles: \$300 MXN/);
assert.doesNotMatch(source, /Monteverde: \$300 MXN/);
assert.doesNotMatch(source, /Kit de gorro \+ goggles: \$350 MXN/);
assert.match(source, /\$requestedChannel = strtolower/);
assert.match(source, /\$_SERVER\['REMOTE_ADDR'\]/);
assert.match(source, /CANAL ACTUAL: WHATSAPP/);
assert.match(source, /CANAL ACTUAL: WEB/);
assert.match(source, /NUNCA repitas el número de WhatsApp ni el enlace wa\.me/);
assert.match(source, /puede continuar por este mismo chat/);

console.log('Sharky presentation static checks: OK');