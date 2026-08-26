(() => {
  'use strict';

  const body = document.body;
  if (body.dataset.page !== 'blueprints') return;
  const content = document.getElementById('appContent');
  if (!content) return;
  const base = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const h = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));

  async function api(path, options = {}) {
    const response = await fetch(base + path, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(options.body ? {'Content-Type': 'application/json'} : {}),
        ...((options.method || 'GET') === 'GET' ? {} : {'X-CSRF-Token': csrf}),
        ...(options.headers || {}),
      },
      body: options.body && typeof options.body !== 'string' ? JSON.stringify(options.body) : options.body,
    });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  const options = (items, value = 'id', label = 'name') => items.map(item => `<option value="${h(item[value])}">${h(item[label])}</option>`).join('');

  async function load() {
    const [blueprints, baseCatalog, playbooks, cloudInit] = await Promise.all([
      api('/api/v1/admin/blueprints'),
      api('/api/v1/catalog'),
      api('/api/v1/ansible/playbooks'),
      api('/api/v1/cloud-init-profiles'),
    ]);

    content.innerHTML = `<div class="content-grid">
      <section class="panel"><div class="panel-header"><div><p class="eyebrow mb-0">Automation profiles</p><h2 class="h5 mb-0">VM Blueprints</h2></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Project</th><th>Template / Plan</th><th>Ansible</th><th>Status</th><th></th></tr></thead><tbody id="blueprintRows"></tbody></table></div></section>
      <section class="panel"><div class="panel-header"><div><p class="eyebrow mb-0">One-click VM</p><h2 class="h5 mb-0" id="blueprintFormTitle">New blueprint</h2></div></div><form id="blueprintForm" class="panel-body"><input type="hidden" id="blueprintId">
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label">Name</label><input class="form-control" id="bpName" required></div>
          <div class="col-md-4"><label class="form-label">Slug</label><input class="form-control" id="bpSlug" pattern="[a-z0-9][a-z0-9-]{1,99}" required></div>
          <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" id="bpDescription" rows="2"></textarea></div>
          <div class="col-md-6"><label class="form-label">Project</label><select class="form-select" id="bpProject" required>${options(baseCatalog.projects)}</select></div>
          <div class="col-md-6"><label class="form-label">Resource plan</label><select class="form-select" id="bpPlan" required>${options(baseCatalog.plans)}</select></div>
          <div class="col-md-6"><label class="form-label">Template</label><select class="form-select" id="bpTemplate" required></select></div>
          <div class="col-md-6"><label class="form-label">Network</label><select class="form-select" id="bpNetwork" required></select></div>
          <div class="col-md-6"><label class="form-label">Storage</label><select class="form-select" id="bpStorage" required></select></div>
          <div class="col-md-6"><label class="form-label">Cloud-Init profile</label><select class="form-select" id="bpCloudInit"><option value="">Default</option>${options(cloudInit)}</select></div>
          <div class="col-12"><label class="form-label">Initial hardening command</label><input class="form-control font-monospace" id="bpHardening" value="/root/vm-setup.sh"><div class="form-text">Executed inside the VM through QEMU Guest Agent before reboot.</div></div>
          <div class="col-md-8"><label class="form-label">Ansible playbook</label><select class="form-select" id="bpPlaybook"><option value="">None</option>${playbooks.map(p => `<option value="${h(p.id)}">${h(p.name)}</option>`).join('')}</select></div>
          <div class="col-md-4"><label class="form-label">Ansible extra_vars JSON</label><input class="form-control font-monospace" id="bpExtraVars" value="{}"></div>
          <div class="col-md-4 form-check ms-2"><input class="form-check-input" type="checkbox" id="bpReboot" checked><label class="form-check-label" for="bpReboot">Reboot before Ansible</label></div>
          <div class="col-md-4 form-check ms-2"><input class="form-check-input" type="checkbox" id="bpPuppet"><label class="form-check-label" for="bpPuppet">Run Puppet enrollment</label></div>
          <div class="col-md-4 form-check ms-2"><input class="form-check-input" type="checkbox" id="bpEnabled" checked><label class="form-check-label" for="bpEnabled">Enabled</label></div>
        </div>
        <div class="alert alert-secondary mt-4"><strong>Pipeline:</strong> Cloud Portal → Proxmox API → clone from template → Cloud-Init → initial hardening → reboot → Ansible playbook → VM running.</div>
        <div class="d-flex gap-2 mt-3"><button class="btn btn-primary" type="submit">Save blueprint</button><button class="btn btn-outline-secondary" type="button" id="bpReset">New</button></div>
        <div class="alert mt-3 d-none" id="bpMessage"></div>
      </form></section>
    </div>`;

    const project = document.getElementById('bpProject');
    async function refreshProjectCatalog(selected = {}) {
      if (!project.value) return;
      const catalog = await api(`/api/v1/catalog?project_id=${encodeURIComponent(project.value)}`);
      const template = document.getElementById('bpTemplate');
      const network = document.getElementById('bpNetwork');
      const storage = document.getElementById('bpStorage');
      template.innerHTML = options(catalog.templates);
      network.innerHTML = options(catalog.networks);
      storage.innerHTML = options(catalog.storages, 'id', 'name');
      if (selected.template_id) template.value = String(selected.template_id);
      if (selected.network_id) network.value = String(selected.network_id);
      if (selected.storage_id) storage.value = String(selected.storage_id);
    }
    project.addEventListener('change', () => refreshProjectCatalog().catch(showError));
    await refreshProjectCatalog();

    const rows = document.getElementById('blueprintRows');
    function renderRows() {
      rows.innerHTML = blueprints.length ? blueprints.map(bp => `<tr><td><strong>${h(bp.name)}</strong><div class="resource-meta">${h(bp.slug)}</div></td><td>${h(bp.project_name)}</td><td>${h(bp.template_name)}<div class="resource-meta">${h(bp.plan_name)}</div></td><td>${h(bp.ansible_playbook || '—')}</td><td>${Number(bp.enabled) ? '<span class="status-badge status-running">enabled</span>' : '<span class="status-badge">disabled</span>'}</td><td><button class="btn btn-sm btn-outline-primary" type="button" data-edit-blueprint="${Number(bp.id)}">Edit</button></td></tr>`).join('') : '<tr><td colspan="6">No blueprints configured.</td></tr>';
    }
    renderRows();

    async function editBlueprint(bp) {
      document.getElementById('blueprintId').value = bp.id;
      document.getElementById('blueprintFormTitle').textContent = `Edit: ${bp.name}`;
      document.getElementById('bpName').value = bp.name || '';
      document.getElementById('bpSlug').value = bp.slug || '';
      document.getElementById('bpDescription').value = bp.description || '';
      project.value = String(bp.project_id);
      document.getElementById('bpPlan').value = String(bp.plan_id);
      await refreshProjectCatalog(bp);
      document.getElementById('bpCloudInit').value = bp.cloud_init_profile_id ? String(bp.cloud_init_profile_id) : '';
      document.getElementById('bpHardening').value = bp.initial_hardening_command || '';
      document.getElementById('bpPlaybook').value = bp.ansible_playbook || '';
      const vars = typeof bp.ansible_extra_vars === 'string' ? JSON.parse(bp.ansible_extra_vars || '{}') : (bp.ansible_extra_vars || {});
      document.getElementById('bpExtraVars').value = JSON.stringify(vars);
      document.getElementById('bpReboot').checked = Boolean(Number(bp.reboot_before_ansible));
      document.getElementById('bpPuppet').checked = Boolean(Number(bp.run_puppet));
      document.getElementById('bpEnabled').checked = Boolean(Number(bp.enabled));
    }
    rows.addEventListener('click', event => {
      const button = event.target.closest('[data-edit-blueprint]');
      if (!button) return;
      const bp = blueprints.find(item => Number(item.id) === Number(button.dataset.editBlueprint));
      if (bp) editBlueprint(bp).catch(showError);
    });

    const form = document.getElementById('blueprintForm');
    form.addEventListener('submit', async event => {
      event.preventDefault();
      let extraVars;
      try { extraVars = JSON.parse(document.getElementById('bpExtraVars').value || '{}'); }
      catch { showError(new Error('Ansible extra_vars must be valid JSON.')); return; }
      const data = {
        name: document.getElementById('bpName').value,
        slug: document.getElementById('bpSlug').value,
        description: document.getElementById('bpDescription').value,
        project_id: Number(project.value),
        plan_id: Number(document.getElementById('bpPlan').value),
        template_id: Number(document.getElementById('bpTemplate').value),
        network_id: Number(document.getElementById('bpNetwork').value),
        storage_id: Number(document.getElementById('bpStorage').value),
        cloud_init_profile_id: document.getElementById('bpCloudInit').value ? Number(document.getElementById('bpCloudInit').value) : null,
        initial_hardening_command: document.getElementById('bpHardening').value,
        ansible_playbook: document.getElementById('bpPlaybook').value,
        ansible_extra_vars: extraVars,
        reboot_before_ansible: document.getElementById('bpReboot').checked,
        run_puppet: document.getElementById('bpPuppet').checked,
        enabled: document.getElementById('bpEnabled').checked,
      };
      const id = Number(document.getElementById('blueprintId').value || 0);
      await api(id ? `/api/v1/admin/blueprints/${id}` : '/api/v1/admin/blueprints', {method: id ? 'PATCH' : 'POST', body: data});
      const message = document.getElementById('bpMessage');
      message.className = 'alert alert-success mt-3';
      message.textContent = 'Blueprint saved. Reloading…';
      setTimeout(() => location.reload(), 300);
    });
    document.getElementById('bpReset').addEventListener('click', () => location.reload());
  }

  function showError(error) {
    const message = document.getElementById('bpMessage');
    if (message) {
      message.className = 'alert alert-danger mt-3';
      message.textContent = error.message || String(error);
    } else {
      content.innerHTML = `<div class="alert alert-danger">${h(error.message || error)}</div>`;
    }
  }

  load().catch(showError);
})();
