# assets/vendor/ — self-hosted third-party assets

**Every front-end library the app depends on is served from this tree.** No
`cdnjs.cloudflare.com`, `cdn.jsdelivr.net`, `unpkg.com`, or `cdn.tailwindcss.com`
should appear anywhere in `modules/`, `public/`, `includes/`, or `index.php`.
Grep is the enforcement mechanism (see `.htaccess` CSP + Sprint 6 acceptance).

The `.htaccess` at the repo root denies `/vendor/*` (Composer) but ALLOWS
`/assets/*`, so third-party JS/CSS/fonts belong here — not in the top-level
`vendor/` directory.

---

## Pinned versions (Sprint 6, 21 Jul 2026)

| Library            | Version | Local path                                              | Origin CDN                                                                            |
| ------------------ | ------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| FontAwesome        | 6.4.0   | `fontawesome/css/all.min.css` + `fontawesome/webfonts/` | cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/                                    |
| Chart.js           | 4.4.4   | `chartjs/chart.umd.min.js`                              | cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.js (cdnjs mis-cased path was 404)  |
| jsPDF              | 2.5.1   | `jspdf/jspdf.umd.min.js`                                | cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js                           |
| html2canvas        | 1.4.1   | `html2canvas/html2canvas.min.js`                        | cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js                   |
| html2pdf.js        | 0.10.1  | `html2pdf/html2pdf.bundle.min.js`                       | cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js              |
| signature_pad      | 4.1.5   | `signature_pad/signature_pad.umd.min.js`                | cdn.jsdelivr.net/npm/signature_pad@4.1.5/dist/signature_pad.umd.min.js                |
| qrcodejs           | 1.0.0   | `qrcodejs/qrcode.min.js`                                | cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js                           |

---

## Sub-resource Integrity (SRI) hashes

Every `<link>` / `<script>` that loads a file below MUST carry the matching
`integrity="sha384-…"` attribute + `crossorigin="anonymous"`. The canonical
place these are wired up is `includes/components/head_assets.php` — page
templates should include that file rather than hand-rolling the tags.

```
fontawesome/css/all.min.css              sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0
chartjs/chart.umd.min.js                 sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t
jspdf/jspdf.umd.min.js                   sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk
html2canvas/html2canvas.min.js           sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H
html2pdf/html2pdf.bundle.min.js          sha384-Yv5O+t3uE3hunW8uyrbpPW3iw6/5/Y7HitWJBLgqfMoA36NogMmy+8wWZMpn3HWc
signature_pad/signature_pad.umd.min.js   sha384-SKrWXOuD3tayW46k6CjYf2mKcUXo0AUV/IVlgNBWYl/d6BIHJ4f4i8f1UCLH7E3W
qrcodejs/qrcode.min.js                   sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU
```

The FontAwesome webfonts (`fontawesome/webfonts/*.woff2` + `.ttf`) are
referenced from `all.min.css` via `url(../webfonts/…)`. They don't need SRI
attributes (the browser follows the CSS reference) but the woff2 files must
stay next to the CSS at the exact relative path or `<i class="fas fa-…">`
icons render as boxes.

---

## Upgrading a library

1. Note down the new upstream version and its origin URL.
2. Download the file(s) into the matching sub-folder:
   ```bash
   curl -sSL -o assets/vendor/<lib>/<file> https://cdnjs.cloudflare.com/...
   ```
3. Recompute the SRI hash:
   ```bash
   openssl dgst -sha384 -binary assets/vendor/<lib>/<file> | openssl base64 -A
   ```
4. Update this README's version table + the `sha384-…` line above.
5. Update `includes/components/head_assets.php` with the new hash. Any per-
   page `<script>` that pulls the same lib (jsPDF, html2canvas, etc.) needs
   its `integrity=` attribute updated to match.
6. Deploy + smoke-test the pages that use the library — DevTools console
   will surface an SRI mismatch as
   `Failed to find a valid digest in the 'integrity' attribute for resource ...`.

---

## Regenerating every SRI hash at once

```bash
cd assets/vendor
for f in fontawesome/css/all.min.css chartjs/chart.umd.min.js \
         jspdf/jspdf.umd.min.js html2canvas/html2canvas.min.js \
         html2pdf/html2pdf.bundle.min.js \
         signature_pad/signature_pad.umd.min.js qrcodejs/qrcode.min.js; do
    printf '%-45s  sha384-%s\n' "$f" \
        "$(openssl dgst -sha384 -binary "$f" | openssl base64 -A)"
done
```

---

## Why we bothered

*   **CSP tightening.** With everything self-hosted the CSP `script-src`
    can drop `cdnjs.cloudflare.com`, `cdn.jsdelivr.net`, and `unpkg.com`.
*   **Supply-chain risk.** SRI catches CDN-side tampering. A modified upstream
    file is refused by the browser instead of silently executing.
*   **Offline dev.** The dev VM doesn't always have public network reach.
*   **Ship-day repeatability.** Nothing in the release depends on a third
    party staying up on deploy day.
