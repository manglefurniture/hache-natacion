import fs from 'node:fs';
import path from 'node:path';

function expect(condition, message) {
  if (!condition) {
    console.error(`HACHE_BASE_ADOPTION_REGRESSION_FAIL: ${message}`);
    process.exit(1);
  }
}

const workflowDirectory = '.github/workflows';
const workflowFiles = fs.readdirSync(workflowDirectory).filter((file) => /\.ya?ml$/i.test(file));
const expectedActionRefs = {
  'actions/checkout': '11d5960a326750d5838078e36cf38b85af677262',
  'actions/setup-node': '49933ea5288caeca8642d1e84afbd3f7d6820020',
  'actions/upload-artifact': 'ea165f8d65b6e75b540449e92b4886f43607fa02',
  'shivammathur/setup-php': 'f3e473d116dcccaddc5834248c87452386958240',
};
const requiredMariaDb = 'mariadb:11.8@sha256:2439dcd7d14010ecd1ff7a4e1c5abe8e208c34fe35290744deeeaac3569043c3';
let usesCount = 0;

for (const file of workflowFiles) {
  const source = fs.readFileSync(path.join(workflowDirectory, file), 'utf8');
  for (const match of source.matchAll(/^\s*uses:\s*([^\s#]+)\s*(?:#.*)?$/gm)) {
    usesCount += 1;
    const reference = match[1];
    expect(/@[0-9a-f]{40}$/i.test(reference), `${file} must pin ${reference} to a full immutable commit SHA`);
    const [action, sha] = reference.split('@');
    expect(expectedActionRefs[action] === sha, `${file} must use the approved immutable reference for ${action}`);
  }
  for (const image of source.matchAll(/^\s*image:\s*([^\s#]+)\s*(?:#.*)?$/gm)) {
    expect(image[1] === requiredMariaDb, `${file} must pin its MariaDB service image by digest`);
  }
}

expect(usesCount > 0, 'The workflow inventory must include external actions.');
console.log('HACHE_BASE_ADOPTION_REGRESSION_OK');
