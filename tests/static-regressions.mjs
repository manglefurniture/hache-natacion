import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const phpRoots = ['api', 'config', 'public', 'database'];

function filesUnder(relative, extension) {
  const base = path.join(root, relative);
  const found = [];
  for (const entry of fs.readdirSync(base, { withFileTypes: true })) {
    const full = path.join(base, entry.name);
    if (entry.isDirectory()) {
      found.push(...filesUnder(path.relative(root, full), extension));
    } else if (!extension || entry.name.endsWith(extension)) {
      found.push(path.relative(root, full));
    }
  }
  return found;
}

const phpFiles = phpRoots.flatMap((directory) => filesUnder(directory, '.php'));
const allPhp = phpFiles.map((file) => read(file)).join('\n');
const tests = [];
const test = (name, fn) => tests.push([name, fn]);
const includes = (file, value, message = `${file} debe contener ${value}`) => {
  assert.ok(read(file).includes(value), message);
};
const excludes = (file, value, message = `${file} no debe contener ${value}`) => {
  assert.ok(!read(file).includes(value), message);
};

test('no quedan llamadas a la función DDL eliminada', () => {
  assert.ok(!allPhp.includes('regla_asegurar_tabla_negocio'), 'Quedó una llamada a regla_asegurar_tabla_negocio');
});

test('las funciones auxiliares propias llamadas están definidas', () => {
  const prefixes = ['auth_', 'page_', 'regla_', 'security_', 'telefono_', 'password_temporal_'];
  const definitions = new Set();
  const calls = new Set();
  for (const source of phpFiles.map((file) => read(file))) {
    for (const match of source.matchAll(/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/g)) definitions.add(match[1]);
    for (const match of source.matchAll(/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/g)) {
      if (prefixes.some((prefix) => match[1].startsWith(prefix))) calls.add(match[1]);
    }
  }
  const missing = [...calls].filter((name) => !definitions.has(name));
  assert.deepEqual(missing, [], `Funciones auxiliares no definidas: ${missing.join(', ')}`);
});

test('el portal obtiene la sede de la sesión mediante el horario', () => {
  const source = read('api/portal-alumno.php');
  assert.match(source, /JOIN horarios h ON h\.id=se\.horario_id/);
  assert.match(source, /h\.sede_id=:sede/);
  assert.doesNotMatch(source, /\bse\.sede_id\b/);
});

test('el health check público usa la conexión existente', () => {
  includes('public/api/health.php', "require dirname(__DIR__, 2) . '/config/pdo.php'");
  excludes('public/api/health.php', '$pdo = db()');
});

test('ficha y edición de alumno respetan la sede activa', () => {
  for (const file of ['public/ficha-alumno.php', 'public/editar-alumno.php']) {
    includes(file, 'auth_active_sede_clave()');
    assert.match(read(file), /a?\.sede_id=:sede|sede_id=:sede/);
  }
});

test('los pagos toman el actor de la sesión y no del cliente', () => {
  includes('api/pagos-smart.php', "$createdBy=(string)$me['id']");
  for (const file of ['api/pagos-smart.php', 'api/editar-pago.php', 'api/invalidar-pago.php']) {
    assert.doesNotMatch(read(file), /\$(?:input|in)\[['\"](?:created_by|usuario_id)['\"]\]/);
    includes(file, "header('Allow: POST')");
  }
});

test('editar e invalidar pagos bloquean la fila y conservan el alcance de sede', () => {
  for (const file of ['api/editar-pago.php', 'api/invalidar-pago.php']) {
    includes(file, 'FOR UPDATE');
    assert.match(read(file), /UPDATE pagos SET[\s\S]{0,300}WHERE id=:id AND estado='VALIDO'/);
  }
  assert.match(read('api/editar-pago.php'), /UPDATE mensualidades SET[\s\S]{0,350}WHERE id=:id AND sede_id=:sede/);
  assert.match(read('api/editar-pago.php'), /UPDATE inscripciones SET[\s\S]{0,250}WHERE id=:id AND sede_id=:sede/);
  assert.match(read('api/invalidar-pago.php'), /UPDATE alumnos SET plan_programado_id=NULL[\s\S]{0,300}sede_id=:sede/);
});

test('el alta de pagos serializa por alumno antes de revisar duplicados', () => {
  const source = read('api/pagos-smart.php');
  const begin = source.indexOf('$pdo->beginTransaction()');
  const lock = source.indexOf('LIMIT 1 FOR UPDATE');
  const payment = source.indexOf('INSERT INTO pagos(');
  assert.ok(begin >= 0 && lock > begin && payment > lock, 'El alumno debe quedar bloqueado antes de crear el pago');
  assert.equal((source.match(/\$pdo->beginTransaction\(\)/g) || []).length, 1, 'El flujo debe abrir una sola transacción');
});

test('las altas de intensivos serializan por alumno antes de validar exclusividad', () => {
  const source = read('api/intensivo-alumnos.php');
  includes('api/intensivo-alumnos.php', "estado_administrativo<>'BAJA' LIMIT 1 FOR UPDATE");
  assert.ok(
    source.indexOf("estado_administrativo<>'BAJA' LIMIT 1 FOR UPDATE") < source.indexOf('El alumno ya pertenece a otro curso intensivo activo'),
    'el bloqueo del alumno debe preceder la comprobación de otros intensivos activos'
  );
  assert.match(source, /curso_intensivo_alumnos[\s\S]{0,500}LIMIT 1 FOR UPDATE/);
});

test('la decisión de continuidad bloquea la relación y recalcula ambos caminos', () => {
  const source = read('api/continuidad-intensivo.php');
  assert.match(source, /beginTransaction\(\)[\s\S]{0,700}curso_intensivo_alumnos[\s\S]{0,500}FOR UPDATE/);
  assert.match(source, /if\(!\$continua\)[\s\S]{0,600}regla_recalcular_alumno\(\$pdo,\$alumnoId\)/);
  assert.equal((source.match(/\$pdo->beginTransaction\(\)/g) || []).length, 1);
});

test('la asistencia bloquea la sesión antes de validar que siga abierta', () => {
  const source = read('api/sesiones.php');
  assert.match(source, /ASISTENCIA'[\s\S]{0,900}beginTransaction\(\)[\s\S]{0,900}s\.cerrada=0[\s\S]{0,300}FOR UPDATE/);
  assert.match(source, /if\(!\$sesion\)\{\$pdo->rollBack\(\);out\(/);
});

test('la generación concurrente de sesiones es idempotente', () => {
  includes('api/sesiones.php', 'INSERT IGNORE INTO sesiones');
  includes('database/migrations/20260816_attendance_model.sql', 'uq_sesion_fecha_horario');
});

test('la consulta n8n exige bearer y usa comparación constante', () => {
  includes('config/database.php', "'/api/alumno-por-whatsapp.php'");
  includes('api/alumno-por-whatsapp.php', "getenv('HACHE_N8N_LOOKUP_TOKEN')");
  includes('api/alumno-por-whatsapp.php', 'hash_equals($secret,$token)');
});

test('el catálogo administrativo de sedes permanece detrás de autenticación', () => {
  const gate = read('config/database.php');
  assert.match(gate, /if \(!in_array\(\$uri, \$publicApi, true\)\) \{[\s\S]{0,500}auth_require\(\['ADMIN','VERIFICADOR'\]\)/);
  assert.doesNotMatch(gate, /\$publicApi\s*=\s*\[[^\]]*['"]\/api\/sedes\.php['"]/);
  includes('api/sedes.php', "require __DIR__.'/../config/database.php'");
});

test('Sharky acepta una clave por entorno o archivo env sin exponerla', () => {
  includes('public/api/sharky.php', "getenv('OPENAI_API_KEY')");
  includes('public/api/sharky.php', "str_starts_with($line,'export ')");
  includes('public/api/sharky.php', "str_starts_with($line,'OPENAI_API_KEY=')");
  excludes('public/api/sharky.php', "file_get_contents($keyFile)");
});

test('mensajes quedan ligados a sede por migración y aplicación', () => {
  const migration = read('database/migrations/20260822_integrity_support.sql');
  assert.match(migration, /ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS sede_id/);
  assert.match(migration, /FOREIGN KEY \(sede_id\) REFERENCES sedes\(id\)/);
  assert.match(migration, /CREATE TRIGGER trg_mensajes_sede_default/);
  assert.match(migration, /SELECT sede_id FROM alumnos WHERE id=NEW\.alumno_id/);
  includes('api/mensajes.php', 'INSERT INTO mensajes(sede_id');
  includes('api/portal-alumno.php', 'FROM mensajes WHERE sede_id=:sede');
});

test('no existe una contraseña temporal compartida', () => {
  assert.doesNotMatch(allPhp, /Hache2026/i);
  assert.doesNotMatch(allPhp, /configuracion[^\n;]*clave\s*=\s*['\"]password_temporal/i);
  includes('config/passwords.php', 'random_int');
  includes('database/migrations/20260822_integrity_support.sql', "DELETE FROM configuracion WHERE clave='password_temporal'");
});

test('las solicitudes web no ejecutan DDL', () => {
  const webPhp = ['api', 'config', 'public'].flatMap((directory) => filesUnder(directory, '.php'));
  const offenders = webPhp.filter((file) => /\b(?:CREATE|ALTER|DROP)\s+(?:TABLE|TRIGGER|INDEX)\b/i.test(read(file)));
  assert.deepEqual(offenders, [], `DDL encontrado en solicitudes web: ${offenders.join(', ')}`);
});

test('las exportaciones con texto neutralizan fórmulas CSV', () => {
  for (const file of ['api/exportar-alumnos-horarios.php', 'api/exportar-liquidacion.php', 'api/reportes-exportar.php']) {
    assert.match(read(file), /\[=\+\\-@\]/);
    includes(file, "array_map('csvCell'");
  }
});

test('los endpoints mutables rechazan JSON malformado', () => {
  const endpoints = [
    'api/alumno-rapido.php',
    'api/ausencias-programadas.php',
    'api/cambiar-password.php',
    'api/comisiones-proa.php',
    'api/continuidad-intensivo.php',
    'api/diagnostico.php',
    'api/horarios.php',
    'api/intensivos.php',
    'api/pagos.php',
    'api/portal-alumno.php',
    'api/sesion.php',
    'api/sesiones.php',
    'api/usuarios.php'
  ];
  for (const file of endpoints) {
    const source = read(file);
    includes(file, "file_get_contents('php://input')", `${file} debe leer el cuerpo JSON`);
    assert.match(source, /json_decode\(/, `${file} debe decodificar JSON explícitamente`);
    assert.match(source, /!is_array\([^)]*\)/, `${file} debe rechazar JSON malformado`);
  }
});

test('las páginas administrativas sensibles declaran su rol', () => {
  const protectedPages = {
    'public/ausencias.php': "page_require(['ADMIN','VERIFICADOR'])",
    'public/editar-alumno.php': "page_require(['ADMIN'])",
    'public/ficha-alumno.php': "page_require(['ADMIN','VERIFICADOR'])",
    'public/intensivo-detalle.php': "page_require(['ADMIN','VERIFICADOR'])",
    'public/intensivos.php': "page_require(['ADMIN','VERIFICADOR'])",
    'public/pagos.php': "page_require(['ADMIN','VERIFICADOR'])",
    'public/resumen-financiero.php': "page_require(['ADMIN','VERIFICADOR'])",
    'public/sesiones.php': "page_require(['ADMIN','VERIFICADOR'])",
    'public/usuarios.php': "page_require(['ADMIN'])"
  };
  for (const [file, guard] of Object.entries(protectedPages)) includes(file, guard);
});

test('los atajos de asistencia autentican incluso cuando no hay clases', () => {
  for (const file of ['api/asistencia.php', 'api/sesiones-laborables.php']) {
    const source = read(file);
    includes(file, "require_once __DIR__ . '/../config/auth.php'");
    assert.match(source, /if \(\$method === 'GET' \|\| \$method === 'HEAD'\) auth_require\(\['ADMIN','VERIFICADOR'\]\)/);
    assert.ok(source.indexOf('auth_require(') < source.indexOf("format('N')"), `${file} debe autenticar antes de responder el fin de semana`);
  }
});

test('el flujo de pagos escapa los planes antes de usar innerHTML', () => {
  for (const file of ['public/assets/pagos-flow-v2.js', 'public/assets/pagos-flow.js']) {
    includes(file, 'const esc=');
    assert.match(read(file), /esc\(p\.nombre/);
  }
});

test('el registro público separa errores esperados de fallas internas', () => {
  includes('public/registro.php', 'final class RegistroPublicoException');
  includes('public/registro.php', 'catch(RegistroPublicoException');
  includes('public/registro.php', "error_log('Hache registro público: '");
  excludes('public/registro.php', 'catch(Throwable$x){if($pdo->inTransaction())$pdo->rollBack();$err=$x->getMessage();');
});

test('las altas y ediciones de alumnos serializan la identidad y revalidan WhatsApp', () => {
  includes('config/reglas-acceso.php', 'function regla_bloquear_identidades_alumnos');
  for (const file of ['api/alumnos.php', 'api/portal-alumno.php', 'public/registro.php', 'public/editar-alumno.php']) {
    const source = read(file);
    includes(file, 'regla_bloquear_identidades_alumnos($pdo)');
    assert.ok(
      source.lastIndexOf('WHERE whatsapp=:w') > source.indexOf('regla_bloquear_identidades_alumnos($pdo)'),
      `${file} debe volver a buscar el WhatsApp después de adquirir el bloqueo`
    );
  }
});

test('el registro público entrega la contraseña aleatoria una sola vez', () => {
  const source = read('public/registro.php');
  includes('public/registro.php', '$portalPassword=$temp');
  includes('public/registro.php', 'la contraseña solo se muestra ahora');
  assert.match(source, /Contraseña temporal:[\s\S]{0,100}\$portalPassword/);
});

test('la edición rápida de WhatsApp usa la misma exclusión de identidad', () => {
  const source = read('api/alumno-rapido.php');
  assert.match(source, /WHATSAPP'[\s\S]{0,800}beginTransaction\(\)[\s\S]{0,200}regla_bloquear_identidades_alumnos/);
  assert.match(source, /alumnos WHERE id=:id AND sede_id=:s LIMIT 1 FOR UPDATE/);
});

test('el cambio rápido de horario se serializa con el ingreso a intensivos', () => {
  const source = read('api/alumno-rapido.php');
  const scheduleValue = source.indexOf("$v=trim((string)$valor)");
  const begin = source.indexOf('$pdo->beginTransaction()', scheduleValue);
  const studentLock = source.indexOf('alumnos WHERE id=:id AND sede_id=:s LIMIT 1 FOR UPDATE', begin);
  const intensiveCheck = source.indexOf("ci.estado IN ('PROGRAMADO','EN_CURSO')", studentLock);
  const update = source.indexOf('UPDATE alumnos SET horario_preferido_id=:h', intensiveCheck);
  assert.ok(scheduleValue >= 0 && begin > scheduleValue && studentLock > begin && intensiveCheck > studentLock && update > intensiveCheck,
    'el alumno debe quedar bloqueado antes de volver a comprobar el intensivo y cambiar su horario');
  assert.ok(source.indexOf('$pdo->commit()', update) > update, 'el cambio de horario debe confirmar la transacción');
});

test('la edición completa revalida alumno, horario y plan dentro de la transacción', () => {
  const source = read('public/editar-alumno.php');
  const begin = source.indexOf('$pdo->beginTransaction()');
  const studentLock = source.indexOf('SELECT sede_id,horario_preferido_id,plan_actual_id', begin);
  const scheduleLock = source.indexOf('SELECT activo FROM horarios', studentLock);
  const planLock = source.indexOf('SELECT activo FROM planes', scheduleLock);
  const update = source.indexOf('UPDATE alumnos SET nombre=', planLock);
  assert.ok(begin >= 0 && studentLock > begin && scheduleLock > studentLock && planLock > scheduleLock && update > planLock,
    'la edición debe bloquear y revalidar todas sus referencias antes de guardar');
  includes('public/editar-alumno.php', 'AND (activo=1 OR id=:actual)');
});

test('el detalle de pago escapa etiquetas derivadas antes de usar innerHTML', () => {
  includes('public/pago-detalle.php', 'esc(tipo(p.tipo))');
  includes('public/pago-detalle.php', 'esc(dt(p.fecha))');
});

test('el dashboard neutraliza errores y datos de horario antes de renderizarlos', () => {
  const source = read('public/dashboard.php');
  includes('public/dashboard.php', 'esc(e.message');
  includes('public/dashboard.php', "esc(String(x.hora_inicio??'').slice(0,5))");
  includes('public/dashboard.php', 'const horarios=Array.isArray(d.horarios)?d.horarios:[]');
});

test('la gestión y asignación de alumnos son atómicas y bloquean su fila', () => {
  for (const file of ['api/alumno-gestion.php', 'api/asignar-plan.php']) {
    const source = read(file);
    assert.match(source, /beginTransaction\(\)[\s\S]{0,500}alumnos[\s\S]{0,300}FOR UPDATE/);
    includes(file, '$pdo->commit()');
    assert.match(source, /inTransaction\(\)\)\$pdo->rollBack\(\)/);
  }
  includes('api/alumno-gestion.php', "header('Allow: POST')");
});

test('el portal conserva el país al editar teléfonos internacionales', () => {
  includes('public/mi-cuenta.php', "whatsapp_pais:selector?.value||''");
  includes('api/portal-alumno.php', "$in['whatsapp_pais']");
  includes('api/portal-alumno.php', 'telefono_normalizar_entrada(trim');
});

test('los avisos de ausencia se serializan por alumno', () => {
  for (const file of ['api/ausencias-programadas.php', 'api/portal-alumno.php']) {
    const source = read(file);
    const lock = source.indexOf('LIMIT 1 FOR UPDATE');
    const duplicate = source.indexOf("FROM avisos_ausencia WHERE alumno_id=:a AND fecha_desde=:d");
    const insert = source.indexOf('INSERT INTO avisos_ausencia');
    assert.ok(lock >= 0 && duplicate > lock && insert > duplicate, `${file} debe bloquear antes de buscar e insertar el aviso`);
  }
});

test('el formulario administrativo de alumno exige CSRF', () => {
  includes('config/auth.php', 'function auth_csrf_validate');
  includes('public/editar-alumno.php', "auth_csrf_validate(isset($_POST['csrf'])");
  includes('public/editar-alumno.php', 'name="csrf" value="<?=e(auth_csrf_token())?>"');
});

test('la creación de cursos intensivos serializa por sede y fecha', () => {
  for (const file of ['api/intensivos.php', 'public/registro.php']) {
    const source = read(file);
    const siteLock = source.indexOf('SELECT id FROM sedes WHERE id=:s LIMIT 1 FOR UPDATE');
    const courseLookup = source.lastIndexOf('SELECT id FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:f LIMIT 1');
    assert.ok(siteLock >= 0 && courseLookup > siteLock, `${file} debe bloquear la sede antes de buscar el curso`);
  }
});

test('las altas revalidan sus referencias activas después de adquirir el bloqueo', () => {
  const admin = read('api/alumnos.php');
  const adminLock = admin.indexOf('regla_bloquear_identidades_alumnos($pdo)');
  assert.ok(admin.indexOf('activo=1 AND regular=1 LIMIT 1 FOR UPDATE', adminLock) > adminLock);
  assert.ok(admin.indexOf('activo=1 LIMIT 1 FOR UPDATE', adminLock) > adminLock);
  const publicForm = read('public/registro.php');
  const publicLock = publicForm.indexOf('regla_bloquear_identidades_alumnos($pdo)');
  assert.ok(publicForm.indexOf('AND {$col}=1 LIMIT 1 FOR UPDATE', publicLock) > publicLock);
  assert.ok(publicForm.indexOf("rol='ADMIN' AND activo=1", publicLock) > publicLock);
});

test('el cierre de sesión inexistente no reporta éxito', () => {
  const source = read('api/sesiones.php');
  assert.match(source, /rowCount\(\)===0\)out\(\['ok'=>false/);
});

test('la reconciliación global no se repite en cada vista', () => {
  includes('config/backend-bootstrap.php', "$_SESSION['hache_reconciliada']");
  includes('config/backend-bootstrap.php', "$reconciliada !== $hoyReglas");
  includes('config/reglas-acceso.php', 'function regla_reconciliar_sede_una_vez');
  for (const file of ['api/alumnos.php', 'api/pagos.php', 'config/backend-bootstrap.php']) {
    includes(file, 'regla_reconciliar_sede_una_vez');
  }
});

test('el diagnóstico acepta eventos de verificadores sin abrir otras mutaciones', () => {
  includes('config/database.php', "$uri === '/api/diagnostico.php'");
  assert.match(read('config/database.php'), /diagnostico\.php'[\s\S]{0,100}auth_require\(\['ADMIN','VERIFICADOR'\]\)/);
});

test('las sesiones se revalidan contra cuentas activas antes de mutar', () => {
  const source = read('config/auth.php');
  includes('config/auth.php', 'function auth_revalidate_user(bool $force=false)');
  assert.match(source, /SELECT u\.id,u\.usuario,u\.password_hash,u\.rol,u\.activo/);
  assert.match(source, /if\(!\$fresh \|\| !\(bool\)\$fresh\['activo'\]\)\{auth_logout\(\);return null;\}/);
  assert.match(source, /\$mutation=!in_array\(\$method,\['GET','HEAD','OPTIONS'\],true\)/);
  includes('config/auth.php', "$_SESSION['hache_password_fingerprint']");
  assert.match(source, /!hash_equals\(\$storedFingerprint,\$fingerprint\)\)\{auth_logout\(\);return null;\}/);
  assert.match(source, /catch\(Throwable \$e\)[\s\S]{0,180}auth_logout\(\);[\s\S]{0,80}return null/);
  assert.match(source, /function auth_require[\s\S]{0,180}auth_revalidate_user\(\)/);
  assert.match(source, /function page_require[\s\S]{0,180}auth_revalidate_user\(\)/);
  includes('api/sesion.php', 'auth_revalidate_user(true)');
});

test('cambiar o restablecer contraseña invalida las demás sesiones', () => {
  includes('config/auth.php', 'function auth_refresh_password_fingerprint');
  includes('api/cambiar-password.php', 'session_regenerate_id(true)');
  includes('api/cambiar-password.php', 'auth_refresh_password_fingerprint($newHash)');
  includes('api/usuarios.php', 'UPDATE usuarios SET password_hash=:p,debe_cambiar_password=1');
});

test('roles y sede del verificador se validan tanto al entrar como al revalidar', () => {
  includes('api/login.php', 's.activo sede_activo');
  includes('api/login.php', 'Este verificador no tiene una sede activa asignada');
  includes('config/auth.php', "!in_array($role,['ADMIN','VERIFICADOR','ALUMNO'],true)");
  includes('config/auth.php', "(int)($fresh['sede_activo']??0)!==1");
  includes('config/auth.php', "['ADMIN','VERIFICADOR'],true))throw new RuntimeException('No tienes permiso para cambiar de sede')");
});

test('el pago rápido confía en la sesión del servidor y no en sessionStorage', () => {
  const source = read('public/assets/alumnos-quick-pay.js');
  assert.doesNotMatch(source, /sessionStorage\.getItem\(['\"]hache_usuario/);
  assert.doesNotMatch(source, /if\(!u\.id\)/);
  includes('api/pagos-smart.php', "$createdBy=(string)$me['id']");
});

test('las páginas administrativas también revalidan la cuenta', () => {
  includes('config/backend-bootstrap.php', '$u = auth_revalidate_user();');
  excludes('config/backend-bootstrap.php', '$u = auth_user();');
});

test('toda cuenta nueva debe cambiar la contraseña entregada por el administrador', () => {
  const source = read('api/usuarios.php');
  assert.match(source, /INSERT INTO usuarios\(usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id,sede_id\) VALUES\(:u,:p,:r,1,1,:a,:s\)/);
  assert.doesNotMatch(source, /\$forzar=\$rol==='ALUMNO'/);
});

test('un verificador solo recibe configuración operativa explícitamente permitida', () => {
  const source = read('api/configuracion.php');
  includes('api/configuracion.php', "$visibles = ['nombre_app','dias_clase','version_app','alerta_dias_fin_intensivo','minimo_proa_mensual']");
  assert.match(source, /if \(\(\$me\['rol'\] \?\? ''\) === 'ADMIN'\)[\s\S]{0,800}WHERE clave IN/);
});

test('el cierre calcula su fotografía dentro de la transacción protegida', () => {
  const source = read('api/cierres-mensuales.php');
  const begin = source.lastIndexOf('$pdo->beginTransaction()');
  const lock = source.lastIndexOf('FOR UPDATE');
  const calculate = source.lastIndexOf('calculateClose($pdo,$period,$site)');
  assert.ok(begin >= 0 && lock > begin && calculate > lock, 'El cierre debe calcularse después de iniciar la transacción y bloquear el periodo');
});

test('la promoción de planes se autentica, limita a sede y valida el plan', () => {
  const helper = read('config/reglas-acceso.php');
  assert.match(helper, /function regla_promover_planes_programados_sede/);
  assert.match(helper, /JOIN planes p ON p\.id=a\.plan_programado_id AND p\.sede_id=a\.sede_id AND p\.activo=1/);
  assert.match(helper, /WHERE a\.sede_id=:s/);
  for (const file of ['api/pago-contexto.php', 'api/pagos-smart.php']) {
    includes(file, 'regla_promover_planes_programados_sede');
  }
  for (const file of ['api/alumnos.php', 'api/pagos.php', 'config/backend-bootstrap.php']) {
    includes(file, 'regla_reconciliar_sede_una_vez');
  }
  for (const file of ['api/alumnos.php', 'public/alumnos.php']) {
    assert.doesNotMatch(read(file), /\$pdo->exec\("UPDATE alumnos SET plan_actual_id=plan_programado_id/);
  }
  includes('api/alumnos.php', "$me=auth_require(['ADMIN','VERIFICADOR'])");
  includes('api/alumnos.php', "if($me['rol']==='ADMIN')");
  includes('api/pago-contexto.php', "$me=auth_require(['ADMIN','VERIFICADOR'])");
  includes('api/pago-contexto.php', "if($me['rol']==='ADMIN')");
  includes('config/backend-bootstrap.php', "if ($u['rol'] === 'ADMIN')");
});

test('un pago histórico no cambia el plan vigente del alumno', () => {
  const source = read('api/pagos-smart.php');
  assert.match(source, /if\(\$periodoInicio>\$periodoActual\)[\s\S]+elseif\(\$periodoInicio==\$periodoActual\)/);
  assert.doesNotMatch(source, /if\(\$periodoInicio>\$periodoActual\)[\s\S]{0,700}else\{\$stmt=\$pdo->prepare\("UPDATE alumnos SET plan_actual_id/);
});

test('el cobro bloquea el plan y una invalidación conserva pagos válidos duplicados', () => {
  includes('api/pagos-smart.php', 'AND sede_id=:s AND activo=1 LIMIT 1 FOR UPDATE');
  const source = read('api/invalidar-pago.php');
  const invalidate = source.indexOf("UPDATE pagos SET estado='INVALIDADO'");
  const remaining = source.indexOf("WHERE mensualidad_id=:mensualidad AND estado='VALIDO'", invalidate);
  const reopen = source.indexOf('UPDATE mensualidades SET importe_cobrado=NULL', remaining);
  assert.ok(invalidate >= 0 && remaining > invalidate && reopen > remaining, 'Debe buscar otro pago válido antes de reabrir la mensualidad');
  assert.match(source.slice(remaining, reopen + 120), /if\(!\$otroPagoValido\)/);
});

test('comisiones PROA usa el convenio configurado y detecta eliminaciones inexistentes', () => {
  const source = read('api/comisiones-proa.php');
  includes('api/comisiones-proa.php', 'porcentaje_mensualidad_socio');
  includes('api/comisiones-proa.php', 'porcentaje_intensivo_socio');
  includes('api/comisiones-proa.php', 'porcentaje_inscripcion_socio');
  includes('api/comisiones-proa.php', 'minimo_mensual_socio');
  assert.doesNotMatch(source, /\$mens\s*\*\s*\.5/);
  assert.doesNotMatch(source, /\$intensivos\s*\*\s*\.5/);
  assert.match(source, /ELIMINAR[\s\S]{0,500}rowCount\(\)===0/);
});

test('las migraciones de intensivos conservan delimitadores estructurales', () => {
  for (const file of ['database/migrations/20260815_group_intensive_model.sql', 'database/migrations/20260822_integrity_support.sql']) {
    const source = read(file);
    const triggerCount = (source.match(/CREATE TRIGGER/g) || []).length;
    const endCount = (source.match(/END\$\$/g) || []).length;
    assert.equal(endCount, triggerCount, `${file} tiene bloques de trigger desbalanceados`);
    assert.equal((source.match(/DELIMITER \$\$/g) || []).length, (source.match(/DELIMITER ;/g) || []).length, `${file} tiene DELIMITER desbalanceado`);
  }
});

test('la migración de intensivos completa checks en tablas preexistentes', () => {
  const source = read('database/migrations/20260815_group_intensive_model.sql');
  for (const constraint of ['chk_cia_reposiciones', 'chk_cia_importe_continuidad', 'chk_cia_continuidad_plan']) {
    if (constraint === 'chk_cia_importe_continuidad') {
      assert.match(source, /constraint_name IN \('chk_cia_importe_continuidad','chk_cia_importe'\)/);
    } else {
      assert.match(source, new RegExp(`constraint_name='${constraint}'`));
    }
    assert.match(source, new RegExp(`ADD CONSTRAINT ${constraint} CHECK`));
  }
});

test('los triggers de mensualidad no reescriben periodos históricos', () => {
  for (const file of ['database/migrations/20260819_palapas_finanzas.sql', 'database/migrations/20260822_integrity_support.sql']) {
    const source = read(file);
    assert.ok((source.match(/IF NEW\.periodo_inicio IS NULL OR NEW\.periodo_fin IS NULL THEN/g) || []).length >= 2, `${file} debe preservar fechas explícitas en INSERT y UPDATE`);
  }
});

test('el login normaliza el rol antes de validar sus vínculos', () => {
  const source = read('api/login.php');
  const normalized = source.indexOf("$role=strtoupper(trim((string)($user['rol']??'')))");
  const assigned = source.indexOf("$user['rol']=$role", normalized);
  const student = source.indexOf("$role==='ALUMNO'", assigned);
  const verifier = source.indexOf("$role==='VERIFICADOR'", student);
  assert.ok(normalized >= 0 && assigned > normalized && student > assigned && verifier > student,
    'el rol normalizado debe gobernar las validaciones de alumno y verificador');
});

test('las sesiones públicas conservan cookies seguras detrás del proxy HTTPS', () => {
  for (const file of ['config/auth.php', 'public/registro.php']) {
    includes(file, "HTTP_X_FORWARDED_PROTO");
    assert.match(read(file), /cookie_secure'\s*=>\s*\$httpsDirect\s*\|\|\s*\$httpsForwarded/);
  }
});

test('la interfaz financiera usa la sede autorizada por la sesión', () => {
  includes('public/finanzas.php', "auth_resolve_sede_clave((string)($_GET['sede']??''))");
  assert.doesNotMatch(read('public/finanzas.php'), /in_array\(\$sedeRaw,\['MONTEVERDE','PALAPAS'\]/);
});

test('las clases visuales derivadas de datos se limitan a valores permitidos', () => {
  includes('public/alertas.php', "['ALTA','MEDIA','BAJA','OK'].includes(String(v))");
  includes('public/alertas.php', '${nivel(x.nivel)}');
  assert.doesNotMatch(read('public/alertas.php'), /class="a \$\{x\.nivel\}"/);
});

test('el sexto aviso justificado de intensivo no crea otro crédito', () => {
  const source = read('api/sesiones.php');
  const lock = source.indexOf('SELECT reposiciones_justificadas FROM curso_intensivo_alumnos');
  const cap = source.indexOf('if($creditos>=5)', lock);
  const insert = source.indexOf('INSERT IGNORE INTO ausencias', cap);
  assert.ok(lock >= 0 && cap > lock && insert > cap, 'debe bloquear y comprobar el tope antes de insertar la ausencia');
  includes('api/sesiones.php', 'LIMIT 1 FOR UPDATE');
  includes('api/sesiones.php', 'Ya alcanzó 5 reposiciones justificadas');
});

test('los importadores históricos no mezclan horarios, planes ni cursos entre sedes', () => {
  const base = read('database/maintenance/import_real_students_apr_aug_2026.php');
  includes('database/maintenance/import_real_students_apr_aug_2026.php', "clave='MONTEVERDE' AND activo=1");
  assert.match(base, /FROM planes WHERE sede_id=:s AND activo=1/);
  assert.ok((base.match(/FROM horarios WHERE sede_id=:s/g) || []).length >= 2);
  assert.match(base, /INSERT INTO alumnos\(id,sede_id/);
  assert.match(base, /INSERT INTO mensualidades\(id,sede_id/);
  assert.match(base, /INSERT INTO inscripciones\(id,sede_id/);
  assert.match(base, /INSERT INTO cursos_intensivos\(id,sede_id/);

  const full = read('database/maintenance/import_real_students_apr_aug_2026_full.php');
  assert.match(full, /FROM horarios WHERE sede_id=:s/);
  assert.match(full, /FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:fi/);
  includes('database/maintenance/import_real_students_apr_aug_2026_full.php', 'Este importador histórico solo admite una base sin alumnos fuera de MONTEVERDE.');
  assert.match(full, /INSERT INTO alumnos\(id,sede_id/);
  assert.match(full, /INSERT INTO cursos_intensivos\(id,sede_id/);
});

test('las inclusiones literales basadas en __DIR__ apuntan a archivos existentes', () => {
  const missing = [];
  const pattern = /\b(?:require|require_once|include|include_once)\s*(?:\(\s*)?__DIR__\s*\.\s*['"]([^'"]+)['"]/g;
  for (const file of phpFiles) {
    const source = read(file);
    for (const match of source.matchAll(pattern)) {
      const target = path.resolve(root, path.dirname(file), `.${match[1]}`);
      if (!fs.existsSync(target)) missing.push(`${file} -> ${path.relative(root, target)}`);
    }
  }
  assert.deepEqual(missing, [], `Inclusiones locales rotas: ${missing.join(', ')}`);
});

test('las rutas locales literales apuntan a controladores y recursos existentes', () => {
  const textFiles = ['.php', '.js', '.html', '.css'].flatMap((extension) => filesUnder('public', extension));
  const missing = [];
  const pattern = /["'`](\/(?:api\/[A-Za-z0-9._/-]+|assets\/[A-Za-z0-9._/-]+|[A-Za-z0-9_-]+\.php))(?:[?#][^"'`]*)?["'`]/g;
  for (const file of textFiles) {
    const source = read(file);
    for (const match of source.matchAll(pattern)) {
      const route = match[1];
      const target = route.startsWith('/api/')
        ? path.join(root, route.slice(1))
        : path.join(root, 'public', route.slice(1));
      if (!fs.existsSync(target)) missing.push(`${file} -> ${route}`);
    }
  }
  assert.deepEqual([...new Set(missing)], [], `Rutas locales rotas: ${[...new Set(missing)].join(', ')}`);
});

let passed = 0;
for (const [name, fn] of tests) {
  try {
    fn();
    passed += 1;
    console.log(`✓ ${name}`);
  } catch (error) {
    console.error(`✗ ${name}`);
    throw error;
  }
}
console.log(`\n${passed} regresiones estáticas verificadas.`);
