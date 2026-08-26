import assert from 'node:assert/strict';
import fs from 'node:fs';

const continuidad=fs.readFileSync('api/continuidad-intensivo.php','utf8');
const alumnos=fs.readFileSync('public/alumnos.php','utf8');
const quickPay=fs.readFileSync('public/assets/alumnos-quick-pay.js','utf8');
const pagos=fs.readFileSync('api/pagos-smart.php','utf8');
const altas=fs.readFileSync('api/alumnos.php','utf8');
const correo=fs.readFileSync('config/notificaciones-email.php','utf8');
const bootstrap=fs.readFileSync('config/database.php','utf8');

assert.match(continuidad,/function continuidad_obligacion_intacta[\s\S]{0,350}\['estado'\]==='PENDIENTE'[\s\S]{0,250}\['importe_cobrado'\]===null[\s\S]{0,250}tiene_pagos/,'una continuidad solo puede retirar una obligación pendiente sin cobros');
assert.match(continuidad,/function continuidad_obligacion_de_relacion[\s\S]{0,500}str_contains\(\$observacion,'\[relacion:'\.\$relacionId\.'\]'\)[\s\S]{0,350}preg_match\('\/\\\[relacion:\[\^\\\]\]\+\\\]\/'[\s\S]{0,250}return false[\s\S]{0,700}Continuidad desde intensivo:[\s\S]{0,450}\['plan_id'\][\s\S]{0,450}\['periodo_inicio'\]/,'una obligación etiquetada a otra relación debe rechazarse antes del fallback legacy');
assert.match(continuidad,/if\(!\$continua\)\{[\s\S]+plan_programado_id=NULL,plan_programado_desde=NULL[\s\S]+continua_regular=0/,'PROXIMO → NO_CONTINUA debe retirar únicamente la programación propia');
assert.match(continuidad,/programadoDesde>date\('Y-m-d'\)[\s\S]+continuidad_obligacion_de_relacion[\s\S]+continuidad_obligacion_intacta/,'la revocación futura debe ser idempotente y no tocar pagos ni historial');
assert.match(continuidad,/\$programadoDesdeAnterior=.*plan_programado_desde[\s\S]+\$programacionAnteriorPropia=[\s\S]+planProgramadoAnterior===\$planContinuidadAnterior[\s\S]+\$referenciaPeriodoAnterior=\$referenciaContinuidad[\s\S]+new DateTimeImmutable\(\$programadoDesdeAnterior\)[\s\S]+regla_periodo_regular_actual\(\$sedeClave,\$cicloAnterior,\$referenciaPeriodoAnterior\)/,'P1 ↔ P15 debe derivar el periodo anterior desde la programación previa propia cuando exista');
assert.match(continuidad,/\$periodosRetirables=\[\$periodoAlterno\][\s\S]+\$periodosProcesados[\s\S]+DELETE m FROM mensualidades m/,'cada obligación atribuible se debe retirar una sola vez dentro de la transacción');
assert.match(continuidad,/\$clavePeriodo=\$periodoRetirable\['inicio'\]\.'-'\.\$periodoRetirable\['fin'\]/,'P1 y P15 que comparten mes/año deben procesarse por sus rangos reales');
assert.match(continuidad,/pertenece a otra operación y no puede modificarse desde Continuidad/,'una obligación ajena debe bloquear la mutación en vez de ser reescrita');
assert.match(continuidad,/m\.importe_a_cobrar[\s\S]+\$mensualidadEditable=continuidad_obligacion_de_relacion[\s\S]+if\(!\$mensualidadEditable&&abs\(\(float\)\$importeFinal-\(float\)\$mensualidadObjetivo\['importe_a_cobrar'\]\)>0\.009\)/,'una obligación ajena no puede registrar un ajuste de importe que no se aplicaría');
assert.match(continuidad,/\[relacion:\'\.\$relId\.\'\]/,'las nuevas obligaciones de continuidad deben conservar el identificador de relación');

assert.match(alumnos,/LEFT JOIN mensualidades ma ON ma\.id=\([\s\S]+mensualidad_ciclo\.periodo_inicio=CASE[\s\S]+a\.ciclo_pago='P15'[\s\S]+ORDER BY mensualidad_ciclo\.id ASC[\s\S]+LIMIT 1/,'el listado debe elegir una sola mensualidad determinista del ciclo actual');
assert.doesNotMatch(alumnos,/LEFT JOIN mensualidades ma ON ma\.alumno_id=a\.id[\s\S]+CURDATE\(\) BETWEEN ma\.periodo_inicio AND ma\.periodo_fin/,'el listado no debe volver a unir todos los periodos que se solapan');

assert.match(quickPay,/importe_a_cobrar[\s\S]+importe_estandar[\s\S]+periodo_mes[\s\S]+periodo_anio/,'el pago rápido debe enviar el contexto de la obligación seleccionada');
assert.match(pagos,/SELECT m\.id,m\.estado,m\.periodo_inicio,m\.periodo_fin,m\.plan_id,m\.importe_estandar,m\.importe_a_cobrar[\s\S]+FOR UPDATE/,'el servidor debe bloquear y leer la obligación histórica real');
assert.match(pagos,/\$planPagoId=\$exist\?\$exist\['plan_id'\][\s\S]+\$importeEsperado=\$exist\?\$exist\['importe_a_cobrar'\]/,'el pago histórico debe usar el plan y el importe guardados en servidor');
assert.match(pagos,/if\(!\$exist&&!empty\(\$alumno\['plan_programado_id'\]\)/,'un plan programado actual no debe sustituir el contexto de una obligación histórica');
assert.match(pagos,/tiene_pago_valido[\s\S]+ya está pagada/,'la protección de doble pago debe mantenerse aun ante estados inconsistentes');
assert.match(pagos,/observacion=COALESCE\(:obs,observacion\)/,'pagar una obligación histórica no debe borrar su trazabilidad');

assert.match(correo,/function hache_construir_alerta_nueva_inscripcion/,'la composición del correo debe ser verificable sin SMTP');
assert.match(correo,/\$cursoInicio = \$esIntensivo \? trim\(\(string\)\(\$detalle\['curso_inicio'\] \?\? ''\)\) : ''/,'una inscripción regular no puede mostrar Inicio del intensivo');
assert.match(correo,/if \(\$cursoInicio !== ''\) \$lines\[\] = 'Inicio del intensivo: ' \. \$cursoInicio/,'una inscripción intensiva sí debe incluir su fecha de inicio');
assert.match(altas,/function out_alta_con_alerta[\s\S]+fastcgi_finish_request\(\)[\s\S]+hache_notificar_nueva_inscripcion/,'la respuesta de alta debe terminar antes del SMTP en PHP-FPM');
assert.ok(altas.indexOf('fastcgi_finish_request()')<altas.indexOf('hache_notificar_nueva_inscripcion($alumno,$tipoIngreso'),'SMTP debe ejecutarse después de entregar la respuesta');
assert.doesNotMatch(bootstrap,/hache_notificar_nueva_inscripcion[\s\S]+ob_start/,'el bootstrap no debe ejecutar SMTP dentro del buffer de respuesta');

console.log('Codex Review PR #29/#30/#31 regression checks: OK');
