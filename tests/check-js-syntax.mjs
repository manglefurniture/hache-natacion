import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function walk(relative, extension) {
  const directory = path.join(root, relative);
  const found = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const full = path.join(directory, entry.name);
    if (entry.isDirectory()) found.push(...walk(path.relative(root, full), extension));
    else if (entry.name.endsWith(extension)) found.push(path.relative(root, full));
  }
  return found;
}

let external = 0;
for (const file of walk('public', '.js').concat(walk('frontend', '.js'))) {
  new vm.Script(fs.readFileSync(path.join(root, file), 'utf8'), { filename: file });
  external += 1;
}

let inline = 0;
for (const file of walk('public', '.php').concat(walk('frontend', '.html'))) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  for (const match of source.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
    const attributes = match[1];
    const code = match[2];
    if (/\bsrc\s*=/i.test(attributes)) continue;
    const type = attributes.match(/\btype\s*=\s*["']([^"']+)["']/i)?.[1]?.toLowerCase();
    if (type && !['text/javascript', 'application/javascript', 'module'].includes(type)) continue;
    if (code.includes('<?')) continue;
    new vm.Script(code, { filename: `${file}#inline-${inline + 1}` });
    inline += 1;
  }
}

assert.ok(external >= 25, `Se esperaban al menos 25 archivos JavaScript; se revisaron ${external}`);
assert.ok(inline >= 20, `Se esperaban al menos 20 scripts inline; se revisaron ${inline}`);
console.log(`✓ sintaxis JavaScript: ${external} archivos y ${inline} bloques inline.`);
