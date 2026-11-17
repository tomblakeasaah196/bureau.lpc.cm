"use strict";
/**
 * Moves every scene shares (docs/TRAINING_VIDEOS.md §4.3): finding a window, a field, a button or a
 * heading the way a person reads the screen, pointing softly, touring a row of things, the shell's own
 * parts, and an email shown as the app wrote it.
 *
 * Names are matched exactly but in any case (the shell sets some labels in capitals with CSS).
 */
const { named } = require("./film");

const button = (s, name, within) => (within || s.page).getByRole("button", { name: named(name) }).first();
const link = (s, name, within) => (within || s.page).getByRole("link", { name: named(name) }).first();
const tab = (s, name) => s.page.getByRole("tab", { name: named(name) }).first();
const heading = (s, name) => s.page.getByRole("heading", { name: named(name) }).first();
/** A window by its name; the last one, so a help popup opened on top never answers for the record. */
const dialog = (s, name) => (name ? s.page.getByRole("dialog", { name: named(name) }) : s.page.getByRole("dialog")).last();
const field = (s, label) => s.page.getByLabel(label, { exact: true }).first();
/** Words on the page itself. */
const text = (s, words, exact = true) => s.page.getByText(words, { exact }).first();

/** The shell (includes/components/sidebar.php, topbar.php). */
const shell = {
  /** The sidebar's link to a page, by its menu name (nav.php). */
  menu: (s, name) => s.page.locator("aside").getByRole("menuitem", { name: named(name) }).or(s.page.locator("aside").getByRole("link", { name: named(name) })).first(),
  /** A section heading of the menu (STRATÉGIE & BI…), which opens and closes its section. */
  section: (s, name) => s.page.locator("aside").getByRole("button", { name: named(name) }).first(),
  user: (s) => s.page.locator("#lpc-user-trigger"),
  userMenu: (s) => s.page.locator("#lpc-user-menu"),
};

/** Glide to `loc` when it shows within a few seconds: a line that only points never costs a whole take. */
async function soft(s, loc, wait = 4000) {
  if (await loc.waitFor({ state: "visible", timeout: wait }).then(() => true, () => false)) await s.hover(loc).catch(() => null);
}

/** Point at each of `locs` in turn, pausing on each. */
async function tour(s, locs, pause = 0.5) {
  for (const loc of locs) {
    await soft(s, loc);
    await s.wait(pause);
  }
}

/**
 * An email as a mail window around the app's own message: the subject and the address are the real
 * ones; the body is the HTML the app wrote, untouched. `mail` is { to, subject, html } (ground.mailTo).
 */
function mailPage(mail) {
  const body = /<body[^>]*>([\s\S]*)<\/body>/i.exec(mail.html);
  const esc = (t) => String(t).replace(/&/g, "&amp;").replace(/</g, "&lt;");
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>${esc(mail.subject)}</title></head>
<body style="margin:0;background:#eef2ee;font-family:Inter,system-ui,sans-serif">
<div style="max-width:860px;margin:40px auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px rgba(0,40,20,.14)">
  <div style="padding:20px 28px;border-bottom:1px solid #e3e8e4">
    <div style="font-size:21px;font-weight:700;color:#10241a">${esc(mail.subject)}</div>
    <div style="margin-top:6px;font-size:14px;color:#5a6b62">À : ${esc(mail.to)}</div>
  </div>
  <div style="background:#f5f8f5;padding:8px 0 24px">${body ? body[1] : mail.html}</div>
</div></body></html>`;
}

module.exports = { button, link, tab, heading, dialog, field, text, shell, soft, tour, mailPage };
