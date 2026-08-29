import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const home = read('public/home.php');
const intensive = read('public/curso-intensivo.php');
const regular = read('public/clases-regulares.php');
const monteverde = read('public/monteverde.php');
const palapas = read('public/palapas-protudec.php');
const metodologia = read('public/metodologia.php');
const historias = read('public/historias/index.php');
const mariaStory = read('public/historias/maria-del-carmen.php');
const robots = read('public/robots.txt');
const sitemap = read('public/sitemap.xml');
const llms = read('public/llms.txt');
const bootstrap = read('config/backend-bootstrap.php');
const login = read('public/index.php');

const indexablePages = [
  ['public/home.php', 'https://hnatacion.com/', home],
  ['public/curso-intensivo.php', 'https://hnatacion.com/curso-intensivo.php', intensive],
  ['public/clases-regulares.php', 'https://hnatacion.com/clases-regulares.php', regular],
  ['public/monteverde.php', 'https://hnatacion.com/monteverde.php', monteverde],
  ['public/palapas-protudec.php', 'https://hnatacion.com/palapas-protudec.php', palapas],
  ['public/metodologia.php', 'https://hnatacion.com/metodologia.php', metodologia],
];

const guideUrls = [
  'https://hnatacion.com/guias/',
  'https://hnatacion.com/guias/aprender-a-nadar-de-adulto/',
  'https://hnatacion.com/guias/cuanto-tiempo-tarda-aprender-a-nadar/',
  'https://hnatacion.com/guias/perder-miedo-al-agua/',
  'https://hnatacion.com/guias/curso-intensivo-o-clases-regulares/',
  'https://hnatacion.com/guias/primera-clase-de-natacion/',
  'https://hnatacion.com/guias/respiracion-al-nadar-principiantes/',
  'https://hnatacion.com/guias/aprender-a-flotar/',
  'https://hnatacion.com/guias/como-saber-mi-nivel-de-natacion/',
];

const storyUrls = [
  'https://hnatacion.com/historias/',
  'https://hnatacion.com/historias/maria-del-carmen.php',
];

const titles = new Set();
const canonicals = new Set();
for (const [file, url, html] of indexablePages) {
  const title = html.match(/<title>([^<]+)<\/title>/)?.[1]?.trim();
  assert.ok(title, `${file} debe tener <title> descriptivo`);
  assert.ok(!titles.has(title), `${file} no debe duplicar title con otra página objetivo`);
  titles.add(title);

  const canonicalMatches = [...html.matchAll(/<link rel="canonical" href="([^"]+)">/g)];
  assert.equal(canonicalMatches.length, 1, `${file} debe declarar una sola canonical`);
  assert.equal(canonicalMatches[0][1], url, `${file} debe canonicalizar a ${url}`);
  assert.ok(!canonicals.has(url), `${url} no debe repetirse como canonical de otra página objetivo`);
  canonicals.add(url);

  assert.match(html, /<meta name="description" content="[^"]+">/, `${file} debe tener meta description útil`);
  assert.match(html, /<meta name="robots" content="index,follow[^\"]*">/, `${file} debe ser indexable intencionalmente`);
  assert.match(html, /<h1[^>]*>[\s\S]*?<\/h1>/, `${file} debe tener un heading principal visible`);

  const jsonLdBlocks = [...html.matchAll(/<script type="application\/ld\+json">\s*([\s\S]*?)\s*<\/script>/g)];
  assert.ok(jsonLdBlocks.length > 0, `${file} debe conservar datos estructurados cuando se declaran como parte de la plantilla SEO`);
  for (const [, json] of jsonLdBlocks) {
    assert.doesNotThrow(() => JSON.parse(json), `${file} contiene JSON-LD inválido`);
  }

  for (const img of html.match(/<img\b[^>]*>/g) || []) {
    assert.match(img, /\balt="[^"]*"/, `${file} contiene una imagen sin atributo alt`);
    assert.match(img, /\bwidth="\d+"/, `${file} contiene una imagen sin width reservado`);
    assert.match(img, /\bheight="\d+"/, `${file} contiene una imagen sin height reservado`);
  }
}

assert.match(home, /<title>Clases de natación en Cancún para adultos \| Hache Natación<\/title>/);
assert.match(home, /<h1>Clases de natación para adultos en Cancún\.<br>Tu confianza también\.<\/h1>/);
assert.equal((home.match(/Ver curso intensivo de natación →/g) || []).length, 2);
assert.match(home, /Ver clases regulares de natación →/);
assert.match(home, /<meta name="description" content="Clases de natación en Cancún para adultos y adolescentes desde 12 años\./);
assert.match(home, /href="\/monteverde\.php"/);
assert.match(home, /href="\/palapas-protudec\.php"/);
assert.match(home, /href="\/metodologia\.php"/);
assert.ok((home.match(/href="\/historias\/"/g) || []).length >= 3, 'Home debe enlazar Historias desde navegación y contenido');
assert.match(home, /href="\/historias\/maria-del-carmen\.php"/);
assert.match(home, /window\.matchMedia\('\(min-width: 761px\)'\)/);
assert.match(home, /<a href="\/privacidad\/" style="display:block;margin:0 0 8px;color:#527087;font-size:12px;text-align:center">Privacidad<\/a>/);

assert.match(intensive, /<title>Curso intensivo de natación para adultos en Cancún \| Hache<\/title>/);
assert.match(regular, /<title>Clases de natación para adultos en Cancún \| Plan regular<\/title>/);
assert.match(intensive, /<h1>Curso intensivo de natación para adultos en Cancún\.<br>Una base que se queda\.<\/h1>/);
assert.match(regular, /<h1>Clases regulares de natación para adultos en Cancún\.<br>Seguir avanzando\.<\/h1>/);
assert.match(intensive, /<a href="\/clases-regulares\.php">Clases regulares de natación<\/a>/);
assert.match(regular, /<a href="\/curso-intensivo\.php">Curso intensivo de natación<\/a>/);

assert.match(monteverde, /<h1>Clases de natación en Monteverde, Cancún\.<\/h1>/);
assert.match(monteverde, /href="\/palapas-protudec\.php"/);
assert.match(monteverde, /href="\/curso-intensivo\.php"/);
assert.match(monteverde, /href="\/clases-regulares\.php"/);
assert.match(palapas, /<h1>Clases de natación en Palapas Protudec, Cancún\.<\/h1>/);
assert.match(palapas, /href="\/monteverde\.php"/);
assert.match(palapas, /href="\/curso-intensivo\.php"/);
assert.match(palapas, /href="\/clases-regulares\.php"/);
assert.match(metodologia, /<h1>Aprender a nadar también es ganar confianza\.<\/h1>/);
assert.match(metodologia, /href="\/monteverde\.php"/);
assert.match(metodologia, /href="\/palapas-protudec\.php"/);

assert.match(historias, /<title>Historias Hache \| Personas reales que aprendieron a nadar<\/title>/);
assert.match(historias, /<meta name="robots" content="index,follow[^\"]*">/);
assert.match(historias, /<link rel="canonical" href="https:\/\/hnatacion\.com\/historias\/">/);
assert.match(historias, /href="\/historias\/maria-del-carmen\.php"/);
assert.match(historias, /"@type":"CollectionPage"/);
assert.match(mariaStory, /<meta name="robots" content="index,follow[^\"]*">/);
assert.match(mariaStory, /<link rel="canonical" href="https:\/\/hnatacion\.com\/historias\/maria-del-carmen\.php">/);
assert.match(mariaStory, /data-story-community data-story="maria-del-carmen"/);
assert.match(mariaStory, /\/assets\/historias-interacciones\.js/);

assert.match(bootstrap, /X-Robots-Tag: noindex, nofollow, noarchive/);
for (const route of ['/monteverde.php', '/palapas-protudec.php', '/metodologia.php']) {
  assert.ok(bootstrap.includes(`'${route}'`), `${route} debe estar en la allowlist pública de backend-bootstrap.php`);
}
assert.match(bootstrap, /str_starts_with\(\$currentPath, '\/historias\/'\)/, 'Historias debe conservar acceso público');
assert.match(login, /<meta name="robots" content="noindex,nofollow,noarchive">/);

const expectedRobots = `# OpenAI search crawler: public pages remain discoverable in ChatGPT search.
User-agent: OAI-SearchBot
Allow: /
Disallow: /api/

# OpenAI training crawler: kept as a separate, explicit policy.
# This preserves the site's current crawl behavior while allowing future changes
# without affecting search inclusion.
User-agent: GPTBot
Allow: /
Disallow: /api/

User-agent: *
Allow: /
Disallow: /api/

Sitemap: https://hnatacion.com/sitemap.xml
`;
assert.equal(
  robots,
  expectedRobots,
  'robots.txt debe separar búsqueda y entrenamiento sin cambiar la política de rastreo pública'
);
assert.match(robots, /User-agent: OAI-SearchBot\nAllow: \/\nDisallow: \/api\//, 'OAI-SearchBot debe poder rastrear la superficie pública');
assert.match(robots, /User-agent: GPTBot\nAllow: \/\nDisallow: \/api\//, 'GPTBot debe conservar una política separada y explícita');
for (const route of ['/dashboard.php', '/alumnos.php', '/pagos.php', '/mi-cuenta.php']) {
  assert.ok(!robots.includes(`Disallow: ${route}`), `${route} debe poder rastrearse para recibir X-Robots-Tag noindex`);
}
assert.ok(robots.includes('Disallow: /api/'), 'La API debe continuar excluida del rastreo');

const expectedUrls = [...indexablePages.map(([, url]) => url), ...guideUrls, ...storyUrls];
const sitemapUrls = [...sitemap.matchAll(/<loc>(https:\/\/hnatacion\.com\/[^<]*)<\/loc>/g)]
  .map((match) => match[1])
  .filter((url) => !url.includes('/assets/'));
assert.deepEqual(sitemapUrls, expectedUrls, 'sitemap.xml debe listar únicamente las páginas SEO objetivo, en orden conocido');

for (const url of expectedUrls) {
  assert.ok(sitemap.includes(`<loc>${url}</loc>`), `Falta ${url} en sitemap.xml`);
  if (url !== 'https://hnatacion.com/') {
    assert.match(llms, new RegExp(`\\(${escapeRegExp(url)}\\)`), `Falta ${url} en llms.txt`);
  }
}

const sitemapUrlBlocks = [...sitemap.matchAll(/<url>([\s\S]*?)<\/url>/g)].map((match) => match[1]);
const historiasSitemapBlock = sitemapUrlBlocks.find((block) => block.includes('<loc>https://hnatacion.com/historias/</loc>'));
assert.ok(historiasSitemapBlock, 'El sitemap debe contener el bloque del hub de Historias');
assert.match(
  historiasSitemapBlock,
  /<image:loc>https:\/\/hnatacion\.com\/assets\/file_00000000ddc881fba7297eac7c62765c\.png<\/image:loc>/,
  'El hub de Historias debe declarar su imagen principal en su propio bloque <url>'
);

for (const forbidden of ['/dashboard.php', '/alumnos.php', '/pagos.php', '/api/', '/historias-moderacion.php', '/historias/interacciones.php']) {
  assert.ok(!sitemap.includes(forbidden), `${forbidden} no debe aparecer en sitemap.xml`);
  assert.ok(!llms.includes(forbidden), `${forbidden} no debe aparecer en llms.txt`);
}

assert.match(llms, /^# Hache Natación/m);
assert.match(llms, /\[Hache Natación\]\(https:\/\/hnatacion\.com\/\)/);
assert.match(llms, /\[Curso intensivo de natación para adultos en Cancún\]\(https:\/\/hnatacion\.com\/curso-intensivo\.php\)/);
assert.match(llms, /\[Clases regulares de natación para adultos en Cancún\]\(https:\/\/hnatacion\.com\/clases-regulares\.php\)/);
assert.match(llms, /\[PROA Monteverde\]\(https:\/\/hnatacion\.com\/monteverde\.php\)/);
assert.match(llms, /\[Palapas Protudec\]\(https:\/\/hnatacion\.com\/palapas-protudec\.php\)/);
assert.match(llms, /\[Metodología de Hache Natación\]\(https:\/\/hnatacion\.com\/metodologia\.php\)/);
assert.match(llms, /\[Guías de Hache Natación\]\(https:\/\/hnatacion\.com\/guias\/\)/);
assert.match(llms, /\[Historias Hache\]\(https:\/\/hnatacion\.com\/historias\/\)/);
assert.match(llms, /\[María del Carmen: del miedo al agua a un logro de vida\]\(https:\/\/hnatacion\.com\/historias\/maria-del-carmen\.php\)/);

console.log('✓ regresiones SEO verificadas');
