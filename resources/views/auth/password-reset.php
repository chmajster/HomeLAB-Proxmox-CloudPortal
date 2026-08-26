<div class="auth-shell w-100">
  <?php $english = ($locale ?? 'pl') === 'en'; ?>
  <div class="text-center mb-4">
    <div class="brand-mark mx-auto mb-3">A</div>
    <h1 class="h2 mb-1"><?= $english ? 'Set a new password' : 'Ustaw nowe hasło' ?></h1>
    <p class="text-secondary"><?= $english ? 'The reset link is single-use and expires after 30 minutes.' : 'Link resetujący jest jednorazowy i wygasa po 30 minutach.' ?></p>
  </div>
  <div class="card portal-card p-4">
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div><?php unset($_SESSION['password_reset_error']); endif; ?>
    <?php if ($token === ''): ?>
      <div class="alert alert-danger" role="alert"><?= $english ? 'The reset token is missing.' : 'Brak tokenu resetującego.' ?></div>
    <?php else: ?>
      <form method="post" action="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/password-reset">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <div class="mb-4"><label class="form-label" for="new_password"><?= $english ? 'New password' : 'Nowe hasło' ?></label><input class="form-control form-control-lg" id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="12" required autofocus><div class="form-text"><?= $english ? 'At least 12 characters and three character classes.' : 'Co najmniej 12 znaków i trzy klasy znaków.' ?></div></div>
        <button class="btn btn-primary btn-lg w-100" type="submit"><?= $english ? 'Change password' : 'Zmień hasło' ?></button>
      </form>
    <?php endif; ?>
    <a class="btn btn-link w-100 mt-3" href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/login"><?= $english ? 'Back to sign in' : 'Powrót do logowania' ?></a>
  </div>
</div>
