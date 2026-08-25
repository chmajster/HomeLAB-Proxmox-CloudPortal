<?php
$assetRoot = dirname(__DIR__, 3) . '/public/assets';
$assetVersion = static function (string $path) use ($assetRoot): string {
    $modifiedAt = @filemtime($assetRoot . '/' . ltrim($path, '/'));
    return rawurlencode($modifiedAt === false ? \CloudPortal\Application::VERSION : (string) $modifiedAt);
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars((string) ($user['locale'] ?? 'pl'), ENT_QUOTES, 'UTF-8') ?>" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark light">
  <title><?= htmlspecialchars((string) $appName, ENT_QUOTES, 'UTF-8') ?></title>
  <link href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/css/app.css?v=<?= $assetVersion('css/app.css') ?>" rel="stylesheet">
  <?php if ($page === 'templates'): ?>
    <link href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/css/template-field-help.css?v=<?= $assetVersion('css/template-field-help.css') ?>" rel="stylesheet">
  <?php endif; ?>
</head>
<body data-base-path="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>" data-page="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>" data-admin="<?= $isAdmin ? '1' : '0' ?>" data-user-id="<?= (int) $user['id'] ?>" data-managed-provisioning="<?= !empty($managedProvisioning) ? '1' : '0' ?>" data-hostname-pattern="<?= htmlspecialchars((string) ($hostnamePattern ?? ''), ENT_QUOTES, 'UTF-8') ?>">
  <?= $content ?>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/ui.js?v=<?= $assetVersion('js/ui.js') ?>" defer></script>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/modules/api.js?v=<?= $assetVersion('js/modules/api.js') ?>" defer></script>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/modules/jobs.js?v=<?= $assetVersion('js/modules/jobs.js') ?>" defer></script>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/modules/system.js?v=<?= $assetVersion('js/modules/system.js') ?>" defer></script>
  <?php if (!in_array($page, ['vm-details', 'project-details', 'admin-resource-details', 'settings'], true)): ?>
    <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/app.js?v=<?= $assetVersion('js/app.js') ?>" defer></script>
    <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/managed-provisioning.js?v=<?= $assetVersion('js/managed-provisioning.js') ?>" defer></script>
  <?php endif; ?>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/portal-enhancements.js?v=<?= $assetVersion('js/portal-enhancements.js') ?>" defer></script>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/admin-resource-navigation.js?v=<?= $assetVersion('js/admin-resource-navigation.js') ?>" defer></script>
  <?php if ($page === 'vm-details'): ?>
    <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/vm-details.js?v=<?= $assetVersion('js/vm-details.js') ?>" defer></script>
    <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/vm-admin-enhancements.js?v=<?= $assetVersion('js/vm-admin-enhancements.js') ?>" defer></script>
  <?php elseif ($page === 'project-details'): ?>
    <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/project-details.js?v=<?= $assetVersion('js/project-details.js') ?>" defer></script>
  <?php elseif ($page === 'admin-resource-details'): ?>
    <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/admin-resource-details.js?v=<?= $assetVersion('js/admin-resource-details.js') ?>" defer></script>
  <?php elseif ($page === 'templates'): ?>
    <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/template-field-help.js?v=<?= $assetVersion('js/template-field-help.js') ?>" defer></script>
  <?php elseif ($page === 'settings'): ?>
    <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/settings.js?v=<?= $assetVersion('js/settings.js') ?>" defer></script>
  <?php endif; ?>
</body>
</html>
