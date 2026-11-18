"use strict";
/**
 * The look of a Bureau LPC training video (docs/TRAINING_VIDEOS.md §2), kept in one file so every
 * video, in every chat, matches the one before it. Do not change it without a new decision from the
 * owner: a library whose videos look alike is easier to trust.
 *
 * A frame is 1920 × 1080: the picture (1920 × 960) above the caption bar (1920 × 120). The picture is
 * the app, or a card; the bar carries the French caption of the line being spoken and, on the left,
 * which chapter this is. Each builder returns a self-contained HTML page that `cards.js` photographs
 * with Chromium: the font and the logo are inlined, so no page depends on the network.
 *
 * The cards wear La Petite Cour's own look, as the app does: the brand pair (#005A2B, #8CC63F from
 * tailwind.config.js), Inter (the app's face), and the full logo (assets/img/full_logo.svg) on a white
 * pill. "Conçu par JBS Praxis" sits at the foot, as "Secured by JBS Praxis" does on the sign-in page.
 */
const fs = require("node:fs");
const path = require("node:path");

const ROOT = path.resolve(__dirname, "..", "..");
const FONTS = path.join(ROOT, "node_modules", "@fontsource-variable", "inter", "files");

const SIZE = Object.freeze({ width: 1920, height: 1080, picture: 960, bar: 120 });

/** Desktop is filmed at 1536 × 768 CSS pixels with a device scale of 1.25, which fills the picture exactly. */
const DESKTOP = Object.freeze({ viewport: { width: 1536, height: 768 }, deviceScaleFactor: 1.25 });

/** A phone (kept for a later video; the introduction is desktop only) is 390 × 860 at scale 2, shown in a frame. */
const PHONE = Object.freeze({
  viewport: { width: 390, height: 860 },
  deviceScaleFactor: 2,
  screen: { x: 1068, y: 40, width: 394, height: 869, radius: 46 },
  body: { x: 1050, y: 22, width: 430, height: 905, radius: 64 },
});

const COLOUR = Object.freeze({
  green: "#005A2B",
  lime: "#8CC63F",
  deep: "#00331a",
  bar: "#002a15",
  ink: "#ffffff",
  soft: "#dcebd9",
  muted: "#a9c3ad",
  bezel: "#111312",
});

const cache = new Map();
function dataUri(file, mime) {
  if (!cache.has(file)) cache.set(file, `data:${mime};base64,${fs.readFileSync(file).toString("base64")}`);
  return cache.get(file);
}

/** The page chrome: built when a page is made, so requiring this file needs no font on disk. */
const baseCss = () => `
@font-face{font-family:"Inter";src:url("${dataUri(path.join(FONTS, "inter-latin-wght-normal.woff2"), "font/woff2")}") format("woff2");font-weight:100 900;unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:"Inter";src:url("${dataUri(path.join(FONTS, "inter-latin-ext-wght-normal.woff2"), "font/woff2")}") format("woff2");font-weight:100 900;unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:${SIZE.width}px;overflow:hidden;background:transparent}
body{font-family:"Inter";color:${COLOUR.ink};position:relative;-webkit-font-smoothing:antialiased;font-feature-settings:"cv11","ss01"}
.ground{position:absolute;inset:0;background:
  radial-gradient(1100px 760px at 1700px 120px,rgba(140,198,63,.30) 0%,rgba(140,198,63,0) 70%),
  radial-gradient(900px 640px at 120px 980px,rgba(140,198,63,.16) 0%,rgba(140,198,63,0) 70%),
  linear-gradient(135deg,${COLOUR.green} 0%,${COLOUR.deep} 62%,#00230f 100%)}
.leaf{position:absolute;right:-120px;bottom:-160px;width:820px;height:820px;opacity:.07;
  background:url("${dataUri(path.join(ROOT, "assets", "img", "small_logo.svg"), "image/svg+xml")}") center/contain no-repeat}
`;

const logo = () => `<img src="${dataUri(path.join(ROOT, "assets", "img", "full_logo.svg"), "image/svg+xml")}" alt="">`;

const esc = (s) => String(s ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

function page(height, css, body) {
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>${baseCss()}html,body{height:${height}px}${css}</style></head><body>${body}</body></html>`;
}

/**
 * The title is one line at 132 px, as wide as the picture allows (1440 px beside the logo column): a
 * longer one shrinks to fit. Inter ExtraBold sets about 0.56 em a letter on average.
 */
const titleSize = (title) => Math.min(132, Math.floor(1440 / (String(title).length * 0.58)));

/** The opening card, and the closing one with `closing`: the picture only, 1920 × 960. */
function card({ eyebrow, title, subtitle, left, right = "JBS Praxis", rightLabel = "Conçu par", closing = false }) {
  const size = titleSize(title);
  const css = `
.logo{position:absolute;left:180px;top:118px;width:236px;height:236px;border-radius:40px;background:#fff;display:grid;place-items:center;
  box-shadow:0 18px 50px rgba(0,0,0,.35)}
.logo img{width:206px;height:206px;display:block}
.eyebrow{position:absolute;left:180px;top:410px;font:700 28px "Inter";letter-spacing:.16em;color:${COLOUR.lime};text-transform:uppercase}
h1{position:absolute;left:176px;top:${458 + Math.round((132 - size) * 0.9)}px;font:800 ${size}px/1.05 "Inter";letter-spacing:-.025em;white-space:nowrap}
.sub{position:absolute;left:180px;top:622px;width:1400px;font:500 40px/1.3 "Inter";color:${COLOUR.soft}}
.rule{position:absolute;left:180px;top:712px;width:170px;height:10px;border-radius:5px;background:${COLOUR.lime}}
.foot{position:absolute;left:180px;right:180px;top:842px;display:flex;justify-content:space-between;font:400 28px "Inter";color:${COLOUR.muted}}
.foot b{color:${COLOUR.lime};font-weight:700}`;
  return page(SIZE.picture, css, `<div class="ground"></div><div class="leaf"></div>
<div class="logo">${logo()}</div>
<div class="eyebrow">${esc(eyebrow)}</div>
<h1 data-closing="${closing}">${esc(title)}</h1>
<div class="sub">${esc(subtitle)}</div>
<div class="rule"></div>
<div class="foot"><span>${esc(left)}</span><span>${esc(rightLabel)} <b>${esc(right)}</b></span></div>`);
}

/** The ground a phone sits on, with its label on the left and the phone's body; the screen is cut out. */
function phoneGround({ eyebrow, title, subtitle }) {
  const s = PHONE.screen;
  const b = PHONE.body;
  const css = `
.label{position:absolute;left:150px;top:330px;width:820px}
.label .eyebrow{font:700 25px "Inter";letter-spacing:.14em;color:${COLOUR.lime};text-transform:uppercase}
.label h2{margin-top:17px;font:800 60px/1.1 "Inter";letter-spacing:-.02em}
.label p{margin-top:19px;font:400 28px "Inter";color:${COLOUR.soft}}
.body{position:absolute;left:${b.x}px;top:${b.y}px;width:${b.width}px;height:${b.height}px;border-radius:${b.radius}px;
  background:${COLOUR.bezel};box-shadow:0 0 0 2px #2c302e,0 30px 80px rgba(0,0,0,.45)}
.hole{position:absolute;left:${s.x}px;top:${s.y}px;width:${s.width}px;height:${s.height}px;border-radius:${s.radius}px;
  box-shadow:0 0 0 30px ${COLOUR.bezel};background:#fff}`;
  return page(SIZE.picture, css, `<div class="ground"></div>
<div class="label"><div class="eyebrow">${esc(eyebrow)}</div><h2>${esc(title)}</h2><p>${esc(subtitle)}</p></div>
<div class="body"></div><div class="hole"></div>`);
}

/** The phone's rim, drawn over the filmed screen so its corners are rounded: transparent elsewhere. */
function phoneRim() {
  const s = PHONE.screen;
  const css = `.hole{position:absolute;left:${s.x}px;top:${s.y}px;width:${s.width}px;height:${s.height}px;border-radius:${s.radius}px;
  box-shadow:0 0 0 30px ${COLOUR.bezel}}`;
  return page(SIZE.picture, css, `<div class="hole"></div>`);
}

/**
 * The roles a story names (the owner, 18/11/2026: the narration names the role, not the person), each
 * with its own colour, so a viewer finds "their" colour along the journey map and in the caption bar.
 */
const ROLES = Object.freeze({
  dg: { label: "Directeur Général", short: "Direction", colour: "#F2C94C" },
  commercial: { label: "Commercial", short: "Commercial", colour: "#FF9E7A" },
  operations: { label: "Responsable des Opérations", short: "Opérations", colour: "#6FD3F7" },
  comptable: { label: "Comptable", short: "Comptable", colour: "#B5E36B" },
  controleur: { label: "Contrôleur Financier", short: "Contrôleur", colour: "#C9B2FF" },
  // The IT partner (JBS Praxis Support), who runs the admin screens with the DG: the admin series.
  support: { label: "Support technique", short: "Support", colour: "#9FD8C8" },
});

/**
 * The caption bar: "CHAPITRE 2 SUR 9" and the chapter's name on the left, the spoken line centred,
 * and, when a role acts (`role`, a key of ROLES), the role in its colour on the right with today's
 * holder under it (`holder`, once per role in a video). 1920 × 120.
 */
function bar({ number, total, chapter, caption, role, holder }) {
  const r = role ? ROLES[role] : null;
  if (role && !r) throw new Error(`Unknown role "${role}" (style.js ROLES).`);
  const css = `
.bar{position:absolute;inset:0;background:${COLOUR.bar};border-top:3px solid ${COLOUR.lime}}
.chapter{position:absolute;left:40px;top:0;bottom:0;width:300px;display:flex;flex-direction:column;justify-content:center}
.chapter .n{font:700 15px "Inter";letter-spacing:.14em;color:${COLOUR.lime};text-transform:uppercase}
.chapter .t{margin-top:7px;font:700 20px/1.2 "Inter";color:${COLOUR.ink}}
.caption{position:absolute;left:350px;right:350px;top:0;bottom:0;display:flex;align-items:center;justify-content:center;text-align:center;
  font:400 28px/1.3 "Inter";color:#f3f7f2}
.role{position:absolute;right:32px;top:0;bottom:0;width:310px;display:flex;flex-direction:column;justify-content:center;align-items:flex-end;text-align:right}
.role .k{display:flex;align-items:center;gap:9px;font:700 14px/1.25 "Inter";letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}
.role .k i{width:12px;height:12px;border-radius:6px;flex:none}
.role .h{margin-top:7px;font:500 17px/1.2 "Inter";color:${COLOUR.soft}}`;
  return page(SIZE.bar, css, `<div class="bar"></div>
<div class="chapter"><div class="n">Chapitre ${number} sur ${total}</div><div class="t">${esc(chapter)}</div></div>
<div class="caption">${esc(caption)}</div>
${r ? `<div class="role"><div class="k" style="color:${r.colour}"><i style="background:${r.colour}"></i>${esc(r.label)}</div>${holder ? `<div class="h">Aujourd'hui : ${esc(holder)}</div>` : ""}</div>` : ""}`);
}

/**
 * The journey map of a story video: the stations on a winding road (two rows, the second running back
 * right to left), a 20L bottle on the road, each station with its roles' colours. 1920 × 960.
 *
 *   stations   [{ name, roles: ["operations", …] }]   (up to 12, six a row)
 *   at         the station the story is at (0-based), lit; the ones before it are done; -1 for none
 *   pos        where the bottle stands on the road, as a station index (fractions travel between two);
 *              defaults to `at`; null hides it
 *   lit        role keys whose stations glow (the closing "where do you come in"); the others dim
 */
const ROAD = Object.freeze({ left: 230, right: 1690, rows: [372, 704], turn: 166 });

function roadPoint(stations, s) {
  const perRow = Math.ceil(stations / 2);
  const step = (ROAD.right - ROAD.left) / (perRow - 1);
  const node = (i) => {
    const row = i < perRow ? 0 : 1;
    const k = row === 0 ? i : perRow - 1 - (i - perRow);
    return { x: ROAD.left + k * step, y: ROAD.rows[row] };
  };
  const a = Math.max(0, Math.min(stations - 1, Math.floor(s)));
  const b = Math.min(stations - 1, a + 1);
  const f = s - a;
  if (f <= 0 || a === b) return node(a);
  if (a === perRow - 1) {
    // The turn at the right end: half a circle from the first row down to the second.
    const cx = ROAD.right;
    const cy = (ROAD.rows[0] + ROAD.rows[1]) / 2;
    const rad = (ROAD.rows[1] - ROAD.rows[0]) / 2;
    const ang = -Math.PI / 2 + f * Math.PI;
    return { x: cx + Math.cos(ang) * Math.max(rad, ROAD.turn) * 0.9, y: cy + Math.sin(ang) * rad };
  }
  const p = node(a);
  const q = node(b);
  return { x: p.x + (q.x - p.x) * f, y: p.y + (q.y - p.y) * f };
}

const BOTTLE = `<svg width="62" height="92" viewBox="0 0 62 92" xmlns="http://www.w3.org/2000/svg">
<rect x="21" y="2" width="20" height="9" rx="2.5" fill="#1f7ae0"/>
<path d="M24 11h14v7c0 3 9 5 12 9 2.6 3.4 3 7 3 11v42c0 5-4 8-8 8H17c-4 0-8-3-8-8V38c0-4 .4-7.6 3-11 3-4 12-6 12-9z" fill="#dff1ff" stroke="#ffffff" stroke-width="2.5"/>
<path d="M11 50h40v30c0 4-3 6-6 6H17c-3 0-6-2-6-6z" fill="#7cc4ff" opacity=".85"/>
<rect x="15" y="40" width="5" height="34" rx="2.5" fill="#fff" opacity=".7"/>
<text x="31" y="66" text-anchor="middle" font-family="Inter" font-weight="800" font-size="11" fill="#0b4a8c">OPUR</text>
</svg>`;

function journey({ eyebrow, title, stations, at = -1, pos, lit = null }) {
  const n = stations.length;
  const perRow = Math.ceil(n / 2);
  const pts = stations.map((_, i) => roadPoint(n, i));
  const here = pos === null ? null : roadPoint(n, pos === undefined ? Math.max(at, 0) : pos);
  const litSet = lit ? new Set(lit) : null;
  const glow = (st) => !litSet || st.roles.some((r) => litSet.has(r));
  // The road: its whole length faint, and lime as far as the story has come.
  const road = (to) => {
    const parts = [];
    const steps = Math.max(1, Math.round(to * 24));
    for (let k = 0; k <= steps; k++) { const p = roadPoint(n, (to * k) / steps); parts.push(`${k ? "L" : "M"}${p.x.toFixed(1)} ${p.y.toFixed(1)}`); }
    return parts.join(" ");
  };
  const travelled = at > 0 ? (pos !== undefined && pos !== null ? Math.max(pos, 0) : at) : 0;
  const nodes = stations.map((st, i) => {
    const p = pts[i];
    const state = i === at ? "now" : at >= 0 && i < at ? "done" : "next";
    const dim = !glow(st);
    const below = i < perRow ? 62 : 62;
    const chips = st.roles.map((r) => `<span class="chip" style="--c:${ROLES[r].colour}"><i></i>${esc(ROLES[r].short)}</span>`).join("");
    return `<div class="node ${state}${dim ? " dim" : ""}${litSet && !dim ? " lit" : ""}" style="left:${p.x}px;top:${p.y}px">
  <div class="dot">${i + 1}</div>
  <div class="label" style="top:${below}px"><div class="name">${esc(st.name)}</div><div class="roles">${chips}</div></div>
</div>`;
  }).join("");
  const css = `
.head{position:absolute;left:96px;top:58px}
.head .eyebrow{font:700 22px "Inter";letter-spacing:.16em;color:${COLOUR.lime};text-transform:uppercase}
.head h1{margin-top:10px;font:800 54px/1.05 "Inter";letter-spacing:-.02em}
svg.road{position:absolute;inset:0}
.node{position:absolute;width:0;height:0}
.node .dot{position:absolute;left:-34px;top:-34px;width:68px;height:68px;border-radius:50%;display:grid;place-items:center;
  font:800 26px "Inter";color:${COLOUR.soft};background:rgba(0,40,20,.85);border:3px solid rgba(255,255,255,.35)}
.node.done .dot{background:${COLOUR.lime};border-color:${COLOUR.lime};color:${COLOUR.deep}}
.node.now .dot{left:-44px;top:-44px;width:88px;height:88px;font-size:32px;background:#fff;border:5px solid ${COLOUR.lime};color:${COLOUR.green};
  box-shadow:0 0 0 12px rgba(140,198,63,.28),0 0 46px rgba(140,198,63,.65)}
.node.lit .dot{border-color:#fff;box-shadow:0 0 0 10px rgba(255,255,255,.16),0 0 34px rgba(255,255,255,.35)}
.node.dim{opacity:.28}
.node .label{position:absolute;left:-150px;width:300px;text-align:center}
.node .name{font:700 22px/1.2 "Inter";color:#fff}
.node.next .name{color:${COLOUR.soft}}
.node.now .name{font-size:25px;color:#fff}
.node.now .label{top:74px !important}
.node .roles{margin-top:8px;display:flex;flex-wrap:wrap;justify-content:center;gap:6px}
.chip{display:inline-flex;align-items:center;gap:6px;padding:3px 10px 3px 8px;border-radius:999px;font:700 13px/1.2 "Inter";letter-spacing:.02em;
  color:var(--c);background:rgba(0,30,15,.72);border:1.5px solid var(--c)}
.chip i{width:8px;height:8px;border-radius:4px;background:var(--c)}
.bottle{position:absolute;width:62px;height:92px;filter:drop-shadow(0 10px 14px rgba(0,0,0,.45))}`;
  const svg = `<svg class="road" width="${SIZE.width}" height="${SIZE.picture}" viewBox="0 0 ${SIZE.width} ${SIZE.picture}">
<path d="${road(n - 1)}" fill="none" stroke="rgba(255,255,255,.22)" stroke-width="10" stroke-linecap="round" stroke-dasharray="2 18"/>
${travelled > 0 ? `<path d="${road(travelled)}" fill="none" stroke="${COLOUR.lime}" stroke-width="10" stroke-linecap="round"/>` : ""}
</svg>`;
  const bottle = here ? `<div class="bottle" style="left:${(here.x - 31).toFixed(1)}px;top:${(here.y - 138).toFixed(1)}px">${BOTTLE}</div>` : "";
  return page(SIZE.picture, css, `<div class="ground"></div><div class="leaf"></div>
<div class="head"><div class="eyebrow">${esc(eyebrow)}</div><h1>${esc(title)}</h1></div>
${svg}${nodes}${bottle}`);
}

module.exports = { ROOT, SIZE, DESKTOP, PHONE, COLOUR, ROLES, card, titleSize, phoneGround, phoneRim, bar, journey, roadPoint, esc };
