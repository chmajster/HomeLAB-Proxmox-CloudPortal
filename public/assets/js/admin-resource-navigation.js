(() => {
  'use strict';

  const body = document.body;
  const page = body.dataset.page || '';
  const basePath = body.dataset.basePath || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const supported = new Set(['users', 'proxmox', 'networks', 'templates', 'storages', 'plans']);

  function addDetailsButtons() {
    if (!supported.has(page)) return;
    document.querySelectorAll('[data-admin-action][data-id]').forEach(action => {
      const row = action.closest('tr');
      if (!row || row.querySelector('[data-open-admin-resource]')) return;
      const actions = action.closest('.actions') || action.parentElement;
      if (!actions) return;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-sm btn-outline-secondary';
      button.dataset.openAdminResource = page;
      button.dataset.id = action.dataset.id;
      button.textContent = locale === 'pl' ? 'Szczegóły' : 'Details';
      actions.prepend(button);
    });
  }

  const observer = new MutationObserver(() => queueMicrotask(addDetailsButtons));
  observer.observe(document.body, {childList: true, subtree: true});
  addDetailsButtons();

  document.addEventListener('click', event => {
    const details = event.target.closest('[data-open-admin-resource][data-id]');
    if (details) {
      event.preventDefault();
      event.stopImmediatePropagation();
      location.assign(appUrl(`/admin/${details.dataset.openAdminResource}/${Number(details.dataset.id)}`));
      return;
    }

    const resetPassword = event.target.closest('[data-admin-action="reset-password"][data-id]');
    if (resetPassword) {
      event.preventDefault();
      event.stopImmediatePropagation();
      location.assign(appUrl(`/admin/users/${Number(resetPassword.dataset.id)}#password`));
      return;
    }

    const rotateSecret = event.target.closest('[data-admin-action="rotate-secret"][data-id]');
    if (rotateSecret) {
      event.preventDefault();
      event.stopImmediatePropagation();
      location.assign(appUrl(`/admin/proxmox/${Number(rotateSecret.dataset.id)}#secret`));
      return;
    }

    const member = event.target.closest('[data-admin-action="member"][data-id]');
    if (member) {
      event.preventDefault();
      event.stopImmediatePropagation();
      location.assign(appUrl(`/projects/${Number(member.dataset.id)}#members`));
      return;
    }

    const access = event.target.closest('[data-admin-action="project-access"][data-id]');
    if (access) {
      event.preventDefault();
      event.stopImmediatePropagation();
      location.assign(appUrl(`/projects/${Number(access.dataset.id)}#access`));
    }
  }, true);
})();
