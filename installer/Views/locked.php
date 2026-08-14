<?php
$iconBase = htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8');
$icon = static fn (string $name): string => '<svg class="ui-icon" aria-hidden="true"><use href="' . $iconBase . '/assets/icons.svg#i-' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"></use></svg>';
?>
<div class="installer-shell finish-shell">
  <section class="card portal-card installer-card text-center">
    <div class="locked-icon"><?= $icon('lock') ?></div>
    <p class="eyebrow">Bezpieczeństwo instalacji</p>
    <h1>Aplikacja jest już zainstalowana</h1>
    <p class="text-secondary">Instalator został zablokowany po zakończeniu konfiguracji. Samo wejście na ten adres nie może uruchomić ponownej instalacji ani zmienić danych.</p>
    <a class="btn btn-primary" href="<?= $iconBase ?>/login">Przejdź do logowania<?= $icon('arrow-right') ?></a>
  </section>
</div>
