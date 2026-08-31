# Proxmox Firewall and embedded console

## Firewall manager

Cloud Portal exposes Proxmox Firewall at two levels:

- a VM firewall panel on every managed VM details page,
- an administrator page at `/firewall` for cluster aliases, IPSets and security groups.

Managed VM mutations require the existing `vm.modify` permission and ownership checks. Live Proxmox VM and cluster-level changes require `admin.access`. Every mutation is CSRF protected and recorded in the audit log.

The VM panel supports:

- firewall enable/disable,
- IN/OUT default policy,
- IN/OUT log level,
- IN/OUT rules,
- ACCEPT/DROP/REJECT actions,
- security-group references,
- source and destination expressions,
- protocol, source/destination ports, macro and interface,
- per-rule enable state and comments.

The administrator page supports:

- aliases,
- IPSets and IPSet entries including `nomatch`,
- security groups and their rules.

Cloud Portal does not maintain a shadow copy of these firewall objects. Reads and writes use the Proxmox REST API so the portal reflects changes made directly in Proxmox as well.

The Proxmox API token must have the ACLs required by Proxmox for the firewall objects it is expected to manage. Use a dedicated token and grant only the required scope.

## Embedded noVNC console

The existing SPICE/Proxmox handoff remains available as a fallback. For graphical QEMU VMs, Cloud Portal can now open noVNC inside the portal without exposing the Proxmox API token to browser JavaScript.

The flow is:

1. The authenticated user requests `/api/v1/vms/{id}/console/session` or the administrator live-VM equivalent.
2. Cloud Portal checks RBAC/ownership and requests a short-lived `vncproxy` ticket from Proxmox.
3. The browser receives the one-time VNC password plus an encrypted Cloud Portal token that expires after 20 seconds. The Proxmox API token and VNC ticket are not exposed as plaintext to the browser.
4. noVNC is loaded through the authenticated same-origin `/console/novnc/...` asset proxy.
5. Apache upgrades `/console/ws/...` to the local console gateway.
6. `bin/console-gateway.php` decrypts the short-lived token, authenticates the upstream Proxmox WebSocket with the server-side API token and tunnels WebSocket frames in both directions.

Serial-only VMs continue to use the existing external console fallback.

## Apache modules

Embedded noVNC requires:

```bash
sudo a2enmod rewrite proxy proxy_wstunnel
sudo systemctl restart apache2
```

Both supported `.htaccess` layouts proxy only `/console/ws/*` to `127.0.0.1:6080`. The gateway should not be exposed directly to the network.

If `mod_proxy_wstunnel` or the gateway is unavailable, the embedded console fails closed and the UI exposes the existing SPICE/Proxmox fallback.

## Console gateway

Run manually for a development installation:

```bash
php bin/console-gateway.php --listen=127.0.0.1:6080
```

For a systemd installation, copy and adjust the included example:

```bash
sudo cp config/algen-cloud-console-gateway.service.example /etc/systemd/system/algen-cloud-console-gateway.service
sudo systemctl daemon-reload
sudo systemctl enable --now algen-cloud-console-gateway.service
```

The example assumes the application is installed in `/var/www/cloudportal` and runs as `www-data`. Change `WorkingDirectory`, `ExecStart`, `User` and `Group` to match the installation.

The gateway requires read access to the Cloud Portal runtime configuration and database connectivity. It does not require write access to the application tree.

## TLS

The gateway honors the `verify_ssl` setting of the selected Proxmox connection. Keep certificate verification enabled in production. Disable it only for an isolated lab using a certificate that cannot be validated by the portal host.

## Security properties

- browser code never receives the Proxmox API token,
- VNC tickets are encrypted inside short-lived gateway tokens,
- tokens expire after 20 seconds,
- noVNC asset paths are allowlisted and traversal is rejected,
- the WebSocket listener binds to loopback by default,
- portal VM console creation uses the existing `vm.operate` permission and ownership boundary,
- live Proxmox console creation is administrator-only,
- console creation and firewall changes are audited.
