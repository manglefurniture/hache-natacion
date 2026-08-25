import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const statusApi = read('api/intensivo-pago-estado.php');
const quickPay = read('public/assets/alumnos-quick-pay.js');
const paymentCore = read('api/pagos-smart.php');

assert.match(statusApi, /p\.alumno_id=cia\.alumno_id/);
assert.match(statusApi, /p\.intensivo_id=cia\.curso_intensivo_id/);
assert.match(statusApi, /p\.tipo='INTENSIVO'/);
assert.match(statusApi, /p\.estado='VALIDO'/);
assert.doesNotMatch(statusApi, /\b(?:INSERT|UPDATE|DELETE)\b/i, 'El endpoint de estado debe ser solo lectura');

assert.ok(quickPay.includes('/api/intensivo-pago-estado.php?'), 'El pago rápido debe refrescar el estado del intensivo');
assert.ok(quickPay.includes('marcarIntensivoPagado'), 'La UI debe poder corregir un estado desactualizado a PAGADO');
assert.ok(quickPay.includes('No se registrará otro cobro'), 'La UI debe bloquear visualmente un segundo cobro ya confirmado');
assert.match(quickPay, /consultarEstadoIntensivo\(current\.id, current\.courseId\)[\s\S]{0,600}fetch\('\/api\/pagos-smart\.php'/,
  'Debe volver a comprobar el estado justo antes de registrar un pago intensivo');

assert.match(paymentCore, /WHERE intensivo_id=:curso AND alumno_id=:alumno AND tipo='INTENSIVO' AND estado='VALIDO' LIMIT 1/,
  'La barrera transaccional contra pagos duplicados debe permanecer intacta');
assert.ok(paymentCore.includes('Este alumno ya pagó este curso intensivo'), 'Debe conservarse el rechazo explícito del duplicado');

console.log('✓ regresiones de pago de intensivo verificadas');
