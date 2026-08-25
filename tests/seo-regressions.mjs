import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const home = read('public/home.php');
const intensive = read('public/curso-intensivo.php');
const regular = read('public/clases-regulares.php');
const robots = read('public/robots.txt');
const sitemap = read('public/sitemap.xml');
const llms = read('public/llms.txt');
const bootstrap = read('config/backend-bootstrap.php');
const login = read('public/index.php');

assert.match(home, /<title>Clases de natación en Cancún para adultos \| Hache Natación<\/title>/);
assert.match(home, /<link rel="canonical" href="https:\/\/hnatacion\.com\/">/);
assert.match(intensive, /<link rel="canonical" href="https:\/\/hnatacion\.com\/curso-intensivo\.php">/);
assert.match(regular, /<link rel="canonical" href="https:\/\/hnatacion\.com\/clases-regulares\.php">/);

assert.match(bootstrap, /X-Robots-Tag: noindex, nofollow, noarchive/);
assert.match(login, /<meta name="robots" content="noindex,nofollow,noarchive">/);
assert.equal(
  robots,
  'User-agent: *\nAllow: /\nDisallow: /api/\n\nSitemap: https://hnatacion.com/sitemap.xml\n',
  'robots.txt debe permitir rastrear páginas HTML protegidas para que Google pueda leer su noindex'
);
for (const route of ['/dashboard.php', '/alumnos.php', '/pagos.php', '/mi-cuenta.php']) {
  assert.ok(!robots.includes(`Disallow: ${route}`), `${route} debe poder rastrearse para recibir X-Robots-Tag noindex`);
}
assert.ok(robots.includes('Disallow: /api/'), 'La API debe continuar excluida del rastreo');

for (const url of [
  'https://hnatacion.com/',
  'https://hnatacion.com/curso-intensivo.php',
  'https://hnatacion.com/clases-regulares.php',
]) {
  assert.ok(sitemap.includes(`<loc>${url}</loc>`), `Falta ${url} en sitemap.xml`);
}

for (const forbidden of ['/dashboard.php', '/alumnos.php', '/pagos.php', '/api/']) {
  assert.ok(!sitemap.includes(forbidden), `${forbidden} no debe aparecer en sitemap.xml`);
  assert.ok(!llms.includes(forbidden), `${forbidden} no debe aparecer en llms.txt`);
}

assert.match(llms, /^# Hache Natación/m);
assert.match(llms, /\[Hache Natación\]\(https:\/\/hnatacion\.com\/\)/);
assert.match(llms, /\[Curso intensivo de natación para adultos en Cancún\]\(https:\/\/hnatacion\.com\/curso-intensivo\.php\)/);
assert.match(llms, /\[Clases regulares de natación para adultos en Cancún\]\(https:\/\/hnatacion\.com\/clases-regulares\.php\)/);

assert.match(home, /<h1>Clases de natación para adultos en Cancún\.<br>Tu confianza también\.<\/h1>/);
assert.equal((home.match(/Ver curso intensivo de natación →/g) || []).length, 2);
assert.match(home, /Ver clases regulares de natación →/);
assert.match(home, /<meta name="description" content="Clases de natación en Cancún para adultos y adolescentes desde 12 años\./);

console.log('✓ regresiones SEO verificadas');
