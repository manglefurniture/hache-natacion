import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const wrapper=readFileSync('ops/production-readiness/deploy-hache-natacion-wrapper','utf8');
const workflow=readFileSync('.github/workflows/provision-salad-ntfy-topic.yml','utf8');
const poller=readFileSync('bin/salad-monitor-poll.php','utf8');
for(const fragment of ['set_salad_ntfy_topic()', 'salad-ntfy-topic-set)', 'SALAD_NTFY_TOPIC_CONFIGURED', 'install -o root -g www-data -m 0640', '[[ ! -L "$target" ]]', 'enable_salad_monitor_timer()', 'systemctl enable --now hache-salad-monitor.timer', 'run_salad_monitor_simulation()', 'salad-monitor-simulate)', 'runuser -u www-data -- /usr/bin/php', '--simulate=$mode'])assert.ok(wrapper.includes(fragment),`missing ntfy provisioning control: ${fragment}`);
assert.ok(!wrapper.includes('echo "$topic"'),'helper must never print the ntfy topic');
for(const fragment of ['workflow_dispatch:', 'NTFY_TOPIC: ${{ secrets.NTFY_TOPIC }}', "printf '%s' \"$NTFY_TOPIC\" | ssh", 'salad-ntfy-topic-set'])assert.ok(workflow.includes(fragment),`missing ntfy workflow control: ${fragment}`);
assert.ok(!workflow.includes('echo "$NTFY_TOPIC"'),'workflow must never print the ntfy topic');
for(const fragment of ["'--simulate=yellow'", "'--simulate=green'", 'salad-monitor-alert-simulation-state.json', "'group'=>'salad-ntfy-simulation'", "$simulation===''?hache_salad_monitor_collect()"] )assert.ok(poller.includes(fragment),`missing isolated simulation control: ${fragment}`);
console.log('SALAD_NTFY_PROVISIONING_REGRESSION_OK');
