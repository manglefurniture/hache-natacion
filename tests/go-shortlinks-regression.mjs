import fs from 'node:fs';
import assert from 'node:assert/strict';

const monteverde = fs.readFileSync('public/mv/index.php', 'utf8');
const palapas = fs.readFileSync('public/pal/index.php', 'utf8');
const regularMonteverde = fs.readFileSync('public/regular-mv/index.php', 'utf8');
const regularPalapas = fs.readFileSync('public/regular-pal/index.php', 'utf8');

assert.match(monteverde, /Cache-Control: no-store/);
assert.match(monteverde, /Location: https:\/\/hnatacion\.com\/registro\.php\?sede=MONTEVERDE&tipo=INTENSIVO/);
assert.match(monteverde, /true, 302/);

assert.match(palapas, /Cache-Control: no-store/);
assert.match(palapas, /Location: https:\/\/hnatacion\.com\/registro\.php\?sede=PALAPAS&tipo=INTENSIVO/);
assert.match(palapas, /true, 302/);

assert.match(regularMonteverde, /Cache-Control: no-store/);
assert.match(regularMonteverde, /Location: https:\/\/hnatacion\.com\/registro\.php\?sede=MONTEVERDE&tipo=REGULAR/);
assert.match(regularMonteverde, /true, 302/);

assert.match(regularPalapas, /Cache-Control: no-store/);
assert.match(regularPalapas, /Location: https:\/\/hnatacion\.com\/registro\.php\?sede=PALAPAS&tipo=REGULAR/);
assert.match(regularPalapas, /true, 302/);

console.log('go shortlinks regression: ok');
