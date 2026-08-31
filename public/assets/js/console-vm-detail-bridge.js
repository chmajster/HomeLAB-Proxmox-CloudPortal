(() => {
  'use strict';

  const body = document.body;
  if (!body || body.dataset.page !== 'vm-details') return;
  const basePath = body.dataset.basePath || '';
  const relativePath = location.pathname.startsWith(basePath) ? location.pathname.slice(basePath.length) : location.pathname;
  const live = relativePath.match(/^\/infrastructure\/vms\/(\d+)\/([^/]+)\/(\d+)$/);
  if (!live) return;

  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-vm-manage="console-live"]');
    if (!button) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (button.disabled) return;
    button.disabled = true;
    try {
      if (!window.CloudPortalConsole?.openLive) throw new Error('Embedded console module is unavailable.');
      await window.CloudPortalConsole.openLive(Number(live[1]), decodeURIComponent(live[2]), Number(live[3]));
    } catch (error) {
      console.error(error);
    } finally {
      button.disabled = false;
    }
  }, true);
})();
