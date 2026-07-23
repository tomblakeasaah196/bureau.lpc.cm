/**
 * assets/js/modules/auth-password_reset.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from public/auth/password_reset.php (Sprint 6 D2).
 *
 * Original block was ~2,501 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
const CSRF = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) ? window.LPC.rbac.csrf : {token:'',header:'X-CSRF-Token'};
const np = document.getElementById('np'), cp = document.getElementById('cp'), btn = document.getElementById('btn');
let ro = false, mo = false;
function tv(){ np.type = np.type === 'password' ? 'text' : 'password'; document.getElementById('eye').className = np.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash'; }
function rules(){
    const v = np.value;
    const R = { 'r-len': v.length>=8, 'r-cap': /[A-Z]/.test(v), 'r-num': /\d/.test(v), 'r-spec': /[!@#$%^&*(),.?":{}|<>]/.test(v) };
    let all = true;
    for (const [id, ok] of Object.entries(R)) { document.getElementById(id).className = ok?'valid':'invalid'; if(!ok) all = false; }
    ro = all;
    match();
}
function match(){
    const m = document.getElementById('mt');
    if (!cp.value){ m.classList.add('hidden'); mo=false; }
    else if (np.value===cp.value){ m.textContent='✓ Correspondance'; m.className='text-xs mt-1 font-bold text-lpc-light block'; mo=true; }
    else { m.textContent='✗ Les mots de passe ne correspondent pas'; m.className='text-xs mt-1 font-bold text-red-400 block'; mo=false; }
    const ok = ro && mo;
    btn.disabled = !ok;
    btn.classList.toggle('opacity-50', !ok);
    btn.classList.toggle('cursor-not-allowed', !ok);
    btn.classList.toggle('hover:opacity-90', ok);
}
np.addEventListener('input', rules); cp.addEventListener('input', match);

function alert_(kind, msg){
    const el = document.getElementById('alert');
    el.className = 'mb-4 p-3 rounded-lg text-sm font-medium border ' +
        (kind === 'ok' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-100'
                       : 'bg-red-500/10 border-red-500/30 text-red-100');
    el.textContent = msg;
    el.classList.remove('hidden');
}

document.getElementById('form-reset').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (btn.disabled) return;
    const fd = new FormData(e.target);
    fd.append('action', 'reset');
    try {
        const r = await fetch('/api/v1/password_controller.php', {
            method: 'POST',
            headers: { [CSRF.header]: CSRF.token },
            body: fd,
        });
        const j = await r.json();
        if (j.status === 'success') { alert_('ok', j.message); setTimeout(()=> location.href='/index.php', 1800); }
        else alert_('err', j.message);
    } catch (err) { alert_('err', 'Erreur de connexion.'); }
});
