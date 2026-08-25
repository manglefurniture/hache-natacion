import fs from 'node:fs';

function replaceExact(source, from, to, expected = 1) {
  const count = source.split(from).length - 1;
  if (count !== expected) {
    throw new Error(`Esperaba ${expected} coincidencia(s) y encontré ${count}: ${from}`);
  }
  return source.split(from).join(to);
}

const intensivePath = 'public/curso-intensivo.php';
let intensive = fs.readFileSync(intensivePath, 'utf8');
intensive = replaceExact(
  intensive,
  '<h1>Curso intensivo de natación.<br>Una base que se queda.</h1>',
  '<h1>Curso intensivo de natación para adultos en Cancún.<br>Una base que se queda.</h1>'
);
intensive = replaceExact(
  intensive,
  '<a href="/clases-regulares.php">Clases regulares</a>',
  '<a href="/clases-regulares.php">Clases regulares de natación</a>'
);
fs.writeFileSync(intensivePath, intensive);

const regularPath = 'public/clases-regulares.php';
let regular = fs.readFileSync(regularPath, 'utf8');
regular = replaceExact(
  regular,
  '<h1>Clases regulares de natación.<br>Seguir avanzando.</h1>',
  '<h1>Clases regulares de natación para adultos en Cancún.<br>Seguir avanzando.</h1>'
);
regular = replaceExact(
  regular,
  '<a href="/curso-intensivo.php">Curso intensivo</a>',
  '<a href="/curso-intensivo.php">Curso intensivo de natación</a>'
);
fs.writeFileSync(regularPath, regular);

const sitemapPath = 'public/sitemap.xml';
let sitemap = fs.readFileSync(sitemapPath, 'utf8');
sitemap = replaceExact(
  sitemap,
  '<loc>https://hnatacion.com/curso-intensivo.php</loc>\n    <lastmod>2026-08-22</lastmod>',
  '<loc>https://hnatacion.com/curso-intensivo.php</loc>\n    <lastmod>2026-08-24</lastmod>'
);
sitemap = replaceExact(
  sitemap,
  '<loc>https://hnatacion.com/clases-regulares.php</loc>\n    <lastmod>2026-08-22</lastmod>',
  '<loc>https://hnatacion.com/clases-regulares.php</loc>\n    <lastmod>2026-08-24</lastmod>'
);
fs.writeFileSync(sitemapPath, sitemap);

const testsPath = 'tests/seo-regressions.mjs';
let tests = fs.readFileSync(testsPath, 'utf8');
tests = replaceExact(
  tests,
  "console.log('✓ regresiones SEO verificadas');",
  "assert.match(intensive, /<title>Curso intensivo de natación para adultos en Cancún \\| Hache<\\/title>/);\nassert.match(regular, /<title>Clases de natación para adultos en Cancún \\| Plan regular<\\/title>/);\nassert.match(intensive, /<h1>Curso intensivo de natación para adultos en Cancún\\.<br>Una base que se queda\\.<\\/h1>/);\nassert.match(regular, /<h1>Clases regulares de natación para adultos en Cancún\\.<br>Seguir avanzando\\.<\\/h1>/);\nassert.match(intensive, /<a href=\"\\/clases-regulares\\.php\">Clases regulares de natación<\\/a>/);\nassert.match(regular, /<a href=\"\\/curso-intensivo\\.php\">Curso intensivo de natación<\\/a>/);\n\nconsole.log('✓ regresiones SEO verificadas');"
);
fs.writeFileSync(testsPath, tests);

console.log('SEO on-page de páginas de programa aplicado con reemplazos verificados.');
