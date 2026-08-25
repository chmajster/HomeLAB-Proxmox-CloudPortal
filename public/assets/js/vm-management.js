(() => {
  'use strict';

  const body = document.body;
  if (!body || body.dataset.page !== 'vm-details') return;
  const content = document.getElementById('appContent');
  if (!content) return;

  const basePath = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const isAdmin = body.dataset.admin === '1';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const relativePath = location.pathname.startsWith(basePath) ? location.pathname.slice(basePath.length) : location.pathname;
  const portalMatch = relativePath.match(/^\/vms\/(\d+)$/);
  const liveMatch = relativePath.match(/^\/infrastructure\/vms\/(\d+)\/([^/]+)\/(\d+)$/);
  let target = null;

  async function api(path, options = {}) {
    const init = {...options, headers:{Accept:'application/json', ...(['GET','HEAD'].includes(options.method || 'GET') ? {} : {'X-CSRF-Token':csrf}), ...(options.body ? {'Content-Type':'application/json'} : {}), ...(options.headers || {})}};
    if (options.body && typeof options.body !== 'string') init.body = JSON.stringify(options.body);
    const response = await fetch(appUrl(path), init);
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

  async function pollJob(jobId) {
    let attempts = 0;
    const poll = async () => {
      try {
        const job = await api(`/api/v1/jobs/${encodeURIComponent(jobId)}`);
        if (job.status === 'completed') {
          toast(locale === 'pl' ? 'Operacja zakończona.' : 'Operation completed.');
          window.setTimeout(() => location.reload(), 500);
          return;
        }
        if (job.status === 'failed') {
          toast(job.error_message || (locale === 'pl' ? 'Operacja nie powiodła się.' : 'Operation failed.'), 'danger');
          return;
        }
        if (++attempts < 180) window.setTimeout(poll, 2000);
      } catch {
        if (++attempts < 10) window.setTimeout(poll, 3000);
      }
    };
    window.setTimeout(poll, 900);
  }

  async function queued(path, method = 'POST', bodyData = null) {
    const result = await api(path, {method, ...(bodyData ? {body:bodyData} : {})});
    toast(locale === 'pl' ? 'Operacja została uruchomiona.' : 'Operation started.');
    if (result?.job_id) pollJob(result.job_id);
    else window.setTimeout(() => location.reload(), 1300);
  }

  function portalBase() {
    return `/api/v1/vms/${Number(target.portalId)}`;
  }

  function liveBase() {
    return `/api/v1/admin/proxmox-vms/${Number(target.connectionId)}/${encodeURIComponent(target.node)}/${Number(target.vmid)}`;
  }

  async function requestConsole(path, filename) {
    const response = await fetch(appUrl(path), {method:'POST', headers:{Accept:'application/x-virt-viewer, application/json', 'X-CSRF-Token':csrf}});
    if (!response.ok) {
      const payload = await response.json().catch(() => ({}));
      throw new Error(payload.error?.message || `HTTP ${response.status}`);
    }
    const text = await response.text();
    const url = text.match(/^CLOUDPORTAL_CONSOLE_URL=(.+)$/m)?.[1]?.trim();
    if (url) {
      window.open(url, '_blank', 'noopener,noreferrer');
      return;
    }
    const blobUrl = URL.createObjectURL(new Blob([text], {type:response.headers.get('content-type') || 'application/x-virt-viewer'}));
    const link = document.createElement('a');
    link.href = blobUrl;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(blobUrl);
  }

  function powerButtons(status) {
    if (status === 'running') return [
      ['shutdown', locale === 'pl' ? 'Wyłącz' : 'Shutdown', 'btn-outline-secondary'],
      ['reboot', 'Reboot', 'btn-outline-primary'],
      ['suspend', locale === 'pl' ? 'Wstrzymaj' : 'Suspend', 'btn-outline-secondary'],
      ['stop', 'Stop', 'btn-outline-danger'],
    ];
    if (status === 'paused') return [
      ['resume', locale === 'pl' ? 'Wznów' : 'Resume', 'btn-outline-success'],
      ['stop', 'Stop', 'btn-outline-danger'],
    ];
    return [['start', 'Start', 'btn-outline-success']];
  }

  function button(action, label, style = 'btn-outline-secondary') {
    return `<button type="button" class="btn ${style}" data-vm-manage="${action}">${label}</button>`;
  }

  function renderManagementPanel() {
    if (!target || document.getElementById('vmManagementPanel')) return;
    const firstPanel = content.querySelector('.panel');
    if (!firstPanel) return;

    const panel = document.createElement('section');
    panel.id = 'vmManagementPanel';
    panel.className = 'panel vm-management-panel mb-3';
    const power = powerButtons(target.status).map(([action,label,style]) => button(action,label,style)).join('');
    const consoleButton = target.portalId
      ? `<button type="button" class="btn btn-outline-primary" data-action="console" data-id="${Number(target.portalId)}">${locale === 'pl' ? 'Konsola' : 'Console'}</button>`
      : button('console-live', locale === 'pl' ? 'Konsola' : 'Console', 'btn-outline-primary');
    const adminAssignment = isAdmin && target.portalId ? button('assignment', locale === 'pl' ? 'Przypisanie' : 'Assignment') : '';
    const remove = target.portalId ? button('delete', locale === 'pl' ? 'Usuń VM' : 'Delete VM', 'btn-outline-danger') : '';

    panel.innerHTML = `<div class="panel-header"><div><h2 class="h5 mb-1">${locale === 'pl' ? 'Zarządzanie VM' : 'VM management'}</h2><p class="resource-meta mb-0">${locale === 'pl' ? 'Najczęstsze operacje są dostępne bez wracania do listy maszyn.' : 'Common operations are available without returning to the VM list.'}</p></div><span class="status-badge status-${target.status || 'unknown'}">${target.status || 'unknown'}</span></div><div class="panel-body"><div class="vm-management-meta"><span>VMID <strong>${target.vmid}</strong></span><span>${locale === 'pl' ? 'Węzeł' : 'Node'} <strong>${target.node || '—'}</strong></span>${target.portalId ? `<span>${locale === 'pl' ? 'Tryb' : 'Mode'} <strong>Cloud Portal</strong></span>` : `<span>${locale === 'pl' ? 'Tryb' : 'Mode'} <strong>Proxmox live</strong></span>`}</div><div class="vm-management-actions">${power}${consoleButton}${button('snapshot', locale === 'pl' ? 'Utwórz snapshot' : 'Create snapshot', 'btn-outline-primary')}${adminAssignment}${button('refresh', locale === 'pl' ? 'Odśwież' : 'Refresh')}${remove}</div><form id="vmQuickSnapshotForm" class="vm-inline-form d-none"><div><label class="form-label" for="vmQuickSnapshotName">${locale === 'pl' ? 'Nazwa snapshotu' : 'Snapshot name'}</label><input id="vmQuickSnapshotName" name="name" class="form-control" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,39}" maxlength="40" required placeholder="before-update"></div><div><label class="form-label" for="vmQuickSnapshotDescription">${locale === 'pl' ? 'Opis' : 'Description'}</label><input id="vmQuickSnapshotDescription" name="description" class="form-control" maxlength="255" placeholder="${locale === 'pl' ? 'Opcjonalnie' : 'Optional'}"></div><button type="submit" class="btn btn-primary">${locale === 'pl' ? 'Utwórz' : 'Create'}</button><button type="button" class="btn btn-outline-secondary" data-snapshot-cancel>${locale === 'pl' ? 'Anuluj' : 'Cancel'}</button></form></div>`;
    firstPanel.before(panel);

    panel.addEventListener('click', async event => {
      const manage = event.target.closest('[data-vm-manage]');
      if (!manage) return;
      const action = manage.dataset.vmManage;
      try {
        if (action === 'refresh') return location.reload();
        if (action === 'snapshot') {
          panel.querySelector('#vmQuickSnapshotForm').classList.remove('d-none');
          panel.querySelector('#vmQuickSnapshotName').focus();
          return;
        }
        if (action === 'assignment') {
          document.getElementById('vmAssignmentPanel')?.scrollIntoView({behavior:'smooth', block:'start'});
          return;
        }
        if (action === 'console-live') {
          await requestConsole(`${liveBase()}/console`, `proxmox-vm-${target.vmid}.vv`);
          return;
        }
        if (action === 'delete') {
          if (!window.confirm(locale === 'pl' ? `Usunąć VM ${target.name}? Tej operacji nie można łatwo cofnąć.` : `Delete VM ${target.name}? This operation is not easily reversible.`)) return;
          await queued(portalBase(), 'DELETE');
          return;
        }
        if (action === 'stop' && !window.confirm(locale === 'pl' ? 'Wymusić zatrzymanie VM?' : 'Force stop this VM?')) return;
        await queued(target.portalId ? `${portalBase()}/${action}` : `${liveBase()}/status/${action}`, 'POST');
      } catch (error) {
        toast(error.message || String(error), 'danger');
      }
    });

    panel.querySelector('[data-snapshot-cancel]').addEventListener('click', () => panel.querySelector('#vmQuickSnapshotForm').classList.add('d-none'));
    panel.querySelector('#vmQuickSnapshotForm').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        const data = new FormData(form);
        const payload = {name:String(data.get('name') || '').trim(), description:String(data.get('description') || '').trim()};
        await queued(target.portalId ? `${portalBase()}/snapshots` : `${liveBase()}/snapshots`, 'POST', payload);
        form.reset();
        form.classList.add('d-none');
      } catch (error) {
        toast(error.message || String(error), 'danger');
        submit.disabled = false;
      }
    });
  }

  async function resolveTarget() {
    if (portalMatch) {
      const data = await api(`/api/v1/vms/${Number(portalMatch[1])}`);
      const vm = data.vm || {};
      target = {portalId:Number(vm.id || portalMatch[1]), vmid:Number(vm.vmid || 0), node:String(vm.node_name || ''), name:String(vm.name || `VM ${vm.vmid || ''}`), status:String(vm.status || 'unknown')};
      return;
    }
    if (liveMatch) {
      const connectionId = Number(liveMatch[1]);
      const node = decodeURIComponent(liveMatch[2]);
      const vmid = Number(liveMatch[3]);
      const data = await api(`/api/v1/admin/proxmox-vms/${connectionId}/${encodeURIComponent(node)}/${vmid}`);
      const portalId = Number(data.portal?.portal_id || 0) || null;
      target = {portalId, connectionId, node, vmid, name:String(data.config?.name || data.status?.name || `VM ${vmid}`), status:String(data.status?.status || (data.runtime_available === false ? 'stopped' : 'unknown'))};
    }
  }

  resolveTarget().then(() => {
    const observer = new MutationObserver(renderManagementPanel);
    observer.observe(content, {childList:true, subtree:true});
    renderManagementPanel();
  }).catch(error => toast(error.message || String(error), 'danger'));
})();
