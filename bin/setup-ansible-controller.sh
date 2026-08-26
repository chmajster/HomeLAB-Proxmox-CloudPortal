#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/algen-cloud-portal}"
KEY_DIR="${ANSIBLE_KEY_DIR:-/var/lib/algen-cloud-portal/.ssh}"
PRIVATE_KEY="${ANSIBLE_PRIVATE_KEY:-${KEY_DIR}/ansible_ed25519}"
PUBLIC_KEY="${ANSIBLE_PUBLIC_KEY:-${PRIVATE_KEY}.pub}"
PLAYBOOK_DIR="${ANSIBLE_PLAYBOOKS_DIRECTORY:-${APP_ROOT}/ansible/playbooks}"
WORKER_USER="${CLOUD_PORTAL_WORKER_USER:-www-data}"
WORKER_GROUP="${CLOUD_PORTAL_WORKER_GROUP:-www-data}"

if [[ ${EUID} -ne 0 ]]; then
  echo "Run this script as root." >&2
  exit 1
fi

if ! id "${WORKER_USER}" >/dev/null 2>&1; then
  echo "Worker user does not exist: ${WORKER_USER}" >&2
  exit 1
fi

if ! command -v ansible-playbook >/dev/null 2>&1; then
  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y ansible openssh-client
  else
    echo "ansible-playbook is not installed and apt-get is unavailable." >&2
    exit 1
  fi
fi

install -d -m 0750 -o root -g "${WORKER_GROUP}" "${KEY_DIR}"
install -d -m 0750 -o root -g "${WORKER_GROUP}" "${PLAYBOOK_DIR}"

if [[ ! -f "${PRIVATE_KEY}" ]]; then
  ssh-keygen -q -t ed25519 -N '' -C 'algen-cloud-portal-ansible' -f "${PRIVATE_KEY}"
fi

if [[ ! -f "${PUBLIC_KEY}" ]]; then
  ssh-keygen -y -f "${PRIVATE_KEY}" > "${PUBLIC_KEY}"
fi

chown root:"${WORKER_GROUP}" "${PRIVATE_KEY}" "${PUBLIC_KEY}"
chmod 0640 "${PRIVATE_KEY}"
chmod 0644 "${PUBLIC_KEY}"

if ! sudo -u "${WORKER_USER}" test -r "${PRIVATE_KEY}"; then
  echo "Worker cannot read Ansible private key: ${PRIVATE_KEY}" >&2
  exit 1
fi

if ! sudo -u "${WORKER_USER}" test -r "${PUBLIC_KEY}"; then
  echo "Worker cannot read Ansible public key: ${PUBLIC_KEY}" >&2
  exit 1
fi

if ! sudo -u "${WORKER_USER}" test -x "$(command -v ansible-playbook)"; then
  echo "Worker cannot execute ansible-playbook." >&2
  exit 1
fi

cat <<EOF
Ansible controller ready.
Playbooks:   ${PLAYBOOK_DIR}
Private key: ${PRIVATE_KEY}
Public key:  ${PUBLIC_KEY}
Worker:      ${WORKER_USER}:${WORKER_GROUP}
EOF
