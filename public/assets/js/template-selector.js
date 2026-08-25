(() => {
  'use strict';

  const body = document.body;
  if (!body || body.dataset.page !== 'templates' || body.dataset.admin !== '1') return;
  const content = document.getElementById('appContent');
  if (!content) return;

  const basePath = body.dataset.basePath || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const h = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  let loading = false;

  async function api(path) {
    const response = await fetch(appUrl(path), {headers:{Accept:'application/json'}});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  async function enhance() {
    if (loading || document.getElementById('templateQuickSelect')) return;
    const refresh = document.getElementById('refreshProxmoxTemplates');
    const panel = refresh?.closest('.panel');
    const table = panel?.querySelector('.table-responsive');
    if (!panel || !table) return;

    loading = true;
    try {
      const [inventory, profiles] = await Promise.all([
        api('/api/v1/admin/templates/discovery'),
        api('/api/v1/admin/templates'),
      ]);
      const templates = inventory.templates || [];
      const configured = new Set((profiles || []).map(profile => `${Number(profile.connection_id)}:${Number(profile.vmid)}`));
      const options = templates.map((template, index) => ({template,index}))
        .filter(({template}) => !configured.has(`${Number(template.connection_id)}:${Number(template.vmid)}`));

      const wrapper = document.createElement('div');
      wrapper.id = 'templateQuickSelect';
      wrapper.className = 'template-quick-select';
      wrapper.innerHTML = `<div class="template-quick-select-grid"><div><label class="form-label" for="templateQuickSelectInput">${locale === 'pl' ? 'Wybierz istniejący template z Proxmox' : 'Choose an existing Proxmox template'}</label><select id="templateQuickSelectInput" class="form-select" ${options.length ? '' : 'disabled'}>${options.map(({template,index}) => `<option value="${index}">${h(template.connection_name)} · ${h(template.node_name)} · VMID ${h(template.vmid)} · ${h(template.name)}</option>`).join('') || `<option>${locale === 'pl' ? 'Wszystkie wykryte template są już w katalogu' : 'All discovered templates are already in the catalog'}</option>`}</select><div class="form-text">${locale === 'pl' ? 'Portal pokaże tylko maszyny oznaczone w Proxmox jako template. Po wyborze użyje istniejącego formularza dodawania do katalogu.' : 'Only VMs marked as templates in Proxmox are shown. The existing catalog form is used after selection.'}</div></div><button type="button" class="btn btn-primary" id="templateQuickSelectAdd" ${options.length ? '' : 'disabled'}>${locale === 'pl' ? 'Dodaj wybrany do katalogu' : 'Add selected to catalog'}</button></div>`;
      table.before(wrapper);

      wrapper.querySelector('#templateQuickSelectAdd')?.addEventListener('click', () => {
        const index = Number(wrapper.querySelector('#templateQuickSelectInput').value);
        const rowButton = panel.querySelector(`[data-template-configure="${index}"]`);
        if (rowButton) {
          rowButton.click();
          document.getElementById('templateProfilePanel')?.scrollIntoView({behavior:'smooth', block:'start'});
        }
      });
    } catch (error) {
      const wrapper = document.createElement('div');
      wrapper.id = 'templateQuickSelect';
      wrapper.className = 'template-quick-select';
      wrapper.innerHTML = `<div class="alert alert-warning mb-0">${h(locale === 'pl' ? 'Nie udało się przygotować szybkiego wyboru template: ' : 'Unable to prepare template selector: ')}${h(error.message || error)}</div>`;
      table.before(wrapper);
    } finally {
      loading = false;
    }
  }

  const observer = new MutationObserver(enhance);
  observer.observe(content, {childList:true, subtree:true});
  enhance();
})();
