<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Algen Cloud Portal',
        'env' => getenv('APP_ENV') ?: 'production',
        'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
        'url' => rtrim(getenv('APP_URL') ?: 'http://localhost', '/'),
        'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Warsaw',
        'locale' => getenv('APP_LOCALE') ?: 'pl',
        'key' => getenv('APP_KEY') ?: '',
        'installed' => false,
        'install_id' => '',
    ],
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: '',
        'user' => getenv('DB_USER') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
    'session' => [
        'name' => 'cloud_portal_session',
        'lifetime' => 7200,
    ],
    'security' => [
        'encryption_key' => getenv('ENCRYPTION_KEY') ?: (getenv('APP_KEY') ?: ''),
        'csrf_secret' => getenv('CSRF_SECRET') ?: '',
        'login_attempts' => 5,
        'login_window_seconds' => 900,
        'lockout_seconds' => 900,
        'password_reset_mail_from' => getenv('PASSWORD_RESET_MAIL_FROM') ?: 'no-reply@localhost',
    ],
    'uploads' => [
        'max_iso_bytes' => (int) (getenv('MAX_ISO_UPLOAD_BYTES') ?: 17179869184),
    ],
];
