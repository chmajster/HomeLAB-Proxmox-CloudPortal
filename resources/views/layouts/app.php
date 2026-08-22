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
</head>
<body data-base-path="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>" data-page="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>" data-admin="<?= $isAdmin ? '1' : '0' ?>" data-user-id="<?= (int) $user['id'] ?>">
  <?= $content ?>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/ui.js?v=<?= $assetVersion('js/ui.js') ?>" defer></script>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/modules/api.js?v=<?= $assetVersion('js/modules/api.js') ?>" defer></script>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/modules/jobs.js?v=<?= $assetVersion('js/modules/jobs.js') ?>" defer></script>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/modules/system.js?v=<?= $assetVersion('js/modules/system.js') ?>" defer></script>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/app.js?v=<?= $assetVersion('js/app.js') ?>" defer></script>
</body>
</html>
