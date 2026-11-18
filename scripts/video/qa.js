"use strict";
/* global document, window, getComputedStyle */
/**
 * QA before filming (docs/TRAINING_VIDEOS.md §1): a video session is QA, then fixes, then the video.
 * The intro filmed the Tableau de Bord Exécutif without opening its KPI cards, and one card's detail
 * failed on live (18/11/2026). This crawler opens what a video will show the way a person would, on the
 * filming copy, and reports every error it meets, so nothing broken reaches a video again.
 *
 *   node scripts/video/qa.js <who> <page> [<page> ...] [--selects] [--out <dir>]
 *
 *   <who>      a cast key (cast.js), signed in through the real sign-in page
 *   <page>     an app path, e.g. /modules/dashboard/views/md_dashboard.php
 *   --selects  also try every option of each filter <select> outside a form (periods, years, statuses)
 *
 * On each page it clicks every visible tab; on the page and on each tab it opens every KPI card,
 * detail, "Voir" and <summary>, one at a time, and closes it again. It never presses a button that
 * saves, sends, deletes, pays or validates (SKIP below). It records, per click:
 *   · console errors and uncaught exceptions
 *   · failed requests, HTTP 4xx/5xx, and JSON answers saying status "error" or success false
 *   · PHP's own "Fatal error", "Warning:" or "Uncaught" printed in the page
 *   · an error message the page shows in a window, a toast or an alert ("Erreur", "Impossible", ...)
 *   · new lines in the copy's PHP error log (ERROR_LOG_PATH in filming.env)
 * Writes <out>/report.json and a screenshot of each failure; prints a summary. Exit code 1 when anything failed.
 */
const fs = require("node:fs");
const path = require("node:path");
const ground = require("./ground");
const { CAST, emailOf } = require("./cast");
const { browserFor, signIn, settle, localFonts, UA } = require("./film");
const { DESKTOP } = require("./style");

/** What a QA click never presses: anything that writes. Opening a window that holds such a button is fine. */
const SKIP = /supprim|effacer|delete|remove|retirer|annuler la|enregistr|sauvegard|save|valider|validate|confirm|envoyer|send|soumettre|submit|payer|régler|approuv|rejet|refuser|clôtur|générer|importer|import|export|télécharg|download|imprimer|print|réinitialis|reset|vider|purger|archiv|déconnex|sign out|logout|marquer|rouvrir|dupliquer|créer|ajouter|nouveau|nouvelle|\+|émettre|facturer|livrer|expédier|réceptionner|encaisser|comptabiliser|poster|lettrer|verrouiller|déverrouiller|activer|désactiver|transférer|affecter|assigner/i;

/** What reads as an error on screen, in a window, toast or alert. */
const SAYS_ERROR = /\b(erreur|error|impossible|échec|echec|introuvable|not found|exception|refusé|denied|forbidden)\b/i;

function args(argv) {
  const out = { pages: [], selects: false, dir: null, who: null };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === "--selects") out.selects = true;
    else if (a === "--out") out.dir = argv[++i];
    else if (!out.who) out.who = a;
    else out.pages.push(a);
  }
  if (!out.who || !out.pages.length) {
    console.error("Usage: node scripts/video/qa.js <who> <page> [<page> ...] [--selects] [--out <dir>]");
    process.exit(2);
  }
  if (!CAST[out.who]) { console.error(`Unknown cast key "${out.who}" (scripts/video/cast.js).`); process.exit(2); }
  return out;
}

/** The copy's PHP error log, read from where it ended last time. */
function phpLog() {
  const file = (ground.env().ERROR_LOG_PATH || "").trim();
  let at = file && fs.existsSync(file) ? fs.statSync(file).size : 0;
  return () => {
    if (!file || !fs.existsSync(file)) return [];
    const size = fs.statSync(file).size;
    if (size <= at) { at = size; return []; }
    const fd = fs.openSync(file, "r");
    const buf = Buffer.alloc(size - at);
    fs.readSync(fd, buf, 0, buf.length, at);
    fs.closeSync(fd);
    at = size;
    return buf.toString("utf8").split(/\r?\n/).filter((l) => /^\[/.test(l)).map((l) => l.slice(0, 600));
  };
}

/** Mark what can be opened on screen now; returns [{ id, kind, label }]. Runs in the page. */
function markClickables(onlyTabs) {
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width < 4 || r.height < 4) return false;
    const s = getComputedStyle(el);
    return s.visibility !== "hidden" && s.display !== "none" && !el.closest("[hidden], [aria-hidden=true]");
  };
  const inChrome = (el) => !!el.closest("#lpc-sidebar, #lpc-topbar, header[role=banner], .lpc-topbar, #lpc-command-palette, #video-cursor");
  const inWindow = (el) => !!el.closest("[role=dialog], .lpc-modal, [aria-modal=true]");
  const label = (el) => (el.getAttribute("aria-label") || el.innerText || el.title || el.value || "").replace(/\s+/g, " ").trim().slice(0, 90);
  window.__qaN = window.__qaN || 0;
  const out = [];
  const seen = new Set();
  const add = (el, kind) => {
    if (seen.has(el) || !visible(el) || inChrome(el) || inWindow(el)) return;
    if (el.disabled) return;
    seen.add(el);
    if (!el.dataset.qa) el.dataset.qa = String(++window.__qaN);
    out.push({ id: el.dataset.qa, kind, label: label(el) });
  };
  const tabs = "[role=tab], .lpc-tabs a, .lpc-tabs button, [data-tab], button[onclick*='switchTab'], button[onclick*='Tab(']";
  document.querySelectorAll(tabs).forEach((el) => add(el, "tab"));
  if (onlyTabs) return out;
  document.querySelectorAll("summary").forEach((el) => add(el, "summary"));
  const openers = "[data-drill], [data-detail], [data-kpi], [data-modal], [data-open], [aria-haspopup=dialog], .kpi-card, [role=button], button, a[href='#'], a[href^='javascript']";
  document.querySelectorAll(openers).forEach((el) => {
    if (el.matches(tabs) || el.closest("form[method=post i]") || el.type === "submit") return;
    add(el, "open");
  });
  return out;
}

async function closeWindows(page) {
  for (let i = 0; i < 3; i++) {
    const open = await page.evaluate(() => [...document.querySelectorAll("[role=dialog], .lpc-modal, [aria-modal=true], .fixed.inset-0")]
      .some((el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 50 && r.height > 50 && s.display !== "none" && s.visibility !== "hidden" && !el.classList.contains("hidden"); }));
    if (!open) return true;
    await page.keyboard.press("Escape").catch(() => null);
    await settle(page, 200);
    const close = page.locator("[role=dialog] button, .lpc-modal button, [aria-modal=true] button, .fixed.inset-0 button")
      .filter({ hasText: /^\s*(×|✕|fermer|close|annuler|cancel|retour)\s*$/i }).first()
      .or(page.locator("[role=dialog] [aria-label^=Fermer i], .lpc-modal [aria-label^=Fermer i], .fixed.inset-0 [aria-label^=Fermer i], [aria-label=Close]").first());
    if (await close.first().isVisible().catch(() => false)) { await close.first().click({ timeout: 2000 }).catch(() => null); await settle(page, 250); }
  }
  return false;
}

/** What the page says on screen that reads as an error: in a window, a toast, an alert, or PHP's own text. */
async function screenErrors(page) {
  return page.evaluate((src) => {
    const re = new RegExp(src, "i");
    const found = [];
    const body = document.body ? document.body.innerText : "";
    for (const m of body.matchAll(/(Fatal error|Parse error|Warning|Notice|Deprecated|Uncaught)[: ].{0,160}/g)) found.push("php: " + m[0]);
    document.querySelectorAll("[role=alert], [role=status], .toast, .lpc-toast, [role=dialog], .lpc-modal, [aria-modal=true], .text-red-500, .text-red-600, .text-red-700, .bg-red-50")
      .forEach((el) => {
        const r = el.getBoundingClientRect();
        if (r.width < 4 || r.height < 4 || getComputedStyle(el).display === "none") return;
        const t = (el.innerText || "").replace(/\s+/g, " ").trim();
        const line = t.split(/(?<=[.!?])\s/).find((s) => re.test(s));
        if (line && line.length < 400) found.push("screen: " + line.slice(0, 200));
      });
    return [...new Set(found)];
  }, SAYS_ERROR.source);
}

async function main() {
  const opt = args(process.argv.slice(2));
  ground.guard();
  ground.ready([emailOf(opt.who)]);
  const stamp = new Date().toISOString().replace(/[:.]/g, "-").slice(0, 19);
  const outDir = opt.dir || path.join(ground.HOME, "qa", `${stamp}-${opt.who}`);
  fs.mkdirSync(outDir, { recursive: true });

  const browser = await browserFor(ground.ORIGIN, ground.PORT);
  const context = await browser.newContext({ ...DESKTOP, userAgent: UA.desktop, locale: "fr-FR", timezoneId: "Africa/Douala" });
  await localFonts(context);
  const page = await context.newPage();
  const log = phpLog();

  // Everything that goes wrong lands in `bucket` until the current step takes it.
  let bucket = [];
  page.on("console", (m) => { if (m.type() === "error") bucket.push("console: " + m.text().slice(0, 300)); });
  page.on("pageerror", (e) => bucket.push("exception: " + String(e.message || e).slice(0, 300)));
  page.on("requestfailed", (r) => { if (!/fonts\.g|favicon/.test(r.url())) bucket.push(`request failed: ${r.method()} ${r.url().replace(ground.ORIGIN, "")} (${r.failure()?.errorText})`); });
  page.on("response", async (r) => {
    const url = r.url();
    if (!url.startsWith(ground.ORIGIN)) return;
    const where = `${r.request().method()} ${url.replace(ground.ORIGIN, "").slice(0, 160)}`;
    if (r.status() >= 400) { bucket.push(`http ${r.status()}: ${where}`); return; }
    if (!/json/i.test(r.headers()["content-type"] || "")) return;
    try {
      const j = JSON.parse(await r.text());
      if (j && (j.status === "error" || j.success === false || j.ok === false || j.error))
        bucket.push(`json error: ${where} → ${String(j.message || j.error || "").slice(0, 200)}`);
    } catch { /* not JSON after all */ }
  });
  const take = async (what) => {
    await settle(page, 350);
    const found = [...bucket, ...(await screenErrors(page).catch(() => [])), ...log().map((l) => "php log: " + l)];
    bucket = [];
    return { what, errors: [...new Set(found)] };
  };

  const report = { who: opt.who, at: new Date().toISOString(), pages: [] };
  let shots = 0;
  const shoot = async (name) => {
    const file = path.join(outDir, `${String(++shots).padStart(3, "0")}-${name.replace(/[^a-z0-9]+/gi, "-").slice(0, 60)}.png`);
    await page.screenshot({ path: file }).catch(() => null);
    return path.basename(file);
  };

  console.warn(`QA as ${CAST[opt.who].name}: signing in on the copy`);
  await signIn(page, ground.ORIGIN, emailOf(opt.who), ground.PASSWORD);
  bucket = []; log();

  for (const where of opt.pages) {
    const entry = { page: where, steps: [] };
    report.pages.push(entry);
    const open = async () => { await page.goto(ground.ORIGIN + where, { waitUntil: "domcontentloaded" }); await settle(page, 900); };
    await open();
    const first = await take(`open ${where}`);
    if (first.errors.length) first.shot = await shoot(`open-${where}`);
    entry.steps.push(first);
    if (new URL(page.url()).pathname !== new URL(ground.ORIGIN + where).pathname) {
      entry.steps.push({ what: `redirected to ${page.url().replace(ground.ORIGIN, "")}`, errors: ["redirected: the page did not open for this role"] });
      continue;
    }

    // Each view: the page as it opens, then each of its tabs.
    const views = [{ tab: null }];
    for (const t of await page.evaluate(markClickables, true)) views.push({ tab: t });
    const done = new Set();
    for (const view of views) {
      if (view.tab) {
        await open();
        await page.evaluate(markClickables, true);
        const tab = page.locator(`[data-qa="${view.tab.id}"]`);
        const same = await tab.count() ? tab : page.getByText(view.tab.label, { exact: true }).first();
        await same.click({ timeout: 5000 }).catch((e) => { bucket.push("could not click: " + e.message.split("\n")[0]); });
        const st = await take(`tab « ${view.tab.label} »`);
        if (st.errors.length) st.shot = await shoot(`tab-${view.tab.label}`);
        entry.steps.push(st);
      }
      const here = page.url();
      const items = (await page.evaluate(markClickables, false)).filter((c) => c.kind !== "tab");
      for (const item of items) {
        const key = `${view.tab ? view.tab.label : ""}|${item.label}`;
        if (!item.label || done.has(key) || SKIP.test(item.label)) continue;
        done.add(key);
        const loc = page.locator(`[data-qa="${item.id}"]`);
        if (!(await loc.isVisible().catch(() => false))) continue;
        await loc.click({ timeout: 4000 }).catch((e) => { bucket.push("could not click: " + e.message.split("\n")[0]); });
        await settle(page, 700);
        const st = await take(`${view.tab ? `[${view.tab.label}] ` : ""}${item.kind} « ${item.label} »`);
        if (st.errors.length) st.shot = await shoot(`${item.label}`);
        entry.steps.push(st);
        if (page.url() !== here) {
          st.navigated = page.url().replace(ground.ORIGIN, "");
          await page.goto(here, { waitUntil: "domcontentloaded" }).catch(() => null);
          await settle(page, 700);
          if (view.tab) { await page.evaluate(markClickables, true); await page.locator(`[data-qa="${view.tab.id}"]`).click({ timeout: 4000 }).catch(() => null); await settle(page, 500); }
          await page.evaluate(markClickables, false);
          bucket = [];
        } else if (!(await closeWindows(page))) {
          await page.reload({ waitUntil: "domcontentloaded" }).catch(() => null);
          await settle(page, 700);
          await page.evaluate(markClickables, false);
          bucket = [];
        }
      }

      if (opt.selects) {
        const selects = await page.evaluate(() => [...document.querySelectorAll("select")].filter((s) => !s.closest("form[method=post i], [role=dialog], .lpc-modal, #lpc-sidebar") && s.offsetParent)
          .map((s, i) => { s.dataset.qaSel = String(i); return { i, label: (s.getAttribute("aria-label") || s.name || s.id || "").slice(0, 40), values: [...s.options].map((o) => o.value).slice(0, 8) }; }));
        for (const s of selects) {
          for (const v of s.values) {
            await page.locator(`[data-qa-sel="${s.i}"]`).selectOption(v, { timeout: 3000 }).catch(() => null);
            const st = await take(`${view.tab ? `[${view.tab.label}] ` : ""}select ${s.label} = ${v}`);
            if (st.errors.length) { st.shot = await shoot(`select-${s.label}-${v}`); entry.steps.push(st); }
          }
        }
      }
    }
  }

  await browser.close();
  fs.writeFileSync(path.join(outDir, "report.json"), JSON.stringify(report, null, 1));
  let bad = 0;
  for (const p of report.pages) {
    const failed = p.steps.filter((s) => s.errors.length);
    bad += failed.length;
    console.log(`\n${p.page}: ${p.steps.length} checks, ${failed.length} with errors`);
    for (const s of failed) console.log(`  ✗ ${s.what}${s.shot ? `  [${s.shot}]` : ""}\n      ${s.errors.join("\n      ")}`);
  }
  console.log(`\nReport: ${path.join(outDir, "report.json")}`);
  process.exit(bad ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(2); });
