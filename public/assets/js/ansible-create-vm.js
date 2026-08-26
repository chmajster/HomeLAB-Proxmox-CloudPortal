(() => {
  'use strict';

  const body = document.body;
  if (!body || body.dataset.page !== 'create-vm') return;

  const basePath = body.dataset.basePath || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));

  async function catalog(projectId) {
    const response = await fetch(appUrl(`/api/v1/catalog?project_id=${encodeURIComponent(projectId || '')}`), {
      headers: {Accept: 'application/json'},
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data || {};
  }

  function updateStartState(form, select, note) {
    const start = form.elements.namedItem('start_after_create');
    if (!(start instanceof HTMLInputElement)) return;
    const selected = select.value !== '';
    if (selected) start.checked = true;
    start.disabled = selected;
    note.textContent = selected
      ? (locale === 'pl'
          ? 'VM zostanie uruchomiona automatycznie. Playbook wykona się jako osobny job po zakończeniu provisioningu.'
          : 'The VM will be started automatically. The playbook runs as a separate job after provisioning completes.')
      : (locale === 'pl'
          ? 'Opcjonalnie. Playbook jest wykonywany przez worker Cloud Portal na adresie IP nowej VM.'
          : 'Optional. The Cloud Portal worker runs the playbook against the new VM IP address.');
  }

  async function refresh(form, select, note) {
    const project = form.elements.namedItem('project_id');
    const projectId = project instanceof HTMLSelectElement ? project.value : '';
    select.disabled = true;
    select.innerHTML = `<option value="">${locale === 'pl' ? 'Ładowanie playbooków…' : 'Loading playbooks…'}</option>`;
    try {
      const data = await catalog(projectId);
      const playbooks = Array.isArray(data.playbooks) ? data.playbooks : [];
      select.innerHTML = `<option value="">${locale === 'pl' ? 'Nie uruchamiaj playbooka' : 'Do not run a playbook'}</option>` +
        playbooks.map(playbook => `<option value="${escapeHtml(playbook.id)}">${escapeHtml(playbook.name)}</option>`).join('');
      select.disabled = playbooks.length === 0;
      if (playbooks.length === 0) {
        note.textContent = locale === 'pl'
          ? 'Brak playbooków. Dodaj pliki .yml lub .yaml do skonfigurowanego katalogu Ansible.'
          : 'No playbooks found. Add .yml or .yaml files to the configured Ansible directory.';
      } else {
        updateStartState(form, select, note);
      }
    } catch (error) {
      select.innerHTML = `<option value="">${locale === 'pl' ? 'Nie można pobrać playbooków' : 'Could not load playbooks'}</option>`;
      note.textContent = error.message || String(error);
    }
  }

  function mount() {
    const form = document.getElementById('vmWizard');
    const summary = document.getElementById('wizardSummary');
    if (!form || !summary || document.getElementById('ansiblePlaybook')) return false;

    const wrapper = document.createElement('div');
    wrapper.className = 'mt-4';
    wrapper.innerHTML = `<label class="form-label" for="ansiblePlaybook">${locale === 'pl' ? 'Playbook Ansible po utworzeniu VM' : 'Ansible playbook after VM creation'}</label>
      <select class="form-select" id="ansiblePlaybook" name="ansible_playbook">
        <option value="">${locale === 'pl' ? 'Ładowanie playbooków…' : 'Loading playbooks…'}</option>
      </select>
      <div class="form-text" id="ansiblePlaybookNote"></div>`;
    summary.insertAdjacentElement('afterend', wrapper);

    const select = document.getElementById('ansiblePlaybook');
    const note = document.getElementById('ansiblePlaybookNote');
    if (!(select instanceof HTMLSelectElement) || !(note instanceof HTMLElement)) return true;

    select.addEventListener('change', () => updateStartState(form, select, note));
    const project = form.elements.namedItem('project_id');
    if (project instanceof HTMLSelectElement) {
      project.addEventListener('change', () => refresh(form, select, note));
    }
    refresh(form, select, note);
    return true;
  }

  if (mount()) return;
  const observer = new MutationObserver(() => {
    if (mount()) observer.disconnect();
  });
  observer.observe(document.body, {childList: true, subtree: true});
})();
