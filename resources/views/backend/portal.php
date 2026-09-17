<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cloud Portal</title><link rel="stylesheet" href="<?= $escape($base) ?>/assets/backend.css"></head>
<body><aside><a class="brand" href="<?= $escape($base) ?>/">Cloud Portal</a>
<?php if($me!==null): ?>
<?php $nav=[['Self-service',[['catalog','Utwórz serwer','/create-server','blueprints.read']]],['Infrastruktura',[['dashboard','Dashboard','/',''],['providers','Połączenia','/infrastructure/providers','providers.read'],['credentials','Credentiale','/infrastructure/credentials','credentials.read'],['templates','Szablony','/infrastructure/templates','terraform.read'],['hostnames','Hostname Manager','/infrastructure/hostnames','hostnames.read'],['deployments','Deploymenty','/infrastructure/deployments','deployments.read'],['jobs','Zadania','/infrastructure/jobs','jobs.read'],['logs','Logi','/infrastructure/logs','jobs.read'],['ansible','Ansible','/infrastructure/ansible','ansible.read']]],['Automatyzacja',[['blueprints','Blueprinty','/automation/blueprints','blueprints.read']]],['Administracja',[['users','Użytkownicy','/admin/users','users.read'],['roles','Role','/admin/roles','roles.read'],['permissions','Uprawnienia','/admin/permissions','roles.read'],['tokens','Tokeny API','/admin/tokens','tokens.read'],['audit','Audit','/admin/audit','audit.read']]],['Ustawienia',[['settings','Infrastruktura → Backend','/settings/infrastructure/backend','settings.update'],['account','Moje konto','/account','']]]]; ?>
<?php foreach($nav as [$group,$links]): ?><h2><?= $escape($group) ?></h2><?php foreach($links as [$key,$label,$url,$permission]): if($permission!==''&&!in_array($permission,$me['permissions'],true)) continue; ?><a class="<?= $page===$key?'active':'' ?>" href="<?= $escape($base.$url) ?>"><?= $escape($label) ?></a><?php endforeach; endforeach; ?>
<form method="post" action="<?= $escape($base) ?>/logout"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><button class="secondary">Wyloguj <?= $escape($me['user']['username']) ?></button></form>
<?php endif; ?></aside><main>
<?php if(in_array($page,['login','reset'],true)): ?>
<section class="card narrow"><h1><?= $page==='login'?'Logowanie':'Reset hasła' ?></h1><form method="post">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<?php if($page==='login'): ?><label>Użytkownik<input name="username" autocomplete="username" required maxlength="254"></label><?php else: ?><label>Jednorazowy token resetu<input name="token" type="password" autocomplete="off" required></label><?php endif; ?>
<label>Hasło<input type="password" name="password" autocomplete="<?= $page==='login'?'current-password':'new-password' ?>" required <?= $page==='reset'?'minlength="12"':'' ?> maxlength="256"></label>
<button><?= $page==='login'?'Zaloguj':'Ustaw hasło' ?></button></form>
<?php if($page==='login'): ?><a href="<?= $escape($base) ?>/password-reset">Użyj tokena resetu otrzymanego od administratora</a><?php endif; ?></section>
<?php elseif($page==='settings'): ?>
<h1>Ustawienia infrastruktury — Backend</h1><section class="card"><form method="post" class="settings-form">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<?php if($first): ?><p>Połączenie uruchamia centralne logowanie i zarządzanie infrastrukturą. Lokalne konta i stare operacje portalu przestają być używane.</p><label>Klucz pierwszej konfiguracji<input name="setup_key" type="password" autocomplete="off"></label><p>Klucz generuje polecenie <code>php bin/backend-setup.php</code> uruchomione na serwerze PHP.</p><?php endif; ?>
<label>Backend URL<input name="url" type="url" required placeholder="https://backend.example.com:8443" value="<?= $escape($config['url']) ?>"></label>
<label>API Token<input name="token" type="password" autocomplete="new-password" placeholder="<?= $first?'cp_…':'Pozostaw puste, aby zachować zapisany token' ?>" <?= $first?'required':'' ?>></label>
<label>Timeout w sekundach<input name="timeout" type="number" min="1" max="120" value="<?= $escape($config['timeout']) ?>" required></label>
<label class="check"><input name="verify_tls" type="checkbox" value="1" <?= $config['verify_tls']?'checked':'' ?>> Weryfikuj certyfikat TLS</label>
<p>Użyj osobnego konta serwisowego z rolą Portal Service i tokenem z uprawnieniem portal.connect. Certyfikat własnego CA można wskazać zmienną CP_BACKEND_CA_FILE.</p>
<div class="actions"><button name="action" value="test" class="secondary">Testuj połączenie</button><button name="action" value="save">Zapisz</button></div>
</form></section>
<?php if($result!==null): ?><section class="card"><h2>Wynik połączenia</h2><p>Uwierzytelnienie: OK</p><p>Konto serwisowe: <?= $result['service_account']?'Tak':'Nie — utwórz konto serwisowe i zastąp token bootstrap' ?></p><dl><?php foreach($result['health']['checks'] as $key=>$value): ?><dt><?= $escape($key) ?></dt><dd><?= $escape(is_array($value)?$value['online'].'/'.$value['expected']:($value?'OK':'Niedostępne')) ?></dd><?php endforeach; ?></dl></section><?php endif; ?>
<?php else: ?>
<header><div><span class="eyebrow">CLOUD PORTAL</span><h1 id="page-title"></h1></div><button id="create" hidden>Dodaj</button></header>
<div id="message" role="status" hidden></div><div id="content" aria-live="polite"></div>
<div class="pager"><button id="previous" class="secondary" hidden>Poprzednie</button><span id="page-number"></span><button id="next" class="secondary" hidden>Następne</button></div>
<dialog id="editor"><form id="editor-form"><header><h2 id="editor-title"></h2><button type="button" id="close-editor" class="secondary">Zamknij</button></header><div id="fields"></div><div id="form-error" role="alert"></div><button type="submit" id="save">Zapisz</button></form></dialog>
<dialog id="details"><header><h2 id="details-title">Szczegóły</h2><button type="button" id="close-details" class="secondary">Zamknij</button></header><div id="details-content"></div></dialog>
<script id="portal-data" type="application/json"><?= json_encode(['page'=>$page,'base'=>$base,'csrf'=>$csrf,'me'=>$me],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<script src="<?= $escape($base) ?>/assets/backend.js" defer></script>
<?php endif; ?></main></body></html>
