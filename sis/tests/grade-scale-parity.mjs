// Helper for grade-scale-parity.php: runs js/grading-scale.js on the cases given on stdin.
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os'; import { join, dirname } from 'node:path'; import { fileURLToPath, pathToFileURL } from 'node:url';
const src = join(dirname(fileURLToPath(import.meta.url)), '../../js/grading-scale.js');
const copy = join(mkdtempSync(join(tmpdir(), 'gs-')), 'grading-scale.mjs'); writeFileSync(copy, readFileSync(src));
const G = await import(pathToFileURL(copy).href);
const { options, values } = JSON.parse(readFileSync(0, 'utf8'));
const out = options.map((o) => { G.setGradingOptions(o); return values.map((v) => {
  const t = G.toFinal(v);
  return [G.remarksFor(v), G.gwaRemarks(Math.round((v + Number.EPSILON) * 100) / 100), t, t === null ? null : G.finalRemarks(t), G.finalScaleKey(G.gradingOptions())];
}); });
process.stdout.write(JSON.stringify(out));
