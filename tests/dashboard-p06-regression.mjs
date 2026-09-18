import fs from 'node:fs';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const helper=fs.readFileSync(new URL('../config/dashboard-p06.php',import.meta.url),'utf8');
const coverage=fs.readFileSync(new URL('../config/asistencia-cobertura.php',import.meta.url),'utf8');
const api=fs.readFileSync(new URL('../api/dashboard.php',import.meta.url),'utf8');
const studentsApi=fs.readFileSync(new URL('../api/alumno-gestion.php',import.meta.url),'utf8');
const editStudent=fs.readFileSync(new URL('../public/editar-alumno.php',import.meta.url),'utf8');
const stateEvents=fs.readFileSync(new URL('../config/alumno-estado-eventos.php',import.meta.url),'utf8');
const sessionsApi=fs.readFileSync(new URL('../api/sesiones.php',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../public/dashboard.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../database/migrations/20260917_f6_dashboard_metrics.sql',import.meta.url),'utf8');
const migrationRunner=fs.readFileSync(new URL('../bin/migrate-f6-dashboard-metrics.php',import.meta.url),'utf8');
const opportunities=fs.readFileSync(new URL('../config/sharky-prospect-opportunities.php',import.meta.url),'utf8');
const actionRecovery=fs.readFileSync(new URL('../config/sharky-action-recovery.php',import.meta.url),'utf8');
const orchestratorDb=fs.readFileSync(new URL('../config/sharky-orchestrator-db.php',import.meta.url),'utf8');
const whatsappAdapter=fs.readFileSync(new URL('../config/sharky-whatsapp-adapter.php',import.meta.url),'utf8');
const regularEnrollment=fs.readFileSync(new URL('../config/sharky-regular-enrollment.php',import.meta.url),'utf8');
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
assert.doesNotMatch(helper,/JOIN asistencias/);
assert.match(helper,/c\.present_count/);
assert.match(helper,/function dashboard_p06_period_bounds_utc/);
assert.match(helper,/America\/Cancun/);

const helperPath=fileURLToPath(new URL('../config/dashboard-p06.php',import.meta.url));
const program=`require ${JSON.stringify(helperPath)};
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE configuracion(clave TEXT PRIMARY KEY,valor TEXT)');
$pdo->exec("INSERT INTO configuracion VALUES ('dashboard_bajas_cobertura_desde','2026-09-17 18:00:00'),('dashboard_asistencia_cobertura_desde','2026-09-17 18:00:00'),('dashboard_prospectos_cobertura_desde','2026-09-17 18:00:00')");
$pdo->exec('CREATE TABLE alumnos(id TEXT PRIMARY KEY,sede_id TEXT,nombre TEXT,fecha_inicio TEXT,estado_administrativo TEXT)');
$pdo->exec("INSERT INTO alumnos VALUES ('a1','A','Ana','2026-09-02','ACTIVO'),('a2','A','Beto','2026-08-20','ACTIVO'),('a3','B','Cora','2026-09-05','ACTIVO')");
$pdo->exec('CREATE TABLE auditoria_eventos(id TEXT PRIMARY KEY,accion TEXT,entidad TEXT,entidad_id TEXT,detalle TEXT,created_at TEXT)');
$event=$pdo->prepare('INSERT INTO auditoria_eventos(id,accion,entidad,entidad_id,detalle,created_at) VALUES(?,?,?,?,?,?)');
$event->execute(['e1','ALUMNO_BAJA','alumno','a1',json_encode(['sede_id'=>'A']),'2026-09-17 19:00:00']);
$event->execute(['e2','ALUMNO_BAJA','alumno','a3',json_encode(['sede_id'=>'B']),'2026-09-17 19:05:00']);
$event->execute(['e3','ALUMNO_BAJA','alumno','a2',json_encode(['sede_id'=>'A']),'2026-09-17 17:00:00']);
$pdo->exec('CREATE TABLE horarios(id TEXT PRIMARY KEY,sede_id TEXT,hora_inicio TEXT,hora_fin TEXT)');
$pdo->exec("INSERT INTO horarios VALUES ('h1','A','07:00:00','08:00:00'),('h2','B','07:00:00','08:00:00')");
$pdo->exec('CREATE TABLE sesiones(id TEXT PRIMARY KEY,fecha TEXT,horario_id TEXT,estado TEXT)');
$pdo->exec("INSERT INTO sesiones VALUES ('s1','2026-09-17','h1','REALIZADA'),('s2','2026-09-17','h1','REALIZADA'),('s3','2026-09-17','h1','CANCELADA'),('s4','2026-09-17','h2','REALIZADA')");
$pdo->exec('CREATE TABLE sesion_asistencia_cobertura(sesion_id TEXT PRIMARY KEY,expected_count INTEGER,marked_count INTEGER,present_count INTEGER,justified_count INTEGER,unjustified_count INTEGER,complete INTEGER,captured_at TEXT)');
$pdo->exec("INSERT INTO sesion_asistencia_cobertura VALUES ('s1',3,3,2,1,0,1,'2026-09-17 20:00:00'),('s2',2,1,1,0,0,0,'2026-09-17 21:00:00'),('s3',2,2,2,0,0,1,'2026-09-17 22:00:00'),('s4',1,1,1,0,0,1,'2026-09-17 20:00:00')");
$pdo->exec('CREATE TABLE sharky_prospect_opportunities(id TEXT PRIMARY KEY,entry_source TEXT,sede_clave TEXT,status TEXT,opened_at TEXT,closed_at TEXT)');
$pdo->exec("INSERT INTO sharky_prospect_opportunities VALUES
 ('o0','direct',NULL,'OPEN','2026-09-17 17:59:00',NULL),
 ('o1','direct',NULL,'OPEN','2026-09-17 18:10:00',NULL),
 ('o2','web','MONTEVERDE','CONVERTED','2026-09-17 19:00:00','2026-09-18 01:00:00'),
 ('o3','meta_ad','PALAPAS','EXCLUDED','2026-09-17 20:00:00','2026-09-17 20:05:00'),
 ('o4','meta_ad','PALAPAS','OPEN','2026-09-17 21:00:00',NULL)");
echo json_encode([
 dashboard_nuevos_alumnos($pdo,'A','2026-09-01','2026-09-30'),
 dashboard_bajas_registradas($pdo,'A','2026-09-01','2026-09-30'),
 dashboard_asistencia_periodo($pdo,'A','2026-09-01','2026-09-30'),
 dashboard_prospectos_conversion($pdo,'2026-09-17','2026-09-17')
]);`;
const [newStudents,withdrawals,attendance,prospectConversion]=JSON.parse(execFileSync('php',['-r',program],{encoding:'utf8'}));
assert.equal(newStudents.total,1);
assert.equal(newStudents.rows[0].alumno_id,'a1');
assert.equal(withdrawals.disponible,true);
assert.equal(withdrawals.total,1);
assert.equal(withdrawals.rows[0].evento_id,'e1');
assert.equal(withdrawals.cobertura_desde,'2026-09-17 13:00:00');
assert.equal(withdrawals.rows[0].fecha_hora,'2026-09-17 14:00:00');
assert.equal(attendance.disponible,true);
assert.equal(attendance.porcentaje,66.7);
assert.equal(attendance.sesiones_completas,1);
assert.equal(attendance.sesiones_capturadas,2);
assert.equal(attendance.presentes,2);
assert.equal(attendance.esperados,3);
assert.equal(prospectConversion.disponible,true);
assert.equal(prospectConversion.prospectos,3);
assert.equal(prospectConversion.conversiones,1);
assert.equal(prospectConversion.tasa_conversion,33.3);
assert.equal(prospectConversion.parcial,true);
assert.equal(prospectConversion.rows.length,3);
assert.equal(prospectConversion.rows.some(row=>row.oportunidad_id==='o0'),false);
assert.equal(prospectConversion.rows.some(row=>row.oportunidad_id==='o3'),false);
assert.equal(prospectConversion.por_sede.SIN_SEDE.prospectos,1);
assert.equal(prospectConversion.por_sede.MONTEVERDE.conversiones,1);
assert.equal(prospectConversion.por_sede.PALAPAS.prospectos,1);
assert.equal(prospectConversion.por_fuente.direct.prospectos,1);
assert.equal(prospectConversion.por_fuente.web.conversiones,1);
assert.equal(prospectConversion.por_fuente.meta_ad.prospectos,1);
assert.equal(prospectConversion.por_cohorte['2026-09-17'].prospectos,3);

assert.match(coverage,/INSERT INTO sesion_asistencia_cobertura/);
assert.match(coverage,/complete=VALUES\(complete\)/);
assert.match(coverage,/present_count=VALUES\(present_count\)/);
assert.doesNotMatch(coverage,/catch\(Throwable/);
assert.match(sessionsApi,/asistencia-cobertura\.php/);
assert.match(sessionsApi,/alumnosSesion\(\$pdo,\$sesion/);
assert.match(sessionsApi,/hache_asistencia_cobertura_guardar\(\$pdo,\$sid,\$esperados,\$presentes,\$justificadas,\$injustificadas,\$uid\)/);
assert.match(stateEvents,/function hache_alumno_estado_evento/);
assert.match(stateEvents,/INSERT INTO auditoria_eventos/);
assert.match(stateEvents,/hache_admin_history/);
assert.match(studentsApi,/hache_alumno_estado_evento\(\$pdo,\$me/);
assert.match(studentsApi,/'BAJA','ALUMNO_BAJA'/);
assert.match(studentsApi,/'REACTIVACION','ALUMNO_REACTIVACION'/);
assert.match(studentsApi,/\(string\)\$alumno\['estado_administrativo'\]!=='BAJA'/);
assert.match(editStudent,/estado_administrativo FROM alumnos[\s\S]{0,120}FOR UPDATE/);
assert.match(editStudent,/hache_alumno_estado_evento\(\$pdo,\$admin[\s\S]{0,120}'ALUMNO_BAJA'/);
assert.match(editStudent,/hache_alumno_estado_evento\(\$pdo,\$admin[\s\S]{0,140}'ALUMNO_REACTIVACION'/);
assert.match(api,/dashboard-p06\.php/);
assert.match(api,/dashboard_nuevos_alumnos/);
assert.match(api,/dashboard_bajas_registradas/);
assert.match(api,/dashboard_asistencia_periodo/);
assert.match(api,/dashboard_prospectos_conversion/);
assert.match(api,/Disponible únicamente para ADMIN/);
assert.match(api,/'nuevos_alumnos'=>\$nuevosAlumnos/);
assert.match(api,/'bajas_registradas'=>\$bajasRegistradas/);
assert.match(api,/'asistencia_periodo'=>\$asistenciaPeriodo/);
assert.match(api,/'prospectos_conversion'=>\$prospectosConversion/);
assert.match(page,/Nuevos alumnos/);
assert.match(page,/Bajas registradas/);
assert.match(page,/Asistencia del periodo/);
assert.match(page,/id="nuevos-alumnos"/);
assert.match(page,/id="bajas-registradas"/);
assert.match(page,/id="asistencia-periodo"/);

assert.match(migration,/CREATE TABLE IF NOT EXISTS sesion_asistencia_cobertura/);
assert.match(migration,/dashboard_bajas_cobertura_desde/);
assert.match(migration,/dashboard_asistencia_cobertura_desde/);
assert.match(migration,/present_count INT UNSIGNED/);
assert.match(migration,/marked_count=present_count\+justified_count\+unjustified_count/);
assert.match(migration,/UTC_TIMESTAMP\(\)/);
assert.match(migration,/conversion_action_hash CHAR\(64\) NULL/);
assert.match(migration,/ADD COLUMN IF NOT EXISTS conversion_action_hash/);
assert.match(migration,/dashboard_prospectos_cobertura_desde/);
assert.match(migrationRunner,/conversion_action_hash/);
assert.match(migrationRunner,/dashboard_prospectos_cobertura_desde/);
assert.match(helper,/function dashboard_prospectos_conversion/);
assert.match(helper,/status IN \('OPEN','CONVERTED'\)/);
assert.match(helper,/por_cohorte/);
assert.match(helper,/SIN_SEDE/);
assert.match(helper,/SIN_FUENTE/);
assert.doesNotMatch(helper,/contact_hash/);
assert.match(migrationRunner,/F6_DASHBOARD_METRICS_MIGRATION_OK/);

assert.match(opportunities,/function hache_sharky_prospect_opportunity_link_completed_registration/);
assert.match(opportunities,/status='COMPLETED'/);
assert.match(opportunities,/action_type IN \('register_intensive','register_regular'\)/);
assert.match(opportunities,/WHERE id=:id[\s\S]{0,120}contact_hash=:contact_hash/);
assert.match(opportunities,/conversion_action_hash=:audit/);
assert.match(opportunities,/function hache_sharky_prospect_opportunity_exclude_durable_student/);
assert.match(opportunities,/status='EXCLUDED'/);
assert.match(opportunities,/conversion_action_hash IS NULL/);
assert.match(opportunities,/hache_sharky_prospect_opportunity_state_id\(\$state\)/);
assert.match(opportunities,/durable student exclusion failed; receipt remains pending/);
assert.doesNotMatch(opportunities,/alumno_id/);

assert.match(actionRecovery,/\?string \$f6OpportunityId=null/);
assert.match(actionRecovery,/beginTransaction\(\)/);
assert.match(actionRecovery,/hache_sharky_prospect_opportunity_link_completed_registration/);
assert.match(actionRecovery,/rollBack\(\)/);
assert.match(actionRecovery,/commit\(\)/);
assert.match(orchestratorDb,/f6_opportunity_id/);
assert.match(orchestratorDb,/hache_sharky_action_recovery_finish\([\s\S]{0,300}\$f6OpportunityId/);
assert.match(whatsappAdapter,/\$actionContext\['f6_opportunity_id'\]/);
assert.match(whatsappAdapter,/\$context\['verification'\]\['verified'\]/);
assert.match(whatsappAdapter,/hache_sharky_prospect_opportunity_exclude_durable_student\(\$pdo,\$contactHash,\$state,\$context\['verification'\]\)/);
assert.match(regularEnrollment,/hache_sharky_prospect_opportunity_state_id\(\$state\)/);
assert.match(regularEnrollment,/hache_sharky_prospect_opportunity_link_completed_registration/);

assert.match(deploy,/migrate-f6-dashboard-metrics\.php/);

console.log('dashboard P-06 lifecycle, attendance and conversion-link regression: OK');
