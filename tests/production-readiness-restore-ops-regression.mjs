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

ok(helper.includes('BACKUP_MAX_COMPLETE="${HACHE_BACKUP_MAX_COMPLETE:-20}"'), 'backup retention must have a finite operational safety ceiling');
ok(helper.includes('prune_incomplete_backups()') && helper.includes('prune_complete_backups()'), 'helper must prune failed and excess complete backup directories');
ok(helper.includes('rm -rf -- "$dir"'), 'backup pruning must actually remove selected backup directories');
ok(helper.includes('flock -x 9'), 'backup, deploy and restore operations must serialize while pruning can occur');
ok(helper.indexOf(': > "$destination/BACKUP_COMPLETE"') < helper.indexOf('prune_complete_backups', helper.indexOf('create_complete_backup()')), 'complete backup must be marked before complete-backup pruning runs');

const deployStart = helper.indexOf('deploy_sha()');
const backupCall = helper.indexOf('create_complete_backup', deployStart);
const mergeCall = helper.indexOf('git merge --ff-only origin/main', deployStart);
ok(deployStart >= 0 && backupCall > deployStart && mergeCall > backupCall, 'a complete DB backup must happen before the production fast-forward');

ok(helper.includes('DEPLOY_SHA_FILE="${REPO}/.hache-deployed-sha"'), 'deploy helper must publish a web-readable authoritative SHA marker');
ok(helper.includes('publish_deployed_sha()'), 'deploy helper must own marker publication');
ok(helper.includes('chmod 644 "$temp"'), 'deployed SHA marker must be readable by PHP/FPM without opening .git permissions');
ok(helper.includes('chown root:root "$DEPLOY_SHA_FILE"'), 'deployed SHA marker must remain root-owned');
const alreadyCurrent = helper.indexOf('if [[ "$local_sha" == "$remote" ]]');
const alreadyMarker = helper.indexOf('publish_deployed_sha "$local_sha"', alreadyCurrent);
const deployMarker = helper.indexOf('publish_deployed_sha "$deployed"', mergeCall);
ok(alreadyCurrent >= 0 && alreadyMarker > alreadyCurrent, 'already-current deploy path must repair/publish the SHA marker');
ok(mergeCall >= 0 && deployMarker > mergeCall, 'successful fast-forward must publish the verified deployed SHA');

ok(helper.includes('restore-drill <rpo_seconds> <rto_seconds> <run_id>'), 'restore drill must require explicit RPO/RTO inputs');
ok(!helper.includes('RPO_SECONDS="${RPO_SECONDS:-86400}"') && !helper.includes('RTO_SECONDS="${RTO_SECONDS:-3600}"'), 'project helper must not invent recovery objectives');
ok(helper.includes('RESTORE_TARGET="hache_restore_${run_id}"'), 'restore target must be unique to the evidence run');
ok(helper.includes('[[ "$RESTORE_TARGET" != "$source_db" ]]'), 'restore must reject the production source DB as target');
ok(helper.includes('target_preexisting'), 'restore must refuse a preexisting target');
ok(helper.includes('DROP DATABASE IF EXISTS'), 'isolated restore target must be cleaned up');
ok(helper.includes("'pagos','mensualidades','inscripciones','cursos_intensivos','curso_intensivo_alumnos','cierres_mensuales','auditoria_eventos','sharky_outbox'"), 'restore must verify the Level C critical table set');
ok(helper.includes("'trg_un_pago_valido_insert','trg_un_pago_valido_update'"), 'restore must verify financial validity triggers');
ok(helper.includes("COLUMN_NAME='folio' AND NON_UNIQUE=0"), 'restore must verify the unique payment folio guard');

ok(helper.includes('RESTORE_BACKUP_SELECTED="false"') && helper.includes('RESTORE_PRODUCTION_BACKUP_USED="false"'), 'restore evidence state must start false');
ok(helper.includes('"production_backup_selected"=>$bool("BACKUP_SELECTED")'), 'report must derive backup selection from runtime state');
ok(helper.includes('"production_backup_used"=>$bool("PRODUCTION_BACKUP_USED")'), 'report must derive backup usage from runtime state rather than hard-code true');
const importCall = helper.indexOf('mariadb --protocol=socket --user=root "$RESTORE_TARGET" < "$backup_dir/database.sql"');
const usedTrue = helper.indexOf('RESTORE_PRODUCTION_BACKUP_USED="true"');
ok(importCall >= 0 && usedTrue > importCall, 'production_backup_used may become true only after the real backup imports successfully');

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
