import { spawnSync, spawn } from 'node:child_process';
import { statSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/*
 * Staleness-aware start for the E2E web server.
 *
 * vite-plugin-pwa's closeBundle alone costs ~25s, and Playwright starts the
 * webServer before every run. Rebuilding unconditionally puts that 25s on every
 * iteration even when nothing in the source changed.
 *
 * Skipping the build blindly is worse: that is how the suite ended up testing a
 * previous run's bundle, which showed up as submit.php rejecting valid
 * signatures rather than as anything obviously stale. So the build is skipped
 * only after proving dist/ is strictly newer than every source file it is built
 * from — the guard exists precisely so the optimisation cannot reintroduce the
 * bug.
 */
const ROOT = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const VITE = path.join(ROOT, 'node_modules', 'vite', 'bin', 'vite.js');
const SERVER = path.join(ROOT, 'private_storage', 'bin', 'e2e_server.php');
const OUT = path.join(ROOT, 'dist', 'index.html');

const WATCHED = ['src', 'index.html', 'vite.config.ts', 'tsconfig.json'];

function newestMtime() {
  let newest = 0;
  const walk = (dir) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const p = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        walk(p);
        continue;
      }
      try {
        const m = statSync(p).mtimeMs;
        if (m > newest) newest = m;
      } catch {
        // A file removed between readdir and stat: irrelevant to the comparison.
      }
    }
  };

  for (const rel of WATCHED) {
    const p = path.join(ROOT, rel);
    try {
      if (statSync(p).isDirectory()) {
        walk(p);
      } else {
        const m = statSync(p).mtimeMs;
        if (m > newest) newest = m;
      }
    } catch {
      // Optional input (e.g. no tsconfig.json): skip.
    }
  }
  return newest;
}

let fresh = false;
try {
  fresh = statSync(OUT).mtimeMs > newestMtime();
} catch {
  fresh = false; // no dist yet
}

if (fresh) {
  console.log('[e2e] dist is up to date with the source tree, skipping build');
} else {
  console.log('[e2e] building...');
  const build = spawnSync(process.execPath, [VITE, 'build'], { cwd: ROOT, stdio: 'inherit' });
  if (build.status !== 0) process.exit(build.status ?? 1);
}

const php = process.env.FIELDPULSE_PHP ?? 'php';
const child = spawn(php, [SERVER, '--port=8791'], { cwd: ROOT, stdio: 'inherit' });

const forward = (sig) => () => {
  child.kill(sig);
  process.exit(0);
};
for (const sig of ['SIGINT', 'SIGTERM']) process.on(sig, forward(sig));

child.on('exit', (code) => process.exit(code ?? 0));
