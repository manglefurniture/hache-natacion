import assert from 'node:assert/strict';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const bootstrap = fs.readFileSync(path.join(root, 'config/backend-bootstrap.php'), 'utf8');

assert.match(
  bootstrap,
  /\$isPublicGuide\s*=\s*\$currentPath\s*===\s*'\/guias'\s*\|\|\s*str_starts_with\(\$currentPath, '\/guias\/'\)/,
  'Las rutas /guias deben clasificarse explícitamente como superficie pública SEO'
);

const freePort = await new Promise((resolve, reject) => {
  const server = net.createServer();
  server.once('error', reject);
  server.listen(0, '127.0.0.1', () => {
    const address = server.address();
    const port = typeof address === 'object' && address ? address.port : 0;
    server.close((error) => error ? reject(error) : resolve(port));
  });
});

const php = spawn('php', ['-S', `127.0.0.1:${freePort}`, '-t', root], {
  cwd: root,
  stdio: ['ignore', 'pipe', 'pipe'],
});

let phpError = '';
php.stderr.on('data', (chunk) => { phpError += chunk.toString(); });

const request = async (pathValue, scriptValue) => {
  const params = new URLSearchParams({ path: pathValue, script: scriptValue });
  const url = `http://127.0.0.1:${freePort}/tests/fixtures/bootstrap-http-harness.php?${params}`;
  let lastError;
  for (let attempt = 0; attempt < 30; attempt += 1) {
    try {
      return await fetch(url, { redirect: 'manual' });
    } catch (error) {
      lastError = error;
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
  }
  throw lastError;
};

try {
  const hub = await request('/guias/', '/guias/index.php');
  assert.equal(hub.status, 200, 'El hub /guias/ debe permanecer público');
  assert.equal(hub.headers.get('cache-control'), 'public, max-age=300, stale-while-revalidate=60');
  assert.equal(hub.headers.get('x-robots-tag'), 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1');
  assert.equal(hub.headers.get('location'), null, '/guias/ no debe entrar a autenticación');

  const article = await request('/guias/aprender-a-nadar-de-adulto/', '/guias/aprender-a-nadar-de-adulto/index.php');
  assert.equal(article.status, 200, 'Un slug de guía debe permanecer público');
  assert.equal(article.headers.get('cache-control'), 'public, max-age=300, stale-while-revalidate=60');
  assert.equal(article.headers.get('x-robots-tag'), 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1');
  assert.equal(article.headers.get('location'), null, 'Los artículos de /guias/ no deben entrar a autenticación');

  const registro = await request('/registro.php', '/registro.php');
  assert.equal(registro.status, 200, '/registro.php debe conservar su bypass público actual');
  assert.equal(registro.headers.get('cache-control'), 'private, no-store, max-age=0');
  assert.equal(registro.headers.get('x-robots-tag'), null, 'El bootstrap no debe convertir registro en indexable');
  assert.equal(registro.headers.get('location'), null, 'Registro no debe entrar a autenticación');

  const protectedRoute = await request('/dashboard.php', '/dashboard.php');
  assert.equal(protectedRoute.status, 302, 'Una ruta protegida sin sesión debe seguir entrando a autenticación');
  const protectedCache = protectedRoute.headers.get('cache-control') ?? '';
  assert.match(protectedCache, /no-store/, 'Una ruta protegida debe seguir siendo no-store aunque el cache limiter de sesión normalice el header');
  assert.doesNotMatch(protectedCache, /\bpublic\b/, 'Una ruta protegida nunca debe heredar cache público de /guias/');
  assert.equal(protectedRoute.headers.get('x-robots-tag'), 'noindex, nofollow, noarchive');
  assert.equal(protectedRoute.headers.get('location'), '/', 'La ruta protegida debe alcanzar autenticación y redirigir al acceso sin sesión');
} finally {
  php.kill('SIGTERM');
  await new Promise((resolve) => {
    if (php.exitCode !== null) return resolve();
    php.once('exit', resolve);
    setTimeout(resolve, 1000);
  });
}

assert.equal(phpError.includes('Fatal error'), false, `El harness PHP no debe fallar: ${phpError}`);
console.log('✓ indexabilidad HTTP real de /guias y rutas negativas verificada');
