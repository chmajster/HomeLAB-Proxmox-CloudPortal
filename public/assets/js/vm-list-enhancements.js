(() => {
  'use strict';

  const body = document.body;
  if (!body || body.dataset.page !== 'vms') return;
  const content = document.getElementById('appContent');
  if (!content) return;

  const basePath = body.dataset.basePath || '';
  const isAdmin = body.dataset.admin === '1';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const h = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  let summaryRequest = null;

  async function loadVms() {
    if (!summaryRequest) {
      summaryRequest = fetch(appUrl('/api/v1/vms'), {headers:{Accept:'application/json'}})
        .then(async response => {
          const payload = await response.json().catch(() => ({}));
          if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
          return payload.data || [];
        })
        .finally(() => window.setTimeout(() => { summaryRequest = null; }, 1500));
    }
    return summaryRequest;
  }

  function card(label, value) {
    return `<div class="vm-summary-card"><small>${h(label)}</small><strong>${h(value)}</strong></div>`;
  }

  async function updateSummary(summary) {
    try {
      const vms = await loadVms();
      const running = vms.filter(vm => vm.status === 'running').length;
      const stopped = vms.filter(vm => ['stopped','paused'].includes(String(vm.status))).length;
      const problems = vms.filter(vm => vm.status === 'error').length;
      summary.innerHTML = card(locale === 'pl' ? 'Wszystkie VM' : 'All VMs', vms.length)
        + card(locale === 'pl' ? 'Uruchomione' : 'Running', running)
        + card(locale === 'pl' ? 'Zatrzymane' : 'Stopped', stopped)
        + card(locale === 'pl' ? 'Wymagają uwagi' : 'Need attention', problems);
    } catch {
      summary.remove();
    }
  }

  function addSearch(panel) {
    if (isAdmin || panel.querySelector('#friendlyVmSearch')) return;
    const tableWrap = panel.querySelector('.table-responsive');
    if (!tableWrap) return;
    const toolbar = document.createElement('div');
    toolbar.className = 'vm-list-toolbar';
    toolbar.innerHTML = `<div class="min-w-0"><strong>${locale === 'pl' ? 'Szybkie wyszukiwanie' : 'Quick search'}</strong><div class="resource-meta">${locale === 'pl' ? 'Nazwa, VMID, node, status lub IP' : 'Name, VMID, node, status or IP'}</div></div><input id="friendlyVmSearch" class="form-control ms-auto" type="search" placeholder="${locale === 'pl' ? 'Szukaj VM…' : 'Search VMs…'}" autocomplete="off">`;
    tableWrap.before(toolbar);
    toolbar.querySelector('input').addEventListener('input', event => {
      const query = String(event.currentTarget.value || '').trim().toLowerCase();
      panel.querySelectorAll('tbody tr').forEach(row => {
        row.style.display = !query || row.textContent.toLowerCase().includes(query) ? '' : 'none';
      });
    });
  }

  function decorate() {
    const panel = [...content.querySelectorAll('.panel')].find(candidate => candidate.querySelector('table'));
    if (!panel || panel.dataset.vmFriendly === '1') return;
    panel.dataset.vmFriendly = '1';
    panel.classList.add('vm-list-panel');
    panel.querySelector('table')?.classList.add('vm-friendly-table');
    panel.querySelectorAll('.actions .btn').forEach(button => button.classList.add('btn-sm'));
    addSearch(panel);

    if (!isAdmin && !document.getElementById('vmFriendlySummary')) {
      const summary = document.createElement('div');
      summary.id = 'vmFriendlySummary';
      summary.className = 'vm-summary-grid';
      panel.before(summary);
      updateSummary(summary);
    }
  }

  const observer = new MutationObserver(decorate);
  observer.observe(content, {childList:true, subtree:true});
  decorate();
})();
