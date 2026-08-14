<?php
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$iconBase = $escape($basePath);
$icon = static fn (string $name): string => '<svg class="ui-icon" aria-hidden="true"><use href="' . $iconBase . '/assets/icons.svg#i-' . $escape($name) . '"></use></svg>';
?>
<div class="installer-shell finish-shell">
  <section class="card portal-card installer-card text-center">
    <div class="finish-check"><?= $icon('check') ?></div>
    <p class="eyebrow">Algen Cloud Portal <?= $escape($summary['version']) ?></p>
    <h1>Instalacja zakończona pomyślnie</h1>
    <p class="text-secondary">Portal jest gotowy. Instalator został trwale zablokowany po weryfikacji konfiguracji i bazy.</p>
    <?php if (($summary['administrator_test_account'] ?? false) === true): ?>
      <div class="alert alert-danger text-start" role="alert"><strong>Aktywne konto testowe:</strong> login <code>admin</code>, hasło <code>1</code>. Zmień hasło natychmiast po pierwszym zalogowaniu i nie udostępniaj tej instalacji w Internecie.</div>
    <?php endif; ?>
    <dl class="finish-summary">
      <div><dt>Nazwa portalu</dt><dd><?= $escape($summary['portal_name']) ?></dd></div>
      <div><dt>URL portalu</dt><dd><?= $escape($summary['portal_url']) ?></dd></div>
      <div><dt>Administrator</dt><dd><?= $escape($summary['administrator']) ?></dd></div>
      <div><dt>Serwer bazy</dt><dd><?= $escape($summary['database_server']) ?></dd></div>
      <div><dt>Proxmox</dt><dd><?= $escape($summary['proxmox']) ?></dd></div>
      <div><dt>Wersja aplikacji</dt><dd><?= $escape($summary['version']) ?></dd></div>
    </dl>
    <a class="btn btn-primary btn-lg w-100" href="<?= $escape($basePath) ?>/login">Przejdź do logowania<?= $icon('arrow-right') ?></a>
  </section>
</div>
