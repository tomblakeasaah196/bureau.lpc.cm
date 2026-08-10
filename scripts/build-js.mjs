#!/usr/bin/env node
/*
 * scripts/build-js.mjs
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — bundles the app-wide "core" JS into ONE minified file.
 *
 * Why one file, not twelve.
 * -------------------------
 * head_assets.php used to emit 12 separate <script> tags for the app-wide JS.
 * Under HTTP/2 that is not the parse-cost disaster it would be on HTTP/1.1,
 * but each file still costs its own TLS-verified fetch and its own header
 * block, and the parser walks them one-by-one anyway because they are all
 * `defer` (order matters — LPC.fmt must exist before lpc-kpi.js runs).
 *
 * Concatenating them shaves ~150ms of head-of-line latency on cold visits and
 * makes the DevTools waterfall legible. The individual sources remain the
 * source of truth: this script just writes a build artefact from them.
 *
 * Output.
 * -------
 *   assets/js/build/lpc-core.min.js  — the bundle
 *   assets/js/build/lpc-core.manifest.json  — { hash, size, files, builtAt }
 *
 * head_assets.php checks for the bundle at request time and, if present AND
 * newer than every source file, emits a single <script> tag for it instead of
 * the twelve individuals. If the bundle is missing OR stale (someone edited
 * a source and forgot to rebuild), it falls back to the individual tags — no
 * broken deploys.
 *
 * Run this after editing any of the source files:
 *   npm run build:js
 *
 * Or via `npm run build`, which chains it after the Tailwind build.
 * -----------------------------------------------------------------------------
 */

import { readFile, writeFile, mkdir, stat } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative } from 'node:path';
import * as esbuild from 'esbuild';

const HERE      = dirname(fileURLToPath(import.meta.url));
const REPO      = join(HERE, '..');
const SRC_DIR   = join(REPO, 'assets/js');
const OUT_DIR   = join(REPO, 'assets/js/build');
const OUT_FILE  = join(OUT_DIR, 'lpc-core.min.js');
const MANIFEST  = join(OUT_DIR, 'lpc-core.manifest.json');

// The order matters — see the comments in head_assets.php. LPC.* modules
// register namespaces that later modules read at IIFE time. Any change here
// must be mirrored in head_assets.php's fallback list.
const SOURCES = [
  'lpc-dom.js',
  'lpc-i18n.js',
  'lpc-modal.js',
  'lpc-toast.js',
  'lpc-a11y.js',
  'lpc-session-lock.js',
  'lpc-product-picker.js',
  'lpc-deeplink.js',
  'lpc-paginator.js',
  'lpc-kpi.js',
  'lpc-chart-theme.js',
  'lpc-turbo-init.js',
];

async function main() {
  await mkdir(OUT_DIR, { recursive: true });

  // Read every source and stitch them with a banner comment so a browser
  // "view source" is still navigable. Wrap each file in its own IIFE-like
  // fence: because every source already IIFEs itself, the concatenation is
  // literally string-safe — no minifier trickery required.
  const parts = [];
  const sourceMeta = [];
  for (const name of SOURCES) {
    const p = join(SRC_DIR, name);
    const body = await readFile(p, 'utf8');
    const st   = await stat(p);
    parts.push(
      `/* ==== ${name} (${st.size} bytes, mtime ${st.mtime.toISOString()}) ==== */`,
      body.replace(/\/\/# sourceMappingURL=.*$/gm, ''),
      ''
    );
    sourceMeta.push({ file: name, size: st.size, mtime: st.mtimeMs });
  }
  const stitched = parts.join('\n');

  // Minify with esbuild — ~10x faster than terser and identical output for
  // this style of code.
  const result = await esbuild.transform(stitched, {
    minify: true,
    target: 'es2017',
    legalComments: 'none',
    // Keep function names so LPC.foo shows up in Chrome DevTools' function
    // listing rather than as `_0x0a`.
    keepNames: true,
  });

  await writeFile(OUT_FILE, result.code, 'utf8');

  const hash = createHash('sha384').update(result.code).digest('base64');
  const manifest = {
    hash: 'sha384-' + hash,
    size: Buffer.byteLength(result.code, 'utf8'),
    files: sourceMeta,
    builtAt: new Date().toISOString(),
  };
  await writeFile(MANIFEST, JSON.stringify(manifest, null, 2), 'utf8');

  const totalIn = sourceMeta.reduce((n, s) => n + s.size, 0);
  const pct = ((manifest.size / totalIn) * 100).toFixed(1);
  console.log(
    `[build-js] wrote ${relative(REPO, OUT_FILE)}  ` +
    `${manifest.size} bytes  ` +
    `(${pct}% of ${totalIn} raw)`
  );
}

main().catch(err => {
  console.error('[build-js] failed:', err);
  process.exit(1);
});
