import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const wrapper=readFileSync('ops/production-readiness/deploy-hache-natacion-wrapper','utf8');
const workflow=readFileSync('.github/workflows/provision-salad-monitor-secret.yml','utf8');

for(const fragment of ['set_salad_monitor_secret()', 'salad-monitor-secret-set)', 'SALAD_MONITOR_SECRET_CONFIGURED', 'install -o root -g www-data -m 0640', '[[ ! -L "$target" ]]'])assert.ok(wrapper.includes(fragment),`missing secret-installation control: ${fragment}`);
assert.ok(!wrapper.includes('echo "$secret"'),'helper must never print the Salad secret');
for(const fragment of ['workflow_dispatch:', 'SALAD_API_KEY: ${{ secrets.SALAD_API_KEY }}', 'printf \'%s\' "$SALAD_API_KEY" | ssh', 'salad-monitor-secret-set'])assert.ok(workflow.includes(fragment),`missing provisioning workflow control: ${fragment}`);
assert.ok(!workflow.includes('echo "$SALAD_API_KEY"'),'workflow must never print the Salad secret');
console.log('SALAD_SECRET_PROVISIONING_REGRESSION_OK');
