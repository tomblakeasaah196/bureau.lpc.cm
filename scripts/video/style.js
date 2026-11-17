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

/** The caption bar: "CHAPITRE 2 SUR 9" and the chapter's name on the left, the spoken line centred. 1920 × 120. */
function bar({ number, total, chapter, caption }) {
  const css = `
.bar{position:absolute;inset:0;background:${COLOUR.bar};border-top:3px solid ${COLOUR.lime}}
.chapter{position:absolute;left:40px;top:0;bottom:0;width:300px;display:flex;flex-direction:column;justify-content:center}
.chapter .n{font:700 15px "Inter";letter-spacing:.14em;color:${COLOUR.lime};text-transform:uppercase}
.chapter .t{margin-top:7px;font:700 20px/1.2 "Inter";color:${COLOUR.ink}}
.caption{position:absolute;left:350px;right:350px;top:0;bottom:0;display:flex;align-items:center;justify-content:center;text-align:center;
  font:400 28px/1.3 "Inter";color:#f3f7f2}`;
  return page(SIZE.bar, css, `<div class="bar"></div>
<div class="chapter"><div class="n">Chapitre ${number} sur ${total}</div><div class="t">${esc(chapter)}</div></div>
<div class="caption">${esc(caption)}</div>`);
}

module.exports = { ROOT, SIZE, DESKTOP, PHONE, COLOUR, card, titleSize, phoneGround, phoneRim, bar, esc };
