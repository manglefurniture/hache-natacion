import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const includes = (relative, needle) => assert.ok(read(relative).includes(needle), `${relative} debe incluir ${needle}`);
const test = (name, fn) => {
  try { fn(); console.log(`✓ ${name}`); }
  catch (error) { console.error(`✗ ${name}`); throw error; }
};

// This file intentionally keeps broad static invariants cheap and deterministic.
// Feature-specific regressions live in their dedicated test files.

test('no quedan llamadas a la función DDL eliminada', () => {
  const files = [
    'api/alumnos.php','api/pagos.php','api/pagos-smart.php','api/intensivo-alumnos.php',
    'api/continuidad-intensivo.php','api/asistencia.php','api/sesiones.php','api/horarios.php'
  ];
  for (const file of files) assert.doesNotMatch(read(file), /ensureSchema\s*\(/);
});

test('las funciones auxiliares propias llamadas están definidas', () => {
  const source = read('api/alumnos.php');
  for (const helper of ['telefono_normalizar_mexico','telefono_es_e164']) includes('config/telefono.php', `function ${helper}`);
  assert.ok(source.includes("require_once __DIR__.'/../config/telefono.php'"));
});

test('el portal obtiene la sede de la sesión mediante el horario', () => {
  const source = read('api/portal-alumno.php');
  assert.match(source, /JOIN horarios h ON h\.id=s\.horario_id/);
  assert.match(source, /JOIN sedes sd ON sd\.id=h\.sede_id/);
});

test('el health check público usa la conexión existente', () => {
  const source = read('public/api/health.php');
  assert.match(source, /require .*config\/pdo\.php/);
  assert.doesNotMatch(source, /new PDO\s*\(/);
});

test('ficha y edición de alumno respetan la sede activa', () => {
  assert.match(read('public/ficha-alumno.php'), /auth_active_sede_clave/);
  assert.match(read('public/editar-alumno.php'), /auth_active_sede_clave/);
});

test('los pagos toman el actor de la sesión y no del cliente', () => {
  includes('api/pagos-smart.php', "$createdBy=(string)$me['id']");
  for (const file of ['api/pagos-smart.php', 'api/editar-pago.php', 'api/invalidar-pago.php']) {
    assert.doesNotMatch(read(file), /\$(?:input|in)\[['\"](?:created_by|usuario_id)['\"]\]/);
    includes(file, "header('Allow: POST')");
  }
});

test('editar e invalidar pagos bloquean la fila y conservan el alcance de sede', () => {
  for (const file of ['api/editar-pago.php', 'api/invalidar-pago.php']) {
    includes(file, 'FOR UPDATE');
    assert.match(read(file), /UPDATE pagos SET[\s\S]{0,300}WHERE id=:id AND estado='VALIDO'/);
  }
  assert.match(read('api/editar-pago.php'), /UPDATE mensualidades SET[\s\S]{0,350}WHERE id=:id AND sede_id=:sede/);
  assert.match(read('api/editar-pago.php'), /UPDATE inscripciones SET[\s\S]{0,250}WHERE id=:id AND sede_id=:sede/);
  assert.match(read('api/invalidar-pago.php'), /UPDATE alumnos SET plan_programado_id=NULL[\s\S]{0,300}sede_id=:sede/);
});

test('el alta de pagos serializa por alumno antes de revisar duplicados', () => {
  const source = read('api/pagos-smart.php');
  const begin = source.indexOf('$pdo->beginTransaction()');
  // Helpers may also contain locking SELECTs before the executable transaction.
  // Validate the first lock inside the transaction body, not the first lock in
  // the source file.
  const lock = source.indexOf('LIMIT 1 FOR UPDATE', begin);
  const payment = source.indexOf('INSERT INTO pagos(', begin);
  assert.ok(begin >= 0 && lock > begin && payment > lock, 'El alumno debe quedar bloqueado antes de crear el pago');
  assert.equal((source.match(/\$pdo->beginTransaction\(\)/g) || []).length, 1, 'El flujo debe abrir una sola transacción');
});

test('las altas de intensivos serializan por alumno antes de validar exclusividad', () => {
  const source = read('api/intensivo-alumnos.php');
  includes('api/intensivo-alumnos.php', "estado_administrativo<>'BAJA' LIMIT 1 FOR UPDATE");
  assert.ok(
    source.indexOf("estado_administrativo<>'BAJA' LIMIT 1 FOR UPDATE") < source.indexOf('El alumno ya pertenece a otro curso intensivo activo'),
    'el bloqueo del alumno debe preceder la comprobación de otros intensivos activos'
  );
  assert.match(source, /curso_intensivo_alumnos[\s\S]{0,500}LIMIT 1 FOR UPDATE/);
});

test('la decisión de continuidad bloquea la relación y recalcula ambos caminos', () => {
  const source = read('api/continuidad-intensivo.php');
  assert.match(source, /beginTransaction\(\)[\s\S]{0,700}curso_intensivo_alumnos[\s\S]{0,500}FOR UPDATE/);
  assert.match(source, /if\(!\$continua\)[\s\S]{0,2200}regla_recalcular_alumno\(\$pdo,\$alumnoId\)/);
  assert.equal((source.match(/\$pdo->beginTransaction\(\)/g) || []).length, 1);
});

// Preserve remaining established static coverage from the previous suite.
// Import and execute the focused scripts through npm; here we keep only generic
// cross-cutting assertions that are inexpensive and stable.

test('los endpoints sensibles declaran JSON y autenticación', () => {
  for (const file of ['api/alumnos.php','api/pagos.php','api/intensivo-alumnos.php','api/continuidad-intensivo.php']) {
    const source = read(file);
    assert.match(source, /Content-Type: application\/json/);
    assert.match(source, /auth_/);
  }
});

test('las mutaciones financieras usan transacción', () => {
  for (const file of ['api/pagos-smart.php','api/editar-pago.php','api/invalidar-pago.php']) {
    const source = read(file);
    assert.match(source, /beginTransaction\(\)/);
    assert.match(source, /commit\(\)/);
    assert.match(source, /rollBack\(\)/);
  }
});

test('no se confía en sede enviada por usuario sin resolverla contra sesión', () => {
  for (const file of ['api/pagos-smart.php','api/intensivos.php','api/alumnos.php']) {
    assert.match(read(file), /auth_resolve_sede_clave/);
  }
});

test('la API de pagos mantiene barrera de duplicados intensivos', () => {
  const source = read('api/pagos-smart.php');
  assert.match(source, /pagos WHERE intensivo_id=:curso AND alumno_id=:alumno AND tipo='INTENSIVO' AND estado='VALIDO'/);
  assert.ok(source.includes('Este alumno ya pagó este curso intensivo'));
});

test('la edición de pagos no permite cambiar identidad del actor desde el cliente', () => {
  const source = read('api/editar-pago.php');
  assert.doesNotMatch(source, /created_by\s*=\s*:\w+/);
});

test('la invalidación conserva trazabilidad', () => {
  const source = read('api/invalidar-pago.php');
  assert.match(source, /historial/);
  assert.match(source, /invalid/iu);
});

test('la UI de pagos no envía created_by', () => {
  assert.doesNotMatch(read('public/pagos.php'), /created_by/);
});

test('los endpoints de consulta no mutan datos accidentalmente', () => {
  for (const file of ['api/intensivo-pago-estado.php','api/mensualidad-pendiente.php']) {
    const source = read(file);
    assert.doesNotMatch(source, /\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO\s+)?(?:pagos|mensualidades|alumnos)/i);
  }
});

// Keep this suite intentionally small; dedicated feature suites provide the
// detailed behavioural contracts and are chained by package.json.

console.log('✓ regresiones estáticas verificadas');
