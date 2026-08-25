(() => {
  'use strict';

  const body = document.body;
  if (body.dataset.page !== 'settings') return;
  const content = document.getElementById('appContent');
  if (!content) return;

  const basePath = body.dataset.basePath || '';
  const csrf = body.dataset.csrf || '';
  const locale = document.documentElement.lang === 'en' ? 'en' : 'pl';
  const appUrl = path => `${basePath}${path}`;
  const h = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));

  const text = locale === 'pl' ? {
    title: 'Ustawienia', dns: 'Integracja DNS', description: 'Skonfiguruj połączenie z HomeLAB-DNS. Po włączeniu integracji każda nowa VM najpierw otrzyma rekord DNS, a dopiero potem portal rozpocznie klonowanie z template.',
    enabled: 'Włącz automatyczne DNS dla nowych VM', server: 'Adres IP serwera HomeLAB-DNS', port: 'Port API', scheme: 'Protokół', token: 'Token API DNS', zone: 'Strefa forward DNS', pattern: 'Wzorzec hostname',
    tokenKeep: 'Pozostaw puste, aby zachować zapisany token.', tokenMissing: 'Token nie jest jeszcze zapisany.', zoneHelp: 'Opcjonalne tylko wtedy, gdy HomeLAB-DNS ma dokładnie jedną zarządzaną strefę forward.', patternHelp: 'Dozwolone placeholdery: {project}, {user}, {counter}, {counter:03}. Wzorzec musi zawierać licznik.',
    save: 'Zapisz ustawienia DNS', test: 'Testuj połączenie', saved: 'Ustawienia DNS zostały zapisane.', testing: 'Testuję połączenie…', saving: 'Zapisuję…',
    active: 'Aktywna', inactive: 'Wyłączona', ready: 'Gotowa', incomplete: 'Niekompletna', tokenConfigured: 'Token zapisany', tokenNotConfigured: 'Brak tokenu',
    flow: 'Kolejność tworzenia VM', step1: 'Rezerwacja adresu IP z IPAM.', step2: 'Utworzenie rekordów A i PTR w HomeLAB-DNS oraz ich weryfikacja.', step3: 'Dopiero po poprawnym DNS klonowanie VM z wybranego template Proxmox.', step4: 'Start VM i dalszy bootstrap.',
    rollback: 'Jeśli utworzenie VM nie powiedzie się przed bezpiecznym zakończeniem, rekordy DNS utworzone przez portal są usuwane w ramach rollbacku. Jeżeli nie da się potwierdzić stanu VM, zasoby są zachowywane do reconciliacji.',
    reverse: 'HomeLAB-DNS musi mieć zarządzaną strefę reverse pasującą do adresu IP VM, ponieważ portal tworzy również PTR.',
    testOk: 'Połączenie z HomeLAB-DNS działa.', forward: 'Strefa forward', reverseZones: 'Strefy reverse', error: 'Błąd', signout: 'Nie udało się wylogować.'
  } : {
    title: 'Settings', dns: 'DNS integration', description: 'Configure HomeLAB-DNS. When enabled, every new VM gets DNS records before the portal starts cloning the Proxmox template.',
    enabled: 'Enable automatic DNS for new VMs', server: 'HomeLAB-DNS server IP', port: 'API port', scheme: 'Protocol', token: 'DNS API token', zone: 'Forward DNS zone', pattern: 'Hostname pattern',
    tokenKeep: 'Leave blank to keep the stored token.', tokenMissing: 'No token has been stored yet.', zoneHelp: 'Optional only when HomeLAB-DNS has exactly one managed forward zone.', patternHelp: 'Supported placeholders: {project}, {user}, {counter}, {counter:03}. The pattern must include a counter.',
    save: 'Save DNS settings', test: 'Test connection', saved: 'DNS settings saved.', testing: 'Testing connection…', saving: 'Saving…',
    active: 'Active', inactive: 'Disabled', ready: 'Ready', incomplete: 'Incomplete', tokenConfigured: 'Token stored', tokenNotConfigured: 'No token',
    flow: 'VM creation order', step1: 'Reserve an IP address in IPAM.', step2: 'Create A and PTR records in HomeLAB-DNS and verify them.', step3: 'Only after DNS succeeds, clone the VM from the selected Proxmox template.', step4: 'Start the VM and continue bootstrap.',
    rollback: 'If VM creation fails before safe completion, DNS records created by the portal are removed during rollback. If VM state cannot be proven, resources are retained for reconciliation.',
    reverse: 'HomeLAB-DNS must have a managed reverse zone matching the VM IP because the portal also creates PTR records.',
    testOk: 'HomeLAB-DNS connection is working.', forward: 'Forward zone', reverseZones: 'Reverse zones', error: 'Error', signout: 'Sign out failed.'
  };

  async function api(path, options = {}) {
    const init = {...options, headers: {'Accept':'application/json', ...(options.body ? {'Content-Type':'application/json'} : {}), ...(['GET','HEAD'].includes(options.method || 'GET') ? {} : {'X-CSRF-Token':csrf}), ...(options.headers || {})}};
    if (options.body && typeof options.body !== 'string') init.body = JSON.stringify(options.body);
    const response = await fetch(appUrl(path), init);
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  function toast(message, kind = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const element = document.createElement('div');
    element.className = `toast align-items-center text-bg-${kind} border-0`;
    element.innerHTML = `<div class="d-flex"><div class="toast-body">${h(message)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    container.append(element);
    const instance = new bootstrap.Toast(element, {delay: 5000});
    element.addEventListener('hidden.bs.toast', () => element.remove());
    instance.show();
  }

  function setupShell() {
    document.getElementById('pageTitle').textContent = text.title;
    const menuButton = document.getElementById('menuButton');
    const closeMenu = () => { body.classList.remove('menu-open'); menuButton?.setAttribute('aria-expanded', 'false'); };
    menuButton?.addEventListener('click', () => { const open = body.classList.toggle('menu-open'); menuButton.setAttribute('aria-expanded', String(open)); });
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
      try {
        await api('/api/v1/logout', {method:'POST'});
        location.assign(appUrl('/login'));
      } catch (error) {
        toast(error.message || text.signout, 'danger');
      }
    });
    document.querySelector('[data-dismiss-checklist]')?.addEventListener('click', () => document.getElementById('firstRunChecklist')?.remove());
  }

  function statusBadge(value, ok) {
    return `<span class="status-badge status-${ok ? 'active' : 'stopped'}">${h(value)}</span>`;
  }

  function render(config) {
    const enabled = config.enabled === true;
    const configured = config.configured === true;
    const tokenConfigured = config.token_configured === true;
    content.innerHTML = `
      <div class="content-grid">
        <section class="panel">
          <div class="panel-header">
            <div><p class="eyebrow mb-1">HomeLAB-DNS</p><h2 class="h5 mb-0">${h(text.dns)}</h2></div>
            <div class="d-flex gap-2">${statusBadge(enabled ? text.active : text.inactive, enabled)}${statusBadge(configured ? text.ready : text.incomplete, configured)}</div>
          </div>
          <div class="panel-body">
            <p class="text-secondary">${h(text.description)}</p>
            <form id="dnsSettingsForm" class="form-grid">
              <label class="form-check span-3"><input class="form-check-input" type="checkbox" name="enabled" ${enabled ? 'checked' : ''}> <span class="form-check-label">${h(text.enabled)}</span></label>
              <div><label class="form-label" for="dnsServerIp">${h(text.server)}</label><input id="dnsServerIp" name="server_ip" class="form-control" inputmode="decimal" placeholder="10.0.0.20" value="${h(config.server_ip || '')}" required></div>
              <div><label class="form-label" for="dnsPort">${h(text.port)}</label><input id="dnsPort" name="port" type="number" min="1" max="65535" class="form-control" value="${h(config.port || 81)}" required></div>
              <div><label class="form-label" for="dnsScheme">${h(text.scheme)}</label><select id="dnsScheme" name="scheme" class="form-select"><option value="http" ${config.scheme === 'http' ? 'selected' : ''}>HTTP</option><option value="https" ${config.scheme === 'https' ? 'selected' : ''}>HTTPS</option></select></div>
              <div class="span-3"><label class="form-label" for="dnsApiToken">${h(text.token)}</label><input id="dnsApiToken" name="api_token" type="password" class="form-control" autocomplete="new-password" placeholder="${tokenConfigured ? '••••••••••••' : ''}"><div class="form-text">${h(tokenConfigured ? text.tokenKeep : text.tokenMissing)} ${statusBadge(tokenConfigured ? text.tokenConfigured : text.tokenNotConfigured, tokenConfigured)}</div></div>
              <div><label class="form-label" for="dnsForwardZone">${h(text.zone)}</label><input id="dnsForwardZone" name="forward_zone" class="form-control" placeholder="lab.example.local" value="${h(config.forward_zone || '')}"><div class="form-text">${h(text.zoneHelp)}</div></div>
              <div class="span-2"><label class="form-label" for="hostnamePattern">${h(text.pattern)}</label><input id="hostnamePattern" name="hostname_pattern" class="form-control" maxlength="100" value="${h(config.hostname_pattern || 'vm-{project}-{counter}')}" required><div class="form-text">${h(text.patternHelp)}</div></div>
              <div class="span-3 d-flex flex-wrap gap-2 mt-2"><button type="button" class="btn btn-outline-primary" id="dnsTestButton">${h(text.test)}</button><button type="submit" class="btn btn-primary" id="dnsSaveButton">${h(text.save)}</button></div>
            </form>
            <div id="dnsResult" class="mt-3"></div>
          </div>
        </section>
        <section class="panel">
          <div class="panel-header"><h2 class="h5 mb-0">${h(text.flow)}</h2></div>
          <div class="panel-body">
            <ol class="mb-3"><li>${h(text.step1)}</li><li>${h(text.step2)}</li><li>${h(text.step3)}</li><li>${h(text.step4)}</li></ol>
            <div class="alert alert-info">${h(text.reverse)}</div>
            <div class="alert alert-secondary mb-0">${h(text.rollback)}</div>
          </div>
        </section>
      </div>`;

    const form = document.getElementById('dnsSettingsForm');
    const payload = () => {
      const data = new FormData(form);
      return {
        enabled: form.elements.enabled.checked,
        server_ip: String(data.get('server_ip') || '').trim(),
        port: Number(data.get('port') || 81),
        scheme: String(data.get('scheme') || 'http'),
        api_token: String(data.get('api_token') || ''),
        forward_zone: String(data.get('forward_zone') || '').trim(),
        hostname_pattern: String(data.get('hostname_pattern') || '').trim(),
      };
    };

    document.getElementById('dnsTestButton')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      const original = button.textContent;
      button.disabled = true;
      button.textContent = text.testing;
      const result = document.getElementById('dnsResult');
      try {
        const tested = await api('/api/v1/admin/settings/dns/test', {method:'POST', body:payload()});
        result.innerHTML = `<div class="alert alert-success"><strong>${h(text.testOk)}</strong><div class="small mt-1">${h(text.forward)}: ${h(tested.forward_zone || '—')} · ${h(text.reverseZones)}: ${h((tested.reverse_zones || []).join(', ') || '—')}</div></div>`;
      } catch (error) {
        result.innerHTML = `<div class="alert alert-danger"><strong>${h(text.error)}:</strong> ${h(error.message || error)}</div>`;
      } finally {
        button.disabled = false;
        button.textContent = original;
      }
    });

    form.addEventListener('submit', async event => {
      event.preventDefault();
      const button = document.getElementById('dnsSaveButton');
      const original = button.textContent;
      button.disabled = true;
      button.textContent = text.saving;
      try {
        const saved = await api('/api/v1/admin/settings/dns', {method:'PUT', body:payload()});
        toast(text.saved);
        render(saved);
      } catch (error) {
        document.getElementById('dnsResult').innerHTML = `<div class="alert alert-danger"><strong>${h(text.error)}:</strong> ${h(error.message || error)}</div>`;
        button.disabled = false;
        button.textContent = original;
      }
    });
  }

  setupShell();
  api('/api/v1/admin/settings/dns').then(render).catch(error => {
    content.innerHTML = `<div class="alert alert-danger"><strong>${h(text.error)}:</strong> ${h(error.message || error)}</div>`;
  });
})();
