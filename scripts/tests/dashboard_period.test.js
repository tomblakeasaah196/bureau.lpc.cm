/**
 * scripts/tests/dashboard_period.test.js
 * -----------------------------------------------------------------------------
 * The dashboards' period dates are the local calendar dates (VIDEO_QA_FINDINGS
 * 14.7). lpc-kpi.js formatted them with toISOString(), the UTC date: in
 * Cameroon (UTC+1) local midnight is 23:00 the day before, so every period
 * ended yesterday and today's sales never showed. Runs the page's own iso()
 * under Africa/Douala.
 *
 * Run:  node scripts/tests/dashboard_period.test.js
 * -----------------------------------------------------------------------------
 */
process.env.TZ = "Africa/Douala";
const fs = require("node:fs");
const path = require("node:path");

const src = fs.readFileSync(path.join(__dirname, "..", "..", "assets", "js", "lpc-kpi.js"), "utf8");
const m = src.match(/function iso\(d\) \{[\s\S]*?\n    \}/) || src.match(/function iso\(d\) \{[^\n]*\}/);
let fail = 0;
const check = (label, ok) => { console.log(`  ${ok ? "PASS" : "FAIL"}  ${label}`); if (!ok) fail++; };

check("lpc-kpi.js has an iso() helper", !!m);
if (m) {
  const iso = new Function(m[0] + "; return iso;")();
  const jan1 = new Date(2026, 0, 1); jan1.setHours(0, 0, 0, 0);
  const today = new Date(2026, 10, 18); today.setHours(0, 0, 0, 0);
  const late = new Date(2026, 10, 18, 23, 59);
  check("1 January at local midnight is 2026-01-01", iso(jan1) === "2026-01-01");
  check("18 November at local midnight is 2026-11-18 (it was 2026-11-17)", iso(today) === "2026-11-18");
  check("18 November at 23:59 is still 2026-11-18", iso(late) === "2026-11-18");
  check("no toISOString in the helper", !/toISOString/.test(m[0]));
}
console.log(fail ? `\n${fail} check(s) failed.` : "\nAll checks passed.");
process.exit(fail ? 1 : 0);
