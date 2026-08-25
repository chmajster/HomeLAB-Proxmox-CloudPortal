(() => {
  'use strict';

  const body = document.body;
  if (!body || body.dataset.page !== 'settings' || body.dataset.admin !== '1') return;
  const content = document.getElementById('appContent');
  if (!content) return;

  const basePath = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const h = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  let loading = false;

  async function api(path, options = {}) {
    const init = {...options, headers:{Accept:'application/json', ...(['GET','HEAD'].includes(options.method || 'GET') ? {} : {'X-CSRF-Token':csrf}), ...(options.body ? {'Content-Type':'application/json'} : {})}};
    if (options.body && typeof options.body !== 'string') init.body = JSON.stringify(options.body);
    const response = await fetch(appUrl(path), init);
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  function toast(message, kind = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container || !window.bootstrap?.Toast) return;
    const element = document.createElement('div');
    element.className = `toast align-items-center text-bg-${kind} border-0`;
    element.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    element.querySelector('.toast-body').textContent = message;
    container.append(element);
    const instance = new bootstrap.Toast(element, {delay:4500});
    element.addEventListener('hidden.bs.toast', () => element.remove());
    instance.show();
  }

  function settingValue(rows, key) {
    const row = rows.find(item => item.setting_key === key);
    if (!row) return '';
    try {
      const value = JSON.parse(String(row.value ?? '""'));
      return typeof value === 'string' ? value : '';
    } catch {
      return '';
    }
  }

  async function enhance() {
    if (loading || document.getElementById('portalHostnamePrefixPanel')) return;
    const grid = content.querySelector('.content-grid');
    if (!grid) return;
    loading = true;
    try {
      const rows = await api('/api/v1/admin/settings');
      const prefix = settingValue(Array.isArray(rows) ? rows : [], 'hostname_generator.prefix');
      const panel = document.createElement('section');
      panel.id = 'portalHostnamePrefixPanel';
      panel.className = 'panel';
      panel.innerHTML = `<div class="panel-header"><div><p class="eyebrow mb-1">Cloud Portal</p><h2 class="h5 mb-0">${locale === 'pl' ? 'Nazewnictwo maszyn wirtualnych' : 'Virtual machine naming'}</h2></div></div><div class="panel-body"><p class="text-secondary">${locale === 'pl' ? 'Opcjonalny prefiks jest dopisywany na początku automatycznie generowanego hostname nowych VM.' : 'An optional prefix is prepended to automatically generated hostnames for new VMs.'}</p><form id="hostnamePrefixForm"><label class="form-label" for="hostnamePrefix">${locale === 'pl' ? 'Prefiks hostname' : 'Hostname prefix'}</label><input id="hostnamePrefix" name="prefix" class="form-control" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,31}" value="${h(prefix)}" placeholder="lab-"><div class="form-text">${locale === 'pl' ? 'Np. „lab-”. Jeśli chcesz separator między prefiksem i wygenerowaną nazwą, dodaj „-” na końcu. Puste pole wyłącza prefiks.' : 'Example: “lab-”. Add a trailing “-” if you want a separator. Leave empty to disable the prefix.'}</div><div class="alert alert-info mt-3 mb-3"><strong>${locale === 'pl' ? 'Podgląd:' : 'Preview:'}</strong> <code id="hostnamePrefixPreview"></code></div><button type="submit" class="btn btn-primary">${locale === 'pl' ? 'Zapisz prefiks' : 'Save prefix'}</button></form></div>`;
      grid.append(panel);

      const input = panel.querySelector('#hostnamePrefix');
      const preview = panel.querySelector('#hostnamePrefixPreview');
      const patternInput = document.getElementById('hostnamePattern');
      const updatePreview = () => {
        const pattern = String(patternInput?.value || 'vm-{project}-{counter}').trim();
        preview.textContent = `${String(input.value || '').trim().toLowerCase()}${pattern}`;
      };
      input.addEventListener('input', updatePreview);
      patternInput?.addEventListener('input', updatePreview);
      updatePreview();

      panel.querySelector('#hostnamePrefixForm').addEventListener('submit', async event => {
        event.preventDefault();
        const submit = event.currentTarget.querySelector('button[type="submit"]');
        const value = String(input.value || '').trim().toLowerCase();
        if (value && !/^[a-z0-9][a-z0-9-]{0,31}$/.test(value)) {
          toast(locale === 'pl' ? 'Prefiks może zawierać tylko litery, cyfry i myślniki (maks. 32 znaki).' : 'Prefix may contain only letters, digits and hyphens (max 32 characters).', 'danger');
          return;
        }
        submit.disabled = true;
        try {
          await api('/api/v1/admin/settings', {method:'POST', body:{key:'hostname_generator.prefix', value, is_public:false}});
          input.value = value;
          updatePreview();
          toast(locale === 'pl' ? 'Prefiks hostname został zapisany.' : 'Hostname prefix saved.');
        } catch (error) {
          toast(error.message || String(error), 'danger');
        } finally {
          submit.disabled = false;
        }
      });
    } catch (error) {
      console.error(error);
    } finally {
      loading = false;
    }
  }

  const observer = new MutationObserver(enhance);
  observer.observe(content, {childList:true, subtree:true});
  enhance();
})();
