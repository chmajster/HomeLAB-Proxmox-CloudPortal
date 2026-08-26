<div class="auth-shell w-100">
  <?php $english = ($locale ?? 'pl') === 'en'; ?>
  <div class="text-center mb-4">
    <div class="brand-mark mx-auto mb-3">A</div>
    <h1 class="h2 mb-1"><?= $english ? 'Reset password' : 'Reset hasła' ?></h1>
    <p class="text-secondary"><?= $english ? 'Enter your username or email address.' : 'Podaj nazwę użytkownika lub adres e-mail.' ?></p>
  </div>
  <div class="card portal-card p-4">
    <?php if ($message): ?><div class="alert alert-success" role="alert"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div><?php unset($_SESSION['password_reset_message']); endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div><?php unset($_SESSION['password_reset_error']); endif; ?>
    <form method="post" action="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/forgot-password">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="mb-4"><label class="form-label" for="identity"><?= $english ? 'Username or email' : 'Użytkownik lub e-mail' ?></label><input class="form-control form-control-lg" id="identity" name="identity" autocomplete="username" required autofocus></div>
      <button class="btn btn-primary btn-lg w-100" type="submit"><?= $english ? 'Send reset link' : 'Wyślij link resetujący' ?></button>
    </form>
    <a class="btn btn-link w-100 mt-3" href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/login"><?= $english ? 'Back to sign in' : 'Powrót do logowania' ?></a>
  </div>
</div>
