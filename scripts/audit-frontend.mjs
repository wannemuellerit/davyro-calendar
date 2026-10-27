import { spawnSync } from 'node:child_process';
import fs from 'node:fs';

const result = spawnSync('npm', ['audit', '--json'], { encoding: 'utf8' });
if (result.error) throw result.error;
const report = JSON.parse(result.stdout);
if (report.error || !report.metadata) throw new Error(JSON.stringify(report.error ?? 'Invalid audit response'));
const manifest = JSON.parse(fs.readFileSync(new URL('../package.json', import.meta.url), 'utf8'));
// Bootstrap 3 styles are still needed by the legacy fallback UI. Its vulnerable
// button/tooltip/popover plugins are never compiled or loaded. Keep this narrow,
// time-limited exception visible rather than ignoring the whole npm audit.
const allowed = new Set(['GHSA-vxmc-5x29-h64v', 'GHSA-q58r-hwc8-rm9j']);
const bootstrapSources = manifest.scripts['prebuild:js'].match(/node_modules\/bootstrap\/\S+/g) ?? [];
const safeSubset = bootstrapSources.length === 1
  && bootstrapSources.includes('node_modules/bootstrap/js/tab.js');
let failed = false;
for (const [name, finding] of Object.entries(report.vulnerabilities ?? {})) {
  const reviewed = name === 'bootstrap' && finding.severity === 'moderate' && safeSubset
    && new Date() < new Date('2026-11-27T00:00:00Z')
    && Array.isArray(finding.via) && finding.via.length > 0
    && finding.via.every(v => typeof v === 'object' && allowed.has(v.url.split('/').at(-1)));
  if (reviewed) console.log('REVIEWED: Bootstrap CSS/tab subset; button/tooltip/popover excluded. Owner: Davyro. Review expires 2026-11-27.');
  else { console.error(`BLOCKED: ${name}: ${finding.severity}`); failed = true; }
}
if (failed) process.exit(1);
console.log('Frontend security gate passed; no unreviewed dependency findings.');
