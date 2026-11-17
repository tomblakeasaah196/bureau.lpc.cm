"use strict";
/* global document, window */
/**
 * Film a video's shots (docs/TRAINING_VIDEOS.md §4): each desktop shot is signed in off camera, then
 * recorded as full-resolution captures taken back to back, each with its time, while its lines play
 * out. A line's actions start as its narration starts and the next line waits for both, so the
 * picture and the voice stay together however long a screen takes.
 *
 * Writes <out>/shots/<shot>/NNNNN.jpg and <out>/shots/<shot>/take.json:
 *   { start, end, frames: [{ file, t }], lines: [{ id, at, page }] }   (times in seconds since the epoch;
 *   `page` is the app path on screen as the line starts, which the library's search and "?" button use)
 */
const fs = require("node:fs");
const path = require("node:path");
const { launch } = require("./browser");
const { DESKTOP, PHONE } = require("./style");

/** The browser filming presents as: Chrome on a Windows computer, as LPC's office PCs run. */
const UA = Object.freeze({
  desktop: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36",
  phone: "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36",
});

/** The pause between sentences, and before a shot's first line and after its last (so a cut never clips a word). */
const GAP = 0.7;
const LEAD = 0.35;
const TAIL = 0.5;

const sleep = (s) => new Promise((r) => setTimeout(r, Math.max(0, s) * 1000));
/** `promise`, or a rejection after `seconds`: a browser connection that stops answering must not hold a take for ever. */
const within = (promise, seconds, what) =>
  Promise.race([promise, new Promise((_, reject) => setTimeout(() => reject(new Error(`${what} did not answer in ${seconds}s`)), seconds * 1000))]);
const now = () => Date.now() / 1000;

/** The pointer drawn on desktop shots: a white arrow with a dark edge that follows the mouse. */
const CURSOR = `(() => {
  const draw = () => {
    if (document.getElementById("video-cursor")) return;
    const c = document.createElement("div");
    c.id = "video-cursor";
    c.innerHTML = '<svg width="22" height="30" viewBox="0 0 22 30"><path d="M2 2 L2 24 L8 18.5 L12.2 28 L16 26.3 L11.8 17 L19.5 17 Z" fill="#fff" stroke="#111" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    Object.assign(c.style, { position: "fixed", left: "0", top: "0", zIndex: "2147483647", pointerEvents: "none",
      transform: "translate(" + (window.__cursorAt ? window.__cursorAt : "760px, 420px") + ")", filter: "drop-shadow(0 1px 1.5px rgba(0,0,0,.35))" });
    document.documentElement.appendChild(c);
    const follow = (e) => { if (e.clientX || e.clientY) { const at = (e.clientX - 2) + "px," + (e.clientY - 2) + "px"; c.style.transform = "translate(" + at + ")"; try { sessionStorage.setItem("__cursor", at); } catch (_) {} } };
    for (const type of ["mousemove", "dragover", "drag"]) addEventListener(type, follow, true);
  };
  try { window.__cursorAt = sessionStorage.getItem("__cursor"); } catch (_) {}
  if (document.readyState === "loading") addEventListener("DOMContentLoaded", draw); else draw();
})();`;

/** Let a screen finish drawing: the page loaded, and a beat for the data, the charts and the entrance animations. */
async function settle(page, ms = 450) {
  await page.waitForLoadState("domcontentloaded").catch(() => null);
  await page.waitForLoadState("networkidle", { timeout: 3000 }).catch(() => null);
  await sleep(ms / 1000);
}

/** A name as a person reads it: exact, in any case, spaces collapsed. */
const named = (name) => (name instanceof RegExp ? name : new RegExp(`^\\s*${name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&").replace(/\s+/g, "\\s+")}\\s*$`, "i"));

/** What a line's `do`, `before` and `after` receive: the page and the moves a person makes on it. */
function hands(page, { phone, origin }) {
  let at = { x: 760, y: 420 };
  const find = (target) => {
    if (typeof target !== "string" && !(target instanceof RegExp)) return target;
    const opts = { name: named(target) };
    return page.getByRole("button", opts).or(page.getByRole("link", opts)).or(page.getByRole("tab", opts))
      .or(page.getByRole("menuitem", opts)).or(page.getByRole("option", opts)).first();
  };
  const glide = async (loc) => {
    await loc.waitFor({ state: "visible", timeout: 15000 });
    await loc.scrollIntoViewIfNeeded();
    // Measured again after each glide: a menu that opens or closes as the pointer travels moves what it aims at.
    for (let tries = 0; tries < 4; tries++) {
      const box = await loc.boundingBox();
      if (!box) break;
      const to = { x: box.x + box.width / 2, y: box.y + box.height / 2 };
      if (tries && Math.hypot(to.x - at.x, to.y - at.y) < 3) break;
      const steps = tries ? 6 : Math.max(14, Math.round(Math.hypot(to.x - at.x, to.y - at.y) / 16));
      await page.mouse.move(to.x, to.y, { steps });
      at = to;
      await sleep(0.18);
    }
  };
  const s = {
    page,
    phone,
    find,
    /** Open an address in the app (a cut, not a click). */
    go: async (where) => { await page.goto(origin + where); await settle(page, 600); },
    /** Move to and press a button, link, tab, menu item or option by its name, or a Locator. */
    click: async (target) => {
      const loc = find(target);
      if (phone) { await loc.waitFor({ state: "visible", timeout: 15000 }); await loc.scrollIntoViewIfNeeded(); await sleep(0.25); await loc.tap(); }
      else { await glide(loc); await page.mouse.down(); await sleep(0.07); await page.mouse.up(); }
      await settle(page, 380);
    },
    /** Move the pointer to something without pressing it (nothing on a phone). */
    hover: async (target) => { if (!phone) await glide(find(target)); },
    /** Type into a field by its label (or a Locator), at a person's pace. */
    type: async (field, text, { delay = 55, clear = true } = {}) => {
      const loc = typeof field === "string" ? page.getByLabel(field, { exact: true }).first() : field;
      if (phone) await loc.tap(); else { await glide(loc); await loc.click(); }
      if (clear) await loc.fill("");
      await loc.pressSequentially(text, { delay });
    },
    /** Press a key or a chord (Control+K), as a person would. */
    key: async (combo) => { await page.keyboard.press(combo); await sleep(0.25); },
    /** Scroll the page (or the element `inside`, a Locator or a name) by `dy` pixels, smoothly. */
    scroll: async (dy, inside) => {
      if (inside) await glide(find(inside));
      for (let i = 0; i < 14; i++) { await page.mouse.wheel(0, dy / 14); await sleep(0.03); }
      await sleep(0.25);
    },
    wait: sleep,
    settle: (ms) => settle(page, ms),
  };
  return s;
}

/**
 * Chromium for filming. The copy runs on this machine, but every address on screen must read as the
 * real one (bureau.lpc.cm), never localhost: Chromium sends that host to the local server.
 */
async function browserFor(origin, port) {
  const host = new URL(origin);
  return launch({
    args: [
      `--host-resolver-rules=MAP ${host.hostname} 127.0.0.1:${port}`,
      `--unsafely-treat-insecure-origin-as-secure=${host.origin}`,
      "--disable-features=HttpsUpgrades,HttpsFirstBalancedModeAutoEnable",
      "--lang=fr-FR",
    ],
  });
}

/** Answer what opens by itself over a first screen, as a person would before a demonstration. */
async function clearOffers(page) {
  for (let i = 0; i < 4; i++) {
    let any = false;
    for (const name of [/^plus tard$/i, /^pas maintenant$/i, /^ignorer$/i, /^not now$/i, /^later$/i]) {
      const b = page.getByRole("button", { name }).first();
      if (await b.isVisible().catch(() => false)) { await b.click({ timeout: 2000 }).catch(() => null); any = true; await sleep(0.4); }
    }
    if (!any) break;
  }
}

/** Sign in through the real sign-in page, off camera (no "remember this device": the page stays the first-visit one). */
async function signIn(page, origin, email, password) {
  await page.goto(origin + "/");
  await settle(page, 500);
  await page.locator("#email").fill(email);
  await page.locator("#password").fill(password);
  await Promise.all([page.waitForURL((u) => !/\/(index\.php)?$/.test(u.pathname), { timeout: 30000 }), page.locator("#password-form button[type=submit]").click()]);
  await page.locator("#lpc-user-trigger").waitFor({ state: "visible", timeout: 30000 });
  await settle(page, 700);
  await clearOffers(page);
}

const pathOf = (page) => { try { const u = new URL(page.url()); return u.protocol.startsWith("http") ? u.pathname : null; } catch { return null; } };

/**
 * Film one shot. `shot` is { kind: "desktop"|"phone", as, start, setup, ground, lines, theme }, each line
 * { id, duration, before, do, after, hold }. A shot with no `as` is filmed signed out (a sign-in shown on
 * camera). `ground` runs before the sign-in, `setup` after it.
 */
async function filmShot(browser, shot, dir, { origin, password, emailOf }) {
  const phone = shot.kind === "phone";
  const context = await browser.newContext({
    ...(phone ? { viewport: PHONE.viewport, deviceScaleFactor: PHONE.deviceScaleFactor, isMobile: true, hasTouch: true }
      : { viewport: DESKTOP.viewport, deviceScaleFactor: DESKTOP.deviceScaleFactor }),
    userAgent: phone ? UA.phone : UA.desktop,
    locale: "fr-FR",
    timezoneId: "Africa/Douala",
    colorScheme: shot.theme === "dark" ? "dark" : "light",
    acceptDownloads: true,
    serviceWorkers: "block",
    ...(shot.still ? { reducedMotion: "reduce" } : {}),
  });
  if (!phone) await context.addInitScript(CURSOR);
  // No red underline under words the dictionary lacks as a person types.
  await context.addInitScript(() => document.addEventListener("DOMContentLoaded", () => document.documentElement.setAttribute("spellcheck", "false")));
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  if (shot.ground) await shot.ground();
  if (shot.as) await signIn(page, origin, emailOf(shot.as), password);
  if (shot.start) { await page.goto(origin + shot.start); await settle(page, 800); }
  if (shot.as) await clearOffers(page);

  const s = hands(page, { phone, origin });
  s.password = password;
  s.emailOf = emailOf;
  s.clearOffers = () => clearOffers(page);
  if (shot.setup) await shot.setup(s);
  await sleep(0.6);

  fs.rmSync(dir, { recursive: true, force: true });
  fs.mkdirSync(dir, { recursive: true });
  const take = { start: 0, end: 0, frames: [], lines: [] };
  // Full-resolution captures, one after another: Chromium's screencast sends frames at CSS size and
  // ignores the device scale, which would leave the text soft.
  let cdp = await context.newCDPSession(page);
  const vp = phone ? PHONE.viewport : DESKTOP.viewport;
  const scale = phone ? PHONE.deviceScaleFactor : DESKTOP.deviceScaleFactor;
  let n = 0;
  let rolling = true;
  let target = page;
  page.on("framenavigated", async (f) => { if (f === page.mainFrame()) cdp = await context.newCDPSession(page).catch(() => cdp); });
  const camera = (async () => {
    while (rolling) {
      const t0 = now();
      const img = await within((async () => {
        const view = await cdp.send("Page.getLayoutMetrics").then((m) => m.cssVisualViewport, () => ({ pageX: 0, pageY: 0 }));
        return cdp.send("Page.captureScreenshot", {
          format: "jpeg", quality: 92, optimizeForSpeed: true,
          clip: { x: view.pageX, y: view.pageY, width: vp.width, height: vp.height, scale },
        });
      })(), 6, "the camera").catch(async () => {
        cdp = await context.newCDPSession(target).catch(() => cdp);
        return null;
      });
      if (!img) { await sleep(0.03); continue; }
      const file = String(++n).padStart(5, "0") + ".jpg";
      fs.writeFileSync(path.join(dir, file), Buffer.from(img.data, "base64"));
      take.frames.push({ file, t: (t0 + now()) / 2 });
    }
  })();
  take.start = now();
  try {
    await sleep(LEAD);
    for (const [i, line] of shot.lines.entries()) {
      if (line.before) await line.before(s);
      const at = now();
      take.lines.push({ id: line.id, at, page: pathOf(page) });
      await Promise.all([sleep(line.duration), line.do ? line.do(s) : null]);
      if (line.after) await line.after(s);
      const spoken = now() - at;
      await sleep((line.hold || 0) + (i < shot.lines.length - 1 ? GAP : TAIL) - Math.max(0, spoken - line.duration));
    }
  } finally {
    take.end = now();
    rolling = false;
    await camera;
  }
  fs.writeFileSync(path.join(dir, "take.json"), JSON.stringify(take, null, 1));
  await context.close();
  return take;
}

/** Film every shot of `plan` that is not a card, or only those in `only` (a Set of shot ids). */
async function film(plan, out, opts) {
  const browser = await browserFor(opts.origin, opts.port);
  try {
    for (const shot of plan.shots) {
      if (shot.kind === "card" || (opts.only && !opts.only.has(shot.id))) continue;
      process.stdout.write(`filming ${shot.id} (${shot.kind}, ${shot.lines.length} lines) ... `);
      // A shot takes about as long as its narration; far longer means it is stuck, and the run stops there.
      const limit = shot.lines.reduce((t, l) => t + l.duration + 2, 0) * 1.6 + 120;
      const take = await within(filmShot(browser, shot, path.join(out, "shots", shot.id), opts), limit, `shot ${shot.id}`);
      const secs = take.end - take.start;
      console.warn(`${secs.toFixed(1)}s, ${take.frames.length} frames (${(take.frames.length / secs).toFixed(1)} a second)`);
    }
  } finally {
    await browser.close();
  }
}

module.exports = { film, settle, clearOffers, signIn, named, browserFor, GAP, LEAD, TAIL, UA };
