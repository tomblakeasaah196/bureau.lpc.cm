"use strict";
/**
 * Where a training video finds its browser (docs/TRAINING_VIDEOS.md §1).
 *
 * playwright-core is a dev dependency (package.json, pinned) and never downloads a browser of its own.
 * Chromium is the first of these that exists AND starts: LPC_CHROMIUM, CHROME_BIN, the installed Google
 * Chrome, then the Chromium Playwright 1.64 expects in the local Playwright cache (chromium-1248). On the
 * owner's PC Windows refuses to run the cached one ("Permission denied"), so Chrome is tried first there.
 */
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");

function playwright() {
  try {
    return require("playwright-core");
  } catch {
    throw new Error("playwright-core is not installed: run `npm ci` at the repository root (docs/TRAINING_VIDEOS.md §1).");
  }
}

function candidates() {
  const cache = process.env.PLAYWRIGHT_BROWSERS_PATH
    || (process.platform === "win32" ? path.join(process.env.LOCALAPPDATA || "", "ms-playwright") : path.join(os.homedir(), ".cache", "ms-playwright"));
  return [
    process.env.LPC_CHROMIUM,
    process.env.CHROME_BIN,
    "C:/Program Files/Google/Chrome/Application/chrome.exe",
    "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe",
    path.join(cache, "chromium-1248", "chrome-win64", "chrome.exe"),
    path.join(cache, "chromium-1248", "chrome-linux64", "chrome"),
    "/usr/bin/google-chrome",
    "/opt/pw-browsers/chromium",
  ].filter((p, i, all) => p && all.indexOf(p) === i && fs.existsSync(p));
}

let chosen = null;

/** A Chromium, headless, with `args` and any other launch options. The first that starts is kept for the run. */
async function launch(options = {}) {
  const list = chosen ? [chosen] : candidates();
  if (!list.length) throw new Error("No Chromium found: set LPC_CHROMIUM (docs/TRAINING_VIDEOS.md §1).");
  let last;
  for (const executablePath of list) {
    try {
      const browser = await playwright().chromium.launch({ executablePath, headless: true, ...options });
      chosen = executablePath;
      return browser;
    } catch (err) {
      last = err;
    }
  }
  throw last;
}

const chromiumPath = () => chosen || candidates()[0];

module.exports = { playwright, launch, chromiumPath };
