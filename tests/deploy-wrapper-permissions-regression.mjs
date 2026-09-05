import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';

const wrapperPath = 'ops/production-readiness/deploy-hache-natacion-wrapper';
const wrapper = readFileSync(wrapperPath, 'utf8');

execFileSync('bash', ['-n', wrapperPath], {stdio: 'inherit'});

for (const fragment of [
  'umask 077',
  'normalize_tracked_worktree_permissions()',
  'git ls-files -z --stage',
  '100644)',
  'chmod 0644 -- "$REPO/$path"',
  '100755)',
  'chmod 0755 -- "$REPO/$path"',
  '120000|160000)',
  'publish_deployed_sha "$deployed"',
  'sync_wrapper_from_target_if_present "$sha"',
  'git -C "$REPO" show "${sha}:${repo_path}"',
  'bash -n "$candidate"',
  'install -o root -g root -m 0755 "$candidate" "$INSTALLED_HELPER"',
  'WORKTREE_PERMISSIONS_OK sha=$deployed',
]) {
  assert.ok(wrapper.includes(fragment), `missing deploy wrapper contract: ${fragment}`);
}

assert.ok(!wrapper.includes('chmod -R'), 'wrapper must never recursively chmod the production worktree');
assert.ok(!wrapper.includes('find "$REPO"'), 'wrapper must not sweep untracked files; permission repair is Git-index scoped');

const remoteCheck = wrapper.indexOf('[[ "$(git rev-parse origin/main)" == "$sha" ]]');
const wrapperSync = wrapper.indexOf('sync_wrapper_from_target_if_present "$sha"');
const targetExtract = wrapper.indexOf('extract_target_file "$sha" "$IMPLEMENTATION_PATH"');
assert.ok(remoteCheck >= 0 && wrapperSync > remoteCheck && targetExtract > wrapperSync,
  'self-update and target implementation extraction must happen only after exact origin/main SHA validation');

const delegatedRun = wrapper.indexOf('bash "$implementation" "$sha"');
const normalize = wrapper.indexOf('normalize_tracked_worktree_permissions', delegatedRun);
const publish = wrapper.indexOf('publish_deployed_sha "$deployed"', normalize);
assert.ok(delegatedRun >= 0 && normalize > delegatedRun && publish > normalize,
  'successful target deploy must be followed by permission repair before authoritative marker publication');

const currentDelegate = wrapper.indexOf('run_current_implementation()');
const caseBlock = wrapper.indexOf('case "$1" in');
assert.ok(currentDelegate >= 0 && caseBlock > currentDelegate && wrapper.includes('backup|restore-drill)'),
  'backup and restore drill must keep delegating to the current tracked implementation without changing recovery semantics');

console.log('DEPLOY_WRAPPER_PERMISSIONS_OK');
