# OnePloy delivery roadmap

Status date: 2026-09-03

This roadmap tracks delivery against the [OnePloy Platform V1](PLATFORM-V1.md) product and architecture contract.

This document records the verified repository boundary. A feature is marked complete only when its schema, authorization, user path, tests, operations, and rollback behavior exist.

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
- PayPal Orders v2 checkout with server-side capture, PayPal webhook verification, strict checkout/amount/currency binding, replay protection, and five-minute reconciliation.
- Product-scoped subscriptions so a second product purchase does not overwrite an existing app-hosting subscription.
- ConnectReseller read-only domain availability through the production API path.
- PowerDNS authoritative zone adapter and tenant-scoped activation for registered domains.

## Still required before calling the business “fully live”

- Live PayPal credentials, production webhook registration, and provider acceptance transaction (**external**).
- Live Stripe/Razorpay keys and signed-webhook acceptance against real provider events (**external**).
- ConnectReseller production key, Brand ID, funded balance, source-IP allowlist, explicit registrant-data authorization, and live purchase acceptance (**external**).
- PowerDNS nameserver fleet, API key, registrar glue, delegation, and live failover acceptance (**external**).
- GHCR multi-arch signed release channel (today: build-on-VPS).
- Deeper managed-app runbooks (WordPress/Minecraft/n8n) on top of existing templates.
- Cross-tenant negative test suite in CI.

## Release gates

The control plane can be installed and used for Git/Docker/Compose hosting after the Ubuntu installer and HTTPS health gate succeed. PayPal checkout and PowerDNS zone orchestration are repository-complete at the stated boundary, but charging customers and serving public authoritative DNS remain gated on credentials and live acceptance. ConnectReseller purchases remain gated on explicit authorization for the registrant-data transfer and provider acceptance.
