import assert from 'node:assert/strict';
import fs from 'node:fs';

const continuidad = fs.readFileSync('api/continuidad-intensivo.php', 'utf8');
const quickPay = fs.readFileSync('public/assets/alumnos-quick-pay.js', 'utf8');
const pagosSmart = fs.readFileSync('api/pagos-smart.php', 'utf8');

assert.match(
  continuidad,
  /regla_crear_mensualidad_pendiente\([\s\S]{0,500}UPDATE mensualidades SET importe_a_cobrar=:importe/,
  'la continuidad debe crear la obligación y después ajustar únicamente el importe a cobrar'
);

assert.match(
  continuidad,
  /UPDATE mensualidades SET importe_a_cobrar=:importe,observacion=COALESCE\(:obs,observacion\),updated_at=NOW\(\) WHERE alumno_id=:a AND sede_id=:s AND periodo_inicio=:pi AND periodo_fin=:pf AND estado='PENDIENTE'/,
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
  /Continuidad desde intensivo: importe ajustado para el periodo/,
  'un prorrateo sin nota manual debe conservar trazabilidad'
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

console.log('Continuidad y prorrateo: regresiones OK');
