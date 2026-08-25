(() => {
  'use strict';

  if (document.body.dataset.page !== 'vm-details') return;
  const body = document.body;
  const content = document.getElementById('appContent');
  const pageTitle = document.getElementById('pageTitle');
  const basePath = body.dataset.basePath || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const relativePath = location.pathname.startsWith(basePath) ? location.pathname.slice(basePath.length) : location.pathname;
  const appUrl = path => `${basePath}${path}`;
  const h = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const fmtDate = value => value ? new Intl.DateTimeFormat(locale === 'pl' ? 'pl-PL' : 'en-US', {dateStyle:'medium', timeStyle:'short'}).format(new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'))) : '—';
  const fmtUnix = value => value ? new Intl.DateTimeFormat(locale === 'pl' ? 'pl-PL' : 'en-US', {dateStyle:'medium', timeStyle:'short'}).format(new Date(Number(value) * 1000)) : '—';
  const fmtBytes = value => {
    const bytes = Number(value || 0); if (!bytes) return '—';
    const units = ['B','KB','MB','GB','TB']; const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${new Intl.NumberFormat(locale === 'pl' ? 'pl-PL' : 'en-US', {maximumFractionDigits:1}).format(bytes / (1024 ** index))} ${units[index]}`;
  };
  const badge = value => `<span class="status-badge status-${h(String(value || 'unknown'))}">${h(value || 'unknown')}</span>`;
  const api = async path => {
    const response = await fetch(appUrl(path), {headers: {'Accept':'application/json'}});
    let payload;
    try { payload = await response.json(); } catch { throw new Error(`HTTP ${response.status}`); }
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  };
  const back = href => `<a class="btn btn-outline-secondary mb-3" href="${appUrl(href)}">← ${locale === 'pl' ? 'Wróć do listy VM' : 'Back to VM list'}</a>`;
  const item = (label, value) => `<div class="summary-item"><small>${h(label)}</small>${value}</div>`;

  async function renderPortal(id) {
    const data = await api(`/api/v1/vms/${id}`);
    const vm = data.vm || {};
    pageTitle.textContent = vm.name || (locale === 'pl' ? 'Szczegóły VM' : 'VM details');
    const provisioning = data.provisioning;
    const provisioningPanel = provisioning ? `<section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">${locale === 'pl' ? 'Provisioning' : 'Provisioning'}</h2></div><div class="panel-body"><div class="summary-list">${item('Status', badge(provisioning.status))}${item(locale === 'pl' ? 'Krok' : 'Step', h(provisioning.current_step_name || provisioning.current_step || '—'))}${item('Hostname', h(provisioning.hostname || '—'))}${item('FQDN', h(provisioning.fqdn || '—'))}${item('IP', h(provisioning.ip_address || vm.ip_address || '—'))}</div>${provisioning.last_error ? `<div class="alert alert-danger mt-3 mb-0">${h(provisioning.last_error)}</div>` : ''}</div></section>` : '';
    const snapshots = (data.snapshots || []).map(snapshot => `<tr><td>${h(snapshot.name)}</td><td>${badge(snapshot.status)}</td><td>${h(snapshot.description || '—')}</td><td>${fmtDate(snapshot.created_at)}</td></tr>`).join('');
    const jobs = (data.jobs || []).map(job => `<tr><td>${fmtDate(job.created_at)}</td><td>${h(job.type)}</td><td>${badge(job.status)}</td><td class="text-danger">${h(job.error_message || '')}</td></tr>`).join('');
    content.innerHTML = `${back('/vms')}<section class="panel"><div class="panel-header"><div><h2 class="h4 mb-1">${h(vm.name || `VM ${id}`)}</h2><p class="text-secondary small mb-0">VMID ${h(vm.vmid)} · ${h(vm.node_name || '—')}</p></div>${badge(vm.status)}</div><div class="panel-body"><div class="summary-list">${item('Projekt', h(vm.project_name || '—'))}${item(locale === 'pl' ? 'Właściciel' : 'Owner', h(vm.owner_name || '—'))}${item('vCPU', h(vm.vcpu || '—'))}${item('RAM', vm.ram_mb ? `${h(Math.round(Number(vm.ram_mb)/1024))} GB` : '—')}${item(locale === 'pl' ? 'Dysk' : 'Disk', vm.disk_gb ? `${h(vm.disk_gb)} GB` : '—')}${item('IP', h(vm.ip_address || '—'))}</div></div></section>${provisioningPanel}<section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">Snapshoty</h2></div><div class="table-responsive"><table class="table"><thead><tr><th>${locale==='pl'?'Nazwa':'Name'}</th><th>Status</th><th>${locale==='pl'?'Opis':'Description'}</th><th>${locale==='pl'?'Utworzono':'Created'}</th></tr></thead><tbody>${snapshots || `<tr><td colspan="4" class="text-secondary">${locale==='pl'?'Brak snapshotów.':'No snapshots.'}</td></tr>`}</tbody></table></div></section><section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">${locale==='pl'?'Ostatnie operacje':'Recent operations'}</h2></div><div class="table-responsive"><table class="table"><thead><tr><th>${locale==='pl'?'Czas':'Time'}</th><th>${locale==='pl'?'Operacja':'Operation'}</th><th>Status</th><th>${locale==='pl'?'Błąd':'Error'}</th></tr></thead><tbody>${jobs || `<tr><td colspan="4" class="text-secondary">${locale==='pl'?'Brak operacji.':'No operations.'}</td></tr>`}</tbody></table></div></section>`;
  }

  async function renderLive(connectionId, node, vmid) {
    const data = await api(`/api/v1/admin/proxmox-vms/${connectionId}/${encodeURIComponent(node)}/${vmid}`);
    const status = data.status || {};
    const config = data.config || {};
    pageTitle.textContent = config.name || status.name || `VM ${vmid}`;
    const runtimeUnavailable = data.runtime_available === false;
    const runtimeNotice = runtimeUnavailable ? `<div class="alert alert-warning"><strong>${locale==='pl'?'VM jest zatrzymana.':'VM is stopped.'}</strong> ${h(data.runtime_note || (locale==='pl'?'Dane runtime są niedostępne, ale konfiguracja VM nadal może być wyświetlona.':'Runtime data is unavailable, but the VM configuration can still be displayed.'))}</div>` : '';
    const general = Object.entries(config).filter(([key]) => !/^(?:scsi|sata|ide|virtio|net)\d+$/.test(key));
    const hardware = Object.entries(config).filter(([key]) => /^(?:scsi|sata|ide|virtio|net)\d+$/.test(key));
    const snapshots = (data.snapshots || []).map(snapshot => `<tr><td>${h(snapshot.name)}</td><td>${h(snapshot.description || '—')}</td><td>${fmtUnix(snapshot.snaptime)}</td><td>${h(snapshot.parent || '—')}</td></tr>`).join('');
    const runtime = `${item('Status', badge(status.status || (runtimeUnavailable ? 'stopped' : 'unknown')))}${item('CPU', status.cpus ? `${h(status.cpus)} vCPU${status.cpu === undefined ? '' : ` · ${(Number(status.cpu)*100).toFixed(1)}%`}` : '—')}${item('RAM', status.maxmem ? `${fmtBytes(status.mem)} / ${fmtBytes(status.maxmem)}` : '—')}${item(locale==='pl'?'Dysk runtime':'Runtime disk', status.maxdisk ? `${fmtBytes(status.disk)} / ${fmtBytes(status.maxdisk)}` : '—')}${item('Uptime', status.uptime ? `${h(Math.floor(Number(status.uptime)/60))} min` : '—')}`;
    content.innerHTML = `${back('/vms')}${runtimeNotice}<section class="panel"><div class="panel-header"><div><h2 class="h4 mb-1">${h(config.name || status.name || `VM ${vmid}`)}</h2><p class="text-secondary small mb-0">VMID ${h(vmid)} · ${h(node)}</p></div>${badge(status.status || (runtimeUnavailable ? 'stopped' : 'unknown'))}</div><div class="panel-body"><div class="summary-list">${runtime}</div>${data.portal ? `<div class="alert alert-info mt-3 mb-0">${locale==='pl'?'Maszyna jest zarządzana przez portal.':'This machine is managed by the portal.'} ${h(data.portal.project_name || '')} · ${h(data.portal.owner_name || '')}</div>` : ''}</div></section><section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">${locale==='pl'?'Konfiguracja':'Configuration'}</h2></div><div class="panel-body"><div class="summary-list">${general.map(([key,value]) => item(key, h(value))).join('') || `<span class="text-secondary">${locale==='pl'?'Brak danych.':'No data.'}</span>`}</div><h3 class="h6 mt-4">${locale==='pl'?'Dyski i interfejsy':'Disks and interfaces'}</h3>${hardware.map(([key,value]) => `<div class="border-bottom py-2"><strong>${h(key)}</strong><div class="resource-meta text-break">${h(value)}</div></div>`).join('') || `<p class="text-secondary">${locale==='pl'?'Brak danych.':'No data.'}</p>`}</div></section><section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">Snapshoty</h2></div><div class="table-responsive"><table class="table"><thead><tr><th>${locale==='pl'?'Nazwa':'Name'}</th><th>${locale==='pl'?'Opis':'Description'}</th><th>${locale==='pl'?'Utworzono':'Created'}</th><th>Parent</th></tr></thead><tbody>${snapshots || `<tr><td colspan="4" class="text-secondary">${locale==='pl'?'Brak snapshotów.':'No snapshots.'}</td></tr>`}</tbody></table></div></section>`;
  }

  (async () => {
    try {
      const portal = relativePath.match(/^\/vms\/(\d+)$/);
      if (portal) return await renderPortal(Number(portal[1]));
      const live = relativePath.match(/^\/infrastructure\/vms\/(\d+)\/([^/]+)\/(\d+)$/);
      if (live) return await renderLive(Number(live[1]), decodeURIComponent(live[2]), Number(live[3]));
      throw new Error(locale === 'pl' ? 'Nieprawidłowy adres strony szczegółów VM.' : 'Invalid VM details page URL.');
    } catch (error) {
      pageTitle.textContent = locale === 'pl' ? 'Szczegóły VM' : 'VM details';
      content.innerHTML = `${back('/vms')}<div class="alert alert-danger"><h2 class="h5">${locale==='pl'?'Nie udało się pobrać szczegółów VM.':'Unable to load VM details.'}</h2><p class="mb-0">${h(error.message || error)}</p></div>`;
    }
  })();
})();
