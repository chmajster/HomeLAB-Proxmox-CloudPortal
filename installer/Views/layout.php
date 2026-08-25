<?php
$installerJsPath = dirname(__DIR__, 2) . '/public/assets/js/installer.js';
$installerJsVersion = is_file($installerJsPath) ? (string) filemtime($installerJsPath) : '1';
?>
<!doctype html>
<html lang="pl" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="theme-color" content="#f6f8fc">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Instalator — Algen Cloud Portal</title>
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/favicon.svg">
  <link href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/css/app.css" rel="stylesheet">
</head>
<body class="installer-bg" data-base-path="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>">
  <main class="container installer-page"><?= $content ?></main>
  <script src="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/installer.js?v=<?= htmlspecialchars($installerJsVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>
</html>
