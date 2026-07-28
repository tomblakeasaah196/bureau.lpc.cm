/**
 * assets/js/modules/crm-proposal_studio.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7E · Proposal Studio.
 *
 * Drives modules/crm/proposal_studio.php: tab switching, dirty tracking,
 * bulk save, per-field reset, logo upload.
 *
 * Only changed fields are sent on save. The page carries ~90 bilingual fields;
 * posting all of them on every save would stamp updated_by/updated_at on rows
 * nobody touched and make the audit trail useless for answering "who changed
 * the payment terms?".
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    // ---- Page data ----------------------------------------------------------
    var PAGE = {};
    try {
        PAGE = JSON.parse(document.getElementById('lpc-page-data').textContent) || {};
    } catch (e) {
        PAGE = {};
    }
    var ENDPOINT   = PAGE.endpoint || '/api/v1/proposal_template_controller.php';
    var CSRF_TOKEN = PAGE.csrf || '';
    var CSRF_FIELD = PAGE.csrfField || '_csrf';

    // ---- Dirty tracking -----------------------------------------------------
    // Baseline is captured at load; a field is dirty only while it differs from
    // it. Typing a change and undoing it correctly clears the flag.
    var baseline = new Map();
    var dirty    = new Set();

    function fieldId(key, lang) { return key + '|' + lang; }

    function captureBaseline() {
        document.querySelectorAll('.pt-field').forEach(function (row) {
            var key = row.dataset.key;
            row.querySelectorAll('.pt-val').forEach(function (input) {
                baseline.set(fieldId(key, input.dataset.lang), input.value);
            });
        });
    }

    function refreshDirtyBadge() {
        var el = document.getElementById('pt-dirty-count');
        if (!el) return;
        var n = new Set(Array.from(dirty).map(function (id) { return id.split('|')[0]; })).size;
        el.textContent = n === 0 ? '' : n + ' champ(s) non enregistré(s)';
    }

    function markDirty(input) {
        var row = input.closest('.pt-field');
        if (!row) return;
        var id = fieldId(row.dataset.key, input.dataset.lang);
        if (input.value === baseline.get(id)) {
            dirty.delete(id);
            input.classList.remove('dirty');
        } else {
            dirty.add(id);
            input.classList.add('dirty');
        }
        refreshDirtyBadge();
    }

    // ---- Character budget ---------------------------------------------------
    // The proposal pages are fixed-height with overflow:hidden — text that runs
    // long is clipped, not reflowed, and the person editing has no way to see
    // that from this screen. The counter is the only feedback they get, so it
    // shows the target range, not just a number.
    function updateCount(input) {
        var counter = input.parentNode.querySelector('.pt-count');
        if (!counter) return;

        var len = input.value.length;
        var min = parseInt(input.dataset.min || '0', 10);
        var max = parseInt(input.dataset.max || '0', 10);

        counter.textContent = len + ' / ' + max;
        counter.classList.remove('warn', 'over', 'under');
        input.classList.remove('over');

        if (len > max) {
            // Reachable by paste on some browsers, and by any non-browser client.
            counter.classList.add('over');
            input.classList.add('over');
            counter.textContent = len + ' / ' + max + ' — trop long';
        } else if (len === max || len > max * 0.95) {
            counter.classList.add('warn');
        } else if (min > 0 && len < min) {
            // Not an error. Much shorter than the design length leaves a visible
            // gap on the page rather than breaking it, so this is advisory only.
            counter.classList.add('under');
            counter.textContent = len + ' / ' + max + ' — court (min conseillé ' + min + ')';
        }
    }

    function initCounters() {
        document.querySelectorAll('.pt-val').forEach(function (input) {
            if (!input.dataset.max) return;
            if (input.parentNode.querySelector('.pt-count')) return;
            var span = document.createElement('span');
            span.className = 'pt-count';
            input.parentNode.appendChild(span);
            updateCount(input);
        });
    }

    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('pt-val')) {
            markDirty(e.target);
            updateCount(e.target);
        }
    });

    // Leaving with unsaved edits is almost always an accident on a form this long.
    window.addEventListener('beforeunload', function (e) {
        if (dirty.size === 0) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ---- Tabs ---------------------------------------------------------------
    // Active tab is the one WITHOUT `border-transparent` — the shared shell
    // convention (README §5.5). Deviating breaks the segmented pill styling.
    document.querySelectorAll('.pt-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.pt-tab').forEach(function (t) {
                t.classList.add('border-transparent', 'text-gray-500');
                t.classList.remove('text-lpc-dark');
                t.setAttribute('aria-selected', 'false');
            });
            tab.classList.remove('border-transparent', 'text-gray-500');
            tab.classList.add('text-lpc-dark');
            tab.setAttribute('aria-selected', 'true');

            document.querySelectorAll('.pt-panel').forEach(function (p) { p.hidden = true; });
            var panel = document.getElementById('panel-' + tab.dataset.tab);
            if (panel) panel.hidden = false;
        });
    });

    // ---- Networking ---------------------------------------------------------
    function post(params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        body.append(CSRF_FIELD, CSRF_TOKEN);

        return fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
            credentials: 'same-origin',
            body: body
        }).then(function (r) {
            return r.json().catch(function () {
                throw new Error('Réponse inattendue du serveur (' + r.status + ').');
            }).then(function (j) {
                if (!r.ok || j.status === 'error') throw new Error(j.message || 'Erreur serveur.');
                return j;
            });
        });
    }

    function notify(msg, isError) {
        if (window.LPC && LPC.modal && LPC.modal.alert) {
            LPC.modal.alert(msg, { title: isError ? 'Erreur' : 'Studio Proposition' });
        } else {
            // lpc-modal.js is loaded by head_assets.php; this is belt-and-braces
            // so a save result is never silently swallowed.
            window.alert(msg);
        }
    }

    // ---- Save ---------------------------------------------------------------
    var saveBtn = document.getElementById('pt-save');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            if (dirty.size === 0) { notify('Aucune modification à enregistrer.'); return; }

            var fields = {};
            dirty.forEach(function (id) {
                var key = id.split('|')[0];
                if (fields[key]) return;
                var row = document.querySelector('.pt-field[data-key="' + key + '"]');
                if (!row) return;
                var fr = row.querySelector('.pt-val[data-lang="fr"]');
                var en = row.querySelector('.pt-val[data-lang="en"]');
                // Both languages always travel together, even if only one
                // changed — the server writes the pair as a unit.
                fields[key] = { fr: fr ? fr.value : '', en: en ? en.value : '' };
            });

            saveBtn.disabled = true;
            var original = saveBtn.innerHTML;
            saveBtn.textContent = 'Enregistrement…';

            post({ action: 'save', fields: JSON.stringify(fields) })
                .then(function (j) {
                    var refused = (j.too_long || []).concat(j.skipped || []);
                    dirty.forEach(function (id) {
                        var parts = id.split('|');
                        if (refused.indexOf(parts[0]) !== -1) return;   // keep it dirty
                        var row = document.querySelector('.pt-field[data-key="' + parts[0] + '"]');
                        if (!row) return;
                        var input = row.querySelector('.pt-val[data-lang="' + parts[1] + '"]');
                        if (input) { baseline.set(id, input.value); input.classList.remove('dirty'); }
                    });
                    // Refused fields deliberately stay in `dirty` so the save
                    // button remains live and the edit is not lost.
                    Array.from(dirty).forEach(function (id) {
                        if (refused.indexOf(id.split('|')[0]) === -1) dirty.delete(id);
                    });
                    refreshDirtyBadge();

                    var msg = j.message || 'Enregistré.';
                    if (j.skipped && j.skipped.length) {
                        msg += ' ' + j.skipped.length + ' champ(s) ignoré(s) (clé inconnue).';
                    }
                    // A rejected field keeps its edit in the box and stays
                    // dirty, so the person can shorten it and save again
                    // rather than losing what they typed.
                    if (j.too_long && j.too_long.length) {
                        j.too_long.forEach(function (key) {
                            var row = document.querySelector('.pt-field[data-key="' + key + '"]');
                            if (!row) return;
                            row.scrollIntoView({ block: 'center' });
                            row.querySelectorAll('.pt-val').forEach(updateCount);
                        });
                    }
                    notify(msg, !!(j.too_long && j.too_long.length));
                })
                .catch(function (err) { notify(err.message, true); })
                .finally(function () {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = original;
                });
        });
    }

    // ---- Per-field reset ----------------------------------------------------
    document.querySelectorAll('.pt-reset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = btn.dataset.key;
            post({ action: 'reset_field', key: key })
                .then(function (j) {
                    var row = document.querySelector('.pt-field[data-key="' + key + '"]');
                    if (!row) return;
                    var fr = row.querySelector('.pt-val[data-lang="fr"]');
                    var en = row.querySelector('.pt-val[data-lang="en"]');
                    if (fr) { fr.value = j.value_fr; baseline.set(fieldId(key, 'fr'), j.value_fr); fr.classList.remove('dirty'); }
                    if (en) { en.value = j.value_en; baseline.set(fieldId(key, 'en'), j.value_en); en.classList.remove('dirty'); }
                    dirty.delete(fieldId(key, 'fr'));
                    dirty.delete(fieldId(key, 'en'));
                    refreshDirtyBadge();

                    if (fr) updateCount(fr);
                    if (en) updateCount(en);

                    var prev = row.querySelector('.pt-logo-prev');
                    if (prev && j.value_fr) prev.src = j.value_fr;

                    var badge = row.querySelector('.pt-badge');
                    if (badge) badge.remove();
                })
                .catch(function (err) { notify(err.message, true); });
        });
    });

    // ---- Reset everything ---------------------------------------------------
    var resetAll = document.getElementById('pt-reset-all');
    if (resetAll) {
        resetAll.addEventListener('click', function () {
            var ask = (window.LPC && LPC.modal && LPC.modal.confirm)
                ? LPC.modal.confirm('Restaurer TOUT le modèle à ses valeurs d\'origine ? Cette action est irréversible.')
                : Promise.resolve(window.confirm('Restaurer TOUT le modèle ? Irréversible.'));

            Promise.resolve(ask).then(function (ok) {
                if (!ok) return;
                return post({ action: 'reset_all', confirm: 'RESET' }).then(function () {
                    dirty.clear();                 // stop beforeunload blocking the reload
                    window.location.reload();
                });
            }).catch(function (err) { notify(err.message, true); });
        });
    }

    // ---- Logo upload --------------------------------------------------------
    document.querySelectorAll('.pt-upload').forEach(function (input) {
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;

            var key   = input.dataset.key;
            var fname = document.querySelector('.pt-field[data-key="' + key + '"] .pt-upload-name');
            if (fname) fname.textContent = 'Envoi de ' + file.name + '…';

            var fd  = new FormData();
            fd.append('action', 'upload_image');
            fd.append('key', key);
            fd.append('image', file);
            fd.append(CSRF_FIELD, CSRF_TOKEN);

            fetch(ENDPOINT, {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF_TOKEN },   // no Content-Type: the browser sets the multipart boundary
                credentials: 'same-origin',
                body: fd
            })
            .then(function (r) { return r.json().then(function (j) {
                if (!r.ok || j.status === 'error') throw new Error(j.message || 'Échec du téléversement.');
                return j;
            }); })
            .then(function (j) {
                var row = document.querySelector('.pt-field[data-key="' + key + '"]');
                if (!row) return;
                var path = row.querySelector('.pt-val[data-lang="fr"]');
                var prev = row.querySelector('.pt-logo-prev');
                // The server already persisted the path, so the baseline moves
                // with it — the field must not come back marked dirty.
                if (path) { path.value = j.path; baseline.set(fieldId(key, 'fr'), j.path); path.classList.remove('dirty'); }
                dirty.delete(fieldId(key, 'fr'));
                refreshDirtyBadge();
                if (prev) prev.src = j.path;
                if (path) updateCount(path);
                if (fname) fname.textContent = file.name + ' — enregistré';
                notify('Image téléversée.');
            })
            .catch(function (err) {
                if (fname) fname.textContent = 'PNG, JPEG ou WebP — 2 Mo max';
                notify(err.message, true);
            })
            .finally(function () { input.value = ''; });
        });
    });

    // ---- Preview ------------------------------------------------------------
    // The toolbar link needs a real proposal token to render anything. Rather
    // than ship a fake one, resolve the most recent quote at click time.
    var preview = document.getElementById('pt-preview');
    if (preview) {
        preview.addEventListener('click', function (e) {
            if (dirty.size === 0) return;   // nothing pending, let it through
            e.preventDefault();
            notify('Enregistrez vos modifications avant de générer l\'aperçu.');
        });
    }

    captureBaseline();
    initCounters();
    refreshDirtyBadge();
})();
