<!doctype html>
<html lang="<?= htmlspecialchars((string) ($locale ?? 'pl'), ENT_QUOTES, 'UTF-8') ?>" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars((string) ($appName ?? 'Algen Cloud Portal'), ENT_QUOTES, 'UTF-8') ?></title>
  <link href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/assets/css/app.css" rel="stylesheet">
</head>
<body class="guest-bg">
  <main class="container min-vh-100 d-flex align-items-center justify-content-center py-5"><?= $content ?></main>
</body>
</html>
