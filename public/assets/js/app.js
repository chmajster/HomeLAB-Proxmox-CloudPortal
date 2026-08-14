(() => {
  'use strict';

  const body = document.body;
  const content = document.getElementById('appContent');
  if (!content) return;

  const page = body.dataset.page || 'dashboard';
  const basePath = body.dataset.basePath || '';
  const appUrl = path => `${basePath}${path}`;
  const csrf = body.dataset.csrf || '';
  const isAdmin = body.dataset.admin === '1';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const tr = {
    pl: {
      dashboard: 'Dashboard', vms: 'Maszyny wirtualne', 'create-vm': 'Utwórz maszynę', projects: 'Projekty', networks: 'Sieci', templates: 'Template', activity: 'Aktywność', users: 'Użytkownicy', infrastructure: 'Infrastruktura', proxmox: 'Połączenia Proxmox', storages: 'Storage', plans: 'Plany zasobów', quotas: 'Quota', audit: 'Audit log', settings: 'Ustawienia',
      error: 'Wystąpił błąd', empty: 'Brak danych do wyświetlenia.', loading: 'Ładowanie…', saved: 'Zapisano zmiany.', queued: 'Operacja została dodana do kolejki.', confirm: 'Czy na pewno chcesz wykonać tę operację?', cancel: 'Anuluj'
    },
    en: {
      dashboard: 'Dashboard', vms: 'Virtual machines', 'create-vm': 'Create virtual machine', projects: 'Projects', networks: 'Networks', templates: 'Templates', activity: 'Activity', users: 'Users', infrastructure: 'Infrastructure', proxmox: 'Proxmox connections', storages: 'Storage', plans: 'Resource plans', quotas: 'Quotas', audit: 'Audit log', settings: 'Settings',
      error: 'An error occurred', empty: 'No data to display.', loading: 'Loading…', saved: 'Changes saved.', queued: 'The operation has been queued.', confirm: 'Are you sure you want to perform this operation?', cancel: 'Cancel'
    }
  }[locale];

  const h = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const icon = (name, className = 'ui-icon') => `<svg class="${h(className)}" aria-hidden="true"><use href="${appUrl('/assets/icons.svg')}#i-${h(name)}"></use></svg>`;
  const fmt = value => new Intl.NumberFormat(locale === 'pl' ? 'pl-PL' : 'en-US').format(Number(value || 0));
  const formatBytes = value => { const bytes=Number(value||0);if(!bytes)return '—';const units=['B','KB','MB','GB','TB','PB'];const index=Math.min(Math.floor(Math.log(bytes)/Math.log(1024)),units.length-1);return `${new Intl.NumberFormat(locale==='pl'?'pl-PL':'en-US',{maximumFractionDigits:1}).format(bytes/(1024**index))} ${units[index]}`; };
  const date = value => value ? new Intl.DateTimeFormat(locale === 'pl' ? 'pl-PL' : 'en-US', {dateStyle:'medium', timeStyle:'short'}).format(new Date(String(value).replace(' ', 'T') + 'Z')) : '—';
  const unixDate = value => value ? new Intl.DateTimeFormat(locale === 'pl' ? 'pl-PL' : 'en-US', {dateStyle:'medium', timeStyle:'short'}).format(new Date(Number(value) * 1000)) : '—';
  const uptime = value => { const seconds=Number(value||0);if(!seconds)return '—';const days=Math.floor(seconds/86400),hours=Math.floor((seconds%86400)/3600),minutes=Math.floor((seconds%3600)/60);return [days?`${days}d`:'',hours?`${hours}h`:'',`${minutes}m`].filter(Boolean).join(' '); };
  const status = value => `<span class="status-badge status-${h(String(value || 'unknown').replace('running', 'running'))}">${h(value || 'unknown')}</span>`;

  async function api(url, options = {}) {
    const init = {...options, headers: {'Accept':'application/json', ...(options.body ? {'Content-Type':'application/json'} : {}), ...(['GET','HEAD'].includes(options.method || 'GET') ? {} : {'X-CSRF-Token':csrf}), ...(options.headers || {})}};
    if (options.body && typeof options.body !== 'string') init.body = JSON.stringify(options.body);
    const response = await fetch(url.startsWith('/') ? appUrl(url) : url, init);
    const type = response.headers.get('content-type') || '';
    if (!type.includes('application/json')) {
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return response;
    }
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  function toast(message, kind = 'success') {
    const container = document.getElementById('toastContainer');
    const element = document.createElement('div');
    element.className = `toast align-items-center text-bg-${kind} border-0`;
    element.setAttribute('role', 'status');
    element.innerHTML = `<div class="d-flex"><div class="toast-body">${h(message)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Zamknij"></button></div>`;
    container.append(element);
    const instance = new bootstrap.Toast(element, {delay: 5000});
    element.addEventListener('hidden.bs.toast', () => element.remove());
    instance.show();
  }

  function showError(error) {
    content.innerHTML = `<div class="alert alert-danger" role="alert"><h2 class="h5 d-flex align-items-center gap-2">${icon('alert-circle')}${h(tr.error)}</h2><p class="mb-0">${h(error.message || error)}</p></div>`;
    toast(error.message || String(error), 'danger');
  }

  let confirmCallback = null;
  function confirmAction(message, callback) {
    document.getElementById('confirmTitle').textContent = locale === 'pl' ? 'Potwierdź operację' : 'Confirm operation';
    document.getElementById('confirmMessage').textContent = message || tr.confirm;
    const button = document.getElementById('confirmAction');
    button.classList.remove('d-none');
    confirmCallback = callback;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmModal')).show();
  }
  document.getElementById('confirmAction').addEventListener('click', async () => {
    const callback = confirmCallback;
    confirmCallback = null;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmModal')).hide();
    if (callback) await callback();
  });

  const menuButton = document.getElementById('menuButton');
  const closeMenu = () => { body.classList.remove('menu-open'); menuButton?.setAttribute('aria-expanded', 'false'); };
  menuButton?.addEventListener('click', () => { const open = body.classList.toggle('menu-open'); menuButton.setAttribute('aria-expanded', String(open)); });
  document.getElementById('sidebarBackdrop')?.addEventListener('click', closeMenu);
  document.querySelectorAll('.portal-nav a').forEach(link => link.addEventListener('click', closeMenu));

  const themeButton = document.getElementById('themeButton');
  const storedTheme = localStorage.getItem('portal-theme');
  if (storedTheme === 'light' || storedTheme === 'dark') document.documentElement.dataset.bsTheme = storedTheme;
  themeButton?.addEventListener('click', () => {
    const next = document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.bsTheme = next;
    localStorage.setItem('portal-theme', next);
  });
  document.getElementById('logoutButton')?.addEventListener('click', async () => { await api('/api/v1/logout', {method:'POST'}); location.assign(appUrl('/login')); });
  document.querySelector('[data-dismiss-checklist]')?.addEventListener('click', () => document.getElementById('firstRunChecklist')?.remove());
  document.getElementById('pageTitle').textContent = tr[page] || page;

  const metric = (label, value, foot = '') => `<div class="metric-card"><div class="metric-label">${h(label)}</div><div class="metric-value">${h(value)}</div><div class="metric-foot">${h(foot)}</div></div>`;
  const usage = (label, used, max, unit = '') => { const safeMax=Math.max(0,Number(max||0)),safeUsed=Math.max(0,Number(used||0)); return `<div class="usage-row"><div class="usage-head"><span>${h(label)}</span><span>${h(fmt(safeUsed))}${h(unit)} / ${h(fmt(safeMax))}${h(unit)}</span></div><progress class="usage-progress" value="${Math.min(safeUsed,safeMax)}" max="${safeMax||1}">${safeMax?Math.round(safeUsed/safeMax*100):0}%</progress></div>`; };
  const empty = () => `<div class="empty-state">${icon('inbox')}<span>${h(tr.empty)}</span></div>`;

  async function dashboard() {
    const data = await api('/api/v1/dashboard');
    const s = data.summary || {};
    let cards = metric('VM', fmt(s.vms), `${fmt(s.running)} running · ${fmt(s.stopped)} stopped`) +
      metric('vCPU', fmt(s.vcpu), locale === 'pl' ? 'przydzielone' : 'allocated') +
      metric('RAM', `${fmt(Math.round((s.ram_mb || 0) / 1024))} GB`, locale === 'pl' ? 'przydzielona' : 'allocated') +
      metric('Storage', `${fmt(s.storage_gb)} GB`, locale === 'pl' ? 'przydzielony' : 'allocated');
    if (isAdmin && data.admin_counts) {
      cards += metric(locale === 'pl' ? 'Użytkownicy' : 'Users', fmt(data.admin_counts.users)) + metric(locale === 'pl' ? 'Projekty' : 'Projects', fmt(data.admin_counts.projects)) + metric('Proxmox', fmt(data.admin_counts.proxmox_connections), `${fmt(data.admin_counts.proxmox_nodes)} nodes`);
    }
    const vmRows = (data.recent_vms || []).map(vm => `<tr><td><a class="vm-name" href="${appUrl('/vms')}" data-vm-id="${vm.id}">${h(vm.name)}</a><div class="resource-meta">VMID ${h(vm.vmid)}</div></td><td>${status(vm.status)}</td><td>${fmt(vm.vcpu)} / ${fmt(Math.round(vm.ram_mb/1024))} GB / ${fmt(vm.disk_gb)} GB</td><td>${date(vm.created_at)}</td></tr>`).join('');
    const jobItems = (data.recent_jobs || []).map(job => `<li class="activity-item"><span class="activity-dot"></span><span><strong>${h(job.type)}</strong><small class="d-block text-secondary">${h(job.vm_name || job.public_id)}</small></span>${status(job.status)}</li>`).join('');
    let infra = '';
    if (isAdmin && data.infrastructure) {
      infra = `<div class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">Proxmox live</h2><a href="${appUrl('/infrastructure')}" class="btn btn-sm btn-outline-primary">${locale === 'pl' ? 'Szczegóły' : 'Details'}</a></div><div class="panel-body"><div class="row g-3">${data.infrastructure.map(cluster => {
        const nodes = cluster.resources.filter(r => r.type === 'node');
        const vms = cluster.resources.filter(r => r.type === 'qemu');
        const lxc = cluster.resources.filter(r => r.type === 'lxc');
        return `<div class="col-md-6"><div class="cluster-card"><div class="d-flex justify-content-between"><strong>${h(cluster.connection.name)}</strong>${cluster.error ? status('error') : status('active')}</div><div class="resource-meta mt-2">${nodes.length} nodes · ${vms.length} VM · ${lxc.length} LXC</div>${cluster.error ? `<div class="text-danger small mt-2">${h(cluster.error)}</div>` : ''}</div></div>`;
      }).join('')}</div></div></div>`;
      const taskRows=(data.proxmox_tasks||[]).map(task=>`<tr><td>${h(task.cluster)}</td><td>${h(task.type||'task')}</td><td>${h(task.node||'—')}</td><td>${status(task.status||task.exitstatus||'running')}</td></tr>`).join('');
      const errorRows=(data.recent_errors||[]).map(error=>`<tr><td>${date(error.created_at)}</td><td>${h(error.type)}</td><td class="text-danger">${h(error.error_message)}</td></tr>`).join('');
      infra += `<div class="content-grid"><section class="panel"><div class="panel-header"><h2 class="h5 mb-0">Proxmox tasks</h2></div><div class="table-responsive"><table class="table"><thead><tr><th>Cluster</th><th>Task</th><th>Node</th><th>Status</th></tr></thead><tbody>${taskRows||`<tr><td colspan="4">${empty()}</td></tr>`}</tbody></table></div></section><section class="panel"><div class="panel-header"><h2 class="h5 mb-0">${locale==='pl'?'Ostatnie błędy':'Recent errors'}</h2></div><div class="table-responsive"><table class="table"><tbody>${errorRows||`<tr><td>${empty()}</td></tr>`}</tbody></table></div></section></div>`;
    }
    let quotaPanel = '';
    if (!isAdmin && data.quota) quotaPanel = `<section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">Quota</h2></div><div class="panel-body">${usage('VM',s.vms,data.quota.max_vms)}${usage('vCPU',s.vcpu,data.quota.max_vcpu)}${usage('RAM',Math.round(s.ram_mb/1024),Math.round(data.quota.max_ram_mb/1024),' GB')}${usage('Storage',s.storage_gb,data.quota.max_storage_gb,' GB')}</div></section>`;
    if (isAdmin && data.admin_usage) { const u=data.admin_usage; quotaPanel=`<section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">Infrastructure utilization</h2></div><div class="panel-body">${usage('CPU',Math.round(u.cpu_used*10)/10,u.cpu_total,' cores')}${usage('RAM',Math.round(u.ram_used/1073741824),Math.round(u.ram_total/1073741824),' GB')}${usage('Storage',Math.round(u.storage_used/1073741824),Math.round(u.storage_total/1073741824),' GB')}</div></section>`; }
    content.innerHTML = `<div class="metric-grid">${cards}</div><div class="content-grid"><section class="panel"><div class="panel-header"><h2 class="h5 mb-0">${locale === 'pl' ? 'Ostatnie maszyny' : 'Recent machines'}</h2><a class="btn btn-sm btn-outline-primary" href="${appUrl('/vms')}">${locale === 'pl' ? 'Wszystkie' : 'View all'}</a></div><div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Status</th><th>Resources</th><th>Created</th></tr></thead><tbody>${vmRows || `<tr><td colspan="4">${empty()}</td></tr>`}</tbody></table></div></section><section class="panel"><div class="panel-header"><h2 class="h5 mb-0">${locale === 'pl' ? 'Ostatnie operacje' : 'Recent operations'}</h2></div><div class="panel-body"><ul class="activity-list">${jobItems || empty()}</ul></div></section></div>${quotaPanel}${infra}`;
  }

  const liveVmPath = vm => `/api/v1/admin/proxmox-vms/${Number(vm.connection_id)}/${encodeURIComponent(vm.node_name)}/${Number(vm.vmid)}`;

  async function adminVmList() {
    const inventory = await api('/api/v1/admin/vms/discovery');
    const vms = inventory.vms || [], connections = inventory.connections || [];
    const errors = connections.filter(connection => connection.error);
    const clusters = [...new Set(vms.map(vm => vm.connection_name).filter(Boolean))].sort();
    const total = vms.filter(vm => !vm.live_missing).length, running = vms.filter(vm => !vm.live_missing && vm.status === 'running').length, stopped = vms.filter(vm => !vm.live_missing && vm.status === 'stopped').length;
    const unmanaged = vms.filter(vm => !vm.portal_managed).length, missing = vms.filter(vm => vm.live_missing).length;
    const errorAlerts = errors.map(connection => `<div class="alert alert-danger" role="alert"><strong>${h(connection.name)}:</strong> ${h(connection.error)}</div>`).join('');
    content.innerHTML = `${errorAlerts}<div class="metric-grid">${metric('VM Proxmox',fmt(total),locale==='pl'?'pełny odczyt live':'full live inventory')}${metric(locale==='pl'?'Uruchomione':'Running',fmt(running))}${metric(locale==='pl'?'Zatrzymane':'Stopped',fmt(stopped))}${metric(locale==='pl'?'Poza portalem':'Unmanaged',fmt(unmanaged))}${metric(locale==='pl'?'Brak w Proxmox':'Missing',fmt(missing))}</div>
      <section class="panel mt-3"><div class="panel-header"><div><h2 class="h5 mb-1">${locale==='pl'?'Maszyny wirtualne Proxmox':'Proxmox virtual machines'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Lista pochodzi bezpośrednio z klastrów; powiązania projektu i właściciela pochodzą z portalu.':'The inventory comes directly from clusters; project and owner assignments come from the portal.'}</p></div><div class="actions"><button class="btn btn-outline-primary" id="refreshProxmoxVms" type="button">${icon('refresh')}${locale==='pl'?'Odśwież z Proxmox':'Refresh from Proxmox'}</button><a href="${appUrl('/create-vm')}" class="btn btn-primary">${icon('plus')}${locale==='pl'?'Utwórz VM':'Create VM'}</a></div></div>
      <div class="panel-body border-bottom"><div class="row g-2"><div class="col-12 col-lg-5"><label class="visually-hidden" for="vmSearch">${locale==='pl'?'Szukaj VM':'Search VMs'}</label><input class="form-control" id="vmSearch" type="search" placeholder="${locale==='pl'?'Szukaj po nazwie, VMID, właścicielu…':'Search name, VMID, owner…'}"></div><div class="col-6 col-lg"><label class="visually-hidden" for="vmClusterFilter">Proxmox</label><select class="form-select" id="vmClusterFilter"><option value="">${locale==='pl'?'Wszystkie klastry':'All clusters'}</option>${clusters.map(cluster=>`<option value="${h(cluster)}">${h(cluster)}</option>`).join('')}</select></div><div class="col-6 col-lg"><label class="visually-hidden" for="vmStatusFilter">Status</label><select class="form-select" id="vmStatusFilter"><option value="">${locale==='pl'?'Każdy status':'Any status'}</option><option value="running">Running</option><option value="stopped">Stopped</option><option value="paused">Paused</option><option value="missing">${locale==='pl'?'Brak w Proxmox':'Missing'}</option></select></div><div class="col-12 col-lg"><label class="visually-hidden" for="vmManagementFilter">Portal</label><select class="form-select" id="vmManagementFilter"><option value="">${locale==='pl'?'Wszystkie maszyny':'All machines'}</option><option value="managed">${locale==='pl'?'Zarządzane przez portal':'Portal managed'}</option><option value="unmanaged">${locale==='pl'?'Poza portalem':'Outside portal'}</option></select></div></div></div>
      <div class="table-responsive"><table class="table"><thead><tr><th>VM</th><th>Proxmox / ${locale==='pl'?'węzeł':'node'}</th><th>Portal</th><th>Status</th><th>CPU</th><th>RAM</th><th>${locale==='pl'?'Dysk':'Disk'}</th><th>${locale==='pl'?'Akcje':'Actions'}</th></tr></thead><tbody id="adminVmRows"></tbody></table></div></section>`;

    const renderRows = () => {
      const query = document.getElementById('vmSearch').value.trim().toLowerCase();
      const cluster = document.getElementById('vmClusterFilter').value;
      const selectedStatus = document.getElementById('vmStatusFilter').value;
      const management = document.getElementById('vmManagementFilter').value;
      const filtered = vms.map((vm,index)=>({...vm,_index:index})).filter(vm => {
        const haystack = [vm.name,vm.vmid,vm.connection_name,vm.node_name,vm.project_name,vm.owner_name,vm.ip_address,vm.tags].join(' ').toLowerCase();
        if (query && !haystack.includes(query)) return false;
        if (cluster && vm.connection_name !== cluster) return false;
        if (selectedStatus === 'missing' ? !vm.live_missing : selectedStatus && vm.status !== selectedStatus) return false;
        if (management === 'managed' && !vm.portal_managed) return false;
        if (management === 'unmanaged' && vm.portal_managed) return false;
        return true;
      });
      document.getElementById('adminVmRows').innerHTML = filtered.map(vm => {
        const portal = vm.portal_managed ? `<strong>${h(vm.project_name||'—')}</strong><div class="resource-meta">${h(vm.owner_name||'—')}${vm.ip_address?` · ${h(vm.ip_address)}`:''}</div>` : `<span class="status-badge status-unknown">${locale==='pl'?'poza portalem':'unmanaged'}</span>`;
        const liveStatus = vm.live_missing ? 'error' : vm.status;
        const cpu = vm.cpu_usage===null?'—':`${(Number(vm.cpu_usage)*100).toFixed(1)}%`;
        const cpuMeta = vm.cpu_count?`${h(vm.cpu_count)} vCPU`:'';
        const ram = vm.memory_total?`${formatBytes(vm.memory_used)} / ${formatBytes(vm.memory_total)}`:'—';
        const disk = vm.disk_total?`${formatBytes(vm.disk_used)} / ${formatBytes(vm.disk_total)}`:'—';
        const details = vm.live_missing ? '' : `<button class="btn btn-sm btn-outline-secondary" data-live-vm="${vm._index}" data-live-action="details">${icon('eye')}${locale==='pl'?'Szczegóły':'Details'}</button>`;
        let actions = details;
        if (!vm.live_missing) {
          if (vm.status !== 'running' && vm.status !== 'paused') actions += `<button class="btn btn-sm btn-outline-success" data-live-vm="${vm._index}" data-live-action="start">${icon('play')}Start</button>`;
          if (vm.status === 'running') actions += `<button class="btn btn-sm btn-outline-secondary" data-live-vm="${vm._index}" data-live-action="shutdown">${icon('power')}Shutdown</button><button class="btn btn-sm btn-outline-danger" data-live-vm="${vm._index}" data-live-action="stop">${icon('stop')}Stop</button><button class="btn btn-sm btn-outline-primary" data-live-vm="${vm._index}" data-live-action="reboot">${icon('refresh')}Reboot</button><button class="btn btn-sm btn-outline-secondary" data-live-vm="${vm._index}" data-live-action="suspend">${icon('power')}Suspend</button>`;
          if (vm.status === 'paused') actions += `<button class="btn btn-sm btn-outline-success" data-live-vm="${vm._index}" data-live-action="resume">${icon('play')}Resume</button><button class="btn btn-sm btn-outline-danger" data-live-vm="${vm._index}" data-live-action="stop">${icon('stop')}Stop</button>`;
          actions += `<button class="btn btn-sm btn-outline-primary" data-live-vm="${vm._index}" data-live-action="console">${icon('terminal')}Console</button><button class="btn btn-sm btn-outline-secondary" data-live-vm="${vm._index}" data-live-action="snapshot">${icon('camera')}Snapshot</button>`;
          if (vm.portal_managed) actions += `<button class="btn btn-sm btn-outline-secondary" data-live-vm="${vm._index}" data-live-action="resize">${icon('maximize')}Resize</button><button class="btn btn-sm btn-outline-secondary" data-live-vm="${vm._index}" data-live-action="assign">${icon('user-plus')}Assign</button><button class="btn btn-sm btn-outline-danger" data-live-vm="${vm._index}" data-live-action="delete">${icon('trash')}Delete</button>`;
        }
        return `<tr><td><strong>${h(vm.name||`VM ${vm.vmid}`)}</strong><div class="resource-meta">VMID ${h(vm.vmid)}${vm.tags?` · ${h(vm.tags)}`:''}</div></td><td>${h(vm.connection_name)}<div class="resource-meta">${h(vm.node_name)}</div></td><td>${portal}</td><td>${status(liveStatus)}${vm.live_missing?`<div class="resource-meta text-danger">${locale==='pl'?'Nie znaleziono w Proxmox':'Not found in Proxmox'}</div>`:vm.lock?`<div class="resource-meta">lock: ${h(vm.lock)}</div>`:`<div class="resource-meta">uptime: ${uptime(vm.uptime)}</div>`}</td><td>${h(cpu)}<div class="resource-meta">${cpuMeta}</div></td><td>${ram}</td><td>${disk}</td><td><div class="actions">${actions||'—'}</div></td></tr>`;
      }).join('') || `<tr><td colspan="8">${empty()}</td></tr>`;
    };
    renderRows();
    ['vmSearch','vmClusterFilter','vmStatusFilter','vmManagementFilter'].forEach(id => document.getElementById(id).addEventListener(id==='vmSearch'?'input':'change',renderRows));
    document.getElementById('refreshProxmoxVms').addEventListener('click', async event => { event.currentTarget.disabled=true;try{await adminVmList();}catch(error){event.currentTarget.disabled=false;toast(error.message,'danger');} });
    content.onclick = async event => {
      const button=event.target.closest('[data-live-action]');if(!button)return;
      const vm=vms[Number(button.dataset.liveVm)],action=button.dataset.liveAction;if(!vm)return;
      await adminVmAction(vm,action,button);
    };
  }

  async function adminVmAction(vm, action, button) {
    if (action === 'details') return adminVmDetails(vm);
    if (action === 'console') return vm.portal_managed ? openConsole(Number(vm.portal_id)) : openLiveConsole(vm);
    if (action === 'snapshot') {
      const name=prompt(locale==='pl'?'Nazwa snapshotu:':'Snapshot name:');if(!name)return;
      if(vm.portal_managed)return queue(`/api/v1/vms/${Number(vm.portal_id)}/snapshots`,'POST',{name});
      return directLiveVmOperation(vm,'snapshots','POST',{name});
    }
    if (action === 'resize') { const plan=prompt(locale==='pl'?'ID większego planu zasobów:':'Larger resource plan ID:');if(plan)return queue(`/api/v1/vms/${Number(vm.portal_id)}/resize`,'POST',{plan_id:Number(plan)});return; }
    if (action === 'assign') { const projectId=prompt('Target project ID:');if(!projectId)return;const ownerId=prompt('Target owner user ID:');if(!ownerId)return;return confirmAction(tr.confirm,async()=>{try{await api(`/api/v1/vms/${Number(vm.portal_id)}/assignment`,{method:'PATCH',body:{project_id:Number(projectId),owner_user_id:Number(ownerId)}});toast(tr.saved);adminVmList();}catch(error){toast(error.message,'danger');}}); }
    if (action === 'delete') return confirmAction(`${tr.confirm} (delete)`,()=>queue(`/api/v1/vms/${Number(vm.portal_id)}`,'DELETE'));
    const destructive=['stop'].includes(action);
    const run=()=>vm.portal_managed?queue(`/api/v1/vms/${Number(vm.portal_id)}/${action}`,'POST'):directLiveVmOperation(vm,`status/${action}`,'POST');
    if(destructive)return confirmAction(`${tr.confirm} (${action})`,run);
    button.disabled=true;try{return await run();}finally{if(document.body.contains(button))button.disabled=false;}
  }

  async function directLiveVmOperation(vm, suffix, method, payload) {
    try { await api(`${liveVmPath(vm)}/${suffix}`,{method,...(payload?{body:payload}:{})});toast(tr.queued);window.setTimeout(()=>{if(page==='vms')adminVmList().catch(showError);},1800); }
    catch(error){toast(error.message,'danger');}
  }

  async function adminVmDetails(vm) {
    try {
      const data=await api(liveVmPath(vm));
      const current=data.status||{},config=data.config||{},snapshots=data.snapshots||[];
      const hardware=Object.entries(config).filter(([key])=>/^(?:scsi|sata|ide|virtio|net)\d+$/.test(key));
      const general=Object.entries(config).filter(([key])=>!['name','description'].includes(key)&&!/^(?:scsi|sata|ide|virtio|net)\d+$/.test(key));
      const portal=data.portal?`<div class="summary-item"><small>Portal</small>${h(data.portal.project_name)} · ${h(data.portal.owner_name)}${data.portal.ip_address?` · ${h(data.portal.ip_address)}`:''}</div>`:`<div class="summary-item"><small>Portal</small>${locale==='pl'?'Maszyna niezarządzana':'Unmanaged VM'}</div>`;
      const snapshotRows=snapshots.map(snapshot=>{let remove='';if(!vm.portal_managed)remove=`<button class="btn btn-sm btn-outline-danger" data-live-delete-snapshot="${h(snapshot.name)}">${icon('trash')}Delete</button>`;else if(snapshot.portal_snapshot_id)remove=`<button class="btn btn-sm btn-outline-danger" data-portal-delete-snapshot="${h(snapshot.portal_snapshot_id)}">${icon('trash')}Delete</button>`;return `<div class="d-flex justify-content-between align-items-center gap-3 border-bottom py-2"><span><strong>${h(snapshot.name)}</strong><small class="d-block text-secondary">${unixDate(snapshot.snaptime)}${snapshot.description?` · ${h(snapshot.description)}`:''}${vm.portal_managed&&!snapshot.portal_snapshot_id?` · ${locale==='pl'?'spoza portalu':'external'}`:''}</small></span>${remove}</div>`;}).join('')||`<p class="text-secondary">${tr.empty}</p>`;
      const modal=document.getElementById('confirmModal');document.getElementById('confirmTitle').textContent=config.name||vm.name||`VM ${vm.vmid}`;document.getElementById('confirmMessage').innerHTML=`<div class="summary-list"><div class="summary-item"><small>VMID</small>${h(vm.vmid)}</div><div class="summary-item"><small>Status</small>${status(current.status||vm.status)}</div><div class="summary-item"><small>Proxmox</small>${h(vm.connection_name)} · ${h(vm.node_name)}</div>${portal}<div class="summary-item"><small>CPU</small>${h(current.cpus||config.cores||'—')} vCPU · ${current.cpu===undefined?'—':`${(Number(current.cpu)*100).toFixed(1)}%`}</div><div class="summary-item"><small>RAM</small>${formatBytes(current.mem)} / ${formatBytes(current.maxmem||Number(config.memory||0)*1024*1024)}</div><div class="summary-item"><small>Uptime</small>${uptime(current.uptime)}</div></div>${config.description?`<p class="mt-3 mb-0">${h(config.description)}</p>`:''}<h3 class="h6 mt-4">${locale==='pl'?'Konfiguracja':'Configuration'}</h3><div class="summary-list">${general.map(([key,value])=>`<div class="summary-item"><small>${h(key)}</small>${h(value)}</div>`).join('')||`<div class="text-secondary">${tr.empty}</div>`}</div><h3 class="h6 mt-4">${locale==='pl'?'Dyski i interfejsy':'Disks and interfaces'}</h3>${hardware.map(([key,value])=>`<div class="border-bottom py-2"><strong>${h(key)}</strong><div class="resource-meta text-break">${h(value)}</div></div>`).join('')||`<p class="text-secondary">${tr.empty}</p>`}<h3 class="h6 mt-4">Snapshoty</h3>${snapshotRows}`;document.getElementById('confirmAction').classList.add('d-none');bootstrap.Modal.getOrCreateInstance(modal).show();
      document.getElementById('confirmMessage').querySelectorAll('[data-live-delete-snapshot]').forEach(remove=>remove.addEventListener('click',()=>{bootstrap.Modal.getOrCreateInstance(modal).hide();confirmAction(`${tr.confirm} (${remove.dataset.liveDeleteSnapshot})`,()=>directLiveVmOperation(vm,`snapshots/${encodeURIComponent(remove.dataset.liveDeleteSnapshot)}`,'DELETE'));}));
      document.getElementById('confirmMessage').querySelectorAll('[data-portal-delete-snapshot]').forEach(remove=>remove.addEventListener('click',()=>{bootstrap.Modal.getOrCreateInstance(modal).hide();confirmAction(tr.confirm,()=>queue(`/api/v1/vms/${Number(vm.portal_id)}/snapshots/${Number(remove.dataset.portalDeleteSnapshot)}`,'DELETE'));}));
    } catch(error){toast(error.message,'danger');}
  }

  async function openLiveConsole(vm) {
    try { const response=await api(`${liveVmPath(vm)}/console`,{method:'POST'});const blob=await response.blob();const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download=`proxmox-vm-${vm.vmid}.vv`;link.click();URL.revokeObjectURL(url);toast(locale==='pl'?'Pobrano konfigurację konsoli SPICE.':'SPICE console configuration downloaded.'); }
    catch(error){toast(error.message,'danger');}
  }

  async function vmList() {
    if (isAdmin) return adminVmList();
    const vms = await api('/api/v1/vms');
    const rows = vms.map(vm => `<tr><td><button class="btn btn-link p-0 vm-name text-start" data-action="details" data-id="${vm.id}">${h(vm.name)}</button><div class="resource-meta">VMID ${h(vm.vmid)}</div></td>${isAdmin ? `<td>${h(vm.owner_name)}</td><td>${h(vm.project_name)}</td>` : ''}<td>${h(vm.node_name)}</td><td>${status(vm.status)}</td><td>${fmt(vm.vcpu)}</td><td>${fmt(Math.round(vm.ram_mb/1024))} GB</td><td>${fmt(vm.disk_gb)} GB</td><td>${h(vm.ip_address || '—')}</td><td><div class="actions"><button class="btn btn-outline-success" data-action="start" data-id="${vm.id}">${icon('play')}Start</button><button class="btn btn-outline-secondary" data-action="shutdown" data-id="${vm.id}">${icon('power')}Shutdown</button><button class="btn btn-outline-danger" data-action="stop" data-id="${vm.id}">${icon('stop')}Stop</button><button class="btn btn-outline-primary" data-action="reboot" data-id="${vm.id}">${icon('refresh')}Reboot</button><button class="btn btn-outline-primary" data-action="console" data-id="${vm.id}">${icon('terminal')}Console</button><button class="btn btn-outline-secondary" data-action="snapshot" data-id="${vm.id}">${icon('camera')}Snapshot</button><button class="btn btn-outline-secondary" data-action="resize" data-id="${vm.id}">${icon('maximize')}Resize</button>${isAdmin ? `<button class="btn btn-outline-secondary" data-action="assign" data-id="${vm.id}">${icon('user-plus')}Assign</button>` : ''}<button class="btn btn-outline-danger" data-action="delete" data-id="${vm.id}">${icon('trash')}Delete</button></div></td></tr>`).join('');
    content.innerHTML = `<section class="panel"><div class="panel-header"><div><h2 class="h5 mb-1">${tr.vms}</h2><p class="text-secondary small mb-0">${locale === 'pl' ? 'Stan jest synchronizowany z API Proxmox.' : 'Status is synchronized with the Proxmox API.'}</p></div><a href="${appUrl('/create-vm')}" class="btn btn-primary">${icon('plus')}${locale === 'pl' ? 'Utwórz VM' : 'Create VM'}</a></div><div class="table-responsive"><table class="table"><thead><tr><th>Name</th>${isAdmin ? '<th>Owner</th><th>Project</th>' : ''}<th>Node</th><th>Status</th><th>CPU</th><th>RAM</th><th>Disk</th><th>IP</th><th>Actions</th></tr></thead><tbody>${rows || `<tr><td colspan="11">${empty()}</td></tr>`}</tbody></table></div></section>`;
    content.addEventListener('click', vmAction);
  }

  async function vmAction(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const id = Number(button.dataset.id);
    const action = button.dataset.action;
    if (action === 'details') return vmDetails(id);
    if (action === 'console') return openConsole(id);
    if (action === 'snapshot') {
      const name = prompt(locale === 'pl' ? 'Nazwa snapshotu:' : 'Snapshot name:');
      if (name) queue(`/api/v1/vms/${id}/snapshots`, 'POST', {name});
      return;
    }
    if (action === 'resize') {
      const plan = prompt(locale === 'pl' ? 'ID większego planu zasobów:' : 'Larger resource plan ID:');
      if (plan) queue(`/api/v1/vms/${id}/resize`, 'POST', {plan_id:Number(plan)});
      return;
    }
    if (action === 'assign') {
      const projectId = prompt('Target project ID:'); if (!projectId) return;
      const ownerId = prompt('Target owner user ID:'); if (!ownerId) return;
      confirmAction(tr.confirm, async () => { try { await api(`/api/v1/vms/${id}/assignment`, {method:'PATCH', body:{project_id:Number(projectId), owner_user_id:Number(ownerId)}}); toast(tr.saved); vmList(); } catch(error) { toast(error.message,'danger'); } });
      return;
    }
    const destructive = ['stop','delete'].includes(action);
    const run = () => queue(`/api/v1/vms/${id}${action === 'delete' ? '' : '/' + action}`, action === 'delete' ? 'DELETE' : 'POST');
    if (destructive) confirmAction(`${tr.confirm} (${action})`, run); else run();
  }

  async function queue(url, method, payload) {
    try {
      const result = await api(url, {method, ...(payload ? {body:payload} : {})});
      toast(tr.queued);
      if (result.job_id) pollJob(result.job_id);
    } catch (error) { toast(error.message, 'danger'); }
  }

  async function pollJob(jobId) {
    let attempts = 0;
    const poll = async () => {
      try {
        const job = await api(`/api/v1/jobs/${encodeURIComponent(jobId)}`);
        if (job.status === 'completed') { toast(`${job.type}: completed`); if (page === 'vms') vmList(); return; }
        if (job.status === 'failed') { toast(job.error_message || `${job.type}: failed`, 'danger'); if (page === 'vms') vmList(); return; }
        if (++attempts < 180) setTimeout(poll, 2000);
      } catch (error) { if (++attempts < 10) setTimeout(poll, 3000); }
    };
    setTimeout(poll, 1000);
  }

  async function vmDetails(id) {
    try {
      const data = await api(`/api/v1/vms/${id}`);
      const vm = data.vm;
      document.getElementById('confirmTitle').textContent = vm.name;
      document.getElementById('confirmMessage').innerHTML = `<div class="summary-list"><div class="summary-item"><small>VMID</small>${h(vm.vmid)}</div><div class="summary-item"><small>Status</small>${status(vm.status)}</div><div class="summary-item"><small>Project</small>${h(vm.project_name)}</div><div class="summary-item"><small>Owner</small>${h(vm.owner_name)}</div><div class="summary-item"><small>Resources</small>${fmt(vm.vcpu)} vCPU · ${fmt(Math.round(vm.ram_mb/1024))} GB · ${fmt(vm.disk_gb)} GB</div><div class="summary-item"><small>IP</small>${h(vm.ip_address || '—')}</div></div><h3 class="h6 mt-4">Snapshots</h3>${data.snapshots.length ? data.snapshots.map(s => `<div class="d-flex justify-content-between align-items-center border-bottom py-2"><span>${h(s.name)} ${status(s.status)}</span><button class="btn btn-sm btn-outline-danger" data-delete-snapshot="${s.id}" data-vm="${vm.id}">${icon('trash')}Delete</button></div>`).join('') : `<p class="text-secondary">${tr.empty}</p>`}<h3 class="h6 mt-4">Jobs</h3>${data.jobs.slice(0,5).map(j => `<div class="d-flex justify-content-between border-bottom py-2"><span>${h(j.type)}</span>${status(j.status)}</div>`).join('') || `<p class="text-secondary">${tr.empty}</p>`}`;
      document.getElementById('confirmAction').classList.add('d-none');
      bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmModal')).show();
      document.getElementById('confirmMessage').querySelectorAll('[data-delete-snapshot]').forEach(button => button.addEventListener('click', () => {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmModal')).hide();
        confirmAction(tr.confirm, () => queue(`/api/v1/vms/${button.dataset.vm}/snapshots/${button.dataset.deleteSnapshot}`, 'DELETE'));
      }));
    } catch (error) { toast(error.message, 'danger'); }
  }

  async function openConsole(id) {
    try {
      const response = await api(`/api/v1/vms/${id}/console`, {method:'POST'});
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a'); link.href = url; link.download = 'console.vv'; link.click(); URL.revokeObjectURL(url);
      toast(locale === 'pl' ? 'Pobrano konfigurację konsoli SPICE.' : 'SPICE console configuration downloaded.');
    } catch (error) { toast(error.message, 'danger'); }
  }

  async function createVmWizard() {
    const catalog = await api('/api/v1/catalog');
    if (!catalog.projects.length) { content.innerHTML = `<div class="alert alert-warning">${locale === 'pl' ? 'Nie należysz do aktywnego projektu. Administrator musi przypisać projekt i quota.' : 'You are not assigned to an active project. An administrator must assign a project and quota.'}</div>`; return; }
    content.innerHTML = `<div class="wizard-shell"><div class="wizard-steps" aria-label="Wizard progress">${Array.from({length:12},(_,i)=>`<span class="wizard-step ${i===0?'active':''}"></span>`).join('')}</div><form class="panel" id="vmWizard"><div class="panel-body p-4 p-md-5">
      ${wizardPage(1,'Nazwa i projekt',`<div class="mb-3"><label class="form-label" for="vmName">Nazwa VM</label><input class="form-control form-control-lg" id="vmName" name="name" pattern="[A-Za-z0-9][A-Za-z0-9-]{1,62}" required></div><div><label class="form-label" for="projectId">Projekt</label><select class="form-select form-select-lg" id="projectId" name="project_id" required>${catalog.projects.map(p=>`<option value="${p.id}">${h(p.name)}</option>`).join('')}</select></div>`)}
      ${wizardPage(2,'System / template','<div id="templateChoices" class="plan-grid"></div>')}
      ${wizardPage(3,'CPU','<div id="planChoices" class="plan-grid"></div>')}
      ${wizardPage(4,'RAM','<div id="ramPreview"></div>')}
      ${wizardPage(5,'Dysk','<div id="diskPreview"></div>')}
      ${wizardPage(6,'Storage','<div id="storageChoices" class="plan-grid"></div>')}
      ${wizardPage(7,'Sieć','<div id="networkChoices" class="plan-grid"></div>')}
      ${wizardPage(8,'VLAN','<div id="vlanPreview"></div>')}
      ${wizardPage(9,'Cloud-init','<label class="form-label" for="cloudUser">Użytkownik systemu</label><input class="form-control" id="cloudUser" name="cloud_init_user" value="clouduser" pattern="[a-z_][a-z0-9_-]{0,31}" required>')}
      ${wizardPage(10,'Klucz SSH','<label class="form-label" for="sshKey">Publiczny klucz SSH</label><textarea class="form-control" id="sshKey" name="ssh_public_key" rows="5" required></textarea><div class="form-text">ssh-ed25519, ssh-rsa lub ECDSA</div>')}
      ${wizardPage(11,'Podsumowanie','<div id="wizardSummary" class="summary-list"></div><div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="startAfter" name="start_after_create" checked><label class="form-check-label" for="startAfter">Uruchom VM po utworzeniu</label></div>')}
      ${wizardPage(12,'Utwórz',`<div class="text-center py-4"><div class="wizard-success-icon mb-3">${icon('check')}</div><h3>Gotowe do provisioningu</h3><p class="text-secondary">Quota i adres IP zostaną zarezerwowane atomowo. Postęp będzie śledzony przez job Proxmox.</p></div>`)}
      </div><div class="panel-header"><button type="button" class="btn btn-outline-secondary" id="wizardBack" disabled>${icon('arrow-left')}Wstecz</button><span class="text-secondary small" id="wizardCounter">1 / 12</span><button type="button" class="btn btn-primary" id="wizardNext">Dalej${icon('arrow-right')}</button></div></form></div>`;
    const state = {step:1, catalog};
    const form = document.getElementById('vmWizard');
    const loadProject = async () => { state.catalog = await api(`/api/v1/catalog?project_id=${encodeURIComponent(form.elements.project_id.value)}`); renderChoices(state); };
    form.elements.project_id.addEventListener('change', loadProject);
    await loadProject();
    document.getElementById('wizardBack').addEventListener('click', () => changeWizard(state, -1));
    document.getElementById('wizardNext').addEventListener('click', async () => {
      if (!validateWizardStep(state.step, form)) return;
      if (state.step === 11) buildSummary(form, state.catalog);
      if (state.step < 12) return changeWizard(state, 1);
      const raw = Object.fromEntries(new FormData(form));
      raw.project_id = Number(raw.project_id); raw.template_id = Number(raw.template_id); raw.plan_id = Number(raw.plan_id); raw.storage_id = Number(raw.storage_id); raw.network_id = Number(raw.network_id); raw.start_after_create = form.elements.start_after_create.checked;
      const button = document.getElementById('wizardNext'); button.disabled = true; button.innerHTML = tr.loading;
      try { const result = await api('/api/v1/vms', {method:'POST', body:raw}); toast(tr.queued); pollJob(result.job_id); location.assign(appUrl('/activity')); }
      catch (error) { button.disabled=false; button.innerHTML=`Create${icon('arrow-right')}`; toast(error.message,'danger'); }
    });
  }

  function wizardPage(number, title, inner) { return `<section class="wizard-page ${number===1?'active':''}" data-step="${number}"><p class="eyebrow">Krok ${number} / 12</p><h2 class="h3 mb-4">${h(title)}</h2>${inner}</section>`; }
  function renderChoices(state) {
    const c = state.catalog;
    document.getElementById('templateChoices').innerHTML = c.templates.map((x,i)=>selectCard('template_id',x.id,x.name,x.operating_system||'',i===0)).join('') || empty();
    document.getElementById('planChoices').innerHTML = c.plans.map((x,i)=>selectCard('plan_id',x.id,x.name,`${x.vcpu} vCPU · ${Math.round(x.ram_mb/1024)} GB · ${x.disk_gb} GB`,i===0)).join('') || empty();
    const renderDependent = () => {
      const template=c.templates.find(x=>String(x.id)===document.querySelector('[name="template_id"]:checked')?.value);
      const compatible=x=>template&&Number(x.connection_id)===Number(template.connection_id)&&(!x.node_name||x.node_name===template.node_name);
      const storages=c.storages.filter(compatible),networks=c.networks.filter(compatible);
      document.getElementById('storageChoices').innerHTML = storages.map((x,i)=>selectCard('storage_id',x.id,x.storage_name,x.node_name||'cluster',i===0)).join('') || empty();
      document.getElementById('networkChoices').innerHTML = networks.map((x,i)=>selectCard('network_id',x.id,x.name,`${x.bridge} · ${x.subnet}`,i===0)).join('') || empty();
    };
    document.querySelectorAll('[name="template_id"]').forEach(input=>input.addEventListener('change',renderDependent));
    renderDependent();
  }
  function selectCard(name,id,title,meta,checked) { return `<label class="select-card"><input type="radio" name="${name}" value="${id}" ${checked?'checked':''} required><strong class="d-block">${h(title)}</strong><span class="resource-meta">${h(meta)}</span></label>`; }
  function validateWizardStep(step, form) {
    const section = form.querySelector(`[data-step="${step}"]`);
    for (const input of section.querySelectorAll('input,select,textarea')) if (!input.checkValidity()) { input.reportValidity(); return false; }
    const requiredByStep = {2:'template_id',3:'plan_id',6:'storage_id',7:'network_id'}[step];
    if (requiredByStep && !form.querySelector(`[name="${requiredByStep}"]:checked`)) { toast(locale === 'pl' ? 'Wybierz jedną opcję.' : 'Select an option.', 'warning'); return false; }
    return true;
  }
  function changeWizard(state, delta) {
    state.step = Math.max(1, Math.min(12, state.step + delta));
    document.querySelectorAll('.wizard-page').forEach(x=>x.classList.toggle('active',Number(x.dataset.step)===state.step));
    document.querySelectorAll('.wizard-step').forEach((x,i)=>{x.classList.toggle('active',i+1===state.step);x.classList.toggle('done',i+1<state.step);});
    document.getElementById('wizardBack').disabled = state.step === 1;
    document.getElementById('wizardNext').innerHTML = `${state.step === 12 ? 'Create' : 'Dalej'}${icon('arrow-right')}`;
    document.getElementById('wizardCounter').textContent = `${state.step} / 12`;
    const form = document.getElementById('vmWizard');
    const plan = state.catalog.plans.find(x=>String(x.id)===form.elements.plan_id?.value);
    const network = state.catalog.networks.find(x=>String(x.id)===form.elements.network_id?.value);
    if (state.step===4) document.getElementById('ramPreview').innerHTML = plan ? metric('RAM',`${Math.round(plan.ram_mb/1024)} GB`,plan.name) : empty();
    if (state.step===5) document.getElementById('diskPreview').innerHTML = plan ? metric('Disk',`${plan.disk_gb} GB`,plan.name) : empty();
    if (state.step===8) document.getElementById('vlanPreview').innerHTML = network ? metric('VLAN',network.vlan_id||'untagged',`${network.bridge} · ${network.subnet}`) : empty();
    if (state.step===11) buildSummary(form,state.catalog);
  }
  function buildSummary(form,c) {
    const plan=c.plans.find(x=>String(x.id)===form.elements.plan_id.value), template=c.templates.find(x=>String(x.id)===form.elements.template_id.value), network=c.networks.find(x=>String(x.id)===form.elements.network_id.value), storage=c.storages.find(x=>String(x.id)===form.elements.storage_id.value);
    const items=[['Name',form.elements.name.value],['Project',form.elements.project_id.selectedOptions[0]?.textContent],['Template',template?.name],['Plan',plan?`${plan.name}: ${plan.vcpu} vCPU / ${Math.round(plan.ram_mb/1024)} GB / ${plan.disk_gb} GB`:''],['Storage',storage?.storage_name],['Network',network?`${network.name} / VLAN ${network.vlan_id||'untagged'}`:''],['Cloud-init',form.elements.cloud_init_user.value],['SSH',form.elements.ssh_public_key.value.split(' ').slice(0,2).join(' ')]];
    document.getElementById('wizardSummary').innerHTML=items.map(([k,v])=>`<div class="summary-item"><small>${h(k)}</small>${h(v||'—')}</div>`).join('');
  }

  async function simpleResource(resource) {
    const data = await api(`/api/v1/${resource}`);
    const keys = data.length ? Object.keys(data[0]).filter(k=>!['description'].includes(k)) : [];
    content.innerHTML = `<section class="panel"><div class="panel-header"><h2 class="h5 mb-0">${h(tr[resource]||resource)}</h2></div>${data.length ? `<div class="table-responsive"><table class="table"><thead><tr>${keys.map(k=>`<th>${h(k.replaceAll('_',' '))}</th>`).join('')}</tr></thead><tbody>${data.map(row=>`<tr>${keys.map(k=>`<td>${k==='status'?status(row[k]):h(row[k]??'—')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : empty()}</section>`;
  }

  async function activity() {
    const jobs = await api('/api/v1/jobs');
    content.innerHTML = `<section class="panel"><div class="panel-header"><h2 class="h5 mb-0">${tr.activity}</h2><button class="btn btn-sm btn-outline-primary" id="refreshJobs">${icon('refresh')}Refresh</button></div><div class="table-responsive"><table class="table"><thead><tr><th>Time</th><th>Operation</th><th>VM</th><th>Status</th><th>Error</th></tr></thead><tbody>${jobs.map(j=>`<tr><td>${date(j.created_at)}</td><td>${h(j.type)}<div class="resource-meta">${h(j.public_id)}</div></td><td>${h(j.virtual_machine_id||'—')}</td><td>${status(j.status)}</td><td class="text-danger">${h(j.error_message||'')}</td></tr>`).join('')||`<tr><td colspan="5">${empty()}</td></tr>`}</tbody></table></div></section>`;
    document.getElementById('refreshJobs').addEventListener('click', activity);
    if (jobs.some(j=>['queued','running'].includes(j.status))) setTimeout(()=>{if(page==='activity')activity();},3000);
  }

  function normalizeIpv4Cidr(value) {
    const match = String(value || '').match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\/(\d{1,2})$/);
    if (!match) return '';
    const octets = match.slice(1, 5).map(Number), prefix = Number(match[5]);
    if (octets.some(part => part > 255) || prefix < 8 || prefix > 30) return '';
    const address = (((octets[0] << 24) >>> 0) + (octets[1] << 16) + (octets[2] << 8) + octets[3]) >>> 0;
    const mask = (0xffffffff << (32 - prefix)) >>> 0;
    const network = (address & mask) >>> 0;
    return `${network >>> 24}.${(network >>> 16) & 255}.${(network >>> 8) & 255}.${network & 255}/${prefix}`;
  }

  async function networkResource() {
    const [topology, profiles] = await Promise.all([
      api('/api/v1/admin/networks/discovery'),
      api('/api/v1/admin/networks')
    ]);
    const networks = topology.networks || [];
    const connections = topology.connections || [];
    const errors = connections.filter(connection => connection.error);
    const configuredProfiles = network => profiles.filter(profile =>
      Number(profile.connection_id) === Number(network.connection_id) &&
      profile.bridge === network.iface &&
      (!profile.node_name || profile.node_name === network.node_name)
    );
    const liveRows = networks.map((network, index) => {
      const configured = configuredProfiles(network);
      const address = network.cidr || [network.address, network.netmask].filter(Boolean).join(' / ') || '—';
      const details = [network.type, network.bridge_ports ? `ports: ${network.bridge_ports}` : '', network.vlan_aware === true ? 'VLAN-aware' : ''].filter(Boolean).join(' · ');
      const liveStatus = network.active === null ? network.node_status : (network.active ? 'active' : 'disabled');
      const profileStatus = configured.length
        ? `<span class="status-badge status-active">IPAM: ${configured.length}</span>`
        : `<span class="status-badge status-unknown">${locale==='pl'?'bez IPAM':'no IPAM'}</span>`;
      const action = network.configurable
        ? `<button class="btn btn-sm btn-outline-primary" type="button" data-network-configure="${index}">${icon('sliders')}${locale==='pl'?'Skonfiguruj IPAM':'Configure IPAM'}</button>`
        : `<span class="resource-meta">${locale==='pl'?'Interfejs nie jest bridge':'Interface is not a bridge'}</span>`;
      return `<tr><td><strong>${h(network.iface)}</strong><div class="resource-meta">${h(details)}</div></td><td>${h(network.connection_name)}</td><td>${h(network.node_name)}</td><td>${status(liveStatus)}</td><td>${h(address)}${network.gateway?`<div class="resource-meta">gateway: ${h(network.gateway)}</div>`:''}</td><td>${profileStatus}</td><td>${action}</td></tr>`;
    }).join('');
    const errorAlerts = errors.map(connection => `<div class="alert alert-danger" role="alert"><strong>${h(connection.name)}:</strong> ${h(connection.error)}</div>`).join('');
    const connectionSummary = connections.length
      ? connections.map(connection => `${h(connection.name)}: ${connection.error ? h(locale==='pl'?'błąd':'error') : h(connection.network_count)}`).join(' · ')
      : (locale === 'pl' ? 'Brak aktywnych połączeń Proxmox.' : 'No active Proxmox connections.');
    const profileRows = profiles.map(profile => {
      const existsInProxmox = networks.some(network => Number(network.connection_id) === Number(profile.connection_id) && network.iface === profile.bridge && (!profile.node_name || profile.node_name === network.node_name));
      const portalStatus = !existsInProxmox ? 'error' : (Number(profile.enabled) === 1 ? 'active' : 'disabled');
      const missing = existsInProxmox ? '' : `<div class="resource-meta text-danger">${locale==='pl'?'Nie znaleziono bridge w aktualnym odczycie Proxmox':'Bridge not found in the current Proxmox response'}</div>`;
      return `<tr><td><strong>${h(profile.name)}</strong><div class="resource-meta">#${h(profile.id)}</div></td><td>${h(profile.connection_name)}</td><td>${h(profile.node_name || (locale==='pl'?'cały klaster':'whole cluster'))}</td><td>${h(profile.bridge)}${profile.vlan_id?` · VLAN ${h(profile.vlan_id)}`:''}${missing}</td><td>${h(profile.subnet)}</td><td>${h(profile.free_count ?? 0)} / ${h(profile.address_count ?? 0)}</td><td>${status(portalStatus)}</td>${adminActions('networks',profile)}</tr>`;
    }).join('');

    content.innerHTML = `${errorAlerts}
      <section class="panel mb-3 d-none" id="networkIpamPanel" aria-labelledby="networkIpamTitle">
        <div class="panel-header"><div><h2 class="h5 mb-1" id="networkIpamTitle">${locale==='pl'?'Konfiguracja IPAM dla bridge':'IPAM configuration for bridge'}</h2><p class="text-secondary small mb-0" id="networkIpamSource"></p></div><button class="btn btn-sm btn-outline-secondary" id="networkIpamCancel" type="button">${icon('x')}${tr.cancel}</button></div>
        <form class="panel-body admin-form-grid" id="networkIpamForm">
          <input name="connection_id" type="hidden">
          <div><label class="form-label" for="networkConnection">${locale==='pl'?'Połączenie Proxmox':'Proxmox connection'}</label><input id="networkConnection" class="form-control" readonly></div>
          <div><label class="form-label" for="networkBridge">Bridge</label><input id="networkBridge" name="bridge" class="form-control" readonly required></div>
          <div><label class="form-label" for="networkScope">${locale==='pl'?'Zakres':'Scope'}</label><select id="networkScope" name="node_name" class="form-select"></select></div>
          <div><label class="form-label" for="networkName">${locale==='pl'?'Nazwa profilu':'Profile name'}</label><input id="networkName" name="name" class="form-control" maxlength="100" required></div>
          <div><label class="form-label" for="networkVlan">VLAN ID</label><input id="networkVlan" name="vlan_id" type="number" class="form-control" min="1" max="4094" placeholder="${locale==='pl'?'opcjonalnie':'optional'}"></div>
          <div><label class="form-label" for="networkSubnet">${locale==='pl'?'Podsieć dla VM':'VM subnet'}</label><input id="networkSubnet" name="subnet" class="form-control" placeholder="192.0.2.0/24" required></div>
          <div><label class="form-label" for="networkGateway">Gateway</label><input id="networkGateway" name="gateway" class="form-control" placeholder="192.0.2.1"></div>
          <div><label class="form-label" for="networkIpStart">${locale==='pl'?'Pierwszy adres IP':'First IP address'}</label><input id="networkIpStart" name="ip_start" class="form-control" placeholder="192.0.2.10" required></div>
          <div><label class="form-label" for="networkIpEnd">${locale==='pl'?'Ostatni adres IP':'Last IP address'}</label><input id="networkIpEnd" name="ip_end" class="form-control" placeholder="192.0.2.200" required></div>
          <div class="span-3"><label class="form-label" for="networkDns">DNS</label><input id="networkDns" name="dns_servers" class="form-control" placeholder="1.1.1.1, 8.8.8.8"><div class="form-text">${locale==='pl'?'Zakres IP musi pomijać adresy hostów Proxmox i inne urządzenia używane w tej podsieci.':'The IP range must exclude Proxmox host addresses and other devices used in this subnet.'}</div></div>
          <button class="btn btn-primary span-3" type="submit">${icon('save')}${locale==='pl'?'Zapisz profil IPAM':'Save IPAM profile'}</button>
        </form>
      </section>
      <section class="panel mb-3"><div class="panel-header"><div><h2 class="h5 mb-1">${locale==='pl'?'Sieci Proxmox':'Proxmox networks'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Widok jest pobierany bezpośrednio z każdego węzła Proxmox.':'This view is read directly from every Proxmox node.'} ${connectionSummary}</p></div><button class="btn btn-primary" id="refreshProxmoxNetworks" type="button">${icon('refresh')}${locale==='pl'?'Odśwież z Proxmox':'Refresh from Proxmox'}</button></div><div class="table-responsive"><table class="table"><thead><tr><th>Interface / bridge</th><th>Proxmox</th><th>${locale==='pl'?'Węzeł':'Node'}</th><th>Status</th><th>IP / CIDR</th><th>Portal</th><th>${locale==='pl'?'Akcje':'Actions'}</th></tr></thead><tbody>${liveRows||`<tr><td colspan="7">${empty()}</td></tr>`}</tbody></table></div></section>
      <section class="panel"><div class="panel-header"><div><h2 class="h5 mb-1">${locale==='pl'?'Profile IPAM portalu':'Portal IPAM profiles'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Profile korzystają wyłącznie z bridge wykrytych w Proxmox.':'Profiles use only bridges discovered in Proxmox.'}</p></div></div><div class="table-responsive"><table class="table"><thead><tr><th>${locale==='pl'?'Nazwa':'Name'}</th><th>Proxmox</th><th>${locale==='pl'?'Zakres':'Scope'}</th><th>Bridge</th><th>${locale==='pl'?'Podsieć':'Subnet'}</th><th>${locale==='pl'?'Wolne IP':'Free IPs'}</th><th>Status</th><th>${locale==='pl'?'Akcje':'Actions'}</th></tr></thead><tbody>${profileRows||`<tr><td colspan="8">${empty()}</td></tr>`}</tbody></table></div></section>`;

    const panel = document.getElementById('networkIpamPanel');
    const form = document.getElementById('networkIpamForm');
    const closePanel = () => { panel.classList.add('d-none'); form.reset(); };
    document.getElementById('networkIpamCancel').addEventListener('click', closePanel);
    document.getElementById('refreshProxmoxNetworks').addEventListener('click', async event => {
      event.currentTarget.disabled = true;
      try { await networkResource(); } catch (error) { event.currentTarget.disabled = false; toast(error.message, 'danger'); }
    });
    content.onclick = async event => {
      const configure = event.target.closest('[data-network-configure]');
      if (configure) {
        const network = networks[Number(configure.dataset.networkConfigure)];
        if (!network) return;
        form.reset();
        form.elements.namedItem('connection_id').value = network.connection_id;
        form.elements.namedItem('bridge').value = network.iface;
        form.elements.namedItem('name').value = `${network.iface}-${network.node_name}`.slice(0, 100);
        form.elements.namedItem('subnet').value = normalizeIpv4Cidr(network.cidr);
        form.elements.namedItem('gateway').value = network.gateway || '';
        document.getElementById('networkConnection').value = network.connection_name;
        document.getElementById('networkIpamSource').textContent = `${network.connection_name} · ${network.node_name} · ${network.iface}`;
        document.getElementById('networkScope').innerHTML = `<option value="${h(network.node_name)}">${locale==='pl'?'Tylko węzeł':'Node only'}: ${h(network.node_name)}</option><option value="">${locale==='pl'?'Cały klaster (bridge musi istnieć na każdym aktywnym węźle)':'Whole cluster (bridge must exist on every active node)'}</option>`;
        panel.classList.remove('d-none');
        panel.scrollIntoView({behavior:'smooth', block:'start'});
        window.setTimeout(() => form.elements.namedItem('name').focus(), 0);
        return;
      }
      const toggle = event.target.closest('[data-admin-action="toggle"]');
      if (toggle) {
        toggle.disabled = true;
        try {
          await api(`/api/v1/admin/networks/${Number(toggle.dataset.id)}`, {method:'PATCH', body:{enabled:toggle.dataset.enable==='1'}});
          toast(tr.saved); await networkResource();
        } catch (error) { toggle.disabled = false; toast(error.message, 'danger'); }
      }
    };
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        await api('/api/v1/admin/networks', {method:'POST', body:Object.fromEntries(new FormData(form))});
        toast(tr.saved); await networkResource();
      } catch (error) { submit.disabled = false; toast(error.message, 'danger'); }
    });
  }

  async function storageResource() {
    const [inventory, profiles] = await Promise.all([
      api('/api/v1/admin/storages/discovery'),
      api('/api/v1/admin/storages')
    ]);
    const storages = inventory.storages || [];
    const connections = inventory.connections || [];
    const errorAlerts = connections.filter(connection => connection.error).map(connection => `<div class="alert alert-danger" role="alert"><strong>${h(connection.name)}:</strong> ${h(connection.error)}</div>`).join('');
    const summary = connections.length
      ? connections.map(connection => `${h(connection.name)}: ${connection.error ? h(locale==='pl'?'błąd':'error') : h(connection.resource_count)}`).join(' · ')
      : (locale==='pl'?'Brak aktywnych połączeń Proxmox.':'No active Proxmox connections.');
    const configuredFor = storage => profiles.filter(profile => Number(profile.connection_id) === Number(storage.connection_id) && profile.storage_name === storage.storage_name);
    const liveRows = storages.map((storage, index) => {
      const configured = configuredFor(storage);
      const available = storage.available_nodes || [];
      const nodeDetails = (storage.nodes || []).map(node => `${node.node_name}: ${node.active===false||node.enabled===false?(locale==='pl'?'niedostępny':'unavailable'):`${formatBytes(node.available_bytes)} ${locale==='pl'?'wolne':'free'}`}`).join(' · ');
      const profileStatus = configured.length ? `<span class="status-badge status-active">${locale==='pl'?'w portalu':'in portal'}: ${configured.length}</span>` : `<span class="status-badge status-unknown">${locale==='pl'?'nie dodano':'not added'}</span>`;
      let action = `<span class="resource-meta">${locale==='pl'?'Brak obsługi dysków VM':'No VM disk support'}</span>`;
      if (storage.supports_images) action = available.length
        ? `<button class="btn btn-sm btn-outline-primary" type="button" data-storage-configure="${index}">${icon('sliders')}${locale==='pl'?'Dodaj do portalu':'Add to portal'}</button>`
        : `<span class="resource-meta text-danger">${locale==='pl'?'Storage nieaktywne':'Storage unavailable'}</span>`;
      return `<tr><td><strong>${h(storage.storage_name)}</strong><div class="resource-meta">${h(storage.type)}${storage.shared===true?' · shared':''}</div></td><td>${h(storage.connection_name)}</td><td>${h(storage.content_types||'—')}</td><td>${storage.enabled?status('active'):status('disabled')}</td><td>${h(available.length)}<div class="resource-meta">${h(nodeDetails||'—')}</div></td><td>${profileStatus}</td><td>${action}</td></tr>`;
    }).join('');
    const profileRows = profiles.map(profile => {
      const discovered = storages.find(storage => Number(storage.connection_id) === Number(profile.connection_id) && storage.storage_name === profile.storage_name);
      const exists = Boolean(discovered) && (!profile.node_name || discovered.nodes.some(node => node.node_name === profile.node_name));
      const portalStatus = !exists ? 'error' : (Number(profile.enabled)===1?'active':'disabled');
      const missing = exists ? '' : `<div class="resource-meta text-danger">${locale==='pl'?'Nie znaleziono w Proxmox':'Not found in Proxmox'}</div>`;
      return `<tr><td><strong>${h(profile.storage_name)}</strong><div class="resource-meta">#${h(profile.id)}</div></td><td>${h(profile.connection_name)}</td><td>${h(profile.node_name||(locale==='pl'?'cały klaster':'whole cluster'))}</td><td>${h(profile.content_types||'—')}${missing}</td><td>${status(portalStatus)}</td>${adminActions('storages',profile)}</tr>`;
    }).join('');

    content.innerHTML = `${errorAlerts}
      <section class="panel mb-3 d-none" id="storageProfilePanel" aria-labelledby="storageProfileTitle"><div class="panel-header"><div><h2 class="h5 mb-1" id="storageProfileTitle">${locale==='pl'?'Dodaj storage do portalu':'Add storage to portal'}</h2><p class="text-secondary small mb-0" id="storageProfileSource"></p></div><button class="btn btn-sm btn-outline-secondary" id="storageProfileCancel" type="button">${icon('x')}${tr.cancel}</button></div><form class="panel-body admin-form-grid" id="storageProfileForm"><input name="connection_id" type="hidden"><div><label class="form-label" for="storageConnection">Proxmox</label><input id="storageConnection" class="form-control" readonly></div><div><label class="form-label" for="storageName">Storage ID</label><input id="storageName" name="storage_name" class="form-control" readonly required></div><div><label class="form-label" for="storageScope">${locale==='pl'?'Zakres':'Scope'}</label><select id="storageScope" name="node_name" class="form-select"></select></div><div class="span-3 form-text">${locale==='pl'?'Portal nie tworzy storage w Proxmox — włącza do katalogu istniejący storage obsługujący obrazy VM.':'The portal does not create Proxmox storage; it adds an existing VM-image storage to the catalog.'}</div><button class="btn btn-primary span-3" type="submit">${icon('save')}${locale==='pl'?'Dodaj do katalogu':'Add to catalog'}</button></form></section>
      <section class="panel mb-3"><div class="panel-header"><div><h2 class="h5 mb-1">${locale==='pl'?'Storage Proxmox':'Proxmox storage'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Konfiguracja i dostępność są pobierane bezpośrednio z Proxmox.':'Configuration and availability are read directly from Proxmox.'} ${summary}</p></div><button class="btn btn-primary" id="refreshProxmoxStorages" type="button">${icon('refresh')}${locale==='pl'?'Odśwież z Proxmox':'Refresh from Proxmox'}</button></div><div class="table-responsive"><table class="table"><thead><tr><th>Storage</th><th>Proxmox</th><th>Content</th><th>Status</th><th>${locale==='pl'?'Dostępne węzły':'Available nodes'}</th><th>Portal</th><th>${locale==='pl'?'Akcje':'Actions'}</th></tr></thead><tbody>${liveRows||`<tr><td colspan="7">${empty()}</td></tr>`}</tbody></table></div></section>
      <section class="panel"><div class="panel-header"><div><h2 class="h5 mb-1">${locale==='pl'?'Storage skonfigurowane w portalu':'Storage configured in portal'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Tylko te pozycje można przypisywać projektom.':'Only these entries can be assigned to projects.'}</p></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Storage</th><th>Proxmox</th><th>${locale==='pl'?'Zakres':'Scope'}</th><th>Content</th><th>Status</th><th>${locale==='pl'?'Akcje':'Actions'}</th></tr></thead><tbody>${profileRows||`<tr><td colspan="6">${empty()}</td></tr>`}</tbody></table></div></section>`;

    const panel = document.getElementById('storageProfilePanel'), form = document.getElementById('storageProfileForm');
    document.getElementById('storageProfileCancel').addEventListener('click', () => { panel.classList.add('d-none'); form.reset(); });
    document.getElementById('refreshProxmoxStorages').addEventListener('click', async event => { event.currentTarget.disabled=true;try{await storageResource();}catch(error){event.currentTarget.disabled=false;toast(error.message,'danger');} });
    content.onclick = async event => {
      const configure = event.target.closest('[data-storage-configure]');
      if (configure) {
        const storage = storages[Number(configure.dataset.storageConfigure)]; if (!storage) return;
        form.reset();
        form.elements.namedItem('connection_id').value = storage.connection_id;
        form.elements.namedItem('storage_name').value = storage.storage_name;
        document.getElementById('storageConnection').value = storage.connection_name;
        document.getElementById('storageProfileSource').textContent = `${storage.connection_name} · ${storage.storage_name} · ${storage.type}`;
        const nodeOptions = (storage.available_nodes||[]).map(node => `<option value="${h(node)}">${locale==='pl'?'Tylko węzeł':'Node only'}: ${h(node)}</option>`).join('');
        document.getElementById('storageScope').innerHTML = `${nodeOptions}<option value="">${locale==='pl'?'Cały klaster (storage musi być aktywne na każdym węźle)':'Whole cluster (storage must be active on every node)'}</option>`;
        panel.classList.remove('d-none'); panel.scrollIntoView({behavior:'smooth',block:'start'}); document.getElementById('storageScope').focus(); return;
      }
      const toggle = event.target.closest('[data-admin-action="toggle"]');
      if (toggle) { toggle.disabled=true;try{await api(`/api/v1/admin/storages/${Number(toggle.dataset.id)}`,{method:'PATCH',body:{enabled:toggle.dataset.enable==='1'}});toast(tr.saved);await storageResource();}catch(error){toggle.disabled=false;toast(error.message,'danger');} }
    };
    form.addEventListener('submit', async event => { event.preventDefault();const submit=form.querySelector('button[type="submit"]');submit.disabled=true;try{await api('/api/v1/admin/storages',{method:'POST',body:Object.fromEntries(new FormData(form))});toast(tr.saved);await storageResource();}catch(error){submit.disabled=false;toast(error.message,'danger');} });
  }

  async function templateResource() {
    const [inventory, profiles, builder] = await Promise.all([
      api('/api/v1/admin/templates/discovery'),
      api('/api/v1/admin/templates'),
      api('/api/v1/admin/template-builder/options')
    ]);
    const templates = inventory.templates || [];
    const connections = inventory.connections || [];
    const isoImages = builder.iso_images || [];
    const uploadTargets = builder.upload_targets || [];
    const diskTargets = builder.disk_targets || [];
    const bridges = builder.bridges || [];
    const candidates = (builder.candidates || []).filter(candidate => !candidate.portal_managed);
    const maxIsoBytes = Number(builder.max_iso_bytes || 16 * 1024 ** 3);
    const allStatuses = [...connections, ...(builder.connections || [])];
    const uniqueErrors = [...new Map(allStatuses.filter(connection => connection.error).map(connection => [`${connection.id}:${connection.error}`, connection])).values()];
    const errorAlerts = uniqueErrors.map(connection => `<div class="alert alert-danger" role="alert"><strong>${h(connection.name)}:</strong> ${h(connection.error)}</div>`).join('');
    const summary = connections.length
      ? connections.map(connection => `${h(connection.name)}: ${connection.error ? h(locale==='pl'?'błąd':'error') : h(connection.resource_count)}`).join(' · ')
      : (locale==='pl'?'Brak aktywnych połączeń Proxmox.':'No active Proxmox connections.');
    const configuredFor = template => profiles.find(profile => Number(profile.connection_id) === Number(template.connection_id) && Number(profile.vmid) === Number(template.vmid));
    const liveRows = templates.map((template, index) => {
      const configured = configuredFor(template);
      const resources = [template.cpu_count?`${h(template.cpu_count)} vCPU`:'', template.memory_bytes?formatBytes(template.memory_bytes):'', template.disk_bytes?formatBytes(template.disk_bytes):''].filter(Boolean).join(' · ');
      const action = configured
        ? `<span class="status-badge status-active">${locale==='pl'?'w katalogu':'in catalog'}</span>`
        : `<button class="btn btn-sm btn-outline-primary" type="button" data-template-configure="${index}">${icon('plus')}${locale==='pl'?'Dodaj do katalogu':'Add to catalog'}</button>`;
      return `<tr><td><strong>${h(template.name)}</strong><div class="resource-meta">VMID ${h(template.vmid)}${template.tags?` · ${h(template.tags)}`:''}</div></td><td>${h(template.connection_name)}</td><td>${h(template.node_name)}</td><td>${status(template.status)}</td><td>${resources||'—'}</td><td>${action}</td></tr>`;
    }).join('');
    const profileRows = profiles.map(profile => {
      const exists = templates.some(template => Number(template.connection_id)===Number(profile.connection_id) && Number(template.vmid)===Number(profile.vmid));
      const portalStatus = !exists?'error':(Number(profile.enabled)===1?'active':'disabled');
      const missing = exists?'':`<div class="resource-meta text-danger">${locale==='pl'?'Nie znaleziono template w Proxmox':'Template not found in Proxmox'}</div>`;
      return `<tr><td><strong>${h(profile.name)}</strong><div class="resource-meta">#${h(profile.id)} · VMID ${h(profile.vmid)}</div></td><td>${h(profile.connection_name)}</td><td>${h(profile.node_name)}</td><td>${h(profile.operating_system||'—')}${missing}</td><td>${status(portalStatus)}</td>${adminActions('templates',profile)}</tr>`;
    }).join('');
    const isoRows = isoImages.map(image => `<tr><td><strong>${h(image.filename)}</strong><div class="resource-meta">${h(image.volid)}</div></td><td>${h(image.connection_name)}</td><td>${h(image.node)}</td><td>${h(image.storage)}</td><td>${formatBytes(image.size_bytes)}</td><td>${unixDate(image.created_at)}</td></tr>`).join('');
    const uploadOptions = uploadTargets.map((target, index) => `<option value="${index}">${h(target.connection_name)} · ${h(target.node)} · ${h(target.storage)} (${formatBytes(target.available_bytes)} ${locale==='pl'?'wolne':'free'})</option>`).join('');
    const isoOptions = isoImages.map((image, index) => `<option value="${index}">${h(image.connection_name)} · ${h(image.node)} · ${h(image.filename)}</option>`).join('');
    const convertible = candidates.filter(candidate => candidate.status === 'stopped');
    const candidateOptions = convertible.map((candidate, index) => `<option value="${index}">${h(candidate.connection_name)} · ${h(candidate.node)} · VMID ${h(candidate.vmid)} · ${h(candidate.name)}</option>`).join('');

    content.innerHTML = `${errorAlerts}
      <section class="panel mb-3 d-none" id="isoUploadPanel" aria-labelledby="isoUploadTitle"><div class="panel-header"><div><h2 class="h5 mb-1" id="isoUploadTitle">${locale==='pl'?'Wyślij ISO do Proxmox':'Upload ISO to Proxmox'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Plik jest przesyłany bezpiecznymi fragmentami, więc nie wymaga zwiększania upload_max_filesize.':'The file is sent in safe chunks and does not require increasing upload_max_filesize.'}</p></div><button class="btn btn-sm btn-outline-secondary" data-template-panel-close type="button">${icon('x')}${tr.cancel}</button></div><form class="panel-body admin-form-grid" id="isoUploadForm"><div class="span-2"><label class="form-label" for="isoUploadTarget">${locale==='pl'?'Cel w Proxmox':'Proxmox target'}</label><select id="isoUploadTarget" class="form-select" required>${uploadOptions||`<option value="">${locale==='pl'?'Brak storage obsługującego ISO':'No ISO-capable storage'}</option>`}</select></div><div><label class="form-label" for="isoUploadFile">${locale==='pl'?'Plik ISO':'ISO file'}</label><input id="isoUploadFile" class="form-control" type="file" accept=".iso,application/x-iso9660-image" required></div><div class="span-3"><progress id="isoUploadProgress" class="usage-progress w-100" value="0" max="100">0%</progress><div class="form-text" id="isoUploadStatus">${locale==='pl'?`Maksymalny rozmiar: ${formatBytes(maxIsoBytes)}. Nie zamykaj strony podczas wysyłania.`:`Maximum size: ${formatBytes(maxIsoBytes)}. Keep this page open during upload.`}</div></div><button class="btn btn-primary span-3" type="submit" ${uploadTargets.length?'':'disabled'}>${icon('cloud')}${locale==='pl'?'Wyślij ISO':'Upload ISO'}</button></form></section>
      <section class="panel mb-3 d-none" id="installVmPanel" aria-labelledby="installVmTitle"><div class="panel-header"><div><h2 class="h5 mb-1" id="installVmTitle">${locale==='pl'?'Utwórz VM instalacyjną z ISO':'Create installation VM from ISO'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Po instalacji systemu wyłącz VM i użyj opcji „Konwertuj VM”.':'After installing the OS, stop the VM and use “Convert VM”.'}</p></div><button class="btn btn-sm btn-outline-secondary" data-template-panel-close type="button">${icon('x')}${tr.cancel}</button></div><form class="panel-body admin-form-grid" id="installVmForm"><input name="connection_id" type="hidden"><input name="node" type="hidden"><input name="iso_storage" type="hidden"><input name="iso_volume" type="hidden"><div class="span-2"><label class="form-label" for="installIso">ISO</label><select id="installIso" class="form-select" required>${isoOptions||`<option value="">${locale==='pl'?'Najpierw wyślij plik ISO':'Upload an ISO first'}</option>`}</select></div><div><label class="form-label" for="installName">${locale==='pl'?'Nazwa VM':'VM name'}</label><input id="installName" name="name" class="form-control" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,62}" maxlength="63" placeholder="ubuntu-template-build" required></div><div><label class="form-label" for="installVmid">VMID</label><input id="installVmid" name="vmid" class="form-control" type="number" min="100" max="999999999" placeholder="${locale==='pl'?'automatycznie':'automatic'}"></div><div><label class="form-label" for="installCores">vCPU</label><input id="installCores" name="cores" class="form-control" type="number" min="1" max="128" value="2" required></div><div><label class="form-label" for="installMemory">RAM (MB)</label><input id="installMemory" name="memory_mb" class="form-control" type="number" min="512" max="1048576" value="2048" required></div><div><label class="form-label" for="installDisk">${locale==='pl'?'Dysk (GB)':'Disk (GB)'}</label><input id="installDisk" name="disk_gb" class="form-control" type="number" min="4" max="65536" value="20" required></div><div><label class="form-label" for="installDiskStorage">Storage</label><select id="installDiskStorage" name="disk_storage" class="form-select" required></select></div><div><label class="form-label" for="installBridge">Bridge</label><select id="installBridge" name="bridge" class="form-select" required></select></div><div><label class="form-label" for="installVlan">VLAN</label><input id="installVlan" name="vlan_id" class="form-control" type="number" min="1" max="4094" placeholder="${locale==='pl'?'opcjonalnie':'optional'}"></div><div><label class="form-label" for="installOs">OS</label><select id="installOs" name="ostype" class="form-select"><option value="l26">Linux 2.6+</option><option value="win11">Windows 11 / Server 2022–2025</option><option value="win10">Windows 10 / Server 2016–2019</option><option value="other">Other</option></select></div><div class="span-2"><label class="form-label" for="installDescription">${locale==='pl'?'Opis':'Description'}</label><input id="installDescription" name="description" class="form-control" maxlength="500"></div><div class="span-3 alert alert-info mb-0">${locale==='pl'?'Portal utworzy dysk scsi0, napęd cloud-init oraz podłączy ISO. Instalację wykonaj przez konsolę Proxmox.':'The portal creates scsi0, a cloud-init drive, and attaches the ISO. Complete installation through the Proxmox console.'}</div><button class="btn btn-primary span-3" type="submit" ${isoImages.length?'':'disabled'}>${icon('plus')}${locale==='pl'?'Utwórz VM instalacyjną':'Create installation VM'}</button></form></section>
      <section class="panel mb-3 d-none" id="convertVmPanel" aria-labelledby="convertVmTitle"><div class="panel-header"><div><h2 class="h5 mb-1" id="convertVmTitle">${locale==='pl'?'Konwertuj VM do template':'Convert VM to template'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Dostępne są wyłączone maszyny spoza zarządzania portalem.':'Stopped machines not managed by the portal are available.'}</p></div><button class="btn btn-sm btn-outline-secondary" data-template-panel-close type="button">${icon('x')}${tr.cancel}</button></div><form class="panel-body admin-form-grid" id="convertVmForm"><div class="span-3"><label class="form-label" for="convertCandidate">VM</label><select id="convertCandidate" class="form-select" required>${candidateOptions||`<option value="">${locale==='pl'?'Brak wyłączonych VM możliwych do konwersji':'No stopped VM available for conversion'}</option>`}</select></div><div class="span-3 alert alert-warning mb-0">${locale==='pl'?'Konwersja jest nieodwracalna w Proxmox. VM musi mieć dysk scsi0 i cloud-init; portal odłączy instalacyjny napęd CD/DVD.':'Conversion is irreversible in Proxmox. The VM must contain scsi0 and cloud-init; the portal detaches installation CD/DVD media.'}</div><button class="btn btn-warning span-3" type="submit" ${convertible.length?'':'disabled'}>${icon('template')}${locale==='pl'?'Konwertuj do template':'Convert to template'}</button></form></section>
      <section class="panel mb-3 d-none" id="templateProfilePanel" aria-labelledby="templateProfileTitle"><div class="panel-header"><div><h2 class="h5 mb-1" id="templateProfileTitle">${locale==='pl'?'Dodaj template do katalogu':'Add template to catalog'}</h2><p class="text-secondary small mb-0" id="templateProfileSource"></p></div><button class="btn btn-sm btn-outline-secondary" id="templateProfileCancel" type="button">${icon('x')}${tr.cancel}</button></div><form class="panel-body admin-form-grid" id="templateProfileForm"><input name="connection_id" type="hidden"><div><label class="form-label" for="templateConnection">Proxmox</label><input id="templateConnection" class="form-control" readonly></div><div><label class="form-label" for="templateNode">${locale==='pl'?'Węzeł':'Node'}</label><input id="templateNode" name="node_name" class="form-control" readonly required></div><div><label class="form-label" for="templateVmid">VMID</label><input id="templateVmid" name="vmid" class="form-control" type="number" readonly required></div><div><label class="form-label" for="templateName">${locale==='pl'?'Nazwa w portalu':'Portal name'}</label><input id="templateName" name="name" class="form-control" maxlength="100" required></div><div class="span-2"><label class="form-label" for="templateOs">${locale==='pl'?'System operacyjny':'Operating system'}</label><input id="templateOs" name="operating_system" class="form-control" maxlength="100"></div><div class="span-3"><label class="form-label" for="templateDescription">${locale==='pl'?'Opis':'Description'}</label><textarea id="templateDescription" name="description" class="form-control" maxlength="5000"></textarea><div class="form-text">${locale==='pl'?'Przed zapisaniem portal potwierdzi w Proxmox obecność dysku scsi0 i cloud-init.':'Before saving, the portal verifies scsi0 and cloud-init in Proxmox.'}</div></div><button class="btn btn-primary span-3" type="submit">${icon('save')}${locale==='pl'?'Dodaj do katalogu':'Add to catalog'}</button></form></section>
      <section class="panel mb-3"><div class="panel-header"><div><h2 class="h5 mb-1">${locale==='pl'?'Template dostępne w Proxmox':'Templates available in Proxmox'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Lista pochodzi z zasobów klastra Proxmox.':'The list comes from Proxmox cluster resources.'} ${summary}</p></div><div class="actions"><button class="btn btn-outline-primary" id="openIsoUpload" type="button" ${uploadTargets.length?'':'disabled'}>${icon('cloud')}${locale==='pl'?'Upload ISO':'Upload ISO'}</button><button class="btn btn-outline-primary" id="openInstallVm" type="button" ${isoImages.length?'':'disabled'}>${icon('plus')}${locale==='pl'?'Utwórz z ISO':'Create from ISO'}</button><button class="btn btn-outline-warning" id="openConvertVm" type="button" ${convertible.length?'':'disabled'}>${icon('template')}${locale==='pl'?'Konwertuj VM':'Convert VM'}</button><button class="btn btn-primary" id="refreshProxmoxTemplates" type="button">${icon('refresh')}${locale==='pl'?'Odśwież':'Refresh'}</button></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Template</th><th>Proxmox</th><th>${locale==='pl'?'Węzeł':'Node'}</th><th>Status</th><th>${locale==='pl'?'Zasoby':'Resources'}</th><th>${locale==='pl'?'Akcje':'Actions'}</th></tr></thead><tbody>${liveRows||`<tr><td colspan="6">${empty()}</td></tr>`}</tbody></table></div></section>
      <section class="panel mb-3"><div class="panel-header"><div><h2 class="h5 mb-1">${locale==='pl'?'Obrazy ISO w Proxmox':'ISO images in Proxmox'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Obrazy wykryte na aktywnych storage z typem content ISO.':'Images discovered on active storage with ISO content enabled.'}</p></div><span class="status-badge status-active">${h(isoImages.length)}</span></div><div class="table-responsive"><table class="table"><thead><tr><th>ISO</th><th>Proxmox</th><th>${locale==='pl'?'Węzeł':'Node'}</th><th>Storage</th><th>${locale==='pl'?'Rozmiar':'Size'}</th><th>${locale==='pl'?'Dodano':'Added'}</th></tr></thead><tbody>${isoRows||`<tr><td colspan="6">${empty()}</td></tr>`}</tbody></table></div></section>
      <section class="panel"><div class="panel-header"><div><h2 class="h5 mb-1">${locale==='pl'?'Template skonfigurowane w portalu':'Templates configured in portal'}</h2><p class="text-secondary small mb-0">${locale==='pl'?'Tylko te template są dostępne podczas tworzenia VM.':'Only these templates are available when creating a VM.'}</p></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Template</th><th>Proxmox</th><th>${locale==='pl'?'Węzeł':'Node'}</th><th>OS</th><th>Status</th><th>${locale==='pl'?'Akcje':'Actions'}</th></tr></thead><tbody>${profileRows||`<tr><td colspan="6">${empty()}</td></tr>`}</tbody></table></div></section>`;

    const panel=document.getElementById('templateProfilePanel'),form=document.getElementById('templateProfileForm');
    const workflowPanels = ['isoUploadPanel','installVmPanel','convertVmPanel','templateProfilePanel'].map(id=>document.getElementById(id));
    const openPanel = id => { workflowPanels.forEach(item=>item.classList.toggle('d-none',item.id!==id));const target=document.getElementById(id);target.scrollIntoView({behavior:'smooth',block:'start'});window.setTimeout(()=>target.querySelector('input:not([type=hidden]),select,textarea')?.focus(),0); };
    const closePanels = () => workflowPanels.forEach(item=>item.classList.add('d-none'));
    document.querySelectorAll('[data-template-panel-close]').forEach(button=>button.addEventListener('click',closePanels));
    document.getElementById('templateProfileCancel').addEventListener('click',()=>{closePanels();form.reset();});
    document.getElementById('openIsoUpload').addEventListener('click',()=>openPanel('isoUploadPanel'));
    document.getElementById('openInstallVm').addEventListener('click',()=>openPanel('installVmPanel'));
    document.getElementById('openConvertVm').addEventListener('click',()=>openPanel('convertVmPanel'));
    document.getElementById('refreshProxmoxTemplates').addEventListener('click',async event=>{event.currentTarget.disabled=true;try{await templateResource();}catch(error){event.currentTarget.disabled=false;toast(error.message,'danger');}});
    content.onclick = async event => {
      const configure=event.target.closest('[data-template-configure]');
      if(configure){const template=templates[Number(configure.dataset.templateConfigure)];if(!template)return;form.reset();form.elements.namedItem('connection_id').value=template.connection_id;form.elements.namedItem('node_name').value=template.node_name;form.elements.namedItem('vmid').value=template.vmid;form.elements.namedItem('name').value=template.name;document.getElementById('templateConnection').value=template.connection_name;document.getElementById('templateProfileSource').textContent=`${template.connection_name} · ${template.node_name} · VMID ${template.vmid}`;openPanel('templateProfilePanel');return;}
      const toggle=event.target.closest('[data-admin-action="toggle"]');
      if(toggle){toggle.disabled=true;try{await api(`/api/v1/admin/templates/${Number(toggle.dataset.id)}`,{method:'PATCH',body:{enabled:toggle.dataset.enable==='1'}});toast(tr.saved);await templateResource();}catch(error){toggle.disabled=false;toast(error.message,'danger');}}
    };
    form.addEventListener('submit',async event=>{event.preventDefault();const submit=form.querySelector('button[type="submit"]');submit.disabled=true;try{await api('/api/v1/admin/templates',{method:'POST',body:Object.fromEntries(new FormData(form))});toast(tr.saved);await templateResource();}catch(error){submit.disabled=false;toast(error.message,'danger');}});

    const installForm=document.getElementById('installVmForm'),installIso=document.getElementById('installIso');
    const updateInstallTargets=()=>{const image=isoImages[Number(installIso.value)];const diskSelect=document.getElementById('installDiskStorage'),bridgeSelect=document.getElementById('installBridge');if(!image){diskSelect.innerHTML='';bridgeSelect.innerHTML='';return;}installForm.elements.namedItem('connection_id').value=image.connection_id;installForm.elements.namedItem('node').value=image.node;installForm.elements.namedItem('iso_storage').value=image.storage;installForm.elements.namedItem('iso_volume').value=image.volid;const disks=diskTargets.filter(item=>Number(item.connection_id)===Number(image.connection_id)&&item.node===image.node);const links=bridges.filter(item=>Number(item.connection_id)===Number(image.connection_id)&&item.node===image.node);diskSelect.innerHTML=disks.map(item=>`<option value="${h(item.storage)}">${h(item.storage)} · ${formatBytes(item.available_bytes)} ${locale==='pl'?'wolne':'free'}</option>`).join('');bridgeSelect.innerHTML=links.map(item=>`<option value="${h(item.bridge)}">${h(item.bridge)} · ${h(item.type)}</option>`).join('');installForm.querySelector('button[type="submit"]').disabled=!disks.length||!links.length;};
    installIso.addEventListener('change',updateInstallTargets);updateInstallTargets();
    installForm.addEventListener('submit',async event=>{event.preventDefault();const submit=event.currentTarget.querySelector('button[type="submit"]');submit.disabled=true;try{const result=await api('/api/v1/admin/template-builder/vms',{method:'POST',body:Object.fromEntries(new FormData(event.currentTarget))});toast(`${locale==='pl'?'VM instalacyjna została utworzona':'Installation VM created'}: VMID ${result.vmid}. ${locale==='pl'?'Dokończ instalację w konsoli Proxmox.':'Complete installation in the Proxmox console.'}`);await templateResource();}catch(error){submit.disabled=false;toast(error.message,'danger');}});

    const convertForm=document.getElementById('convertVmForm');
    convertForm.addEventListener('submit',event=>{event.preventDefault();const candidate=convertible[Number(document.getElementById('convertCandidate').value)];if(!candidate)return;confirmAction(locale==='pl'?`Konwertować VMID ${candidate.vmid} do template? Tej operacji nie można cofnąć.`:`Convert VMID ${candidate.vmid} to a template? This cannot be undone.`,async()=>{const submit=convertForm.querySelector('button[type="submit"]');submit.disabled=true;try{await api('/api/v1/admin/template-builder/convert',{method:'POST',body:{connection_id:candidate.connection_id,node:candidate.node,vmid:candidate.vmid}});toast(tr.queued);window.setTimeout(()=>templateResource().catch(error=>toast(error.message,'danger')),2500);}catch(error){submit.disabled=false;toast(error.message,'danger');}});});

    document.getElementById('isoUploadForm').addEventListener('submit',async event=>{event.preventDefault();const submit=event.currentTarget.querySelector('button[type="submit"]'),file=document.getElementById('isoUploadFile').files[0],target=uploadTargets[Number(document.getElementById('isoUploadTarget').value)],progress=document.getElementById('isoUploadProgress'),message=document.getElementById('isoUploadStatus');if(!file||!target)return;if(!/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}\.iso$/i.test(file.name)){toast(locale==='pl'?'Nazwa ISO zawiera niedozwolone znaki.':'The ISO filename contains unsupported characters.','danger');return;}if(file.size>maxIsoBytes){toast(locale==='pl'?`Plik przekracza limit ${formatBytes(maxIsoBytes)}.`:`The file exceeds the ${formatBytes(maxIsoBytes)} limit.`,'danger');return;}submit.disabled=true;let session=null;try{session=await api('/api/v1/admin/iso-uploads',{method:'POST',body:{filename:file.name,size:file.size,connection_id:target.connection_id,node:target.node,storage:target.storage}});let offset=0;while(offset<file.size){const chunk=file.slice(offset,Math.min(offset+Number(session.chunk_size),file.size));const response=await fetch(appUrl(`/api/v1/admin/iso-uploads/${encodeURIComponent(session.id)}/chunks`),{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/octet-stream','X-CSRF-Token':csrf,'X-Upload-Offset':String(offset)},body:chunk});let payload;try{payload=await response.json();}catch{throw new Error(`HTTP ${response.status}`);}if(!response.ok)throw new Error(payload.error?.message||`HTTP ${response.status}`);offset=Number(payload.data.received);const percent=Math.floor(offset/file.size*100);progress.value=percent;progress.textContent=`${percent}%`;message.textContent=`${locale==='pl'?'Wysłano do portalu':'Sent to portal'}: ${percent}% (${formatBytes(offset)} / ${formatBytes(file.size)})`; }message.textContent=locale==='pl'?'Portal przesyła ISO do Proxmox i czeka na zakończenie zadania…':'The portal is uploading the ISO to Proxmox and waiting for the task…';await api(`/api/v1/admin/iso-uploads/${encodeURIComponent(session.id)}/complete`,{method:'POST'});progress.value=100;toast(locale==='pl'?'ISO zostało przesłane do Proxmox.':'ISO uploaded to Proxmox.');await templateResource();}catch(error){if(session?.id){try{await api(`/api/v1/admin/iso-uploads/${encodeURIComponent(session.id)}`,{method:'DELETE'});}catch{}}submit.disabled=false;message.textContent=error.message;toast(error.message,'danger');}});
  }

  const adminForms = {
    users: `<input name="username" class="form-control" placeholder="Username" required><input name="email" type="email" class="form-control" placeholder="Email" required><select name="role" class="form-select"><option value="user">User</option><option value="admin">Admin</option></select><select name="locale" class="form-select"><option value="pl">Polski</option><option value="en">English</option></select><input name="password" type="password" class="form-control span-2" minlength="12" placeholder="Password (min. 12)" required>`,
    projects: `<input name="name" class="form-control" placeholder="Name" required><input name="slug" class="form-control" pattern="[a-z0-9][a-z0-9-]+" placeholder="slug" required><textarea name="description" class="form-control span-3" placeholder="Description"></textarea>`,
    proxmox: `<input name="name" class="form-control" placeholder="Name" required><input name="hostname" class="form-control" placeholder="pve.example.com" required><input name="port" type="number" class="form-control" value="8006" min="1" max="65535" required><input name="realm" class="form-control" value="pve" required><input name="api_token_id" class="form-control" placeholder="root@pam!cloudportal" autocomplete="username" required><input name="api_token_secret" type="password" class="form-control" placeholder="${locale==='pl'?'Sekret tokenu (nie hasło)':'Token secret (not password)'}" autocomplete="new-password" required><div class="form-text span-3">${locale==='pl'?'Token ID ma format user@realm!token, np. root@pam!cloudportal. Sam login root i hasło nie zadziałają.':'Token ID uses user@realm!token format, e.g. root@pam!cloudportal. A username and password are not an API token.'}</div><label class="form-check span-3"><input name="verify_ssl" class="form-check-input" type="checkbox" checked> Verify TLS certificate</label>`,
    plans: `<input name="name" class="form-control" placeholder="Name" required><input name="slug" class="form-control" placeholder="slug" required><input name="vcpu" type="number" class="form-control" placeholder="vCPU" required><input name="ram_mb" type="number" class="form-control" placeholder="RAM MB" required><input name="disk_gb" type="number" class="form-control" placeholder="Disk GB" required><label class="form-check"><input name="allow_resize" class="form-check-input" type="checkbox" checked> Allow resize</label>`,
    quotas: `<select name="subject_type" class="form-select"><option value="project">Project</option><option value="user">User</option></select><input name="subject_id" type="number" class="form-control" placeholder="Subject ID" required><input name="max_vms" type="number" class="form-control" placeholder="Max VM" required><input name="max_vcpu" type="number" class="form-control" placeholder="Max vCPU" required><input name="max_ram_mb" type="number" class="form-control" placeholder="Max RAM MB" required><input name="max_storage_gb" type="number" class="form-control" placeholder="Max storage GB" required><input name="max_snapshots" type="number" class="form-control" placeholder="Max snapshots" required><input name="max_ip_addresses" type="number" class="form-control" placeholder="Max IP (optional)">`,
    settings: `<input name="key" class="form-control" placeholder="setting.key" required><input name="value" class="form-control span-2" placeholder="Value" required><label class="form-check span-3"><input name="is_public" class="form-check-input" type="checkbox"> Public setting</label>`
  };

  const adminCreateLabels = {
    users: {pl:'Dodaj użytkownika', en:'Add user'},
    projects: {pl:'Utwórz projekt', en:'Create project'},
    proxmox: {pl:'Dodaj połączenie', en:'Add connection'},
    plans: {pl:'Utwórz plan', en:'Create plan'},
    quotas: {pl:'Dodaj limit', en:'Add quota'},
    settings: {pl:'Dodaj ustawienie', en:'Add setting'}
  };

  async function adminResource(resource) {
    const data = await api(`/api/v1/admin/${resource}`);
    const keys = data.length ? Object.keys(data[0]).filter(k=>!['metadata','api_token_id'].includes(k)) : [];
    const form = adminForms[resource] || '';
    const createLabel = adminCreateLabels[resource]?.[locale] || (locale === 'pl' ? 'Dodaj' : 'Add');
    const hasActions = ['proxmox','users','projects','plans','networks','templates','storages'].includes(resource);
    const createPanel = form ? `<section class="panel mb-3 d-none" id="adminCreatePanel" aria-labelledby="adminCreateTitle"><div class="panel-header"><h2 class="h5 mb-0" id="adminCreateTitle">${h(createLabel)}</h2><button class="btn btn-sm btn-outline-secondary" id="adminCreateCancel" type="button">${icon('x')}${locale==='pl'?'Anuluj':'Cancel'}</button></div><form class="panel-body admin-form-grid" id="adminCreate">${form}<button class="btn btn-primary span-3" type="submit">${icon('plus')}${h(createLabel)}</button></form></section>` : '';
    const createButton = form ? `<button class="btn btn-primary" id="adminCreateToggle" type="button" aria-controls="adminCreatePanel" aria-expanded="false">${icon('plus')}${h(createLabel)}</button>` : '';
    const table = data.length ? `<div class="table-responsive"><table class="table"><thead><tr>${keys.map(k=>`<th>${h(k.replaceAll('_',' '))}</th>`).join('')}${hasActions?'<th>Actions</th>':''}</tr></thead><tbody>${data.map(row=>`<tr>${keys.map(k=>`<td>${k==='status'||k==='result'?status(row[k]):h(typeof row[k]==='object'?JSON.stringify(row[k]):row[k]??'—')}</td>`).join('')}${adminActions(resource,row)}</tr>`).join('')}</tbody></table></div>` : empty();
    content.innerHTML = `${createPanel}<section class="panel"><div class="panel-header"><h2 class="h5 mb-0">${h(tr[resource]||resource)}</h2>${createButton}</div>${table}</section>`;

    const createSection = document.getElementById('adminCreatePanel');
    const createToggle = document.getElementById('adminCreateToggle');
    const setCreatePanelOpen = open => {
      if (!createSection || !createToggle) return;
      createSection.classList.toggle('d-none', !open);
      createToggle.setAttribute('aria-expanded', String(open));
      if (open) window.setTimeout(() => createSection.querySelector('input, select, textarea')?.focus(), 0);
    };
    createToggle?.addEventListener('click', () => setCreatePanelOpen(createSection?.classList.contains('d-none') ?? true));
    document.getElementById('adminCreateCancel')?.addEventListener('click', () => {
      document.getElementById('adminCreate')?.reset();
      setCreatePanelOpen(false);
      createToggle?.focus();
    });
    document.querySelectorAll('#adminCreate input, #adminCreate select, #adminCreate textarea').forEach(field => {
      if (!field.getAttribute('aria-label')) field.setAttribute('aria-label', field.getAttribute('placeholder') || field.name.replaceAll('_', ' '));
    });
    document.getElementById('adminCreate')?.addEventListener('submit', async event => {
      event.preventDefault(); const values=Object.fromEntries(new FormData(event.currentTarget)); event.currentTarget.querySelectorAll('input[type=checkbox]').forEach(x=>values[x.name]=x.checked);
      const submit = event.currentTarget.querySelector('button[type=submit]');
      submit.disabled = true;
      try { await api(`/api/v1/admin/${resource}`,{method:'POST',body:values});toast(tr.saved);adminResource(resource); } catch(error){submit.disabled=false;toast(error.message,'danger');}
    });
    content.onclick = async event => {
      const button=event.target.closest('[data-admin-action]');if(!button)return;
      const id=Number(button.dataset.id), action=button.dataset.adminAction;
      try {
        if(action==='sync'){button.disabled=true;await api(`/api/v1/admin/proxmox/${id}/sync`,{method:'POST'});toast(tr.saved);}
        else if(action==='toggle'){await api(`/api/v1/admin/${resource}/${id}`,{method:'PATCH',body:{enabled:button.dataset.enable==='1'}});toast(tr.saved);}
        else if(action==='block'){await api(`/api/v1/admin/users/${id}`,{method:'PATCH',body:{status:button.dataset.status==='blocked'?'active':'blocked',role:button.dataset.role}});toast(tr.saved);}
        else if(action==='reset-password'){const password=prompt(locale==='pl'?'Nowe hasło (min. 12 znaków):':'New password (min. 12 characters):');if(!password)return;await api(`/api/v1/admin/users/${id}`,{method:'PATCH',body:{status:button.dataset.status,role:button.dataset.role,password}});toast(tr.saved);}
        else if(action==='toggle-role'){const role=button.dataset.role==='admin'?'user':'admin';confirmAction(`${tr.confirm} (${role})`,()=>api(`/api/v1/admin/users/${id}`,{method:'PATCH',body:{status:button.dataset.status,role}}).then(()=>{toast(tr.saved);adminResource(resource);}).catch(error=>toast(error.message,'danger')));return;}
        else if(action==='member'){const userId=prompt('User ID:');if(!userId)return;await api(`/api/v1/admin/projects/${id}/members`,{method:'POST',body:{user_id:Number(userId),membership_role:'member'}});toast(tr.saved);}
        else if(action==='project-access'){const networkId=prompt('Network ID (leave empty to skip):')||null;const storageId=prompt('Storage ID (leave empty to skip):')||null;if(!networkId&&!storageId)return;await api(`/api/v1/admin/projects/${id}/access`,{method:'POST',body:{network_id:networkId,storage_id:storageId}});toast(tr.saved);}
        else if(action==='project-details'){const details=await api(`/api/v1/admin/projects/${id}`);const modal=document.getElementById('confirmModal');document.getElementById('confirmTitle').textContent=details.project.name;document.getElementById('confirmMessage').innerHTML=`<h3 class="h6">Members</h3>${details.members.map(x=>`<div class="d-flex justify-content-between align-items-center border-bottom py-2"><span>#${x.id} ${h(x.username)} · ${h(x.membership_role)}</span><button class="btn btn-sm btn-outline-danger" data-remove-project="${id}" data-remove-type="members" data-remove-id="${x.id}">Remove</button></div>`).join('')||empty()}<h3 class="h6 mt-4">Networks</h3>${details.networks.map(x=>`<div class="d-flex justify-content-between align-items-center border-bottom py-2"><span>#${x.id} ${h(x.name)} · ${h(x.bridge)} · VLAN ${h(x.vlan_id||'untagged')}</span><button class="btn btn-sm btn-outline-danger" data-remove-project="${id}" data-remove-type="access/network" data-remove-id="${x.id}">Remove</button></div>`).join('')||empty()}<h3 class="h6 mt-4">Storage</h3>${details.storages.map(x=>`<div class="d-flex justify-content-between align-items-center border-bottom py-2"><span>#${x.id} ${h(x.storage_name)}</span><button class="btn btn-sm btn-outline-danger" data-remove-project="${id}" data-remove-type="access/storage" data-remove-id="${x.id}">Remove</button></div>`).join('')||empty()}`;document.getElementById('confirmAction').classList.add('d-none');document.getElementById('confirmMessage').querySelectorAll('[data-remove-project]').forEach(remove=>remove.addEventListener('click',()=>{bootstrap.Modal.getOrCreateInstance(modal).hide();confirmAction(tr.confirm,async()=>{try{await api(`/api/v1/admin/projects/${remove.dataset.removeProject}/${remove.dataset.removeType}/${remove.dataset.removeId}`,{method:'DELETE'});toast(tr.saved);adminResource('projects');}catch(error){toast(error.message,'danger');}});}));bootstrap.Modal.getOrCreateInstance(modal).show();return;}
        else if(action==='connection-status'){await api(`/api/v1/admin/proxmox/${id}`,{method:'PATCH',body:{status:button.dataset.status==='disabled'?'active':'disabled'}});toast(tr.saved);}
        else if(action==='rotate-secret'){const secret=prompt(locale==='pl'?'Nowy sekret tokenu API:':'New API token secret:');if(!secret)return;await api(`/api/v1/admin/proxmox/${id}`,{method:'PATCH',body:{status:button.dataset.status,api_token_secret:secret}});toast(tr.saved);}
        else if(action==='project-status'){await api(`/api/v1/admin/projects/${id}`,{method:'PATCH',body:{status:button.dataset.status==='active'?'suspended':'active'}});toast(tr.saved);}
        adminResource(resource);
      } catch(error){toast(error.message,'danger');button.disabled=false;}
    };
  }
  function adminActions(resource,row) {
    if(resource==='proxmox')return `<td><div class="actions"><button class="btn btn-sm btn-outline-primary" data-admin-action="sync" data-id="${row.id}">${icon('refresh')}Test / Sync</button><button class="btn btn-sm btn-outline-secondary" data-admin-action="rotate-secret" data-id="${row.id}" data-status="${h(row.status)}">${icon('key')}${locale==='pl'?'Rotuj sekret':'Rotate secret'}</button><button class="btn btn-sm btn-outline-warning" data-admin-action="connection-status" data-id="${row.id}" data-status="${h(row.status)}">${icon('power')}${row.status==='disabled'?'Enable':'Disable'}</button></div></td>`;
    if(resource==='users')return `<td><div class="actions"><button class="btn btn-sm btn-outline-warning" data-admin-action="block" data-id="${row.id}" data-status="${h(row.status)}" data-role="${h(row.role)}">${icon('ban')}${row.status==='blocked'?'Unblock':'Block'}</button><button class="btn btn-sm btn-outline-secondary" data-admin-action="reset-password" data-id="${row.id}" data-status="${h(row.status)}" data-role="${h(row.role)}">${icon('key')}Reset password</button><button class="btn btn-sm btn-outline-primary" data-admin-action="toggle-role" data-id="${row.id}" data-status="${h(row.status)}" data-role="${h(row.role)}">${icon('users')}Set ${row.role==='admin'?'user':'admin'}</button></div></td>`;
    if(resource==='projects')return `<td><div class="actions"><button class="btn btn-sm btn-outline-secondary" data-admin-action="project-details" data-id="${row.id}">${icon('eye')}Details</button><button class="btn btn-sm btn-outline-warning" data-admin-action="project-status" data-id="${row.id}" data-status="${h(row.status)}">${icon('power')}${row.status==='active'?'Suspend':'Activate'}</button><button class="btn btn-sm btn-outline-primary" data-admin-action="member" data-id="${row.id}">${icon('user-plus')}Add member</button><button class="btn btn-sm btn-outline-primary" data-admin-action="project-access" data-id="${row.id}">${icon('link')}Assign network/storage</button></div></td>`;
    if(['plans','networks','templates','storages'].includes(resource))return `<td><button class="btn btn-sm btn-outline-secondary" data-admin-action="toggle" data-id="${row.id}" data-enable="${Number(row.enabled)===1?'0':'1'}">${icon('power')}${Number(row.enabled)===1?'Disable':'Enable'}</button></td>`;
    return '';
  }

  async function infrastructure() {
    const connections = await api('/api/v1/admin/proxmox');
    const nodes = await api('/api/v1/admin/nodes');
    content.innerHTML = `<div class="metric-grid">${metric('Clusters',connections.length)}${metric('Nodes',nodes.length)}${metric('Online',nodes.filter(n=>n.status==='online').length)}${metric('Errors',connections.filter(c=>c.status==='error').length)}</div><section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">Nodes</h2></div><div class="table-responsive"><table class="table"><thead><tr><th>Cluster</th><th>Node</th><th>Status</th><th>CPU</th><th>RAM</th><th>Last seen</th></tr></thead><tbody>${nodes.map(n=>`<tr><td>${h(n.connection_name)}</td><td>${h(n.node_name)}</td><td>${status(n.status)}</td><td>${n.cpu_usage===null?'—':(Number(n.cpu_usage)*100).toFixed(1)+'%'}</td><td>${n.memory_total?`${Math.round(n.memory_used/1073741824)} / ${Math.round(n.memory_total/1073741824)} GB`:'—'}</td><td>${date(n.last_seen_at)}</td></tr>`).join('')||`<tr><td colspan="6">${empty()}</td></tr>`}</tbody></table></div></section>`;
  }

  const managedResource = resource => isAdmin ? adminResource(resource) : simpleResource(resource);
  const loaders = {dashboard, vms:vmList, 'create-vm':createVmWizard, projects:()=>managedResource('projects'), networks:()=>isAdmin?networkResource():simpleResource('networks'), templates:()=>isAdmin?templateResource():simpleResource('templates'), activity, infrastructure, users:()=>adminResource('users'), proxmox:()=>adminResource('proxmox'), storages:storageResource, plans:()=>adminResource('plans'), quotas:()=>adminResource('quotas'), audit:()=>adminResource('audit'), settings:()=>adminResource('settings')};
  Promise.resolve(loaders[page]?.() || dashboard()).catch(showError);
})();
