/**
 * assets/js/lpc-usermenu.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 9 · the sidebar account menu and its two modals.
 *
 * WHAT IT OWNS
 *   · The pop-up menu on the sidebar footer trigger (open/close, roving
 *     keyboard focus, outside-click, Escape).
 *   · The quick strip inside it — language and theme, applied optimistically
 *     and persisted in the background.
 *   · "My profile"       → a tabbed modal: Profile · Preferences · Security.
 *   · "Change password"  → a focused modal with a live rules checklist.
 *   · "Sign out"         → confirm, then navigate.
 *
 * WHAT IT DELIBERATELY DOES NOT OWN
 *   · The <dialog>, focus trap, backdrop and Escape handling — all of that is
 *     lpc-modal.js. This file only supplies bodies and wires the controls
 *     inside them. Two focus traps fighting each other is a bug we already
 *     shipped once, in the proposal studio.
 *   · Reading employee_profiles. Everything comes from #lpc-user-data (printed
 *     by the sidebar) or from profile_controller.php responses.
 *
 * STATE MODEL
 *   `state.profile` is the single client-side copy of the user's profile. Every
 *   successful API call returns the server's version of it, and we overwrite
 *   wholesale rather than merging — so a validation rule that rewrote a value
 *   server-side (trimmed name, rejected landing page) is reflected immediately
 *   instead of drifting until reload.
 *
 * OPTIMISTIC UI
 *   Theme, accent, density and reduce-motion are applied to <html> BEFORE the
 *   request goes out, because they are instant-feedback settings and a 200ms
 *   round-trip makes them feel broken. If the save fails, `revert()` puts the
 *   previous value back and the status line says so. Language is NOT optimistic
 *   — it needs a reload to re-render server-side strings.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    if (window.LPC && window.LPC.userMenu) return;   // double-include guard

    var ENDPOINT = '/api/v1/profile_controller.php';

    // -------------------------------------------------------------------------
    // Boot data
    // -------------------------------------------------------------------------
    function readBootData() {
        var el = document.getElementById('lpc-user-data');
        if (!el) return null;
        try { return JSON.parse(el.textContent || '{}'); }
        catch (e) { console.error('[LPC] user data payload is not valid JSON', e); return null; }
    }

    var state = {
        profile:  readBootData() || {},
        security: null,        // lazily fetched with the Security tab
        landing:  null,
        zones:    null,
        open:     false
    };

    // -------------------------------------------------------------------------
    // i18n — falls back to French, which is this install's default locale.
    // Keys live in includes/config/i18n_dictionaries.php under `ui.account.*`.
    // -------------------------------------------------------------------------
    function t(key, fallback) {
        if (window.LPC && typeof window.LPC.t === 'function') {
            var v = window.LPC.t(key);
            if (v && v !== key) return v;
        }
        return fallback;
    }

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // -------------------------------------------------------------------------
    // Transport
    //
    // FormData rather than JSON because profile_controller reads $_POST, and
    // the avatar action needs multipart anyway — one code path for both.
    // -------------------------------------------------------------------------
    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || null;
        return c || { token: '', header: 'X-CSRF-Token', field: '_csrf' };
    }

    function api(action, data) {
        var c  = csrf();
        var fd = (data instanceof FormData) ? data : new FormData();
        if (!(data instanceof FormData) && data) {
            Object.keys(data).forEach(function (k) {
                if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]);
            });
        }
        fd.append('action', action);
        if (c.token) fd.append(c.field || '_csrf', c.token);

        var headers = {};
        if (c.token) headers[c.header || 'X-CSRF-Token'] = c.token;

        return fetch(ENDPOINT, {
            method: 'POST',
            body: fd,
            headers: headers,
            credentials: 'same-origin'
        }).then(function (r) {
            // A 401 here means the session died while the modal was open. Say so
            // plainly rather than showing "Action inconnue" from a login page
            // that failed to parse as JSON.
            if (r.status === 401) {
                return { status: 'error', message: t('ui.account.session_expired', 'Session expirée. Reconnectez-vous.'), code: 'session_expired' };
            }
            return r.json().catch(function () {
                return { status: 'error', message: t('ui.account.bad_response', 'Réponse inattendue du serveur.') };
            });
        }).then(function (json) {
            if (json && json.profile) state.profile = json.profile;
            if (json && json.security) state.security = json.security;
            return json || { status: 'error', message: 'Erreur.' };
        }).catch(function () {
            return { status: 'error', message: t('ui.account.offline', 'Connexion impossible. Vérifiez votre réseau.') };
        });
    }

    // =========================================================================
    // Presentation — applying preferences to <html>
    // =========================================================================
    var html = document.documentElement;

    function applyTheme(pref) {
        var concrete = pref;
        if (pref === 'system') {
            concrete = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
                ? 'dark' : 'light';
        }
        var previous = html.getAttribute('data-lpc-theme');
        html.setAttribute('data-lpc-theme', concrete);
        html.setAttribute('data-lpc-theme-pref', pref);

        // Announce the change so the parts of the UI that CSS cannot reach can
        // follow it. Right now that means <canvas> charts (lpc-chart-theme.js),
        // which resolve their colours in JS at construction time and would
        // otherwise keep the palette they were built with until a full reload.
        //
        // Fired only on an actual light<->dark transition: switching the
        // preference from "dark" to "system" on a machine already in dark mode
        // changes data-lpc-theme-pref but nothing visual, and repainting every
        // chart for that would be a visible stutter for no reason.
        if (previous !== concrete) {
            try {
                document.dispatchEvent(new CustomEvent('lpc:themechange', {
                    detail: { theme: concrete, preference: pref }
                }));
            } catch (e) {
                // CustomEvent constructor is unavailable on a couple of the
                // older site tablets. Personalisation must never throw into the
                // page, and a chart keeping its old palette until reload is a
                // survivable outcome; a broken account menu is not.
            }
        }
    }

    function applyAccent(a)  { html.setAttribute('data-lpc-accent', a); }
    function applyDensity(d) { html.setAttribute('data-lpc-density', d); }
    function applyMotion(on) {
        if (on) html.setAttribute('data-lpc-reduce-motion', '1');
        else    html.removeAttribute('data-lpc-reduce-motion');
    }

    // Follow the OS while the preference is "system". Without this the user
    // picks "match system", their laptop switches to dark at sunset, and the
    // app stays light until the next page load.
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var onSchemeChange = function () {
            if (html.getAttribute('data-lpc-theme-pref') === 'system') applyTheme('system');
        };
        if (mq.addEventListener) mq.addEventListener('change', onSchemeChange);
        else if (mq.addListener) mq.addListener(onSchemeChange);
    }

    /**
     * Persist one or more preference keys, applying them locally first.
     * `revert` restores the previous visual state if the server says no.
     */
    function savePrefs(fields, revert) {
        return api('save_prefs', fields).then(function (res) {
            if (res.status !== 'success') {
                if (typeof revert === 'function') revert();
                notify(res.message, 'err');
            }
            return res;
        });
    }

    // -------------------------------------------------------------------------
    // Lightweight transient notice. Not a toast system — one node, reused,
    // bottom-left above the sidebar footer so it reads as part of the menu.
    // -------------------------------------------------------------------------
    var noticeEl = null, noticeTimer = null;
    function notify(message, kind) {
        if (!message) return;
        if (!noticeEl) {
            noticeEl = document.createElement('div');
            noticeEl.className = 'lpc-account-notice';
            noticeEl.setAttribute('role', 'status');
            noticeEl.setAttribute('aria-live', 'polite');
            noticeEl.style.cssText =
                'position:fixed;left:1rem;bottom:1rem;z-index:9999;max-width:22rem;' +
                'padding:.6rem .9rem;border-radius:.6rem;font-size:.8125rem;font-weight:600;' +
                'color:#fff;box-shadow:0 10px 30px -10px rgba(0,0,0,.5);opacity:0;' +
                'transition:opacity .16s ease,transform .16s ease;transform:translateY(6px);';
            document.body.appendChild(noticeEl);
        }
        noticeEl.style.background = (kind === 'err') ? '#DC2626' : '#047857';
        noticeEl.textContent = message;
        requestAnimationFrame(function () {
            noticeEl.style.opacity = '1';
            noticeEl.style.transform = 'translateY(0)';
        });
        clearTimeout(noticeTimer);
        noticeTimer = setTimeout(function () {
            noticeEl.style.opacity = '0';
            noticeEl.style.transform = 'translateY(6px)';
        }, kind === 'err' ? 5000 : 2600);
    }

    // =========================================================================
    // The pop-up menu
    // =========================================================================
    var trigger, menu, root;

    function menuItems() {
        return Array.prototype.slice.call(menu.querySelectorAll('.lpc-user-menu-item'));
    }

    function setActiveItem(idx) {
        var items = menuItems();
        items.forEach(function (el, i) {
            el.setAttribute('data-lpc-active', i === idx ? 'true' : 'false');
        });
        if (items[idx]) items[idx].focus();
    }

    function openMenu() {
        if (state.open) return;
        state.open = true;
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        document.addEventListener('mousedown', onDocDown, true);
        document.addEventListener('keydown', onDocKey, true);
    }

    function closeMenu(refocus) {
        if (!state.open) return;
        state.open = false;
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        menuItems().forEach(function (el) { el.setAttribute('data-lpc-active', 'false'); });
        document.removeEventListener('mousedown', onDocDown, true);
        document.removeEventListener('keydown', onDocKey, true);
        if (refocus) trigger.focus();
    }

    function onDocDown(e) {
        if (!root.contains(e.target)) closeMenu(false);
    }

    function onDocKey(e) {
        // Only handle keys while the menu owns focus. A modal opened FROM the
        // menu must keep its own Escape behaviour, and the menu is closed by
        // then anyway — but the capture-phase listener would still see the key.
        if (!state.open) return;
        var items = menuItems();
        var idx = items.findIndex(function (el) { return el.getAttribute('data-lpc-active') === 'true'; });

        if (e.key === 'Escape')            { e.preventDefault(); closeMenu(true); }
        else if (e.key === 'ArrowDown')    { e.preventDefault(); setActiveItem(idx < 0 ? 0 : (idx + 1) % items.length); }
        else if (e.key === 'ArrowUp')      { e.preventDefault(); setActiveItem(idx <= 0 ? items.length - 1 : idx - 1); }
        else if (e.key === 'Home')         { e.preventDefault(); setActiveItem(0); }
        else if (e.key === 'End')          { e.preventDefault(); setActiveItem(items.length - 1); }
        else if (e.key === 'Tab')          { closeMenu(false); }
    }

    function wireMenu() {
        root    = document.querySelector('[data-lpc-usermenu]');
        if (!root) return;
        trigger = root.querySelector('#lpc-user-trigger');
        menu    = root.querySelector('#lpc-user-menu');
        if (!trigger || !menu) return;

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            state.open ? closeMenu(false) : openMenu();
        });

        // Opening with a keyboard should land on the first item; opening with
        // the mouse should not steal focus from wherever the pointer is going.
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openMenu();
                setActiveItem(0);
            }
        });

        menu.addEventListener('click', function (e) {
            var item = e.target.closest('[data-lpc-action]');
            if (item) {
                var action = item.getAttribute('data-lpc-action');
                closeMenu(false);
                if (action === 'profile')  openProfileModal();
                if (action === 'password') openPasswordModal();
                if (action === 'signout')  confirmSignOut();
                return;
            }

            var loc = e.target.closest('[data-lpc-set-locale]');
            if (loc) { setLocale(loc.getAttribute('data-lpc-set-locale')); return; }

            var th = e.target.closest('[data-lpc-set-theme]');
            if (th) { setThemeFromMenu(th.getAttribute('data-lpc-set-theme')); return; }
        });
    }

    // -------------------------------------------------------------------------
    // Quick strip actions
    // -------------------------------------------------------------------------
    function setLocale(locale) {
        if (!locale || locale === state.profile.locale) { closeMenu(true); return; }
        savePrefs({ locale: locale }).then(function (res) {
            if (res.status === 'success') {
                // Server-rendered strings (nav labels, page titles, the whole
                // topbar) only change on a fresh render, so reload rather than
                // leave the UI half-translated.
                window.location.reload();
            }
        });
    }

    function setThemeFromMenu(theme) {
        var previous = state.profile.theme;
        applyTheme(theme);
        state.profile.theme = theme;
        syncQuickStrip();
        savePrefs({ theme: theme }, function () {
            state.profile.theme = previous;
            applyTheme(previous);
            syncQuickStrip();
        });
    }

    function syncQuickStrip() {
        if (!menu) return;
        menu.querySelectorAll('[data-lpc-set-theme]').forEach(function (b) {
            b.setAttribute('aria-pressed', String(b.getAttribute('data-lpc-set-theme') === state.profile.theme));
        });
        menu.querySelectorAll('[data-lpc-set-locale]').forEach(function (b) {
            b.setAttribute('aria-pressed', String(b.getAttribute('data-lpc-set-locale') === state.profile.locale));
        });
    }

    // -------------------------------------------------------------------------
    // Sign out
    // -------------------------------------------------------------------------
    function confirmSignOut() {
        var go = function () { window.location.href = '/api/v1/auth.php?logout=true'; };

        if (!window.LPC || !window.LPC.modal) { go(); return; }   // JS half-loaded: still work

        window.LPC.modal.confirm(
            t('ui.account.signout_body', 'Vous serez déconnecté de cet appareil. Les modifications non enregistrées seront perdues.'),
            {
                title:        t('ui.account.signout_title', 'Se déconnecter ?'),
                confirmLabel: t('ui.account.signout_confirm', 'Se déconnecter'),
                cancelLabel:  t('ui.common.cancel', 'Annuler'),
                danger:       true
            }
        ).then(function (yes) { if (yes) go(); });
    }

    // =========================================================================
    // Shared modal plumbing
    //
    // LPC.modal.custom resolves when a footer button is clicked, and does not
    // hand back the DOM node. We do not need it to: the modal is the only
    // .lpc-account in the document at any time, so a single rAF after opening
    // gets us the root to wire up.
    // =========================================================================
    function afterOpen(selector, wire) {
        requestAnimationFrame(function () {
            var el = document.querySelector(selector);
            if (!el) return;
            wire(el);
        });
    }

    /**
     * Widen the profile modal.
     *
     * The class goes on TWO elements. lpc-modal.js renders
     * `<dialog class="lpc-modal-dialog"><div class="lpc-modal">…`, and
     * src/input.css caps the DIALOG at min(560px, 92vw). Widening only the
     * inner div made it overflow its own dialog — clipped title, sideways
     * scrollbar. Widening only the dialog would leave the inner div at its
     * 560px max-width. Both, or neither.
     *
     * The non-<dialog> fallback path uses `.lpc-modal-fallback` as the host,
     * so we walk up to whichever is present.
     */
    function widen(el) {
        var panel = el.closest('.lpc-modal');
        if (panel) panel.classList.add('lpc-modal--wide');
        var host = el.closest('.lpc-modal-dialog, .lpc-modal-fallback');
        if (host) host.classList.add('lpc-modal--wide');
    }

    function setStatus(el, message, kind) {
        if (!el) return;
        el.textContent = message || '';
        el.className = 'lpc-account-status' + (kind ? ' lpc-account-status--' + kind : '');
    }

    // =========================================================================
    // Profile modal
    // =========================================================================
    function profileBody() {
        var p = state.profile;
        var avatarInner = p.avatar
            ? '<img alt="" src="' + esc(p.avatar) + '">'
            : '<span>' + esc(p.initials || '?') + '</span>';

        return '' +
        '<div class="lpc-account" data-lpc-account>' +

          '<div class="lpc-account-tabs" role="tablist">' +
            '<button type="button" class="lpc-account-tab" role="tab" data-tab="profile"  aria-selected="true">'  + esc(t('ui.account.tab_profile',  'Profil')) + '</button>' +
            '<button type="button" class="lpc-account-tab" role="tab" data-tab="prefs"    aria-selected="false">' + esc(t('ui.account.tab_prefs',    'Préférences')) + '</button>' +
            '<button type="button" class="lpc-account-tab" role="tab" data-tab="security" aria-selected="false">' + esc(t('ui.account.tab_security', 'Sécurité')) + '</button>' +
          '</div>' +

          // ---- Panel: profile -------------------------------------------------
          '<div class="lpc-account-panel" data-panel="profile" role="tabpanel">' +
            '<div class="lpc-account-hero">' +
              '<button type="button" class="lpc-account-avatar" data-avatar-btn ' +
                      'title="' + esc(t('ui.account.avatar_hint', 'Cliquez ou déposez une image')) + '">' +
                avatarInner +
                '<span class="lpc-account-avatar-overlay">' + esc(t('ui.account.change', 'Modifier')) + '</span>' +
              '</button>' +
              '<input type="file" accept="image/jpeg,image/png,image/webp" hidden data-avatar-input>' +
              '<div class="lpc-account-hero-text">' +
                '<div class="lpc-account-hero-name" data-hero-name></div>' +
                '<div class="lpc-account-hero-sub"  data-hero-sub></div>' +
                '<div class="lpc-account-hero-actions">' +
                  '<button type="button" class="lpc-account-btn" data-avatar-btn>' + esc(t('ui.account.upload', 'Changer la photo')) + '</button>' +
                  '<button type="button" class="lpc-account-btn lpc-account-btn--danger" data-avatar-remove>' + esc(t('ui.account.remove', 'Retirer')) + '</button>' +
                '</div>' +
                '<div class="lpc-account-status" data-avatar-status></div>' +
              '</div>' +
            '</div>' +

            '<div class="lpc-account-grid">' +
              field('display_name', t('ui.account.display_name', 'Nom affiché'),
                    '<input type="text" class="lpc-account-input" data-f="display_name" maxlength="120" autocomplete="nickname">',
                    t('ui.account.display_name_hint', 'Laissez vide pour utiliser votre nom RH.')) +
              field('pronouns', t('ui.account.pronouns', 'Pronoms'),
                    '<input type="text" class="lpc-account-input" data-f="pronouns" maxlength="40" placeholder="il/lui · elle/elle">') +
              field('phone', t('ui.account.phone', 'Téléphone'),
                    '<input type="tel" class="lpc-account-input" data-f="phone" maxlength="40" autocomplete="tel">') +
              field('email', t('ui.account.email', 'E-mail'),
                    '<input type="email" class="lpc-account-input" data-f="email" readonly>',
                    t('ui.account.email_hint', 'Géré par les RH.')) +
              field('job_title', t('ui.account.job_title', 'Fonction'),
                    '<input type="text" class="lpc-account-input" data-f="job_title" readonly>',
                    t('ui.account.hr_managed', 'Géré par les RH.')) +
              field('employee_code', t('ui.account.employee_code', 'Matricule'),
                    '<input type="text" class="lpc-account-input" data-f="employee_code" readonly>') +
              fieldFull('about', t('ui.account.about', 'À propos'),
                    '<textarea class="lpc-account-textarea" data-f="about" maxlength="280"></textarea>' +
                    '<div class="lpc-account-count"><span data-about-count>0</span>/280</div>') +
            '</div>' +

            '<div style="display:flex;align-items:center;gap:.75rem;margin-top:1rem;">' +
              '<button type="button" class="lpc-account-btn lpc-account-btn--primary" data-save-identity>' +
                esc(t('ui.account.save', 'Enregistrer')) + '</button>' +
              '<span class="lpc-account-status" data-identity-status></span>' +
            '</div>' +
          '</div>' +

          // ---- Panel: preferences --------------------------------------------
          '<div class="lpc-account-panel" data-panel="prefs" role="tabpanel" hidden>' +
            '<div class="lpc-account-section-title">' + esc(t('ui.account.appearance', 'Apparence')) + '</div>' +

            '<div class="lpc-account-field" style="margin-bottom:.875rem;">' +
              '<span class="lpc-account-label">' + esc(t('ui.account.theme', 'Thème')) + '</span>' +
              '<div class="lpc-account-seg" data-seg="theme">' +
                segBtn('theme', 'light',  t('ui.account.theme_light',  'Clair')) +
                segBtn('theme', 'dark',   t('ui.account.theme_dark',   'Sombre')) +
                segBtn('theme', 'system', t('ui.account.theme_system', 'Système')) +
              '</div>' +
              '<span class="lpc-account-hint">' + esc(t('ui.account.theme_hint', 'Le menu latéral garde les couleurs de la marque.')) + '</span>' +
            '</div>' +

            '<div class="lpc-account-field" style="margin-bottom:.875rem;">' +
              '<span class="lpc-account-label">' + esc(t('ui.account.accent', 'Couleur d\'accent')) + '</span>' +
              '<div class="lpc-account-swatches" data-seg="accent">' +
                swatch('brand',  t('ui.account.accent_brand',  'Vert LPC')) +
                swatch('teal',   t('ui.account.accent_teal',   'Turquoise')) +
                swatch('indigo', t('ui.account.accent_indigo', 'Indigo')) +
                swatch('amber',  t('ui.account.accent_amber',  'Ambre')) +
                swatch('rose',   t('ui.account.accent_rose',   'Rose')) +
              '</div>' +
            '</div>' +

            '<div class="lpc-account-field" style="margin-bottom:.875rem;">' +
              '<span class="lpc-account-label">' + esc(t('ui.account.density', 'Densité')) + '</span>' +
              '<div class="lpc-account-seg" data-seg="density">' +
                segBtn('density', 'comfortable', t('ui.account.density_comfortable', 'Confortable')) +
                segBtn('density', 'compact',     t('ui.account.density_compact',     'Compacte')) +
              '</div>' +
            '</div>' +

            '<div class="lpc-account-section-title">' + esc(t('ui.account.regional', 'Région et langue')) + '</div>' +
            '<div class="lpc-account-grid">' +
              field('locale', t('ui.account.language', 'Langue'),
                    '<select class="lpc-account-select" data-f="locale">' +
                      '<option value="fr">Français</option><option value="en">English</option>' +
                    '</select>') +
              field('timezone', t('ui.account.timezone', 'Fuseau horaire'),
                    '<select class="lpc-account-select" data-f="timezone"></select>') +
              fieldFull('landing_page', t('ui.account.landing', 'Page d\'accueil après connexion'),
                    '<select class="lpc-account-select" data-f="landing_page"></select>',
                    t('ui.account.landing_hint', 'Seules les pages auxquelles vous avez accès sont proposées.')) +
            '</div>' +

            '<div class="lpc-account-section-title">' + esc(t('ui.account.comfort', 'Confort')) + '</div>' +
            toggleRow('reduce_motion',
                      t('ui.account.reduce_motion', 'Réduire les animations'),
                      t('ui.account.reduce_motion_sub', 'Supprime les transitions et les défilements animés.')) +
            toggleRow('sidebar_collapsed',
                      t('ui.account.rail', 'Menu réduit par défaut'),
                      t('ui.account.rail_sub', 'S\'applique à vos nouveaux appareils.')) +

            '<div class="lpc-account-status" data-prefs-status style="margin-top:.75rem;"></div>' +
          '</div>' +

          // ---- Panel: security ------------------------------------------------
          '<div class="lpc-account-panel" data-panel="security" role="tabpanel" hidden>' +
            '<div class="lpc-account-facts" data-sec-facts></div>' +

            '<div class="lpc-account-section-title">' + esc(t('ui.account.pin', 'Code de déverrouillage rapide')) + '</div>' +
            '<p class="lpc-account-hint" style="margin-bottom:.5rem;">' +
              esc(t('ui.account.pin_hint', 'Un code à 4–8 chiffres pour rouvrir votre session après une inactivité. Il ne remplace jamais votre mot de passe à la connexion.')) +
            '</p>' +
            '<div class="lpc-account-grid" data-pin-form>' +
              field('pin', t('ui.account.pin_new', 'Nouveau code'),
                    '<input type="password" inputmode="numeric" class="lpc-account-input lpc-account-pin" data-f="pin" maxlength="8" autocomplete="off">') +
              field('pin_confirm', t('ui.account.pin_confirm', 'Confirmer'),
                    '<input type="password" inputmode="numeric" class="lpc-account-input lpc-account-pin" data-f="pin_confirm" maxlength="8" autocomplete="off">') +
              fieldFull('pin_password', t('ui.account.confirm_password', 'Votre mot de passe'),
                    '<input type="password" class="lpc-account-input" data-f="pin_password" autocomplete="current-password">') +
            '</div>' +
            '<div style="display:flex;align-items:center;gap:.5rem;margin-top:.75rem;flex-wrap:wrap;">' +
              '<button type="button" class="lpc-account-btn lpc-account-btn--primary" data-save-pin>' + esc(t('ui.account.pin_save', 'Enregistrer le code')) + '</button>' +
              '<button type="button" class="lpc-account-btn lpc-account-btn--danger" data-clear-pin hidden>' + esc(t('ui.account.pin_clear', 'Supprimer le code')) + '</button>' +
              '<span class="lpc-account-status" data-pin-status></span>' +
            '</div>' +

            '<div class="lpc-account-section-title">' + esc(t('ui.account.sessions', 'Sessions actives')) + '</div>' +
            '<div class="lpc-account-sessions" data-sec-sessions></div>' +
            '<div style="display:flex;align-items:center;gap:.5rem;margin-top:.75rem;">' +
              '<button type="button" class="lpc-account-btn lpc-account-btn--danger" data-revoke>' + esc(t('ui.account.revoke', 'Fermer les autres sessions')) + '</button>' +
              '<span class="lpc-account-status" data-sec-status></span>' +
            '</div>' +
          '</div>' +
        '</div>';
    }

    // --- small markup helpers ------------------------------------------------
    function field(id, label, control, hint) {
        return '<label class="lpc-account-field" data-field="' + esc(id) + '">' +
                 '<span class="lpc-account-label">' + esc(label) + '</span>' + control +
                 (hint ? '<span class="lpc-account-hint">' + esc(hint) + '</span>' : '') +
               '</label>';
    }
    function fieldFull(id, label, control, hint) {
        return field(id, label, control, hint).replace('class="lpc-account-field"',
                                                       'class="lpc-account-field lpc-account-field--full"');
    }
    function segBtn(group, value, label) {
        return '<button type="button" class="lpc-account-seg-btn" data-opt="' + esc(group) + '" ' +
               'data-val="' + esc(value) + '" aria-pressed="false">' + esc(label) + '</button>';
    }
    function swatch(value, label) {
        return '<button type="button" class="lpc-account-swatch" data-opt="accent" data-accent="' + esc(value) + '" ' +
               'data-val="' + esc(value) + '" aria-pressed="false" aria-label="' + esc(label) + '" title="' + esc(label) + '"></button>';
    }
    function toggleRow(key, title, sub) {
        return '<div class="lpc-account-toggle">' +
                 '<span class="lpc-account-toggle-copy">' +
                   '<span class="lpc-account-toggle-title">' + esc(title) + '</span><br>' +
                   '<span class="lpc-account-toggle-sub">' + esc(sub) + '</span>' +
                 '</span>' +
                 '<button type="button" class="lpc-account-switch" role="switch" ' +
                         'aria-checked="false" data-toggle="' + esc(key) + '" aria-label="' + esc(title) + '"></button>' +
               '</div>';
    }

    function openProfileModal() {
        if (!window.LPC || !window.LPC.modal) return;

        window.LPC.modal.custom({
            title:    t('ui.account.title', 'Mon profil'),
            bodyHtml: profileBody(),
            buttons:  [{ label: t('ui.common.close', 'Fermer'), value: null, variant: 'secondary', primary: true }]
        });

        afterOpen('[data-lpc-account]', function (el) {
            widen(el);
            wireProfileModal(el);
        });

        // Fetch the authoritative copy in parallel with rendering. The modal is
        // populated from the boot payload first so it never opens empty, then
        // re-populated when this lands — usually before the user has read the
        // first field.
        //
        // The [data-lpc-account] re-query matters: the user can close this
        // modal before the request resolves, and writing into a detached node
        // (or worse, into the password modal they opened next) would be a
        // silent, confusing bug.
        api('get', {}).then(function (res) {
            if (res.status !== 'success') return;
            state.landing = res.landing || [];
            state.zones   = res.zones   || [];
            var el = document.querySelector('[data-lpc-account]');
            if (el) { fillSelects(el); populateProfile(el); renderSecurity(el); }
        });
    }

    function wireProfileModal(el) {
        // ---- Tabs ------------------------------------------------------------
        el.querySelectorAll('.lpc-account-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-tab');
                el.querySelectorAll('.lpc-account-tab').forEach(function (t2) {
                    t2.setAttribute('aria-selected', String(t2 === tab));
                });
                el.querySelectorAll('.lpc-account-panel').forEach(function (p) {
                    p.hidden = (p.getAttribute('data-panel') !== name);
                });
                if (name === 'security' && !state.security) {
                    api('sessions', {}).then(function () { renderSecurity(el); });
                }
            });
        });

        fillSelects(el);
        populateProfile(el);

        // ---- Live character count -------------------------------------------
        var about = el.querySelector('[data-f="about"]');
        var count = el.querySelector('[data-about-count]');
        if (about && count) {
            var updateCount = function () { count.textContent = String(about.value.length); };
            about.addEventListener('input', updateCount);
            updateCount();
        }

        // ---- Identity save ---------------------------------------------------
        var identityStatus = el.querySelector('[data-identity-status]');
        el.querySelector('[data-save-identity]').addEventListener('click', function (e) {
            var btn = e.currentTarget;
            btn.disabled = true;
            setStatus(identityStatus, t('ui.account.saving', 'Enregistrement…'), 'busy');

            api('save_identity', {
                display_name: el.querySelector('[data-f="display_name"]').value,
                pronouns:     el.querySelector('[data-f="pronouns"]').value,
                phone:        el.querySelector('[data-f="phone"]').value,
                about:        el.querySelector('[data-f="about"]').value
            }).then(function (res) {
                btn.disabled = false;
                setStatus(identityStatus, res.message, res.status === 'success' ? 'ok' : 'err');
                if (res.status === 'success') {
                    populateProfile(el);
                    refreshSidebarIdentity();
                }
            });
        });

        // ---- Avatar ----------------------------------------------------------
        wireAvatar(el);

        // ---- Preferences: segmented buttons and swatches ---------------------
        el.querySelectorAll('[data-opt]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-opt');
                var val = btn.getAttribute('data-val');
                var prev = state.profile[key];
                if (val === prev) return;

                state.profile[key] = val;
                applyOne(key, val);
                syncOptionButtons(el);
                syncQuickStrip();

                var fields = {};
                fields[key] = val;
                setStatus(el.querySelector('[data-prefs-status]'), t('ui.account.saving', 'Enregistrement…'), 'busy');
                savePrefs(fields, function () {
                    state.profile[key] = prev;
                    applyOne(key, prev);
                    syncOptionButtons(el);
                    syncQuickStrip();
                }).then(function (res) {
                    setStatus(el.querySelector('[data-prefs-status]'), res.message,
                              res.status === 'success' ? 'ok' : 'err');
                });
            });
        });

        // ---- Preferences: toggles -------------------------------------------
        el.querySelectorAll('[data-toggle]').forEach(function (sw) {
            sw.addEventListener('click', function () {
                var key = sw.getAttribute('data-toggle');
                var next = sw.getAttribute('aria-checked') !== 'true';
                sw.setAttribute('aria-checked', String(next));
                state.profile[key === 'reduce_motion' ? 'reduceMotion' : 'sidebarCollapsed'] = next;
                if (key === 'reduce_motion') applyMotion(next);

                var fields = {};
                fields[key] = next ? '1' : '0';
                savePrefs(fields, function () {
                    sw.setAttribute('aria-checked', String(!next));
                    if (key === 'reduce_motion') applyMotion(!next);
                }).then(function (res) {
                    setStatus(el.querySelector('[data-prefs-status]'), res.message,
                              res.status === 'success' ? 'ok' : 'err');
                });
            });
        });

        // ---- Preferences: selects -------------------------------------------
        var localeSel = el.querySelector('[data-f="locale"]');
        localeSel.addEventListener('change', function () { setLocale(localeSel.value); });

        ['timezone', 'landing_page'].forEach(function (key) {
            var sel = el.querySelector('[data-f="' + key + '"]');
            if (!sel) return;
            sel.addEventListener('change', function () {
                var fields = {};
                fields[key] = sel.value;
                savePrefs(fields).then(function (res) {
                    setStatus(el.querySelector('[data-prefs-status]'), res.message,
                              res.status === 'success' ? 'ok' : 'err');
                });
            });
        });

        // ---- Security --------------------------------------------------------
        wireSecurity(el);
        renderSecurity(el);
    }

    function applyOne(key, val) {
        if (key === 'theme')   applyTheme(val);
        if (key === 'accent')  applyAccent(val);
        if (key === 'density') applyDensity(val);
    }

    function fillSelects(el) {
        var tz = el.querySelector('[data-f="timezone"]');
        if (tz && state.zones) {
            tz.innerHTML = '<option value="">' + esc(t('ui.account.tz_default', 'Fuseau de l\'entreprise')) + '</option>' +
                state.zones.map(function (z) { return '<option value="' + esc(z) + '">' + esc(z) + '</option>'; }).join('');
        }
        var lp = el.querySelector('[data-f="landing_page"]');
        if (lp && state.landing) {
            lp.innerHTML = '<option value="">' + esc(t('ui.account.landing_default', 'Automatique (selon mes droits)')) + '</option>' +
                state.landing.map(function (o) {
                    return '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>';
                }).join('');
        }
    }

    /**
     * Push state.profile into the DOM. Values are assigned as PROPERTIES, never
     * interpolated into the HTML string above — a display name containing
     * `"><script>` must be impossible to weaponise, and this is how.
     */
    function populateProfile(el) {
        var p = state.profile;
        var set = function (name, value) {
            var node = el.querySelector('[data-f="' + name + '"]');
            if (node) node.value = (value === null || value === undefined) ? '' : value;
        };

        // display_name is echoed only when the user actually chose one; if it
        // equals the legal name it is a fallback, and showing it pre-filled
        // would turn "I never set this" into "I set it to my legal name".
        set('display_name', (p.displayName && p.displayName !== p.legalName) ? p.displayName : '');
        set('pronouns',     p.pronouns);
        set('phone',        p.phone);
        set('about',        p.about);
        set('email',        p.email);
        set('job_title',    p.jobTitle);
        set('employee_code',p.employeeCode);
        set('locale',       p.locale);
        set('timezone',     p.timezone || '');
        set('landing_page', p.landingPage || '');

        var heroName = el.querySelector('[data-hero-name]');
        var heroSub  = el.querySelector('[data-hero-sub]');
        if (heroName) heroName.textContent = p.displayName || p.legalName || '';
        if (heroSub)  heroSub.textContent  = [p.roleLabel, p.email].filter(Boolean).join(' · ');

        var count = el.querySelector('[data-about-count]');
        if (count) count.textContent = String((p.about || '').length);

        syncOptionButtons(el);

        el.querySelectorAll('[data-toggle]').forEach(function (sw) {
            var key = sw.getAttribute('data-toggle');
            var on  = (key === 'reduce_motion') ? !!p.reduceMotion : !!p.sidebarCollapsed;
            sw.setAttribute('aria-checked', String(on));
        });

        paintAvatar(el);
    }

    function syncOptionButtons(el) {
        el.querySelectorAll('[data-opt]').forEach(function (b) {
            var key = b.getAttribute('data-opt');
            b.setAttribute('aria-pressed', String(state.profile[key] === b.getAttribute('data-val')));
        });
    }

    function paintAvatar(el) {
        var p = state.profile;
        el.querySelectorAll('.lpc-account-avatar').forEach(function (box) {
            var overlay = box.querySelector('.lpc-account-avatar-overlay');
            box.innerHTML = p.avatar
                ? '<img alt="" src="' + esc(p.avatar) + '">'
                : '<span>' + esc(p.initials || '?') + '</span>';
            if (overlay) box.appendChild(overlay);
        });
        var remove = el.querySelector('[data-avatar-remove]');
        if (remove) remove.hidden = !p.avatar;
    }

    /**
     * Mirror a fresh identity into the sidebar without a reload. The full
     * server render still happens on the next navigation; this just stops the
     * footer from disagreeing with the modal the user is looking at.
     */
    function refreshSidebarIdentity() {
        var p = state.profile;
        if (!root) return;
        root.querySelectorAll('.lpc-user-name, .lpc-user-menu-name').forEach(function (n) {
            n.textContent = p.displayName || '';
        });
        // Rewrite only the inner clipper, never .lpc-user-avatar itself — the
        // presence dot is its sibling and blowing away the parent's innerHTML
        // would delete the dot and its state along with the photo.
        root.querySelectorAll('.lpc-user-avatar-img').forEach(function (box) {
            box.innerHTML = p.avatar
                ? '<img src="' + esc(p.avatar) + '" alt="">'
                : '<span class="lpc-user-initials">' + esc(p.initials || '?') + '</span>';
        });
    }

    // -------------------------------------------------------------------------
    // Avatar upload
    //
    // The file is compressed in the browser first (lpc-image-compress.js), for
    // two reasons: a 6 MB phone photo would be rejected by the 2 MiB server cap
    // that the user cannot see, and uploading 6 MB over a Douala mobile
    // connection to display a 40px circle is unkind. If the compressor is not
    // loaded on this page, the raw file is sent and the server decides.
    // -------------------------------------------------------------------------
    function wireAvatar(el) {
        var input  = el.querySelector('[data-avatar-input]');
        var status = el.querySelector('[data-avatar-status]');
        var box    = el.querySelector('.lpc-account-avatar');

        el.querySelectorAll('[data-avatar-btn]').forEach(function (b) {
            b.addEventListener('click', function () { input.click(); });
        });

        input.addEventListener('change', function () {
            if (input.files && input.files[0]) upload(input.files[0]);
            input.value = '';        // so re-picking the same file fires change
        });

        // Drag and drop onto the avatar itself.
        ['dragenter', 'dragover'].forEach(function (ev) {
            box.addEventListener(ev, function (e) {
                e.preventDefault(); e.stopPropagation();
                box.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            box.addEventListener(ev, function (e) {
                e.preventDefault(); e.stopPropagation();
                box.classList.remove('is-dragover');
            });
        });
        box.addEventListener('drop', function (e) {
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) upload(f);
        });

        el.querySelector('[data-avatar-remove]').addEventListener('click', function () {
            setStatus(status, t('ui.account.removing', 'Suppression…'), 'busy');
            api('remove_avatar', {}).then(function (res) {
                setStatus(status, res.message, res.status === 'success' ? 'ok' : 'err');
                if (res.status === 'success') { paintAvatar(el); refreshSidebarIdentity(); }
            });
        });

        function upload(file) {
            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
                setStatus(status, t('ui.account.avatar_type', 'Formats acceptés : JPG, PNG, WebP.'), 'err');
                return;
            }
            setStatus(status, t('ui.account.uploading', 'Envoi…'), 'busy');

            var prepared = (window.LPC && window.LPC.compress)
                ? window.LPC.compress.compressToBlob(file, { maxDim: 512, quality: 0.86, mime: 'image/jpeg' })
                      .catch(function () { return file; })   // compressor failed → send the original
                : Promise.resolve(file);

            prepared.then(function (blob) {
                // compressToBlob returns the ORIGINAL File untouched for images
                // under 512 KB, so the blob's type is not guaranteed to be JPEG
                // — name the part after what it actually is. The server sniffs
                // the bytes regardless, but a .jpg named PNG makes the uploads
                // directory a liar.
                var extByMime = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' };
                var fd = new FormData();
                fd.append('avatar', blob, 'avatar.' + (extByMime[blob.type] || 'jpg'));
                return api('save_avatar', fd);
            }).then(function (res) {
                setStatus(status, res.message, res.status === 'success' ? 'ok' : 'err');
                if (res.status === 'success') { paintAvatar(el); refreshSidebarIdentity(); }
            });
        }
    }

    // -------------------------------------------------------------------------
    // Security panel
    // -------------------------------------------------------------------------
    function wireSecurity(el) {
        var pinStatus = el.querySelector('[data-pin-status]');
        var secStatus = el.querySelector('[data-sec-status]');

        // Digits only, live. Typing a letter into a PIN box and seeing nothing
        // happen is clearer than typing it, submitting, and being told off.
        el.querySelectorAll('.lpc-account-pin').forEach(function (inp) {
            inp.addEventListener('input', function () {
                inp.value = inp.value.replace(/\D/g, '').slice(0, 8);
            });
        });

        el.querySelector('[data-save-pin]').addEventListener('click', function (e) {
            var btn = e.currentTarget;
            btn.disabled = true;
            setStatus(pinStatus, t('ui.account.saving', 'Enregistrement…'), 'busy');
            api('set_pin', {
                pin:         el.querySelector('[data-f="pin"]').value,
                pin_confirm: el.querySelector('[data-f="pin_confirm"]').value,
                password:    el.querySelector('[data-f="pin_password"]').value
            }).then(function (res) {
                btn.disabled = false;
                setStatus(pinStatus, res.message, res.status === 'success' ? 'ok' : 'err');
                if (res.status === 'success') {
                    ['pin', 'pin_confirm', 'pin_password'].forEach(function (f) {
                        el.querySelector('[data-f="' + f + '"]').value = '';
                    });
                    renderSecurity(el);
                }
            });
        });

        el.querySelector('[data-clear-pin]').addEventListener('click', function () {
            setStatus(pinStatus, t('ui.account.removing', 'Suppression…'), 'busy');
            api('clear_pin', {}).then(function (res) {
                setStatus(pinStatus, res.message, res.status === 'success' ? 'ok' : 'err');
                renderSecurity(el);
            });
        });

        el.querySelector('[data-revoke]').addEventListener('click', function () {
            setStatus(secStatus, t('ui.account.working', 'En cours…'), 'busy');
            api('revoke_sessions', {}).then(function (res) {
                setStatus(secStatus, res.message, res.status === 'success' ? 'ok' : 'err');
                renderSecurity(el);
            });
        });
    }

    function renderSecurity(el) {
        var s = state.security;
        var facts    = el.querySelector('[data-sec-facts]');
        var sessions = el.querySelector('[data-sec-sessions]');
        var clearBtn = el.querySelector('[data-clear-pin]');
        if (!facts) return;

        if (!s) {
            facts.innerHTML = '<div class="lpc-account-fact"><div class="lpc-account-fact-v">' +
                              esc(t('ui.account.loading', 'Chargement…')) + '</div></div>';
            return;
        }

        clearBtn.hidden = !s.hasPin;

        facts.innerHTML =
            fact(t('ui.account.password_changed', 'Mot de passe modifié'), fmtDate(s.passwordChangedAt) || t('ui.account.never', 'Jamais')) +
            fact(t('ui.account.last_login', 'Dernière connexion'),        fmtDate(s.lastLogin) || '—') +
            fact(t('ui.account.active_sessions', 'Sessions actives'),     String(s.activeSessions || 0)) +
            fact(t('ui.account.pin_status', 'Code rapide'),
                 s.hasPin ? t('ui.account.pin_on', 'Actif') : t('ui.account.pin_off', 'Non configuré'));

        sessions.innerHTML = (s.sessions && s.sessions.length)
            ? s.sessions.map(function (x) {
                return '<div class="lpc-account-session">' +
                         '<span>' + esc(x.device || '—') +
                           '<br><span class="lpc-account-session-meta">' +
                             esc(fmtDate(x.loginTime)) + ' · ' + esc(x.ip || '') +
                           '</span>' +
                         '</span>' +
                         (x.current ? '<span class="lpc-account-badge">' + esc(t('ui.account.this_device', 'Cet appareil')) + '</span>' : '') +
                       '</div>';
              }).join('')
            : '<p class="lpc-account-hint">' + esc(t('ui.account.no_sessions', 'Aucune session enregistrée.')) + '</p>';
    }

    function fact(k, v) {
        return '<div class="lpc-account-fact">' +
                 '<div class="lpc-account-fact-k">' + esc(k) + '</div>' +
                 '<div class="lpc-account-fact-v">' + esc(v) + '</div>' +
               '</div>';
    }

    function fmtDate(raw) {
        if (!raw) return '';
        // MySQL DATETIME ("2026-07-28 14:33:00") is not a valid ISO string in
        // Safari — the space has to become a T or the parse returns NaN.
        var d = new Date(String(raw).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(raw);
        try {
            return d.toLocaleString(state.profile.locale === 'en' ? 'en-GB' : 'fr-FR', {
                day: '2-digit', month: 'short', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
        } catch (e) { return d.toLocaleString(); }
    }

    // =========================================================================
    // Password modal
    // =========================================================================
    var RULES = [
        { key: 'len',    test: function (v) { return v.length >= 8; },            label: '8 caractères minimum' },
        { key: 'upper',  test: function (v) { return /[A-Z]/.test(v); },          label: 'Une majuscule' },
        { key: 'digit',  test: function (v) { return /\d/.test(v); },             label: 'Un chiffre' },
        { key: 'symbol', test: function (v) { return /[!@#$%^&*(),.?":{}|<>]/.test(v); }, label: 'Un caractère spécial' }
    ];

    function openPasswordModal() {
        if (!window.LPC || !window.LPC.modal) return;

        var body = '' +
        '<div class="lpc-account" data-lpc-password>' +
          '<div class="lpc-account-grid">' +
            fieldFull('old_password', t('ui.account.old_password', 'Mot de passe actuel'),
                  '<input type="password" class="lpc-account-input" data-f="old_password" autocomplete="current-password">') +
            fieldFull('new_password', t('ui.account.new_password', 'Nouveau mot de passe'),
                  '<input type="password" class="lpc-account-input" data-f="new_password" autocomplete="new-password">' +
                  '<div class="lpc-account-meter" data-meter data-score="0"><span></span><span></span><span></span><span></span></div>' +
                  '<ul class="lpc-account-rules" data-rules>' +
                    RULES.map(function (r) {
                      return '<li data-rule="' + r.key + '" data-ok="false">' + esc(t('ui.account.rule_' + r.key, r.label)) + '</li>';
                    }).join('') +
                  '</ul>') +
            fieldFull('confirm_password', t('ui.account.confirm_new', 'Confirmer le nouveau mot de passe'),
                  '<input type="password" class="lpc-account-input" data-f="confirm_password" autocomplete="new-password">') +
          '</div>' +
          '<p class="lpc-account-hint" style="margin-top:.75rem;">' +
            esc(t('ui.account.password_warning', 'Vos autres sessions seront fermées et votre code rapide sera réinitialisé.')) +
          '</p>' +
          '<div style="display:flex;align-items:center;gap:.75rem;margin-top:.75rem;">' +
            '<button type="button" class="lpc-account-btn lpc-account-btn--primary" data-save-password disabled>' +
              esc(t('ui.account.update_password', 'Mettre à jour')) + '</button>' +
            '<span class="lpc-account-status" data-pw-status></span>' +
          '</div>' +
        '</div>';

        window.LPC.modal.custom({
            title:    t('ui.account.password_title', 'Changer le mot de passe'),
            bodyHtml: body,
            buttons:  [{ label: t('ui.common.close', 'Fermer'), value: null, variant: 'secondary', primary: true }]
        });

        afterOpen('[data-lpc-password]', function (el) {
            var oldI  = el.querySelector('[data-f="old_password"]');
            var newI  = el.querySelector('[data-f="new_password"]');
            var repI  = el.querySelector('[data-f="confirm_password"]');
            var save  = el.querySelector('[data-save-password]');
            var stat  = el.querySelector('[data-pw-status]');
            var meter = el.querySelector('[data-meter]');

            function evaluate() {
                var v = newI.value;
                var score = 0;
                RULES.forEach(function (r) {
                    var ok = r.test(v);
                    if (ok) score++;
                    var li = el.querySelector('[data-rule="' + r.key + '"]');
                    if (li) li.setAttribute('data-ok', String(ok));
                });
                meter.setAttribute('data-score', String(score));

                var matches = repI.value === '' || repI.value === v;
                if (!matches) setStatus(stat, t('ui.account.mismatch', 'Les mots de passe ne correspondent pas.'), 'err');
                else if (stat.classList.contains('lpc-account-status--err')) setStatus(stat, '');

                save.disabled = !(score === RULES.length && oldI.value !== '' && repI.value === v && v !== '');
            }

            [oldI, newI, repI].forEach(function (i) { i.addEventListener('input', evaluate); });
            oldI.focus();

            save.addEventListener('click', function () {
                save.disabled = true;
                setStatus(stat, t('ui.account.saving', 'Enregistrement…'), 'busy');
                api('change_password', {
                    old_password:     oldI.value,
                    new_password:     newI.value,
                    confirm_password: repI.value
                }).then(function (res) {
                    setStatus(stat, res.message, res.status === 'success' ? 'ok' : 'err');
                    if (res.status === 'success') {
                        [oldI, newI, repI].forEach(function (i) { i.value = ''; });
                        evaluate();
                        notify(res.message, 'ok');
                    } else {
                        save.disabled = false;
                    }
                });
            });
        });
    }

    // =========================================================================
    // Boot
    // =========================================================================
    function init() {
        wireMenu();
        syncQuickStrip();

        // Keep the collapse toggle's server-side mirror up to date. lpc-shell.js
        // owns the localStorage write (per-device); we only record the value so
        // a NEW device starts in the same state. Wrapped so a missing helper
        // cannot break the toggle itself.
        if (window.LPC && typeof window.LPC.setSidebarCollapsed === 'function') {
            var original = window.LPC.setSidebarCollapsed;
            window.LPC.setSidebarCollapsed = function (collapsed) {
                original(collapsed);
                state.profile.sidebarCollapsed = !!collapsed;
                savePrefs({ sidebar_collapsed: collapsed ? '1' : '0' });
            };
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.LPC = window.LPC || {};
    window.LPC.userMenu = {
        open:     openMenu,
        close:    closeMenu,
        profile:  openProfileModal,
        password: openPasswordModal,
        signOut:  confirmSignOut,
        state:    state
    };
})();
