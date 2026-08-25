(() => {
  'use strict';

  if (document.body.dataset.page !== 'project-details') return;

  const body = document.body;
  const content = document.getElementById('appContent');
  const pageTitle = document.getElementById('pageTitle');
  const basePath = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const h = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const date = value => value ? new Intl.DateTimeFormat(locale === 'pl' ? 'pl-PL' : 'en-US', {dateStyle:'medium', timeStyle:'short'}).format(new Date(String(value).replace(' ', 'T') + 'Z')) : '—';
  const badge = value => `<span class="status-badge status-${h(String(value || 'unknown'))}">${h(value || 'unknown')}</span>`;

  async function api(path, options = {}) {
    const init = {
      ...options,
      headers: {
        'Accept': 'application/json',
        ...(['GET', 'HEAD'].includes(options.method || 'GET') ? {} : {'X-CSRF-Token': csrf}),
        ...(options.body ? {'Content-Type':'application/json'} : {}),
        ...(options.headers || {}),
      },
    };
    if (options.body && typeof options.body !== 'string') init.body = JSON.stringify(options.body);
    const response = await fetch(appUrl(path), init);
    let payload;
    try { payload = await response.json(); } catch { throw new Error(`HTTP ${response.status}`); }
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  function projectIdFromUrl() {
    const path = location.pathname.startsWith(basePath) ? location.pathname.slice(basePath.length) : location.pathname;
    const match = path.match(/^\/projects\/(\d+)$/);
    return match ? Number(match[1]) : 0;
  }

  function empty(text) {
    return `<div class="empty-state"><span>${h(text)}</span></div>`;
  }

  async function removeResource(projectId, type, id, label) {
    const question = locale === 'pl' ? `Usunąć ${label} z projektu?` : `Remove ${label} from the project?`;
    if (!window.confirm(question)) return;
    await api(`/api/v1/admin/projects/${projectId}/${type}/${id}`, {method: 'DELETE'});
    await render(projectId);
  }

  function optionRows(rows, selectedIds, label) {
    return rows
      .filter(row => !selectedIds.has(Number(row.id)))
      .map(row => `<option value="${Number(row.id)}">${h(label(row))}</option>`)
      .join('');
  }

  async function render(projectId) {
    const [data, allUsers, allNetworks, allStorages] = await Promise.all([
      api(`/api/v1/admin/projects/${projectId}`),
      api('/api/v1/admin/users'),
      api('/api/v1/admin/networks'),
      api('/api/v1/admin/storages'),
    ]);
    const project = data.project || {};
    const members = data.members || [];
    const networks = data.networks || [];
    const storages = data.storages || [];
    const memberIds = new Set(members.map(item => Number(item.id)));
    const networkIds = new Set(networks.map(item => Number(item.id)));
    const storageIds = new Set(storages.map(item => Number(item.id)));
    const availableUsers = optionRows(allUsers || [], memberIds, row => `${row.username} · ${row.email || '#' + row.id}`);
    const availableNetworks = optionRows((allNetworks || []).filter(row => Number(row.enabled) === 1), networkIds, row => `${row.name} · ${row.bridge} · ${row.subnet}`);
    const availableStorages = optionRows((allStorages || []).filter(row => Number(row.enabled) === 1), storageIds, row => `${row.storage_name} · ${row.connection_name || ''}`);

    pageTitle.textContent = project.name || (locale === 'pl' ? 'Szczegóły projektu' : 'Project details');

    const memberRows = members.map(member => `
      <tr>
        <td><strong>${h(member.username)}</strong><div class="resource-meta">${h(member.email || '')}</div></td>
        <td>${h(member.membership_role)}</td>
        <td>${badge(member.status)}</td>
        <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-member="${Number(member.id)}" data-label="${h(member.username)}">${locale === 'pl' ? 'Usuń z projektu' : 'Remove from project'}</button></td>
      </tr>`).join('');

    const networkRows = networks.map(network => `
      <tr>
        <td><strong>${h(network.name)}</strong><div class="resource-meta">#${Number(network.id)}</div></td>
        <td>${h(network.bridge)}</td>
        <td>${h(network.subnet || '—')}</td>
        <td>${h(network.vlan_id || (locale === 'pl' ? 'bez tagu' : 'untagged'))}</td>
        <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-network="${Number(network.id)}" data-label="${h(network.name)}">${locale === 'pl' ? 'Odepnij' : 'Remove'}</button></td>
      </tr>`).join('');

    const storageRows = storages.map(storage => `
      <tr>
        <td><strong>${h(storage.storage_name)}</strong><div class="resource-meta">#${Number(storage.id)}</div></td>
        <td>${h(storage.node_name || (locale === 'pl' ? 'cały klaster' : 'whole cluster'))}</td>
        <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-storage="${Number(storage.id)}" data-label="${h(storage.storage_name)}">${locale === 'pl' ? 'Odepnij' : 'Remove'}</button></td>
      </tr>`).join('');

    content.innerHTML = `
      <a class="btn btn-outline-secondary mb-3" href="${appUrl('/projects')}">← ${locale === 'pl' ? 'Wróć do projektów' : 'Back to projects'}</a>
      <section class="panel">
        <div class="panel-header">
          <div><h2 class="h4 mb-1">${h(project.name || `Project ${projectId}`)}</h2><p class="text-secondary small mb-0">${h(project.slug || '—')}</p></div>
          ${badge(project.status)}
        </div>
        <div class="panel-body">
          <div class="summary-list">
            <div class="summary-item"><small>ID</small>${h(project.id || projectId)}</div>
            <div class="summary-item"><small>Slug</small>${h(project.slug || '—')}</div>
            <div class="summary-item"><small>${locale === 'pl' ? 'Utworzono' : 'Created'}</small>${date(project.created_at)}</div>
            <div class="summary-item"><small>${locale === 'pl' ? 'Zaktualizowano' : 'Updated'}</small>${date(project.updated_at)}</div>
          </div>
          ${project.description ? `<div class="mt-4"><h3 class="h6">${locale === 'pl' ? 'Opis' : 'Description'}</h3><p class="mb-0 text-break">${h(project.description)}</p></div>` : ''}
        </div>
      </section>

      <section class="panel mt-3" id="members">
        <div class="panel-header"><h2 class="h5 mb-0">${locale === 'pl' ? 'Członkowie projektu' : 'Project members'}</h2><span class="resource-meta">${members.length}</span></div>
        <div class="panel-body border-bottom">
          <form id="addProjectMember" class="row g-2 align-items-end">
            <div class="col-md-7"><label class="form-label">${locale === 'pl' ? 'Użytkownik' : 'User'}</label><select class="form-select" name="user_id" ${availableUsers ? '' : 'disabled'}><option value="">${availableUsers ? (locale === 'pl' ? 'Wybierz użytkownika…' : 'Select user…') : (locale === 'pl' ? 'Wszyscy użytkownicy są już przypisani' : 'All users are already assigned')}</option>${availableUsers}</select></div>
            <div class="col-md-3"><label class="form-label">${locale === 'pl' ? 'Rola w projekcie' : 'Project role'}</label><select class="form-select" name="membership_role"><option value="member">member</option><option value="owner">owner</option></select></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit" ${availableUsers ? '' : 'disabled'}>${locale === 'pl' ? 'Dodaj' : 'Add'}</button></div>
          </form>
        </div>
        ${members.length ? `<div class="table-responsive"><table class="table"><thead><tr><th>${locale === 'pl' ? 'Użytkownik' : 'User'}</th><th>${locale === 'pl' ? 'Rola' : 'Role'}</th><th>Status</th><th>${locale === 'pl' ? 'Akcje' : 'Actions'}</th></tr></thead><tbody>${memberRows}</tbody></table></div>` : empty(locale === 'pl' ? 'Brak członków projektu.' : 'No project members.')}
      </section>

      <section class="panel mt-3" id="access">
        <div class="panel-header"><div><h2 class="h5 mb-0">${locale === 'pl' ? 'Dostęp projektu do infrastruktury' : 'Project infrastructure access'}</h2><p class="resource-meta mb-0">${locale === 'pl' ? 'Przypisz sieć, storage albo oba zasoby jednym formularzem.' : 'Assign a network, storage, or both in one form.'}</p></div></div>
        <div class="panel-body border-bottom">
          <form id="assignProjectAccess" class="row g-2 align-items-end">
            <div class="col-md-5"><label class="form-label">${locale === 'pl' ? 'Sieć (opcjonalnie)' : 'Network (optional)'}</label><select class="form-select" name="network_id"><option value="">${locale === 'pl' ? 'Bez zmiany sieci' : 'No network change'}</option>${availableNetworks}</select></div>
            <div class="col-md-5"><label class="form-label">Storage (${locale === 'pl' ? 'opcjonalnie' : 'optional'})</label><select class="form-select" name="storage_id"><option value="">${locale === 'pl' ? 'Bez zmiany storage' : 'No storage change'}</option>${availableStorages}</select></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit" ${availableNetworks || availableStorages ? '' : 'disabled'}>${locale === 'pl' ? 'Przypisz' : 'Assign'}</button></div>
          </form>
        </div>
        <div class="panel-header border-top"><h3 class="h6 mb-0">${locale === 'pl' ? 'Przypisane sieci' : 'Assigned networks'}</h3><span class="resource-meta">${networks.length}</span></div>
        ${networks.length ? `<div class="table-responsive"><table class="table"><thead><tr><th>${locale === 'pl' ? 'Sieć' : 'Network'}</th><th>Bridge</th><th>Subnet</th><th>VLAN</th><th>${locale === 'pl' ? 'Akcje' : 'Actions'}</th></tr></thead><tbody>${networkRows}</tbody></table></div>` : empty(locale === 'pl' ? 'Brak przypisanych sieci.' : 'No assigned networks.')}
        <div class="panel-header border-top"><h3 class="h6 mb-0">${locale === 'pl' ? 'Przypisany storage' : 'Assigned storage'}</h3><span class="resource-meta">${storages.length}</span></div>
        ${storages.length ? `<div class="table-responsive"><table class="table"><thead><tr><th>Storage</th><th>${locale === 'pl' ? 'Zakres' : 'Scope'}</th><th>${locale === 'pl' ? 'Akcje' : 'Actions'}</th></tr></thead><tbody>${storageRows}</tbody></table></div>` : empty(locale === 'pl' ? 'Brak przypisanego storage.' : 'No assigned storage.')}
      </section>`;

    content.querySelectorAll('[data-remove-member]').forEach(button => button.addEventListener('click', () => removeResource(projectId, 'members', button.dataset.removeMember, button.dataset.label)));
    content.querySelectorAll('[data-remove-network]').forEach(button => button.addEventListener('click', () => removeResource(projectId, 'access/network', button.dataset.removeNetwork, button.dataset.label)));
    content.querySelectorAll('[data-remove-storage]').forEach(button => button.addEventListener('click', () => removeResource(projectId, 'access/storage', button.dataset.removeStorage, button.dataset.label)));
    document.getElementById('addProjectMember')?.addEventListener('submit', async event => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.currentTarget));
      if (!values.user_id) return;
      await api(`/api/v1/admin/projects/${projectId}/members`, {method:'POST', body:{user_id:Number(values.user_id), membership_role:values.membership_role}});
      await render(projectId);
      document.getElementById('members')?.scrollIntoView({block:'start'});
    });
    document.getElementById('assignProjectAccess')?.addEventListener('submit', async event => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.currentTarget));
      if (!values.network_id && !values.storage_id) return;
      await api(`/api/v1/admin/projects/${projectId}/access`, {method:'POST', body:{network_id:values.network_id || null, storage_id:values.storage_id || null}});
      await render(projectId);
      document.getElementById('access')?.scrollIntoView({block:'start'});
    });
    if (location.hash === '#members' || location.hash === '#access') {
      document.querySelector(location.hash)?.scrollIntoView({block:'start'});
    }
  }

  (async () => {
    try {
      const id = projectIdFromUrl();
      if (!id) throw new Error(locale === 'pl' ? 'Nieprawidłowy adres projektu.' : 'Invalid project URL.');
      await render(id);
    } catch (error) {
      pageTitle.textContent = locale === 'pl' ? 'Szczegóły projektu' : 'Project details';
      content.innerHTML = `<a class="btn btn-outline-secondary mb-3" href="${appUrl('/projects')}">← ${locale === 'pl' ? 'Wróć do projektów' : 'Back to projects'}</a><div class="alert alert-danger"><h2 class="h5">${locale === 'pl' ? 'Nie udało się pobrać projektu.' : 'Unable to load project.'}</h2><p class="mb-0">${h(error.message || error)}</p></div>`;
    }
  })();
})();
