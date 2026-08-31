(() => {
  'use strict';

  const body = document.body;
  if (body.dataset.page !== 'create-vm') return;
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

  async function mount() {
    const blueprints = await api('/api/v1/blueprints');
    if (!Array.isArray(blueprints) || blueprints.length === 0) return;

    for (let i = 0; i < 60 && !document.getElementById('vmWizard'); i++) {
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    const wizard = document.getElementById('vmWizard');
    if (!wizard || document.getElementById('blueprintQuickDeploy')) return;

    const panel = document.createElement('section');
    panel.id = 'blueprintQuickDeploy';
    panel.className = 'panel mb-4';
    panel.innerHTML = `<div class="panel-header"><div><p class="eyebrow mb-0">Blueprint deployment</p><h2 class="h5 mb-1">Utwórz gotową VM jednym wyborem</h2><p class="text-secondary small mb-0">Cloud Portal wykona automatycznie: clone template → initial hardening → reboot → Ansible → running.</p></div></div>
      <div class="panel-body">
        <div class="row g-3 align-items-end">
          <div class="col-lg-8"><label class="form-label" for="blueprintSelect">Blueprint</label><select class="form-select form-select-lg" id="blueprintSelect">${blueprints.map(b => `<option value="${Number(b.id)}">${h(b.name)} — ${h(b.project_name || '')} / ${h(b.template_name || '')} / ${h(b.plan_name || '')}</option>`).join('')}</select></div>
          <div class="col-lg-4 d-grid"><button class="btn btn-primary btn-lg" type="button" id="deployBlueprint">Deploy blueprint</button></div>
        </div>
        <div class="small text-secondary mt-3" id="blueprintSummary"></div>
        <div class="alert alert-info mt-3 d-none" id="blueprintResult"></div>
      </div>`;
    wizard.closest('.wizard-shell')?.before(panel);

    const select = panel.querySelector('#blueprintSelect');
    const summary = panel.querySelector('#blueprintSummary');
    const button = panel.querySelector('#deployBlueprint');
    const result = panel.querySelector('#blueprintResult');
    const refresh = () => {
      const blueprint = blueprints.find(item => Number(item.id) === Number(select.value));
      if (!blueprint) return;
      summary.innerHTML = `<strong>${h(blueprint.name)}</strong> · Template: ${h(blueprint.template_name || blueprint.template_id)} · Plan: ${h(blueprint.plan_name || blueprint.plan_id)} · Network: ${h(blueprint.network_name || blueprint.network_id)} · Storage: ${h(blueprint.storage_name || blueprint.storage_id)} · Ansible: ${h(blueprint.ansible_playbook || 'none')}`;
    };
    select.addEventListener('change', refresh);
    refresh();

    button.addEventListener('click', async () => {
      button.disabled = true;
      result.classList.add('d-none');
      try {
        const deployed = await api(`/api/v1/blueprints/${encodeURIComponent(select.value)}/deploy`, {method: 'POST', body: {}});
        result.className = 'alert alert-success mt-3';
        result.innerHTML = `Deployment uruchomiony. Job: <strong>${h(deployed.job_id)}</strong>. VM zostanie utworzona i skonfigurowana bez dalszej interakcji.`;
      } catch (error) {
        result.className = 'alert alert-danger mt-3';
        result.textContent = error.message || String(error);
      } finally {
        result.classList.remove('d-none');
        button.disabled = false;
      }
    });
  }

  mount().catch(() => {});
})();
