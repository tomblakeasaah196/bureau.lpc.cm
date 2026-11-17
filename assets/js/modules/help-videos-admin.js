/**
 * assets/js/modules/help-videos-admin.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — modules/admin/help_videos.php: upload, publish, delete the
 * training videos. Talks to api/v1/help_videos_controller.php.
 *
 * THE UPLOAD, IN THREE STEPS
 *   1. upload_start  — the MP4's size and name; the server answers an id.
 *   2. upload_chunk  — the MP4 in pieces of chunk_bytes (5 MB: the live
 *      upload_max_filesize is 20M and a video is 20-60 MB), each as a raw
 *      request body at its offset. A piece that fails is retried; if the
 *      server says it already has more (409), the upload resumes from there.
 *   3. publish       — the manifest, captions and poster as ordinary files;
 *      the server checks the MP4 is complete and is the manifest's, then
 *      registers it (or replaces the video with the same slug).
 *
 * The manifest is read here first, so a wrong or missing file is reported
 * before 50 MB are sent. Every write carries the CSRF token as X-CSRF-Token
 * (window.LPC.rbac.csrf.token, else the form's _csrf field).
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var API = '/api/v1/help_videos_controller.php';
    var EN = (document.documentElement.lang || 'fr') === 'en';
    function tr(fr, en) { return EN ? en : fr; }

    function csrf() {
        var c = window.LPC && window.LPC.rbac && window.LPC.rbac.csrf;
        if (c && c.token) return c.token;
        var el = document.querySelector('input[name="_csrf"]');
        return el ? el.value : '';
    }
    function toast(msg, type) {
        if (window.LPC && typeof window.LPC.toast === 'function') window.LPC.toast(msg, type || 'success');
    }
    function size(bytes) {
        var mb = bytes / 1048576;
        return EN ? mb.toFixed(1) + ' MB' : mb.toFixed(1).replace('.', ',') + ' Mo';
    }
    function ext(name) { var m = /\.([a-z0-9]+)$/i.exec(name || ''); return m ? m[1].toLowerCase() : ''; }

    /** POST a FormData (or a raw body with a query string); resolves {ok, status, j}. */
    function post(query, body, signal, raw) {
        var headers = { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' };
        if (raw) headers['Content-Type'] = 'application/octet-stream';
        return fetch(API + (query ? '?' + query : ''), {
            method: 'POST', credentials: 'same-origin', headers: headers, body: body, signal: signal
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, j: j }; });
        });
    }
    function form(action, fields) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('_csrf', csrf());
        Object.keys(fields || {}).forEach(function (k) { if (fields[k] != null) fd.append(k, fields[k]); });
        return fd;
    }

    // =====================================================================
    // Choosing the files
    // =====================================================================
    var box = document.getElementById('vadm-upload');
    var input = document.getElementById('vadm-files');
    var drop = document.getElementById('vadm-drop');
    var summary = document.getElementById('vadm-summary');
    var sendBtn = document.getElementById('vadm-send');
    var cancelBtn = document.getElementById('vadm-cancel');
    var status = document.getElementById('vadm-status');
    var pubBox = document.getElementById('vadm-published');
    var progWrap = document.getElementById('vadm-progress-wrap');
    var prog = document.getElementById('vadm-progress');
    var progText = document.getElementById('vadm-progress-text');

    var existing = {};
    try { existing = JSON.parse((box && box.getAttribute('data-slugs')) || '{}') || {}; } catch (e) { existing = {}; }

    var set = null;        // { video, manifest, captions, poster, m }
    var busy = false;
    var aborter = null;

    function say(msg, ok) {
        if (!status) return;
        status.textContent = msg || '';
        status.style.color = ok === false ? 'var(--lpc-status-danger-fg, #B91C1C)' : 'var(--lpc-ink-soft, #667085)';
    }

    function line(ok, label, text) {
        var li = document.createElement('li');
        var mark = document.createElement('span');
        mark.className = ok ? 'lpc-vadm-ok' : 'lpc-vadm-bad';
        mark.textContent = ok ? '✓' : '✗';
        li.appendChild(mark);
        var b = document.createElement('strong');
        b.textContent = label + ' :';
        li.appendChild(b);
        var s = document.createElement('span');
        s.textContent = text;
        li.appendChild(s);
        summary.appendChild(li);
        return ok;
    }

    function pick(files) {
        if (busy) return;
        var by = { video: [], manifest: [], captions: [], poster: [] };
        [].forEach.call(files || [], function (f) {
            var e = ext(f.name);
            if (e === 'mp4' || e === 'm4v') by.video.push(f);
            else if (e === 'json') by.manifest.push(f);
            else if (e === 'vtt') by.captions.push(f);
            else if (['jpg', 'jpeg', 'png', 'webp'].indexOf(e) >= 0) by.poster.push(f);
        });
        set = { video: by.video[0] || null, manifest: by.manifest[0] || null,
                captions: by.captions[0] || null, poster: by.poster[0] || null, m: null };
        summary.textContent = '';
        summary.hidden = false;
        sendBtn.disabled = true;
        say('');

        var many = ['video', 'manifest', 'captions', 'poster'].filter(function (k) { return by[k].length > 1; });
        if (many.length) {
            line(false, tr('Fichiers', 'Files'), tr('une seule vidéo à la fois : un fichier de chaque sorte.', 'one video at a time: one file of each kind.'));
            return;
        }
        if (!set.manifest) {
            line(false, tr('Manifeste', 'Manifest'), tr('ajoutez le fichier .manifest.json de la vidéo.', 'add the video\'s .manifest.json file.'));
            if (set.video) line(true, tr('Vidéo', 'Video'), set.video.name + ' (' + size(set.video.size) + ')');
            return;
        }
        set.manifest.text().then(function (txt) {
            var m;
            try { m = JSON.parse(txt); } catch (e) { m = null; }
            if (!m || m.schema !== 1 || typeof m.slug !== 'string' || !/^[a-z0-9][a-z0-9-]*$/.test(m.slug) || !m.title || !m.file) {
                line(false, tr('Manifeste', 'Manifest'), set.manifest.name + tr(' n\'est pas un manifeste valide (schema 1, slug, title, file).', ' is not a valid manifest (schema 1, slug, title, file).'));
                return;
            }
            set.m = m;
            var replace = Object.prototype.hasOwnProperty.call(existing, m.slug);
            var ok = line(true, tr('Manifeste', 'Manifest'), '« ' + m.title + ' » (' + m.slug + ') — ' +
                (replace ? tr('remplacera la vidéo existante', 'will replace the existing video')
                         : tr('nouvelle vidéo', 'new video')) +
                ', ' + (m.chapters || []).length + tr(' chapitre(s), ', ' chapter(s), ') + (m.cues || []).length + tr(' réplique(s)', ' line(s)'));

            var same = function (f, want) { return f && want && f.name.toLowerCase() === String(want).toLowerCase(); };
            if (!set.video) ok = line(false, tr('Vidéo', 'Video'), tr('attendue : ', 'expected: ') + m.file) && ok;
            else if (!same(set.video, m.file)) ok = line(false, tr('Vidéo', 'Video'), set.video.name + tr(' n\'est pas celle du manifeste (', ' is not the manifest\'s (') + m.file + ')') && ok;
            else line(true, tr('Vidéo', 'Video'), set.video.name + ' (' + size(set.video.size) + ')');

            if (m.captions) {
                if (!set.captions) ok = line(false, tr('Sous-titres', 'Captions'), tr('attendus : ', 'expected: ') + m.captions) && ok;
                else line(true, tr('Sous-titres', 'Captions'), set.captions.name);
            }
            if (m.poster) {
                if (!set.poster) ok = line(false, tr('Affiche', 'Poster'), tr('attendue : ', 'expected: ') + m.poster) && ok;
                else line(true, tr('Affiche', 'Poster'), set.poster.name);
            }
            line(true, tr('Visible par', 'Visible to'), m.audience === 'all'
                ? tr('tout le monde', 'everyone')
                : tr('qui peut ouvrir une de ses pages : ', 'whoever can open one of its pages: ') + (m.pages || []).join(', '));
            sendBtn.disabled = !ok;
            if (ok) say(tr('Prêt à envoyer.', 'Ready to upload.'));
        });
    }

    if (input) input.addEventListener('change', function () { pick(input.files); });
    if (drop) {
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
        });
        drop.addEventListener('drop', function (e) { if (e.dataTransfer) pick(e.dataTransfer.files); });
    }

    // =====================================================================
    // Sending
    // =====================================================================
    function progress(sent, total, label) {
        var pct = total ? Math.floor(sent * 100 / total) : 0;
        prog.firstElementChild.style.width = pct + '%';
        prog.setAttribute('aria-valuenow', String(pct));
        progText.textContent = label || (pct + ' % — ' + size(sent) + ' / ' + size(total));
    }

    function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    /** Send every piece from `offset`, retrying a failed piece 4 times. */
    function sendChunks(id, file, chunk, offset, signal) {
        var tries = 0;
        function next() {
            if (offset >= file.size) return Promise.resolve();
            var piece = file.slice(offset, Math.min(offset + chunk, file.size));
            return post('action=upload_chunk&upload_id=' + encodeURIComponent(id) + '&offset=' + offset, piece, signal, true)
                .then(function (res) {
                    if (res.ok && res.j.status === 'success') {
                        offset = res.j.received; tries = 0;
                        progress(offset, file.size);
                        return next();
                    }
                    if (res.status === 409 && typeof res.j.received === 'number') {   // resume where the server is
                        offset = res.j.received;
                        return next();
                    }
                    if (res.status >= 500 && tries < 4) { tries++; return wait(1000 * tries).then(next); }
                    throw new Error((res.j && res.j.message) || tr('Envoi refusé.', 'Upload refused.'));
                }, function (err) {
                    if (signal.aborted) throw err;
                    if (tries < 4) { tries++; return wait(1000 * tries).then(next); }   // network hiccup
                    throw new Error(tr('Connexion perdue pendant l\'envoi.', 'Connection lost during the upload.'));
                });
        }
        return next();
    }

    function setBusy(on) {
        busy = on;
        sendBtn.disabled = on;
        cancelBtn.hidden = !on;
        if (input) input.disabled = on;
        progWrap.hidden = !on && !progWrap.dataset.keep;
    }

    window.addEventListener('beforeunload', function (e) {
        if (busy) { e.preventDefault(); e.returnValue = ''; }
    });

    if (cancelBtn) cancelBtn.addEventListener('click', function () { if (aborter) aborter.abort(); });

    if (sendBtn) sendBtn.addEventListener('click', function () {
        if (!set || !set.m || !set.video || busy) return;
        aborter = new AbortController();
        var signal = aborter.signal;
        var file = set.video;
        setBusy(true);
        progWrap.hidden = false;
        progress(0, file.size);
        say(tr('Envoi de la vidéo…', 'Uploading the video…'));

        post('', form('upload_start', { size: file.size, name: file.name }), signal)
            .then(function (res) {
                if (!res.ok || res.j.status !== 'success') throw new Error((res.j && res.j.message) || tr('L\'envoi n\'a pas pu commencer.', 'The upload could not start.'));
                var id = res.j.upload_id;
                return sendChunks(id, file, res.j.chunk_bytes || 5242880, 0, signal).then(function () { return id; });
            })
            .then(function (id) {
                progress(file.size, file.size, tr('Enregistrement de la vidéo et de sa transcription…', 'Saving the video and its transcript…'));
                say(tr('Enregistrement…', 'Saving…'));
                return post('', form('publish', {
                    upload_id: id,
                    published: pubBox && pubBox.checked ? '1' : '0',
                    manifest: set.manifest, captions: set.captions, poster: set.poster
                }), signal);
            })
            .then(function (res) {
                if (!res.ok || res.j.status !== 'success') throw new Error((res.j && res.j.message) || tr('Enregistrement impossible.', 'Could not save.'));
                busy = false;
                var v = res.j.video || {};
                toast(tr((res.j.replaced ? 'Vidéo remplacée : « ' : 'Vidéo ajoutée : « ') + v.title + ' » (' + res.j.chapters + ' chapitres, ' + res.j.cues + ' répliques).',
                         (res.j.replaced ? 'Video replaced: “' : 'Video added: “') + v.title + '” (' + res.j.chapters + ' chapters, ' + res.j.cues + ' lines).'), 'success');
                say(tr('Terminé.', 'Done.'), true);
                setTimeout(function () { window.location.reload(); }, 1200);
            })
            .catch(function (err) {
                setBusy(false);
                progWrap.hidden = true;
                var msg = signal.aborted ? tr('Envoi annulé.', 'Upload cancelled.') : (err && err.message) || tr('Échec.', 'Failed.');
                say(msg, false);
                if (!signal.aborted) toast(msg, 'error');
                sendBtn.disabled = !(set && set.m);
            });
    });

    // =====================================================================
    // The list: publish / unpublish, delete
    // =====================================================================
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-vadm-toggle]') : null;
        if (t) {
            e.preventDefault();
            post('', form('toggle', { id: t.getAttribute('data-vadm-toggle') })).then(function (res) {
                if (!res.ok || res.j.status !== 'success') { toast((res.j && res.j.message) || tr('Échec.', 'Failed.'), 'error'); return; }
                var on = res.j.is_published === 1;
                t.className = 'lpc-help-badge ' + (on ? 'lpc-help-badge--on' : 'lpc-help-badge--off');
                t.textContent = on ? tr('Publiée', 'Published') : tr('Brouillon', 'Draft');
                toast(on ? tr('Vidéo publiée.', 'Video published.') : tr('Vidéo retirée : les lecteurs ne la voient plus.', 'Video unpublished: readers no longer see it.'));
            });
            return;
        }
        var d = e.target.closest ? e.target.closest('[data-vadm-delete]') : null;
        if (!d) return;
        e.preventDefault();
        var title = d.getAttribute('data-title') || '';
        var msg = tr('Supprimer la vidéo « ' + title + ' » ? Le fichier, ses sous-titres et sa transcription sont effacés du serveur ; les liens vers elle ne fonctionneront plus.',
                     'Delete the video “' + title + '”? The file, its captions and its transcript are erased from the server; links to it will stop working.');
        var ask = (window.LPC && window.LPC.modal && window.LPC.modal.confirm)
            ? window.LPC.modal.confirm(msg, { danger: true, title: tr('Supprimer la vidéo', 'Delete the video'), confirmLabel: tr('Supprimer', 'Delete') })
            : Promise.resolve(window.confirm(msg));
        ask.then(function (yes) {
            if (!yes) return;
            post('', form('delete', { id: d.getAttribute('data-vadm-delete') })).then(function (res) {
                if (!res.ok || res.j.status !== 'success') { toast((res.j && res.j.message) || tr('Échec.', 'Failed.'), 'error'); return; }
                var row = d.closest('tr');
                if (row) row.remove();
                toast(tr('Vidéo supprimée.', 'Video deleted.'));
            });
        });
    });
})();
