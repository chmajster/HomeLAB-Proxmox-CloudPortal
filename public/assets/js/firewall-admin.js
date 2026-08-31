(() => {
  'use strict';

  const body = document.body;
  if (!body || body.dataset.page !== 'firewall') return;
  const content = document.getElementById('appContent');
  const title = document.getElementById('pageTitle');
  if (!content) return;

  const basePath = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const h = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  let connections = [];
  let connectionId = null;
  let state = {aliases:[], ipsets:[], groups:[]};

  async function api(path, options = {}) {
    const method = options.method || 'GET';
    const headers = {'Accept':'application/json', ...(method === 'GET' ? {} : {'X-CSRF-Token':csrf}), ...(options.body ? {'Content-Type':'application/json'} : {})};
    const response = await fetch(appUrl(path), {...options, method, headers, ...(options.body && typeof options.body !== 'string' ? {body:JSON.stringify(options.body)} : {})});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  function toast(message, kind = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container || !window.bootstrap?.Toast) return;
    const node = document.createElement('div');
    node.className = `toast align-items-center text-bg-${kind} border-0`;
    node.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    node.querySelector('.toast-body').textContent = message;
    container.append(node);
    const instance = new bootstrap.Toast(node, {delay:5500});
    node.addEventListener('hidden.bs.toast', () => node.remove());
    instance.show();
  }

  const clusterBase = () => `/api/v1/admin/proxmox/${Number(connectionId)}/firewall`;

  async function reloadState() {
    if (!connectionId) return;
    state = await api(clusterBase());
    render();
  }

  function aliasesPanel() {
    const rows = (state.aliases || []).map(alias => `<tr><td><strong>${h(alias.name)}</strong></td><td><code>${h(alias.cidr)}</code></td><td>${h(alias.comment || '—')}</td><td class="text-end"><button class="btn btn-sm btn-outline-secondary" data-alias-edit="${h(alias.name)}" data-cidr="${h(alias.cidr)}" data-comment="${h(alias.comment || '')}">${locale==='pl'?'Edytuj':'Edit'}</button> <button class="btn btn-sm btn-outline-danger" data-alias-delete="${h(alias.name)}">${locale==='pl'?'Usuń':'Delete'}</button></td></tr>`).join('');
    return `<section class="panel"><div class="panel-header"><div><h2 class="h5 mb-1">Aliases</h2><p class="resource-meta mb-0">${locale==='pl'?'Nazwane adresy i podsieci używane w regułach Proxmox Firewall.':'Named addresses and networks used by Proxmox Firewall rules.'}</p></div></div><div class="panel-body"><form id="aliasForm" class="row g-2 align-items-end"><input type="hidden" name="original"><div class="col-md-3"><label class="form-label">Name</label><input class="form-control" name="name" pattern="[A-Za-z][A-Za-z0-9_-]{0,63}" required></div><div class="col-md-3"><label class="form-label">CIDR</label><input class="form-control" name="cidr" required placeholder="10.0.0.10/32"></div><div class="col-md-4"><label class="form-label">${locale==='pl'?'Komentarz':'Comment'}</label><input class="form-control" name="comment" maxlength="255"></div><div class="col-md-2"><button class="btn btn-primary w-100" type="submit">${locale==='pl'?'Zapisz':'Save'}</button></div></form><div class="table-responsive mt-3"><table class="table align-middle"><thead><tr><th>Name</th><th>CIDR</th><th>${locale==='pl'?'Komentarz':'Comment'}</th><th></th></tr></thead><tbody>${rows || `<tr><td colspan="4" class="text-secondary">${locale==='pl'?'Brak aliasów.':'No aliases.'}</td></tr>`}</tbody></table></div></div></section>`;
  }

  function ipsetsPanel() {
    const cards = (state.ipsets || []).map(set => {
      const name = String(set.name || '');
      const entries = (set.entries || []).map(entry => `<tr><td><code>${h(entry.cidr)}</code></td><td>${entry.nomatch ? 'nomatch' : 'match'}</td><td>${h(entry.comment || '—')}</td><td class="text-end"><button class="btn btn-sm btn-outline-danger" data-ipset-entry-delete="${h(name)}" data-cidr="${h(entry.cidr)}">${locale==='pl'?'Usuń':'Delete'}</button></td></tr>`).join('');
      return `<div class="firewall-object-card"><div class="d-flex align-items-center gap-2"><div><strong>${h(name)}</strong><div class="resource-meta">${h(set.comment || '')}</div></div><button class="btn btn-sm btn-outline-danger ms-auto" data-ipset-delete="${h(name)}">${locale==='pl'?'Usuń IPSet':'Delete IPSet'}</button></div><form class="row g-2 align-items-end mt-2" data-ipset-entry-form="${h(name)}"><div class="col-md-5"><label class="form-label">CIDR</label><input class="form-control" name="cidr" required placeholder="10.0.0.0/24"></div><div class="col-md-4"><label class="form-label">${locale==='pl'?'Komentarz':'Comment'}</label><input class="form-control" name="comment" maxlength="255"></div><div class="col-md-1"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="nomatch"><label class="form-check-label">!</label></div></div><div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">${locale==='pl'?'Dodaj':'Add'}</button></div></form><div class="table-responsive mt-2"><table class="table table-sm align-middle"><thead><tr><th>CIDR</th><th>Mode</th><th>${locale==='pl'?'Komentarz':'Comment'}</th><th></th></tr></thead><tbody>${entries || `<tr><td colspan="4" class="text-secondary">${locale==='pl'?'Brak wpisów.':'No entries.'}</td></tr>`}</tbody></table></div></div>`;
    }).join('');
    return `<section class="panel"><div class="panel-header"><div><h2 class="h5 mb-1">IPSet</h2><p class="resource-meta mb-0">${locale==='pl'?'Listy adresów i podsieci wielokrotnego użytku.':'Reusable lists of addresses and networks.'}</p></div></div><div class="panel-body"><form id="ipsetForm" class="row g-2 align-items-end"><div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="name" pattern="[A-Za-z][A-Za-z0-9_-]{0,63}" required></div><div class="col-md-5"><label class="form-label">${locale==='pl'?'Komentarz':'Comment'}</label><input class="form-control" name="comment" maxlength="255"></div><div class="col-md-3"><button class="btn btn-primary w-100" type="submit">${locale==='pl'?'Utwórz IPSet':'Create IPSet'}</button></div></form><div class="firewall-object-grid mt-3">${cards || `<div class="text-secondary">${locale==='pl'?'Brak IPSetów.':'No IPSets.'}</div>`}</div></div></section>`;
  }

  function groupsPanel() {
    const cards = (state.groups || []).map(group => {
      const name = String(group.group || '');
      const rules = (group.rules || []).map(rule => `<tr><td>${h(rule.pos ?? '')}</td><td>${h(String(rule.type || '').toUpperCase())}</td><td>${h(rule.action || '—')}</td><td>${h(rule.source || '—')}</td><td>${h(rule.dest || '—')}</td><td>${h(rule.proto || rule.macro || '—')}</td><td>${h(rule.dport || '—')}</td><td class="text-end"><button class="btn btn-sm btn-outline-danger" data-group-rule-delete="${h(name)}" data-position="${h(rule.pos ?? 0)}">${locale==='pl'?'Usuń':'Delete'}</button></td></tr>`).join('');
      return `<div class="firewall-object-card"><div class="d-flex align-items-center gap-2"><div><strong>${h(name)}</strong><div class="resource-meta">${h(group.comment || '')}</div></div><button class="btn btn-sm btn-outline-danger ms-auto" data-group-delete="${h(name)}">${locale==='pl'?'Usuń grupę':'Delete group'}</button></div><form class="row g-2 align-items-end mt-2" data-group-rule-form="${h(name)}"><div class="col-md-2"><label class="form-label">Type</label><select class="form-select" name="type"><option value="in">IN</option><option value="out">OUT</option></select></div><div class="col-md-2"><label class="form-label">Action</label><select class="form-select" name="action"><option>ACCEPT</option><option>DROP</option><option>REJECT</option></select></div><div class="col-md-2"><label class="form-label">Source</label><input class="form-control" name="source" placeholder="+alias"></div><div class="col-md-2"><label class="form-label">Destination</label><input class="form-control" name="dest"></div><div class="col-md-1"><label class="form-label">Proto</label><select class="form-select" name="proto"><option value=""></option><option>tcp</option><option>udp</option><option>icmp</option><option>icmpv6</option></select></div><div class="col-md-1"><label class="form-label">DPort</label><input class="form-control" name="dport"></div><div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">${locale==='pl'?'Dodaj regułę':'Add rule'}</button></div></form><div class="table-responsive mt-2"><table class="table table-sm align-middle"><thead><tr><th>#</th><th>Type</th><th>Action</th><th>Source</th><th>Destination</th><th>Proto</th><th>DPort</th><th></th></tr></thead><tbody>${rules || `<tr><td colspan="8" class="text-secondary">${locale==='pl'?'Brak reguł.':'No rules.'}</td></tr>`}</tbody></table></div></div>`;
    }).join('');
    return `<section class="panel"><div class="panel-header"><div><h2 class="h5 mb-1">Security Groups</h2><p class="resource-meta mb-0">${locale==='pl'?'Współdzielone zestawy reguł, które można przypinać do reguł VM.':'Shared rule sets that can be referenced by VM firewall rules.'}</p></div></div><div class="panel-body"><form id="groupForm" class="row g-2 align-items-end"><div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="group" pattern="[A-Za-z][A-Za-z0-9_-]{0,63}" required></div><div class="col-md-5"><label class="form-label">${locale==='pl'?'Komentarz':'Comment'}</label><input class="form-control" name="comment" maxlength="255"></div><div class="col-md-3"><button class="btn btn-primary w-100" type="submit">${locale==='pl'?'Utwórz grupę':'Create group'}</button></div></form><div class="firewall-object-grid mt-3">${cards || `<div class="text-secondary">${locale==='pl'?'Brak security groups.':'No security groups.'}</div>`}</div></div></section>`;
  }

  function render() {
    title.textContent = 'Proxmox Firewall';
    const connectionOptions = connections.map(item => `<option value="${Number(item.id)}" ${Number(item.id) === Number(connectionId) ? 'selected' : ''}>${h(item.name)} · ${h(item.hostname)}:${h(item.port)}</option>`).join('');
    content.innerHTML = `<section class="panel mb-3"><div class="panel-header"><div><h2 class="h5 mb-1">${locale==='pl'?'Połączenie Proxmox':'Proxmox connection'}</h2><p class="resource-meta mb-0">${locale==='pl'?'Aliasy, IPSety i security groups są obiektami klastra wybranego połączenia.':'Aliases, IPSets and security groups belong to the selected Proxmox cluster.'}</p></div></div><div class="panel-body"><select class="form-select" id="firewallConnection">${connectionOptions}</select></div></section><div class="firewall-admin-stack">${aliasesPanel()}${ipsetsPanel()}${groupsPanel()}</div>`;
    bind();
  }

  async function mutate(path, options, success) {
    try {
      state = await api(path, options);
      toast(success);
      render();
    } catch (error) { toast(error.message || String(error), 'danger'); }
  }

  function bind() {
    document.getElementById('firewallConnection')?.addEventListener('change', async event => {
      connectionId = Number(event.currentTarget.value);
      content.innerHTML = '<div class="loading-panel"><span class="spinner-border text-primary"></span><span>Loading…</span></div>';
      try { await reloadState(); } catch (error) { showError(error); }
    });

    document.getElementById('aliasForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const data = new FormData(form);
      const original = String(data.get('original') || '');
      const name = String(data.get('name') || '').trim();
      const bodyData = {name, cidr:String(data.get('cidr') || '').trim(), comment:String(data.get('comment') || '').trim()};
      await mutate(original ? `${clusterBase()}/aliases/${encodeURIComponent(original)}` : `${clusterBase()}/aliases`, {method:original?'PUT':'POST', body:bodyData}, locale==='pl'?'Alias zapisany.':'Alias saved.');
    });
    document.querySelectorAll('[data-alias-edit]').forEach(button => button.addEventListener('click', () => {
      const form = document.getElementById('aliasForm');
      form.elements.original.value = button.dataset.aliasEdit;
      form.elements.name.value = button.dataset.aliasEdit;
      form.elements.name.disabled = true;
      form.elements.cidr.value = button.dataset.cidr || '';
      form.elements.comment.value = button.dataset.comment || '';
      form.scrollIntoView({behavior:'smooth', block:'center'});
    }));
    document.querySelectorAll('[data-alias-delete]').forEach(button => button.addEventListener('click', () => {
      if (!confirm(`${locale==='pl'?'Usunąć alias':'Delete alias'} ${button.dataset.aliasDelete}?`)) return;
      mutate(`${clusterBase()}/aliases/${encodeURIComponent(button.dataset.aliasDelete)}`, {method:'DELETE'}, locale==='pl'?'Alias usunięty.':'Alias deleted.');
    }));

    document.getElementById('ipsetForm')?.addEventListener('submit', event => {
      event.preventDefault(); const data = new FormData(event.currentTarget);
      mutate(`${clusterBase()}/ipsets`, {method:'POST', body:{name:String(data.get('name') || '').trim(), comment:String(data.get('comment') || '').trim()}}, locale==='pl'?'IPSet utworzony.':'IPSet created.');
    });
    document.querySelectorAll('[data-ipset-delete]').forEach(button => button.addEventListener('click', () => {
      if (!confirm(`${locale==='pl'?'Usunąć IPSet':'Delete IPSet'} ${button.dataset.ipsetDelete}?`)) return;
      mutate(`${clusterBase()}/ipsets/${encodeURIComponent(button.dataset.ipsetDelete)}`, {method:'DELETE'}, locale==='pl'?'IPSet usunięty.':'IPSet deleted.');
    }));
    document.querySelectorAll('[data-ipset-entry-form]').forEach(form => form.addEventListener('submit', event => {
      event.preventDefault(); const data = new FormData(form); const name = form.dataset.ipsetEntryForm;
      mutate(`${clusterBase()}/ipsets/${encodeURIComponent(name)}/entries`, {method:'POST', body:{cidr:String(data.get('cidr') || '').trim(), comment:String(data.get('comment') || '').trim(), nomatch:form.elements.nomatch.checked}}, locale==='pl'?'Wpis IPSet dodany.':'IPSet entry added.');
    }));
    document.querySelectorAll('[data-ipset-entry-delete]').forEach(button => button.addEventListener('click', () => {
      if (!confirm(`${locale==='pl'?'Usunąć wpis':'Delete entry'} ${button.dataset.cidr}?`)) return;
      mutate(`${clusterBase()}/ipsets/${encodeURIComponent(button.dataset.ipsetEntryDelete)}/entries`, {method:'DELETE', body:{cidr:button.dataset.cidr}}, locale==='pl'?'Wpis usunięty.':'Entry deleted.');
    }));

    document.getElementById('groupForm')?.addEventListener('submit', event => {
      event.preventDefault(); const data = new FormData(event.currentTarget);
      mutate(`${clusterBase()}/groups`, {method:'POST', body:{group:String(data.get('group') || '').trim(), comment:String(data.get('comment') || '').trim()}}, locale==='pl'?'Security group utworzona.':'Security group created.');
    });
    document.querySelectorAll('[data-group-delete]').forEach(button => button.addEventListener('click', () => {
      if (!confirm(`${locale==='pl'?'Usunąć grupę':'Delete group'} ${button.dataset.groupDelete}?`)) return;
      mutate(`${clusterBase()}/groups/${encodeURIComponent(button.dataset.groupDelete)}`, {method:'DELETE'}, locale==='pl'?'Security group usunięta.':'Security group deleted.');
    }));
    document.querySelectorAll('[data-group-rule-form]').forEach(form => form.addEventListener('submit', event => {
      event.preventDefault(); const data = new FormData(form); const name = form.dataset.groupRuleForm;
      mutate(`${clusterBase()}/groups/${encodeURIComponent(name)}/rules`, {method:'POST', body:{type:data.get('type'), action:data.get('action'), source:String(data.get('source') || '').trim(), dest:String(data.get('dest') || '').trim(), proto:String(data.get('proto') || '').trim(), dport:String(data.get('dport') || '').trim(), enable:true, log:'nolog'}}, locale==='pl'?'Reguła grupy dodana.':'Group rule added.');
    }));
    document.querySelectorAll('[data-group-rule-delete]').forEach(button => button.addEventListener('click', () => {
      if (!confirm(locale==='pl'?'Usunąć regułę security group?':'Delete security group rule?')) return;
      mutate(`${clusterBase()}/groups/${encodeURIComponent(button.dataset.groupRuleDelete)}/rules/${Number(button.dataset.position)}`, {method:'DELETE'}, locale==='pl'?'Reguła usunięta.':'Rule deleted.');
    }));
  }

  function showError(error) {
    title.textContent = 'Proxmox Firewall';
    content.innerHTML = '<div class="alert alert-danger"></div>';
    content.querySelector('.alert').textContent = error.message || String(error);
  }

  (async () => {
    try {
      connections = await api('/api/v1/admin/firewall/connections');
      if (!Array.isArray(connections) || connections.length === 0) {
        title.textContent = 'Proxmox Firewall';
        content.innerHTML = `<div class="alert alert-warning">${locale==='pl'?'Brak aktywnych połączeń Proxmox.':'No active Proxmox connections.'}</div>`;
        return;
      }
      connectionId = Number(connections[0].id);
      state = await api(clusterBase());
      render();
    } catch (error) { showError(error); }
  })();
})();
