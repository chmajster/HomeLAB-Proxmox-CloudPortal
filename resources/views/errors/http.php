<div class="card portal-card error-shell text-center p-5">
  <p class="display-2 fw-bold text-primary mb-2"><?= (int) $status ?></p>
  <h1 class="h4 mb-3">Nie udało się wyświetlić strony</h1>
  <p class="text-secondary"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></p>
  <a class="btn btn-primary mt-2" href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/">Wróć do portalu</a>
</div>
