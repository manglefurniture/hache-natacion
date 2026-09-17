import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ficha = fs.readFileSync(path.join(root,'public/ficha-alumno.php'), 'utf8');
const timeline = fs.readFileSync(path.join(root,'api/timeline-alumno.php'), 'utf8');
const backendMenu = fs.readFileSync(path.join(root,'public/assets/backend-menu.js'), 'utf8');
const backendBootstrap = fs.readFileSync(path.join(root,'config/backend-bootstrap.php'), 'utf8');
const expedienteHelper = path.join(root,'config/expediente-360.php');

assert.match(ficha, /page_require\(\['ADMIN','VERIFICADOR'\]\)/, 'la ficha conserva roles administrativos existentes');
assert.match(ficha, /auth_active_sede_clave\(\)/, 'la ficha conserva el alcance de sede activa');
assert.match(ficha, /a\.id=:id AND a\.sede_id=:sede/, 'el alumno se resuelve dentro de la sede activa');
assert.match(ficha, /a\.plan_programado_desde/, 'la ficha expone el inicio del plan programado');
assert.match(ficha, /pp\.nombre AS plan_programado_nombre/, 'la ficha reutiliza el plan programado real');
assert.match(ficha, /Expediente 360°/, 'la ficha identifica el nuevo expediente integrado');
assert.match(ficha, /timeline-alumno\.php\?alumno_id=/, 'la ficha consume el timeline existente en lugar de duplicarlo');
assert.match(ficha, /if\(!empty\(\$alumno\['ciclo_pago'\]\)\)/, 'el ciclo solo se muestra cuando existe');
assert.match(ficha, /if\(\$alumno\['plan_programado_nombre'\]!==null\)/, 'el plan programado solo se muestra cuando existe');
assert.doesNotMatch(ficha, /Nivel académico/, 'la ficha no ocupa espacio con un nivel sin fuente académica');
assert.match(ficha, /item\.fecha_etiqueta/, 'la ficha acepta etiquetas de fecha con precisión explícita');
assert.match(ficha, /Texto histórico sin autoría\/fecha separada/, 'las observaciones antiguas no se presentan como notas auditadas');
assert.match(ficha, /pagos\.php\?alumno_id=/, 'la ficha enlaza al origen financiero del alumno');

assert.match(timeline, /require_once __DIR__\.'\/\.\.\/config\/expediente-360\.php'/, 'el timeline usa la regla central de precisión histórica');
assert.match(timeline, /auth_require\(\['ADMIN','VERIFICADOR'\]\)/, 'el timeline conserva roles administrativos');
assert.match(timeline, /s\.clave=:site AND s\.activo=1/, 'el timeline valida la sede activa');
assert.match(timeline, /p\.estado/, 'los eventos de pago conservan su estado');
assert.match(timeline, /\$row\['estado'\]!=='VALIDO'/, 'los pagos invalidados siguen identificables en el timeline');
assert.match(timeline, /hache_expediente_pago_historico_fecha_imprecisa/, 'el timeline reconoce pagos históricos de precisión limitada');
assert.match(timeline, /fecha exacta no registrada/, 'la fecha histórica se presenta como mes de referencia, no como día exacto');
assert.match(timeline, /Método no registrado/, 'NO_REGISTRADO se presenta con texto humano');
assert.match(timeline, /JOIN horarios h ON h\.id=s\.horario_id WHERE a\.alumno_id=:student AND h\.sede_id=:site/, 'la asistencia se limita a la sede del expediente');
assert.match(timeline, /FROM reposiciones_regulares rr/, 'el expediente incorpora reposiciones regulares desde su fuente');
assert.match(timeline, /rr\.ausencia_asistencia_id/, 'cada reposición regular conserva la relación con su ausencia de origen');
assert.match(timeline, /Reposición regular · /, 'la reposición regular queda identificada por producto');
assert.match(timeline, /reposiciones_justificadas/, 'el intensivo conserva reposiciones justificadas');
assert.match(timeline, /reposiciones_cancelacion/, 'el intensivo conserva reposiciones por cancelación');
assert.match(timeline, /Continuó a regular/, 'la continuidad de intensivo a regular permanece visible');
assert.match(timeline, /Fuente: Pagos/, 'los pagos declaran su fuente');
assert.match(timeline, /Fuente: Asistencia/, 'la asistencia declara su fuente');
assert.match(timeline, /Fuente: Historial administrativo/, 'el historial declara su fuente');
assert.match(timeline, /usort\(\$items/, 'los eventos se ordenan sin copiarse a una tabla paralela');

const helperProgram = `require ${JSON.stringify(expedienteHelper)}; echo json_encode([
 hache_expediente_pago_historico_fecha_imprecisa('NO_REGISTRADO','Fecha exacta y método de pago no registrados.'),
 hache_expediente_pago_historico_fecha_imprecisa('NO_REGISTRADO','Fecha exacta y método no registrados.'),
 hache_expediente_pago_historico_fecha_imprecisa('TRANSFERENCIA','Fecha exacta y método no registrados.'),
 hache_expediente_limpiar_marca_pago_historico('Importación histórica real. Fecha exacta y método no registrados.')
]);`;
const helperResult = JSON.parse(execFileSync('php',['-r',helperProgram],{encoding:'utf8'}));
assert.deepEqual(helperResult,[true,true,false,'Importación histórica real.'], 'ambas marcas históricas reales se reconocen sin degradar pagos con método conocido');

assert.match(backendMenu, /'\/ficha-alumno\.php':\['\/assets\/ficha-alumno-flow\.js','\/assets\/ficha-relations\.js'\]/, 'la ficha conserva sus helpers vigentes');
assert.doesNotMatch(backendMenu, /ficha-timeline\.js/, 'el timeline legado no debe inyectarse sobre el Expediente 360 nativo');
assert.match(backendBootstrap, /backend-menu\.js\?v=20260915-pendientes1&f3=20260916-timeline1/, 'el loader invalida la caché del menú al retirar el timeline legado');
assert.doesNotMatch(ficha, /sharky/i, 'F3 no debe convertir datos comerciales de Sharky en datos académicos');

console.log('Expediente 360 regression: OK');
