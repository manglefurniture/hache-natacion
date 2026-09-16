import assert from 'node:assert/strict';
import fs from 'node:fs';

const ficha = fs.readFileSync('public/ficha-alumno.php', 'utf8');
const timeline = fs.readFileSync('api/timeline-alumno.php', 'utf8');

assert.match(ficha, /page_require\(\['ADMIN','VERIFICADOR'\]\)/, 'la ficha conserva roles administrativos existentes');
assert.match(ficha, /auth_active_sede_clave\(\)/, 'la ficha conserva el alcance de sede activa');
assert.match(ficha, /a\.id=:id AND a\.sede_id=:sede/, 'el alumno se resuelve dentro de la sede activa');
assert.match(ficha, /a\.plan_programado_desde/, 'la ficha expone el inicio del plan programado');
assert.match(ficha, /pp\.nombre AS plan_programado_nombre/, 'la ficha reutiliza el plan programado real');
assert.match(ficha, /Expediente 360°/, 'la ficha identifica el nuevo expediente integrado');
assert.match(ficha, /timeline-alumno\.php\?alumno_id=/, 'la ficha consume el timeline existente en lugar de duplicarlo');
assert.match(ficha, /No registrado en una fuente académica/, 'un nivel ausente se declara sin inferirlo');
assert.match(ficha, /Texto histórico sin autoría\/fecha separada/, 'las observaciones antiguas no se presentan como notas auditadas');
assert.match(ficha, /pagos\.php\?alumno_id=/, 'la ficha enlaza al origen financiero del alumno');

assert.match(timeline, /auth_require\(\['ADMIN','VERIFICADOR'\]\)/, 'el timeline conserva roles administrativos');
assert.match(timeline, /s\.clave=:site AND s\.activo=1/, 'el timeline valida la sede activa');
assert.match(timeline, /p\.estado/, 'los eventos de pago conservan su estado');
assert.match(timeline, /\$row\['estado'\]!=='VALIDO'/, 'los pagos invalidados siguen identificables en el timeline');
assert.match(timeline, /JOIN horarios h ON h\.id=s\.horario_id WHERE a\.alumno_id=:student AND h\.sede_id=:site/, 'la asistencia se limita a la sede del expediente');
assert.match(timeline, /usort\(\$items/, 'los eventos se ordenan sin copiarse a una tabla paralela');

assert.doesNotMatch(ficha, /sharky/i, 'F3 no debe convertir datos comerciales de Sharky en datos académicos');

console.log('Expediente 360 first increment regression: OK');
