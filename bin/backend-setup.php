#!/usr/bin/env php
<?php
// Run locally as the PHP runtime user. Only a hash is written to disk.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__);
if (is_file($root.'/config/backend.json')) { fwrite(STDERR,"Backend already configured. Use Settings in the portal.\n"); exit(1); }
umask(0077);
$key=bin2hex(random_bytes(32));
if (file_put_contents($root.'/storage/backend-setup.token',hash('sha256',$key),LOCK_EX)===false) exit(1);
chmod($root.'/storage/backend-setup.token',0600);
fwrite(STDOUT,"Backend setup key (shown once): $key\nOpen /settings/infrastructure/backend and enter this key.\n");
