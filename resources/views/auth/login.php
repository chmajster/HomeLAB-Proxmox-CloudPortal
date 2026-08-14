<div class="auth-shell w-100">
  <?php $english = ($locale ?? 'pl') === 'en'; ?>
  <div class="text-center mb-4">
    <div class="brand-mark mx-auto mb-3">A</div>
    <h1 class="h2 mb-1"><?= htmlspecialchars((string) $appName, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="text-secondary"><?= $english ? 'Secure access to your Proxmox cloud' : 'Bezpieczny dostęp do Twojej chmury Proxmox' ?></p>
  </div>
  <div class="card portal-card p-4">
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div><?php unset($_SESSION['login_error']); endif; ?>
    <form method="post" action="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/login">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="mb-3"><label class="form-label" for="identity"><?= $english ? 'Username or email' : 'Użytkownik lub e-mail' ?></label><input class="form-control form-control-lg" id="identity" name="identity" autocomplete="username" required autofocus></div>
      <div class="mb-4"><label class="form-label" for="password"><?= $english ? 'Password' : 'Hasło' ?></label><input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required></div>
      <button class="btn btn-primary btn-lg w-100" type="submit"><?= $english ? 'Sign in' : 'Zaloguj się' ?></button>
    </form>
  </div>
</div>
