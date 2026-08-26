import assert from 'node:assert/strict';
import fs from 'node:fs';

const continuidad = fs.readFileSync('api/continuidad-intensivo.php', 'utf8');
const intensivoFlow = fs.readFileSync('public/assets/intensivo-flow.js', 'utf8');
const quickPay = fs.readFileSync('public/assets/alumnos-quick-pay.js', 'utf8');
const mensualidadPendiente = fs.readFileSync('api/mensualidad-pendiente.php', 'utf8');
const pagosSmart = fs.readFileSync('api/pagos-smart.php', 'utf8');
const alumnos = fs.readFileSync('public/alumnos.php', 'utf8');

assert.match(
  continuidad,
  /in_array\(\$inicioRegular,\['ACTUAL','PROXIMO'\],true\)/,
  'la continuidad debe aceptar únicamente periodo actual o próximo'
);

assert.match(
  continuidad,
  /\$referenciaProxima=\(new DateTimeImmutable\(\$periodoActual\['fin'\]\)\)->modify\('\+1 day'\)/,
  'el próximo periodo debe calcularse desde el día siguiente al fin del periodo actual'
);

assert.match(
  continuidad,
  /\$periodoContinuidad=\$inicioRegular==='PROXIMO'\?\$periodoProximo:\$periodoActual/,
  'la obligación debe apuntar al periodo elegido'
);

assert.match(
  continuidad,
  /if\(\$inicioRegular==='PROXIMO'\)\{[\s\S]{0,500}plan_actual_id=NULL,plan_programado_id=:p,plan_programado_desde=:desde/,
  'una continuidad del próximo periodo debe programar el plan sin activarlo en el periodo actual'
);

assert.match(
  continuidad,
  /else\{[\s\S]{0,500}plan_actual_id=:p,plan_programado_id=NULL,plan_programado_desde=NULL/,
  'una continuidad del periodo actual debe activar el plan y limpiar cualquier programación futura'
);

assert.match(
  continuidad,
  /\$periodosRetirables=\[\$periodoAlterno\][\s\S]{0,1500}continuidad_obligacion_de_relacion\([\s\S]{0,350}continuidad_obligacion_intacta\([\s\S]{0,450}DELETE m FROM mensualidades m[\s\S]{0,300}m\.importe_cobrado IS NULL[\s\S]{0,300}NOT EXISTS \(SELECT 1 FROM pagos p WHERE p\.mensualidad_id=m\.id\)/,
  'al cambiar de periodo solo puede retirar una obligación atribuible, pendiente y sin pagos'
);

assert.match(
  continuidad,
  /\$cicloAnterior=.*ciclo_pago[\s\S]{0,800}\$periodoAnterior=regla_periodo_regular_actual[\s\S]{0,1600}ciclo anterior ya tiene historial financiero/,
  'si el ciclo anterior tiene historial, el cambio debe bloquearse sin borrar pagos'
);

assert.match(
  continuidad,
  /SELECT m\.id,m\.estado,m\.importe_cobrado,m\.periodo_inicio,m\.periodo_fin,m\.plan_id,m\.observacion,EXISTS\(SELECT 1 FROM pagos p WHERE p\.mensualidad_id=m\.id\) tiene_pagos FROM mensualidades m WHERE m\.alumno_id=:a AND m\.sede_id=:s AND m\.mes=:m AND m\.anio=:y LIMIT 1 FOR UPDATE/,
  'la obligación objetivo debe bloquearse y localizarse por la misma clave mes/año usada para evitar duplicados'
);

assert.match(
  continuidad,
  /\$mensualidadConHistorial=\$mensualidadObjetivo\['estado'\]!=='PENDIENTE'[\s\S]{0,300}\$coincidePeriodo=[\s\S]{0,300}\$coincidePlan=/,
  'una mensualidad ya pagada puede conservarse solo si coincide exactamente con periodo y plan de la continuidad'
);

assert.match(
  continuidad,
  /if\(!\$mensualidadConHistorial&&\$mensualidadEditable\)\{[\s\S]{0,800}UPDATE mensualidades SET periodo_inicio=:pi,periodo_fin=:pf,plan_id=:plan,importe_estandar=:estandar,importe_a_cobrar=:importe/,
  'solo una obligación sin historial y atribuible a Continuidad puede ser reescrita'
);

assert.ok(
  !/importe_cobrado=:importe/.test(continuidad),
  'marcar continuidad no debe registrar un cobro'
);

assert.match(
  continuidad,
  /Continuidad desde intensivo:/,
  'la obligación creada por continuidad debe conservar trazabilidad'
);

assert.match(
  intensivoFlow,
  /<option value="ACTUAL">Este periodo<\/option><option value="PROXIMO">Próximo periodo<\/option>/,
  'el formulario debe permitir elegir este periodo o el próximo'
);

assert.match(
  intensivoFlow,
  /No se generará mensualidad del periodo actual\. La primera obligación será la del próximo periodo\./,
  'la interfaz debe explicar que iniciar el próximo periodo no crea deuda actual'
);

assert.match(
  intensivoFlow,
  /inicio_regular:continua\?document\.getElementById\('hache-inicio-periodo'\)\.value:null/,
  'la selección del periodo debe enviarse al backend'
);

assert.match(
  mensualidadPendiente,
  /SELECT id,mes,anio,periodo_inicio,periodo_fin,importe_estandar,importe_a_cobrar,estado/,
  'el contexto de pago rápido debe devolver el importe estándar y el importe real pendiente'
);

assert.match(
  mensualidadPendiente,
  /\(CURDATE\(\) BETWEEN periodo_inicio AND periodo_fin\) DESC[\s\S]{0,180}\(periodo_inicio>CURDATE\(\)\) DESC/,
  'la obligación vigente debe tener prioridad y, si no existe, la próxima obligación debe preceder a deudas antiguas'
);

assert.match(
  quickPay,
  /fetch\('\/api\/mensualidad-pendiente\.php\?' \+ params\.toString\(\)/,
  'Pago rápido debe consultar la obligación mensual real antes de abrir el cobro'
);

assert.match(
  quickPay,
  /const price = usarObligacion \? mensualidadPendiente\.importe_a_cobrar : btn\.dataset\.price/,
  'el importe sugerido debe venir de importe_a_cobrar cuando existe obligación pendiente'
);

assert.match(
  quickPay,
  /const standardPrice = usarObligacion \? mensualidadPendiente\.importe_estandar : btn\.dataset\.price/,
  'la comparación financiera debe conservar el precio estándar del plan'
);

assert.match(
  quickPay,
  /const amountAdjusted = target\.type === 'MENSUALIDAD'[\s\S]{0,250}Math\.abs\(amountNumber - standardNumber\) > 0\.009/,
  'un prorrateo debe justificarse contra el estándar aunque sea el importe sugerido'
);

assert.match(
  quickPay,
  /body\.periodo_mes = target\.periodMes;[\s\S]{0,120}body\.periodo_anio = target\.periodAnio;/,
  'Pago rápido debe enviar el periodo explícito de la obligación seleccionada'
);

assert.match(
  quickPay,
  /observacion: amountAdjusted \? 'Importe ajustado manualmente desde Pago rápido' : ''/,
  'Pago rápido debe justificar importes distintos al estándar para la validación financiera'
);

assert.match(
  quickPay,
  /role="alert" aria-live="polite"/,
  'los errores del pago rápido deben anunciarse de forma visible'
);

assert.match(
  quickPay,
  /err\.scrollIntoView\(\{ block: 'nearest' \}\)/,
  'un rechazo debe llevar el mensaje de error a la vista'
);

assert.match(
  pagosSmart,
  /\$importeReferencia=\$importeEsperado!==null\?number_format\(\(float\)\$importeEsperado,2,'\.',''\):\$estandar;if\(\(float\)\$importeDecimal!==\(float\)\$importeReferencia&&\$observacion===''\)throw new RuntimeException/,
  'la protección del backend debe comparar contra la obligación histórica y seguir exigiendo observación ante un importe distinto'
);

assert.match(
  alumnos,
  /regla_reconciliar_sede_una_vez\(\$pdo,\(string\)\$sedeId,\$sedeClave\)/,
  'Control de Alumnos debe promover planes programados y recalcular estados al cambiar de fecha'
);

assert.match(
  alumnos,
  /a\.plan_programado_id,a\.plan_programado_desde[\s\S]{0,300}plan_programado_nombre/,
  'Control de Alumnos debe cargar el plan futuro y su fecha de activación'
);

assert.match(
  alumnos,
  /\$sinPlan=.*empty\(\$a\['plan_actual_id'\]\) && empty\(\$a\['plan_programado_id'\]\)/,
  'un alumno con plan programado no debe caer en la categoría Sin plan'
);

assert.match(
  alumnos,
  /\$regulares=.*!empty\(\$a\['plan_actual_id'\]\) \|\| !empty\(\$a\['plan_programado_id'\]\)/,
  'los alumnos con inicio futuro deben seguir visibles en Clases regulares'
);

assert.match(
  alumnos,
  /function inicio_regular_futuro[\s\S]{0,900}mensualidad_futura_estado'\]\?\?'\'\)==='PAGADA'/,
  'un continuante legado con futuro pagado y obligación actual sin historial debe mostrarse como inicio futuro'
);

assert.match(
  alumnos,
  /\$estadoTexto='INICIA '\.fecha_corta\(\$inicioFuturo\)[\s\S]{0,300}\$mesFuturo\.' PAGADA'/,
  'la tarjeta futura debe mostrar fecha de inicio y mensualidad futura pagada en vez de deuda actual'
);

console.log('Continuidad, prorrateo, inicio futuro, periodo explícito y cambio P1/P15: regresiones OK');
