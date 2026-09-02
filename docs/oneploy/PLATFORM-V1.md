# OnePloy Platform V1

Status date: 2026-09-02

This document is the product specification for the OnePloy commercial control plane. Implementation status is marked as **shipped**, **partial**, **planned**, or **externally blocked**.

## Customer UI Compatibility Contract

Preserve the mature Coolify-derived customer UX and navigation. Extend it contextually rather than replacing it.

Do not redesign the working customer control panel. Customers already understand Dashboard, Projects, Servers, Sources, Destinations, S3 Storages, Shared Variables, Notifications, Keys & Tokens, Tags, Terminal, Profile, Teams, and Settings, plus resource tabs such as Configuration, Domains, Environment Variables, Logs, Backups, Proxy, Terminal, Security, and Metrics.

SaaS surfaces (Marketplace, Domains, Usage, Billing) are added to that navigation. They must not duplicate deployment logs, environment variables, or backup mechanics.

## Identity

Customer-facing surfaces use OnePloy name, logo, documentation, support, and status. Coolify product branding, sponsor prompts, CoolLabs update channels, and upstream documentation-as-product-docs are removed from the customer UI. Apache-2.0 attribution remains. Internal paths such as `/data/coolify` stay for engine compatibility.

## Tenant model

Team is the tenant. Do not introduce a parallel Tenant aggregate.

Platform Super Admin is distinct from a team owner. Reseller is its own scope.

## Production installation

OnePloy production must run OnePloy-built images from this repository, not `coollabsio/coolify`.

See [INSTALL.md](INSTALL.md) for the Ubuntu one-command installer with SSL.

## Feature matrix (V1)

| Area | State |
| --- | --- |
| Inherited Git/Docker/Compose/DB/backup/proxy/SSL/MCP engine | shipped (reused) |
| OnePloy identity and logos | shipped |
| Local template catalogue (no CoolLabs CDN) | shipped |
| OnePloy installer/updater | shipped (source-build on VPS) |
| Team tenancy, reseller, quotas | shipped / partial (quotas exist; reservation kernel added) |
| Product/plan/price catalog | shipped (DB-backed, seeded) |
| Checkout sessions + webhook idempotency | partial (needs live payment keys) |
| Stripe/Razorpay/PayPal live capture | externally blocked (credentials) |
| ConnectReseller live register | externally blocked (API key + allowlisted IP) |
| Authoritative PowerDNS | planned / externally blocked (nameserver fleet) |
| Managed WordPress/Minecraft/n8n productization | partial (templates exist; commercial workflow started) |
| AI Gateway live providers | partial / externally blocked (provider keys) |
| Multi-arch GHCR release pipeline | planned (VPS builds from git today) |

Do not advertise scale-to-zero, edge, or serverless until those semantics exist.
