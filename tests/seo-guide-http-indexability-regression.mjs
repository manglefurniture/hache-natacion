import assert from 'node:assert/strict';
import fs from 'node:fs';

const bootstrap = fs.readFileSync(new URL('../config/backend-bootstrap.php', import.meta.url), 'utf8');

assert.match(
  bootstrap,
  /\$isPublicGuide\s*=\s*\$currentPath\s*===\s*'\/guias'\s*\|\|\s*str_starts_with\(\$currentPath, '\/guias\/'\)/,
  'Las rutas /guias deben clasificarse explícitamente como superficie pública SEO'
);

assert.match(
  bootstrap,
  /if \(\$isPublicGuide\) \{[\s\S]*?Cache-Control: public, max-age=300, stale-while-revalidate=60[\s\S]*?X-Robots-Tag: index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1[\s\S]*?\} else \{[\s\S]*?Cache-Control: private, no-store, max-age=0/,
  'Las guías deben recibir headers públicos/indexables y no el no-store del backend'
);

assert.match(
  bootstrap,
  /\|\| \$isPublicGuide \|\| \$isPublicStory/,
  'Las guías deben salir del bootstrap antes de autenticación y X-Robots-Tag noindex'
);

console.log('✓ indexabilidad HTTP de /guias verificada');
