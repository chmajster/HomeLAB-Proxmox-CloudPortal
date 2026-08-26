<div class="auth-shell w-100">
  <?php $english = ($locale ?? 'pl') === 'en'; ?>
  <div class="text-center mb-4">
    <div class="brand-mark mx-auto mb-3">A</div>
    <h1 class="h3 mb-1"><?= $english ? 'Two-factor authentication' : 'Uwierzytelnianie dwuskładnikowe' ?></h1>
    <p class="text-secondary"><?= $english ? 'Enter the 6-digit authenticator code or a recovery code.' : 'Wpisz 6-cyfrowy kod z aplikacji Authenticator albo kod odzyskiwania.' ?></p>
  </div>
  <div class="card portal-card p-4">
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div><?php unset($_SESSION['mfa_error']); endif; ?>
    <form method="post" action="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/login/mfa">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="mb-4">
        <label class="form-label" for="code"><?= $english ? 'Authentication code' : 'Kod uwierzytelniający' ?></label>
        <input class="form-control form-control-lg" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required autofocus>
      </div>
      <button class="btn btn-primary btn-lg w-100" type="submit"><?= $english ? 'Verify' : 'Zweryfikuj' ?></button>
    </form>
  </div>
</div>
