# Cloud Portal Ansible playbooks

Put selectable `.yml` or `.yaml` playbooks in this directory or its subdirectories.

Only playbooks under the configured `ANSIBLE_PLAYBOOKS_DIRECTORY` are exposed by the VM creation wizard. Absolute paths and path traversal are rejected by the API.

The portal runs the selected playbook after VM provisioning finishes. The inventory contains only the newly created VM IP address and uses the Cloud-Init system user selected in the VM wizard.

Before using this feature, run as root:

```bash
chmod +x bin/setup-ansible-controller.sh
./bin/setup-ansible-controller.sh
systemctl restart algen-cloud-worker
```

The setup script installs Ansible when required and creates the controller SSH key used by the worker. Its public key is automatically added to the new VM through Cloud-Init whenever a playbook is selected.
