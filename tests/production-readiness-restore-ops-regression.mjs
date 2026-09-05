import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

function ok(condition, message) {
  if (!condition) {
    console.error(`PRODUCTION_RESTORE_OPS_FAIL: ${message}`);
    process.exit(1);
  }
}

const helperPath = 'ops/production-readiness/deploy-hache-natacion';
const workflowPath = '.github/workflows/production-restore-drill.yml';
const helper = fs.readFileSync(helperPath, 'utf8');
const workflow = fs.readFileSync(workflowPath, 'utf8');

execFileSync('bash', ['-n', helperPath], { stdio: 'inherit' });

ok(helper.includes('BACKUP_ROOT="/var/backups/hache-natacion"'), 'backup root must remain explicit and protected on the production host');
ok(helper.includes('backup_status=complete'), 'backup manifest must mark completion');
ok(helper.includes('sha256sum commit.txt database.sql > SHA256SUMS'), 'backup must checksum commit and database dump');
ok(helper.includes(': > "$destination/BACKUP_COMPLETE"'), 'BACKUP_COMPLETE must be created explicitly');
ok(helper.indexOf('sha256sum commit.txt database.sql > SHA256SUMS') < helper.indexOf(': > "$destination/BACKUP_COMPLETE"'), 'completion marker must be created only after checksums');
ok(helper.includes('--single-transaction') && helper.includes('--routines --triggers --events'), 'real MariaDB backup must use the Hache Base consistency contract');

const deployStart = helper.indexOf('deploy_sha()');
const backupCall = helper.indexOf('create_complete_backup', deployStart);
const mergeCall = helper.indexOf('git merge --ff-only origin/main', deployStart);
ok(deployStart >= 0 && backupCall > deployStart && mergeCall > backupCall, 'a complete DB backup must happen before the production fast-forward');

ok(helper.includes('restore-drill <rpo_seconds> <rto_seconds> <run_id>'), 'restore drill must require explicit RPO/RTO inputs');
ok(!helper.includes('RPO_SECONDS="${RPO_SECONDS:-86400}"') && !helper.includes('RTO_SECONDS="${RTO_SECONDS:-3600}"'), 'project helper must not invent recovery objectives');
ok(helper.includes('RESTORE_TARGET="hache_restore_${run_id}"'), 'restore target must be unique to the evidence run');
ok(helper.includes('[[ "$RESTORE_TARGET" != "$source_db" ]]'), 'restore must reject the production source DB as target');
ok(helper.includes('target_preexisting'), 'restore must refuse a preexisting target');
ok(helper.includes('DROP DATABASE IF EXISTS'), 'isolated restore target must be cleaned up');
ok(helper.includes("'pagos','mensualidades','inscripciones','cursos_intensivos','curso_intensivo_alumnos','cierres_mensuales','auditoria_eventos','sharky_outbox'"), 'restore must verify the Level C critical table set');
ok(helper.includes("'trg_un_pago_valido_insert','trg_un_pago_valido_update'"), 'restore must verify financial validity triggers');
ok(helper.includes("COLUMN_NAME='folio' AND NON_UNIQUE=0"), 'restore must verify the unique payment folio guard');

ok(workflow.includes("github.ref == 'refs/heads/main'"), 'real restore workflow must run only from main');
ok(workflow.includes('rpo_seconds:') && workflow.includes('rto_seconds:'), 'workflow must request both recovery objectives');
ok(!/rpo_seconds:[\s\S]{0,180}\bdefault:/m.test(workflow), 'RPO input must have no default');
ok(!/rto_seconds:[\s\S]{0,180}\bdefault:/m.test(workflow), 'RTO input must have no default');
ok(workflow.includes('sudo /usr/local/sbin/deploy-hache-natacion restore-drill'), 'workflow must cross the root boundary only through the approved helper');
ok(workflow.includes('production_backup_used') && workflow.includes('cleanup_status'), 'workflow must validate real-backup and cleanup evidence');
ok(workflow.includes('path: evidence/restore-drill.json'), 'artifact must contain only minimized restore evidence');
ok(!workflow.includes('database.sql'), 'production database dumps must never enter Actions artifacts');
ok(!workflow.includes('scp '), 'workflow must not copy a production dump off the host');
ok(!fs.existsSync('.github/workflows/ops-restore-preflight-probe.yml'), 'temporary host probe must not ship to main');

console.log('PRODUCTION_RESTORE_OPS_OK');
