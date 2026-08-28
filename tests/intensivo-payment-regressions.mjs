import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const statusApi = read('api/intensivo-pago-estado.php');
const detailApi = read('api/intensivo-alumnos.php');
const detailFlow = read('public/assets/intensivo-flow.js');
const quickPay = read('public/assets/alumnos-quick-pay.js');
const paymentContext = read('api/pago-contexto.php');
const paymentUi = read('public/assets/pagos-flow-v2.js');
const paymentCore = read('api/pagos-smart.php');
const statusRules = read('config/intensivos-estado.php');
const intensivesApi = read('api/intensivos.php');
const bootstrap = read('config/backend-bootstrap.php');
const backendMenu = read('public/assets/backend-menu.js');

// El estado de pago canónico sigue siendo alumno + curso + INTENSIVO + VALIDO.
assert.match(statusApi, /p\.alumno_id=cia\.alumno_id/);
assert.match(statusApi, /p\.intensivo_id=cia\.curso_intensivo_id/);
assert.match(statusApi, /p\.tipo='INTENSIVO'/);
assert.match(statusApi, /p\.estado='VALIDO'/);
assert.doesNotMatch(statusApi, /\b(?:INSERT|UPDATE|DELETE)\b/i, 'El endpoint puntual de estado debe ser solo lectura');

// El API que alimenta exactamente las tarjetas de intensivo debe traer el pago en bloque.
assert.match(detailApi, /EXISTS\(SELECT 1 FROM pagos p WHERE p\.alumno_id=cia\.alumno_id AND p\.intensivo_id=cia\.curso_intensivo_id AND p\.tipo='INTENSIVO' AND p\.estado='VALIDO'\) AS intensivo_pagado/);
assert.ok(detailApi.includes("$alumnoCurso['intensivo_pagado']=(int)($alumnoCurso['intensivo_pagado']??0)===1"));

// La pantalla real del detalle no debe ofrecer Pagar a quien ya está pagado.
assert.ok(detailFlow.includes('const intensivoPagado=alumno.intensivo_pagado===true||Number(alumno.intensivo_pagado)===1'));
assert.ok(detailFlow.includes("pagado.textContent='Pagado ✓'"));
const paidBranch = detailFlow.indexOf('if(intensivoPagado)');
const payLink = detailFlow.indexOf("pagar.href='/pagos.php?alumno_id='", paidBranch);
assert.ok(paidBranch >= 0 && payLink > paidBranch, 'El enlace Pagar debe existir únicamente dentro de la rama no pagada');
assert.ok(detailFlow.slice(paidBranch, payLink).includes('}else{'), 'Pagar debe quedar detrás del else del estado pagado');

// La ruta general de pagos también debe conocer el curso específico y bloquear el duplicado visual.
assert.match(paymentContext, /pg\.alumno_id=cia\.alumno_id AND pg\.intensivo_id=ci\.id AND pg\.tipo='INTENSIVO' AND pg\.estado='VALIDO'/);
assert.ok(paymentContext.includes("$cursoId=trim((string)($_GET['curso_id']??''))"));
assert.ok(paymentContext.includes("$intensivo['pagado']=(int)($intensivo['pagado']??0)===1"));
assert.ok(paymentContext.includes("$hoyIntensivo=intensivo_hoy_operativo()->format('Y-m-d')"));
assert.ok(paymentContext.includes('ci.fecha_fin>=:hoy'), 'El contexto no debe depender de un estado persistido ni del huso horario de MariaDB');
assert.ok(paymentUi.includes("cursoParam=cursoId?'&curso_id='+encodeURIComponent(cursoId):''"));
assert.ok(paymentUi.includes("tipo==='INTENSIVO'&&ctx.intensivo_activo?.pagado"));
assert.ok(paymentUi.includes('Este curso intensivo ya está pagado.'));

// La barrera transaccional auditada permanece intacta como defensa final.
assert.match(paymentCore, /WHERE intensivo_id=:curso AND alumno_id=:alumno AND tipo='INTENSIVO' AND estado='VALIDO' LIMIT 1/,
  'La barrera transaccional contra pagos duplicados debe permanecer intacta');
assert.ok(paymentCore.includes('Este alumno ya pagó este curso intensivo'), 'Debe conservarse el rechazo explícito del duplicado');

// El pago rápido del listado general mantiene su preflight añadido previamente.
assert.ok(quickPay.includes('/api/intensivo-pago-estado.php?'), 'El pago rápido debe refrescar el estado del intensivo');
const preflight = quickPay.indexOf('consultarEstadoIntensivo(target.id, target.courseId)');
const submit = quickPay.indexOf("fetch('/api/pagos-smart.php'", preflight);
assert.ok(preflight >= 0 && submit > preflight, 'Debe volver a comprobar el estado antes de registrar un pago intensivo');
assert.ok(quickPay.includes('const target = current'), 'El pago rápido debe congelar el objetivo antes de esperar');
assert.ok(quickPay.includes('const pagado = await consultarEstadoIntensivo(target.id, target.courseId)'), 'La comprobación debe usar el objetivo congelado');
assert.ok(quickPay.includes('if (current !== target) return;'), 'La operación debe abortar si el modal cambia durante la espera');
assert.ok(quickPay.includes('let inFlight = null;'), 'El pago rápido debe conservar un bloqueo mientras el POST está pendiente');
assert.ok(quickPay.includes('if (inFlight) return;'), 'Un segundo pago no debe iniciar mientras el primero sigue en vuelo');
assert.ok(quickPay.includes('inFlight = target;'), 'El bloqueo debe quedar asociado al objetivo inmutable de la primera operación');
assert.ok(quickPay.includes('document.getElementById(\'hqp-save\').disabled = inFlight !== null;'), 'Al abrir B durante A pendiente, B debe permanecer deshabilitado');
assert.ok(quickPay.includes("if (current !== target) return;\n\n      close();"), 'La respuesta de A no debe cerrar el modal de B');
assert.ok(quickPay.includes('if (inFlight === target) {'), 'Solo la operación dueña del bloqueo puede liberarlo');

// La regla temporal debe ser única, acotada por sede y usar explícitamente la fecha operativa de Cancún.
assert.ok(statusRules.includes('function intensivo_hoy_operativo'));
assert.ok(statusRules.includes("new DateTimeZone('America/Cancun')"));
assert.ok(statusRules.includes('function intensivo_estado_por_fechas'));
assert.ok(statusRules.includes('function intensivos_reconciliar_estados_sede'));
assert.ok(statusRules.includes("if ($hoy < $fechaInicio)"));
assert.ok(statusRules.includes("if ($hoy <= $fechaFin)"));
assert.ok(statusRules.includes("return 'TERMINADO'"));
assert.ok(statusRules.includes('WHERE sede_id = :s'));
assert.ok(statusRules.includes('AND estado <> CASE'));

// Ejecutar PHP real para verificar los cuatro límites de fecha y el huso operativo.
const helperPath = path.join(root, 'config/intensivos-estado.php');
const phpProgram = `require ${JSON.stringify(helperPath)}; echo json_encode([
  intensivo_estado_por_fechas('2026-08-26','2026-09-13',new DateTimeImmutable('2026-08-25')),
  intensivo_estado_por_fechas('2026-08-25','2026-09-12',new DateTimeImmutable('2026-08-25')),
  intensivo_estado_por_fechas('2026-08-01','2026-08-25',new DateTimeImmutable('2026-08-25')),
  intensivo_estado_por_fechas('2026-08-01','2026-08-24',new DateTimeImmutable('2026-08-25')),
  intensivo_hoy_operativo()->getTimezone()->getName()
]);`;
const boundaryStates = JSON.parse(execFileSync('php', ['-r', phpProgram], { encoding: 'utf8' }));
assert.deepEqual(boundaryStates, ['PROGRAMADO','EN_CURSO','EN_CURSO','TERMINADO','America/Cancun']);

// Los listados derivan el estado al leer y las altas usan la misma regla.
assert.ok(intensivesApi.includes('intensivo_estado_por_fechas((string)$row'));
assert.ok(intensivesApi.includes('$estadoInicial=intensivo_estado_por_fechas($fi,$ff)'));
assert.ok(intensivesApi.includes("':estado'=>$estadoInicial"));
assert.doesNotMatch(intensivesApi, /VALUES\([^\n]*'PROGRAMADO'/, 'Las altas no deben congelarse siempre como PROGRAMADO');
assert.ok(detailApi.includes("$curso['estado']=intensivo_estado_por_fechas"));
assert.ok(detailApi.includes('intensivos_reconciliar_estados_sede($pdo,$sedeId)'));

// La reconciliación diaria es independiente de la antigua reconciliación de alumnos,
// para que una actualización desplegada a mitad del día se aplique de inmediato.
assert.ok(bootstrap.includes("require_once __DIR__ . '/intensivos-estado.php'"));
assert.ok(bootstrap.includes("$hoyIntensivos = intensivo_hoy_operativo()->format('Y-m-d')"));
assert.ok(bootstrap.includes("$_SESSION['hache_intensivos_reconciliados']"));
assert.ok(bootstrap.includes('intensivos_reconciliar_estados_sede'));
assert.ok(paymentCore.includes("require_once __DIR__.'/../config/intensivos-estado.php'"));
assert.ok(paymentCore.includes("if($tipo==='INTENSIVO')intensivos_reconciliar_estados_sede($pdo,$sedeId)"), 'El pago intensivo debe reconciliar su estado dentro de la transacción');

// Forzar assets nuevos evita que el navegador siga ejecutando el JS anterior con Pagar siempre visible.
assert.ok(backendMenu.includes("const ASSET_VERSION='20260828-historias1'"));
assert.ok(bootstrap.includes('/assets/backend-menu.js?v=20260828-historias1'));

console.log('✓ regresiones de integridad de intensivos verificadas');
