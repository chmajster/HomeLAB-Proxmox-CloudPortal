(() => {
  'use strict';
  if (!['ansible', 'blueprints'].includes(document.body?.dataset.page || '')) return;
  const body = document.body;
  const basePath = body.dataset.basePath || '';
  const request = window.CloudPortal?.api?.request;
  const menuButton = document.getElementById('menuButton');
  const closeMenu = () => {
    body.classList.remove('menu-open');
    menuButton?.setAttribute('aria-expanded', 'false');
  };
  menuButton?.addEventListener('click', () => {
    const open = body.classList.toggle('menu-open');
    menuButton.setAttribute('aria-expanded', String(open));
  });
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

  document.getElementById('logoutButton')?.addEventListener('click', async () => {
    if (typeof request === 'function') await request('/api/v1/logout', {method: 'POST'});
    location.assign(`${basePath}/login`);
  });
  document.querySelector('[data-dismiss-checklist]')?.addEventListener('click', () => document.getElementById('firstRunChecklist')?.remove());
})();
