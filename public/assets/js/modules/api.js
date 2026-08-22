(() => {
  const body = document.body;
  const basePath = body?.dataset.basePath || '';
  const csrf = body?.dataset.csrf || '';
  async function request(path, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (!['GET', 'HEAD'].includes(method)) {
      headers.set('Content-Type', headers.get('Content-Type') || 'application/json');
      headers.set('X-CSRF-Token', csrf);
    }
    const response = await fetch(`${basePath}${path}`, {...options, method, headers, credentials: 'same-origin'});
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data?.error?.message || `HTTP ${response.status}`);
    return data;
  }
  window.CloudPortal = window.CloudPortal || {};
  window.CloudPortal.api = {request, basePath};
})();
