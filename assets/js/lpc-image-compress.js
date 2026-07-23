/**
 * assets/js/lpc-image-compress.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 5 · client-side image compression.
 *
 * WHY:
 *   Drivers upload 12–20 MB HEIC/JPEG receipts from phone cameras. On 3G,
 *   they time out (or blow the PHP upload limit). We compress in the browser
 *   before POST — the server still validates via Uploads::saveUploaded, so
 *   this is purely a bandwidth + latency fix.
 *
 * USAGE:
 *
 *   const compressed = await LPC.compress.compressToBlob(file, {
 *       maxDim:  1280,          // longest side in px
 *       quality: 0.7,           // JPEG quality (0..1)
 *       mime:    'image/jpeg',  // output mime
 *   });
 *   // Send `compressed` in a FormData / fetch upload.
 *
 * BAILS OUT (returns the ORIGINAL file, unmodified) when:
 *   - file.type is not image/* (nothing to do — probably a PDF)
 *   - file.size < 512 KB (compression overhead not worth it)
 *   - the compressed blob is LARGER than the original
 *   - the browser lacks canvas / OffscreenCanvas / createImageBitmap
 *
 * TOAST:
 *   Attach with LPC.compress.attachInput(fileInputEl, opts) to get a small
 *   in-page "Compression: 15.2 MB → 480 KB" indicator wired to the input.
 *
 * SECURITY:
 *   Client-side compression is NOT a validation. The server MUST still call
 *   Uploads::saveUploaded, which re-runs the MIME sniff on the received bytes.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';
    if (!window.LPC) window.LPC = {};
    if (window.LPC.compress) return;

    const MIN_COMPRESS_BYTES = 512 * 1024;   // 512 KB
    const DEFAULT_OPTS = { maxDim: 1280, quality: 0.7, mime: 'image/jpeg' };

    function humanBytes(n) {
        if (!Number.isFinite(n)) return '?';
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function canRun() {
        return typeof document !== 'undefined'
            && typeof document.createElement === 'function'
            && typeof FileReader !== 'undefined';
    }

    // Read EXIF orientation from a JPEG file. Returns 1..8 per the EXIF spec,
    // or 1 if not JPEG / no orientation tag / any parse error. Small, ad-hoc
    // parser — enough for phone-camera JPEGs.
    async function readOrientation(file) {
        if (!/^image\/jpe?g$/i.test(file.type)) return 1;
        try {
            const head = await file.slice(0, 128 * 1024).arrayBuffer();
            const view = new DataView(head);
            if (view.getUint16(0, false) !== 0xFFD8) return 1;   // not JPEG SOI
            let offset = 2;
            const len = view.byteLength;
            while (offset < len - 4) {
                const marker = view.getUint16(offset, false);
                offset += 2;
                if (marker === 0xFFE1) {
                    // APP1 (Exif)
                    if (view.getUint32(offset + 2, false) !== 0x45786966) return 1;   // "Exif"
                    const little = view.getUint16(offset + 8, false) === 0x4949;
                    const first  = view.getUint32(offset + 10, little);
                    const tags   = view.getUint16(offset + 8 + first, little);
                    for (let i = 0; i < tags; i++) {
                        const entry = offset + 8 + first + 2 + (i * 12);
                        if (view.getUint16(entry, little) === 0x0112) {
                            return view.getUint16(entry + 8, little) || 1;
                        }
                    }
                    return 1;
                } else if ((marker & 0xFF00) !== 0xFF00) {
                    return 1;
                } else {
                    offset += view.getUint16(offset, false);
                }
            }
        } catch (e) { /* fall through */ }
        return 1;
    }

    // Given orientation, size the canvas correctly and draw the image with the
    // matching rotation/flip. Covers all 8 EXIF orientation values.
    function drawOriented(ctx, img, w, h, orientation) {
        switch (orientation) {
            case 2: ctx.transform(-1, 0, 0,  1,  w, 0); break;         // horizontal flip
            case 3: ctx.transform(-1, 0, 0, -1,  w, h); break;         // 180°
            case 4: ctx.transform( 1, 0, 0, -1,  0, h); break;         // vertical flip
            case 5: ctx.transform( 0, 1, 1,  0,  0, 0); break;         // vertical flip + 90° CW
            case 6: ctx.transform( 0, 1, -1, 0,  h, 0); break;         // 90° CW
            case 7: ctx.transform( 0,-1, -1, 0,  h, w); break;         // horizontal flip + 90° CW
            case 8: ctx.transform( 0,-1,  1, 0,  0, w); break;         // 90° CCW
            default: break;                                              // 1 = normal
        }
        // Orientations 5..8 rotate the axes — the drawImage width/height still
        // refer to the *source* image dimensions, not the canvas.
        const swap = orientation >= 5 && orientation <= 8;
        ctx.drawImage(img, 0, 0, swap ? h : w, swap ? w : h);
    }

    function loadImageFromBlob(blob) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(blob);
            const img = new Image();
            img.onload  = () => { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = (e) => { URL.revokeObjectURL(url); reject(e); };
            img.src = url;
        });
    }

    /**
     * Compress a file to a Blob. Returns the ORIGINAL file (as-is) when the
     * bail-out conditions above trigger.
     */
    async function compressToBlob(file, opts) {
        opts = Object.assign({}, DEFAULT_OPTS, opts || {});
        if (!canRun()) return file;
        if (!file || typeof file.type !== 'string') return file;
        if (!file.type.startsWith('image/')) return file;
        if (file.size < MIN_COMPRESS_BYTES) return file;

        try {
            const orientation = await readOrientation(file);
            const img = await loadImageFromBlob(file);

            const swap = orientation >= 5 && orientation <= 8;
            let srcW = swap ? img.height : img.width;
            let srcH = swap ? img.width  : img.height;

            // Scale so the longest side is opts.maxDim.
            const scale = Math.min(1, opts.maxDim / Math.max(srcW, srcH));
            const dstW  = Math.round(srcW * scale);
            const dstH  = Math.round(srcH * scale);

            const canvas = document.createElement('canvas');
            canvas.width  = dstW;
            canvas.height = dstH;
            const ctx = canvas.getContext('2d');
            if (!ctx) return file;
            ctx.fillStyle = '#FFFFFF';                       // white matte for JPEG
            ctx.fillRect(0, 0, dstW, dstH);

            // Draw with orientation. For an oriented draw at scaled size, we
            // scale + transform + draw against the original image dims.
            ctx.save();
            ctx.scale(dstW / (swap ? img.height : img.width), dstH / (swap ? img.width : img.height));
            drawOriented(ctx, img, img.width, img.height, orientation);
            ctx.restore();

            const blob = await new Promise((resolve) => canvas.toBlob(resolve, opts.mime, opts.quality));
            if (!blob || blob.size >= file.size) return file;   // no win — bail
            // Preserve the filename with the right extension.
            const base = (file.name || 'image').replace(/\.[^.]+$/, '');
            const ext  = opts.mime === 'image/png' ? '.png' : '.jpg';
            const out  = new File([blob], base + ext, { type: opts.mime, lastModified: Date.now() });
            out.__lpc_original_size = file.size;
            return out;
        } catch (e) {
            console.warn('lpc-image-compress: fallback to original (' + (e && e.message) + ')');
            return file;
        }
    }

    /**
     * Attach compression to an <input type="file">. On change, replaces the
     * chosen file inline (via DataTransfer) with the compressed blob so any
     * subsequent form.submit() picks up the smaller version transparently.
     * Also emits a small toast if the file was compressed.
     */
    function attachInput(inputEl, opts) {
        if (!inputEl || inputEl.tagName !== 'INPUT') return;
        inputEl.addEventListener('change', async () => {
            if (!inputEl.files || !inputEl.files[0]) return;
            const before = inputEl.files[0];
            const after  = await compressToBlob(before, opts);
            if (after === before) return;                          // no change
            try {
                const dt = new DataTransfer();
                dt.items.add(after);
                inputEl.files = dt.files;
                toast(`Compression : ${humanBytes(before.size)} → ${humanBytes(after.size)}`);
            } catch (e) {
                // Some old browsers can't reassign inputEl.files. In that case
                // the caller must call compressToBlob() manually in their
                // submit handler. Log once and move on.
                console.warn('lpc-image-compress: cannot assign compressed file back to input', e);
            }
        }, { passive: true });
    }

    // Tiny non-blocking toast (bottom right, 3 s). Zero deps.
    let toastEl = null;
    function toast(text) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;'
                + 'padding:.6rem 1rem;background:#111;color:#fff;font:600 12px/1.3 system-ui,sans-serif;'
                + 'border-radius:.5rem;box-shadow:0 10px 25px -5px rgba(0,0,0,.4);opacity:0;'
                + 'transition:opacity .2s ease-out;pointer-events:none';
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = text;
        toastEl.style.opacity = '1';
        clearTimeout(toast._t);
        toast._t = setTimeout(() => { if (toastEl) toastEl.style.opacity = '0'; }, 3000);
    }

    /**
     * Downscale a canvas (typically a signature pad) and return a smaller
     * PNG data URL. Signatures are line drawings — PNG is right, but the
     * default 800×400 canvas at 2× DPR is way bigger than any downstream
     * consumer needs. maxDim caps the longest side (default 512).
     *
     * Returns the ORIGINAL dataURL untouched if maxDim is already met, or
     * if anything in the downscale path throws (never fail-close).
     */
    function compressCanvasDataUrl(canvas, maxDim) {
        maxDim = maxDim || 512;
        if (!canvas || typeof canvas.getContext !== 'function') return null;
        const w = canvas.width, h = canvas.height;
        if (!w || !h) return canvas.toDataURL('image/png');
        if (Math.max(w, h) <= maxDim) return canvas.toDataURL('image/png');
        try {
            const scale = maxDim / Math.max(w, h);
            const dstW = Math.max(1, Math.round(w * scale));
            const dstH = Math.max(1, Math.round(h * scale));
            const off  = document.createElement('canvas');
            off.width = dstW; off.height = dstH;
            const ctx = off.getContext('2d');
            if (!ctx) return canvas.toDataURL('image/png');
            ctx.fillStyle = '#FFFFFF';                       // white matte, keep line contrast
            ctx.fillRect(0, 0, dstW, dstH);
            ctx.drawImage(canvas, 0, 0, dstW, dstH);
            return off.toDataURL('image/png');
        } catch (e) {
            console.warn('compressCanvasDataUrl: fallback to full-size PNG (' + (e && e.message) + ')');
            return canvas.toDataURL('image/png');
        }
    }

    window.LPC.compress = { compressToBlob, attachInput, humanBytes, compressCanvasDataUrl };
})();
