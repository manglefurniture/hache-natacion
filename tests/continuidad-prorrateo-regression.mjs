import assert from 'node:assert/strict';
import fs from 'node:fs';

const continuidad = fs.readFileSync('api/continuidad-intensivo.php', 'utf8');
const intensivoFlow = fs.readFileSync('public/assets/intensivo-flow.js', 'utf8');
const quickPay = fs.readFileSync('public/assets/alumnos-quick-pay.js', 'utf8');
const mensualidadPendiente = fs.readFileSync('api/mensualidad-pendiente.php', 'utf8');
const pagosSmart = fs.readFileSync('api/pagos-smart.php', 'utf8');

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
  /DELETE m FROM mensualidades m[\s\S]{0,350}m\.mes=:m AND m\.anio=:y[\s\S]{0,250}m\.estado='PENDIENTE'[\s\S]{0,250}m\.importe_cobrado IS NULL[\s\S]{0,300}NOT EXISTS \(SELECT 1 FROM pagos p WHERE p\.mensualidad_id=m\.id\)/,
  'al cambiar de periodo solo puede retirarse la obligación alterna por clave mensual si nunca tuvo pago'
);

assert.match(
  continuidad,
  /SELECT m\.id,m\.estado,m\.importe_cobrado,EXISTS\(SELECT 1 FROM pagos p WHERE p\.mensualidad_id=m\.id\) tiene_pagos FROM mensualidades m WHERE m\.alumno_id=:a AND m\.sede_id=:s AND m\.mes=:m AND m\.anio=:y LIMIT 1 FOR UPDATE/,
  'la obligación objetivo debe bloquearse y localizarse por la misma clave mes/año usada para evitar duplicados'
);

assert.match(
  continuidad,
  /mensualidadObjetivo\['estado'\]!=='PENDIENTE'[\s\S]{0,250}mensualidadObjetivo\['importe_cobrado'\]!==null[\s\S]{0,250}mensualidadObjetivo\['tiene_pagos'\]/,
  'una obligación con historial financiero no debe reescribirse al cambiar P1/P15'
);

assert.match(
  continuidad,
  /UPDATE mensualidades SET periodo_inicio=:pi,periodo_fin=:pf,plan_id=:plan,importe_estandar=:estandar,importe_a_cobrar=:importe,observacion=:obs,updated_at=NOW\(\) WHERE id=:id AND alumno_id=:a AND sede_id=:s AND estado='PENDIENTE'/,
  'la obligación pendiente existente debe reconciliar fechas, plan, estándar e importe al cambiar ciclo'
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
  /if\(\(float\)\$importeDecimal!==\(float\)\$estandar&&\$observacion===''\)throw new RuntimeException/,
  'la protección del backend ante importes distintos al plan debe seguir activa'
);

console.log('Continuidad, prorrateo, periodo explícito y cambio P1/P15: regresiones OK');
