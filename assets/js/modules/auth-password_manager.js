/**
 * assets/js/modules/auth-password_manager.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from public/auth/password_manager.php (Sprint 6 D2).
 *
 * Original block was ~5,046 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
const CSRF = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) ? window.LPC.rbac.csrf : { token:'', header:'X-CSRF-Token' };

function setMode(m) {
    const isChange = (m === 'change');
    const panelChange  = document.getElementById('form-change');
    const panelRequest = document.getElementById('form-request');
    const tabChange    = document.getElementById('tab-change');
    const tabRequest   = document.getElementById('tab-request');

    // Panels toggle via the hidden attribute (aria-friendly) instead of a CSS class.
    panelChange.hidden  = !isChange;
    panelRequest.hidden =  isChange;
    panelChange.classList.toggle('hidden',  !isChange);
    panelRequest.classList.toggle('hidden',  isChange);

    // Tabs: active class + aria-selected + roving tabindex.
    tabChange.classList.toggle('active',    isChange);
    tabRequest.classList.toggle('active',  !isChange);
    tabChange.setAttribute('aria-selected',  String(isChange));
    tabRequest.setAttribute('aria-selected', String(!isChange));
    tabChange.tabIndex  = isChange ? 0 : -1;
    tabRequest.tabIndex = isChange ? -1 : 0;

    hideAlert();
}
function toggleVis(id, iconId) {
    const el = document.getElementById(id);
    const ic = document.getElementById(iconId);
    if (el.type === 'password') { el.type = 'text';   ic.className = 'fas fa-eye-slash'; }
    else                        { el.type = 'password';ic.className = 'fas fa-eye'; }
}
function alert_(kind, msg) {
    const el = document.getElementById('alert');
    el.className = 'mb-4 p-3 rounded-lg text-sm font-medium border ' +
        (kind === 'ok'
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-100'
            : 'bg-red-500/10 border-red-500/30 text-red-100');
    el.textContent = msg;
    el.classList.remove('hidden');
}
function hideAlert(){ document.getElementById('alert').classList.add('hidden'); }

const npc = document.getElementById('new_password_change');
const cpc = document.getElementById('confirm_change');
const btnC = document.getElementById('btn-change');
let regexOk = false, matchOk = false;

function checkRulesChange() {
    const v = npc.value;
    const rules = {
        'ruleC-len' : v.length >= 8,
        'ruleC-cap' : /[A-Z]/.test(v),
        'ruleC-num' : /\d/.test(v),
        'ruleC-spec': /[!@#$%^&*(),.?":{}|<>]/.test(v),
    };
    let all = true;
    for (const [id, ok] of Object.entries(rules)) {
        const el = document.getElementById(id);
        el.className = ok ? 'valid' : 'invalid';
        if (!ok) all = false;
    }
    regexOk = all;
    checkMatch();
}
function checkMatch() {
    const mt = document.getElementById('match-change');
    if (cpc.value.length === 0) { mt.classList.add('hidden'); matchOk = false; }
    else if (npc.value === cpc.value) {
        mt.textContent = '✓ Les mots de passe correspondent';
        mt.className = 'text-xs mt-1 font-bold text-lpc-light block';
        matchOk = true;
    } else {
        mt.textContent = '✗ Les mots de passe ne correspondent pas';
        mt.className = 'text-xs mt-1 font-bold text-red-400 block';
        matchOk = false;
    }
    const form = document.getElementById('form-change');
    const code = form.querySelector('[name=employee_code]').value.trim();
    const old  = form.querySelector('[name=old_password]').value;
    const ok = code && old && regexOk && matchOk;
    btnC.disabled = !ok;
    btnC.classList.toggle('opacity-50', !ok);
    btnC.classList.toggle('cursor-not-allowed', !ok);
    btnC.classList.toggle('hover:opacity-90', ok);
}
npc.addEventListener('input', checkRulesChange);
cpc.addEventListener('input', checkMatch);
document.getElementById('form-change').addEventListener('input', checkMatch);

document.getElementById('form-change').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (btnC.disabled) return;
    const fd = new FormData(e.target);
    fd.append('action', 'change');
    try {
        const r = await fetch('/api/v1/password_controller.php', {
            method: 'POST',
            headers: { [CSRF.header]: CSRF.token },
            body: fd,
        });
        const j = await r.json();
        if (j.status === 'success') {
            alert_('ok', j.message);
            setTimeout(() => { window.location.href = '/index.php'; }, 1800);
        } else {
            alert_('err', j.message);
        }
    } catch (err) { alert_('err', 'Erreur de connexion.'); }
});

document.getElementById('form-request').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'request_reset');
    try {
        const r = await fetch('/api/v1/password_controller.php', {
            method: 'POST',
            headers: { [CSRF.header]: CSRF.token },
            body: fd,
        });
        const j = await r.json();
        alert_(j.status === 'success' ? 'ok' : 'err', j.message);
        if (j.status === 'success') e.target.reset();
    } catch (err) { alert_('err', 'Erreur de connexion.'); }
});
