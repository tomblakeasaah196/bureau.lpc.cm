/**
 * assets/js/lpc-dom.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — safe DOM helpers.
 *
 * WHY:
 *   Every renderer in the codebase built rows with
 *     tbody.innerHTML += `<tr><td>${row.name}</td></tr>`;
 *   which lets any user-controlled string (client name, product name, driver
 *   note) inject arbitrary HTML. This helper gives us a drop-in replacement
 *   that auto-escapes every interpolation.
 *
 * USAGE:
 *
 *   // Tagged template — every `${…}` is HTML-escaped:
 *   tbody.innerHTML += LPC.html`
 *       <tr>
 *           <td>${row.name}</td>
 *           <td>${row.email}</td>
 *           <td class="text-right">${LPC.fmt.fcfa(row.amount)}</td>
 *       </tr>`;
 *
 *   // If you truly want raw HTML in ONE slot (already-escaped, from a trusted
 *   // source, or a snippet you generated yourself), wrap it in LPC.raw():
 *   const badge = LPC.raw(`<span class="badge">OK</span>`);
 *   el.innerHTML = LPC.html`<div>${badge} ${user.name}</div>`;
 *
 *   // Convenience for creating a single element with escaped attributes:
 *   const btn = LPC.el('button', { class: 'btn', 'data-id': row.id }, row.name);
 *
 * SECURITY MODEL:
 *   · Every `${…}` in an LPC.html`…` template is HTML-escaped by default.
 *   · The ONLY way to inject raw HTML is via LPC.raw(x) — code review this.
 *   · Numbers and null/undefined are coerced to '' / their string form.
 *   · Arrays are joined with '' (so `${items.map(x => LPC.html`…`)}` works).
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';
    if (!window.LPC) window.LPC = {};
    if (window.LPC.html) return;   // already loaded

    // Sentinel class for "trusted raw HTML".
    //
    // toString() matters more than it looks. `coerce()` below unwraps RawHtml
    // when it goes through an LPC.html`...` tagged template — but a plain
    // untagged template literal never calls coerce, it calls String(), and the
    // default Object.prototype.toString yields the literal text
    // "[object Object]". That is exactly what was rendering in the Actions
    // column of the Master Data table: `tr += \`...${LPC.raw(btn)}...\`` on an
    // untagged literal. Roughly ten call sites across the accounting and admin
    // modules use the same shape, so the fix belongs here rather than in each
    // of them. Escaping is unaffected: coerce() still intercepts RawHtml first,
    // and everything that is NOT RawHtml still gets escaped.
    class RawHtml {
        constructor(s) { this.s = String(s); }
        toString() { return this.s; }
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function coerce(v) {
        if (v === null || v === undefined) return '';
        if (v instanceof RawHtml)          return v.s;
        if (Array.isArray(v))              return v.map(coerce).join('');
        if (typeof v === 'number' || typeof v === 'boolean') return String(v);
        return escapeHtml(v);
    }

    // Tagged-template helper. Returns a plain string (safe HTML).
    function html(strings, ...values) {
        let out = strings[0];
        for (let i = 0; i < values.length; i++) {
            out += coerce(values[i]);
            out += strings[i + 1];
        }
        return out;
    }

    // Escape hatch for HTML you've already validated.
    function raw(s) { return new RawHtml(s); }

    // Small element builder with escaped attributes.
    // attrs values that are true/false toggle presence; null/undefined skip.
    function el(tag, attrs, children) {
        const parts = ['<', tag];
        if (attrs && typeof attrs === 'object') {
            for (const [k, v] of Object.entries(attrs)) {
                if (v === null || v === undefined || v === false) continue;
                if (v === true) { parts.push(' ', k); continue; }
                parts.push(' ', k, '="', escapeHtml(v), '"');
            }
        }
        parts.push('>');
        if (Array.isArray(children)) parts.push(children.map(coerce).join(''));
        else if (children !== undefined) parts.push(coerce(children));
        parts.push('</', tag, '>');
        return parts.join('');
    }

    // Set an element's content by clearing and using textContent (no HTML at all).
    function setText(node, s) { if (node) node.textContent = (s === null || s === undefined) ? '' : String(s); }

    // Formatting helpers used across the app.
    const fmt = {
        // FCFA has no subunit — integer only, thin-space thousands separator.
        fcfa(n) {
            const v = Number(n);
            if (!Number.isFinite(v)) return '0 FCFA';
            return Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' FCFA';
        },
        int(n)    { const v = Number(n); return Number.isFinite(v) ? Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') : '0'; },
        percent(n, d = 1) { const v = Number(n); return Number.isFinite(v) ? v.toFixed(d) + ' %' : '—'; },
        date(iso) { if (!iso) return ''; const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleDateString('fr-FR'); },
        dateTime(iso) { if (!iso) return ''; const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleString('fr-FR'); },
    };

    Object.assign(window.LPC, { html, raw, el, setText, escapeHtml, fmt });
})();
