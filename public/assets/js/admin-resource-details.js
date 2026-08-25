(() => {
  'use strict';

  if (document.body.dataset.page !== 'admin-resource-details') return;

  const body = document.body;
  const content = document.getElementById('appContent');
  const pageTitle = document.getElementById('pageTitle');
  const basePath = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const h = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const resourceLabels = locale === 'pl' ? {
    users: 'Użytkownik', proxmox: 'Połączenie Proxmox', networks: 'Sieć', templates: 'Template VM', storages: 'Storage', plans: 'Plan zasobów',
  } : {
    users: 'User', proxmox: 'Proxmox connection', networks: 'Network', templates: 'VM template', storages: 'Storage', plans: 'Resource plan',
  };
  const listLabels = locale === 'pl' ? {
    users: 'Użytkownicy', proxmox: 'Połączenia Proxmox', networks: 'Sieci', templates: 'Template', storages: 'Storage', plans: 'Plany zasobów',
  } : {
    users: 'Users', proxmox: 'Proxmox connections', networks: 'Networks', templates: 'Templates', storages: 'Storage', plans: 'Resource plans',
  };
  const fieldLabels = locale === 'pl' ? {
    id:'ID', name:'Nazwa', username:'Login', email:'E-mail', status:'Status', role:'Rola', locale:'Język', session_version:'Wersja sesji', last_login_at:'Ostatnie logowanie', created_at:'Utworzono', updated_at:'Zaktualizowano',
    hostname:'Host', port:'Port', realm:'Realm', api_token_id:'Token API', verify_ssl:'Weryfikacja TLS', cluster_name:'Klaster', last_checked_at:'Ostatni test', last_error:'Ostatni błąd',
    connection_id:'ID połączenia', connection_name:'Połączenie', node_name:'Węzeł', bridge:'Bridge', vlan_id:'VLAN', subnet:'Podsieć', gateway:'Brama', dns_servers:'DNS', enabled:'Aktywny', address_count:'Adresy IP', free_count:'Wolne IP', reserved_count:'Zarezerwowane IP', allocated_count:'Przydzielone IP',
    vmid:'VMID', operating_system:'System operacyjny', description:'Opis', storage_name:'Storage', content_types:'Typy danych', slug:'Slug', vcpu:'vCPU', ram_mb:'RAM (MB)', disk_gb:'Dysk (GB)', allow_resize:'Resize dozwolony', sort_order:'Kolejność',
  } : {};
  const relatedLabels = locale === 'pl' ? {projects:'Projekty', vms:'Maszyny wirtualne', nodes:'Węzły', networks:'Sieci', storages:'Storage', templates:'Template'} : {projects:'Projects',vms:'Virtual machines',nodes:'Nodes',networks:'Networks',storages:'Storage',templates:'Templates'};

  async function api(path, options = {}) {
    const init = {...options, headers: {'Accept':'application/json', ...(['GET','HEAD'].includes(options.method || 'GET') ? {} : {'X-CSRF-Token':csrf}), ...(options.body ? {'Content-Type':'application/json'} : {}), ...(options.headers || {})}};
    if (options.body && typeof options.body !== 'string') init.body = JSON.stringify(options.body);
    const response = await fetch(appUrl(path), init);
    let payload;
    try { payload = await response.json(); } catch { throw new Error(`HTTP ${response.status}`); }
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  function pathData() {
    const path = location.pathname.startsWith(basePath) ? location.pathname.slice(basePath.length) : location.pathname;
    const match = path.match(/^\/admin\/(users|proxmox|networks|templates|storages|plans)\/(\d+)$/);
    return match ? {resource: match[1], id: Number(match[2])} : null;
  }

  function value(value, key) {
    if (value === null || value === undefined || value === '') return '—';
    if (['enabled','allow_resize','verify_ssl'].includes(key)) return Number(value) === 1 ? (locale === 'pl' ? 'Tak' : 'Yes') : (locale === 'pl' ? 'Nie' : 'No');
    return String(value);
  }

  function summary(record) {
    const hidden = new Set(['password_hash','api_token_secret_encrypted']);
    return Object.entries(record).filter(([key]) => !hidden.has(key)).map(([key, raw]) => `<div class="summary-item"><small>${h(fieldLabels[key] || key.replaceAll('_',' '))}</small><span class="text-break">${h(value(raw, key))}</span></div>`).join('');
  }

  function relatedTable(name, rows) {
    if (!Array.isArray(rows) || rows.length === 0) return '';
    const keys = Object.keys(rows[0]).slice(0, 8);
    const headers = keys.map(key => `<th>${h(fieldLabels[key] || key.replaceAll('_',' '))}</th>`).join('');
    const bodyRows = rows.map(row => `<tr>${keys.map(key => `<td class="text-break">${h(value(row[key], key))}</td>`).join('')}</tr>`).join('');
    return `<section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">${h(relatedLabels[name] || name)}</h2><span class="resource-meta">${rows.length}</span></div><div class="table-responsive"><table class="table"><thead><tr>${headers}</tr></thead><tbody>${bodyRows}</tbody></table></div></section>`;
  }

  function actions(resource, record) {
    if (resource === 'users') {
      return `<section class="panel mt-3" id="account"><div class="panel-header"><h2 class="h5 mb-0">${locale==='pl'?'Zarządzanie kontem':'Account management'}</h2></div><div class="panel-body"><form id="userUpdate" class="row g-3"><div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active" ${record.status==='active'?'selected':''}>active</option><option value="blocked" ${record.status==='blocked'?'selected':''}>blocked</option><option value="pending" ${record.status==='pending'?'selected':''}>pending</option></select></div><div class="col-md-4"><label class="form-label">${locale==='pl'?'Rola':'Role'}</label><select class="form-select" name="role"><option value="user" ${record.role==='user'?'selected':''}>user</option><option value="admin" ${record.role==='admin'?'selected':''}>admin</option></select></div><div class="col-md-4" id="password"><label class="form-label">${locale==='pl'?'Nowe hasło (opcjonalnie)':'New password (optional)'}</label><input class="form-control" name="password" type="password" minlength="12" autocomplete="new-password"></div><div class="col-12"><button class="btn btn-primary" type="submit">${locale==='pl'?'Zapisz zmiany':'Save changes'}</button></div></form></div></section>`;
    }
    if (resource === 'proxmox') {
      return `<section class="panel mt-3" id="connection"><div class="panel-header"><h2 class="h5 mb-0">${locale==='pl'?'Zarządzanie połączeniem':'Connection management'}</h2></div><div class="panel-body"><form id="proxmoxUpdate" class="row g-3"><div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active" ${record.status==='active'?'selected':''}>active</option><option value="disabled" ${record.status==='disabled'?'selected':''}>disabled</option></select></div><div class="col-md-8" id="secret"><label class="form-label">${locale==='pl'?'Nowy sekret tokenu API (opcjonalnie)':'New API token secret (optional)'}</label><input class="form-control" name="api_token_secret" type="password" autocomplete="new-password"></div><div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">${locale==='pl'?'Zapisz zmiany':'Save changes'}</button><button class="btn btn-outline-primary" id="syncProxmox" type="button">Test / Sync</button></div></form></div></section>`;
    }
    if (['networks','templates','storages','plans'].includes(resource)) {
      const enabled = Number(record.enabled) === 1;
      return `<section class="panel mt-3"><div class="panel-header"><h2 class="h5 mb-0">${locale==='pl'?'Stan zasobu':'Resource state'}</h2></div><div class="panel-body"><button class="btn ${enabled?'btn-outline-warning':'btn-outline-success'}" id="toggleResource" type="button">${enabled ? (locale==='pl'?'Wyłącz':'Disable') : (locale==='pl'?'Włącz':'Enable')}</button></div></section>`;
    }
    return '';
  }

  function bindActions(resource, id, record) {
    document.getElementById('userUpdate')?.addEventListener('submit', async event => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.currentTarget));
      if (!values.password) delete values.password;
      await api(`/api/v1/admin/users/${id}`, {method:'PATCH', body:values});
      await render(resource, id);
    });
    document.getElementById('proxmoxUpdate')?.addEventListener('submit', async event => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.currentTarget));
      if (!values.api_token_secret) delete values.api_token_secret;
      await api(`/api/v1/admin/proxmox/${id}`, {method:'PATCH', body:values});
      await render(resource, id);
    });
    document.getElementById('syncProxmox')?.addEventListener('click', async event => {
      event.currentTarget.disabled = true;
      try { await api(`/api/v1/admin/proxmox/${id}/sync`, {method:'POST'}); await render(resource, id); } finally { if (document.body.contains(event.currentTarget)) event.currentTarget.disabled = false; }
    });
    document.getElementById('toggleResource')?.addEventListener('click', async event => {
      event.currentTarget.disabled = true;
      await api(`/api/v1/admin/${resource}/${id}`, {method:'PATCH', body:{enabled:Number(record.enabled)!==1}});
      await render(resource, id);
    });
  }

  async function render(resource, id) {
    const data = await api(`/api/v1/admin/details/${resource}/${id}`);
    const record = data.record || {};
    const title = record.name || record.username || record.storage_name || `${resourceLabels[resource]} #${id}`;
    pageTitle.textContent = title;
    const related = Object.entries(data.related || {}).map(([name, rows]) => relatedTable(name, rows)).join('');
    content.innerHTML = `<a class="btn btn-outline-secondary mb-3" href="${appUrl('/' + resource)}">← ${h(locale==='pl'?'Wróć: ':'Back to ')}${h(listLabels[resource])}</a><section class="panel"><div class="panel-header"><div><p class="eyebrow mb-0">${h(resourceLabels[resource])}</p><h2 class="h4 mb-0">${h(title)}</h2></div><span class="status-badge status-${h(record.status || (Number(record.enabled)===1?'active':'disabled'))}">${h(record.status || (Number(record.enabled)===1?'active':'disabled'))}</span></div><div class="panel-body"><div class="summary-list">${summary(record)}</div></div></section>${actions(resource, record)}${related}`;
    bindActions(resource, id, record);
    if (location.hash) document.querySelector(location.hash)?.scrollIntoView({block:'start'});
  }

  (async () => {
    const current = pathData();
    try {
      if (!current) throw new Error(locale === 'pl' ? 'Nieprawidłowy adres zasobu.' : 'Invalid resource URL.');
      await render(current.resource, current.id);
    } catch (error) {
      pageTitle.textContent = locale === 'pl' ? 'Szczegóły zasobu' : 'Resource details';
      content.innerHTML = `<div class="alert alert-danger"><h2 class="h5">${locale==='pl'?'Nie udało się pobrać danych.':'Unable to load resource.'}</h2><p class="mb-0">${h(error.message || error)}</p></div>`;
    }
  })();
})();
