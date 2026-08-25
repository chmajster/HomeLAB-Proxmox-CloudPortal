(() => {
  'use strict';

  const form = document.getElementById('installerForm');
  if (!form) return;
  const base = document.body.dataset.basePath || '';
  const csrf = form.elements._csrf?.value || '';
  const url = path => `${base}${path}`;
  const escape = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
  const icon = name => `<svg class="ui-icon" aria-hidden="true"><use href="${url('/assets/icons.svg')}#i-${name}"></use></svg>`;

  async function post(path, data = {}) {
    const response = await fetch(url(path), {
      method: 'POST',
      headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify({...data, _csrf: csrf}),
    });
    let payload;
    try { payload = await response.json(); } catch { throw new Error(`Serwer zwrócił nieprawidłową odpowiedź (HTTP ${response.status}).`); }
    if (!response.ok) throw new Error(payload.error?.message || `HTTP ${response.status}`);
    return payload.data;
  }

  function values() {
    const result = Object.fromEntries(new FormData(form));
    form.querySelectorAll('input[type="checkbox"]').forEach(input => result[input.name] = input.checked);
    return result;
  }

  function busy(button, active, label = 'Testowanie…') {
    if (!button) return;
    if (active) button.dataset.originalHtml = button.innerHTML;
    button.disabled = active;
    button.classList.toggle('is-loading', active);
    button.innerHTML = active ? label : (button.dataset.originalHtml || button.innerHTML);
  }

  function showResult(element, kind, html) {
    element.className = `connection-result result-${kind}`;
    element.innerHTML = html;
  }

  function prepareDatabaseStep() {
    if (form.dataset.step !== '2') return;

    const heading = document.querySelector('.installer-header h1');
    if (heading) heading.textContent = 'Konfiguracja bazy danych';

    const intro = form.querySelector('.section-intro');
    if (intro) {
      intro.textContent = 'Podaj dane serwera MariaDB/MySQL i nazwę bazy. Domyślnie instalator utworzy wskazaną bazę, jeśli jeszcze nie istnieje, a następnie utworzy wymagane tabele i uruchomi migracje.';
    }

    const databaseName = document.getElementById('db_name');
    const databaseNameLabel = form.querySelector('label[for="db_name"]');
    if (databaseNameLabel) databaseNameLabel.textContent = 'Nazwa bazy danych';
    if (databaseName) databaseName.placeholder = 'cloudportal';

    const grid = databaseName?.closest('.form-grid');
    if (!grid || document.getElementById('createDatabaseIfMissing')) return;

    const row = document.createElement('label');
    row.className = 'check-row database-create-row';
    row.innerHTML = '<input type="hidden" name="create_database_if_missing" value="0"><input type="checkbox" value="1" id="createDatabaseIfMissing" name="create_database_if_missing" checked> <span><strong>Utwórz bazę danych, jeśli nie istnieje</strong><br>Jeśli podana nazwa nie istnieje, instalator wykona <code>CREATE DATABASE</code> z kodowaniem UTF-8. Użytkownik MySQL/MariaDB musi mieć uprawnienie do utworzenia bazy.</span>';
    grid.insertAdjacentElement('afterend', row);
  }

  prepareDatabaseStep();

  const dbButton = document.getElementById('testDatabase');
  dbButton?.addEventListener('click', async () => {
    const output = document.getElementById('databaseResult');
    busy(dbButton, true, 'Sprawdzanie…');
    showResult(output, 'loading', 'Sprawdzanie serwera i bazy danych…');
    try {
      const data = await post('/install/test/database', values());
      const created = data.database_created ? '<p><strong>Baza nie istniała i została utworzona automatycznie.</strong></p>' : '<p>Baza danych już istnieje — zostanie użyta bez ponownego tworzenia.</p>';
      const warning = data.warning ? `<p class="result-warning">${escape(data.warning)}</p>` : '';
      showResult(output, 'success', `<strong>Połączenie i dostęp do bazy są prawidłowe.</strong>${created}<dl><div><dt>Serwer</dt><dd>${escape(data.server_version)}</dd></div><div><dt>Kodowanie</dt><dd>${escape(data.charset)}</dd></div><div><dt>Sortowanie</dt><dd>${escape(data.collation)}</dd></div><div><dt>Liczba tabel</dt><dd>${escape(data.table_count)}</dd></div></dl>${warning}`);
    } catch (error) {
      showResult(output, 'error', `<strong>Sprawdzenie bazy nie powiodło się.</strong><p>${escape(error.message)}</p>`);
    } finally { busy(dbButton, false); }
  });

  const testAdministrator = document.getElementById('useTestAdministrator');
  function updateAdministratorMode() {
    const fields = document.getElementById('administratorFields');
    if (!fields || !testAdministrator) return;
    const skipped = testAdministrator.checked;
    fields.classList.toggle('is-skipped', skipped);
    fields.setAttribute('aria-disabled', String(skipped));
    testAdministrator.setAttribute('aria-expanded', String(!skipped));
    fields.querySelectorAll('input, select, textarea').forEach(input => input.disabled = skipped);
  }
  testAdministrator?.addEventListener('change', updateAdministratorMode);
  updateAdministratorMode();

  const proxmoxSkip = document.getElementById('skipProxmox');
  function updateProxmoxSkip() {
    const fields = document.getElementById('proxmoxFields');
    if (!fields || !proxmoxSkip) return;
    fields.classList.toggle('is-skipped', proxmoxSkip.checked);
    fields.querySelectorAll('input[required]').forEach(input => input.disabled = proxmoxSkip.checked);
  }
  proxmoxSkip?.addEventListener('change', updateProxmoxSkip);
  updateProxmoxSkip();

  const proxmoxButton = document.getElementById('testProxmox');
  proxmoxButton?.addEventListener('click', async () => {
    const output = document.getElementById('proxmoxResult');
    busy(proxmoxButton, true);
    showResult(output, 'loading', 'Łączenie z API Proxmox…');
    try {
      const data = await post('/install/test/proxmox', values());
      showResult(output, 'success', `<strong>Połączenie działa.</strong><dl><div><dt>Klaster</dt><dd>${escape(data.cluster)}</dd></div><div><dt>Węzły</dt><dd>${escape(data.nodes)}</dd></div><div><dt>Proxmox VE</dt><dd>${escape(data.version)}</dd></div><div><dt>Storage</dt><dd>${escape(data.storages)}</dd></div></dl>`);
    } catch (error) {
      showResult(output, 'error', `<strong>Test nie powiódł się.</strong><p>${escape(error.message)}</p>`);
    } finally { busy(proxmoxButton, false); }
  });

  const recheck = document.getElementById('recheckRequirements');
  recheck?.addEventListener('click', async () => {
    busy(recheck, true, 'Sprawdzanie…');
    try {
      const data = await post('/install/requirements');
      document.getElementById('requirementsBody').innerHTML = data.checks.map(check => `<tr><td>${escape(check.name)}</td><td>${escape(check.required)}</td><td>${escape(check.detected)}</td><td><span class="requirement-status status-${escape(check.status)}">${escape(check.status.toUpperCase())}</span></td></tr>`).join('');
      document.getElementById('continueButton').disabled = !data.can_continue;
    } catch (error) {
      alert(error.message);
    } finally { busy(recheck, false); }
  });

  const start = document.getElementById('startInstallation');
  start?.addEventListener('click', async () => {
    const items = [...document.querySelectorAll('#installationStages li')];
    const errorBox = document.getElementById('installationError');
    const setStageIcon = (item, name) => { item.querySelector('.stage-icon').innerHTML = icon(name); };
    errorBox.classList.add('d-none');
    items.forEach(item => { item.dataset.status = 'pending'; setStageIcon(item, 'circle'); item.querySelector('strong').textContent = 'Oczekuje'; });
    busy(start, true, 'Instalowanie…');
    if (items[0]) { items[0].dataset.status = 'running'; setStageIcon(items[0], 'loader'); items[0].querySelector('strong').textContent = 'Trwa'; }
    try {
      const data = await post('/install/finalize');
      data.stages.forEach((stage, index) => {
        if (!items[index]) return;
        items[index].dataset.status = stage.status;
        setStageIcon(items[index], stage.status === 'completed' ? 'check' : 'x');
        items[index].querySelector('strong').textContent = stage.status === 'completed' ? 'Gotowe' : 'Błąd';
      });
      items.forEach(item => {
        if (item.dataset.status === 'pending') { item.dataset.status = 'completed'; setStageIcon(item, 'check'); item.querySelector('strong').textContent = 'Gotowe'; }
      });
      start.innerHTML = `${icon('check')}Instalacja zakończona`;
      window.setTimeout(() => location.assign(data.redirect), 500);
    } catch (error) {
      const running = items.find(item => item.dataset.status === 'running');
      if (running) { running.dataset.status = 'failed'; setStageIcon(running, 'x'); running.querySelector('strong').textContent = 'Błąd'; }
      errorBox.textContent = `Instalacja nie powiodła się: ${error.message} Możesz bezpiecznie spróbować ponownie.`;
      errorBox.classList.remove('d-none');
      busy(start, false);
      start.innerHTML = `${icon('refresh')}Ponów instalację`;
    }
  });
})();
