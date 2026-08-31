# VM Blueprints — automated VM deployment

## How VM creation works in Cloud Portal

The automated lifecycle is:

```text
Cloud Portal
    ↓
Proxmox VE API
    ↓
Create VM by cloning a selected Proxmox template
    ↓
Apply Cloud-Init configuration
    ↓
Start VM and wait for QEMU Guest Agent
    ↓
Initial hardening
    ↓
Optional Puppet enrollment
    ↓
Reboot VM
    ↓
Wait until the VM is available again
    ↓
Run the selected Ansible playbook
    ↓
VM status = READY / running
```

For blueprint deployments, VM cloning is performed directly through the Proxmox VE API. The Terraform provisioning adapter is not used for this path.

## Blueprint profile

A VM Blueprint stores the complete deployment definition so the user does not need to select every provisioning option each time.

A blueprint contains:

- project;
- Proxmox VM template;
- resource plan (CPU, RAM and disk);
- network;
- storage;
- optional global Cloud-Init profile;
- initial hardening command;
- optional Puppet enrollment;
- reboot-before-Ansible setting;
- Ansible playbook;
- Ansible `extra_vars`;
- enabled/disabled state.

Blueprints are managed by an administrator from:

```text
Administration -> VM Blueprints
```

## One-click deployment

A project member opens:

```text
Create VM -> Blueprint deployment
```

and selects only one blueprint.

The portal then automatically:

1. validates that the user is a member of the blueprint project;
2. reserves quota and an IP address;
3. generates the hostname using managed provisioning;
4. creates and verifies DNS records when managed DNS is enabled;
5. clones the selected Proxmox template through the Proxmox API;
6. applies Cloud-Init settings and SSH keys;
7. starts the VM and waits for QEMU Guest Agent;
8. runs the configured initial hardening command;
9. optionally performs Puppet enrollment;
10. reboots the VM when the blueprint requires it;
11. waits for the VM to become available again;
12. automatically creates an Ansible job;
13. runs the selected playbook with blueprint `extra_vars`;
14. marks provisioning as `READY` only after Ansible finishes successfully.

The user does not need to select a template, CPU/RAM plan, storage, network, hardening command or playbook during deployment.

## Failure behavior

The lifecycle does not mark a blueprint deployment as `READY` before Ansible succeeds.

If the Ansible job fails temporarily, the normal Cloud Portal job retry mechanism is used. If all Ansible retry attempts are exhausted, the VM is marked with an error and the managed provisioning state becomes `FAILED`.

Provisioning state and Ansible output remain visible through the existing job and audit mechanisms.

## API

List blueprints available to the current user:

```http
GET /api/v1/blueprints
```

Deploy one blueprint:

```http
POST /api/v1/blueprints/{id}/deploy
```

Administrative profile management:

```http
GET   /api/v1/admin/blueprints
POST  /api/v1/admin/blueprints
PATCH /api/v1/admin/blueprints/{id}
```

Example profile payload:

```json
{
  "name": "Ubuntu Web Server",
  "slug": "ubuntu-web-server",
  "project_id": 1,
  "template_id": 3,
  "plan_id": 2,
  "network_id": 1,
  "storage_id": 1,
  "cloud_init_profile_id": 4,
  "initial_hardening_command": "/root/vm-setup.sh",
  "run_puppet": false,
  "reboot_before_ansible": true,
  "ansible_playbook": "webserver/install.yml",
  "ansible_extra_vars": {
    "environment": "production",
    "install_nginx": true
  },
  "enabled": true
}
```
