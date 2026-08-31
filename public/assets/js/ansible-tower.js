(() => {
  'use strict';

  if (document.body?.dataset.page !== 'ansible') return;
  const content = document.getElementById('appContent');
  const title = document.getElementById('pageTitle');
  const api = window.CloudPortal?.api?.request;
  if (!content || typeof api !== 'function') return;
  if (title) title.textContent = 'Ansible';

  const h = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const parseObject = (value, label) => {
    const text = String(value || '').trim();
    if (text === '') return {};
    let decoded;
    try { decoded = JSON.parse(text); } catch { throw new Error(`${label}: nieprawidłowy JSON.`); }
    if (!decoded || Array.isArray(decoded) || typeof decoded !== 'object') throw new Error(`${label}: wymagany jest obiekt JSON.`);
    return decoded;
  };
  const badge = status => `<span class="status-badge status-${h(status || 'unknown')}">${h(status || 'unknown')}</span>`;
  const date = value => value ? new Intl.DateTimeFormat('pl-PL', {dateStyle:'short', timeStyle:'medium'}).format(new Date(String(value).replace(' ', 'T') + 'Z')) : '—';

  const state = {inventories: [], playbooks: [], vms: [], projects: [], jobs: [], selected: null};

  async function loadAll(keepSelection = true) {
    const selectedId = keepSelection ? state.selected?.id : null;
    const [inventories, playbooks, vms, catalog, jobs] = await Promise.all([
      api('/api/v1/ansible/inventories'),
      api('/api/v1/ansible/playbooks'),
      api('/api/v1/vms'),
      api('/api/v1/catalog'),
      api('/api/v1/jobs'),
    ]);
    state.inventories = inventories.data || [];
    state.playbooks = playbooks.data || [];
    state.vms = vms.data || [];
    state.projects = catalog.data?.projects || [];
    state.jobs = (jobs.data || []).filter(job => job.type === 'vm.ansible' || job.type === 'ansible.inventory');
    if (selectedId) {
      const stillExists = state.inventories.some(item => Number(item.id) === Number(selectedId));
      state.selected = stillExists ? (await api(`/api/v1/ansible/inventories/${selectedId}`)).data : null;
    }
    render();
  }

  async function selectInventory(id) {
    state.selected = (await api(`/api/v1/ansible/inventories/${Number(id)}`)).data;
    render();
  }

  function projectOptions(selected = '') {
    return state.projects.map(project => `<option value="${Number(project.id)}" ${Number(selected) === Number(project.id) ? 'selected' : ''}>${h(project.name)}</option>`).join('');
  }

  function playbookOptions() {
    return `<option value="">Wybierz playbook</option>${state.playbooks.map(playbook => `<option value="${h(playbook.id)}">${h(playbook.name)}</option>`).join('')}`;
  }

  function vmOptions(projectId = null, includeBlank = true) {
    const vms = state.vms.filter(vm => !projectId || Number(vm.project_id) === Number(projectId));
    return `${includeBlank ? '<option value="">Wybierz VM</option>' : ''}${vms.map(vm => `<option value="${Number(vm.id)}">${h(vm.name)}${vm.ip_address ? ` · ${h(vm.ip_address)}` : ''}</option>`).join('')}`;
  }

  function inventoryList() {
    if (!state.inventories.length) return '<div class="empty-state">Brak inventory. Utwórz pierwsze inventory dla projektu.</div>';
    return `<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Nazwa</th><th>Projekt</th><th>Hosty</th><th></th></tr></thead><tbody>${state.inventories.map(item => `<tr>
      <td><strong>${h(item.name)}</strong><div class="resource-meta">${h(item.description || '—')}</div></td>
      <td>${h(item.project_name || item.project_id)}</td><td>${Number(item.host_count || 0)}</td>
      <td class="text-end"><button class="btn btn-sm btn-outline-primary" data-open-inventory="${Number(item.id)}">Zarządzaj</button></td>
    </tr>`).join('')}</tbody></table></div>`;
  }

  function inventoryDetail() {
    const inventory = state.selected;
    if (!inventory) return '<div class="empty-state">Wybierz inventory z listy.</div>';
    const hosts = inventory.hosts || [];
    const hostRows = hosts.length ? hosts.map(host => `<tr>
      <td><strong>${h(host.host_alias)}</strong><div class="resource-meta">${h(host.vm_name)}</div></td>
      <td>${h(host.ip_address || 'brak IP')}</td><td>${h(host.ansible_user)}</td><td>${badge(host.vm_status)}</td>
      <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-remove-host="${Number(host.id)}">Usuń</button></td>
    </tr>`).join('') : '<tr><td colspan="5"><div class="empty-state">Brak hostów.</div></td></tr>';
    const limitOptions = `<option value="">Całe inventory</option>${hosts.filter(host => host.enabled).map(host => `<option value="${Number(host.virtual_machine_id)}">${h(host.host_alias)}</option>`).join('')}`;

    return `<div class="d-flex justify-content-between align-items-start gap-3 mb-3">
      <div><p class="eyebrow mb-1">Inventory #${Number(inventory.id)}</p><h2 class="h4 mb-1">${h(inventory.name)}</h2><div class="text-secondary">${h(inventory.project_name || '')}</div></div>
      <button class="btn btn-sm btn-outline-danger" id="deleteInventory">Usuń inventory</button>
    </div>
    <div class="content-grid">
      <section class="panel"><div class="panel-header"><h3 class="h5 mb-0">Hosty</h3></div>
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Host</th><th>IP</th><th>User</th><th>Status VM</th><th></th></tr></thead><tbody>${hostRows}</tbody></table></div>
        <form id="addHostForm" class="panel-body border-top"><div class="row g-2">
          <div class="col-md-5"><label class="form-label">VM</label><select class="form-select" name="virtual_machine_id" required>${vmOptions(inventory.project_id)}</select></div>
          <div class="col-md-3"><label class="form-label">Ansible user</label><input class="form-control" name="ansible_user" value="clouduser" required></div>
          <div class="col-md-4"><label class="form-label">Host alias</label><input class="form-control" name="host_alias" placeholder="opcjonalnie"></div>
          <div class="col-12"><label class="form-label">Host variables (JSON)</label><textarea class="form-control font-monospace" rows="2" name="variables" placeholder='{"role":"web"}'></textarea></div>
          <div class="col-12"><button class="btn btn-primary" type="submit">Dodaj VM do inventory</button></div>
        </div></form>
      </section>
      <section class="panel"><div class="panel-header"><h3 class="h5 mb-0">Launch playbook</h3></div><form id="launchInventoryForm" class="panel-body">
        <div class="mb-3"><label class="form-label">Playbook</label><select class="form-select" name="playbook" required>${playbookOptions()}</select></div>
        <div class="mb-3"><label class="form-label">Limit</label><select class="form-select" name="limit_vm_id">${limitOptions}</select></div>
        <div class="mb-3"><label class="form-label">Extra vars (JSON)</label><textarea class="form-control font-monospace" rows="4" name="extra_vars" placeholder='{"environment":"dev"}'></textarea></div>
        <button class="btn btn-primary" type="submit" ${hosts.length ? '' : 'disabled'}>Uruchom job</button>
      </form></section>
    </div>`;
  }

  function recentJobs() {
    const rows = state.jobs.slice(0, 20).map(job => `<tr><td><code>${h(job.public_id)}</code></td><td>${h(job.type)}</td><td>${badge(job.status)}</td><td>${Number(job.attempts || 0)}/${Number(job.max_attempts || 0)}</td><td>${date(job.created_at)}</td><td class="text-danger small">${h(job.error_message || '')}</td></tr>`).join('');
    return `<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Job</th><th>Typ</th><th>Status</th><th>Próby</th><th>Utworzono</th><th>Błąd</th></tr></thead><tbody>${rows || '<tr><td colspan="6"><div class="empty-state">Brak jobów Ansible.</div></td></tr>'}</tbody></table></div>`;
  }

  function render() {
    content.innerHTML = `<div class="metric-grid mb-3">
      <div class="metric-card"><div class="metric-label">Inventories</div><div class="metric-value">${state.inventories.length}</div><div class="metric-foot">trwałe inventory</div></div>
      <div class="metric-card"><div class="metric-label">Playbooks</div><div class="metric-value">${state.playbooks.length}</div><div class="metric-foot">ansible/playbooks</div></div>
      <div class="metric-card"><div class="metric-label">Jobs</div><div class="metric-value">${state.jobs.length}</div><div class="metric-foot">ostatnie uruchomienia</div></div>
    </div>
    <div class="content-grid">
      <section class="panel"><div class="panel-header"><h2 class="h5 mb-0">Inventories</h2></div>${inventoryList()}</section>
      <section class="panel"><div class="panel-header"><h2 class="h5 mb-0">Nowe inventory</h2></div><form id="createInventoryForm" class="panel-body">
        <div class="mb-3"><label class="form-label">Projekt</label><select class="form-select" name="project_id" required><option value="">Wybierz projekt</option>${projectOptions()}</select></div>
        <div class="mb-3"><label class="form-label">Nazwa</label><input class="form-control" name="name" maxlength="120" required></div>
        <div class="mb-3"><label class="form-label">Opis</label><input class="form-control" name="description" maxlength="500"></div>
        <div class="mb-3"><label class="form-label">Inventory variables (JSON)</label><textarea class="form-control font-monospace" rows="3" name="variables" placeholder='{"environment":"dev"}'></textarea></div>
        <button class="btn btn-primary" type="submit">Utwórz inventory</button>
      </form></section>
    </div>
    <section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">Inventory / hosts / launch</h2></div><div class="panel-body">${inventoryDetail()}</div></section>
    <section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">Uruchom playbook bezpośrednio na VM</h2></div><form id="launchVmForm" class="panel-body"><div class="row g-2">
      <div class="col-md-4"><label class="form-label">VM</label><select class="form-select" name="vm_id" required>${vmOptions()}</select></div>
      <div class="col-md-4"><label class="form-label">Playbook</label><select class="form-select" name="playbook" required>${playbookOptions()}</select></div>
      <div class="col-md-4"><label class="form-label">Ansible user</label><input class="form-control" name="ansible_user" value="clouduser" required></div>
      <div class="col-12"><label class="form-label">Extra vars (JSON)</label><textarea class="form-control font-monospace" rows="2" name="extra_vars"></textarea></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Uruchom na VM</button></div>
    </div></form></section>
    <section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">Ansible jobs</h2><button class="btn btn-sm btn-outline-primary" id="refreshJobs">Odśwież</button></div>${recentJobs()}</section>`;
    bind();
  }

  function bind() {
    document.querySelectorAll('[data-open-inventory]').forEach(button => button.addEventListener('click', () => run(() => selectInventory(button.dataset.openInventory))));
    document.getElementById('createInventoryForm')?.addEventListener('submit', event => run(async () => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      const response = await api('/api/v1/ansible/inventories', {method:'POST', body: JSON.stringify({
        project_id: Number(form.get('project_id')),
        name: form.get('name'), description: form.get('description'), variables: parseObject(form.get('variables'), 'Inventory variables'),
      })});
      state.selected = response.data;
      await loadAll(true);
    }));
    document.getElementById('deleteInventory')?.addEventListener('click', () => run(async () => {
      if (!confirm(`Usunąć inventory ${state.selected?.name || ''}?`)) return;
      await api(`/api/v1/ansible/inventories/${Number(state.selected.id)}`, {method:'DELETE'});
      state.selected = null;
      await loadAll(false);
    }));
    document.getElementById('addHostForm')?.addEventListener('submit', event => run(async () => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      await api(`/api/v1/ansible/inventories/${Number(state.selected.id)}/hosts`, {method:'POST', body: JSON.stringify({
        virtual_machine_id: Number(form.get('virtual_machine_id')), ansible_user: form.get('ansible_user'), host_alias: form.get('host_alias'), variables: parseObject(form.get('variables'), 'Host variables'),
      })});
      await selectInventory(state.selected.id);
    }));
    document.querySelectorAll('[data-remove-host]').forEach(button => button.addEventListener('click', () => run(async () => {
      await api(`/api/v1/ansible/inventories/${Number(state.selected.id)}/hosts/${Number(button.dataset.removeHost)}`, {method:'DELETE'});
      await selectInventory(state.selected.id);
    })));
    document.getElementById('launchInventoryForm')?.addEventListener('submit', event => run(async () => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      const response = await api(`/api/v1/ansible/inventories/${Number(state.selected.id)}/launch`, {method:'POST', body: JSON.stringify({
        playbook: form.get('playbook'), limit_vm_id: form.get('limit_vm_id') ? Number(form.get('limit_vm_id')) : null, extra_vars: parseObject(form.get('extra_vars'), 'Extra vars'),
      })});
      alert(`Job ${response.data.job_id} dodany do kolejki.`);
      await loadAll(true);
    }));
    document.getElementById('launchVmForm')?.addEventListener('submit', event => run(async () => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      const vmId = Number(form.get('vm_id'));
      const response = await api(`/api/v1/vms/${vmId}/ansible`, {method:'POST', body: JSON.stringify({
        playbook: form.get('playbook'), ansible_user: form.get('ansible_user'), extra_vars: parseObject(form.get('extra_vars'), 'Extra vars'),
      })});
      alert(`Job ${response.data.job_id} dodany do kolejki.`);
      await loadAll(true);
    }));
    document.getElementById('refreshJobs')?.addEventListener('click', () => run(() => loadAll(true)));
  }

  async function run(callback) {
    try { await callback(); } catch (error) { alert(error?.message || String(error)); }
  }

  run(() => loadAll(false));
})();
