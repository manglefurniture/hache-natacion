import fs from 'node:fs';

function replaceExact(source, from, to, expected = 1) {
  const count = source.split(from).length - 1;
  if (count !== expected) {
    throw new Error(`Esperaba ${expected} coincidencia(s) y encontré ${count}: ${from}`);
  }
  return source.split(from).join(to);
}

const homePath = 'public/home.php';
let home = fs.readFileSync(homePath, 'utf8');

home = replaceExact(home,
  '<title>Clases de natación para adultos en Cancún | Hache</title>',
  '<title>Clases de natación en Cancún para adultos | Hache Natación</title>'
);
home = replaceExact(home,
  '<meta name="description" content="Clases de natación para adultos y adolescentes desde 12 años en Cancún. Aprende desde cero o mejora técnica en Monteverde y Palapas Protudec.">',
  '<meta name="description" content="Clases de natación en Cancún para adultos y adolescentes desde 12 años. Aprende desde cero o mejora técnica en PROA Monteverde y Palapas Protudec.">'
);
home = replaceExact(home,
  '<meta property="og:title" content="Clases de natación para adultos en Cancún | Hache">',
  '<meta property="og:title" content="Clases de natación en Cancún para adultos | Hache Natación">'
);
home = replaceExact(home,
  '<meta property="og:description" content="Aprende desde cero o mejora tu técnica con clases para adultos y adolescentes desde 12 años en Cancún.">',
  '<meta property="og:description" content="Clases de natación en Cancún para adultos y adolescentes desde 12 años. Empieza desde cero o mejora tu técnica.">'
);
home = replaceExact(home,
  '<meta name="twitter:title" content="Clases de natación para adultos en Cancún | Hache">',
  '<meta name="twitter:title" content="Clases de natación en Cancún para adultos | Hache Natación">'
);
home = replaceExact(home,
  '<meta name="twitter:description" content="Aprende desde cero o mejora tu técnica con clases para adultos y adolescentes desde 12 años en Cancún.">',
  '<meta name="twitter:description" content="Clases de natación en Cancún para adultos y adolescentes desde 12 años. Empieza desde cero o mejora tu técnica.">'
);
home = replaceExact(home,
  '"name": "Clases de natación para adultos en Cancún | Hache",',
  '"name": "Clases de natación en Cancún para adultos | Hache Natación",'
);
home = replaceExact(home,
  '"description": "Clases de natación para adultos y adolescentes desde 12 años en Cancún.",',
  '"description": "Clases de natación en Cancún para adultos y adolescentes desde 12 años.",'
);
home = replaceExact(home,
  '<h1>Tu nivel cambia.<br>Tu confianza también.</h1>',
  '<h1>Clases de natación para adultos en Cancún.<br>Tu confianza también.</h1>'
);
home = replaceExact(home,
  '<strong>Ver curso intensivo →</strong>',
  '<strong>Ver curso intensivo de natación →</strong>',
  2
);
home = replaceExact(home,
  '<strong>Ver clases regulares →</strong>',
  '<strong>Ver clases regulares de natación →</strong>'
);

fs.writeFileSync(homePath, home);

const sitemapPath = 'public/sitemap.xml';
let sitemap = fs.readFileSync(sitemapPath, 'utf8');
sitemap = replaceExact(sitemap,
  '<loc>https://hnatacion.com/</loc>\n    <lastmod>2026-08-22</lastmod>',
  '<loc>https://hnatacion.com/</loc>\n    <lastmod>2026-08-24</lastmod>'
);
fs.writeFileSync(sitemapPath, sitemap);

const testsPath = 'tests/seo-regressions.mjs';
let tests = fs.readFileSync(testsPath, 'utf8');
tests = replaceExact(tests,
  'assert.match(home, /<title>Clases de natación para adultos en Cancún \\| Hache<\\/title>/);',
  'assert.match(home, /<title>Clases de natación en Cancún para adultos \\| Hache Natación<\\/title>/);'
);
tests = replaceExact(tests,
  "console.log('✓ regresiones SEO verificadas');",
  "assert.match(home, /<h1>Clases de natación para adultos en Cancún\\.<br>Tu confianza también\\.<\\/h1>/);\nassert.equal((home.match(/Ver curso intensivo de natación →/g) || []).length, 2);\nassert.match(home, /Ver clases regulares de natación →/);\nassert.match(home, /<meta name=\"description\" content=\"Clases de natación en Cancún para adultos y adolescentes desde 12 años\\./);\n\nconsole.log('✓ regresiones SEO verificadas');"
);
fs.writeFileSync(testsPath, tests);

console.log('SEO on-page de la home aplicado con reemplazos verificados.');
