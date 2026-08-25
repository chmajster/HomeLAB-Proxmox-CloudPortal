(() => {
  'use strict';
  const root = document.getElementById('openApiDocs');
  if (!root) return;
  const body = document.body;
  const base = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const h = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const methodBadge = method => `<span class="status-badge status-${method === 'get' ? 'active' : method === 'delete' ? 'error' : 'running'}">${h(method.toUpperCase())}</span>`;
  const sampleBody = operation => {
    const ref = operation.requestBody?.content?.['application/json']?.schema?.$ref;
    if (!ref) return null;
    const name = ref.split('/').pop();
    const schema = window.__openapi?.components?.schemas?.[name];
    if (!schema?.properties) return {};
    const value = {};
    for (const [key, property] of Object.entries(schema.properties)) {
      if (property.example !== undefined) value[key] = property.example;
      else if (property.default !== undefined) value[key] = property.default;
      else if (property.type === 'integer') value[key] = 1;
      else if (property.type === 'boolean') value[key] = true;
      else if (property.type === 'array') value[key] = [];
      else value[key] = '';
    }
    return value;
  };
  const curl = (method, path, operation) => {
    const resolved = path.replace(/\{[^}]+\}/g, '1');
    const lines = [`curl -sS -X ${method.toUpperCase()} \\\n  '${location.origin}${base}${resolved}' \\\n  -H 'Accept: application/json'`];
    if (!['get','head'].includes(method)) lines.push(`  -H 'X-CSRF-Token: ${csrf}'`);
    const body = sampleBody(operation);
    if (body !== null) {
      lines.push(`  -H 'Content-Type: application/json' \\\n  --data '${JSON.stringify(body)}'`);
    }
    return lines.join(' \\\n');
  };

  fetch(`${base}/api/openapi.json`, {headers:{Accept:'application/json'}})
    .then(response => { if (!response.ok) throw new Error(`HTTP ${response.status}`); return response.json(); })
    .then(spec => {
      window.__openapi = spec;
      const byTag = new Map();
      for (const [path, methods] of Object.entries(spec.paths || {})) {
        for (const [method, operation] of Object.entries(methods)) {
          const tag = operation.tags?.[0] || 'Other';
          if (!byTag.has(tag)) byTag.set(tag, []);
          byTag.get(tag).push({path, method, operation});
        }
      }
      root.innerHTML = `<section class="panel mb-4"><div class="panel-body"><div class="metric-grid"><div class="metric-card"><div class="metric-label">OpenAPI</div><div class="metric-value">${h(spec.openapi)}</div></div><div class="metric-card"><div class="metric-label">Version</div><div class="metric-value">${h(spec.info?.version)}</div></div><div class="metric-card"><div class="metric-label">Endpoints</div><div class="metric-value">${[...byTag.values()].reduce((sum, rows) => sum + rows.length, 0)}</div></div></div><p class="text-secondary mt-3 mb-0">${h(spec.info?.description || '')}</p></div></section>` + [...byTag.entries()].map(([tag, rows]) => `<section class="panel mb-4"><div class="panel-header"><h2 class="h4 mb-0">${h(tag)}</h2><span class="status-badge status-active">${rows.length}</span></div><div class="panel-body">${rows.map(({path,method,operation}) => `<details class="border-bottom py-3"><summary class="d-flex align-items-center gap-3">${methodBadge(method)}<code>${h(path)}</code><strong>${h(operation.summary || '')}</strong></summary><div class="mt-3"><p class="text-secondary">Operation ID: <code>${h(operation.operationId || '')}</code></p>${operation.parameters?.length ? `<h3 class="h6">Parameters</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Name</th><th>Location</th><th>Required</th></tr></thead><tbody>${operation.parameters.map(p=>`<tr><td><code>${h(p.name)}</code></td><td>${h(p.in)}</td><td>${p.required?'yes':'no'}</td></tr>`).join('')}</tbody></table></div>`:''}<h3 class="h6 mt-3">cURL</h3><pre class="p-3 border rounded overflow-auto"><code>${h(curl(method,path,operation))}</code></pre></div></details>`).join('')}</div></section>`).join('');
    })
    .catch(error => { root.innerHTML = `<div class="alert alert-danger"><strong>Nie udało się załadować OpenAPI.</strong><br>${h(error.message)}</div>`; });
})();
