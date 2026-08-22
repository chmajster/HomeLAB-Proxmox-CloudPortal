# Managed VM provisioning

When the portal has `dns.server_ip`, `dns.api_token_encrypted` and `hostname_generator.pattern` configured, VM creation uses the managed provisioning workflow by default.

## Workflow

1. Generate hostname from `hostname_generator.pattern`.
2. Reserve an IP address in Cloud Portal IPAM.
3. Store provisioning status `RESERVED`.
4. Create the forward `A` record through HomeLAB-DNS.
5. Create the matching `PTR` record through HomeLAB-DNS.
6. Verify both DNS answers against the configured DNS server.
7. Clone and configure the VM in Proxmox.
8. Store provisioning status `CREATING`.
9. Start the VM and wait for QEMU Guest Agent.
10. Execute `/usr/local/sbin/vm-setup.sh` inside the VM.
11. Execute `puppet agent --test` inside the VM.
12. Store provisioning status `READY`.

Every completed or failed stage is stored in `vm_provisioning_events`. The current state and history are returned with `GET /api/jobs/{job-id}`.

## DNS requirements

The configured HomeLAB-DNS API token needs the permissions required by:

- `zones.read`
- `records.read`
- `records.write`
- `tools.lookup`

The default API endpoint is `http://<dns.server_ip>:81/api/v1`. Runtime configuration can override:

```php
'dns' => [
    'server_ip' => '10.0.10.2',
    'port' => 81,
    'scheme' => 'http',
    'forward_zone' => 'lab.example.internal',
    'api_token_encrypted' => '...',
],
```

`dns.forward_zone` is optional only when HomeLAB-DNS contains exactly one enabled, managed forward zone. The reverse zone is selected automatically from the reserved IPv4 address.

## VM template requirements

The VM template must contain and enable QEMU Guest Agent. The default bootstrap commands are:

```text
/usr/local/sbin/vm-setup.sh
puppet agent --test
```

They can be changed in runtime configuration:

```php
'provisioning' => [
    'vm_setup_command' => '/usr/local/sbin/vm-setup.sh',
    'puppet_command' => 'puppet agent --test',
    'guest_agent_timeout' => 300,
    'guest_command_timeout' => 900,
],
```

The portal sends guest commands to Proxmox as JSON arrays, as required by current Proxmox VE QEMU Guest Agent `agent/exec` API behavior.

## Failure handling

Before the VM exists, a failure removes DNS records created by the workflow and releases the IP/quota reservation. After the VM has been persisted, a bootstrap or Puppet failure keeps the VM and DNS identity for diagnosis, sets provisioning status to `ERROR`, and records the failing step and error message.

To explicitly use the legacy manual-name flow, send:

```json
{
  "managed_provisioning": false
}
```
