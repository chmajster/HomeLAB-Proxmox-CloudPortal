<?php
$english = ($user['locale'] ?? 'pl') === 'en';
$escapedBasePath = htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8');
$icon = static fn (string $name, string $class = 'ui-icon'): string => '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"><use href="' . $escapedBasePath . '/assets/icons.svg#i-' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"></use></svg>';
$nav = [
  ['dashboard', 'Dashboard', 'dashboard'], ['vms', $english ? 'Virtual machines' : 'Maszyny wirtualne', 'server'], ['create-vm', $english ? 'Create VM' : 'Utwórz VM', 'plus-circle'],
  ['projects', $english ? 'Projects' : 'Projekty', 'folder'], ['networks', $english ? 'Networks' : 'Sieci', 'network'], ['templates', $english ? 'Templates' : 'Template', 'template'], ['activity', $english ? 'Activity' : 'Aktywność', 'history'],
];
$adminNav = [
  ['users', $english ? 'Users' : 'Użytkownicy', 'users'], ['infrastructure', $english ? 'Infrastructure' : 'Infrastruktura', 'infrastructure'], ['proxmox', 'Proxmox', 'proxmox'],
  ['storages', 'Storage', 'storage'], ['plans', $english ? 'Resource plans' : 'Plany zasobów', 'sliders'], ['quotas', 'Quota', 'gauge'], ['audit', 'Audit log', 'audit'], ['settings', $english ? 'Settings' : 'Ustawienia', 'settings'],
];
?>
<div class="portal-layout">
  <aside class="portal-sidebar" id="portalSidebar">
    <a href="<?= $escapedBasePath ?>/dashboard" class="portal-brand"><span class="brand-mark brand-small">A</span><span><?= htmlspecialchars((string) $appName, ENT_QUOTES, 'UTF-8') ?></span></a>
    <nav class="portal-nav" aria-label="Główna nawigacja">
      <p class="nav-section">Portal</p>
      <?php foreach ($nav as [$slug, $label, $iconName]): ?><a class="nav-link <?= $page === $slug ? 'active' : '' ?>" href="<?= $escapedBasePath ?>/<?= $slug ?>"><?= $icon($iconName, 'ui-icon nav-icon') ?><span><?= $label ?></span></a><?php endforeach; ?>
      <?php if ($isAdmin): ?><p class="nav-section mt-4"><?= $english ? 'Administration' : 'Administracja' ?></p><?php foreach ($adminNav as [$slug, $label, $iconName]): ?><a class="nav-link <?= $page === $slug ? 'active' : '' ?>" href="<?= $escapedBasePath ?>/<?= $slug ?>"><?= $icon($iconName, 'ui-icon nav-icon') ?><span><?= $label ?></span></a><?php endforeach; endif; ?>
    </nav>
    <div class="sidebar-footer">
      <?php $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr((string) $user['username'], 0, 1)) : strtoupper(substr((string) $user['username'], 0, 1)); ?>
      <div class="user-avatar"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="min-w-0"><strong class="d-block text-truncate"><?= htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') ?></strong><small class="text-secondary"><?= $isAdmin ? 'Administrator' : 'User' ?></small></div>
      <button class="icon-button ms-auto" id="logoutButton" aria-label="<?= $english ? 'Sign out' : 'Wyloguj' ?>"><?= $icon('log-out') ?></button>
    </div>
  </aside>
  <main class="portal-main">
    <header class="portal-topbar">
      <button class="icon-button mobile-menu" id="menuButton" aria-label="<?= $english ? 'Open menu' : 'Otwórz menu' ?>" aria-controls="portalSidebar" aria-expanded="false"><?= $icon('menu') ?></button>
      <div><p class="eyebrow mb-0">Cloud Portal</p><h1 class="page-title mb-0" id="pageTitle">Ładowanie…</h1></div>
      <div class="ms-auto d-flex gap-2">
        <button class="icon-button" id="themeButton" aria-label="<?= $english ? 'Change theme' : 'Zmień motyw' ?>"><?= $icon('theme') ?></button>
        <a class="btn btn-primary d-none d-sm-inline-flex" href="<?= $escapedBasePath ?>/create-vm"><?= $icon('plus') ?><?= $english ? 'Create VM' : 'Utwórz VM' ?></a>
      </div>
    </header>
    <div class="portal-content">
      <?php if (is_array($firstRun)): ?>
        <section class="first-run-checklist" id="firstRunChecklist" aria-labelledby="firstRunTitle">
          <div>
            <p class="eyebrow mb-0"><?= $english ? 'VM provisioning' : 'Provisioning VM' ?></p>
            <h2 class="h5 mb-1" id="firstRunTitle"><?= $english ? 'Prepare resources for VM provisioning' : 'Przygotuj zasoby do tworzenia VM' ?></h2>
            <p class="text-secondary small mb-0"><?= $english ? 'The portal itself is configured. These resources are only required before the first automated VM can be created.' : 'Portal jest skonfigurowany. Poniższe zasoby są potrzebne dopiero przed pierwszym automatycznym utworzeniem VM.' ?></p>
          </div>
          <button class="btn-close" type="button" data-dismiss-checklist aria-label="<?= $english ? 'Hide readiness checklist' : 'Ukryj listę gotowości' ?>"></button>
          <ul>
            <?php foreach ([
              'proxmox' => $english ? 'Proxmox connection' : 'Połączenie Proxmox',
              'projects' => $english ? 'At least one project' : 'Co najmniej jeden projekt',
              'networks' => $english ? 'At least one network' : 'Co najmniej jedna sieć',
              'storages' => $english ? 'At least one storage' : 'Co najmniej jeden storage',
              'templates' => $english ? 'At least one VM template' : 'Co najmniej jeden template VM',
              'plans' => $english ? 'At least one resource plan' : 'Co najmniej jeden plan zasobów',
            ] as $key => $label): ?><li class="<?= !empty($firstRun[$key]) ? 'complete' : '' ?>"><?= $icon(!empty($firstRun[$key]) ? 'check' : 'circle') ?><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
      <div id="appContent" aria-live="polite"><div class="loading-panel"><span class="spinner-border text-primary" aria-hidden="true"></span><span><?= $english ? 'Loading data…' : 'Ładowanie danych…' ?></span></div></div>
    </div>
  </main>
</div>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer" aria-live="polite"></div>
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content portal-card"><div class="modal-header"><h2 class="modal-title fs-5" id="confirmTitle"><?= $english ? 'Confirm operation' : 'Potwierdź operację' ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $english ? 'Close' : 'Zamknij' ?>"></button></div><div class="modal-body" id="confirmMessage"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= $english ? 'Cancel' : 'Anuluj' ?></button><button type="button" class="btn btn-danger" id="confirmAction"><?= $english ? 'Confirm' : 'Potwierdź' ?></button></div></div></div>
</div>
