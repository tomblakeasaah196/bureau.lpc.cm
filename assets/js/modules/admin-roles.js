/**
 * assets/js/modules/admin-roles.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/admin/roles.php (Sprint 6 D2).
 *
 * Original block contained PHP interpolations; they were relocated to
 * `window.PAGE_DATA` in a small hoister block just above the src include.
 * -----------------------------------------------------------------------------
 */

// D2 hoister prelude: populate window.PAGE_DATA from the inert JSON block.
(function(){
    if (window.PAGE_DATA) return;
    var el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

const CAN_EDIT   = window.PAGE_DATA.v1;
const CAN_CREATE = window.PAGE_DATA.v2;
const CAN_DELETE = window.PAGE_DATA.v3;
const LANG       = "window.PAGE_DATA.v4";
const SYSTEM_ROLES = ['admin','accountant','operations','driver'];

let state = { roles: [], selectedRoleId: null, permissions: [], currentGranted: new Set() };

document.addEventListener('DOMContentLoaded', () => {
    Promise.all([loadRoles(), loadPermissions()]);
});

async function api(action, payload, method='GET') {
    const url = '/api/v1/rbac_controller.php?action=' + encodeURIComponent(action)
              + (method==='GET' && payload ? '&' + new URLSearchParams(payload).toString() : '');
    const opts = { method, headers: { 'X-Requested-With':'XMLHttpRequest' } };
    if (method === 'POST') { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(payload||{}); }
    const r = await fetch(url, opts);
    const j = await r.json();
    if (j.status !== 'success') throw new Error(j.message || 'Erreur');
    return j.data;
}

function toast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'fixed bottom-6 right-6 z-50 px-4 py-3 rounded-lg text-sm font-medium '
                + (type==='success'
                    ? 'bg-emerald-500/20 text-emerald-100 border border-emerald-400/30'
                    : 'bg-red-500/20 text-red-100 border border-red-400/30');
    setTimeout(() => t.classList.add('hidden'), 3200);
    t.classList.remove('hidden');
}

async function loadRoles() {
    try {
        state.roles = await api('list_roles');
        document.getElementById('roles-count').textContent = state.roles.length;
        renderRoles();
    } catch(e) { toast(e.message, 'error'); }
}

async function loadPermissions() {
    try {
        const grouped = await api('list_permissions');
        state.permissions = grouped;
    } catch(e) { toast(e.message, 'error'); }
}

function renderRoles() {
    const ul = document.getElementById('roles-list');
    ul.innerHTML = '';
    state.roles.forEach(r => {
        const isSys = SYSTEM_ROLES.includes(r.name);
        const selected = state.selectedRoleId === r.id;
        const li = document.createElement('li');
        li.className = 'rounded-lg border transition-all ' + (selected
            ? 'border-emerald-400/40 bg-emerald-500/10'
            : 'border-white/5 hover:border-white/15 hover:bg-white/5');
        li.innerHTML = LPC.html`
            <div class="flex items-center gap-2 p-3">
                <button onclick="selectRole(${r.id})" class="flex-1 text-left">
                    <div class="font-medium text-white flex items-center gap-2">
                        ${escapeHtml(r.name)}
                        ${isSys ? '<span class="chip text-emerald-300">système</span>' : ''}
                    </div>
                    <div class="text-[11px] text-white/70 mt-0.5">
                        ${r.user_count} window.PAGE_DATA.v5
                        &middot; ${r.perm_count} permissions
                    </div>
                </button>
                ${(!isSys && CAN_EDIT) ? `<button onclick="openRenameRoleModal(${r.id}, '${escapeAttr(r.name)}')" title="Renommer" class="text-white/70 hover:text-white p-1" aria-label="Renommer">✎</button>` : ''}
                ${(!isSys && CAN_DELETE) ? `<button onclick="deleteRole(${r.id}, '${escapeAttr(r.name)}')" title="Supprimer" class="text-red-400/70 hover:text-red-400 p-1" aria-label="Supprimer">🗑</button>` : ''}
            </div>`;
        ul.appendChild(li);
    });
}

async function selectRole(id) {
    state.selectedRoleId = id;
    renderRoles();
    document.getElementById('matrix-empty').classList.add('hidden');
    document.getElementById('matrix-header').classList.remove('hidden');
    document.getElementById('matrix-body').classList.remove('hidden');
    document.getElementById('matrix-body').innerHTML = '<div class="text-white/70 text-sm p-4">Chargement…</div>';

    const data = await api('get_role_permissions', { role_id: id });
    const role = data.role;
    const grouped = data.permissions;
    state.currentGranted = new Set(data.granted);

    document.getElementById('matrix-role-name').textContent = role.name;
    const totalGranted = data.granted.length;
    const totalPossible = Object.values(grouped).reduce((a, arr) => a + arr.length, 0);
    document.getElementById('matrix-role-meta').textContent =
        `${totalGranted} / ${totalPossible} permissions accordées`;

    renderMatrix(grouped);
}

function renderMatrix(grouped) {
    const body = document.getElementById('matrix-body');
    body.innerHTML = '';
    const disabled = !CAN_EDIT ? 'disabled' : '';

    Object.keys(grouped).sort().forEach(mod => {
        const perms = grouped[mod];
        const sec = document.createElement('div');
        sec.className = 'glass rounded-lg overflow-hidden';
        sec.dataset.mod = mod;
        const grantedInMod = perms.filter(p => p.granted).length;
        sec.innerHTML = LPC.html`
            <div class="flex items-center justify-between px-4 py-2 bg-white/5 border-b border-white/10">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-widest text-emerald-300">${escapeHtml(mod)}</span>
                    <span class="text-[10px] text-white/70" data-count>${grantedInMod}/${perms.length}</span>
                </div>
                ${CAN_EDIT ? `
                <div class="flex gap-2 text-[11px]">
                    <button onclick="moduleToggle('${escapeAttr(mod)}', true)"  class="text-emerald-300 hover:underline">window.PAGE_DATA.v6</button>
                    <button onclick="moduleToggle('${escapeAttr(mod)}', false)" class="text-white/70 hover:underline">window.PAGE_DATA.v7</button>
                </div>` : ''}
            </div>
            <div class="divide-y divide-white/5">
                ${perms.map(p => `
                    <label class="perm-row flex items-start gap-3 px-4 py-2 cursor-pointer" data-perm="${escapeAttr(p.name)}">
                        <input type="checkbox" ${p.granted ? 'checked':''} ${disabled}
                               data-perm-name="${escapeAttr(p.name)}"
                               onchange="onPermChange('${escapeAttr(mod)}', this)">
                        <div class="min-w-0">
                            <div class="text-sm text-white">${escapeHtml(p.description)}</div>
                            <code class="text-[10px] text-white/70">${escapeHtml(p.name)}</code>
                        </div>
                    </label>
                `).join('')}
            </div>`;
        body.appendChild(sec);
    });

    document.getElementById('filter-perms').oninput = filterMatrix;
}

function filterMatrix() {
    const q = document.getElementById('filter-perms').value.toLowerCase();
    document.querySelectorAll('#matrix-body [data-perm]').forEach(row => {
        row.style.display = row.dataset.perm.toLowerCase().includes(q) ? '' : 'none';
    });
}

function onPermChange(mod, cb) {
    const name = cb.dataset.permName;
    if (cb.checked) state.currentGranted.add(name); else state.currentGranted.delete(name);
    // update section counter
    const sec = cb.closest('[data-mod]');
    const total = sec.querySelectorAll('input[type=checkbox]').length;
    const on    = sec.querySelectorAll('input[type=checkbox]:checked').length;
    sec.querySelector('[data-count]').textContent = `${on}/${total}`;
}

function moduleToggle(mod, on) {
    const sec = document.querySelector(`[data-mod="${CSS.escape(mod)}"]`);
    sec.querySelectorAll('input[type=checkbox]').forEach(cb => {
        cb.checked = on;
        onPermChange(mod, cb);
    });
}

function matrixSelectAll() {
    document.querySelectorAll('#matrix-body input[type=checkbox]').forEach(cb => {
        cb.checked = true; state.currentGranted.add(cb.dataset.permName);
    });
    document.querySelectorAll('#matrix-body [data-mod]').forEach(sec => {
        const t = sec.querySelectorAll('input[type=checkbox]').length;
        sec.querySelector('[data-count]').textContent = `${t}/${t}`;
    });
}
function matrixSelectNone() {
    document.querySelectorAll('#matrix-body input[type=checkbox]').forEach(cb => {
        cb.checked = false;
    });
    state.currentGranted.clear();
    document.querySelectorAll('#matrix-body [data-mod]').forEach(sec => {
        const t = sec.querySelectorAll('input[type=checkbox]').length;
        sec.querySelector('[data-count]').textContent = `0/${t}`;
    });
}

async function saveMatrix() {
    if (!state.selectedRoleId) return;
    try {
        const btn = document.getElementById('btn-save-matrix');
        btn.disabled = true;
        await api('set_role_permissions', {
            role_id: state.selectedRoleId,
            permissions: [...state.currentGranted]
        }, 'POST');
        toast('Permissions enregistrées.');
        await loadRoles();
        selectRole(state.selectedRoleId);
    } catch(e) { toast(e.message, 'error'); }
    finally { const b=document.getElementById('btn-save-matrix'); if(b) b.disabled=false; }
}

function openCreateRoleModal() {
    document.getElementById('modal-role-title').textContent = LANG==='fr' ? 'Nouveau rôle' : 'New role';
    document.getElementById('modal-role-id').value = '';
    document.getElementById('modal-role-name').value = '';
    document.getElementById('modal-role').classList.remove('hidden');
    document.getElementById('modal-role').classList.add('flex');
    setTimeout(() => document.getElementById('modal-role-name').focus(), 50);
}
function openRenameRoleModal(id, name) {
    document.getElementById('modal-role-title').textContent = LANG==='fr' ? 'Renommer le rôle' : 'Rename role';
    document.getElementById('modal-role-id').value = id;
    document.getElementById('modal-role-name').value = name;
    document.getElementById('modal-role').classList.remove('hidden');
    document.getElementById('modal-role').classList.add('flex');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
    document.getElementById(id).classList.remove('flex');
}

async function submitRole() {
    const id   = document.getElementById('modal-role-id').value;
    const name = document.getElementById('modal-role-name').value.trim().toLowerCase();
    if (!/^[a-z][a-z0-9_-]{1,31}$/.test(name)) return toast('Nom invalide.', 'error');
    try {
        if (id) {
            await api('update_role', { id: +id, name }, 'POST');
            toast('Rôle renommé.');
        } else {
            await api('create_role', { name }, 'POST');
            toast('Rôle créé.');
        }
        closeModal('modal-role');
        await loadRoles();
    } catch(e) { toast(e.message, 'error'); }
}

async function deleteRole(id, name) {
    if (!(await LPC.modal.confirm(`Supprimer le rôle « ${name} » ?\nCette action est irréversible.`))) return;
    try {
        await api('delete_role', { id }, 'POST');
        toast('Rôle supprimé.');
        if (state.selectedRoleId === id) {
            state.selectedRoleId = null;
            document.getElementById('matrix-header').classList.add('hidden');
            document.getElementById('matrix-body').classList.add('hidden');
            document.getElementById('matrix-empty').classList.remove('hidden');
        }
        await loadRoles();
    } catch(e) { toast(e.message, 'error'); }
}

async function resetDefaults() {
    if (!(await LPC.modal.confirm('Réinitialiser les 4 rôles système (admin/accountant/operations/driver) à leurs permissions par défaut ?\nLes rôles personnalisés ne seront pas modifiés.'))) return;
    try {
        await api('reset_defaults', {}, 'POST');
        toast('Défauts appliqués.');
        await loadRoles();
        if (state.selectedRoleId) selectRole(state.selectedRoleId);
    } catch(e) { toast(e.message, 'error'); }
}

// tiny escapers
function escapeHtml(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function escapeAttr(s){return escapeHtml(s).replace(/`/g,'&#96;');}
