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
    const svg = button.querySelector('svg');
    button.textContent = '';
    if (svg) button.append(svg);
    button.append(document.createTextNode(text));
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

  document.addEventListener('click', async event => {
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
      window.alert(error.message || String(error));
    }
  }, true);
})();
