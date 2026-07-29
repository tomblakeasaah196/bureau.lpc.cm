/**
 * assets/js/lpc-product-picker.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 9 · the product picker. One combobox, every line-item
 * editor in the app.
 *
 * WHAT IT REPLACES
 * ----------------
 * Three different ways of choosing a product, none of them good:
 *
 *   · CRM devis modal      — three <option> tags typed by hand, with product ids
 *                            1/2/3 that pointed at whatever rows happened to
 *                            occupy those slots. One was an emballage, which we
 *                            do not sell.
 *   · Sales order editor   — a native <select> listing every product, sorted by
 *                            name, showing "Supermont La Pétillante Verre 33cl
 *                            (33cl)". Fourteen entries today. No search: to pick
 *                            the last one you scroll past all thirteen.
 *   · Purchase order editor— the same <select>, built from a query that forgot
 *                            `is_active = 1`, so retired SKUs stayed orderable.
 *
 * A native <select> cannot show a price, a stock level or a SKU, cannot be
 * typed into, and cannot group without <optgroup> markup nobody was emitting.
 * It also cannot be styled — the screenshots show it rendering as a white
 * Windows listbox on top of the dark theme, because the OS draws it.
 *
 * THE PATTERN
 * -----------
 * WAI-ARIA 1.2 combobox with an autocomplete listbox popup:
 *
 *   · Click, focus or ArrowDown opens the full list. It opens on click even
 *     with an empty query — this is a picker first and a search box second, and
 *     "show me what there is" is the more common intent.
 *   · Typing filters. Tokens are AND-ed, so "sup 33" finds "Supermont … 33cl"
 *     and "opur 20" finds "20L Opur" — order-independent, because people type
 *     the brand first and the size first about equally often.
 *   · Accent- and case-insensitive: "petillante" matches "Pétillante". The fold
 *     is done server-side, once per product, and shipped in the payload.
 *   · ArrowUp/Down move, Home/End jump, Enter selects, Escape closes (and a
 *     second Escape clears), Tab closes and commits nothing.
 *   · aria-activedescendant, not focus-moving: focus stays in the input so the
 *     user can keep typing while the highlight moves.
 *
 * DESIGN DECISIONS WORTH KNOWING
 * ------------------------------
 * 1. THE POPUP IS position:fixed AND APPENDED TO <body>.
 *    Every place this is used sits inside a modal whose body is
 *    `overflow-y: auto` (.custom-scrollbar in clients.php, the same in the
 *    order and PO modals). A popup rendered as a child of the row would be
 *    clipped by that scroll container the moment the list was longer than the
 *    remaining space — which is always, on the last row of a long order. Fixed
 *    positioning against the viewport is the only version that cannot be
 *    clipped. The cost is that it must be repositioned on scroll and resize;
 *    that is what the listeners at the bottom of open() do.
 *
 * 2. THE ORIGINAL FORM CONTROL SURVIVES, AS A HIDDEN INPUT.
 *    Existing code reads `row.querySelector('.prod-id').value` and
 *    `.so-prod-select`, and existing submit handlers walk `.item-row`. Rather
 *    than rewrite all of that and hope nothing was missed, mount() keeps a real
 *    <input type="hidden"> carrying the ORIGINAL element's class list, name and
 *    id. `.value` still returns the product id, `change` still fires and still
 *    bubbles. Any handler written against the old <select> keeps working.
 *
 * 3. THE CATALOGUE IS FETCHED ONCE PER PAGE, SHARED BY EVERY PICKER.
 *    A ten-line order used to be ten identical copies of the product list, one
 *    per <select>, built in a loop. Here the fetch is a promise memoised per
 *    (purpose, clientId); the tenth picker on the page costs one DOM subtree and
 *    zero requests. The endpoint is ETag'd on top of that, so re-opening the
 *    modal is a 304.
 *
 * 4. EMBALLAGES ARE HIDDEN, NOT ABSENT.
 *    We lend consignes against a deposit; we do not sell them. They are
 *    excluded from the default list, and a footer toggle reveals them for the
 *    rare quote that bills a deposit as an explicit line. They ship in the same
 *    payload so the toggle is instant — see the header of product_catalog.php.
 *
 * 5. IT DEGRADES, IT DOES NOT DIE.
 *    If the fetch fails the popup shows the error with a Réessayer button, and
 *    the hidden input keeps whatever value it already had. A picker that cannot
 *    load its list must not silently discard the line the user already picked.
 *
 * USAGE
 * -----
 *   Declarative — the common case. Any <select> or <input> with the attribute
 *   is upgraded on DOM ready, and again whenever one is inserted later (rows
 *   added by "Ajouter une ligne" are caught by the MutationObserver):
 *
 *       <select class="prod-id" data-lpc-product-picker></select>
 *       <select class="po-prod" data-lpc-product-picker="buy"></select>
 *
 *   Imperative — when you need the callback:
 *
 *       LPC.productPicker.mount(el, {
 *           purpose:  'sell',              // 'sell' (base_price) | 'buy' (cump)
 *           clientId: 42,                  // optional, for negotiated pricing
 *           placeholder: 'Choisir un produit…',
 *           allowEmpties: false,           // show the footer toggle at all
 *           onSelect(product, picker) { … } // product is null when cleared
 *       });
 *
 *   Any other list — same combobox over data you supply. Used by the Prix &
 *   Tarifs modal to pick a client. Items need `id` and `name`; `code`,
 *   `subtitle`, `category`, `price` and the stock fields are all optional and
 *   are rendered only when present, so a client row does not print "0 en stock":
 *
 *       LPC.picker.mountList(el, {
 *           items: metaData.clients,       // array, promise, or a function
 *           cacheKey: 'mdm-clients',       // share one fetch across pickers
 *           placeholder: 'Rechercher un client…',
 *           onSelect(item) { … }
 *       });
 *
 *   Events (both bubble, both fire on the hidden input):
 *       'change'             — for legacy listeners
 *       'lpc:product-change' — detail: { product, id }  (product null = cleared)
 *
 *   Housekeeping:
 *       LPC.productPicker.refresh()        // bust the cache after a master-data edit
 *       LPC.productPicker.get(el)          // the instance for a mounted element
 *       picker.setValue(id)                // programmatic select
 *       picker.destroy()
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    if (!window.LPC) window.LPC = {};
    if (window.LPC.productPicker && window.LPC.productPicker.__loaded) return;

    var ENDPOINT   = '/api/v1/product_catalog.php';
    var MAX_RENDER = 300;   // hard cap on rendered rows; see filter()
    var uid        = 0;

    // -------------------------------------------------------------------------
    // Small helpers. Deliberately not reaching for LPC.html here: this file
    // builds DOM nodes with textContent rather than innerHTML, so there is no
    // HTML-injection surface to escape in the first place. Product names come
    // from master data, which is admin-editable — treating them as text is the
    // cheap way to make that irrelevant.
    // -------------------------------------------------------------------------
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = String(text);
        return n;
    }

    function t(key, fallback) {
        // Every string has a French fallback baked in, so a missing dictionary
        // key degrades to French rather than printing "ui.picker.whatever".
        if (typeof window.LPC.t !== 'function') return fallback;
        var s = window.LPC.t(key);
        return (!s || s === key) ? fallback : s;
    }

    function fmtInt(n) {
        if (window.LPC.fmt && window.LPC.fmt.int) return window.LPC.fmt.int(n);
        var v = Number(n);
        return Number.isFinite(v) ? Math.round(v).toString() : '0';
    }

    /** Mirror of lpc_fold() in product_catalog.php, for the query side. */
    function fold(s) {
        s = String(s == null ? '' : s).toLowerCase().trim();
        // U+0300-U+036F is the combining-diacritics block. NFD decomposes an
        // accented letter into base + combining mark, and this strips the mark,
        // so "Petillante" matches "Pétillante". Written as \u escapes rather
        // than a literal range so the file survives any re-encoding.
        if (s.normalize) s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        return s.replace(/\s+/g, ' ');
    }

    // =========================================================================
    // CATALOGUE CACHE
    // -------------------------------------------------------------------------
    // Memoised per cache key. The stored value is the PROMISE, not the result,
    // so ten pickers mounting in the same tick share one in-flight request
    // instead of racing to start ten. A rejected promise is evicted, otherwise
    // one network blip would poison the picker for the life of the page.
    // =========================================================================
    var cache = Object.create(null);

    function cacheKey(opts) {
        if (opts.fetchItems) return 'custom|' + (opts.cacheKey || opts.sourceName || 'anon');
        return (opts.purpose || 'sell') + '|' + (opts.clientId || '');
    }

    /**
     * Fill in the fields the renderer expects but a custom source may not
     * supply. Only `id` and `name` are genuinely required of a caller; the rest
     * of the row (SKU, price, stock chip, consigne) is rendered only when
     * present, so the same component lists clients, suppliers or drivers
     * without pretending they have a stock level.
     */
    function normalise(items) {
        return (items || []).map(function (raw) {
            var p = {};
            for (var k in raw) if (Object.prototype.hasOwnProperty.call(raw, k)) p[k] = raw[k];
            p.id   = p.id != null ? p.id : p.value;
            p.name = p.name != null ? p.name : (p.label || p.title || String(p.id));
            if (p.category == null) p.category = '';
            if (p.category_sort == null) p.category_sort = 999;
            // Server-side fold is preferred (it has a real transliterator), but
            // a custom source can hand over plain rows and still search well.
            if (!p.search) {
                p.search = fold([p.code, p.name, p.format, p.category, p.subtitle]
                    .filter(Boolean).join(' '));
            }
            return p;
        });
    }

    function loadCatalog(opts) {
        var key = cacheKey(opts);
        if (cache[key]) return cache[key];

        var run;
        if (typeof opts.fetchItems === 'function') {
            // Custom source. Wrapped in Promise.resolve so a caller may hand
            // back a plain array, a promise, or a thenable.
            run = Promise.resolve().then(function () { return opts.fetchItems(); });
        } else {
            var url = ENDPOINT + '?purpose=' + encodeURIComponent(opts.purpose || 'sell');
            if (opts.clientId) url += '&client_id=' + encodeURIComponent(opts.clientId);

            run = fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            }).then(function (j) {
                if (!j || j.status !== 'success' || !Array.isArray(j.data)) {
                    throw new Error((j && j.message) || 'Réponse inattendue');
                }
                return j.data;
            });
        }

        cache[key] = run.then(normalise).catch(function (err) {
            delete cache[key];      // never cache a failure
            throw err;
        });

        return cache[key];
    }

    // =========================================================================
    // PICKER
    // =========================================================================
    function Picker(source, opts) {
        this.opts = opts || {};
        this.purpose = this.opts.purpose === 'buy' ? 'buy' : 'sell';
        this.id = 'lpc-pp-' + (++uid);

        // The source descriptor, built ONCE and reused by _load() and by the
        // error state's Réessayer button. It used to be rebuilt inline at each
        // call site as { purpose, clientId } — which silently dropped
        // fetchItems, so every mountList() picker quietly fell back to the
        // product catalogue and listed products where the caller asked for
        // clients.
        this._src = {
            purpose:    this.purpose,
            clientId:   this.opts.clientId,
            fetchItems: this.opts.fetchItems,
            cacheKey:   this.opts.cacheKey,
        };

        this.items         = [];      // full catalogue
        this.view          = [];      // what is currently rendered
        this.selected      = null;    // the chosen product object
        this.activeIndex   = -1;
        this.open          = false;
        this.loaded        = false;
        this.showEmpties   = false;
        this.query         = '';

        this._build(source);
        this._bind();

        // Preload immediately rather than on first open. The list is small, the
        // request is cached and usually a 304, and it means the first click
        // shows products instead of a spinner.
        this._load();
    }

    // -------------------------------------------------------------------------
    // DOM
    // -------------------------------------------------------------------------
    Picker.prototype._build = function (source) {
        var self = this;

        // The hidden input inherits the source element's identity so existing
        // querySelector(...).value calls keep resolving. See decision 2.
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        if (source.className) hidden.className = source.className;
        if (source.name)      hidden.name  = source.name;
        if (source.id)        hidden.id    = source.id;
        if (source.dataset) {
            Object.keys(source.dataset).forEach(function (k) {
                if (k !== 'lpcProductPicker') hidden.dataset[k] = source.dataset[k];
            });
        }
        hidden.value = '';
        this.hidden = hidden;

        var wrap = el('div', 'lpc-pp');
        wrap.dataset.lpcPpId = this.id;

        var field = el('div', 'lpc-pp__field');

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'lpc-pp__input';
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.placeholder = this.opts.placeholder ||
            t('ui.picker.placeholder', 'Choisir un produit…');
        // ARIA 1.2 combobox. aria-expanded is maintained in open()/close().
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-haspopup', 'listbox');
        input.setAttribute('aria-controls', this.id + '-list');
        if (source.getAttribute && source.getAttribute('aria-label')) {
            input.setAttribute('aria-label', source.getAttribute('aria-label'));
        }
        if (source.required) input.setAttribute('aria-required', 'true');
        this.input = input;

        // Clear button. Hidden until something is selected — an always-visible
        // × on an empty field is noise, and worse, it looks like a close button
        // for the modal behind it.
        var clear = el('button', 'lpc-pp__clear');
        clear.type = 'button';
        clear.setAttribute('aria-label', t('ui.picker.clear', 'Effacer la sélection'));
        clear.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
        clear.hidden = true;
        this.clearBtn = clear;

        var caret = el('span', 'lpc-pp__caret');
        caret.setAttribute('aria-hidden', 'true');
        caret.innerHTML = '<i class="fas fa-chevron-down"></i>';

        field.appendChild(input);
        field.appendChild(clear);
        field.appendChild(caret);
        wrap.appendChild(field);
        wrap.appendChild(hidden);

        // A second line under the field, shown only once a product is picked:
        // the SKU, the pack, the consigne that will follow. It is the
        // confirmation that the right thing was chosen — the input itself only
        // has room for the name.
        this.meta = el('div', 'lpc-pp__meta');
        this.meta.hidden = true;
        wrap.appendChild(this.meta);

        // ---- popup ----------------------------------------------------------
        var pop = el('div', 'lpc-pp__pop');
        pop.hidden = true;

        var list = el('div', 'lpc-pp__list');
        list.id = this.id + '-list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', t('ui.picker.list_label', 'Produits'));
        this.list = list;

        var status = el('div', 'lpc-pp__status');
        // polite, not assertive: the result count updating on every keystroke
        // must not interrupt what the screen reader is already saying.
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        this.status = status;

        var foot = el('div', 'lpc-pp__foot');
        this.foot = foot;

        pop.appendChild(status);
        pop.appendChild(list);
        pop.appendChild(foot);
        this.pop = pop;

        // Replace the source element in the DOM, keep everything it carried.
        source.parentNode.insertBefore(wrap, source);
        source.parentNode.removeChild(source);
        document.body.appendChild(pop);          // decision 1

        this.wrap = wrap;
        this.source = source;
        wrap.__lpcPicker = this;
        hidden.__lpcPicker = this;
        // Back-reference so the observer's reaper can find the owner of an
        // orphaned popup and tear it down. See watch().
        pop.__lpcOwner = this;

        // The footer's emballage toggle. Built once; shown only when the
        // catalogue actually contains empties AND the caller allows it.
        this.emptiesLabel = el('label', 'lpc-pp__toggle');
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'lpc-pp__toggle-cb';
        this.emptiesCb = cb;
        this.emptiesLabel.appendChild(cb);
        this.emptiesLabel.appendChild(
            el('span', null, t('ui.picker.show_empties', 'Afficher les emballages (consigne)'))
        );
        this.emptiesLabel.hidden = true;
        foot.appendChild(this.emptiesLabel);

        cb.addEventListener('change', function () {
            self.showEmpties = cb.checked;
            self.filter(self.query);
        });
    };

    // -------------------------------------------------------------------------
    // Events
    // -------------------------------------------------------------------------
    Picker.prototype._bind = function () {
        var self = this;

        this.input.addEventListener('focus', function () {
            if (!self._suppressFocusOpen) self.openPop();
        });
        this.input.addEventListener('click', function () { self.openPop(); });

        this.input.addEventListener('input', function () {
            // Typing over a selection invalidates it. The id is cleared as soon
            // as the text stops matching, so a half-typed query can never be
            // submitted as if it were the previously chosen product.
            if (self.selected && self.input.value !== self.selected.name) {
                self._commit(null, { silent: false, keepText: true });
            }
            self.query = self.input.value;
            self.openPop();
            self.filter(self.query);
        });

        this.input.addEventListener('keydown', function (e) { self._onKey(e); });

        this.clearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            self.clear();
            self.input.focus();
        });

        // mousedown, not click: click fires after blur, by which point the
        // popup would already be closing and the row gone from the DOM.
        this.list.addEventListener('mousedown', function (e) {
            var row = e.target.closest('.lpc-pp__opt');
            if (!row || row.getAttribute('aria-disabled') === 'true') return;
            e.preventDefault();
            var item = self.view[Number(row.dataset.idx)];
            if (item) self.select(item);
        });

        this.list.addEventListener('mousemove', function (e) {
            var row = e.target.closest('.lpc-pp__opt');
            if (!row) return;
            var i = Number(row.dataset.idx);
            if (i !== self.activeIndex) self._setActive(i, { scroll: false });
        });

        // Keep the popup open when the click lands inside it (the toggle, the
        // scrollbar, the retry button).
        this.pop.addEventListener('mousedown', function (e) { e.preventDefault(); });

        this.input.addEventListener('blur', function () {
            // A tick's grace so a mousedown inside the popup wins the race.
            setTimeout(function () {
                if (document.activeElement !== self.input) self.closePop();
            }, 0);
        });

        this._onDocDown = function (e) {
            if (!self.wrap.contains(e.target) && !self.pop.contains(e.target)) self.closePop();
        };
        document.addEventListener('mousedown', this._onDocDown, true);

        // Reposition rather than close on scroll: closing a popup because the
        // modal body scrolled a pixel under an inertial trackpad is maddening.
        this._onReflow = function () { if (self.open) self._place(); };
        window.addEventListener('scroll', this._onReflow, true);
        window.addEventListener('resize', this._onReflow);
    };

    Picker.prototype._onKey = function (e) {
        var k = e.key;

        if (k === 'ArrowDown' || k === 'ArrowUp') {
            e.preventDefault();
            if (!this.open) { this.openPop(); if (this.activeIndex >= 0) return; }
            this._move(k === 'ArrowDown' ? 1 : -1);
            return;
        }
        if (k === 'Home' && this.open) { e.preventDefault(); this._setActive(this._nextSelectable(-1, 1)); return; }
        if (k === 'End'  && this.open) { e.preventDefault(); this._setActive(this._nextSelectable(this.view.length, -1)); return; }

        if (k === 'Enter') {
            if (this.open && this.activeIndex >= 0 && this.view[this.activeIndex]) {
                // Only swallow Enter when it actually picks something —
                // otherwise let it submit the form, which is what a user
                // pressing Enter on a filled-in row expects.
                e.preventDefault();
                this.select(this.view[this.activeIndex]);
            }
            return;
        }
        if (k === 'Escape') {
            if (this.open) { e.preventDefault(); e.stopPropagation(); this.closePop(); }
            else if (this.input.value) { e.preventDefault(); e.stopPropagation(); this.clear(); }
            // When closed and empty, Escape belongs to the modal. Not ours.
            return;
        }
        if (k === 'Tab') { this.closePop(); }
    };

    Picker.prototype._move = function (dir) {
        var next = this._nextSelectable(this.activeIndex, dir);
        if (next >= 0) this._setActive(next);
    };

    /** Walk past group headers, which live in `view` as null placeholders. */
    Picker.prototype._nextSelectable = function (from, dir) {
        var i = from + dir;
        while (i >= 0 && i < this.view.length) {
            if (this.view[i]) return i;
            i += dir;
        }
        // Wrap, so ArrowDown on the last row returns to the first.
        i = dir > 0 ? 0 : this.view.length - 1;
        while (i >= 0 && i < this.view.length) {
            if (this.view[i]) return i;
            i += dir;
        }
        return -1;
    };

    Picker.prototype._setActive = function (i, o) {
        o = o || {};
        var rows = this.list.querySelectorAll('.lpc-pp__opt');
        for (var j = 0; j < rows.length; j++) rows[j].classList.remove('is-active');

        this.activeIndex = i;
        if (i < 0) { this.input.removeAttribute('aria-activedescendant'); return; }

        var row = this.list.querySelector('.lpc-pp__opt[data-idx="' + i + '"]');
        if (!row) return;
        row.classList.add('is-active');
        this.input.setAttribute('aria-activedescendant', row.id);

        if (o.scroll !== false) {
            var lr = this.list.getBoundingClientRect(), rr = row.getBoundingClientRect();
            // Manual, not scrollIntoView(): that scrolls every ancestor,
            // including the modal body, which yanks the whole dialog around.
            if (rr.top < lr.top)          this.list.scrollTop -= (lr.top - rr.top) + 4;
            else if (rr.bottom > lr.bottom) this.list.scrollTop += (rr.bottom - lr.bottom) + 4;
        }
    };

    // -------------------------------------------------------------------------
    // Data
    // -------------------------------------------------------------------------
    Picker.prototype._load = function () {
        var self = this;
        this.error = null;
        this._renderLoading();

        loadCatalog(this._src)
            .then(function (items) {
                self.items  = items;
                self.loaded = true;

                var anyEmpty = items.some(function (p) { return p.is_empty; });
                self.emptiesLabel.hidden = !(anyEmpty && self.opts.allowEmpties !== false);

                // A value may have been set before the catalogue arrived —
                // setValue() stashes it in _pending for exactly this moment.
                if (self._pending != null) {
                    var want = String(self._pending);
                    self._pending = null;
                    var hit = items.filter(function (p) { return String(p.id) === want; })[0];
                    if (hit) self._commit(hit, { silent: true });
                }
                self.filter(self.query);
            })
            .catch(function (err) {
                self.error = err;
                self._renderError();
            });
    };

    /**
     * Filter + group + render.
     *
     * Ranking, in order: exact SKU match, then name-prefix, then everything
     * else alphabetically inside its category. A rep who knows the SKU types it
     * and hits Enter; a rep who knows the brand types three letters and the
     * product they meant is at the top of its group.
     */
    Picker.prototype.filter = function (q) {
        if (!this.loaded) return;

        var tokens = fold(q).split(' ').filter(Boolean);
        var showEmpties = this.showEmpties;

        var hits = this.items.filter(function (p) {
            if (p.is_empty && !showEmpties) return false;
            if (!tokens.length) return true;
            return tokens.every(function (tok) { return p.search.indexOf(tok) !== -1; });
        });

        var qf = fold(q);
        if (qf) {
            hits.sort(function (a, b) {
                var as = rank(a), bs = rank(b);
                if (as !== bs) return as - bs;
                if (a.category_sort !== b.category_sort) return a.category_sort - b.category_sort;
                return a.name.localeCompare(b.name, 'fr');
            });
        }
        function rank(p) {
            if (fold(p.code) === qf) return 0;
            if (fold(p.code).indexOf(qf) === 0) return 1;
            if (fold(p.name).indexOf(qf) === 0) return 2;
            return 3;
        }

        // Build the flat view: group headers are null entries, so keyboard
        // navigation can skip them by testing truthiness and the index of a row
        // in `view` is the index of its DOM node. One array, no parallel maps.
        var view = [], nodes = [], lastCat = null;
        var capped = hits.length > MAX_RENDER;
        var shown = capped ? hits.slice(0, MAX_RENDER) : hits;

        for (var i = 0; i < shown.length; i++) {
            var p = shown[i];
            if (p.category !== lastCat) {
                lastCat = p.category;
                view.push(null);
                nodes.push(this._groupNode(p.category));
            }
            view.push(p);
            nodes.push(this._optNode(p, view.length - 1));
        }

        this.view = view;
        this.list.innerHTML = '';

        if (!shown.length) {
            this.list.appendChild(this._emptyNode(q));
        } else {
            var frag = document.createDocumentFragment();
            nodes.forEach(function (n) { frag.appendChild(n); });
            this.list.appendChild(frag);
        }

        var msg = shown.length
            ? fmtInt(hits.length) + ' ' + (hits.length > 1
                ? t('ui.picker.n_results', 'produits')
                : t('ui.picker.n_result',  'produit'))
            : t('ui.picker.none', 'Aucun produit');
        if (capped) msg += ' · ' + t('ui.picker.capped', 'affinez la recherche');
        this.status.textContent = msg;

        this.list.scrollTop = 0;
        // Preselect the first real row so Enter always has a target, but only
        // when a query is active — with an empty query, arming Enter on an
        // arbitrary first product invites mis-picks.
        this._setActive(q ? this._nextSelectable(-1, 1) : -1);
        if (this.open) this._place();
    };

    Picker.prototype._groupNode = function (label) {
        var g = el('div', 'lpc-pp__group', label);
        g.setAttribute('role', 'presentation');
        return g;
    };

    Picker.prototype._optNode = function (p, idx) {
        var row = el('div', 'lpc-pp__opt');
        row.id = this.id + '-o' + idx;
        row.dataset.idx = idx;
        row.setAttribute('role', 'option');
        row.setAttribute('aria-selected', this.selected && this.selected.id === p.id ? 'true' : 'false');
        if (this.selected && this.selected.id === p.id) row.classList.add('is-selected');
        if (p.is_empty) row.classList.add('is-empty-row');

        var main = el('div', 'lpc-pp__main');

        var line1 = el('div', 'lpc-pp__name');
        line1.appendChild(el('span', 'lpc-pp__name-txt', p.name));
        if (p.format) line1.appendChild(el('span', 'lpc-pp__fmt', p.format));
        if (p.is_empty) line1.appendChild(el('span', 'lpc-pp__tag', t('ui.picker.consigne', 'consigne')));
        main.appendChild(line1);

        // The whole second line is optional. A product row carries SKU, stock,
        // pack and consigne; a client or supplier row carries none of them, and
        // the component is used for both — so every part is rendered only when
        // the item actually has it, rather than printing "0 en stock" against a
        // customer.
        var line2 = el('div', 'lpc-pp__sub');
        if (p.code)     line2.appendChild(el('span', 'lpc-pp__sku', p.code));
        if (p.subtitle) line2.appendChild(el('span', 'lpc-pp__pack', p.subtitle));

        // Stock chip. Out-of-stock is a warning, not a block: quotes are
        // routinely written for stock we will have next week, and the empties
        // ledger means "0 on hand" is a normal steady state for some SKUs.
        if (p.stock_state) {
            var chip = el('span', 'lpc-pp__chip lpc-pp__chip--' + p.stock_state);
            if (p.stock_state === 'out') {
                chip.textContent = t('ui.picker.stock_out', 'rupture');
            } else if (p.stock_state === 'low') {
                chip.textContent = t('ui.picker.stock_low', 'stock bas') + ' · ' + fmtInt(p.stock_qty);
            } else {
                chip.textContent = fmtInt(p.stock_qty) + ' ' + t('ui.picker.in_stock', 'en stock');
            }
            line2.appendChild(chip);
        }

        if (p.units_per_pack > 1) {
            line2.appendChild(el('span', 'lpc-pp__pack',
                '×' + p.units_per_pack + ' / ' + (p.unit_of_measure || 'pack')));
        }
        if (p.consigne) {
            line2.appendChild(el('span', 'lpc-pp__consigne',
                '+ ' + t('ui.picker.consigne', 'consigne') + ' ' + fmtInt(p.consigne.price)));
        }
        if (line2.children.length) main.appendChild(line2);

        row.appendChild(main);
        if (p.price != null) {
            var price = el('div', 'lpc-pp__price', fmtInt(p.price));
            price.appendChild(el('span', 'lpc-pp__cur', ' FCFA'));
            row.appendChild(price);
        }
        return row;
    };

    Picker.prototype._emptyNode = function (q) {
        var box = el('div', 'lpc-pp__empty');
        box.appendChild(el('div', 'lpc-pp__empty-icon')).innerHTML =
            '<i class="fas fa-box-open" aria-hidden="true"></i>';
        box.appendChild(el('div', 'lpc-pp__empty-title',
            q ? t('ui.picker.no_match', 'Aucun produit ne correspond')
              : t('ui.picker.none', 'Aucun produit')));
        if (q) box.appendChild(el('div', 'lpc-pp__empty-sub', '« ' + q + ' »'));
        // The single most likely reason a search comes back empty here.
        if (q && !this.showEmpties && this.items.some(function (p) {
            return p.is_empty && p.search.indexOf(fold(q)) !== -1;
        })) {
            box.appendChild(el('div', 'lpc-pp__empty-hint',
                t('ui.picker.hint_empties', 'Un emballage correspond — activez la case ci-dessous.')));
        }
        return box;
    };

    Picker.prototype._renderLoading = function () {
        this.list.innerHTML = '';
        this.list.appendChild(el('div', 'lpc-pp__loading',
            t('ui.picker.loading', 'Chargement du catalogue…')));
        this.status.textContent = '';
    };

    Picker.prototype._renderError = function () {
        var self = this;
        this.list.innerHTML = '';
        var box = el('div', 'lpc-pp__error');
        box.appendChild(el('div', 'lpc-pp__error-title',
            t('ui.picker.error', 'Catalogue indisponible')));
        box.appendChild(el('div', 'lpc-pp__error-sub',
            t('ui.picker.error_sub', 'La liste des produits n\'a pas pu être chargée.')));
        var retry = el('button', 'lpc-pp__retry', t('ui.picker.retry', 'Réessayer'));
        retry.type = 'button';
        retry.addEventListener('click', function (e) {
            e.preventDefault();
            delete cache[cacheKey(self._src)];
            self._load();
        });
        box.appendChild(retry);
        this.list.appendChild(box);
        this.status.textContent = t('ui.picker.error', 'Catalogue indisponible');
    };

    // -------------------------------------------------------------------------
    // Open / close / position
    // -------------------------------------------------------------------------
    Picker.prototype.openPop = function () {
        if (this.open) return;
        this.open = true;
        this.pop.hidden = false;
        this.wrap.classList.add('is-open');
        this.input.setAttribute('aria-expanded', 'true');
        if (this.loaded) this.filter(this.query);
        this._place();
    };

    Picker.prototype.closePop = function () {
        if (!this.open) return;
        this.open = false;
        this.pop.hidden = true;
        this.wrap.classList.remove('is-open');
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
        this.activeIndex = -1;

        // Restore the committed product's name. Without this, a user who types
        // "sup" over a chosen product and then clicks away is left looking at
        // "sup" while the hidden input holds nothing — the line looks filled
        // and submits empty.
        this.input.value = this.selected ? this.selected.name : '';
        this.query = '';
    };

    Picker.prototype._place = function () {
        var r  = this.wrap.getBoundingClientRect();
        var vh = window.innerHeight;
        var below = vh - r.bottom - 8;
        var above = r.top - 8;

        // Flip up only when below is genuinely too small AND above is better —
        // a picker near the bottom of a tall modal otherwise flips on every
        // keystroke as the result count changes.
        var flip = below < 220 && above > below;
        var max  = Math.max(160, Math.min(360, flip ? above : below));

        this.pop.style.width = r.width + 'px';
        this.pop.style.left  = Math.max(8, Math.min(r.left, window.innerWidth - r.width - 8)) + 'px';
        this.pop.classList.toggle('is-flipped', flip);
        this.list.style.maxHeight = (max - 76) + 'px';   // minus status + footer

        if (flip) {
            this.pop.style.top    = 'auto';
            this.pop.style.bottom = (vh - r.top + 6) + 'px';
        } else {
            this.pop.style.bottom = 'auto';
            this.pop.style.top    = (r.bottom + 6) + 'px';
        }
    };

    // -------------------------------------------------------------------------
    // Selection
    // -------------------------------------------------------------------------
    Picker.prototype.select = function (p) {
        this._commit(p, { silent: false });
        // closePop() is immediately followed by focus() below, and focus()
        // fires the 'focus' listener bound in _bind(), which calls openPop()
        // — so without this guard every selection closed the popup and
        // reopened it in the same tick. The flag is only up for the duration
        // of this synchronous call.
        this._suppressFocusOpen = true;
        this.closePop();
        this.input.focus();
        this._suppressFocusOpen = false;
    };

    Picker.prototype.clear = function () {
        this._commit(null, { silent: false });
        this.filter('');
    };

    Picker.prototype._commit = function (p, o) {
        o = o || {};
        this.selected = p || null;
        this.hidden.value = p ? String(p.id) : '';

        if (!o.keepText) this.input.value = p ? p.name : '';
        this.clearBtn.hidden = !p;
        this.wrap.classList.toggle('has-value', !!p);

        // The meta line under the field.
        this.meta.innerHTML = '';
        if (p) {
            if (p.code) this.meta.appendChild(el('span', 'lpc-pp__meta-sku', p.code));
            if (p.format) this.meta.appendChild(el('span', null, p.format));
            if (p.subtitle) this.meta.appendChild(el('span', null, p.subtitle));
            if (p.price != null) {
                this.meta.appendChild(el('span', 'lpc-pp__meta-price',
                    fmtInt(p.price) + ' FCFA' + (p.sold_by === 'pack' ? ' / ' + t('ui.picker.pack', 'pack') : '')));
            }
            if (p.consigne) {
                this.meta.appendChild(el('span', 'lpc-pp__meta-consigne',
                    '+ ' + t('ui.picker.consigne', 'consigne') + ' ' + fmtInt(p.consigne.price) + ' FCFA'));
            }
            if (p.stock_state === 'out') {
                this.meta.appendChild(el('span', 'lpc-pp__meta-warn',
                    t('ui.picker.stock_out_warn', 'rupture de stock')));
            }
        }
        this.meta.hidden = !p;

        if (!o.silent) {
            // change first, for anything written against the old <select>.
            this.hidden.dispatchEvent(new Event('change', { bubbles: true }));
            this.hidden.dispatchEvent(new CustomEvent('lpc:product-change', {
                bubbles: true,
                detail: { product: p || null, id: p ? p.id : null, picker: this }
            }));
            if (typeof this.opts.onSelect === 'function') this.opts.onSelect(p || null, this);
        }
    };

    /** Programmatic selection. Safe to call before the catalogue has loaded. */
    Picker.prototype.setValue = function (id, o) {
        o = o || {};
        if (id == null || id === '') { this._commit(null, { silent: o.silent !== false }); return; }
        if (!this.loaded) { this._pending = id; this.hidden.value = String(id); return; }
        var hit = this.items.filter(function (p) { return String(p.id) === String(id); })[0];
        this._commit(hit || null, { silent: o.silent !== false });
    };

    Picker.prototype.getValue   = function () { return this.hidden.value || ''; };
    Picker.prototype.getProduct = function () { return this.selected; };

    /** Idempotent — the observer's reaper and an explicit caller may both hit it. */
    Picker.prototype.destroy = function () {
        if (this._destroyed) return;
        this._destroyed = true;
        document.removeEventListener('mousedown', this._onDocDown, true);
        window.removeEventListener('scroll', this._onReflow, true);
        window.removeEventListener('resize', this._onReflow);
        if (this.pop.parentNode)  this.pop.parentNode.removeChild(this.pop);
        if (this.wrap.parentNode) this.wrap.parentNode.removeChild(this.wrap);
        this.pop.__lpcOwner = null;
    };

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    function mount(target, opts) {
        var node = (typeof target === 'string') ? document.querySelector(target) : target;
        if (!node) return null;
        if (node.__lpcPicker) return node.__lpcPicker;      // idempotent

        opts = opts || {};
        if (!opts.purpose && node.dataset && node.dataset.lpcProductPicker) {
            var v = node.dataset.lpcProductPicker;
            if (v === 'buy' || v === 'sell') opts.purpose = v;
        }
        if (!opts.placeholder && node.dataset && node.dataset.placeholder) {
            opts.placeholder = node.dataset.placeholder;
        }
        if (opts.clientId == null && node.dataset && node.dataset.clientId) {
            opts.clientId = node.dataset.clientId;
        }

        var preset = node.value || (node.dataset ? node.dataset.value : '') || '';
        var p = new Picker(node, opts);
        if (preset) p.setValue(preset, { silent: true });
        return p;
    }

    function upgradeAll(root) {
        (root || document).querySelectorAll('[data-lpc-product-picker]').forEach(function (n) {
            if (!n.__lpcPicker && n.tagName !== 'DIV') mount(n, {});
        });
    }

    // Rows are added dynamically by every one of the three editors
    // ("Ajouter une ligne" appends a row), so a one-shot pass on
    // DOMContentLoaded would upgrade the first row and nothing after it.
    //
    // The observer also handles REMOVAL, which matters more than it looks.
    // Because the popup is appended to <body> (decision 1), it is not a
    // descendant of the row — so `container.innerHTML = ''`, which is how all
    // three modals reset themselves, destroys the field and leaves the popup
    // orphaned on <body> along with its document and window listeners. Do that
    // once per modal open and the page slowly fills with invisible popups that
    // still reposition themselves on every scroll event.
    //
    // Making cleanup automatic here means no caller has to remember it. The
    // explicit destroy() calls in the row-remove handlers are still correct;
    // they just are not load-bearing any more.
    function reapDetached() {
        document.querySelectorAll('.lpc-pp__pop').forEach(function (pop) {
            var p = pop.__lpcOwner;
            if (p && p.wrap && !document.body.contains(p.wrap)) p.destroy();
        });
    }

    function watch() {
        upgradeAll(document);
        if (!window.MutationObserver) return;
        new MutationObserver(function (muts) {
            var removed = false;
            for (var i = 0; i < muts.length; i++) {
                var added = muts[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var n = added[j];
                    if (n.nodeType !== 1) continue;
                    if (n.matches && n.matches('[data-lpc-product-picker]')) mount(n, {});
                    if (n.querySelectorAll) upgradeAll(n);
                }
                if (muts[i].removedNodes.length) removed = true;
            }
            if (removed) reapDetached();
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', watch);
    } else {
        watch();
    }

    window.LPC.productPicker = {
        __loaded: true,
        mount: mount,
        /**
         * Convenience for a custom-source picker. Everything the product
         * version does — type-to-filter, keyboard nav, grouping, the hidden
         * input contract — over any list you hand it.
         *
         *   LPC.picker.mountList(el, {
         *       items: [{ id, name, code?, subtitle?, category?, price? }],
         *       cacheKey: 'clients',        // share one fetch across pickers
         *       placeholder: 'Choisir un client…',
         *       onSelect(item) { … }
         *   });
         *
         * `items` may be an array, a promise, or a function returning either.
         */
        mountList: function (elm, opts) {
            opts = opts || {};
            var src = opts.items;
            return mount(elm, Object.assign({}, opts, {
                allowEmpties: false,
                fetchItems: typeof src === 'function' ? src : function () { return src; }
            }));
        },
        upgradeAll: upgradeAll,
        get: function (elm) {
            var n = (typeof elm === 'string') ? document.querySelector(elm) : elm;
            return n ? (n.__lpcPicker || null) : null;
        },
        /** Call after a master-data edit so open pickers pick up new prices. */
        refresh: function () {
            Object.keys(cache).forEach(function (k) { delete cache[k]; });
            document.querySelectorAll('.lpc-pp').forEach(function (w) {
                if (w.__lpcPicker) w.__lpcPicker._load();
            });
        }
    };

    // The component is not products-only any more (see mountList), so it gets
    // a name that says so. LPC.productPicker stays as the name every existing
    // call site already uses.
    window.LPC.picker = window.LPC.productPicker;
})();
