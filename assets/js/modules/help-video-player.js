/**
 * assets/js/modules/help-video-player.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the training-video player (modules/help/video.php).
 *
 * The page is rendered by the server (chapters and transcript are buttons
 * carrying data-start / data-end in milliseconds); this file only:
 *   · seeks when a chapter or a transcript line is clicked, and plays;
 *   · highlights the line being spoken and the current chapter, and keeps the
 *     line in view inside the transcript panel — until the reader scrolls the
 *     panel themselves, then « Suivre la vidéo » resumes it;
 *   · starts at ?t= (seconds), the moment the search, the chat or the "?"
 *     button linked to, and keeps t= in the address bar on pause, so a refresh
 *     or a copied address resumes there;
 *   · filters the transcript (accent-insensitive);
 *   · copies a link to the current moment.
 *
 * No innerHTML: text from the transcript is never re-parsed as markup.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var video = document.getElementById('vp-video');
    var wrap  = document.getElementById('vp');
    var list  = document.getElementById('vp-transcript');
    if (!video || !wrap || !list) return;

    var data = {};
    try { data = JSON.parse((document.getElementById('vp-data') || {}).textContent || '{}'); } catch (e) { data = {}; }
    var S = data.strings || {};

    function items(root) {
        return [].slice.call(root ? root.querySelectorAll('.lpc-vp-item') : []).map(function (b) {
            return { el: b, li: b.parentNode, s: parseInt(b.getAttribute('data-start'), 10) || 0,
                     e: parseInt(b.getAttribute('data-end'), 10) || 0,
                     text: fold(b.textContent || '') };
        });
    }
    function fold(s) {
        return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }
    function timecode(sec) {
        sec = Math.max(0, Math.floor(sec));
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
        var mm = (m < 10 ? '0' : '') + m, ss = (s < 10 ? '0' : '') + s;
        return h > 0 ? h + ':' + mm + ':' + ss : mm + ':' + ss;
    }
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var cues  = items(list);
    var chaps = items(document.getElementById('vp-chapters'));

    // ------------------------------------------------------------ seeking
    function seek(ms, play) {
        try { video.currentTime = ms / 1000; } catch (e) { /* not seekable yet */ }
        if (play) {
            var p = video.play();
            if (p && p.catch) p.catch(function () { /* autoplay refused: stays paused there */ });
        }
        update();
    }

    wrap.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.lpc-vp-item') : null;
        if (!b) return;
        e.preventDefault();
        setFollow(true);
        seek(parseInt(b.getAttribute('data-start'), 10) || 0, true);
    });

    // --------------------------------------------------------- highlight
    /** The last item whose start is at or before ms (items are in time order). */
    function indexAt(arr, ms) {
        var lo = 0, hi = arr.length - 1, ans = -1;
        while (lo <= hi) {
            var mid = (lo + hi) >> 1;
            if (arr[mid].s <= ms) { ans = mid; lo = mid + 1; } else { hi = mid - 1; }
        }
        return ans;
    }

    var cur = -1, curCh = -1;
    function mark(arr, from, to) {
        if (from >= 0 && arr[from]) { arr[from].el.classList.remove('is-current'); arr[from].el.removeAttribute('aria-current'); }
        if (to >= 0 && arr[to])     { arr[to].el.classList.add('is-current');      arr[to].el.setAttribute('aria-current', 'true'); }
    }
    function update() {
        var ms = video.currentTime * 1000 + 60;
        var i = indexAt(cues, ms);
        if (i !== cur) {
            mark(cues, cur, i);
            cur = i;
            if (i >= 0 && follow) keepInView(cues[i].el);
        }
        var c = indexAt(chaps, ms);
        if (c !== curCh) { mark(chaps, curCh, c); curCh = c; }
    }
    video.addEventListener('timeupdate', update);
    video.addEventListener('seeked', update);

    // ---------------------------------------------------------- following
    var follow = true;
    var followEl = document.getElementById('vp-follow');

    function keepInView(el) {
        if (el.parentNode.hidden) return;
        var lr = list.getBoundingClientRect(), er = el.getBoundingClientRect();
        if (er.top >= lr.top + lr.height * 0.15 && er.bottom <= lr.bottom - lr.height * 0.3) return;
        var target = list.scrollTop + (er.top - lr.top) - lr.height * 0.3;
        if (list.scrollTo) list.scrollTo({ top: target, behavior: reduceMotion ? 'auto' : 'smooth' });
        else list.scrollTop = target;
    }

    function setFollow(on) {
        if (follow === on) return;
        follow = on;
        if (!followEl) return;
        followEl.textContent = '';
        if (on) {
            followEl.textContent = S.following || '';
            if (cur >= 0) keepInView(cues[cur].el);
        } else {
            followEl.appendChild(document.createTextNode((S.paused || '') + ' '));
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = S.resume || 'Suivre la vidéo';
            b.addEventListener('click', function () { setFollow(true); });
            followEl.appendChild(b);
        }
    }
    // The reader's own scrolling of the panel (not ours) pauses following.
    ['wheel', 'touchmove'].forEach(function (ev) {
        list.addEventListener(ev, function () { setFollow(false); }, { passive: true });
    });
    list.addEventListener('keydown', function (e) {
        if (['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End'].indexOf(e.key) >= 0) setFollow(false);
    });

    // ------------------------------------------------------------- filter
    var filter = document.getElementById('vp-filter');
    var empty = null;
    if (filter) {
        filter.addEventListener('input', function () {
            var q = fold(filter.value.trim());
            var shown = 0;
            cues.forEach(function (c) {
                var hit = !q || c.text.indexOf(q) >= 0;
                c.li.hidden = !hit;
                if (hit) shown++;
            });
            [].forEach.call(list.querySelectorAll('.lpc-vp-chap-sep'), function (li) { li.hidden = !!q; });
            if (empty) { empty.remove(); empty = null; }
            if (q && !shown) {
                empty = document.createElement('li');
                empty.className = 'lpc-vp-chap-sep';
                empty.textContent = (S.noMatch || '').replace('{q}', filter.value.trim());
                list.appendChild(empty);
            }
            if (!q) { setFollow(true); }
        });
        filter.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && filter.value) {
                e.preventDefault();
                filter.value = '';
                filter.dispatchEvent(new Event('input'));
            }
        });
    }

    // ------------------------------------------------------- link + t=
    function momentUrl(sec) {
        var u = new URL(window.location.href);
        u.searchParams.set('t', String(Math.floor(sec)));
        return u;
    }
    video.addEventListener('pause', function () {
        try { history.replaceState(null, '', momentUrl(video.currentTime).toString()); } catch (e) { /* ignore */ }
    });

    var copyBtn = document.getElementById('vp-copy');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var sec = video.currentTime;
            var url = momentUrl(sec).toString();
            var ok = function () {
                var msg = (S.copied || '').replace('{t}', timecode(sec));
                if (window.LPC && window.LPC.toast) window.LPC.toast(msg, 'success');
            };
            var fail = function () {
                if (window.LPC && window.LPC.toast) window.LPC.toast(S.copyFail || '', 'error');
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(ok, fail);
            } else {
                var ta = document.createElement('textarea');
                ta.value = url;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy') ? ok() : fail(); } catch (e) { fail(); }
                ta.remove();
            }
        });
    }

    // -------------------------------------------------------------- start
    var start = parseFloat(wrap.getAttribute('data-start') || '0') || 0;
    if (start > 0) {
        // Show where we are going before the video has even loaded.
        var i0 = indexAt(cues, start * 1000 + 60);
        if (i0 >= 0) { mark(cues, -1, i0); cur = i0; keepInView(cues[i0].el); }
        var go = function () { seek(start * 1000, true); };
        if (video.readyState >= 1) go();
        else video.addEventListener('loadedmetadata', go, { once: true });
    }
})();
