import fs from 'node:fs';

function ok(condition, message) {
  if (!condition) {
    console.error(`PRODUCTION_BACKUP_CADENCE_FAIL: ${message}`);
    process.exit(1);
  }
}

const backup = fs.readFileSync('.github/workflows/production-backup-daily.yml', 'utf8');
const once = fs.readFileSync('.github/workflows/ops-production-restore-evidence-once.yml', 'utf8');
const manual = fs.readFileSync('.github/workflows/production-restore-drill.yml', 'utf8');

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

ok(manual.includes('rpo_seconds:') && manual.includes('rto_seconds:'), 'manual restore drill remains available for explicit future objectives');

console.log('PRODUCTION_BACKUP_CADENCE_OK');
