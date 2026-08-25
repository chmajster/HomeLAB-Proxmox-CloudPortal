(() => {
  'use strict';

  const body = document.body;
  const basePath = body.dataset.basePath || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const projectCopy = locale === 'pl' ? {
    name: 'Nazwa projektu',
    slug: 'Slug projektu',
    description: 'Opis (opcjonalnie)',
    slugHelp: '2–100 znaków. Dozwolone: małe litery a-z, cyfry 0-9 i myślnik (-). Slug musi zaczynać się literą lub cyfrą, np. moj-projekt.',
    slugInvalid: 'Nieprawidłowy slug. Użyj 2–100 znaków: małe litery a-z, cyfry 0-9 i myślnik (-). Przykład: moj-projekt.',
    actions: 'Akcje',
    details: 'Szczegóły',
    suspend: 'Zawieś',
    activate: 'Aktywuj',
    addMember: 'Dodaj członka',
    assignAccess: 'Przypisz sieć/storage',
    members: 'Członkowie',
    networks: 'Sieci',
    remove: 'Usuń',
  } : {
    name: 'Project name',
    slug: 'Project slug',
    description: 'Description (optional)',
    slugHelp: '2–100 characters. Allowed: lowercase a-z letters, digits 0-9 and hyphens (-). The slug must start with a letter or digit, e.g. my-project.',
    slugInvalid: 'Invalid slug. Use 2–100 characters: lowercase a-z letters, digits 0-9 and hyphens (-). Example: my-project.',
    actions: 'Actions',
    details: 'Details',
    suspend: 'Suspend',
    activate: 'Activate',
    addMember: 'Add member',
    assignAccess: 'Assign network/storage',
    members: 'Members',
    networks: 'Networks',
    remove: 'Remove',
  };
  const workerCopy = locale === 'pl' ? {
    title: 'Worker i kolejka zadań',
    online: 'Online', offline: 'Offline', neverSeen: 'Nigdy nie uruchomiony',
    queued: 'W kolejce', running: 'W trakcie', failed: 'Błędne', dead: 'Dead letter', stuck: 'Podejrzanie długie',
    lastSeen: 'Ostatni heartbeat', worker: 'Worker', openQueue: 'Otwórz kolejkę zadań',
    blocked: 'Worker jest offline, a kolejka zawiera zadania. Operacje Proxmox nie będą wykonywane do czasu uruchomienia workera.',
    idleOffline: 'Worker jest offline. Portal działa, ale nowe operacje Proxmox pozostaną w kolejce do czasu jego uruchomienia.',
    healthy: 'Worker odpowiada prawidłowo. Reconcile nieudanych operacji tworzenia VM jest wykonywany automatycznie przez worker.',
    unavailable: 'Nie udało się pobrać stanu workera.',
    seconds: 's temu', minute: 'min temu', minutes: 'min temu',
  } : {
    title: 'Worker and job queue',
    online: 'Online', offline: 'Offline', neverSeen: 'Never started',
    queued: 'Queued', running: 'Running', failed: 'Failed', dead: 'Dead letter', stuck: 'Long-running',
    lastSeen: 'Last heartbeat', worker: 'Worker', openQueue: 'Open job queue',
    blocked: 'The worker is offline while jobs are queued. Proxmox operations will not execute until the worker is running.',
    idleOffline: 'The worker is offline. The portal remains available, but new Proxmox operations will stay queued until it starts.',
    healthy: 'The worker is responding normally. Failed create reconciliation is handled automatically by the worker.',
    unavailable: 'Worker health could not be loaded.',
    seconds: 's ago', minute: 'min ago', minutes: 'min ago',
  };

  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));

  function enhanceProjectUi() {
    if (body.dataset.page !== 'projects') return;
    const form = document.getElementById('adminCreate');
    if (form) {
      const name = form.elements.namedItem('name');
      const slug = form.elements.namedItem('slug');
      const description = form.elements.namedItem('description');
      if (name) {
        name.placeholder = projectCopy.name;
        name.maxLength = 100;
        name.setAttribute('aria-label', projectCopy.name);
      }
      if (slug) {
        slug.placeholder = projectCopy.slug;
        slug.minLength = 2;
        slug.maxLength = 100;
        slug.pattern = '[a-z0-9][a-z0-9-]{1,99}';
        slug.title = projectCopy.slugInvalid;
        slug.setAttribute('aria-label', projectCopy.slug);
        if (!document.getElementById('projectSlugHelp')) {
          const help = document.createElement('div');
          help.id = 'projectSlugHelp';
          help.className = 'form-text';
          help.textContent = projectCopy.slugHelp;
          slug.insertAdjacentElement('afterend', help);
          slug.setAttribute('aria-describedby', help.id);
        }
        if (slug.dataset.validationBound !== '1') {
          slug.dataset.validationBound = '1';
          slug.addEventListener('input', () => slug.setCustomValidity(''));
          slug.addEventListener('invalid', () => slug.setCustomValidity(projectCopy.slugInvalid));
        }
      }
      if (description) {
        description.placeholder = projectCopy.description;
        description.maxLength = 5000;
        description.setAttribute('aria-label', projectCopy.description);
      }
    }

    document.querySelectorAll('th').forEach(th => {
      if (th.textContent.trim() === 'Actions') th.textContent = projectCopy.actions;
    });
    document.querySelectorAll('[data-admin-action="project-details"]').forEach(button => replaceButtonText(button, projectCopy.details));
    document.querySelectorAll('[data-admin-action="project-status"]').forEach(button => replaceButtonText(button, button.dataset.status === 'active' ? projectCopy.suspend : projectCopy.activate));
    document.querySelectorAll('[data-admin-action="member"]').forEach(button => replaceButtonText(button, projectCopy.addMember));
    document.querySelectorAll('[data-admin-action="project-access"]').forEach(button => replaceButtonText(button, projectCopy.assignAccess));

    const modal = document.getElementById('confirmMessage');
    if (modal && locale === 'pl') {
      modal.querySelectorAll('h3').forEach(heading => {
        if (heading.textContent.trim() === 'Members') heading.textContent = projectCopy.members;
        if (heading.textContent.trim() === 'Networks') heading.textContent = projectCopy.networks;
      });
      modal.querySelectorAll('button').forEach(button => {
        if (button.textContent.trim() === 'Remove') replaceButtonText(button, projectCopy.remove);
      });
    }
  }

  function replaceButtonText(button, text) {
    if (button.textContent.trim() === text) return;
    const svg = button.querySelector('svg');
    button.textContent = '';
    if (svg) button.append(svg);
    button.append(document.createTextNode(text));
  }

  function heartbeatAge(seconds) {
    if (seconds === null || seconds === undefined) return '—';
    const value = Math.max(0, Number(seconds) || 0);
    if (value < 60) return `${Math.round(value)} ${workerCopy.seconds}`;
    const minutes = Math.round(value / 60);
    return `${minutes} ${minutes === 1 ? workerCopy.minute : workerCopy.minutes}`;
  }

  function workerHealthHost() {
    let host = document.getElementById('workerHealthPanel');
    if (host) return host;
    const appContent = document.getElementById('appContent');
    if (!appContent) return null;
    host = document.createElement('section');
    host.id = 'workerHealthPanel';
    host.className = 'panel mb-3';
    appContent.insertAdjacentElement('beforebegin', host);
    return host;
  }

  async function loadWorkerHealth() {
    if (body.dataset.page !== 'dashboard' || body.dataset.admin !== '1') return;
    const host = workerHealthHost();
    if (!host) return;
    try {
      const response = await fetch(appUrl('/api/v1/admin/system/health'), {headers: {'Accept': 'application/json'}});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
      const data = payload.data || {};
      const jobs = data.jobs || {};
      const worker = data.worker || null;
      const online = data.worker_online === true;
      const required = data.worker_required === true;
      const statusText = online ? workerCopy.online : (data.worker_status === 'never_seen' ? workerCopy.neverSeen : workerCopy.offline);
      const severity = online ? 'success' : (required ? 'danger' : 'warning');
      const explanation = online ? workerCopy.healthy : (required ? workerCopy.blocked : workerCopy.idleOffline);
      const workerName = worker ? `${worker.worker_name || 'worker'} · ${worker.hostname || '—'} · PID ${worker.pid || '—'} · v${worker.version || '—'}` : '—';
      const metrics = [
        [workerCopy.queued, jobs.queued || 0], [workerCopy.running, jobs.running || 0],
        [workerCopy.failed, jobs.failed || 0], [workerCopy.dead, jobs.dead_letter || 0], [workerCopy.stuck, jobs.stuck_running || 0],
      ].map(([label, value]) => `<div class="col-6 col-md"><div class="resource-meta">${escapeHtml(label)}</div><strong>${escapeHtml(value)}</strong></div>`).join('');
      host.className = `panel mb-3 border-${severity}`;
      host.innerHTML = `<div class="panel-header"><div><h2 class="h5 mb-1">${escapeHtml(workerCopy.title)}</h2><div class="resource-meta">${escapeHtml(workerCopy.worker)}: ${escapeHtml(workerName)}</div></div><span class="status-badge status-${online ? 'running' : 'error'}">${escapeHtml(statusText)}</span></div><div class="panel-body"><div class="alert alert-${severity} py-2 mb-3">${escapeHtml(explanation)}</div><div class="row g-3 mb-3">${metrics}</div><div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><small class="text-secondary">${escapeHtml(workerCopy.lastSeen)}: ${escapeHtml(heartbeatAge(data.worker_age_seconds))}</small><a class="btn btn-sm btn-outline-primary" href="${appUrl('/activity')}">${escapeHtml(workerCopy.openQueue)}</a></div></div>`;
    } catch (error) {
      host.className = 'panel mb-3 border-warning';
      host.innerHTML = `<div class="panel-body"><div class="alert alert-warning mb-0">${escapeHtml(workerCopy.unavailable)} ${escapeHtml(error.message || String(error))}</div></div>`;
    }
  }

  let enhanceQueued = false;
  const observer = new MutationObserver(() => {
    if (enhanceQueued) return;
    enhanceQueued = true;
    queueMicrotask(() => {
      enhanceQueued = false;
      enhanceProjectUi();
    });
  });
  observer.observe(document.body, {childList: true, subtree: true});
  enhanceProjectUi();
  loadWorkerHealth();
  if (body.dataset.page === 'dashboard' && body.dataset.admin === '1') {
    window.setInterval(loadWorkerHealth, 15000);
  }

  document.addEventListener('click', async event => {
    const projectDetails = event.target.closest('[data-admin-action="project-details"][data-id]');
    if (projectDetails) {
      event.preventDefault();
      event.stopImmediatePropagation();
      const id = Number(projectDetails.dataset.id);
      if (Number.isInteger(id) && id > 0) location.assign(appUrl(`/projects/${id}`));
      return;
    }

    const portalDetails = event.target.closest('[data-action="details"][data-id]');
    if (portalDetails) {
      event.preventDefault();
      event.stopImmediatePropagation();
      const id = Number(portalDetails.dataset.id);
      if (Number.isInteger(id) && id > 0) location.assign(appUrl(`/vms/${id}`));
      return;
    }

    const liveDetails = event.target.closest('[data-live-action="details"][data-live-vm]');
    if (!liveDetails) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    liveDetails.disabled = true;
    try {
      const response = await fetch(appUrl('/api/v1/admin/vms/discovery'), {headers: {'Accept': 'application/json'}});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
      const vm = payload.data?.vms?.[Number(liveDetails.dataset.liveVm)];
      if (!vm) throw new Error(locale === 'pl' ? 'Nie znaleziono wybranej maszyny w aktualnym odczycie Proxmox.' : 'The selected machine was not found in the current Proxmox inventory.');
      location.assign(appUrl(`/infrastructure/vms/${encodeURIComponent(vm.connection_id)}/${encodeURIComponent(vm.node_name)}/${encodeURIComponent(vm.vmid)}`));
    } catch (error) {
      liveDetails.disabled = false;
      const container = document.getElementById('toastContainer');
      if (container && window.bootstrap?.Toast) {
        const toast = document.createElement('div');
        toast.className = 'toast align-items-center text-bg-danger border-0';
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `<div class="d-flex"><div class="toast-body">${escapeHtml(error.message || String(error))}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        container.append(toast);
        const instance = new bootstrap.Toast(toast, {delay: 6000});
        toast.addEventListener('hidden.bs.toast', () => toast.remove());
        instance.show();
      }
    }
  }, true);
})();
