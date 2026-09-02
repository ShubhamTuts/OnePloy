# OnePloy delivery roadmap

Status date: 2026-09-02

See [PLATFORM-V1.md](PLATFORM-V1.md) for the product contract, including the **Customer UI Compatibility Contract**.

## Architecture decision

OnePloy remains a modular Laravel control plane. A dedicated management node owns identity, policy, scheduling, billing, and audit state. Ubuntu VPS, cloud VM, bare-metal, and rack servers join through SSH and Docker. Internal Docker names (`coolify`, `/data/coolify`) stay for engine compatibility. Production artifacts are OnePloy-built.

## Verified now

- Inherited server, application, service, database, deployment, proxy, backup, notification, API, and MCP engine.
- Super Admin / Reseller / Tenant roles on Team tenancy, quotas, and reseller-owned tenant creation.
- OnePloy branding (name, logos, favicon), sponsor prompts removed from customer UI.
- OnePloy-owned Ubuntu installer and updater that build from `ShubhamTuts/OnePloy` instead of CoolLabs images/CDN.
- Local Compose template catalogue (CoolLabs template CDN not required).
- Database-backed product/plan/price catalog, checkout sessions, payment webhook inbox, domain records, compute-pool tables, marketplace metadata.
- Customer nav additions: Marketplace, Domains, Usage, Billing.
- Public Storefront API: `GET /api/storefront/v1/catalogue`.

## Still required before calling the business “fully live”

- Live Stripe/Razorpay/PayPal keys and signed webhook verification against real events (**external**).
- ConnectReseller production key + IP allowlist for paid domain registration (**external**).
- PowerDNS nameserver fleet for authoritative DNS (**external**).
- GHCR multi-arch signed release channel (today: build-on-VPS).
- Deeper managed-app runbooks (WordPress/Minecraft/n8n) on top of existing templates.
- Cross-tenant negative test suite in CI.

## Release gates

The control plane can be installed and used for Git/Docker/Compose hosting after the Ubuntu installer succeeds. Charging cards, registering domains at the registrar, and serving DNS are gated on the external credentials above.
