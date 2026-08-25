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
    const response = await fetch(appUrl(path), {
      ...options,
      headers: {
        'Accept': 'application/json',
        ...(['GET', 'HEAD'].includes(options.method || 'GET') ? {} : {'X-CSRF-Token': csrf}),
        ...(options.headers || {}),
      },
    });
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
    const question = locale === 'pl'
      ? `Usunąć ${label} z projektu?`
      : `Remove ${label} from the project?`;
    if (!window.confirm(question)) return;
    await api(`/api/v1/admin/projects/${projectId}/${type}/${id}`, {method: 'DELETE'});
    await render(projectId);
  }

  async function render(projectId) {
    const data = await api(`/api/v1/admin/projects/${projectId}`);
    const project = data.project || {};
    const members = data.members || [];
    const networks = data.networks || [];
    const storages = data.storages || [];

    pageTitle.textContent = project.name || (locale === 'pl' ? 'Szczegóły projektu' : 'Project details');

    const memberRows = members.map(member => `
      <tr>
        <td><strong>${h(member.username)}</strong><div class="resource-meta">${h(member.email || '')}</div></td>
        <td>${h(member.membership_role)}</td>
        <td>${badge(member.status)}</td>
        <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-member="${h(member.id)}" data-label="${h(member.username)}">${locale === 'pl' ? 'Usuń z projektu' : 'Remove from project'}</button></td>
      </tr>`).join('');

    const networkRows = networks.map(network => `
      <tr>
        <td><strong>${h(network.name)}</strong><div class="resource-meta">#${h(network.id)}</div></td>
        <td>${h(network.bridge)}</td>
        <td>${h(network.subnet || '—')}</td>
        <td>${h(network.vlan_id || (locale === 'pl' ? 'bez tagu' : 'untagged'))}</td>
        <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-network="${h(network.id)}" data-label="${h(network.name)}">${locale === 'pl' ? 'Odepnij' : 'Remove'}</button></td>
      </tr>`).join('');

    const storageRows = storages.map(storage => `
      <tr>
        <td><strong>${h(storage.storage_name)}</strong><div class="resource-meta">#${h(storage.id)}</div></td>
        <td>${h(storage.node_name || (locale === 'pl' ? 'cały klaster' : 'whole cluster'))}</td>
        <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-storage="${h(storage.id)}" data-label="${h(storage.storage_name)}">${locale === 'pl' ? 'Odepnij' : 'Remove'}</button></td>
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

      <section class="panel mt-3">
        <div class="panel-header"><h2 class="h5 mb-0">${locale === 'pl' ? 'Członkowie projektu' : 'Project members'}</h2><span class="resource-meta">${members.length}</span></div>
        ${members.length ? `<div class="table-responsive"><table class="table"><thead><tr><th>${locale === 'pl' ? 'Użytkownik' : 'User'}</th><th>${locale === 'pl' ? 'Rola' : 'Role'}</th><th>Status</th><th>${locale === 'pl' ? 'Akcje' : 'Actions'}</th></tr></thead><tbody>${memberRows}</tbody></table></div>` : empty(locale === 'pl' ? 'Brak członków projektu.' : 'No project members.')}
      </section>

      <section class="panel mt-3">
        <div class="panel-header"><h2 class="h5 mb-0">${locale === 'pl' ? 'Przypisane sieci' : 'Assigned networks'}</h2><span class="resource-meta">${networks.length}</span></div>
        ${networks.length ? `<div class="table-responsive"><table class="table"><thead><tr><th>${locale === 'pl' ? 'Sieć' : 'Network'}</th><th>Bridge</th><th>Subnet</th><th>VLAN</th><th>${locale === 'pl' ? 'Akcje' : 'Actions'}</th></tr></thead><tbody>${networkRows}</tbody></table></div>` : empty(locale === 'pl' ? 'Brak przypisanych sieci.' : 'No assigned networks.')}
      </section>

      <section class="panel mt-3">
        <div class="panel-header"><h2 class="h5 mb-0">${locale === 'pl' ? 'Przypisany storage' : 'Assigned storage'}</h2><span class="resource-meta">${storages.length}</span></div>
        ${storages.length ? `<div class="table-responsive"><table class="table"><thead><tr><th>Storage</th><th>${locale === 'pl' ? 'Zakres' : 'Scope'}</th><th>${locale === 'pl' ? 'Akcje' : 'Actions'}</th></tr></thead><tbody>${storageRows}</tbody></table></div>` : empty(locale === 'pl' ? 'Brak przypisanego storage.' : 'No assigned storage.')}
      </section>`;

    content.querySelectorAll('[data-remove-member]').forEach(button => button.addEventListener('click', () => removeResource(projectId, 'members', button.dataset.removeMember, button.dataset.label)));
    content.querySelectorAll('[data-remove-network]').forEach(button => button.addEventListener('click', () => removeResource(projectId, 'access/network', button.dataset.removeNetwork, button.dataset.label)));
    content.querySelectorAll('[data-remove-storage]').forEach(button => button.addEventListener('click', () => removeResource(projectId, 'access/storage', button.dataset.removeStorage, button.dataset.label)));
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
