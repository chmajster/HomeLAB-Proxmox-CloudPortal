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
      intro.textContent = 'Podaj adres serwera MariaDB/MySQL i dane logowania. Nazwa bazy jest opcjonalna: puste pole oznacza „cloudportal” po kliknięciu „Kontynuuj”. „Testuj połączenie” jest niedestrukcyjny. Opcjonalne wyczyszczenie bazy następuje wyłącznie po „Kontynuuj”.';
    }

    const databaseName = document.getElementById('db_name');
    const databaseNameLabel = form.querySelector('label[for="db_name"]');
    if (databaseNameLabel) databaseNameLabel.textContent = 'Nazwa bazy danych (opcjonalnie)';
    if (databaseName) {
      databaseName.placeholder = 'cloudportal';
      databaseName.required = false;
      databaseName.setAttribute('aria-description', 'Pozostaw puste, aby użyć domyślnej nazwy cloudportal po kliknięciu Kontynuuj.');
    }

    const grid = databaseName?.closest('.form-grid');
    if (!grid || document.getElementById('createDatabaseIfMissing')) return;

    const row = document.createElement('label');
    row.className = 'check-row database-create-row';
    row.innerHTML = '<input type="hidden" name="create_database_if_missing" value="0"><input type="checkbox" value="1" id="createDatabaseIfMissing" name="create_database_if_missing" checked> <span><strong>Utwórz bazę danych, jeśli nie istnieje</strong><br>Opcja jest domyślnie włączona. „Testuj połączenie” nie tworzy bazy; utworzenie następuje dopiero po „Kontynuuj”.</span>';
    grid.insertAdjacentElement('afterend', row);
  }

  prepareDatabaseStep();

  const resetDatabase = document.getElementById('resetDatabase');
  const confirmExistingDatabase = document.getElementById('confirmExistingDatabase');
  function updateDatabaseResetMode() {
    if (!resetDatabase || !confirmExistingDatabase) return;
    if (resetDatabase.checked) confirmExistingDatabase.checked = false;
    confirmExistingDatabase.disabled = resetDatabase.checked;
    confirmExistingDatabase.closest('.check-row')?.classList.toggle('is-skipped', resetDatabase.checked);
  }
  resetDatabase?.addEventListener('change', updateDatabaseResetMode);
  updateDatabaseResetMode();

  const dbButton = document.getElementById('testDatabase');
  dbButton?.addEventListener('click', async () => {
    const output = document.getElementById('databaseResult');
    const createIfMissing = document.getElementById('createDatabaseIfMissing')?.checked ?? true;
    const databaseName = (document.getElementById('db_name')?.value || '').trim();
    const databaseNameBlank = databaseName === '';
    const serverOnly = createIfMissing || databaseNameBlank;
    const resetRequested = document.getElementById('resetDatabase')?.checked ?? false;

    busy(dbButton, true, 'Sprawdzanie…');
    showResult(output, 'loading', serverOnly
      ? 'Sprawdzanie połączenia z serwerem MariaDB/MySQL…'
      : 'Sprawdzanie połączenia z wybraną bazą danych…');
    try {
      const request = values();
      request.connection_test_only = true;
      const data = await post('/install/test/database', request);

      if (data.database_check_skipped) {
        let explanation = databaseNameBlank
          ? 'Pole „Nazwa bazy danych” jest puste, dlatego test celowo sprawdził tylko serwer i dane logowania. Po kliknięciu „Kontynuuj” instalator użyje nazwy <code>cloudportal</code> i utworzy tę bazę, jeśli nie istnieje.'
          : `Ponieważ zaznaczono automatyczne tworzenie bazy, test celowo nie sprawdza, czy baza <code>${escape(data.database_name)}</code> już istnieje. Baza zostanie sprawdzona i w razie potrzeby utworzona po kliknięciu „Kontynuuj”.`;
        if (resetRequested) explanation += ' Zaznaczone czyszczenie bazy również nie jest wykonywane podczas testu — uruchomi się dopiero po „Kontynuuj”.';
        showResult(output, 'success', `<strong>Połączenie z serwerem MariaDB/MySQL działa.</strong><p>Login i hasło zostały zaakceptowane. ${explanation}</p><dl><div><dt>Serwer</dt><dd>${escape(data.server_version)}</dd></div><div><dt>Zakres testu</dt><dd>serwer + dane logowania</dd></div></dl>`);
        return;
      }

      const warning = data.warning ? `<p class="result-warning">${escape(data.warning)}</p>` : '';
      const resetNote = resetRequested ? '<p class="result-warning"><strong>Czyszczenie nie zostało jeszcze wykonane.</strong> Wszystkie tabele i widoki zostaną usunięte dopiero po kliknięciu „Kontynuuj”.</p>' : '';
      showResult(output, 'success', `<strong>Połączenie z wybraną bazą danych działa.</strong><p>Baza istnieje i użytkownik może tworzyć w niej tabele.</p>${resetNote}<dl><div><dt>Serwer</dt><dd>${escape(data.server_version)}</dd></div><div><dt>Kodowanie</dt><dd>${escape(data.charset)}</dd></div><div><dt>Sortowanie</dt><dd>${escape(data.collation)}</dd></div><div><dt>Liczba tabel</dt><dd>${escape(data.table_count)}</dd></div></dl>${warning}`);
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

  function prepareProxmoxStep() {
    if (form.dataset.step !== '5') return;
    form.action = url('/install/proxmox');

    const intro = form.querySelector('.section-intro');
    if (intro) {
      intro.textContent = 'Możesz użyć istniejącego tokenu API albo podać login i hasło. W trybie login/hasło przycisk testu tylko sprawdza uwierzytelnienie. Dedykowany token API zostanie utworzony dopiero po kliknięciu „Kontynuuj”, a hasło nie będzie zapisane w konfiguracji portalu.';
    }

    const tokenId = document.getElementById('api_token_id');
    const tokenSecret = document.getElementById('api_token_secret');
    const grid = tokenId?.closest('.form-grid');
    if (!grid || document.getElementById('proxmoxAuthMode')) return;

    const selector = document.createElement('div');
    selector.id = 'proxmoxAuthMode';
    selector.className = 'check-row';
    selector.innerHTML = '<span><strong>Sposób uwierzytelnienia</strong><br><label><input type="radio" name="proxmox_auth_mode" value="token" checked> Mam już token API</label><br><label><input type="radio" name="proxmox_auth_mode" value="password"> Login i hasło — utwórz token automatycznie</label></span>';
    grid.insertAdjacentElement('beforebegin', selector);

    const usernameField = document.createElement('div');
    usernameField.className = 'field';
    usernameField.dataset.proxmoxPasswordField = '1';
    usernameField.hidden = true;
    usernameField.innerHTML = '<label for="proxmox_username">Login Proxmox</label><input id="proxmox_username" name="proxmox_username" placeholder="root@pam" autocomplete="username"><small>Najlepiej podaj pełny login z realm, np. <code>root@pam</code>.</small>';

    const passwordField = document.createElement('div');
    passwordField.className = 'field';
    passwordField.dataset.proxmoxPasswordField = '1';
    passwordField.hidden = true;
    passwordField.innerHTML = '<label for="proxmox_password">Hasło Proxmox</label><input id="proxmox_password" name="proxmox_password" type="password" autocomplete="current-password"><small>Hasło służy wyłącznie do utworzenia tokenu i nie jest zapisywane.</small>';

    const tokenNameField = document.createElement('div');
    tokenNameField.className = 'field span-2';
    tokenNameField.dataset.proxmoxPasswordField = '1';
    tokenNameField.hidden = true;
    tokenNameField.innerHTML = '<label for="api_token_name">Nazwa tworzonego tokenu</label><input id="api_token_name" name="api_token_name" value="cloudportal" maxlength="64"><small>Instalator utworzy token dla podanego użytkownika. Jeśli token o tej nazwie już istnieje, wybierz inną nazwę.</small>';

    grid.append(usernameField, passwordField, tokenNameField);
    tokenId.closest('.field')?.setAttribute('data-proxmox-token-field', '1');
    tokenSecret.closest('.field')?.setAttribute('data-proxmox-token-field', '1');
  }

  prepareProxmoxStep();

  const proxmoxSkip = document.getElementById('skipProxmox');
  const proxmoxAuthRadios = [...form.querySelectorAll('input[name="proxmox_auth_mode"]')];
  function updateProxmoxMode() {
    const fields = document.getElementById('proxmoxFields');
    if (!fields || !proxmoxSkip) return;
    const skipped = proxmoxSkip.checked;
    const mode = form.querySelector('input[name="proxmox_auth_mode"]:checked')?.value || 'token';
    const passwordMode = mode === 'password';

    fields.classList.toggle('is-skipped', skipped);
    ['connection_name', 'hostname', 'port', 'realm'].forEach(id => {
      const input = document.getElementById(id);
      if (!input) return;
      input.disabled = skipped;
      input.required = !skipped;
    });

    fields.querySelectorAll('[data-proxmox-token-field]').forEach(wrapper => {
      wrapper.hidden = passwordMode;
      wrapper.querySelectorAll('input').forEach(input => {
        input.disabled = skipped || passwordMode;
        input.required = !skipped && !passwordMode;
      });
    });
    fields.querySelectorAll('[data-proxmox-password-field]').forEach(wrapper => {
      wrapper.hidden = !passwordMode;
      wrapper.querySelectorAll('input').forEach(input => {
        input.disabled = skipped || !passwordMode;
        input.required = !skipped && passwordMode;
      });
    });

    const verify = document.getElementById('verify_ssl');
    if (verify) verify.disabled = skipped;
    const testButton = document.getElementById('testProxmox');
    if (testButton && !testButton.classList.contains('is-loading')) testButton.disabled = skipped;
  }
  proxmoxSkip?.addEventListener('change', updateProxmoxMode);
  proxmoxAuthRadios.forEach(radio => radio.addEventListener('change', updateProxmoxMode));
  updateProxmoxMode();

  const proxmoxButton = document.getElementById('testProxmox');
  proxmoxButton?.addEventListener('click', async () => {
    const output = document.getElementById('proxmoxResult');
    const mode = form.querySelector('input[name="proxmox_auth_mode"]:checked')?.value || 'token';
    busy(proxmoxButton, true);
    showResult(output, 'loading', mode === 'password'
      ? 'Sprawdzanie loginu i hasła w Proxmox…'
      : 'Łączenie z API Proxmox za pomocą tokenu…');
    try {
      const data = await post('/install/test/proxmox', values());
      const note = data.auth_mode === 'password'
        ? '<p><strong>Login i hasło są prawidłowe.</strong> Test nie utworzył tokenu. Token zostanie utworzony dopiero po kliknięciu „Kontynuuj”.</p>'
        : '<p>Istniejący token API został zaakceptowany.</p>';
      showResult(output, 'success', `<strong>Połączenie działa.</strong>${note}<dl><div><dt>Klaster</dt><dd>${escape(data.cluster)}</dd></div><div><dt>Węzły</dt><dd>${escape(data.nodes)}</dd></div><div><dt>Proxmox VE</dt><dd>${escape(data.version)}</dd></div><div><dt>Storage</dt><dd>${escape(data.storages)}</dd></div>${data.username ? `<div><dt>Użytkownik</dt><dd>${escape(data.username)}</dd></div>` : ''}</dl>`);
    } catch (error) {
      showResult(output, 'error', `<strong>Test nie powiódł się.</strong><p>${escape(error.message)}</p>`);
    } finally {
      busy(proxmoxButton, false);
      updateProxmoxMode();
    }
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
