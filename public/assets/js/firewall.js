(() => {
  'use strict';

  const body = document.body;
  if (!body || body.dataset.page !== 'vm-details') return;
  const content = document.getElementById('appContent');
  if (!content) return;

  const basePath = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const relativePath = location.pathname.startsWith(basePath) ? location.pathname.slice(basePath.length) : location.pathname;
  const portal = relativePath.match(/^\/vms\/(\d+)$/);
  const live = relativePath.match(/^\/infrastructure\/vms\/(\d+)\/([^/]+)\/(\d+)$/);
  const firewallBase = portal
    ? `/api/v1/vms/${Number(portal[1])}/firewall`
    : live
      ? `/api/v1/admin/proxmox-vms/${Number(live[1])}/${encodeURIComponent(decodeURIComponent(live[2]))}/${Number(live[3])}/firewall`
      : null;
  if (!firewallBase) return;

  const h = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const bool = value => value === true || value === 1 || value === '1' || value === 'yes' || value === 'true';
  const logLevels = ['nolog','emerg','alert','crit','err','warning','notice','info','debug'];
  let state = null;
  let editingPosition = null;
  let loading = false;

  async function api(path, options = {}) {
    const method = options.method || 'GET';
    const headers = {'Accept':'application/json', ...(method === 'GET' ? {} : {'X-CSRF-Token':csrf}), ...(options.body ? {'Content-Type':'application/json'} : {}), ...(options.headers || {})};
    const response = await fetch(appUrl(path), {...options, method, headers, ...(options.body && typeof options.body !== 'string' ? {body:JSON.stringify(options.body)} : {})});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  function toast(message, kind = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container || !window.bootstrap?.Toast) return;
    const element = document.createElement('div');
    element.className = `toast align-items-center text-bg-${kind} border-0`;
    element.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    element.querySelector('.toast-body').textContent = message;
    container.append(element);
    const instance = new bootstrap.Toast(element, {delay:5500});
    element.addEventListener('hidden.bs.toast', () => element.remove());
    instance.show();
  }

  function option(value, selected) {
    return `<option value="${h(value)}" ${String(selected ?? '').toLowerCase() === String(value).toLowerCase() ? 'selected' : ''}>${h(String(value).toUpperCase())}</option>`;
  }

  function row(rule) {
    const position = Number(rule.pos ?? 0);
    const direction = String(rule.type || '').toUpperCase();
    const action = rule.type === 'group' ? `GROUP: ${rule.action || '—'}` : (rule.action || '—');
    const endpoint = [rule.source ? `src ${rule.source}` : '', rule.dest ? `dst ${rule.dest}` : ''].filter(Boolean).join(' · ') || '—';
    const protocol = [rule.macro || rule.proto || '', rule.sport ? `s:${rule.sport}` : '', rule.dport ? `d:${rule.dport}` : ''].filter(Boolean).join(' · ') || '—';
    return `<tr><td>${h(position)}</td><td><span class="badge text-bg-secondary">${h(direction)}</span></td><td><strong>${h(action)}</strong></td><td>${h(endpoint)}</td><td>${h(protocol)}</td><td>${bool(rule.enable) ? '<span class="badge text-bg-success">ON</span>' : '<span class="badge text-bg-secondary">OFF</span>'}</td><td class="text-break">${h(rule.comment || '—')}</td><td class="text-nowrap"><button class="btn btn-sm btn-outline-secondary" data-fw-edit="${position}">${locale === 'pl' ? 'Edytuj' : 'Edit'}</button> <button class="btn btn-sm btn-outline-danger" data-fw-delete="${position}">${locale === 'pl' ? 'Usuń' : 'Delete'}</button></td></tr>`;
  }

  function ruleForm(rule = {}) {
    const type = String(rule.type || 'in').toLowerCase();
    const action = String(rule.action || 'ACCEPT');
    return `<form id="vmFirewallRuleForm" class="firewall-rule-form mt-3"><input type="hidden" name="position" value="${editingPosition ?? ''}"><div class="row g-3"><div class="col-md-2"><label class="form-label">${locale === 'pl' ? 'Kierunek' : 'Direction'}</label><select class="form-select" name="type" data-fw-type>${option('in', type)}${option('out', type)}${option('group', type)}</select></div><div class="col-md-2" data-fw-action-select><label class="form-label">Action</label><select class="form-select" name="action_select">${option('ACCEPT', action)}${option('DROP', action)}${option('REJECT', action)}</select></div><div class="col-md-3 d-none" data-fw-group-action><label class="form-label">Security group</label><input class="form-control" name="group_action" maxlength="64" value="${type === 'group' ? h(action) : ''}" placeholder="web-servers"></div><div class="col-md-2"><label class="form-label">Source</label><input class="form-control" name="source" maxlength="255" value="${h(rule.source || '')}" placeholder="10.0.0.0/24"></div><div class="col-md-2"><label class="form-label">Destination</label><input class="form-control" name="dest" maxlength="255" value="${h(rule.dest || '')}" placeholder="+alias / +ipset"></div><div class="col-md-2"><label class="form-label">Protocol</label><select class="form-select" name="proto"><option value="">ANY</option>${['tcp','udp','icmp','icmpv6','esp','gre'].map(value => option(value, rule.proto)).join('')}</select></div><div class="col-md-2"><label class="form-label">Source port</label><input class="form-control" name="sport" maxlength="128" value="${h(rule.sport || '')}" placeholder="1024:65535"></div><div class="col-md-2"><label class="form-label">Destination port</label><input class="form-control" name="dport" maxlength="128" value="${h(rule.dport || '')}" placeholder="22,80,443"></div><div class="col-md-2"><label class="form-label">Macro</label><input class="form-control" name="macro" maxlength="64" value="${h(rule.macro || '')}" placeholder="SSH"></div><div class="col-md-2"><label class="form-label">Interface</label><input class="form-control" name="iface" maxlength="255" value="${h(rule.iface || '')}" placeholder="net0"></div><div class="col-md-2"><label class="form-label">Log</label><select class="form-select" name="log">${logLevels.map(value => option(value, rule.log || 'nolog')).join('')}</select></div><div class="col-md-4"><label class="form-label">${locale === 'pl' ? 'Komentarz' : 'Comment'}</label><input class="form-control" name="comment" maxlength="255" value="${h(rule.comment || '')}"></div><div class="col-md-2 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="enable" id="fwRuleEnable" ${rule.enable === undefined || bool(rule.enable) ? 'checked' : ''}><label class="form-check-label" for="fwRuleEnable">Enabled</label></div></div></div><div class="d-flex gap-2 mt-3"><button class="btn btn-primary" type="submit">${editingPosition === null ? (locale === 'pl' ? 'Dodaj regułę' : 'Add rule') : (locale === 'pl' ? 'Zapisz regułę' : 'Save rule')}</button><button class="btn btn-outline-secondary" type="button" data-fw-rule-cancel>${locale === 'pl' ? 'Anuluj' : 'Cancel'}</button></div></form>`;
  }

  function render() {
    if (!state) return;
    let panel = document.getElementById('vmFirewallPanel');
    if (!panel) {
      panel = document.createElement('section');
      panel.id = 'vmFirewallPanel';
      panel.className = 'panel mt-3';
      const anchor = document.getElementById('vmManagementPanel') || content.querySelector('.panel');
      if (!anchor) return;
      anchor.after(panel);
    }
    const options = state.options || {};
    const rules = Array.isArray(state.rules) ? [...state.rules].sort((a,b) => Number(a.pos || 0) - Number(b.pos || 0)) : [];
    const editingRule = editingPosition === null ? null : rules.find(rule => Number(rule.pos) === editingPosition) || {};
    panel.innerHTML = `<div class="panel-header"><div><h2 class="h5 mb-1">Proxmox Firewall</h2><p class="resource-meta mb-0">${locale === 'pl' ? 'Reguły są zapisywane bezpośrednio w firewallu tej VM w Proxmox.' : 'Rules are written directly to this VM firewall in Proxmox.'}</p></div><span class="badge ${bool(options.enable) ? 'text-bg-success' : 'text-bg-secondary'}">${bool(options.enable) ? 'ENABLED' : 'DISABLED'}</span></div><div class="panel-body"><form id="vmFirewallOptionsForm"><div class="row g-3 align-items-end"><div class="col-md-2"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="fwEnabled" name="enable" ${bool(options.enable) ? 'checked' : ''}><label class="form-check-label" for="fwEnabled">Firewall</label></div></div><div class="col-md-2"><label class="form-label">Policy IN</label><select class="form-select" name="policy_in">${['ACCEPT','DROP','REJECT'].map(value => option(value, options.policy_in || 'DROP')).join('')}</select></div><div class="col-md-2"><label class="form-label">Policy OUT</label><select class="form-select" name="policy_out">${['ACCEPT','DROP','REJECT'].map(value => option(value, options.policy_out || 'ACCEPT')).join('')}</select></div><div class="col-md-2"><label class="form-label">Log IN</label><select class="form-select" name="log_level_in">${logLevels.map(value => option(value, options.log_level_in || 'nolog')).join('')}</select></div><div class="col-md-2"><label class="form-label">Log OUT</label><select class="form-select" name="log_level_out">${logLevels.map(value => option(value, options.log_level_out || 'nolog')).join('')}</select></div><div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">${locale === 'pl' ? 'Zapisz opcje' : 'Save options'}</button></div></div></form><div class="d-flex align-items-center justify-content-between mt-4 mb-2"><h3 class="h6 mb-0">${locale === 'pl' ? 'Reguły VM' : 'VM rules'}</h3><button class="btn btn-sm btn-primary" type="button" data-fw-add>${locale === 'pl' ? 'Dodaj regułę' : 'Add rule'}</button></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>#</th><th>Type</th><th>Action</th><th>Source / Destination</th><th>Protocol / Ports</th><th>Status</th><th>${locale === 'pl' ? 'Komentarz' : 'Comment'}</th><th></th></tr></thead><tbody>${rules.map(row).join('') || `<tr><td colspan="8" class="text-secondary">${locale === 'pl' ? 'Brak reguł firewalla.' : 'No firewall rules.'}</td></tr>`}</tbody></table></div><div id="vmFirewallRuleEditor">${editingPosition !== null ? ruleForm(editingRule) : ''}</div></div>`;
    bind(panel);
  }

  function bind(panel) {
    panel.querySelector('#vmFirewallOptionsForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const data = new FormData(form);
      const button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      try {
        state = await api(`${firewallBase}/options`, {method:'PUT', body:{enable:form.elements.enable.checked, policy_in:data.get('policy_in'), policy_out:data.get('policy_out'), log_level_in:data.get('log_level_in'), log_level_out:data.get('log_level_out')}});
        toast(locale === 'pl' ? 'Zapisano opcje firewalla.' : 'Firewall options saved.');
        render();
      } catch (error) {
        toast(error.message || String(error), 'danger');
        button.disabled = false;
      }
    });

    panel.querySelector('[data-fw-add]')?.addEventListener('click', () => {
      editingPosition = -1;
      render();
      document.getElementById('vmFirewallRuleForm')?.scrollIntoView({behavior:'smooth', block:'center'});
    });

    panel.querySelectorAll('[data-fw-edit]').forEach(button => button.addEventListener('click', () => {
      editingPosition = Number(button.dataset.fwEdit);
      render();
      document.getElementById('vmFirewallRuleForm')?.scrollIntoView({behavior:'smooth', block:'center'});
    }));

    panel.querySelectorAll('[data-fw-delete]').forEach(button => button.addEventListener('click', async () => {
      const position = Number(button.dataset.fwDelete);
      if (!window.confirm(locale === 'pl' ? `Usunąć regułę #${position}?` : `Delete rule #${position}?`)) return;
      button.disabled = true;
      try {
        state = await api(`${firewallBase}/rules/${position}`, {method:'DELETE'});
        editingPosition = null;
        toast(locale === 'pl' ? 'Reguła została usunięta.' : 'Rule deleted.');
        render();
      } catch (error) { toast(error.message || String(error), 'danger'); button.disabled = false; }
    }));

    const form = panel.querySelector('#vmFirewallRuleForm');
    if (!form) return;
    const syncType = () => {
      const group = form.elements.type.value === 'group';
      form.querySelector('[data-fw-action-select]').classList.toggle('d-none', group);
      form.querySelector('[data-fw-group-action]').classList.toggle('d-none', !group);
    };
    form.elements.type.addEventListener('change', syncType);
    syncType();
    form.querySelector('[data-fw-rule-cancel]').addEventListener('click', () => { editingPosition = null; render(); });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const data = new FormData(form);
      const type = String(data.get('type') || 'in');
      const payload = {
        type,
        action:type === 'group' ? String(data.get('group_action') || '').trim() : String(data.get('action_select') || 'ACCEPT'),
        source:String(data.get('source') || '').trim(),
        dest:String(data.get('dest') || '').trim(),
        proto:String(data.get('proto') || '').trim(),
        sport:String(data.get('sport') || '').trim(),
        dport:String(data.get('dport') || '').trim(),
        macro:String(data.get('macro') || '').trim(),
        iface:String(data.get('iface') || '').trim(),
        log:String(data.get('log') || 'nolog'),
        comment:String(data.get('comment') || '').trim(),
        enable:form.elements.enable.checked,
      };
      Object.keys(payload).forEach(key => { if (payload[key] === '' && !['source','dest','proto','sport','dport','macro','iface','comment'].includes(key)) return; });
      const editing = editingPosition !== null && editingPosition >= 0;
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        state = await api(editing ? `${firewallBase}/rules/${editingPosition}` : `${firewallBase}/rules`, {method:editing ? 'PUT' : 'POST', body:payload});
        editingPosition = null;
        toast(locale === 'pl' ? 'Reguła firewalla została zapisana.' : 'Firewall rule saved.');
        render();
      } catch (error) { toast(error.message || String(error), 'danger'); submit.disabled = false; }
    });
  }

  async function load() {
    if (loading || state) return;
    const anchor = document.getElementById('vmManagementPanel') || content.querySelector('.panel');
    if (!anchor) return;
    loading = true;
    try {
      state = await api(firewallBase);
      render();
    } catch (error) {
      const panel = document.createElement('section');
      panel.id = 'vmFirewallPanel';
      panel.className = 'panel mt-3';
      panel.innerHTML = `<div class="panel-header"><h2 class="h5 mb-0">Proxmox Firewall</h2></div><div class="panel-body"><div class="alert alert-warning mb-0"></div></div>`;
      panel.querySelector('.alert').textContent = `${locale === 'pl' ? 'Firewall tej VM nie jest dostępny: ' : 'VM firewall is unavailable: '}${error.message || error}`;
      anchor.after(panel);
    } finally { loading = false; }
  }

  const observer = new MutationObserver(load);
  observer.observe(content, {childList:true, subtree:true});
  load();
})();
