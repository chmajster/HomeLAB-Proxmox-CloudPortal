#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_BASE="$ROOT/storage/backups/updates"
LOCK_DIR="$ROOT/storage/update.lock"
MAINTENANCE_FILE="$ROOT/storage/maintenance.json"
LOG_FILE="$ROOT/storage/logs/update.log"
WORKER_SERVICE="${CLOUDPORTAL_WORKER_SERVICE:-algen-cloud-worker.service}"

MODE="update"
PACKAGE=""
EXPECTED_SHA=""
NO_CHECKSUM=0
ALLOW_DOWNGRADE=0
ROLLBACK_REF=""
LOCK_ACQUIRED=0
IN_MAINTENANCE=0
BACKUP_READY=0
BACKUP_DIR=""
TEMP_DIR=""
MYSQL_CNF=""
DB_NAME=""
WORKER_WAS_ACTIVE=0
WORKER_STOPPED=0
ERROR_HANDLING=0

usage() {
  cat <<'EOF'
Usage:
  bash update.sh --package /path/release.zip [--sha256 HEX] [--no-checksum] [--allow-downgrade]
  bash update.sh --rollback latest
  bash update.sh --rollback BACKUP_DIRECTORY_NAME

Options:
  --worker-service NAME   systemd worker unit (default: algen-cloud-worker.service)
  --sha256 HEX            expected package SHA-256
  --no-checksum           explicitly allow a package without SHA-256 verification
  --allow-downgrade       allow installing a version older than the current version

The updater refuses to start while queued/running jobs exist. It preserves
config/runtime.php, storage/, vendor/ and .git/, creates a full database dump,
and restores code + database automatically when an update step fails.
EOF
}

mkdir -p "$ROOT/storage/logs" "$BACKUP_BASE" "$ROOT/storage/cache"

timestamp() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }
log() { printf '[%s] %s\n' "$(timestamp)" "$*" | tee -a "$LOG_FILE" >&2; }
die() { log "ERROR: $*"; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

cleanup() {
  local status=$?
  if [[ -n "$MYSQL_CNF" ]]; then rm -f -- "$MYSQL_CNF" || true; fi
  if [[ -n "$TEMP_DIR" ]]; then rm -rf -- "$TEMP_DIR" || true; fi
  if [[ "$LOCK_ACQUIRED" == "1" ]]; then rmdir "$LOCK_DIR" 2>/dev/null || true; fi
  return "$status"
}

worker_exists() {
  command -v systemctl >/dev/null 2>&1 && systemctl cat "$WORKER_SERVICE" >/dev/null 2>&1
}

stop_worker() {
  if ! worker_exists; then return 0; fi
  if systemctl is-active --quiet "$WORKER_SERVICE"; then
    WORKER_WAS_ACTIVE=1
    log "Stopping worker service: $WORKER_SERVICE"
    systemctl stop "$WORKER_SERVICE"
    WORKER_STOPPED=1
  fi
}

restart_worker() {
  if [[ "$WORKER_WAS_ACTIVE" != "1" ]]; then return 0; fi
  log "Starting worker service: $WORKER_SERVICE"
  systemctl start "$WORKER_SERVICE"
  WORKER_STOPPED=0
}

enable_maintenance() {
  local operation="$1"
  local detail="$2"
  local temporary="$MAINTENANCE_FILE.tmp.$$"
  umask 077
  printf '{"maintenance":true,"operation":"%s","message":"Portal maintenance in progress","detail":"%s","started_at":"%s"}\n' \
    "$operation" "$detail" "$(timestamp)" > "$temporary"
  mv -f -- "$temporary" "$MAINTENANCE_FILE"
  IN_MAINTENANCE=1
  log "Maintenance mode enabled ($operation)."
}

disable_maintenance() {
  if [[ "$IN_MAINTENANCE" == "1" || -f "$MAINTENANCE_FILE" ]]; then
    rm -f -- "$MAINTENANCE_FILE"
    IN_MAINTENANCE=0
    log "Maintenance mode disabled."
  fi
}

pending_jobs() {
  php "$ROOT/bin/update-helper.php" pending-jobs | tr -d '[:space:]'
}

assert_queue_empty() {
  local count
  count="$(pending_jobs)"
  [[ "$count" =~ ^[0-9]+$ ]] || die "Could not determine queued/running job count."
  if (( count > 0 )); then
    die "There are $count queued/running jobs. Let the worker finish them before updating or rolling back."
  fi
}

prepare_mysql() {
  TEMP_DIR="$(mktemp -d "$ROOT/storage/cache/cloudportal-update.XXXXXX")"
  MYSQL_CNF="$TEMP_DIR/mysql.cnf"
  php "$ROOT/bin/update-helper.php" mysql-config "$MYSQL_CNF"
  DB_NAME="$(php "$ROOT/bin/update-helper.php" database-name | tr -d '\r\n')"
  [[ "$DB_NAME" =~ ^[A-Za-z0-9_\$-]{1,64}$ ]] || die "Updater received an unsafe database name."
}

code_sync() {
  local source="$1"
  local destination="$2"
  rsync -a --delete \
    --exclude='/.git/' \
    --exclude='/storage/' \
    --exclude='/config/runtime.php' \
    --exclude='/install.json' \
    --exclude='/vendor/' \
    "$source/" "$destination/"
}

restore_backup() {
  local backup="$1"
  [[ -d "$backup/code" ]] || { log "Rollback backup has no code directory: $backup"; return 1; }
  [[ -s "$backup/database.sql" ]] || { log "Rollback backup has no database.sql: $backup"; return 1; }
  log "Restoring application code from $backup"
  code_sync "$backup/code" "$ROOT"
  log "Restoring database $DB_NAME"
  mysql --defaults-extra-file="$MYSQL_CNF" "$DB_NAME" < "$backup/database.sql"
  if [[ -f "$ROOT/tests/smoke.php" ]]; then
    php "$ROOT/tests/smoke.php"
  fi
}

on_error() {
  local status="${1:-$?}"
  if [[ "$ERROR_HANDLING" == "1" ]]; then exit "$status"; fi
  ERROR_HANDLING=1
  trap - ERR INT TERM
  set +e
  log "Update operation failed with exit code $status."
  if [[ "$BACKUP_READY" == "1" && -n "$BACKUP_DIR" ]]; then
    log "Automatic rollback started."
    if restore_backup "$BACKUP_DIR"; then
      log "Automatic rollback completed."
      disable_maintenance
      restart_worker
    else
      log "CRITICAL: automatic rollback failed. Maintenance mode remains enabled. Backup: $BACKUP_DIR"
    fi
  else
    disable_maintenance
    restart_worker
  fi
  exit "$status"
}

trap cleanup EXIT
trap 'on_error $?' ERR
trap 'on_error 130' INT TERM

while (( $# > 0 )); do
  case "$1" in
    --package) [[ $# -ge 2 ]] || die "--package requires a path"; PACKAGE="$2"; shift 2 ;;
    --sha256) [[ $# -ge 2 ]] || die "--sha256 requires a value"; EXPECTED_SHA="$2"; shift 2 ;;
    --no-checksum) NO_CHECKSUM=1; shift ;;
    --allow-downgrade) ALLOW_DOWNGRADE=1; shift ;;
    --rollback) [[ $# -ge 2 ]] || die "--rollback requires latest or a backup name"; MODE="rollback"; ROLLBACK_REF="$2"; shift 2 ;;
    --worker-service) [[ $# -ge 2 ]] || die "--worker-service requires a unit name"; WORKER_SERVICE="$2"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown argument: $1" ;;
  esac
done

for command in php rsync mysql mysqldump; do require_cmd "$command"; done
[[ -f "$ROOT/config/runtime.php" && -f "$ROOT/storage/installed.lock" ]] || die "Portal is not installed in $ROOT."
[[ -f "$ROOT/bin/update-helper.php" ]] || die "bin/update-helper.php is missing."

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  die "Another update/rollback appears to be running: $LOCK_DIR"
fi
LOCK_ACQUIRED=1

CURRENT_VERSION="$(php "$ROOT/bin/update-helper.php" version | tr -d '\r\n')"
[[ "$CURRENT_VERSION" =~ ^[0-9A-Za-z][0-9A-Za-z.+_-]*$ ]] || die "Current application version is invalid."

if [[ "$MODE" == "rollback" ]]; then
  [[ "$ROLLBACK_REF" == "latest" || "$ROLLBACK_REF" =~ ^[0-9A-Za-z._+-]+$ ]] || die "Unsafe backup reference."
  if [[ "$ROLLBACK_REF" == "latest" ]]; then
    BACKUP_DIR="$(find "$BACKUP_BASE" -mindepth 1 -maxdepth 1 -type d -printf '%p\n' | sort | tail -n 1)"
  else
    BACKUP_DIR="$BACKUP_BASE/$ROLLBACK_REF"
  fi
  [[ -n "$BACKUP_DIR" && -d "$BACKUP_DIR" ]] || die "Rollback backup not found."
  assert_queue_empty
  enable_maintenance "rollback" "$(basename "$BACKUP_DIR")"
  assert_queue_empty
  stop_worker
  prepare_mysql
  restore_backup "$BACKUP_DIR"
  disable_maintenance
  restart_worker
  log "Rollback completed from $(basename "$BACKUP_DIR")."
  exit 0
fi

[[ -n "$PACKAGE" ]] || { usage; die "--package is required for update mode."; }
[[ -f "$PACKAGE" ]] || die "Package not found: $PACKAGE"
require_cmd unzip
require_cmd sha256sum
PACKAGE="$(cd "$(dirname "$PACKAGE")" && pwd)/$(basename "$PACKAGE")"

if [[ -z "$EXPECTED_SHA" && -f "$PACKAGE.sha256" ]]; then
  EXPECTED_SHA="$(awk 'NR==1 {print $1}' "$PACKAGE.sha256")"
fi
if [[ -z "$EXPECTED_SHA" && "$NO_CHECKSUM" != "1" ]]; then
  die "No SHA-256 supplied. Provide --sha256, place ${PACKAGE}.sha256 next to the ZIP, or explicitly use --no-checksum."
fi
if [[ -n "$EXPECTED_SHA" ]]; then
  EXPECTED_SHA="${EXPECTED_SHA,,}"
  [[ "$EXPECTED_SHA" =~ ^[0-9a-f]{64}$ ]] || die "Invalid SHA-256 value."
  ACTUAL_SHA="$(sha256sum "$PACKAGE" | awk '{print $1}')"
  [[ "$ACTUAL_SHA" == "$EXPECTED_SHA" ]] || die "Package SHA-256 mismatch."
  log "Package SHA-256 verified: $ACTUAL_SHA"
else
  ACTUAL_SHA="unverified"
  log "WARNING: package checksum verification was explicitly disabled."
fi

TEMP_DIR="$(mktemp -d "$ROOT/storage/cache/cloudportal-update.XXXXXX")"
STAGE="$TEMP_DIR/stage"
mkdir -p "$STAGE"
unzip -q "$PACKAGE" -d "$STAGE"
if [[ -f "$STAGE/app/Application.php" ]]; then
  PACKAGE_ROOT="$STAGE"
else
  mapfile -t CANDIDATES < <(find "$STAGE" -mindepth 2 -maxdepth 3 -type f -path '*/app/Application.php' -print)
  [[ "${#CANDIDATES[@]}" -eq 1 ]] || die "Release ZIP must contain exactly one application root."
  PACKAGE_ROOT="$(dirname "$(dirname "${CANDIDATES[0]}")")"
fi
for required in app/Application.php public/index.php bin/migrate.php autoload.php database/schema.sql; do
  [[ -f "$PACKAGE_ROOT/$required" ]] || die "Release ZIP is incomplete: missing $required"
done
TARGET_VERSION="$(php -r '$s=file_get_contents($argv[1]); if (!preg_match("/public const VERSION = '\''([^'\'']+)'\''/", $s, $m)) exit(2); echo $m[1];' "$PACKAGE_ROOT/app/Application.php")"
[[ "$TARGET_VERSION" =~ ^[0-9A-Za-z][0-9A-Za-z.+_-]*$ ]] || die "Target application version is invalid."
if [[ "$ALLOW_DOWNGRADE" != "1" ]] && ! php -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "$TARGET_VERSION" "$CURRENT_VERSION"; then
  die "Refusing downgrade from $CURRENT_VERSION to $TARGET_VERSION without --allow-downgrade."
fi
log "Validated release $TARGET_VERSION (installed: $CURRENT_VERSION)."

assert_queue_empty
prepare_mysql
enable_maintenance "update" "$CURRENT_VERSION -> $TARGET_VERSION"
assert_queue_empty
stop_worker

BACKUP_DIR="$BACKUP_BASE/$(date -u '+%Y%m%dT%H%M%SZ')-from-$CURRENT_VERSION-to-$TARGET_VERSION"
mkdir -p "$BACKUP_DIR/code"
log "Backing up application code to $BACKUP_DIR"
code_sync "$ROOT" "$BACKUP_DIR/code"
cp -p "$ROOT/config/runtime.php" "$BACKUP_DIR/runtime.php"
cp -p "$ROOT/storage/installed.lock" "$BACKUP_DIR/installed.lock"
log "Dumping database $DB_NAME"
mysqldump --defaults-extra-file="$MYSQL_CNF" --single-transaction --quick --routines --triggers --events --hex-blob "$DB_NAME" > "$BACKUP_DIR/database.sql"
[[ -s "$BACKUP_DIR/database.sql" ]] || die "Database backup is empty."
printf '{"from":"%s","to":"%s","package_sha256":"%s","created_at":"%s"}\n' \
  "$CURRENT_VERSION" "$TARGET_VERSION" "$ACTUAL_SHA" "$(timestamp)" > "$BACKUP_DIR/metadata.json"
BACKUP_READY=1

log "Installing application files. Persistent runtime/storage data will be preserved."
code_sync "$PACKAGE_ROOT" "$ROOT"
log "Applying database migrations."
php "$ROOT/bin/migrate.php"
if [[ -f "$ROOT/tests/smoke.php" ]]; then
  log "Running post-update smoke checks."
  php "$ROOT/tests/smoke.php"
fi
INSTALLED_VERSION="$(php "$ROOT/bin/update-helper.php" version | tr -d '\r\n')"
[[ "$INSTALLED_VERSION" == "$TARGET_VERSION" ]] || die "Installed version verification failed: expected $TARGET_VERSION, got $INSTALLED_VERSION"

printf '{"result":"success","from":"%s","to":"%s","backup":"%s","finished_at":"%s"}\n' \
  "$CURRENT_VERSION" "$TARGET_VERSION" "$(basename "$BACKUP_DIR")" "$(timestamp)" >> "$ROOT/storage/update-history.jsonl"
BACKUP_READY=0
disable_maintenance
restart_worker
log "Update completed successfully: $CURRENT_VERSION -> $TARGET_VERSION. Backup retained at $BACKUP_DIR"
