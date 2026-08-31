(() => {
  'use strict';

  const body = document.body;
  if (!body || body.dataset.page !== 'firewall') return;
  const content = document.getElementById('appContent');
  if (!content) return;

  const basePath = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const h = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  let node = '';
  let ctid = null;
  let state = null;
  let editing = null;

  const connectionId = () => Number(document.getElementById('firewallConnection')?.value || 0);
  const endpoint = suffix => `${basePath}/api/v1/admin/proxmox-lxc/${connectionId()}/${encodeURIComponent(node)}/${ctid}/firewall${suffix}`;

  async function api(url, options = {}) {
    const method = options.method || 'GET';
    const response = await fetch(url, {
      ...options,
      method,
      headers: {
        Accept: 'application/json',
        ...(method === 'GET' ? {} : {'X-CSRF-Token': csrf}),
        ...(options.body ? {'Content-Type': 'application/json'} : {}),
      },
      ...(options.body && typeof options.body !== 'string' ? {body: JSON.stringify(options.body)} : {}),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  function notify(message, kind = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container || !window.bootstrap?.Toast) return;
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-bg-${kind} border-0`;
    toast.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    toast.querySelector('.toast-body').textContent = message;
    container.append(toast);
    const instance = new bootstrap.Toast(toast, {delay: 5000});
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
    instance.show();
  }

  function shell() {
    if (document.getElementById('lxcFirewallPanel')) return;
    const stack = content.querySelector('.firewall-admin-stack');
    if (!stack) return;
    const section = document.createElement('section');
    section.className = 'panel';
    section.id = 'lxcFirewallPanel';
    section.innerHTML = `<div class="panel-header"><div><h2 class="h5 mb-1">LXC Firewall</h2><p class="resource-meta mb-0">${locale === 'pl' ? 'Zarządzanie firewallem istniejącego kontenera LXC przez node i CTID.' : 'Manage an existing LXC container firewall by node and CTID.'}</p></div></div><div class="panel-body"><form id="lxcTargetForm" class="row g-2 align-items-end"><div class="col-md-5"><label class="form-label">Node</label><input class="form-control" name="node" required pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,99}" placeholder="pve1"></div><div class="col-md-4"><label class="form-label">CTID</label><input class="form-control" name="ctid" required type="number" min="100" max="999999999" placeholder="101"></div><div class="col-md-3"><button class="btn btn-primary w-100" type="submit">${locale === 'pl' ? 'Załaduj firewall' : 'Load firewall'}</button></div></form><div id="lxcFirewallContent" class="mt-3"></div></div>`;
    stack.append(section);
    section.querySelector('#lxcTargetForm').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      node = String(form.elements.node.value || '').trim();
      ctid = Number(form.elements.ctid.value);
      editing = null;
      await load();
    });
  }

  async function load() {
    const target = document.getElementById('lxcFirewallContent');
    if (!target) return;
    target.innerHTML = '<div class="loading-panel"><span class="spinner-border spinner-border-sm"></span><span>Loading…</span></div>';
    try {
      state = await api(endpoint(''));
      render();
    } catch (error) {
      target.innerHTML = '<div class="alert alert-danger mb-0"></div>';
      target.querySelector('.alert').textContent = error.message || String(error);
    }
  }

  function option(value, current) {
    return `<option value="${h(value)}" ${String(value).toUpperCase() === String(current ?? '').toUpperCase() ? 'selected' : ''}>${h(value)}</option>`;
  }

  function render() {
    const target = document.getElementById('lxcFirewallContent');
    if (!target || !state) return;
    const options = state.options || {};
    const rules = Array.isArray(state.rules) ? [...state.rules].sort((a, b) => Number(a.pos || 0) - Number(b.pos || 0)) : [];
    const active = editing === null ? null : rules.find(rule => Number(rule.pos) === editing) || {};
    target.innerHTML = `<div class="d-flex align-items-center justify-content-between mb-3"><div><strong>${h(node)} / CT ${h(ctid)}</strong></div><span class="badge ${Number(options.enable) === 1 ? 'text-bg-success' : 'text-bg-secondary'}">${Number(options.enable) === 1 ? 'ENABLED' : 'DISABLED'}</span></div><form id="lxcOptionsForm" class="row g-2 align-items-end"><div class="col-md-2"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="enable" ${Number(options.enable) === 1 ? 'checked' : ''}><label class="form-check-label">Firewall</label></div></div><div class="col-md-3"><label class="form-label">Policy IN</label><select class="form-select" name="policy_in">${['ACCEPT','DROP','REJECT'].map(v => option(v, options.policy_in || 'DROP')).join('')}</select></div><div class="col-md-3"><label class="form-label">Policy OUT</label><select class="form-select" name="policy_out">${['ACCEPT','DROP','REJECT'].map(v => option(v, options.policy_out || 'ACCEPT')).join('')}</select></div><div class="col-md-4"><button class="btn btn-outline-primary w-100" type="submit">${locale === 'pl' ? 'Zapisz opcje' : 'Save options'}</button></div></form><div class="table-responsive mt-3"><table class="table table-sm align-middle"><thead><tr><th>#</th><th>Type</th><th>Action</th><th>Source</th><th>Destination</th><th>Proto</th><th>DPort</th><th></th></tr></thead><tbody>${rules.map(rule => `<tr><td>${h(rule.pos)}</td><td>${h(String(rule.type || '').toUpperCase())}</td><td>${h(rule.action || '—')}</td><td>${h(rule.source || '—')}</td><td>${h(rule.dest || '—')}</td><td>${h(rule.proto || rule.macro || '—')}</td><td>${h(rule.dport || '—')}</td><td class="text-end"><button class="btn btn-sm btn-outline-secondary" data-lxc-edit="${Number(rule.pos)}">${locale === 'pl' ? 'Edytuj' : 'Edit'}</button> <button class="btn btn-sm btn-outline-danger" data-lxc-delete="${Number(rule.pos)}">${locale === 'pl' ? 'Usuń' : 'Delete'}</button></td></tr>`).join('') || `<tr><td colspan="8" class="text-secondary">${locale === 'pl' ? 'Brak reguł.' : 'No rules.'}</td></tr>`}</tbody></table></div><form id="lxcRuleForm" class="row g-2 align-items-end mt-2"><div class="col-md-2"><label class="form-label">Type</label><select class="form-select" name="type">${['in','out'].map(v => option(v, active?.type || 'in')).join('')}</select></div><div class="col-md-2"><label class="form-label">Action</label><select class="form-select" name="action">${['ACCEPT','DROP','REJECT'].map(v => option(v, active?.action || 'ACCEPT')).join('')}</select></div><div class="col-md-2"><label class="form-label">Source</label><input class="form-control" name="source" value="${h(active?.source || '')}"></div><div class="col-md-2"><label class="form-label">Destination</label><input class="form-control" name="dest" value="${h(active?.dest || '')}"></div><div class="col-md-1"><label class="form-label">Proto</label><select class="form-select" name="proto"><option value=""></option>${['tcp','udp','icmp','icmpv6'].map(v => option(v, active?.proto || '')).join('')}</select></div><div class="col-md-1"><label class="form-label">DPort</label><input class="form-control" name="dport" value="${h(active?.dport || '')}"></div><div class="col-md-2"><button class="btn btn-primary w-100" type="submit">${editing === null ? (locale === 'pl' ? 'Dodaj regułę' : 'Add rule') : (locale === 'pl' ? 'Zapisz regułę' : 'Save rule')}</button></div></form>`;

    target.querySelector('#lxcOptionsForm').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      try {
        state = await api(endpoint('/options'), {method: 'PUT', body: {enable: form.elements.enable.checked, policy_in: form.elements.policy_in.value, policy_out: form.elements.policy_out.value}});
        notify(locale === 'pl' ? 'Opcje LXC firewall zapisane.' : 'LXC firewall options saved.');
        render();
      } catch (error) { notify(error.message || String(error), 'danger'); }
    });
    target.querySelectorAll('[data-lxc-edit]').forEach(button => button.addEventListener('click', () => { editing = Number(button.dataset.lxcEdit); render(); }));
    target.querySelectorAll('[data-lxc-delete]').forEach(button => button.addEventListener('click', async () => {
      const pos = Number(button.dataset.lxcDelete);
      if (!confirm(locale === 'pl' ? `Usunąć regułę #${pos}?` : `Delete rule #${pos}?`)) return;
      try { state = await api(endpoint(`/rules/${pos}`), {method: 'DELETE'}); editing = null; render(); }
      catch (error) { notify(error.message || String(error), 'danger'); }
    }));
    target.querySelector('#lxcRuleForm').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const payload = {type: form.elements.type.value, action: form.elements.action.value, source: form.elements.source.value.trim(), dest: form.elements.dest.value.trim(), proto: form.elements.proto.value, dport: form.elements.dport.value.trim(), enable: true, log: 'nolog'};
      const path = editing === null ? '/rules' : `/rules/${editing}`;
      const method = editing === null ? 'POST' : 'PUT';
      try { state = await api(endpoint(path), {method, body: payload}); editing = null; notify(locale === 'pl' ? 'Reguła LXC zapisana.' : 'LXC rule saved.'); render(); }
      catch (error) { notify(error.message || String(error), 'danger'); }
    });
  }

  const observer = new MutationObserver(() => {
    shell();
    if (!document.getElementById('firewallConnection')) return;
    document.getElementById('firewallConnection').addEventListener('change', () => { node = ''; ctid = null; state = null; editing = null; const target = document.getElementById('lxcFirewallContent'); if (target) target.innerHTML = ''; }, {once: true});
  });
  observer.observe(content, {childList: true, subtree: true});
  shell();
})();
