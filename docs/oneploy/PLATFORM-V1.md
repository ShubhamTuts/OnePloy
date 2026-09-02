# OnePloy Platform V1

Status: implementation contract

OnePloy is an independent hosting and application control plane for developers, agencies, resellers, and software platforms. It must be able to run on OnePloy-managed infrastructure or customer-owned servers without any runtime dependency on Coolify/CoolLabs images, CDNs, update feeds, telemetry, documentation, or hosted services.

Apache-2.0 attribution for inherited source remains in LICENSE/NOTICE where legally required. Attribution is not a runtime dependency and must not be presented as OnePloy branding.

## Product principles

1. One control plane, many compute nodes.
2. Git commit is the source of every release and deployment revision.
3. Every billable, destructive, security-sensitive, domain, DNS, and infrastructure action is auditable.
4. Marketing sites never contain product truth. Plans, prices, currencies, billing periods, domain pricing, availability, stock-like capacity, feature flags, and checkout state come from the OnePloy API.
5. Provider integrations are adapters. OnePloy owns the internal contracts.
6. Customers can use OnePloy infrastructure or connect their own VPS/cloud/on-prem server.
7. The UI uses plain language first; infrastructure jargon is explained rather than assumed.
8. AI can recommend and prepare actions. Billable or destructive actions require explicit confirmation unless an administrator has created an approved automation policy.

## Platform roles

- Super Admin: owns the OnePloy installation, global providers, plans, pricing, release channels, risk controls, support access, DNS infrastructure, registrar credentials, payment providers, AI providers, and system policy.
- Reseller: owns a branded tenant portfolio, optional custom domain, optional vanity name servers, retail pricing/markup, tenant quotas, support and wallet/credit policy within Super Admin limits.
- Tenant: owns projects, environments, deployments, domains, databases, members, API keys, usage and invoices.
- Sub-user: tenant-scoped member with owner/admin/deployer/member or future fine-grained permissions.
- Service account: non-human principal with scoped, expiring credentials.

## Control-plane topology

The management node owns identity, policy, billing, usage, domains, DNS intent, deployment intent, scheduling, audit, release state and provider credentials. It should not automatically host customer workloads.

Compute nodes are enrolled with signed credentials and report health, CPU, memory, disk, architecture, labels, availability zone, public/private addresses, maintenance state, drain state and cost metadata. Placement decisions are persisted and explainable.

Supported compute modes:

- OnePloy-managed compute pool
- Bring-your-own Ubuntu/Debian server over SSH
- Cloud-provider provisioned VM through an adapter
- Bare metal/on-prem node
- Future ephemeral/sandbox worker pool

## Deployment product

A project may contain multiple environments such as production, preview, staging and development.

Deployment sources:

- GitHub
- GitLab
- Bitbucket
- Generic Git SSH/HTTPS
- Direct Docker image
- Docker Compose
- Archive/upload through API
- Platform-generated source from an AI/coding agent

Build modes:

- Dockerfile
- Docker Compose
- Railpack
- Nixpacks
- Buildpacks
- Static output
- Framework presets

Deployment capabilities:

- branch and commit deploys
- monorepo root selection
- environment variables and encrypted secrets
- preview deployments per branch/PR
- custom build/start commands
- health checks
- zero/low-downtime replacement
- rollbacks to a previous immutable revision
- deployment protection
- deployment webhooks
- resource limits
- build cache
- private registry credentials
- scheduled deployments
- manual approvals
- deployment comments/audit timeline

## Compute scheduler

Required records:

- ComputePool
- ComputeNode
- NodeCapacitySnapshot
- WorkloadReservation
- PlacementDecision
- WorkloadRuntime
- DrainOperation

Admission control must reserve quota and capacity before the workload is created. Concurrent requests must not overbook a tenant or node.

Initial placement score should consider:

- node health
- architecture compatibility
- available CPU
- available memory
- available disk
- tenant/project placement policy
- labels/region/zone
- current workload count
- estimated cost
- anti-affinity/load spreading
- maintenance/drain state

Every decision records the inputs and score so support can answer “why was this deployed here?”.

## Managed data and storage

V1 product surface:

- PostgreSQL
- MySQL/MariaDB
- Redis/Valkey
- MongoDB where supported
- S3-compatible object storage integration
- persistent Docker volumes
- scheduled backups
- point-in-time/continuous backup adapters where available
- restore into same or different node
- backup retention policy
- encryption and credential rotation

Database/storage actions must be entitlement and quota checked through the same kernel as applications.

## Domains and DNS

Use two independent contracts:

1. Registrar: availability and lifecycle of a registered domain.
2. DNS provider: authoritative DNS zones and records.

Never infer purchasable availability from WHOIS alone. Use registrar availability for checkout. Use RDAP as the preferred standards-based registration-information lookup, with WHOIS only as a compatibility fallback when needed.

Registrar contract:

- search availability
- suggestions
- TLD catalogue
- registration price
- renewal price
- transfer price
- restore/redemption price where exposed
- register
- renew
- transfer in
- transfer status
- domain lock
- privacy
- contacts
- nameservers
- child/host nameservers (glue records)
- auth/EPP code where permitted
- expiry and grace/redemption state
- registrar wallet/funds
- webhooks/reconciliation

Initial registrar adapter: ConnectReseller official API. Credentials are encrypted, redacted from logs, and never included in queue payloads in plaintext.

DNS contract:

- zones
- A/AAAA/CNAME/TXT/MX/CAA/SRV/NS records
- wildcard records
- DNSSEC state and DS data
- record TTL
- validation
- import/export
- propagation checks
- automatic deployment-domain records

### OnePloy authoritative DNS

OnePloy should own an optional authoritative DNS service instead of forcing customers to use a third-party DNS provider. Recommended implementation is a separate PowerDNS Authoritative cluster backed by replicated PostgreSQL and accessed only through a OnePloy DNS service/API.

Default branded name servers can be:

- ns1.oneploy.dev
- ns2.oneploy.dev
- ns3.oneploy.dev
- ns4.oneploy.dev

Run these on independent failure domains. Production should move toward Anycast or a managed multi-region front for high availability.

### Reseller vanity name servers

Resellers may configure names such as ns1.example.com and ns2.example.com. The system guides the reseller through creating child name servers/glue at the registrar and delegates the vanity names to OnePloy DNS addresses. The ConnectReseller adapter should support its child-name-server operations where available.

The UI should call this “Custom domain name servers” and explain what it changes.

## Commerce and billing kernel

Never hardcode plans into marketing templates.

Core records:

- Product
- Plan
- immutable PlanVersion
- Price
- Entitlement
- Subscription
- Order
- OrderLine
- Invoice
- InvoiceLine
- Payment
- Refund
- CreditNote
- Wallet
- WalletEntry
- UsageLedger
- Meter
- MeterEvent
- Coupon/Promotion
- TaxRule/TaxSnapshot
- CheckoutSession

All money is stored as integer minor units plus ISO currency. Historical invoice/price snapshots are immutable.

A plan can have many prices, for example monthly INR, yearly INR, monthly USD, yearly USD and EUR equivalents. The admin may enter explicit localized prices. Optional FX conversion may create a proposed price but must not silently rewrite existing subscriptions.

Billing periods:

- one-time
- monthly
- quarterly
- half-yearly
- yearly
- custom fixed duration where a product supports it
- usage/pay-as-you-go

Payment adapters:

- Stripe
- Razorpay
- PayPal
- manual/offline payment
- future provider adapters behind the same payment contract

Checkout should be hosted by OnePloy so WordPress/React marketing sites do not handle card data directly. Payment-provider hosted elements/pages may be used by the OnePloy checkout service.

### Credits

Separate money from promotional/product credits.

- Wallet balance: real monetary value/accounting entries.
- Platform credits: admin-defined unit for AI, build minutes, promotional usage or bundled allowances.
- Metered usage: measured raw units before conversion to price or credits.

Every credit grant/consume/expire/refund entry is append-only and attributable to a user, job or order.

## Pricing and entitlement examples

Entitlements should support numeric limits and booleans rather than plan-name conditionals:

- projects.max
- environments.max
- applications.max
- databases.max
- services.max
- members.max
- domains.max
- custom_domains.enabled
- preview_deployments.enabled
- build_minutes.included
- compute_cpu_seconds.included
- bandwidth_bytes.included
- storage_bytes.included
- backup_bytes.included
- ai_tokens.included
- ai_gateway.enabled
- mcp.enabled
- api.enabled
- reseller.enabled
- custom_branding.enabled
- vanity_nameservers.enabled
- audit_retention_days

Entitlement checks must run at web, REST API, MCP, webhook, scheduler and queue boundaries.

## Public Storefront API

Marketing websites consume a read-oriented Storefront API. Sensitive administration stays on the authenticated Platform API.

Suggested endpoints:

- GET /api/storefront/v1/catalog
- GET /api/storefront/v1/plans
- GET /api/storefront/v1/plans/{slug}
- GET /api/storefront/v1/prices?currency=INR&period=monthly
- GET /api/storefront/v1/currencies
- GET /api/storefront/v1/locales
- POST /api/storefront/v1/domain/search
- GET /api/storefront/v1/domain/tlds
- POST /api/storefront/v1/checkout/sessions
- GET /api/storefront/v1/checkout/sessions/{token}
- POST /api/storefront/v1/coupons/validate
- GET /api/storefront/v1/platform/status

Storefront responses include cache headers and ETags. Checkout writes use idempotency keys.

## Currency selection

Selection order:

1. explicit user selection stored in cookie/local storage/account
2. account billing currency when signed in
3. site integration default
4. geo suggestion
5. OnePloy global default

Geo detection must never override an explicit selection. Prefer a reverse-proxy country header when available; otherwise use a locally hosted GeoIP database rather than making every request depend on a remote geolocation API.

## Multilingual support

The platform and Storefront API are locale aware.

- Accept-Language support
- account locale
- integration-specific allowed locales
- translated product names/descriptions/features
- translated checkout labels and transactional email templates
- locale-safe date/number/currency formatting
- IDN/Punycode-safe domain handling
- RTL-ready UI primitives

The pricing API returns structured values; the client formats them using the locale rather than receiving preformatted HTML.

## WordPress Bridge plugin

Plugin name: OnePloy Bridge

The plugin is a storefront and lead/order bridge, not a second billing system.

Connection settings:

- OnePloy base URL
- site/integration ID
- public storefront key
- encrypted server-side secret for privileged bridge actions
- allowed locale/currency overrides
- webhook signing secret

Authentication uses short-lived signed requests or scoped integration tokens. Secrets are never exposed to browser JavaScript.

### Blocks and shortcodes

- [oneploy_pricing]
- [oneploy_domain_search]
- [oneploy_checkout]
- [oneploy_plan slug="..."]
- [oneploy_status]
- [oneploy_deploy_button]

Provide Gutenberg blocks and Elementor widgets for the same components. Components fetch live data from Storefront API and support design controls without duplicating prices in WordPress.

### Pricing cards

Pricing cards support:

- plan selection
- monthly/yearly/custom durations returned by API
- currency switcher
- locale switcher when enabled
- compare features
- usage inclusions
- sale/original price when represented by an active promotion
- reseller-specific catalogue
- CTA to hosted OnePloy checkout

### Domain search

The widget supports:

- exact availability
- suggestions
- TLD price and renewal price
- registration duration
- privacy availability
- add to checkout
- IDN normalization

### One-page checkout bridge

The bridge creates a CheckoutSession in OnePloy and either renders a secure OnePloy-hosted checkout in an allowed embedded flow or redirects to the hosted checkout URL. The checkout may contain plan/subscription items, domains, add-ons, credits and coupons in one order when provider rules allow it.

Do not collect raw card details in the WordPress plugin.

### Forms: “any form” architecture

Provide native adapters for common form products and a generic adapter rather than hardcoding one form builder.

Native adapters:

- Elementor Forms
- Contact Form 7
- Gravity Forms
- WPForms
- Fluent Forms
- Forminator
- Ninja Forms
- WooCommerce checkout/order hooks where used as a lead/order source

Generic adapter:

- administrator selects a CSS form selector or webhook mode
- maps fields to canonical OnePloy fields
- selects action: lead, checkout session, support ticket, provisioning request, domain-interest request, webhook workflow
- supports consent field mapping
- validates signatures for server-to-server webhooks
- can run after successful submission without replacing the original form’s storage/email behavior

Canonical fields include name, email, phone, company, country, locale, currency, plan, billing period, domain, coupon, referral, reseller, consent and arbitrary metadata.

## React/JavaScript integration

Ship an official @oneploy/storefront package with:

- createOnePloyClient()
- listPlans()
- listPrices()
- searchDomains()
- createCheckoutSession()
- getPlatformStatus()
- React hooks/components for PricingTable, CurrencySwitcher, DomainSearch and CheckoutButton

The package calls the same Storefront API as WordPress.

## Webhooks

Outbound events are signed and retryable:

- checkout.created
- order.paid
- subscription.created
- subscription.updated
- subscription.cancelled
- invoice.created
- invoice.paid
- payment.failed
- domain.registered
- domain.renewed
- domain.expiring
- deployment.created
- deployment.ready
- deployment.failed
- server.offline
- usage.threshold
- support.ticket.created

Each delivery has an event id, idempotency key, timestamp, signature, attempt count and replay UI.

## Observability

Platform observability must cover both customer workloads and OnePloy itself:

- deployment logs
- runtime logs
- build logs
- HTTP request metrics
- status code/error rate
- latency
- CPU/memory/disk
- bandwidth
- database health
- queue health
- node health
- certificate expiry
- domain/DNS state
- billing/metering lag
- webhook failures
- audit events

Use OpenTelemetry-compatible internal contracts so customers can export logs/metrics/traces without OnePloy being locked to one vendor.

## AI Gateway

OnePloy AI Gateway is a provider-neutral, tenant-scoped gateway for application and platform AI workloads.

Provider adapters may include OpenAI, Anthropic, Google, xAI, DeepSeek, Groq, OpenRouter or self-hosted OpenAI-compatible endpoints, subject to administrator configuration.

Capabilities:

- one endpoint
- model aliases
- routing/fallback
- per-team/project/key budgets
- token/usage metering
- provider cost tables with versioned effective dates
- request logs with configurable retention/redaction
- rate limits
- project API keys
- usage webhooks
- admin margin/markup
- reseller margin/markup
- optional customer-supplied provider key

Do not log secrets or unredacted sensitive prompts by default.

## MCP and agent control

Existing MCP support becomes an explicit OnePloy product surface.

Tool classes:

- inspect projects/deployments/logs
- inspect server health
- create project/app/database/service
- prepare deploy
- trigger deploy
- rollback
- manage environment variables
- domain search
- prepare domain purchase
- DNS record changes
- billing/usage lookup
- support diagnostics

Policy levels:

- read only
- safe write
- billable write requires confirmation
- destructive write requires confirmation
- administrator-approved automation

Every MCP/agent action records principal, tenant, tool, arguments hash/redacted payload, policy decision, confirmation, result and resulting resource ids.

## Admin AI operator

Super Admin can enable an AI operator for diagnostics and controlled automation. It can summarize failures, explain placement, propose fixes, generate deployment configuration, inspect logs, draft incident notices and prepare infrastructure/domain actions.

It must never silently purchase domains, delete workloads, rotate credentials, change billing, refund money, modify name servers, or change global security policy without an applicable explicit approval policy.

## Admin console

Super Admin sections:

- Overview
- Customers/Tenants
- Resellers
- Users
- Projects and workloads
- Compute pools and nodes
- Domains
- DNS
- Registrar providers
- Products and plans
- Prices
- Promotions
- Orders
- Subscriptions
- Invoices
- Payments/refunds
- Wallets/credits
- Usage/meters
- Payment providers
- AI providers and models
- AI pricing and budgets
- MCP policy
- API integrations
- Webhooks
- Email/SMS/notification providers
- Support/tickets
- Incidents/status
- Templates/marketplace
- Languages/currencies
- Tax settings
- Security/audit
- Releases/upgrade channel
- System health

## Reseller console

A reseller can have:

- reseller storefront integration keys
- custom logo/colors
- custom customer-facing domain
- custom support identity
- custom plan catalogue subset
- retail markup or explicit retail prices
- tenant limits
- wallet/credit controls
- invoices and usage views
- vanity name servers where globally enabled

Wholesale cost and reseller retail charge are separate immutable ledger entries.

## Security baseline

- MFA support
- scoped API tokens
- short-lived service tokens where possible
- encrypted provider credentials
- secret redaction
- CSRF/session protection
- tenant-bound authorization at every boundary
- webhook signatures
- idempotency for external side effects
- rate limiting
- append-only audit
- dependency/container scanning
- signed releases
- SBOM/provenance
- backup-before-migration
- disaster recovery test
- no public PostgreSQL/Redis ports
- support impersonation only with reason, time limit and audit

## Installation and releases

Production is image based, not `git pull` based. Git remains the source of truth: every release image maps to an immutable commit and Git tag.

Owned images:

- ghcr.io/shubhamtuts/oneploy
- ghcr.io/shubhamtuts/oneploy-helper
- ghcr.io/shubhamtuts/oneploy-realtime

Release channels:

- stable
- beta
- nightly/development

Installer requirements:

- root check
- supported OS check
- CPU/memory/disk preflight
- Docker install/validation
- automatic public IPv4/IPv6 and primary private IP detection
- no Coolify URLs/images/update feeds
- /data/oneploy filesystem
- OnePloy-owned Docker network
- secure secret generation
- local-server SSH key generation
- Compose/manifest download from OnePloy-controlled release source
- health check
- print detected IP and first-login URL
- optional noninteractive environment variables

Updater requirements:

- fetch OnePloy release manifest
- resolve requested version/channel
- verify manifest/artifact signatures or digests
- backup database and environment
- pull exact immutable image tags/digests
- run preflight
- apply migrations
- health check
- automatic rollback to previous image set when safe
- persistent status and logs

The in-app release page shows current version, commit, channel, published date, security notes, target version and rollback target.

## API versioning

- existing authenticated infrastructure API remains under /api/v1 during migration
- new commerce/storefront surfaces use explicit namespaces
- breaking changes require a new major API version
- webhooks carry schema_version
- SDKs pin an API version

## Required implementation sequence

P0: release independence

- OnePloy images for app/helper/realtime
- OnePloy installer/updater
- OnePloy release manifest
- owned runtime paths and image defaults
- fresh-server test
- upgrade/rollback test

P1: entitlement and commerce kernel

- Product/PlanVersion/Price/Entitlement
- Subscription/Order/Invoice/Payment/Wallet/UsageLedger
- Stripe/Razorpay/PayPal/manual adapters
- global entitlement enforcement

P2: Storefront + WordPress/React bridge

- catalogue/pricing/currency/locale API
- checkout sessions
- domain search API
- WordPress plugin
- JS/React SDK
- signed webhooks

P3: domains and DNS

- ConnectReseller adapter
- RDAP lookup
- authoritative DNS service
- deployment-domain automation
- custom name servers and reseller vanity name servers

P4: scheduler and managed compute

- pools/nodes/reservations/placement/drain
- admission control and quotas
- workload metering
- backup/restore across nodes

P5: AI gateway, MCP and observability

- provider gateway
- budgets/cost accounting
- tenant-scoped MCP policies
- AI operator
- logs/metrics/traces/export

P6: marketplace and platform ecosystem

- templates
- integrations
- reseller marketplace
- platform SDK
- customer-generated app hosting patterns

## Production definition of done

OnePloy V1 is production-ready only when fresh installation, upgrade, rollback, cross-tenant authorization, entitlement concurrency, payment reconciliation, domain lifecycle, DNS reconciliation, backup restore, node failure, webhook retry, AI budget enforcement and security scanning are automated in CI or an acceptance environment and publish evidence for the release.
