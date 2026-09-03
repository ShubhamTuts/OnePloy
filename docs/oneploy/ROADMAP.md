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
- ConnectReseller v11 ESHOP availability and paid registration with encrypted registrant data, explicit customer consent, globally unique reservations, and non-retrying manual-review safety for uncertain purchases.
- PayPal-to-registrar provisioning job with lifecycle status, automatic customer confirmation, and authenticated Storefront checkout/status endpoints.
- Bundled official open-source PowerDNS Authoritative Server, automatic API credentials, persistent zone storage, and tenant-scoped zone activation.

## Still required before calling the business “fully live”

- Live PayPal credentials, production webhook registration, and provider acceptance transaction (**external**).
- Live Stripe/Razorpay keys and signed-webhook acceptance against real provider events (**external**).
- ConnectReseller production key, funded balance, source-IP allowlist, supported-TLD/KYC acceptance, and one controlled live purchase (**external**).
- Public nameserver host records, registrar glue, delegation, and a second authoritative server for DNS high availability (**external**).
- GHCR multi-arch signed release channel (today: build-on-VPS).
- Deeper managed-app runbooks (WordPress/Minecraft/n8n) on top of existing templates.
- Cross-tenant negative test suite in CI.

## Release gates

The control plane can be installed and used for Git/Docker/Compose hosting after the Ubuntu installer and HTTPS health gate succeed. PayPal checkout, ConnectReseller registration, customer confirmation, and bundled PowerDNS orchestration are repository-complete. Charging customers and registering real domains remain intentionally gated on operator credentials, retail prices, provider balance/IP allowlisting, nameserver glue/delegation, and live acceptance.
