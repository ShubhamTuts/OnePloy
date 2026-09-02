<div align="center">
  <img src="public/oneploy-logo.png" alt="OnePloy" width="160" />

# OnePloy

Self-hosted, multi-tenant server and application management for VPS, cloud, and on-premises infrastructure.
</div>

## Installation (Ubuntu VPS)

Point `app.oneploy.dev` (or your hostname) at the VPS, then:

```bash
curl -fsSL https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/scripts/oneploy-install.sh | sudo bash -s -- --fqdn app.oneploy.dev --email admin@oneploy.dev
```

This builds OnePloy from source on the server (not CoolLabs images) and enables HTTPS through the inherited Traefik proxy once DNS is live. Full notes: [docs/oneploy/INSTALL.md](docs/oneploy/INSTALL.md).

## Current foundation

OnePloy is being developed as a modular Laravel control plane on top of the mature Coolify codebase. The current repository includes:

- SSH-based management of VPS, bare-metal, local-rack, and cloud servers inherited from the upstream platform.
- Applications, services, databases, deployments, backups, proxies, notifications, API access, and MCP support.
- Four platform levels: Super Admin, Reseller, Tenant, and Sub-user, with team roles including owner, admin, deployer, and member.
- Reseller-owned tenants, lifecycle states, plan assignment, per-tenant quotas, and reseller aggregate quota fields.
- Atomic reseller tenant creation with ownership attribution, default-plan assignment, capacity locking, and audit events.
- Suspended-tenant enforcement for resource creation plus application and service deployment entry points.
- Fork-safe defaults: upstream telemetry and update installation are disabled, and inherited publishing workflows are archived outside GitHub's active workflow directory.

See [the OnePloy delivery roadmap](docs/oneploy/ROADMAP.md) for the implemented/pending boundary.

## Development

The application requires PHP 8.4, Node.js, Docker, and the extensions documented in the repository development configuration.

```bash
cp .env.development.example .env
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

Run the focused OnePloy tests with:

```bash
php artisan test tests/Feature/OnePloyTenancyTest.php tests/Feature/CreateTenantActionTest.php
```

## Installation status

Use `scripts/oneploy-install.sh` for production. The inherited `scripts/install.sh` still talks to CoolLabs and must not be used for OnePloy.

## Security

Never commit credentials. Configure secrets through environment variables or the encrypted credential models. Report OnePloy issues through the [repository issue tracker](https://github.com/ShubhamTuts/OnePloy/issues). Upstream security issues should also be reported through the upstream project's published security process.

## License and upstream attribution

OnePloy is licensed under the Apache License 2.0. This repository is derived from [Coolify](https://github.com/coollabsio/coolify); its license and copyright notices are retained. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

OnePloy is an independent fork and is not endorsed by or affiliated with Coolify or CoolLabs.
