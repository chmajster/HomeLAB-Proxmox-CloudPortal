# Security and observability hardening

## MFA

Cloud Portal supports TOTP MFA compatible with common Authenticator applications.
The TOTP secret is encrypted with the portal encryption key before it is stored in the
database. Recovery codes are never stored in plaintext; each code is stored as a
one-way password hash and becomes unusable immediately after successful use.

The authentication flow is intentionally two-stage:

1. username/e-mail and password are verified,
2. an MFA-enabled account receives a five-minute pending challenge,
3. no authenticated `user_id` is written to the session until TOTP or a recovery code
   succeeds,
4. successful MFA regenerates the PHP session identifier,
5. failed MFA attempts are persisted through the same database-backed limiter used for
   login throttling, so restarting a browser session does not reset the brute-force
   budget.

Self-service API:

```text
GET    /api/v1/me/security
POST   /api/v1/me/mfa/setup
POST   /api/v1/me/mfa/enable
DELETE /api/v1/me/mfa
POST   /api/v1/me/password
```

MFA setup requires `current_password`. The setup response contains the Base32 secret,
`otpauth://` URI and recovery codes. Store the recovery codes before enabling MFA.
Enabling requires the first valid TOTP code.

Disabling MFA requires both the current password and a valid TOTP/recovery code. It
increments `session_version` and logs the user out, invalidating all existing sessions.
Changing the password also increments `session_version` and requires reauthentication.

## Prometheus metrics

`GET /metrics` exports aggregate operational metrics including readiness, worker status,
queue states, VM states, IPAM usage, failed webhook deliveries and the number of active
MFA-enabled users.

The endpoint is not anonymous. Access is allowed to an authenticated portal
administrator or with a Bearer token derived from the installation encryption key.
Print the token locally on the portal server:

```bash
php bin/metrics-token.php
```

Prometheus example:

```yaml
scrape_configs:
  - job_name: algen-cloudportal
    metrics_path: /metrics
    authorization:
      type: Bearer
      credentials: "REPLACE_WITH_OUTPUT_OF_METRICS_TOKEN_CLI"
    static_configs:
      - targets: ['cloudportal.example.com']
```

Treat the metrics token as a production credential. Rotating the portal encryption key
also rotates this token.

## Proxmox capability preflight

Before treating a connection as provisioning-ready, administrators can call:

```text
GET /api/v1/admin/proxmox/{connectionId}/preflight
```

The check validates read access to version, cluster status, nodes and storage, then
queries `/access/permissions`. The report separates:

- `api_readiness`: required read endpoints are accessible,
- `permission_readiness`: required provisioning privileges can be proven,
- `ready_for_provisioning`: both checks are true,
- `missing_privileges`: privileges not visible to the current token.

The preflight is conservative. If the token cannot read its effective permission map,
`permission_readiness` is `null` and the connection is not declared provisioning-ready.
The privilege report is a preflight signal, not a replacement for Proxmox path-scoped
ACL design; privileges still need to cover the actual templates, nodes, storages and
VM paths used by the portal.

## Migration 1.6.0

Migration `1.6.0.sql` adds encrypted MFA state to `users` and the
`user_mfa_recovery_codes` table. Each column is added by a separate statement so the
migration can safely resume after a partial DDL failure on MySQL/MariaDB.
