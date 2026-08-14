<?php
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$steps = [
    0 => 'Start', 1 => 'Wymagania', 2 => 'Baza danych', 4 => 'Administrator', 5 => 'Proxmox',
    6 => 'Portal', 9 => 'Instalacja',
];
$titles = [
    0 => 'Witaj w instalatorze', 1 => 'Kontrola środowiska', 2 => 'Połączenie z bazą danych',
    4 => 'Pierwszy administrator', 5 => 'Połączenie Proxmox', 6 => 'Konfiguracja portalu',
    9 => 'Instalowanie portalu',
];
if (($requirementsHidden ?? false) === true) {
    unset($steps[1], $titles[1]);
}
$review = $step <= $completed;
$nextVisibleStep = 9;
foreach (array_keys($steps) as $visibleStep) {
    if ($visibleStep > $completed) {
        $nextVisibleStep = $visibleStep;
        break;
    }
}
$visibleStepNumbers = array_keys($steps);
$currentStepIndex = (int) array_search($step, $visibleStepNumbers, true);
$displayStep = $currentStepIndex + 1;
$previousStep = $currentStepIndex > 0 ? $visibleStepNumbers[$currentStepIndex - 1] : null;
$followingStep = $currentStepIndex < count($visibleStepNumbers) - 1 ? $visibleStepNumbers[$currentStepIndex + 1] : null;
$hasRequirementErrors = false;
foreach ($requirements as $check) $hasRequirementErrors = $hasRequirementErrors || $check['status'] === 'error';
$errorFor = static fn (string $name): string => isset($fieldErrors[$name]) ? '<span class="invalid-feedback">' . $escape($fieldErrors[$name]) . '</span>' : '';
$invalid = static fn (string $name): string => isset($fieldErrors[$name]) ? ' is-invalid' : '';
$checked = static fn (bool $value): string => $value ? ' checked' : '';
$iconBase = $escape($basePath);
$icon = static fn (string $name, string $class = 'ui-icon'): string => '<svg class="' . $escape($class) . '" aria-hidden="true"><use href="' . $iconBase . '/assets/icons.svg#i-' . $escape($name) . '"></use></svg>';
?>
<div class="installer-shell">
  <header class="installer-header">
    <div class="brand-mark">A</div>
    <div>
      <p class="eyebrow">Algen Cloud Portal <?= $escape($version) ?></p>
      <h1><?= $escape($titles[$step]) ?></h1>
      <p class="text-secondary">Krok <?= $displayStep ?> z <?= count($steps) ?></p>
    </div>
  </header>

  <ol class="installer-progress steps-<?= count($steps) ?>" aria-label="Postęp instalacji">
    <?php $visiblePosition = 0; ?>
    <?php foreach ($steps as $number => $label): ?>
      <?php
      $visiblePosition++;
      $isCompleted = $number <= $completed;
      $isCurrent = $number === $step;
      $isNavigable = $number <= $completed || $number === $nextVisibleStep;
      $stepClass = trim(($isCompleted ? 'done ' : '') . ($isCurrent ? 'active' : ''));
      $stepLabel = 'Krok ' . $visiblePosition . ': ' . $label;
      ?>
      <li class="<?= $escape($stepClass) ?>">
        <?php if ($isNavigable): ?>
          <a class="installer-step-link" href="<?= $escape($basePath) ?>/install?step=<?= $number ?>" aria-label="<?= $escape(($isCurrent ? 'Bieżący ' : 'Przejdź do ') . $stepLabel) ?>"<?= $isCurrent ? ' aria-current="step"' : '' ?> title="<?= $escape($stepLabel) ?>">
            <span class="installer-step-marker"><?= $isCompleted ? $icon('check') : $visiblePosition ?></span><small><?= $escape($label) ?></small>
          </a>
        <?php else: ?>
          <span class="installer-step-link" aria-disabled="true" aria-label="<?= $escape($stepLabel . ' — jeszcze niedostępny') ?>" title="<?= $escape($stepLabel . ' — jeszcze niedostępny') ?>">
            <span class="installer-step-marker"><?= $visiblePosition ?></span><small><?= $escape($label) ?></small>
          </span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <section class="card portal-card installer-card">
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><strong>Nie udało się wykonać kroku.</strong><br><?= $escape($error) ?></div><?php endif; ?>
    <?php if ($review): ?><div class="alert alert-info" role="status">Ten krok został już bezpiecznie zakończony. Dane są pokazane tylko do odczytu.</div><?php endif; ?>

    <form method="post" action="<?= $escape($basePath) ?>/install" autocomplete="off" id="installerForm" data-step="<?= $step ?>">
      <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
      <input type="hidden" name="step" value="<?= $step ?>">
      <fieldset<?= $review ? ' disabled' : '' ?>>
        <?php if ($step === 0): ?>
          <div class="welcome-copy">
            <span class="installer-icon"><?= $icon('cloud') ?></span>
            <p>Ten kreator skonfiguruje portal podobnie jak instalator WordPress — bez ręcznego importowania SQL i bez edycji plików konfiguracyjnych.</p>
            <h2>Przygotuj:</h2>
            <ul class="installer-list">
              <li>dane pustej bazy MariaDB lub MySQL,</li>
              <li>nazwę i silne hasło administratora; e-mail jest opcjonalny,</li>
              <li>opcjonalnie token API Proxmox (można dodać go później),</li>
              <li>nazwę i publiczny adres portalu.</li>
            </ul>
            <p class="text-secondary">Wersja aplikacji: <?= $escape($version) ?>. Instalator nie uruchamia komend systemowych i nie wysyła danych poza wskazaną bazę oraz Proxmox.</p>
          </div>

        <?php elseif ($step === 1): ?>
          <p class="section-intro">Wszystkie pozycje oznaczone jako ERROR muszą zostać naprawione. WARNING nie blokuje instalacji.</p>
          <div class="table-responsive requirements-wrap">
            <table class="table requirements-table">
              <thead><tr><th>Wymaganie</th><th>Wymagane</th><th>Wykryto</th><th>Status</th></tr></thead>
              <tbody id="requirementsBody">
              <?php foreach ($requirements as $check): ?>
                <tr><td><?= $escape($check['name']) ?></td><td><?= $escape($check['required']) ?></td><td><?= $escape($check['detected']) ?></td><td><span class="requirement-status status-<?= $escape($check['status']) ?>"><?= strtoupper($escape($check['status'])) ?></span></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (!$review): ?><button class="btn btn-outline-primary" type="button" id="recheckRequirements"><?= $icon('refresh') ?>Sprawdź ponownie</button><?php endif; ?>

        <?php elseif ($step === 2): ?>
          <p class="section-intro">Przycisk testu wykona prawdziwe połączenie PDO i sprawdzi uprawnienie tworzenia tabel. Po wybraniu „Kontynuuj” wersjonowany schemat zostanie utworzony automatycznie — bez dodatkowego ekranu.</p>
          <div class="form-grid">
            <div class="field span-2"><label for="db_driver">Typ bazy</label><select id="db_driver" name="db_driver"><option value="mysql">MariaDB / MySQL</option></select></div>
            <div class="field"><label for="db_host">Host bazy</label><input class="<?= $invalid('db_host') ?>" id="db_host" name="db_host" value="<?= $escape($values['db_host'] ?? '') ?>" required><?= $errorFor('db_host') ?></div>
            <div class="field"><label for="db_port">Port</label><input class="<?= $invalid('db_port') ?>" id="db_port" name="db_port" type="number" min="1" max="65535" value="<?= $escape($values['db_port'] ?? 3306) ?>" required><?= $errorFor('db_port') ?></div>
            <div class="field span-2"><label for="db_name">Nazwa istniejącej bazy</label><input class="<?= $invalid('db_name') ?>" id="db_name" name="db_name" value="<?= $escape($values['db_name'] ?? '') ?>" required><?= $errorFor('db_name') ?></div>
            <div class="field"><label for="db_user">Użytkownik</label><input class="<?= $invalid('db_user') ?>" id="db_user" name="db_user" value="<?= $escape($values['db_user'] ?? '') ?>" autocomplete="username" required><?= $errorFor('db_user') ?></div>
            <div class="field"><label for="db_password">Hasło</label><input id="db_password" name="db_password" type="password" autocomplete="new-password"></div>
          </div>
          <label class="check-row"><input type="checkbox" value="1" name="confirm_existing_database"<?= $checked((bool) ($values['confirm_existing_database'] ?? false)) ?>> <span>Potwierdzam użycie niepustej bazy. Kreator zachowa wszystkie istniejące tabele.</span></label>
          <?php if (!$review): ?><button class="btn btn-outline-primary test-button" type="button" id="testDatabase"><?= $icon('database') ?>Testuj połączenie</button><?php endif; ?>
          <div class="connection-result" id="databaseResult" aria-live="polite"></div>

        <?php elseif ($step === 4): ?>
          <p class="section-intro">E-mail nie jest wymagany. Każde hasło, również testowe, zostanie zapisane wyłącznie jako bezpieczny hash.</p>
          <label class="check-row skip-row test-account-row"><input type="checkbox" value="1" id="useTestAdministrator" name="use_test_administrator" aria-controls="administratorFields"<?= $checked((bool) ($values['use_test_administrator'] ?? $values['test_account'] ?? false)) ?>> <span><strong>Pomiń dane administratora i utwórz konto testowe</strong><br>Login: <code>admin</code>, hasło: <code>1</code>.</span></label>
          <div class="alert alert-danger test-account-warning" role="note"><strong>Tylko środowisko testowe.</strong> To publicznie znane dane logowania. Nie wybieraj tej opcji w systemie dostępnym z Internetu i zmień hasło natychmiast po zalogowaniu.</div>
          <div id="administratorFields">
            <div class="form-grid">
              <div class="field"><label for="username">Nazwa użytkownika</label><input class="<?= $invalid('username') ?>" id="username" name="username" value="<?= $escape($values['username'] ?? '') ?>" minlength="3" maxlength="64" autocomplete="username" required><?= $errorFor('username') ?></div>
              <div class="field"><label for="email">E-mail (opcjonalnie)</label><input class="<?= $invalid('email') ?>" id="email" name="email" type="email" value="<?= $escape($values['email'] ?? '') ?>" autocomplete="email"><?= $errorFor('email') ?><small>Jeśli pozostawisz pole puste, instalator zapisze lokalny adres techniczny.</small></div>
              <div class="field"><label for="password">Hasło</label><input class="<?= $invalid('password') ?>" id="password" name="password" type="password" minlength="12" autocomplete="new-password" required><?= $errorFor('password') ?><small>Minimum 12 znaków, mała i wielka litera oraz cyfra.</small></div>
              <div class="field"><label for="password_confirmation">Powtórz hasło</label><input class="<?= $invalid('password_confirmation') ?>" id="password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required><?= $errorFor('password_confirmation') ?></div>
            </div>
            <label class="check-row"><input type="checkbox" value="1" name="resume_existing_admin"> <span>Zaznacz tylko przy świadomym wznawianiu instalacji z dokładnie tym samym kontem administratora.</span></label>
          </div>

        <?php elseif ($step === 5): ?>
          <p class="section-intro">Token zostanie sprawdzony przez prawdziwe API Proxmox, a następnie zaszyfrowany. Możesz pominąć ten krok.</p>
          <label class="check-row skip-row"><input type="checkbox" value="1" id="skipProxmox" name="skip_proxmox"<?= $checked((bool) ($values['skipped'] ?? false)) ?>> <span>Skonfiguruję Proxmox później w panelu administratora</span></label>
          <div id="proxmoxFields">
            <div class="form-grid">
              <div class="field span-2"><label for="connection_name">Nazwa połączenia</label><input class="<?= $invalid('connection_name') ?>" id="connection_name" name="connection_name" value="<?= $escape($values['connection_name'] ?? '') ?>" required><?= $errorFor('connection_name') ?></div>
              <div class="field"><label for="hostname">Hostname / IP</label><input class="<?= $invalid('hostname') ?>" id="hostname" name="hostname" value="<?= $escape($values['hostname'] ?? '') ?>" placeholder="pve.example.com" required><?= $errorFor('hostname') ?></div>
              <div class="field"><label for="port">Port</label><input class="<?= $invalid('port') ?>" id="port" name="port" type="number" min="1" max="65535" value="<?= $escape($values['port'] ?? 8006) ?>" required><?= $errorFor('port') ?></div>
              <div class="field"><label for="realm">Realm</label><input class="<?= $invalid('realm') ?>" id="realm" name="realm" value="<?= $escape($values['realm'] ?? 'pve') ?>" required><?= $errorFor('realm') ?></div>
              <div class="field"><label for="api_token_id">API Token ID</label><input class="<?= $invalid('api_token_id') ?>" id="api_token_id" name="api_token_id" value="<?= $escape($values['api_token_id'] ?? '') ?>" placeholder="root@pam!cloudportal" autocomplete="username" required><?= $errorFor('api_token_id') ?><small>Format: user@realm!token. To nie jest sam login użytkownika.</small></div>
              <div class="field span-2"><label for="api_token_secret">API Token Secret</label><input class="<?= $invalid('api_token_secret') ?>" id="api_token_secret" name="api_token_secret" type="password" autocomplete="new-password" required><?= $errorFor('api_token_secret') ?><small>Wklej sekret wygenerowanego tokenu, a nie hasło konta.</small></div>
            </div>
            <input type="hidden" name="verify_ssl" value="0">
            <label class="check-row"><input type="checkbox" value="1" id="verify_ssl" name="verify_ssl"<?= $checked((bool) ($values['verify_ssl'] ?? true)) ?>> <span>Weryfikuj certyfikat TLS (zalecane)</span></label>
            <?php if (!$review): ?><button class="btn btn-outline-primary test-button" type="button" id="testProxmox"><?= $icon('proxmox') ?>Testuj połączenie Proxmox</button><?php endif; ?>
            <div class="connection-result" id="proxmoxResult" aria-live="polite"></div>
          </div>

        <?php elseif ($step === 6): ?>
          <div class="form-grid">
            <div class="field span-2"><label for="portal_name">Nazwa portalu</label><input class="<?= $invalid('portal_name') ?>" id="portal_name" name="portal_name" value="<?= $escape($values['portal_name'] ?? '') ?>" required><?= $errorFor('portal_name') ?></div>
            <div class="field span-2"><label for="base_url">Bazowy URL</label><input class="<?= $invalid('base_url') ?>" id="base_url" name="base_url" type="url" value="<?= $escape($values['base_url'] ?? '') ?>" required><?= $errorFor('base_url') ?><small>Adres jest wykrywany automatycznie i może zostać zmieniony.</small></div>
            <div class="field"><label for="timezone">Strefa czasowa</label><select class="<?= $invalid('timezone') ?>" id="timezone" name="timezone" required><?php foreach ($timezones as $timezone): ?><option value="<?= $escape($timezone) ?>"<?= ($values['timezone'] ?? '') === $timezone ? ' selected' : '' ?>><?= $escape($timezone) ?></option><?php endforeach; ?></select><?= $errorFor('timezone') ?></div>
            <div class="field"><label for="locale">Domyślny język</label><select id="locale" name="locale"><option value="pl"<?= ($values['locale'] ?? 'pl') === 'pl' ? ' selected' : '' ?>>Polski</option><option value="en"<?= ($values['locale'] ?? '') === 'en' ? ' selected' : '' ?>>English</option></select></div>
            <div class="field span-2"><label for="session_lifetime">Czas sesji (sekundy)</label><input class="<?= $invalid('session_lifetime') ?>" id="session_lifetime" name="session_lifetime" type="number" min="900" max="86400" value="<?= $escape($values['session_lifetime'] ?? 7200) ?>" required><?= $errorFor('session_lifetime') ?></div>
          </div>

        <?php else: ?>
          <p class="section-intro">Kreator ponownie sprawdzi wszystkie elementy. Blokada instalatora powstanie dopiero po pełnym sukcesie.</p>
          <ol class="installation-stages" id="installationStages">
            <?php foreach (['Weryfikacja konfiguracji', 'Łączenie z bazą danych', 'Weryfikacja schematu bazy', 'Weryfikacja ról', 'Weryfikacja uprawnień', 'Weryfikacja administratora', 'Zapisywanie ustawień portalu', 'Konfigurowanie Proxmox', 'Weryfikacja kluczy bezpieczeństwa', 'Weryfikacja końcowa', 'Tworzenie blokady instalatora'] as $stage): ?>
              <li data-status="pending"><span class="stage-icon"><?= $icon('circle') ?></span><span><?= $escape($stage) ?></span><strong>Oczekuje</strong></li>
            <?php endforeach; ?>
          </ol>
          <div class="alert alert-danger d-none" id="installationError" role="alert"></div>
          <button class="btn btn-primary btn-lg w-100" type="button" id="startInstallation"><?= $icon('play') ?>Rozpocznij instalację</button>
        <?php endif; ?>
      </fieldset>

      <?php if ($step < 9): ?>
        <footer class="installer-actions">
          <?php if ($previousStep !== null): ?><a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/install?step=<?= $previousStep ?>"><?= $icon('arrow-left') ?>Wstecz</a><?php else: ?><span></span><?php endif; ?>
          <?php if ($review && $followingStep !== null): ?>
            <a class="btn btn-primary" id="continueButton" href="<?= $escape($basePath) ?>/install?step=<?= $followingStep ?>">Dalej<?= $icon('arrow-right') ?></a>
          <?php else: ?>
            <button class="btn btn-primary" id="continueButton" type="submit"<?= $step === 1 && $hasRequirementErrors ? ' disabled' : '' ?>><?= $step === 0 ? $icon('play') . 'Rozpocznij instalację' : 'Kontynuuj' . $icon('arrow-right') ?></button>
          <?php endif; ?>
        </footer>
      <?php endif; ?>
    </form>
  </section>
  <p class="installer-footnote">Instalacja wykonywana lokalnie przez PHP + PDO. Bez poleceń shell i bez wysyłania sekretów do przeglądarki.</p>
</div>
