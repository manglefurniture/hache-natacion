import assert from 'node:assert/strict';
import fs from 'node:fs';

const continuidad = fs.readFileSync('api/continuidad-intensivo.php', 'utf8');
const intensivoFlow = fs.readFileSync('public/assets/intensivo-flow.js', 'utf8');
const quickPay = fs.readFileSync('public/assets/alumnos-quick-pay.js', 'utf8');
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
  'la obligación debe crearse en el periodo elegido'
);

assert.match(
  continuidad,
  /regla_crear_mensualidad_pendiente\([\s\S]{0,350}\$referenciaContinuidad\)/,
  'la mensualidad pendiente debe crearse usando la referencia del periodo de continuidad'
);

assert.match(
  continuidad,
  /UPDATE mensualidades SET importe_a_cobrar=:importe,observacion=:obs,updated_at=NOW\(\) WHERE alumno_id=:a AND sede_id=:s AND periodo_inicio=:pi AND periodo_fin=:pf AND estado='PENDIENTE'/,
  'el ajuste debe limitarse a la mensualidad pendiente del periodo y de la sede'
);

assert.ok(
  !/UPDATE mensualidades SET[^\n]*importe_estandar=:importe/.test(continuidad),
  'el prorrateo no debe reemplazar el importe estándar del plan'
);

assert.ok(
  !/UPDATE mensualidades SET[^\n]*importe_cobrado=:importe/.test(continuidad),
  'marcar continuidad no debe registrar un cobro todavía'
);

assert.match(
  continuidad,
  /DELETE m FROM mensualidades m[\s\S]{0,400}m\.estado='PENDIENTE'[\s\S]{0,250}m\.importe_cobrado IS NULL[\s\S]{0,300}NOT EXISTS \(SELECT 1 FROM pagos p WHERE p\.mensualidad_id=m\.id\)/,
  'al cambiar de periodo solo puede retirarse una obligación alterna pendiente que nunca tuvo pago'
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
  quickPay,
  /const amountAdjusted = target\.type === 'MENSUALIDAD'[\s\S]{0,250}Math\.abs\(amountNumber - suggestedNumber\) > 0\.009/,
  'Pago rápido debe detectar importes manuales distintos al sugerido'
);

assert.match(
  quickPay,
  /observacion: amountAdjusted \? 'Importe ajustado manualmente desde Pago rápido' : ''/,
  'Pago rápido debe justificar el importe manual para la validación financiera'
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

console.log('Continuidad, prorrateo e inicio por periodo: regresiones OK');
