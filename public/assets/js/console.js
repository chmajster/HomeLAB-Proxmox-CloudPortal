(() => {
  'use strict';

  const body = document.body;
  if (!body) return;

  const basePath = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;

  function toast(message, kind = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container || !window.bootstrap?.Toast) {
      if (kind === 'danger') console.error(message);
      return;
    }
    const element = document.createElement('div');
    element.className = `toast align-items-center text-bg-${kind} border-0`;
    element.setAttribute('role', 'status');
    element.innerHTML = `<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
    element.querySelector('.toast-body').textContent = message;
    container.append(element);
    const instance = new bootstrap.Toast(element, {delay: 6000});
    element.addEventListener('hidden.bs.toast', () => element.remove());
    instance.show();
  }

  async function requestConsole(path, filename) {
    const response = await fetch(appUrl(path), {
      method: 'POST',
      headers: {
        'Accept': 'application/x-virt-viewer, application/json',
        'X-CSRF-Token': csrf,
      },
    });

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}));
      throw new Error(payload.error?.message || `HTTP ${response.status}`);
    }

    const content = await response.text();
    const urlMatch = content.match(/^CLOUDPORTAL_CONSOLE_URL=(.+)$/m);
    const modeMatch = content.match(/^CLOUDPORTAL_CONSOLE_MODE=(.+)$/m);

    if (urlMatch) {
      const link = document.createElement('a');
      link.href = urlMatch[1].trim();
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      document.body.append(link);
      link.click();
      link.remove();
      const mode = modeMatch?.[1]?.trim() || 'web';
      toast(locale === 'pl'
        ? `Otwarto konsolę Proxmox (${mode}). Jeśli Proxmox poprosi o logowanie, zaloguj się w nowej karcie.`
        : `Opened the Proxmox console (${mode}). If Proxmox asks for authentication, sign in in the new tab.`);
      return;
    }

    const blob = new Blob([content], {type: response.headers.get('content-type') || 'application/x-virt-viewer'});
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
    toast(locale === 'pl' ? 'Pobrano konfigurację konsoli SPICE.' : 'SPICE console configuration downloaded.');
  }

  let discoveryPromise = null;
  async function liveVmByIndex(index) {
    if (!discoveryPromise) {
      discoveryPromise = fetch(appUrl('/api/v1/admin/vms/discovery'), {headers: {'Accept': 'application/json'}})
        .then(async response => {
          const payload = await response.json().catch(() => ({}));
          if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
          return payload.data?.vms || [];
        })
        .finally(() => { window.setTimeout(() => { discoveryPromise = null; }, 1000); });
    }
    const vms = await discoveryPromise;
    return vms[index] || null;
  }

  document.addEventListener('click', async event => {
    const portalButton = event.target.closest('[data-action="console"][data-id]');
    const liveButton = event.target.closest('[data-live-action="console"][data-live-vm]');
    if (!portalButton && !liveButton) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    const button = portalButton || liveButton;
    if (button.disabled) return;
    button.disabled = true;

    try {
      if (portalButton) {
        const id = Number(portalButton.dataset.id);
        if (!Number.isInteger(id) || id <= 0) throw new Error('Invalid VM id.');
        await requestConsole(`/api/v1/vms/${id}/console`, 'console.vv');
        return;
      }

      const index = Number(liveButton.dataset.liveVm);
      const vm = await liveVmByIndex(index);
      if (!vm) throw new Error(locale === 'pl' ? 'Nie znaleziono VM w aktualnym inventarzu.' : 'VM was not found in the current inventory.');
      const path = `/api/v1/admin/proxmox-vms/${Number(vm.connection_id)}/${encodeURIComponent(vm.node_name)}/${Number(vm.vmid)}/console`;
      await requestConsole(path, `proxmox-vm-${Number(vm.vmid)}.vv`);
    } catch (error) {
      toast(error.message || String(error), 'danger');
    } finally {
      button.disabled = false;
    }
  }, true);
})();
