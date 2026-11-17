"use strict";
/**
 * Photograph the pages style.js builds: the opening and closing cards, the phone's ground and rim,
 * and one caption bar per caption. One Chromium for the whole run (`open`, then `shoot` many times).
 */
const fs = require("node:fs");
const path = require("node:path");
const { launch } = require("./browser");
const { SIZE } = require("./style");

async function open() {
  const browser = await launch();
  const page = await browser.newPage({ viewport: { width: SIZE.width, height: SIZE.height } });
  return {
    /** Save `html` as a PNG `height` pixels tall; transparent where the page paints nothing. */
    async shoot(html, file, height) {
      fs.mkdirSync(path.dirname(file), { recursive: true });
      await page.setViewportSize({ width: SIZE.width, height });
      await page.setContent(html, { waitUntil: "load" });
      await page.evaluate(() => document.fonts.ready); // eslint-disable-line no-undef
      await page.screenshot({ path: file, omitBackground: true, clip: { x: 0, y: 0, width: SIZE.width, height } });
      return file;
    },
    close: () => browser.close(),
  };
}

module.exports = { open };
