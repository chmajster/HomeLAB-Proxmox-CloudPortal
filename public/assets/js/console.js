(() => {
  'use strict';

  const body = document.body;
  if (!body) return;

  const basePath = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  let currentRfb = null;
  let currentFallback = null;
  let modalInstance = null;

  function toast(message, kind = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container || !window.bootstrap?.Toast) {
      if (kind === 'danger') console.error(message);
      return;
    }
    const element = document.createElement('div');
    element.className = `toast align-items-center text-bg-${kind} border-0`;
    element.setAttribute('role', 'status');
    element.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    element.querySelector('.toast-body').textContent = message;
    container.append(element);
    const instance = new bootstrap.Toast(element, {delay: 6000});
    element.addEventListener('hidden.bs.toast', () => element.remove());
    instance.show();
  }

  function ensureModal() {
    let modal = document.getElementById('consoleModal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'consoleModal';
    modal.tabIndex = -1;
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `<div class="modal-dialog modal-xl modal-dialog-centered console-dialog"><div class="modal-content portal-card"><div class="modal-header"><div><p class="eyebrow mb-0">${locale === 'pl' ? 'Konsola VM' : 'VM console'}</p><h2 class="modal-title fs-5" id="consoleModalTitle">noVNC</h2></div><div class="ms-auto d-flex align-items-center gap-2"><span class="badge text-bg-secondary" id="consoleStatus">${locale === 'pl' ? 'Rozłączono' : 'Disconnected'}</span><button type="button" class="btn btn-sm btn-outline-secondary" id="consoleFullscreen">${locale === 'pl' ? 'Pełny ekran' : 'Fullscreen'}</button><button type="button" class="btn btn-sm btn-outline-secondary" id="consoleFallback">SPICE / Proxmox</button><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div></div><div class="modal-body p-0"><div class="console-screen" id="consoleScreen" tabindex="0"><div class="console-placeholder" id="consolePlaceholder"><span class="spinner-border" aria-hidden="true"></span><span>${locale === 'pl' ? 'Łączenie z konsolą…' : 'Connecting to console…'}</span></div></div></div><div class="modal-footer py-2"><small class="text-secondary me-auto">${locale === 'pl' ? 'Sesja noVNC jest zestawiana przez lokalny gateway; token Proxmox nie jest wysyłany do przeglądarki.' : 'The noVNC session is bridged by the local gateway; the Proxmox API token is not sent to the browser.'}</small><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">${locale === 'pl' ? 'Zamknij' : 'Close'}</button></div></div></div>`;
    document.body.append(modal);
    modalInstance = window.bootstrap?.Modal ? new bootstrap.Modal(modal, {backdrop: 'static'}) : null;
    modal.addEventListener('hidden.bs.modal', disconnect);
    modal.querySelector('#consoleFullscreen').addEventListener('click', async () => {
      const screen = modal.querySelector('#consoleScreen');
      try {
        if (!document.fullscreenElement) await screen.requestFullscreen();
        else await document.exitFullscreen();
      } catch (error) {
        toast(error.message || String(error), 'danger');
      }
    });
    modal.querySelector('#consoleFallback').addEventListener('click', async () => {
      if (!currentFallback) return;
      try { await requestExternalConsole(currentFallback.path, currentFallback.filename); }
      catch (error) { toast(error.message || String(error), 'danger'); }
    });
    return modal;
  }

  function setStatus(text, kind = 'secondary') {
    const element = document.getElementById('consoleStatus');
    if (!element) return;
    element.className = `badge text-bg-${kind}`;
    element.textContent = text;
  }

  function disconnect() {
    if (currentRfb) {
      try { currentRfb.disconnect(); } catch {}
      currentRfb = null;
    }
    const screen = document.getElementById('consoleScreen');
    if (screen) screen.replaceChildren();
    setStatus(locale === 'pl' ? 'Rozłączono' : 'Disconnected');
  }

  async function requestSession(path) {
    const response = await fetch(appUrl(path), {
      method: 'POST',
      headers: {'Accept': 'application/json', 'X-CSRF-Token': csrf},
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data || {};
  }

  async function openEmbedded(sessionPath, fallback, title) {
    const modal = ensureModal();
    currentFallback = fallback;
    disconnect();
    modal.querySelector('#consoleModalTitle').textContent = title || 'noVNC';
    const screen = modal.querySelector('#consoleScreen');
    const placeholder = document.createElement('div');
    placeholder.className = 'console-placeholder';
    placeholder.innerHTML = `<span class="spinner-border" aria-hidden="true"></span><span>${locale === 'pl' ? 'Łączenie z konsolą…' : 'Connecting to console…'}</span>`;
    screen.replaceChildren(placeholder);
    setStatus(locale === 'pl' ? 'Łączenie' : 'Connecting', 'warning');
    modalInstance?.show();

    try {
      const session = await requestSession(sessionPath);
      if (session.mode !== 'novnc' || !session.rfb_module || !session.ws_path || !session.password) throw new Error(locale === 'pl' ? 'Backend nie zwrócił kompletnej sesji noVNC.' : 'Backend returned an incomplete noVNC session.');
      const module = await import(appUrl(session.rfb_module));
      const RFB = module.default || module.RFB;
      if (typeof RFB !== 'function') throw new Error(locale === 'pl' ? 'Nie udało się załadować klienta noVNC.' : 'Unable to load the noVNC client.');
      const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
      const wsUrl = `${scheme}//${location.host}${appUrl(session.ws_path)}`;
      screen.replaceChildren();
      const rfb = new RFB(screen, wsUrl, {credentials: {password: session.password}});
      currentRfb = rfb;
      rfb.scaleViewport = true;
      rfb.resizeSession = true;
      rfb.showDotCursor = true;
      rfb.background = '#111111';
      rfb.addEventListener('connect', () => {
        setStatus(locale === 'pl' ? 'Połączono' : 'Connected', 'success');
        screen.focus();
      });
      rfb.addEventListener('disconnect', event => {
        if (currentRfb === rfb) currentRfb = null;
        setStatus(event.detail?.clean ? (locale === 'pl' ? 'Rozłączono' : 'Disconnected') : (locale === 'pl' ? 'Połączenie zerwane' : 'Connection lost'), event.detail?.clean ? 'secondary' : 'danger');
      });
      rfb.addEventListener('securityfailure', event => {
        setStatus(locale === 'pl' ? 'Błąd autoryzacji' : 'Authentication error', 'danger');
        toast(event.detail?.reason || (locale === 'pl' ? 'noVNC odrzuciło dane sesji.' : 'noVNC rejected the session credentials.'), 'danger');
      });
      rfb.addEventListener('credentialsrequired', () => {
        try { rfb.sendCredentials({password: session.password}); } catch {}
      });
    } catch (error) {
      setStatus(locale === 'pl' ? 'Błąd' : 'Error', 'danger');
      placeholder.innerHTML = `<div class="alert alert-danger m-3"><strong>${locale === 'pl' ? 'Nie udało się uruchomić osadzonej konsoli.' : 'Unable to start the embedded console.'}</strong><div class="small mt-1"></div><button type="button" class="btn btn-sm btn-outline-danger mt-3" data-console-fallback-inline>SPICE / Proxmox</button></div>`;
      placeholder.querySelector('.small').textContent = error.message || String(error);
      placeholder.querySelector('[data-console-fallback-inline]').addEventListener('click', () => modal.querySelector('#consoleFallback').click());
      if (!placeholder.isConnected) screen.replaceChildren(placeholder);
    }
  }

  async function requestExternalConsole(path, filename) {
    const response = await fetch(appUrl(path), {
      method: 'POST',
      headers: {'Accept': 'application/x-virt-viewer, application/json', 'X-CSRF-Token': csrf},
    });
    if (!response.ok) {
      const payload = await response.json().catch(() => ({}));
      throw new Error(payload.error?.message || `HTTP ${response.status}`);
    }
    const content = await response.text();
    const urlMatch = content.match(/^CLOUDPORTAL_CONSOLE_URL=(.+)$/m);
    if (urlMatch) {
      window.open(urlMatch[1].trim(), '_blank', 'noopener,noreferrer');
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
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    toast(locale === 'pl' ? 'Pobrano konfigurację konsoli SPICE.' : 'SPICE console configuration downloaded.');
  }

  async function openPortal(id) {
    id = Number(id);
    if (!Number.isInteger(id) || id <= 0) throw new Error('Invalid VM id.');
    return openEmbedded(
      `/api/v1/vms/${id}/console/session`,
      {path: `/api/v1/vms/${id}/console`, filename: `vm-${id}.vv`},
      `VM ${id} · noVNC`,
    );
  }

  async function openLive(connectionId, node, vmid) {
    connectionId = Number(connectionId);
    vmid = Number(vmid);
    if (!Number.isInteger(connectionId) || connectionId <= 0 || !Number.isInteger(vmid) || vmid < 100 || !node) throw new Error('Invalid Proxmox VM target.');
    const base = `/api/v1/admin/proxmox-vms/${connectionId}/${encodeURIComponent(node)}/${vmid}/console`;
    return openEmbedded(
      `${base}/session`,
      {path: base, filename: `proxmox-vm-${vmid}.vv`},
      `VM ${vmid} · noVNC`,
    );
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

  window.CloudPortalConsole = {openPortal, openLive, fallback: requestExternalConsole, disconnect};

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
      if (portalButton) await openPortal(portalButton.dataset.id);
      else {
        const vm = await liveVmByIndex(Number(liveButton.dataset.liveVm));
        if (!vm) throw new Error(locale === 'pl' ? 'Nie znaleziono VM w aktualnym inventarzu.' : 'VM was not found in the current inventory.');
        await openLive(vm.connection_id, vm.node_name, vm.vmid);
      }
    } catch (error) {
      toast(error.message || String(error), 'danger');
    } finally {
      button.disabled = false;
    }
  }, true);
})();
