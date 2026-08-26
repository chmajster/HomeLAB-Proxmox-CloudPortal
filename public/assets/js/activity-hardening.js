(() => {
  'use strict';
  const body = document.body;
  if (body.dataset.page !== 'activity' || body.dataset.admin !== '1') return;
  const content = document.getElementById('appContent');
  const api = window.CloudPortal?.api;
  const jobs = window.CloudPortal?.jobs;
  if (!content || !api || !jobs) return;

  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  let loading = false;
  let lastLoad = 0;
  let cachedIncidents = [];

  function enhanceJobRows() {
    content.querySelectorAll('table tbody tr').forEach(row => {
      const cells = row.querySelectorAll('td');
      if (cells.length < 5) return;
      const status = (cells[3]?.textContent || '').trim().toLowerCase().replace(/\s+/g, '_');
      if (!['failed', 'dead_letter'].some(value => status.includes(value))) return;
      const publicId = row.querySelector('.resource-meta')?.textContent?.trim();
      if (!publicId || row.querySelector('[data-retry-job]')) return;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-sm btn-outline-warning ms-2';
      button.dataset.retryJob = publicId;
      button.textContent = 'Retry';
      cells[4].append(button);
    });
  }

  function ensureReconciliationPanel() {
    if (content.querySelector('#reconciliationPanel')) return;
    const panel = document.createElement('section');
    panel.id = 'reconciliationPanel';
    panel.className = 'panel mb-3';
    panel.innerHTML = `<div class="panel-header"><div><h2 class="h5 mb-1">Reconciliation</h2><div class="resource-meta">Diagnostyka różnic Portal ↔ Proxmox ↔ IPAM. Skan nie usuwa zasobów automatycznie.</div></div><button class="btn btn-sm btn-outline-primary" type="button" data-run-reconciliation>Uruchom skan</button></div><div class="panel-body" data-reconciliation-body><span class="text-secondary">Ładowanie incydentów…</span></div>`;
    content.prepend(panel);
    renderIncidents();
    if (Date.now() - lastLoad > 5000) loadIncidents();
  }

  function renderIncidents() {
    const host = content.querySelector('[data-reconciliation-body]');
    if (!host) return;
    if (!cachedIncidents.length) {
      host.innerHTML = '<div class="alert alert-success mb-0">Brak otwartych incydentów reconciliation.</div>';
      return;
    }
    host.innerHTML = `<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Severity</th><th>Typ</th><th>Zasób</th><th>Wykryto</th><th></th></tr></thead><tbody>${cachedIncidents.map(item => `<tr><td><span class="status-badge status-${item.severity === 'critical' ? 'error' : item.severity === 'warning' ? 'pending' : 'unknown'}">${esc(item.severity)}</span></td><td><code>${esc(item.incident_type)}</code></td><td>${item.virtual_machine_id ? `VM #${Number(item.virtual_machine_id)}` : item.job_id ? `Job #${Number(item.job_id)}` : '—'}<div class="resource-meta">${esc(JSON.stringify(item.details || {}))}</div></td><td>${esc(item.last_seen_at || item.detected_at || '')}</td><td class="text-nowrap"><button class="btn btn-sm btn-outline-success" data-close-incident="${Number(item.id)}" data-status="resolved">Resolved</button> <button class="btn btn-sm btn-outline-secondary" data-close-incident="${Number(item.id)}" data-status="ignored">Ignore</button></td></tr>`).join('')}</tbody></table></div>`;
  }

  async function loadIncidents() {
    if (loading) return;
    loading = true;
    try {
      const response = await api.request('/api/v1/admin/reconciliation/incidents?status=open');
      cachedIncidents = response.data || [];
      lastLoad = Date.now();
      renderIncidents();
    } catch (error) {
      const host = content.querySelector('[data-reconciliation-body]');
      if (host) host.innerHTML = `<div class="alert alert-warning mb-0">${esc(error.message || error)}</div>`;
    } finally {
      loading = false;
    }
  }

  async function runScan(button) {
    button.disabled = true;
    try {
      await api.request('/api/v1/admin/reconciliation/scan', {method: 'POST', body: '{}'});
      await loadIncidents();
    } finally {
      button.disabled = false;
    }
  }

  async function closeIncident(button) {
    button.disabled = true;
    try {
      await api.request(`/api/v1/admin/reconciliation/incidents/${encodeURIComponent(button.dataset.closeIncident)}`, {method: 'POST', body: JSON.stringify({status: button.dataset.status})});
      await loadIncidents();
    } finally {
      button.disabled = false;
    }
  }

  content.addEventListener('click', async event => {
    const retry = event.target.closest('[data-retry-job]');
    if (retry) {
      retry.disabled = true;
      try {
        await jobs.retry(retry.dataset.retryJob);
        document.getElementById('refreshJobs')?.click();
      } catch (error) {
        retry.disabled = false;
        window.alert(error.message || String(error));
      }
      return;
    }
    const scan = event.target.closest('[data-run-reconciliation]');
    if (scan) {
      try { await runScan(scan); } catch (error) { window.alert(error.message || String(error)); }
      return;
    }
    const close = event.target.closest('[data-close-incident]');
    if (close) {
      try { await closeIncident(close); } catch (error) { window.alert(error.message || String(error)); }
    }
  });

  let queued = false;
  const observer = new MutationObserver(() => {
    if (queued) return;
    queued = true;
    queueMicrotask(() => {
      queued = false;
      enhanceJobRows();
      ensureReconciliationPanel();
    });
  });
  observer.observe(content, {childList: true, subtree: true});
  enhanceJobRows();
  ensureReconciliationPanel();
})();
