/**
 * assets/js/modules/mail-mailboxes.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Boîtes mail (modules/mail/mailboxes.php; PR 1).
 *
 * Opens the page's native <dialog>s, sends their forms to
 * api/v1/mail/mailboxes.php (JSON, X-CSRF-Token) and puts the server's answer
 * next to the field it is about. After a change the page reloads, so the list
 * is always the server's; the success message survives the reload through
 * sessionStorage. Passwords are read from the field at submit time and cleared
 * as soon as the request is sent; nothing keeps them.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var DATA = {};
    try { DATA = JSON.parse(document.getElementById('mail-page-data').textContent || '{}'); } catch (e) { DATA = {}; }
    var API = DATA.api || '/api/v1/mail/mailboxes.php';
    var TOAST_KEY = 'lpc-mail-toast';

    function boxById(id) {
        id = Number(id);
        return (DATA.boxes || []).filter(function (b) { return b.id === id; })[0] || null;
    }

    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || {};
        return c.token || '';
    }

    function toast(message, kind) {
        if (window.LPC && window.LPC.toast) {
            (window.LPC.toast[kind || 'success'] || window.LPC.toast)(message);
        }
    }

    function api(method, body, query) {
        var url = API + (query ? '?' + query : '');
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = csrf();
            opts.body = JSON.stringify(body || {});
        }
        return fetch(url, opts).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (json) {
                if (!res.ok || json.status !== 'success') {
                    var err = new Error(json.message || 'Bureau n’a pas pu terminer. Réessayez dans un instant.');
                    err.field = json.field || null;
                    throw err;
                }
                return json;
            });
        }, function () {
            throw new Error('Pas de connexion avec bureau. Vérifiez le réseau, puis réessayez.');
        });
    }

    function reloadWith(message, boxId) {
        try { sessionStorage.setItem(TOAST_KEY, message); } catch (e) { /* private mode: no message after reload */ }
        var url = location.pathname + (boxId ? '?box=' + encodeURIComponent(boxId) : '');
        location.replace(url);
    }

    // ------------------------------------------------------------------ field errors

    function clearErrors(scope) {
        scope.querySelectorAll('.mb-field-error, .mb-form-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        scope.querySelectorAll('[aria-invalid="true"]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
    }

    function showError(scope, message, field) {
        var slot = field ? scope.querySelector('[data-error-for="' + field + '"]') : null;
        if (slot) {
            slot.textContent = message;
            slot.hidden = false;
            var details = slot.closest('details');
            if (details) details.open = true;
            var input = slot.parentElement.querySelector('input, select');
            if (input) {
                input.setAttribute('aria-invalid', 'true');
                if (slot.id === '') slot.id = (input.id || 'f') + '-err';
                input.setAttribute('aria-describedby', slot.id);
                input.focus();
            }
            return;
        }
        var general = scope.querySelector('.mb-form-error');
        if (general) { general.textContent = message; general.hidden = false; }
    }

    // Blur-first: a required field left empty says so when you leave it, and the message goes as you type
    var REQUIRED = {
        email: 'Saisissez l’adresse complète, par exemple prenom.nom@lpc.cm.',
        password: 'Saisissez le mot de passe de la boîte mail.',
        display_name: 'Saisissez le nom que les destinataires verront.'
    };
    document.addEventListener('focusout', function (ev) {
        var input = ev.target;
        if (!input.matches || !input.matches('.mb-dialog input[required]')) return;
        if (input.value.trim() !== '') return;
        var slot = input.parentElement.querySelector('.mb-field-error');
        if (slot && REQUIRED[input.name]) {
            slot.textContent = REQUIRED[input.name];
            slot.hidden = false;
            input.setAttribute('aria-invalid', 'true');
        }
    });
    document.addEventListener('input', function (ev) {
        var input = ev.target;
        if (!input.matches || !input.matches('.mb-dialog input, .mb-dialog select')) return;
        var slot = input.parentElement.querySelector('.mb-field-error');
        if (slot && !slot.hidden) { slot.hidden = true; slot.textContent = ''; }
        input.removeAttribute('aria-invalid');
    });

    // ------------------------------------------------------------------ dialogs

    function openDialog(id, box) {
        var dlg = document.getElementById(id);
        if (!dlg) return null;
        clearErrors(dlg);
        dlg.dataset.box = box ? String(box.id) : '';
        dlg.querySelectorAll('[data-fill="email"]').forEach(function (n) { n.textContent = box ? box.email : ''; });
        var form = dlg.querySelector('form[data-api]');
        if (form && form.dataset.api !== 'connect_personal' && form.dataset.api !== 'add_shared') {
            form.reset();
        }
        if (box && box.settings) {
            Object.keys(box.settings).forEach(function (k) {
                var input = dlg.querySelector('[name="' + k + '"]');
                if (input) input.value = box.settings[k];
            });
        }
        if (id === 'dlg-rename' && box) {
            dlg.querySelector('[name="display_name"]').value = box.display_name;
        }
        dlg.querySelectorAll('input[type="password"]').forEach(function (i) { i.value = ''; });
        dlg.showModal();
        var first = dlg.querySelector('input:not([type="hidden"]):not([value=""]), input');
        var emptyRequired = Array.prototype.filter.call(dlg.querySelectorAll('input[required]'), function (i) { return i.value === ''; })[0];
        (emptyRequired || first || dlg.querySelector('button')).focus();
        return dlg;
    }

    document.addEventListener('click', function (ev) {
        var opener = ev.target.closest('[data-open]');
        if (opener) { openDialog(opener.getAttribute('data-open'), null); return; }

        var closer = ev.target.closest('[data-close]');
        if (closer) { closer.closest('dialog').close(); return; }

        var action = ev.target.closest('[data-action]');
        if (!action) return;
        var row = action.closest('[data-box]');
        var box = row ? boxById(row.getAttribute('data-box')) : null;
        if (!box) return;
        switch (action.getAttribute('data-action')) {
            case 'password': openDialog('dlg-password', box); break;
            case 'rename':   openDialog('dlg-rename', box); break;
            case 'remove':   openDialog('dlg-remove', box); break;
            case 'people':   openPeople(box); break;
            case 'sync':     syncNow(action, box); break;
        }
    });

    // A click on the dimmed backdrop closes a dialog (the dialog itself is the backdrop's target)
    document.querySelectorAll('dialog.mb-dialog').forEach(function (dlg) {
        dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
        dlg.addEventListener('close', function () {
            if (dlg.id === 'dlg-people' && dlg.dataset.changed === '1') reloadWith('Accès mis à jour.', dlg.dataset.box);
        });
    });

    // ------------------------------------------------------------------ forms

    var BUSY = {
        connect_personal: 'Vérification auprès du serveur…',
        add_shared: 'Vérification auprès du serveur…',
        update_password: 'Vérification auprès du serveur…',
        rename: 'Enregistrement…',
        remove: 'Retrait…'
    };

    document.querySelectorAll('form[data-api]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var dlg = form.closest('dialog');
            clearErrors(form);
            var action = form.dataset.api;
            var body = { action: action };
            if (dlg && dlg.dataset.box) body.box = Number(dlg.dataset.box);

            ['email', 'password', 'display_name'].forEach(function (name) {
                var input = form.querySelector('[name="' + name + '"]');
                if (input) body[name] = input.value;
            });
            // Required fields first, in the browser, so a slow server check is never spent on an empty form
            var missing = Array.prototype.filter.call(form.querySelectorAll('input[required]'), function (i) { return i.value.trim() === ''; })[0];
            if (missing) { showError(form, REQUIRED[missing.name] || 'Ce champ est requis.', missing.name); return; }

            if (form.querySelector('details')) {
                body.settings = {};
                ['imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption'].forEach(function (k) {
                    var input = form.querySelector('[name="' + k + '"]');
                    if (input) body.settings[k] = /port$/.test(k) ? Number(input.value) : input.value;
                });
            }

            var submit = form.querySelector('[type="submit"]');
            var label = submit.textContent;
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            submit.textContent = BUSY[action] || 'Un instant…';

            api('POST', body).then(function (json) {
                form.querySelectorAll('input[type="password"]').forEach(function (i) { i.value = ''; });
                reloadWith(json.message, action === 'remove' ? null : (json.box || body.box));
            }, function (err) {
                submit.disabled = false;
                submit.removeAttribute('aria-busy');
                submit.textContent = label;
                showError(form, err.message, err.field);
            });
            body.password = undefined;
        });
    });

    function syncNow(button, box) {
        var label = button.textContent;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.textContent = 'Actualisation…';
        api('POST', { action: 'sync_now', box: box.id }).then(function (json) {
            reloadWith(json.message, box.id);
        }, function (err) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = label;
            toast(err.message, 'error');
        });
    }

    // ------------------------------------------------------------------ people

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    function openPeople(box) {
        var dlg = openDialog('dlg-people', box);
        if (!dlg) return;
        dlg.dataset.changed = '';
        loadPeople(dlg, box);
    }

    function loadPeople(dlg, box) {
        var list = dlg.querySelector('[data-people]');
        list.replaceChildren(el('li', 'mb-people-loading', 'Chargement…'));
        api('GET', null, 'action=members&box=' + encodeURIComponent(box.id)).then(function (json) {
            renderPeople(dlg, box, json.data);
        }, function (err) {
            list.replaceChildren();
            showError(dlg, err.message, null);
        });
    }

    function renderPeople(dlg, box, data) {
        var list = dlg.querySelector('[data-people]');
        list.replaceChildren();
        (data.members || []).forEach(function (m) {
            var li = el('li', 'mb-person');
            var who = el('div', 'mb-person-who');
            who.appendChild(el('span', 'mb-person-name', m.name + (m.user_id === data.me ? ' (vous)' : '')));
            who.appendChild(el('span', 'mb-person-mail', m.email + (m.active ? '' : ' · compte désactivé')));
            li.appendChild(who);

            if (data.can_manage) {
                var controls = el('div', 'mb-person-controls');
                var select = el('select', 'mb-select');
                select.setAttribute('aria-label', 'Niveau de ' + m.name);
                ['read', 'send', 'manage'].forEach(function (lv) {
                    var o = el('option', null, (DATA.levels || {})[lv] || lv);
                    o.value = lv;
                    if (lv === m.level) o.selected = true;
                    select.appendChild(o);
                });
                select.addEventListener('change', function () {
                    var previous = m.level;
                    clearErrors(dlg);
                    api('POST', { action: 'member_change', box: box.id, member_id: m.id, level: select.value }).then(function (json) {
                        m.level = select.value;
                        dlg.dataset.changed = '1';
                        toast(json.message);
                    }, function (err) {
                        select.value = previous;
                        showError(dlg, err.message, null);
                    });
                });
                var remove = el('button', 'mb-btn mb-btn--danger-ghost mb-btn--sm', 'Retirer');
                remove.type = 'button';
                remove.setAttribute('aria-label', 'Retirer l’accès de ' + m.name);
                remove.addEventListener('click', function () {
                    clearErrors(dlg);
                    remove.disabled = true;
                    api('POST', { action: 'member_remove', box: box.id, member_id: m.id }).then(function (json) {
                        dlg.dataset.changed = '1';
                        toast(json.message);
                        loadPeople(dlg, box);
                    }, function (err) {
                        remove.disabled = false;
                        showError(dlg, err.message, null);
                    });
                });
                controls.appendChild(select);
                controls.appendChild(remove);
                li.appendChild(controls);
            } else {
                li.appendChild(el('span', 'mb-pill mb-pill--level', (DATA.levels || {})[m.level] || m.level));
            }
            list.appendChild(li);
        });
        if (!(data.members || []).length) {
            list.appendChild(el('li', 'mb-people-loading', 'Personne pour l’instant.'));
        }

        var invite = dlg.querySelector('[data-invite]');
        invite.hidden = !data.can_manage;
        if (!data.can_manage) return;
        var who = invite.querySelector('[name="user_id"]');
        who.replaceChildren();
        var placeholder = el('option', null, (data.invitable || []).length ? 'Choisir une personne…' : 'Tout le monde a déjà accès');
        placeholder.value = '';
        who.appendChild(placeholder);
        (data.invitable || []).forEach(function (p) {
            var o = el('option', null, p.name + (p.job_title ? ' · ' + p.job_title : '') + ' — ' + p.email);
            o.value = String(p.id);
            who.appendChild(o);
        });
        invite.onsubmit = function (ev) {
            ev.preventDefault();
            clearErrors(dlg);
            if (!who.value) { showError(invite, 'Choisissez la personne à qui donner l’accès.', 'invite_user'); return; }
            var btn = invite.querySelector('[type="submit"]');
            btn.disabled = true;
            api('POST', { action: 'member_add', box: box.id, user_id: Number(who.value), level: invite.querySelector('[name="level"]').value }).then(function (json) {
                btn.disabled = false;
                dlg.dataset.changed = '1';
                toast(json.message);
                loadPeople(dlg, box);
            }, function (err) {
                btn.disabled = false;
                showError(invite, err.message, err.field);
            });
        };
    }

    // ------------------------------------------------------------------ on load

    function onReady() {
        var message = null;
        try { message = sessionStorage.getItem(TOAST_KEY); sessionStorage.removeItem(TOAST_KEY); } catch (e) { message = null; }
        if (message) setTimeout(function () { toast(message); }, 50);

        var focus = DATA.focusBox ? document.getElementById('box-' + DATA.focusBox) : null;
        if (focus) {
            focus.scrollIntoView({ block: 'center' });
            var box = boxById(DATA.focusBox);
            if (box && box.status === 'needs_reconnect' && box.can_manage && !message) openDialog('dlg-password', box);
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady); else onReady();
})();
