import fs from 'node:fs';

const registro = fs.readFileSync(new URL('../public/registro.php', import.meta.url), 'utf8');
const transferencia = fs.readFileSync(new URL('../config/transferencia-publica.php', import.meta.url), 'utf8');
const runtime = fs.readFileSync(new URL('../config/sharky-runtime.php', import.meta.url), 'utf8');
const telefono = fs.readFileSync(new URL('../public/assets/telefono-internacional.js', import.meta.url), 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(
  registro.includes("$transferencia=$tipo==='INTENSIVO'?require __DIR__.'/../config/transferencia-publica.php':[]"),
  'Los datos de transferencia deben cargarse únicamente para registros intensivos.'
);
expect(
  registro.includes("<?php if($ok&&$tipo==='INTENSIVO'):?>"),
  'La pantalla de transferencia debe mostrarse únicamente tras un registro intensivo exitoso.'
);
expect(
  registro.includes("<?php elseif($ok):?><section class=\"ok\">") && registro.includes('Hache Natación revisará tu inscripción y confirmará los siguientes pasos.'),
  'El registro regular debe conservar su pantalla de éxito anterior sin instrucciones bancarias.'
);
expect(
  registro.includes('SELECT id FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:f LIMIT 1') && registro.includes('SELECT precio FROM cursos_intensivos WHERE id=:c LIMIT 1 FOR UPDATE') && registro.includes('$intensivoPrecio=(float)$st->fetchColumn()'),
  'El importe mostrado debe salir del precio real del curso intensivo existente sin romper la serialización previa.'
);
expect(
  registro.includes("':precio'=>$intensivoPrecio") && registro.includes("number_format((float)$intensivoPrecio,2,'.',',')") && registro.includes("rtrim(rtrim(number_format((float)$intensivoPrecio,2,'.',','),'0'),'.')"),
  'Los cursos nuevos y la pantalla final deben compartir la misma variable de precio sin redondear importes existentes con centavos.'
);
expect(
  registro.includes("$intensivoReservaMinima=($ok&&$tipo==='INTENSIVO'&&$intensivoPrecio!==null)?((float)$intensivoPrecio/2):null") &&
    registro.includes('Total del curso: $<?=e(rtrim(rtrim(number_format((float)$intensivoPrecio') &&
    registro.includes('Reserva mínima (50%): $<?=e(rtrim(rtrim(number_format((float)$intensivoReservaMinima') &&
    registro.includes('Transfiere el total o, como mínimo, el 50% para reservar') &&
    registro.includes('El saldo restante debe quedar pagado antes del curso o, como máximo, el mismo día que inicia.'),
  'El checkout intensivo debe mostrar total, reserva mínima del 50% y la regla de liquidación del saldo.'
);
expect(
  registro.includes('id="copiar-clabe"') && registro.includes('navigator.clipboard.writeText(value)') && registro.includes("document.execCommand('copy')"),
  'La pantalla intensiva debe permitir copiar la CLABE con fallback.'
);
expect(
  registro.includes('Tu registro está realizado, pero el pago permanece pendiente hasta que sea confirmado.'),
  'La advertencia de pago pendiente debe permanecer visible.'
);
expect(
  registro.includes('<meta name="robots" content="noindex,nofollow">'),
  'El flujo transaccional de registro debe mantenerse fuera del índice.'
);
expect(
  telefono.includes('function registroAccesibilidad()') &&
    telefono.includes("['nombre','registro-nombre']") &&
    telefono.includes("['whatsapp','whatsapp']") &&
    telefono.includes("['horario_id','registro-horario']") &&
    telefono.includes("['fecha_inicio','registro-fecha-inicio']") &&
    telefono.includes('label.htmlFor=control.id'),
  'Los campos del registro deben quedar asociados programáticamente con sus etiquetas visibles.'
);
expect(
  transferencia.includes('hache_sharky_business_values') &&
    transferencia.includes("['sharky_pago_institucion']") &&
    transferencia.includes("['sharky_pago_beneficiario']") &&
    transferencia.includes("['sharky_pago_clabe']"),
  'El registro público y Sharky deben compartir una única fuente configurable de datos de transferencia.'
);
expect(
  /'sharky_pago_clabe'\s*=>\s*\[\s*'valor'\s*=>\s*'\d{18}'/.test(runtime),
  'La CLABE por defecto debe conservar exactamente 18 dígitos.'
);
expect(
  runtime.includes("'sharky_pago_institucion'=>['valor'=>'Mercado Pago W'") && runtime.includes("'sharky_pago_beneficiario'=>['valor'=>'Heidy Garcia Liranza'"),
  'La fuente de verdad debe conservar la institución y beneficiario actuales como valores por defecto.'
);

console.log('INTENSIVO_TRANSFERENCIA_REGRESSION_OK');
