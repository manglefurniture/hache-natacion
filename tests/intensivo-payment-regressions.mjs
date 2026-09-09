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
const paymentPage = read('public/pagos.php');
const historicalPaymentCourses = read('api/alumno-intensivos-pago.php');
const statusRules = read('config/intensivos-estado.php');
const intensivesApi = read('api/intensivos.php');
const bootstrap = read('config/backend-bootstrap.php');
const backendMenu = read('public/assets/backend-menu.js');
const configApi = read('api/configuracion.php');
const planVariantsMigration = read('database/migrations/20260907_allow_plan_variants_same_sessions.sql');
const planVariantsRunner = read('bin/migrate-plan-variants.php');
const deployImplementation = read('ops/production-readiness/deploy-hache-natacion');

// El estado de pago canónico sigue siendo alumno + curso + INTENSIVO + VALIDO.
assert.match(statusApi, /SUM\(p\.importe\)/);
assert.match(statusApi, /p\.alumno_id=cia\.alumno_id/);
assert.match(statusApi, /p\.intensivo_id=cia\.curso_intensivo_id/);
assert.match(statusApi, /p\.tipo='INTENSIVO'/);
assert.match(statusApi, /p\.estado='VALIDO'/);
assert.doesNotMatch(statusApi, /\b(?:INSERT|UPDATE|DELETE)\b/i, 'El endpoint puntual de estado debe ser solo lectura');

// El API que alimenta exactamente las tarjetas de intensivo debe acumular abonos y solo marcar PAGADO al liquidar.
assert.match(detailApi, /SUM\(p\.importe\).*AS intensivo_pagado_total/);
assert.ok(detailApi.includes("$alumnoCurso['intensivo_saldo']=max(0.0,round((float)$curso['precio']-$alumnoCurso['intensivo_pagado_total'],2))"));
assert.ok(detailApi.includes("$alumnoCurso['intensivo_pagado']=$alumnoCurso['intensivo_saldo']<=0.009"));

// La pantalla real del detalle no debe ofrecer Pagar a quien ya está pagado.
assert.ok(detailFlow.includes('const intensivoPagado=alumno.intensivo_pagado===true||Number(alumno.intensivo_pagado)===1'));
assert.ok(detailFlow.includes('const pagadoTotal=Number(alumno.intensivo_pagado_total||0)'));
assert.ok(detailFlow.includes("anticipo.textContent='Anticipo $'"));
assert.ok(detailFlow.includes("pagar.textContent=pagadoTotal>0?'Pagar saldo':'Pagar'"));
assert.ok(detailFlow.includes("pagado.textContent='Pagado ✓'"));
const paidBranch = detailFlow.indexOf('if(intensivoPagado)');
const payLink = detailFlow.indexOf("pagar.href='/pagos.php?alumno_id='", paidBranch);
assert.ok(paidBranch >= 0 && payLink > paidBranch, 'El enlace Pagar debe existir únicamente dentro de la rama no pagada');
assert.ok(detailFlow.slice(paidBranch, payLink).includes('}else{'), 'Pagar debe quedar detrás del else del estado pagado');

// La ruta general de pagos conoce el curso específico, acepta ambos nombres de
// parámetro históricos y, cuando hay selección explícita, valida exactamente ese
// curso aunque ya haya terminado.
assert.match(paymentContext, /pg\.alumno_id=cia\.alumno_id AND pg\.intensivo_id=ci\.id AND pg\.tipo='INTENSIVO' AND pg\.estado='VALIDO'/);
assert.ok(paymentContext.includes("$_GET['curso_intensivo_id']??($_GET['curso_id']??'')"));
assert.ok(paymentContext.includes("$intensivo['pagado']=(int)($intensivo['pagado']??0)===1"));
assert.ok(paymentContext.includes("$hoyIntensivo=intensivo_hoy_operativo()->format('Y-m-d')"));
assert.ok(paymentContext.includes("if($cursoId!=='')"));
assert.ok(paymentContext.includes('ci.id=:c'));
assert.ok(paymentContext.includes('ci.fecha_fin>=:hoy'), 'Sin selección explícita, el contexto activo debe seguir usando la fecha operativa');
assert.ok(paymentContext.includes("'intensivo_seleccionado'=>$cursoId!==''?$intensivo:null"));

// El selector visible es la única fuente de verdad para el POST. No puede quedar
// un curso_id fijo de la URL sobrescribiendo una selección posterior del ADMIN.
assert.ok(paymentUi.includes("initialCursoId=qs.get('curso_intensivo_id')||qs.get('curso_id')||''"));
assert.ok(paymentUi.includes("function selectedCourseId(){return document.getElementById('curso_intensivo_id')?.value||initialCursoId||'';}"));
assert.ok(paymentUi.includes("const chosen=document.getElementById('curso_intensivo_id')?.value||''"));
assert.ok(paymentUi.includes('b.curso_intensivo_id=chosen'));
assert.doesNotMatch(paymentUi, /b\.curso_intensivo_id=cursoId/);
assert.ok(paymentUi.includes('selectedIntensiveContext()?.pagado'));
assert.ok(paymentUi.includes('Este curso intensivo ya está pagado.'));
assert.ok(paymentUi.includes("document.addEventListener('hache:intensivo-seleccionado'"));

// La carga del catálogo solo acepta una respuesta HTTP/JSON con cursos válidos;
// cualquier 404/500, JSON inválido o payload viejo sin cursos cae al endpoint de
// compatibilidad en lugar de mostrarse como una lista vacía.
assert.ok(paymentPage.includes('async function leerCatalogoIntensivos(url)'));
assert.ok(paymentPage.includes("if(!response.ok)throw new Error('HTTP '+response.status)"));
assert.ok(paymentPage.includes('!Array.isArray(data.cursos)'));
assert.ok(paymentPage.includes("intensiveCourses=await leerCatalogoIntensivos('/api/pagos.php?'"));
assert.ok(paymentPage.includes("intensiveCourses=await leerCatalogoIntensivos('/api/alumno-intensivos-pago.php?'"));
assert.ok(paymentPage.includes("function cursoSolicitado(){return query.get('curso_intensivo_id')||query.get('curso_id')||'';}"));

// La barrera transaccional ahora acumula abonos válidos y rechaza únicamente exceder el saldo.
assert.match(paymentCore, /SELECT id,importe FROM pagos WHERE intensivo_id=:curso AND alumno_id=:alumno AND tipo='INTENSIVO' AND estado='VALIDO' FOR UPDATE/);
assert.ok(paymentCore.includes('El importe supera el saldo pendiente del curso intensivo'));
assert.ok(paymentCore.includes("$estadoPagoIntensivo=$saldoIntensivoDespues<=0.009?'PAGADO':'ANTICIPO'"));

// ADMIN puede seleccionar expresamente un curso histórico sin reabrirlo. La
// selección explícita valida la relación alumno+curso+sede, pero no exige que el
// curso siga PROGRAMADO/EN_CURSO. Sin id explícito, el fallback viejo sí queda
// restringido a un intensivo activo para no asociar dinero histórico por error.
assert.ok(paymentCore.includes('function hache_pago_resolver_intensivo'));
assert.match(paymentCore, /ci\.id=:curso AND ci\.sede_id=:sede[\s\S]{0,80}LIMIT 1\s+FOR UPDATE/);
assert.match(paymentCore, /ci\.estado IN \('PROGRAMADO','EN_CURSO'\)[\s\S]{0,180}ORDER BY ci\.fecha_inicio DESC LIMIT 1\s+FOR UPDATE/);
assert.ok(paymentCore.includes('hache_admin_historical_note'));
assert.ok(paymentCore.includes('pago_intensivo_historico'));
assert.ok(paymentCore.includes("hache_admin_history($pdo,$alumnoId,'PAGO'"));

// El formulario administrativo muestra todos los intensivos en los que el
// alumno está inscrito, incluidos históricos, obliga a seleccionar uno y envía
// su id al core de pagos. Un curso con anticipo sigue elegible y sugiere solo el saldo.
assert.ok(paymentPage.includes('id="curso_intensivo_id"'));
assert.ok(paymentPage.includes('/api/alumno-intensivos-pago.php?'));
assert.ok(paymentPage.includes("if(tipo==='INTENSIVO')datos.curso_intensivo_id=cursoId"));
assert.ok(paymentPage.includes("option.dataset.balance=curso.saldo??curso.precio??''"));
assert.ok(paymentPage.includes("option.disabled=curso.pagado===true"));
assert.ok(paymentPage.includes('ANTICIPO '+money(curso.pagado_total)));
assert.ok(paymentPage.includes("query.get('curso_intensivo_id')"));
assert.ok(paymentPage.includes("query.get('curso_id')"));
assert.match(historicalPaymentCourses, /WHERE cia\.alumno_id=:a AND ci\.sede_id=:s/);
assert.doesNotMatch(historicalPaymentCourses, /ci\.estado IN \('PROGRAMADO','EN_CURSO'\)/, 'El catálogo de ADMIN debe incluir cursos terminados');
assert.ok(historicalPaymentCourses.includes("p.tipo='INTENSIVO'"));
assert.ok(historicalPaymentCourses.includes("p.estado='VALIDO'"));
assert.ok(historicalPaymentCourses.includes("require_once __DIR__.'/../config/intensivos-estado.php'"));
assert.ok(historicalPaymentCourses.includes("$today=intensivo_hoy_operativo()->format('Y-m-d')"));
assert.doesNotMatch(historicalPaymentCourses, /<date\('Y-m-d'\)/, 'El catálogo histórico no debe usar la zona horaria implícita del servidor');

// Configuración permite variantes comerciales con la misma frecuencia semanal:
// la unicidad se conserva por nombre de plan, no por sesiones_semana.
assert.ok(planVariantsMigration.includes('DROP INDEX uq_planes_sede_sesiones'));
assert.ok(planVariantsMigration.includes("index_name='uq_planes_sede_sesiones'"));
assert.ok(planVariantsRunner.includes("ALTER TABLE planes DROP INDEX uq_planes_sede_sesiones"));
assert.ok(planVariantsRunner.includes("index_name='uq_planes_sede_nombre'"));
assert.ok(planVariantsRunner.includes('PLAN_VARIANTS_MIGRATION_OK'));
assert.ok(configApi.includes('Ya existe un plan con ese nombre en esta sede'));
assert.ok(!configApi.includes('nombre o número de sesiones'), 'La API no debe seguir comunicando sesiones_semana como clave única');

// El deploy aplica de forma idempotente la migración de abonos antes de publicar el SHA.
assert.ok(deployImplementation.includes('apply_release_migrations()'));
assert.ok(deployImplementation.includes('bin/migrate-intensive-partial-payments.php'));
const migrationCall = deployImplementation.indexOf('apply_release_migrations', deployImplementation.indexOf('deployed="$(git rev-parse HEAD)"'));
const deployedMarker = deployImplementation.indexOf('publish_deployed_sha "$deployed"', migrationCall);
assert.ok(migrationCall >= 0 && deployedMarker > migrationCall, 'La migración financiera debe completar antes del marcador de deploy');

// El pago rápido del listado general mantiene su preflight añadido previamente.
assert.ok(quickPay.includes('/api/intensivo-pago-estado.php?'), 'El pago rápido debe refrescar el estado del intensivo');
const preflight = quickPay.indexOf('consultarEstadoIntensivo(target.id, target.courseId)');
const submit = quickPay.indexOf("fetch('/api/pagos-smart.php'", preflight);
assert.ok(preflight >= 0 && submit > preflight, 'Debe volver a comprobar el estado antes de registrar un pago intensivo');
assert.ok(quickPay.includes('const target = current'), 'El pago rápido debe congelar el objetivo antes de esperar');
assert.ok(quickPay.includes('const estado = await consultarEstadoIntensivo(target.id, target.courseId)'), 'La comprobación debe usar el objetivo congelado');
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
