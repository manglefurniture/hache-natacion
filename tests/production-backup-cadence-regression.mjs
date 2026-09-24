import fs from 'node:fs';

function ok(condition, message) {
  if (!condition) {
    console.error(`PRODUCTION_BACKUP_CADENCE_FAIL: ${message}`);
    process.exit(1);
  }
}

const backup = fs.readFileSync('.github/workflows/production-backup-daily.yml', 'utf8');
const once = fs.readFileSync('.github/workflows/ops-production-restore-evidence-once.yml', 'utf8');
const restore = fs.readFileSync('.github/workflows/production-restore-drill.yml', 'utf8');

ok(backup.includes("cron: '17 9 * * *'"), 'daily backup must remain scheduled once per day at the approved off-peak slot');
ok(backup.includes("sudo /usr/local/sbin/deploy-hache-natacion backup"), 'daily backup must use the protected production helper');
ok(backup.includes("grep -Eq '^BACKUP_OK .*retained_complete_max=20$'"), 'daily backup must require a completed retained backup result');
ok(!backup.includes('database.sql'), 'scheduled workflow must not copy or artifact the production dump');
ok(!backup.includes('actions/upload-artifact'), 'scheduled backup must keep production backup bytes on the VPS');

ok(once.includes("RPO_SECONDS: '86400'"), 'one-shot restore evidence must use owner-approved 24h RPO');
ok(once.includes("RTO_SECONDS: '3600'"), 'one-shot restore evidence must use owner-approved 1h RTO');
ok(once.includes('production_backup_selected') && once.includes('production_backup_used'), 'one-shot restore must verify a real production backup was selected and imported');
ok(once.includes('cleanup_status') && once.includes('critical_tables_verified') && once.includes('financial_guards_verified'), 'one-shot restore must verify cleanup and critical integrity evidence');
ok(!once.includes('database.sql'), 'one-shot restore artifact must never contain production database bytes');
ok(once.includes('path: evidence/restore-drill.json'), 'one-shot restore must upload only minimized JSON evidence');

ok(restore.includes('rpo_seconds:') && restore.includes('rto_seconds:'), 'manual restore drill must remain available for explicit future objectives');
ok(restore.includes('workflow_run:') && restore.includes('workflows: ["Production Backup Daily"]'), 'recurring drill must depend on completion of the daily backup workflow');
ok(restore.includes("github.event.workflow_run.conclusion == 'success'"), 'recurring drill must require successful backup completion');
ok(restore.includes("github.event.workflow_run.event == 'schedule'"), 'recurring drill must only inherit scheduled backup runs, not manual backup runs');
ok(restore.includes("github.event.workflow_run.head_branch == 'main'"), 'recurring drill must only inherit backups from main');
ok(!restore.includes("cron: '17 10 1 * *'"), 'restore must not keep an independent monthly cron that can race the daily backup');
ok(restore.includes('BACKUP_CREATED_AT: ${{ github.event.workflow_run.created_at }}'), 'recurring cadence must be derived from the completed backup run');
ok(restore.includes('date -u -d "$BACKUP_CREATED_AT" +%d') && restore.includes('[[ "$backup_day" == "01" ]]'), 'recurring restore must run only for the scheduled backup created on day 1 UTC');
ok(restore.includes("github.event_name == 'workflow_run' && '86400'"), 'recurring drill must use the owner-approved 24h RPO');
ok(restore.includes("github.event_name == 'workflow_run' && '3600'"), 'recurring drill must use the owner-approved 1h RTO');
ok(restore.includes('GITHUB_EVENT_NAME') && restore.includes('[[ "$RPO_SECONDS" == "86400" ]]') && restore.includes('[[ "$RTO_SECONDS" == "3600" ]]'), 'recurring path must fail closed if approved recovery objectives drift');
ok(restore.includes('name: production-readiness-real-restore-${{ github.run_id }}-${{ github.run_attempt }}'), 'historical artifacts must remain unique across workflow reruns');
ok(restore.includes('retention-days: 90'), 'restore evidence history must retain minimized artifacts for 90 days');
ok(!restore.includes('database.sql'), 'recurring restore evidence must never contain production database bytes');
ok(!restore.includes('scp '), 'recurring restore drill must not copy production database bytes off-host');

console.log('PRODUCTION_BACKUP_CADENCE_OK');
