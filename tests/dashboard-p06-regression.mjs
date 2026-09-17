import fs from 'node:fs';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const helper=fs.readFileSync(new URL('../config/dashboard-p06.php',import.meta.url),'utf8');
const coverage=fs.readFileSync(new URL('../config/asistencia-cobertura.php',import.meta.url),'utf8');
const api=fs.readFileSync(new URL('../api/dashboard.php',import.meta.url),'utf8');
const studentsApi=fs.readFileSync(new URL('../api/alumno-gestion.php',import.meta.url),'utf8');
const sessionsApi=fs.readFileSync(new URL('../api/sesiones.php',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../public/dashboard.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../database/migrations/20260917_f6_dashboard_metrics.sql',import.meta.url),'utf8');
const migrationRunner=fs.readFileSync(new URL('../bin/migrate-f6-dashboard-metrics.php',import.meta.url),'utf8');
const deploy=fs.readFileSync(new URL('../ops/production-readiness/deploy-hache-natacion',import.meta.url),'utf8');

assert.match(helper,/function dashboard_nuevos_alumnos/);
assert.match(helper,/fecha_inicio BETWEEN :i AND :f/);
assert.match(helper,/function dashboard_bajas_registradas/);
assert.match(helper,/accion='ALUMNO_BAJA'/);
assert.doesNotMatch(helper,/updated_at/);
assert.match(helper,/function dashboard_asistencia_periodo/);
assert.match(helper,/s\.estado='REALIZADA'/);
assert.match(helper,/c\.complete=1/);
assert.match(helper,/c\.expected_count>0/);

const helperPath=fileURLToPath(new URL('../config/dashboard-p06.php',import.meta.url));
const program=`require ${JSON.stringify(helperPath)};
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE configuracion(clave TEXT PRIMARY KEY,valor TEXT)');
$pdo->exec("INSERT INTO configuracion VALUES ('dashboard_bajas_cobertura_desde','2026-09-17 18:00:00'),('dashboard_asistencia_cobertura_desde','2026-09-17 18:00:00')");
$pdo->exec('CREATE TABLE alumnos(id TEXT PRIMARY KEY,sede_id TEXT,nombre TEXT,fecha_inicio TEXT,estado_administrativo TEXT)');
$pdo->exec("INSERT INTO alumnos VALUES ('a1','A','Ana','2026-09-02','ACTIVO'),('a2','A','Beto','2026-08-20','ACTIVO'),('a3','B','Cora','2026-09-05','ACTIVO')");
$pdo->exec('CREATE TABLE auditoria_eventos(id TEXT PRIMARY KEY,accion TEXT,entidad TEXT,entidad_id TEXT,detalle TEXT,created_at TEXT)');
$pdo->exec("INSERT INTO auditoria_eventos VALUES ('e1','ALUMNO_BAJA','alumno','a1','{\"sede_id\":\"A\"}','2026-09-17 19:00:00'),('e2','ALUMNO_BAJA','alumno','a3','{\"sede_id\":\"B\"}','2026-09-17 19:05:00'),('e3','ALUMNO_BAJA','alumno','a2','{\"sede_id\":\"A\"}','2026-09-17 17:00:00')");
$pdo->exec('CREATE TABLE horarios(id TEXT PRIMARY KEY,sede_id TEXT,hora_inicio TEXT,hora_fin TEXT)');
$pdo->exec("INSERT INTO horarios VALUES ('h1','A','07:00:00','08:00:00'),('h2','B','07:00:00','08:00:00')");
$pdo->exec('CREATE TABLE sesiones(id TEXT PRIMARY KEY,fecha TEXT,horario_id TEXT,estado TEXT)');
$pdo->exec("INSERT INTO sesiones VALUES ('s1','2026-09-17','h1','REALIZADA'),('s2','2026-09-17','h1','REALIZADA'),('s3','2026-09-17','h1','CANCELADA'),('s4','2026-09-17','h2','REALIZADA')");
$pdo->exec('CREATE TABLE sesion_asistencia_cobertura(sesion_id TEXT PRIMARY KEY,expected_count INTEGER,marked_count INTEGER,complete INTEGER,captured_at TEXT)');
$pdo->exec("INSERT INTO sesion_asistencia_cobertura VALUES ('s1',3,3,1,'2026-09-17 20:00:00'),('s2',2,1,0,'2026-09-17 21:00:00'),('s3',2,2,1,'2026-09-17 22:00:00'),('s4',1,1,1,'2026-09-17 20:00:00')");
$pdo->exec('CREATE TABLE asistencias(id TEXT PRIMARY KEY,sesion_id TEXT,estado TEXT)');
$pdo->exec("INSERT INTO asistencias VALUES ('x1','s1','PRESENTE'),('x2','s1','PRESENTE'),('x3','s1','AUSENTE_JUSTIFICADA'),('x4','s2','PRESENTE'),('x5','s3','PRESENTE'),('x6','s3','PRESENTE'),('x7','s4','PRESENTE')");
echo json_encode([
 dashboard_nuevos_alumnos($pdo,'A','2026-09-01','2026-09-30'),
 dashboard_bajas_registradas($pdo,'A','2026-09-01','2026-09-30'),
 dashboard_asistencia_periodo($pdo,'A','2026-09-01','2026-09-30')
]);`;
const [newStudents,withdrawals,attendance]=JSON.parse(execFileSync('php',['-r',program],{encoding:'utf8'}));
assert.equal(newStudents.total,1);
assert.equal(newStudents.rows[0].alumno_id,'a1');
assert.equal(withdrawals.disponible,true);
assert.equal(withdrawals.total,1);
assert.equal(withdrawals.rows[0].evento_id,'e1');
assert.equal(attendance.disponible,true);
assert.equal(attendance.porcentaje,66.7);
assert.equal(attendance.sesiones_completas,1);
assert.equal(attendance.sesiones_capturadas,2);
assert.equal(attendance.presentes,2);
assert.equal(attendance.esperados,3);

assert.match(coverage,/INSERT INTO sesion_asistencia_cobertura/);
assert.match(coverage,/complete=VALUES\(complete\)/);
assert.match(sessionsApi,/asistencia-cobertura\.php/);
assert.match(sessionsApi,/alumnosSesion\(\$pdo,\$sesion/);
assert.match(sessionsApi,/hache_asistencia_cobertura_guardar\(\$pdo,\$sid,\$esperados,\$marcados,\$uid\)/);
assert.match(studentsApi,/registrar_cambio_estado_alumno/);
assert.match(studentsApi,/'BAJA','ALUMNO_BAJA'/);
assert.match(studentsApi,/'REACTIVACION','ALUMNO_REACTIVACION'/);
assert.match(studentsApi,/\(string\)\$alumno\['estado_administrativo'\]!=='BAJA'/);
assert.match(api,/dashboard-p06\.php/);
assert.match(api,/dashboard_nuevos_alumnos/);
assert.match(api,/dashboard_bajas_registradas/);
assert.match(api,/dashboard_asistencia_periodo/);
assert.match(api,/'nuevos_alumnos'=>\$nuevosAlumnos/);
assert.match(api,/'bajas_registradas'=>\$bajasRegistradas/);
assert.match(api,/'asistencia_periodo'=>\$asistenciaPeriodo/);
assert.match(page,/Nuevos alumnos/);
assert.match(page,/Bajas registradas/);
assert.match(page,/Asistencia del periodo/);
assert.match(page,/id="nuevos-alumnos"/);
assert.match(page,/id="bajas-registradas"/);
assert.match(page,/id="asistencia-periodo"/);

assert.match(migration,/CREATE TABLE IF NOT EXISTS sesion_asistencia_cobertura/);
assert.match(migration,/dashboard_bajas_cobertura_desde/);
assert.match(migration,/dashboard_asistencia_cobertura_desde/);
assert.match(migrationRunner,/F6_DASHBOARD_METRICS_MIGRATION_OK/);
assert.match(deploy,/migrate-f6-dashboard-metrics\.php/);

console.log('dashboard P-06 lifecycle and attendance regression: OK');
