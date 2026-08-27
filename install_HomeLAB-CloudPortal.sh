#!/usr/bin/env bash
set -Eeuo pipefail

APP_NAME="HomeLAB-CloudPortal"
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
WEB_ROOT="/var/www/algen-cloud-portal"
APACHE_SITE="homelab-cloudportal"
STATE_DIR="/etc/homelab-cloudportal"
DB_PASSWORD_FILE="${STATE_DIR}/db-password"

SILENT=false
WEB_UI_IP="0.0.0.0"
WEB_UI_PORT="81"
PANEL_LOGIN="admin"
PANEL_PASSWORD=""
PANEL_EMAIL=""
DNS_SERVER_IP=""
DNS_API_TOKEN=""
PORTAL_NAME="Algen Cloud Portal"
PORTAL_URL=""
TIMEZONE="Europe/Warsaw"
LOCALE="pl"
SESSION_LIFETIME="7200"
HOSTNAME_PATTERN="vm-{project}-{counter}"

DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="cloudportal"
DB_USER="cloudportal"
DB_PASSWORD=""
CONFIRM_EXISTING_DB=false

PROXMOX_SKIP=true
PROXMOX_NAME="Primary Proxmox"
PROXMOX_HOST=""
PROXMOX_PORT="8006"
PROXMOX_REALM="pam"
PROXMOX_TOKEN_ID=""
PROXMOX_TOKEN_SECRET=""
PROXMOX_VERIFY_SSL=true
ENABLE_WORKER=true

log() { printf '[%s] %s\n' "${APP_NAME}" "$*"; }
fatal() { printf '[%s] ERROR: %s\n' "${APP_NAME}" "$*" >&2; exit 1; }

usage() {
  cat <<'USAGE'
Usage:
  ./install_HomeLAB-CloudPortal.sh [options]

Core:
  --silent
  --web-ui-ip IP                 Apache bind address, default: 0.0.0.0
  --port PORT                    Apache port, default: 81
  --panel-login LOGIN            Default: admin
  --panel-password PASSWORD      Required in --silent mode
  --panel-email EMAIL
  --portal-url URL               Must be HTTPS, except localhost/127.0.0.1
  --portal-name NAME

HomeLAB-DNS:
  --forward-dns-server IP        Compatibility alias for --dns-server-ip
  --dns-server-ip IP
  --panel-api-token TOKEN        Compatibility alias for --dns-api-token
  --dns-api-token TOKEN

Database:
  --db-host HOST                 Default: 127.0.0.1
  --db-port PORT                 Default: 3306
  --db-name NAME                 Default: cloudportal
  --db-user USER                 Default: cloudportal
  --db-password PASSWORD         Auto-generated when omitted
  --confirm-existing-db

Proxmox:
  --proxmox-host HOST
  --proxmox-port PORT            Default: 8006
  --proxmox-name NAME
  --proxmox-realm REALM          Default: pam
  --proxmox-token-id ID          Example: root@pam!cloudportal
  --proxmox-token-secret SECRET
  --proxmox-no-verify-ssl
  --skip-proxmox

Other:
  --hostname-pattern PATTERN
  --timezone TZ
  --locale pl|en
  --session-lifetime SECONDS
  --no-worker
  -h, --help
USAGE
}

need_arg() { [[ $# -ge 2 && -n "${2:-}" ]] || fatal "Option $1 requires a value."; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --silent) SILENT=true; shift ;;
    --web-ui-ip) need_arg "$@"; WEB_UI_IP="$2"; shift 2 ;;
    --port) need_arg "$@"; WEB_UI_PORT="$2"; shift 2 ;;
    --panel-login) need_arg "$@"; PANEL_LOGIN="$2"; shift 2 ;;
    --panel-password) need_arg "$@"; PANEL_PASSWORD="$2"; shift 2 ;;
    --panel-email) need_arg "$@"; PANEL_EMAIL="$2"; shift 2 ;;
    --portal-url) need_arg "$@"; PORTAL_URL="$2"; shift 2 ;;
    --portal-name) need_arg "$@"; PORTAL_NAME="$2"; shift 2 ;;
    --forward-dns-server|--dns-server-ip) need_arg "$@"; DNS_SERVER_IP="$2"; shift 2 ;;
    --panel-api-token|--dns-api-token) need_arg "$@"; DNS_API_TOKEN="$2"; shift 2 ;;
    --db-host) need_arg "$@"; DB_HOST="$2"; shift 2 ;;
    --db-port) need_arg "$@"; DB_PORT="$2"; shift 2 ;;
    --db-name) need_arg "$@"; DB_NAME="$2"; shift 2 ;;
    --db-user) need_arg "$@"; DB_USER="$2"; shift 2 ;;
    --db-password) need_arg "$@"; DB_PASSWORD="$2"; shift 2 ;;
    --confirm-existing-db) CONFIRM_EXISTING_DB=true; shift ;;
    --proxmox-host) need_arg "$@"; PROXMOX_HOST="$2"; PROXMOX_SKIP=false; shift 2 ;;
    --proxmox-port) need_arg "$@"; PROXMOX_PORT="$2"; shift 2 ;;
    --proxmox-name) need_arg "$@"; PROXMOX_NAME="$2"; shift 2 ;;
    --proxmox-realm) need_arg "$@"; PROXMOX_REALM="$2"; shift 2 ;;
    --proxmox-token-id) need_arg "$@"; PROXMOX_TOKEN_ID="$2"; PROXMOX_SKIP=false; shift 2 ;;
    --proxmox-token-secret) need_arg "$@"; PROXMOX_TOKEN_SECRET="$2"; PROXMOX_SKIP=false; shift 2 ;;
    --proxmox-no-verify-ssl) PROXMOX_VERIFY_SSL=false; shift ;;
    --skip-proxmox) PROXMOX_SKIP=true; shift ;;
    --hostname-pattern) need_arg "$@"; HOSTNAME_PATTERN="$2"; shift 2 ;;
    --timezone) need_arg "$@"; TIMEZONE="$2"; shift 2 ;;
    --locale) need_arg "$@"; LOCALE="$2"; shift 2 ;;
    --session-lifetime) need_arg "$@"; SESSION_LIFETIME="$2"; shift 2 ;;
    --no-worker) ENABLE_WORKER=false; shift ;;
    -h|--help) usage; exit 0 ;;
    *) fatal "Unknown option: $1" ;;
  esac
done

[[ ${EUID} -eq 0 ]] || fatal "Run this installer as root."
[[ "${WEB_UI_PORT}" =~ ^[0-9]+$ ]] && (( WEB_UI_PORT >= 1 && WEB_UI_PORT <= 65535 )) || fatal "Invalid --port."
[[ "${DB_PORT}" =~ ^[0-9]+$ ]] && (( DB_PORT >= 1 && DB_PORT <= 65535 )) || fatal "Invalid --db-port."
[[ "${PROXMOX_PORT}" =~ ^[0-9]+$ ]] && (( PROXMOX_PORT >= 1 && PROXMOX_PORT <= 65535 )) || fatal "Invalid --proxmox-port."
[[ "${SESSION_LIFETIME}" =~ ^[0-9]+$ ]] && (( SESSION_LIFETIME >= 900 && SESSION_LIFETIME <= 86400 )) || fatal "--session-lifetime must be 900-86400."
[[ "${DB_NAME}" =~ ^[A-Za-z0-9_]+$ ]] || fatal "--db-name may contain only letters, digits and underscore."
[[ "${DB_USER}" =~ ^[A-Za-z0-9_.-]+$ ]] || fatal "--db-user contains unsupported characters."
[[ "${PANEL_LOGIN}" =~ ^[A-Za-z0-9_.-]{3,64}$ ]] || fatal "--panel-login must contain 3-64 letters, digits, dots, hyphens or underscores."
[[ "${LOCALE}" == "pl" || "${LOCALE}" == "en" ]] || fatal "--locale must be pl or en."

if [[ "${SILENT}" == true && -z "${PANEL_PASSWORD}" ]]; then
  fatal "--panel-password is required in --silent mode."
fi
if [[ -z "${PANEL_PASSWORD}" ]]; then
  read -r -s -p "Panel administrator password: " PANEL_PASSWORD
  printf '\n'
fi
if (( ${#PANEL_PASSWORD} < 12 )) || [[ ! "${PANEL_PASSWORD}" =~ [a-z] ]] || [[ ! "${PANEL_PASSWORD}" =~ [A-Z] ]] || [[ ! "${PANEL_PASSWORD}" =~ [0-9] ]]; then
  fatal "Panel password must have at least 12 characters, including lowercase, uppercase and a digit."
fi

if [[ -n "${DNS_SERVER_IP}" || -n "${DNS_API_TOKEN}" ]]; then
  [[ -n "${DNS_SERVER_IP}" && -n "${DNS_API_TOKEN}" ]] || fatal "DNS integration requires both server IP and API token."
fi

if [[ "${PROXMOX_SKIP}" == false ]]; then
  [[ -n "${PROXMOX_HOST}" ]] || fatal "Proxmox requires --proxmox-host."
  [[ -n "${PROXMOX_TOKEN_ID}" ]] || fatal "Proxmox requires --proxmox-token-id."
  [[ -n "${PROXMOX_TOKEN_SECRET}" ]] || fatal "Proxmox requires --proxmox-token-secret."
fi

[[ -f "${SOURCE_DIR}/index.php" && -d "${SOURCE_DIR}/installer" ]] || fatal "Run the installer from the repository root."

export DEBIAN_FRONTEND=noninteractive

install_php_repo_if_needed() {
  apt-get update -y
  if apt-cache show php8.3-cli >/dev/null 2>&1; then return; fi
  . /etc/os-release
  case "${ID:-}" in
    ubuntu)
      apt-get install -y software-properties-common ca-certificates curl gnupg
      add-apt-repository -y ppa:ondrej/php
      apt-get update -y
      ;;
    debian)
      apt-get install -y ca-certificates curl gnupg lsb-release
      install -d -m 0755 /etc/apt/keyrings
      curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor --yes -o /etc/apt/keyrings/php-sury.gpg
      printf 'deb [signed-by=/etc/apt/keyrings/php-sury.gpg] https://packages.sury.org/php/ %s main\n' "$(lsb_release -sc)" > /etc/apt/sources.list.d/php-sury.list
      apt-get update -y
      ;;
    *) fatal "Supported systems: Ubuntu and Debian." ;;
  esac
}

log "Installing Apache, PHP 8.3 and MariaDB..."
install_php_repo_if_needed
apt-get install -y apache2 mariadb-server git rsync curl openssl \
  php8.3-cli libapache2-mod-php8.3 php8.3-common php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml

php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || fatal "PHP 8.3+ is required."
systemctl enable --now mariadb
systemctl enable apache2

a2enmod rewrite headers >/dev/null
a2enmod php8.3 >/dev/null 2>&1 || true

log "Deploying application to ${WEB_ROOT}..."
install -d -m 0755 "${WEB_ROOT}"
rsync -a --delete --exclude='.git/' --exclude='install.json' --exclude='storage/logs/*.log' "${SOURCE_DIR}/" "${WEB_ROOT}/"
chown -R root:www-data "${WEB_ROOT}"
find "${WEB_ROOT}" -type d -exec chmod 0755 {} +
find "${WEB_ROOT}" -type f -exec chmod 0644 {} +
install -d -o www-data -g www-data -m 0750 "${WEB_ROOT}/storage" "${WEB_ROOT}/storage/logs" "${WEB_ROOT}/storage/cache" "${WEB_ROOT}/config"
chown -R www-data:www-data "${WEB_ROOT}/storage" "${WEB_ROOT}/config"
chmod 0755 "${WEB_ROOT}/update.sh" 2>/dev/null || true

log "Preparing MariaDB..."
install -d -m 0700 "${STATE_DIR}"
if [[ -z "${DB_PASSWORD}" ]]; then
  if [[ -s "${DB_PASSWORD_FILE}" ]]; then
    DB_PASSWORD="$(cat "${DB_PASSWORD_FILE}")"
  else
    DB_PASSWORD="$(openssl rand -hex 24)"
    printf '%s\n' "${DB_PASSWORD}" > "${DB_PASSWORD_FILE}"
    chmod 0600 "${DB_PASSWORD_FILE}"
  fi
fi

sql_escape() { printf '%s' "$1" | sed "s/'/''/g"; }
DB_PASSWORD_SQL="$(sql_escape "${DB_PASSWORD}")"
DB_USER_SQL="$(sql_escape "${DB_USER}")"

mariadb --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER_SQL}'@'localhost' IDENTIFIED BY '${DB_PASSWORD_SQL}';
ALTER USER '${DB_USER_SQL}'@'localhost' IDENTIFIED BY '${DB_PASSWORD_SQL}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER_SQL}'@'localhost';
CREATE USER IF NOT EXISTS '${DB_USER_SQL}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD_SQL}';
ALTER USER '${DB_USER_SQL}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD_SQL}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER_SQL}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

log "Configuring Apache on ${WEB_UI_IP}:${WEB_UI_PORT}..."
cat > /etc/apache2/conf-available/homelab-cloudportal-listen.conf <<EOF_APACHE_LISTEN
Listen ${WEB_UI_IP}:${WEB_UI_PORT}
EOF_APACHE_LISTEN

a2enconf homelab-cloudportal-listen >/dev/null
cat > "/etc/apache2/sites-available/${APACHE_SITE}.conf" <<EOF_APACHE_SITE
<VirtualHost ${WEB_UI_IP}:${WEB_UI_PORT}>
    ServerName localhost
    DocumentRoot ${WEB_ROOT}
    <Directory ${WEB_ROOT}>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/homelab-cloudportal-error.log
    CustomLog \${APACHE_LOG_DIR}/homelab-cloudportal-access.log combined
</VirtualHost>
EOF_APACHE_SITE

a2ensite "${APACHE_SITE}" >/dev/null
if [[ "${WEB_UI_PORT}" == "80" ]]; then a2dissite 000-default >/dev/null 2>&1 || true; fi
apache2ctl configtest >/dev/null
systemctl restart apache2

# The application intentionally permits plain HTTP only for localhost.
# When no explicit HTTPS public URL is supplied, bootstrap with localhost.
if [[ -z "${PORTAL_URL}" ]]; then
  PORTAL_URL="http://127.0.0.1:${WEB_UI_PORT}"
fi

log "Generating install.json..."
export CP_INSTALL_JSON="${WEB_ROOT}/install.json"
export CP_DB_HOST="${DB_HOST}" CP_DB_PORT="${DB_PORT}" CP_DB_NAME="${DB_NAME}" CP_DB_USER="${DB_USER}" CP_DB_PASSWORD="${DB_PASSWORD}"
export CP_CONFIRM_EXISTING_DB="${CONFIRM_EXISTING_DB}"
export CP_PANEL_LOGIN="${PANEL_LOGIN}" CP_PANEL_PASSWORD="${PANEL_PASSWORD}" CP_PANEL_EMAIL="${PANEL_EMAIL}"
export CP_DNS_SERVER_IP="${DNS_SERVER_IP}" CP_DNS_API_TOKEN="${DNS_API_TOKEN}"
export CP_PROXMOX_SKIP="${PROXMOX_SKIP}" CP_PROXMOX_NAME="${PROXMOX_NAME}" CP_PROXMOX_HOST="${PROXMOX_HOST}" CP_PROXMOX_PORT="${PROXMOX_PORT}"
export CP_PROXMOX_REALM="${PROXMOX_REALM}" CP_PROXMOX_TOKEN_ID="${PROXMOX_TOKEN_ID}" CP_PROXMOX_TOKEN_SECRET="${PROXMOX_TOKEN_SECRET}" CP_PROXMOX_VERIFY_SSL="${PROXMOX_VERIFY_SSL}"
export CP_HOSTNAME_PATTERN="${HOSTNAME_PATTERN}" CP_PORTAL_NAME="${PORTAL_NAME}" CP_PORTAL_URL="${PORTAL_URL}" CP_TIMEZONE="${TIMEZONE}" CP_LOCALE="${LOCALE}" CP_SESSION_LIFETIME="${SESSION_LIFETIME}"

php <<'PHP'
<?php
$bool = static fn(string $name): bool => filter_var(getenv($name), FILTER_VALIDATE_BOOL);
$config = [
    'database' => [
        'driver' => 'mysql',
        'host' => getenv('CP_DB_HOST'),
        'port' => (int) getenv('CP_DB_PORT'),
        'name' => getenv('CP_DB_NAME'),
        'user' => getenv('CP_DB_USER'),
        'password' => getenv('CP_DB_PASSWORD'),
        'confirm_existing' => $bool('CP_CONFIRM_EXISTING_DB'),
    ],
    'panel' => [
        'login' => getenv('CP_PANEL_LOGIN'),
        'email' => getenv('CP_PANEL_EMAIL'),
        'password' => getenv('CP_PANEL_PASSWORD'),
        'resume_existing' => false,
    ],
    'proxmox' => ['skip' => $bool('CP_PROXMOX_SKIP')],
    'hostname_generator' => ['pattern' => getenv('CP_HOSTNAME_PATTERN')],
    'portal' => [
        'name' => getenv('CP_PORTAL_NAME'),
        'url' => getenv('CP_PORTAL_URL'),
        'timezone' => getenv('CP_TIMEZONE'),
        'locale' => getenv('CP_LOCALE'),
        'session_lifetime' => (int) getenv('CP_SESSION_LIFETIME'),
    ],
];
if (getenv('CP_DNS_SERVER_IP') !== '' || getenv('CP_DNS_API_TOKEN') !== '') {
    $config['dns'] = ['server_ip' => getenv('CP_DNS_SERVER_IP'), 'api_token' => getenv('CP_DNS_API_TOKEN')];
}
if (!$bool('CP_PROXMOX_SKIP')) {
    $config['proxmox'] = [
        'skip' => false,
        'name' => getenv('CP_PROXMOX_NAME'),
        'hostname' => getenv('CP_PROXMOX_HOST'),
        'port' => (int) getenv('CP_PROXMOX_PORT'),
        'realm' => getenv('CP_PROXMOX_REALM'),
        'token_id' => getenv('CP_PROXMOX_TOKEN_ID'),
        'token_secret' => getenv('CP_PROXMOX_TOKEN_SECRET'),
        'verify_ssl' => $bool('CP_PROXMOX_VERIFY_SSL'),
    ];
}
file_put_contents(
    getenv('CP_INSTALL_JSON'),
    json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    LOCK_EX
) !== false || exit(1);
PHP

chown www-data:www-data "${WEB_ROOT}/install.json"
chmod 0600 "${WEB_ROOT}/install.json"
unset CP_DB_PASSWORD CP_PANEL_PASSWORD CP_DNS_API_TOKEN CP_PROXMOX_TOKEN_SECRET

INSTALL_CONNECT_IP="${WEB_UI_IP}"
[[ "${INSTALL_CONNECT_IP}" == "0.0.0.0" ]] && INSTALL_CONNECT_IP="127.0.0.1"

log "Running automatic installer..."
CURL_OUTPUT="$(mktemp)"
set +e
curl --noproxy '*' -sS -L --max-time 180 -H "Host: 127.0.0.1:${WEB_UI_PORT}" "http://${INSTALL_CONNECT_IP}:${WEB_UI_PORT}/install" >"${CURL_OUTPUT}" 2>&1
CURL_RC=$?
set -e

if [[ ! -f "${WEB_ROOT}/storage/installed.lock" ]]; then
  printf 'Automatic installation failed. curl=%s\n' "${CURL_RC}" >&2
  if [[ -s "${WEB_ROOT}/storage/logs/installer.log" ]]; then tail -n 80 "${WEB_ROOT}/storage/logs/installer.log" >&2 || true; fi
  tail -n 80 "${CURL_OUTPUT}" >&2 || true
  rm -f "${CURL_OUTPUT}"
  exit 1
fi
rm -f "${CURL_OUTPUT}" "${WEB_ROOT}/install.json"
chmod 0600 "${WEB_ROOT}/config/runtime.php" "${WEB_ROOT}/storage/installed.lock" 2>/dev/null || true

if [[ "${ENABLE_WORKER}" == true ]]; then
  log "Installing worker service..."
  cat > /etc/systemd/system/algen-cloud-worker.service <<EOF_WORKER
[Unit]
Description=Algen Cloud Portal Proxmox job worker
After=network-online.target mariadb.service apache2.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${WEB_ROOT}
ExecStart=/usr/bin/php ${WEB_ROOT}/bin/worker.php
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${WEB_ROOT}/storage

[Install]
WantedBy=multi-user.target
EOF_WORKER
  systemctl daemon-reload
  systemctl enable --now algen-cloud-worker.service
fi

log "Installation completed."
log "Apache: ${WEB_UI_IP}:${WEB_UI_PORT}"
log "Portal base URL: ${PORTAL_URL}"
log "Administrator: ${PANEL_LOGIN}"
