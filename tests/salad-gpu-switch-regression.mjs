import { test } from 'node:test';
import assert from 'node:assert/strict';
import { planSwitch, GROUP } from '../ops/salad-gpu-switch.mjs';
const oldId = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
const newId = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
const classes = { items: [
  { id: oldId, name: 'RTX 4070 Ti Super (16 GB)' },
  { id: newId, name: 'RTX 4090 (24 GB)' },
  { id: 'cccccccc-cccc-4ccc-cccc-cccccccccccc', name: 'RTX 4090 Laptop (16 GB)' },
]};
const group = () => ({
  name: GROUP, priority: 'low', replicas: 1, pending_change: false,
  container: { image: 'ghcr.io/example/miner:latest', resources: {gpu_classes: [oldId]} },
});
test('only GPU list changes, laptop excluded', () => {
  const p = planSwitch(group(), classes);
  assert.deepEqual(p.patch, {container: {resources: {gpu_classes: [newId]}}});
  assert.equal(p.oldClass, 'RTX 4070 Ti Super (16 GB)');
  assert.equal(p.newClass, 'RTX 4090 (24 GB)');
});
test('idempotent when already on desktop 4090', () => {
  const g = group(); g.container.resources.gpu_classes = [newId];
  assert.equal(planSwitch(g, classes).already, true);
});
test('never touch another group', () => {
  const g = group(); g.name = 'prl-low-profitable-01';
  assert.throws(() => planSwitch(g, classes), /Different container group/);
});
test('never change non-Low priority', () => {
  const g = group(); g.priority = 'medium';
  assert.throws(() => planSwitch(g, classes), /Priority/);
});
test('never change a group with pending change', () => {
  const g = group(); g.pending_change = true;
  assert.throws(() => planSwitch(g, classes), /Pending/);
});
test('never change multiple replicas', () => {
  const g = group(); g.replicas = 2;
  assert.throws(() => planSwitch(g, classes), /replica/);
});
test('never change unexpected original GPU', () => {
  const g = group(); g.container.resources.gpu_classes = ['dddddddd-dddd-4ddd-dddd-dddddddddddd'];
  assert.throws(() => planSwitch(g, classes), /not a verified RTX 4070/);
});
test('never send multiple GPU classes', () => {
  const g = group(); g.container.resources.gpu_classes = [oldId, newId];
  assert.throws(() => planSwitch(g, classes), /exactly one/);
});
