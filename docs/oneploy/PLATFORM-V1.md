# OnePloy Platform V1

Status date: 2026-09-05

This document is the product and architecture contract for OnePloy V1. It records both the intended platform and the verified implementation boundary. A listed capability is not automatically shipped: the status labels below are authoritative and must be updated only when persistence, authorization, application logic, operations, recovery, and tests exist.

## Status vocabulary

- **SHIPPED** — implemented and covered by repository tests at the stated boundary.
- **PARTIAL** — useful implementation exists, but one or more production requirements remain.
- **PLANNED** — accepted V1 direction with no production-ready implementation yet.
- **EXTERNAL BLOCKER** — repository work is complete but a credential, licensed service, or live infrastructure acceptance environment is still required.

## Product principles

### Customer UI Compatibility Contract

**Preserve the mature Coolify-derived customer UX and navigation; extend it contextually rather than replacing it.**

OnePloy retains the operationally dense Dashboard, Projects, Servers, Sources, Destinations, S3 Storages, Shared Variables, Notifications, Keys & Tokens, Tags, Terminal, Profile, Teams, and Settings experience. Existing application, service, database, deployment, proxy, backup, log, environment-variable, and terminal screens remain the primary operational surfaces. SaaS features such as Marketplace, Domains, Usage, Billing, AI, API/MCP, and Managed Apps must be added in the same visual language and must not duplicate mature controls.

Customer-facing identity must use OnePloy branding, links, documentation, support, status, and legal destinations. Customer-visible upstream donation, sponsorship, feedback, release, and cloud-sales calls to action are not part of OnePloy. Apache-2.0 attribution and compatibility-sensitive internal identifiers remain where legally or operationally required.

### Architecture contracts

1. `Team` remains the tenant ownership anchor. OnePloy must not introduce a parallel tenant aggregate.
2. `Server`, `Application`, `Service`, database, deployment, template, proxy, backup, notification, API, and MCP capabilities are extended rather than rewritten.
3. Platform authorization is distinct from tenant roles. Platform Super Admin, Support, Finance, and Operations permissions must be explicit, server-enforced, and auditable.
4. Reseller access is a separate scope and never grants infrastructure-operator privileges.
5. New SaaS business operations live behind reusable domain/application services suitable for Livewire, REST, MCP, CLI, WordPress, and SDK clients.
6. Compatibility-sensitive paths, environment keys, database columns, Docker services, networks, and parser variables may retain legacy names until a tested, reversible migration provides customer value.
7. OnePloy does not describe continuously running containers as serverless and does not claim scale-to-zero, edge, or global runtime semantics until verified.

## Repository audit

| Capability | State | V1 decision |
| --- | --- | --- |
| Team tenancy and inherited resource ownership | SHIPPED | Reuse `Team`; strengthen every execution boundary with negative isolation tests. |
| Git, Dockerfile, image, Compose, service, and database deployment | SHIPPED | Preserve the inherited deployment engine and regression-test it. |
| Preview deployments, domains, SSL, logs, terminal, backups, S3, notifications | PARTIAL | Reuse operational implementations; add commercial policy, entitlement, audit, and acceptance coverage. |
| Platform/reseller/tenant role foundation | PARTIAL | Extend the existing role and reseller foundation with scoped workspaces and privileged-action audit. |
| API and Laravel MCP server | PARTIAL | Extend existing interfaces; do not create a parallel MCP server. |
| Static plan and quota behavior | PARTIAL | Migrate compatibly to persisted, versioned products and generic entitlements. |
| Commerce, provider-neutral billing, and usage ledger | PARTIAL | Catalog, checkout, signed Stripe/Razorpay success events, orders, invoices, payments, subscriptions, wallets, and usage persistence exist; refunds, tax, dunning, reconciliation, and live-provider acceptance remain. |
| Compute pools and managed placement | PLANNED | Extend `Server` with pools, capacity snapshots, reservations, decisions, and drain state. |
| Domains, registrar integration, and authoritative DNS orchestration | PARTIAL | Availability, consented paid ConnectReseller registration, encrypted contacts, lifecycle safety, Storefront API, and bundled PowerDNS are shipped; renewals, transfers, registrar locks, secondary authoritative DNS, and live-provider acceptance remain. |
| Managed WordPress, games, n8n, and AI applications | PARTIAL | Keep deployable templates; certify and productize only tested managed workflows. |
| AI Gateway and admin AI | PLANNED | Provider-neutral routing with budgets, versioned cost, redaction, and policy approvals. |
| OnePloy-owned installer, release artifacts, and updater | PARTIAL | The Ubuntu source-build installer and backup-gated updater are owned here; blank-VPS acceptance and signed multi-architecture artifacts remain release gates. |
| WordPress Bridge | EXTERNAL BLOCKER | Repository implementation includes cached pricing, short-lived signed hosted checkout handoff, domain search, status, generic-form binding, administrator documentation, packaging, and tests. Installation of the ZIP on the target WordPress 6.2+/PHP 7.4+ site and live provider acceptance remain required. |
| `@oneploy/storefront` SDK | PLANNED | Provide typed Storefront API clients and optional UI primitives without exposing private credentials in browsers. |

## Identity, authorization, and isolation

### Management scopes

- **Platform:** Super Admin, Support, Finance, and Operations roles operate only through explicit abilities.
- **Reseller:** sees its configuration, tenants, applicable catalog, pricing, subscriptions, domains, wallet, support, branding, and nameserver settings.
- **Tenant:** sees only its Team-owned resources and permitted public catalog records.

Impersonation requires an authorized platform principal, reason, impersonator and target identifiers, start and expiry times, a persistent audit event, a prominent banner, and an explicit exit. Financial and destructive operations require separate policy decisions while impersonating.

Isolation applies to Livewire, web and REST routes, policies, actions, queues, schedules, notifications, exports, webhooks, caches, search, logs, backups, tokens, CLI, MCP, AI tools, and billing queries. Launch-blocking negative tests must prove denial of cross-team reads, writes, deletes, deployments, logs, secrets, backup downloads, invoices, domains, credits, and MCP operations, plus reseller-to-reseller isolation.

## Product, plans, and entitlements

The persisted catalog comprises products, immutable published plan versions, effective-dated prices, generic boolean/numeric entitlements, subscriptions and items where needed, orders and lines, invoices and lines, payments, refunds, credit notes, wallets and append-only entries, usage ledgers, meters and events, promotions, tax snapshots, and checkout sessions.

Legacy Stripe state remains readable during an idempotent migration. Provider identifiers are references, never business truth. Existing subscribers retain the purchased plan version unless explicitly migrated. Capability checks must use entitlements rather than plan-name conditionals.

Prices use integer minor units, explicit ISO currency, applicable billing period, effective dates, tax behavior, and provider references. Currency selection precedence is explicit customer choice, account currency, reseller/storefront default, geographic suggestion, then global default. Geographic inference never overwrites an explicit choice and live foreign exchange never silently rewrites subscriptions.

Entitlements include resource counts and capacity, included metered usage, previews, custom domains, backups, API, MCP, AI Gateway, reseller features, custom branding, vanity nameservers, and audit retention. Admission is enforced before queueing and again during execution. Transactions, locks, idempotency keys, expiring reservations, and reconciliation prevent concurrent requests from consuming the same final quota slot.

## Commerce and Storefront

Payment adapters share a provider-neutral contract for checkout, payment success/failure, renewal, retry, grace, dunning, cancellation, suspension, resume, refunds, credit notes, and reconciliation. Webhooks require signature validation, replay protection, raw-event references, processing state, retries, and audit. Browser redirects are never proof of payment.

OnePloy-controlled checkout is resumable, idempotent, auditable, locale/currency aware, and may combine hosting, managed apps, domains, privacy, add-ons, upgrades, credits, and coupons. Provisioning begins only from authoritative payment state.

The versioned Storefront API is the public product truth for catalog, plans, features, prices, currencies, locales, apps, TLDs, availability, promotions, coupon validation, checkout, and platform status. It uses cache validators, rate limits, signed private operations, and idempotency keys. Attribution and funnel events collect only necessary referral, campaign, landing page, reseller, coupon, currency, duration, and conversion metadata.

## Compute and workload runtime

Managed scheduling extends the existing `Server` model with compute pools, node associations, capacity snapshots, workload reservations, placement decisions, workload runtimes, and drain operations. Servers may remain customer-managed BYO infrastructure or become OnePloy-managed nodes.

Pools accept policy classes such as web, worker, database, WordPress, game, AI, GPU, build, preview, and internal. Placement evaluates health, region, architecture, available CPU/memory/disk/GPU, entitlement, shared/dedicated policy, labels, affinity, anti-affinity, maintenance, drain state, utilization, and cost. Inputs and scores are persisted so operators can explain every placement.

Capacity is reserved transactionally before provisioning. Failed or abandoned workflows release or expire reservations and reconciliation repairs drift. Managed customers may receive automatic placement while BYO users retain existing server controls.

## Domains and DNS

Registrar and DNS provider contracts remain provider-neutral. Registrar availability is authoritative for purchase; RDAP provides registration information and WHOIS is fallback only. The domain lifecycle includes suggestions, registration, renewal, transfer, privacy, contacts, locks, auth codes where allowed, expiry, auto-renew, grace/redemption, nameservers, glue records, pricing, reconciliation, and encrypted provider credentials.

Laravel owns desired DNS state, authorization, policy, and billing while mature authoritative infrastructure such as PowerDNS serves records. Zones support A, AAAA, CNAME, TXT, MX, CAA, SRV, NS, wildcards, TTL, DNSSEC, and import/export. Authoritative nodes run across independent failure domains. Eligible resellers may configure verified vanity nameservers with clear glue-record guidance and health monitoring.

Domain-plus-hosting provisioning is a durable, resumable workflow: payment, registration, zone, workload, domain attachment, SSL, and readiness are separate recorded steps with retry and compensation. A registration failure after payment creates a visible resolution path and never silently completes the order.

## Marketplace and managed products

The inherited Compose template registry remains the deployment source. Database-backed marketplace metadata references versioned template artifacts and describes publisher, license, provenance, architectures, resource/GPU needs, ports, domains, volumes, generated secrets, variables, health, backup, upgrade, rollback, lifecycle, compatibility, and meters without duplicating Compose definitions.

Product levels are explicit: **Deployable Template**, **Managed App**, and **Managed Product**. Certification states are Community, Verified, OnePloy Certified, Managed, Beta, and Deprecated. Certification verifies licensing, provenance, schema/Compose validity, secret safety, persistence, health, architecture, backup/restore, upgrade/rollback, vulnerability state, and fresh deployment.

WordPress, WooCommerce, Multisite, Minecraft and other games, n8n variants, OpenClaw, Hermes Agent, OpenCode, and CrewAI workloads are marketed as managed only when their applicable onboarding, authentication, persistence, backup/restore, updates, rollback, monitoring, limits, domain, and support behaviors are automated and tested. Generic deployment remains available independently.

## AI Gateway and MCP

The AI Gateway is separate from deployed AI applications. Administratively configured adapters may support OpenAI, Anthropic, Google, xAI, DeepSeek, Groq, OpenRouter, and compatible endpoints. It provides tenant/project keys, aliases, routing, fallback, budgets, rate limits, usage, BYOK, webhooks, cost accounting, reseller pricing, and redacted logs.

Effective-dated model cost records preserve historical input, output, cached-input, image, audio, video, and request charges only where applicable. OnePloy claims OpenAI interface compatibility only for verified behaviors.

The existing MCP server is extended through shared authorization and entitlements. Tools are classified as read, safe write, billable write, destructive write, or platform-admin write. Billable/destructive operations require confirmation unless an explicit automation policy permits them. Every call records principal, Team, tool, redacted argument hash, authorization, approval, result, and affected resources.

Ask OnePloy operates through authorized application APIs rather than direct database bypass. It may diagnose deployments, logs, servers, disks, backups, placement, DNS, incidents, and capacity; high-impact remediation requires policy approval.

## Release, installation, and updates

Production artifacts must be built from an exact commit and published under OnePloy control: control plane, helpers, realtime component, catalog, installer, updater, and release manifest. Immutable SHA identities accompany release-channel tags. CI includes PHP and frontend dependencies, production assets, formatting/static checks, focused and required tests, supported architecture builds, vulnerability/dependency scans, SBOM, provenance, manifest, notes, signatures/attestations where configured, and installation smoke tests.

The installer detects supported OS, architecture, CPU/RAM/disk, private addresses, likely public IPv4/IPv6, and hostname; verifies Docker; creates compatible directories, SSH keys, secrets, and networks; pulls exact artifacts; starts stateful and realtime services; waits for health; and reports a validated access URL without leaking secrets. Repeated execution must preserve a valid installation.

Updates use an owned manifest, compatibility checks, control-plane database/config backups, exact artifact pulls, migration preparation and execution, restart, health checks, and reconciliation. Each attempt records versions, digest, time, logs, result, operator, and repair outcome. Rollback means image/config rollback where safe, explicit database restoration only when safe, and forward repair otherwise.

Until these acceptance criteria pass, the inherited installer is compatibility/reference material, updates remain disabled, and OnePloy is not production-ready.

## Operations, security, and recovery

Observability covers builds, deployments, runtime resources, node/container/database/queue health, webhooks, SSL, DNS, backups, billing lag, usage, and AI consumption through OpenTelemetry-compatible boundaries where practical. Customers see included, consumed, remaining, overage, estimated bill, limits, and alerts by project/resource/meter/time. Internal unit economics are never exposed to tenants and are not fabricated without source data.

Security requirements include tenant and platform authorization, encrypted credentials, secret redaction, scoped tokens, MFA, service accounts, rate limits, CSRF, signed webhooks, SSRF and DNS-rebinding defenses, outbound URL validation, container limits, upload validation, dependency/image scanning, audit, backup encryption, and rotation procedures. Private-network management uses explicit trusted connection paths; general outbound requests block metadata, link-local, and internal credential endpoints.

Durable workflows persist step state, attempts, idempotency, errors, retries, compensation, and operator/customer-visible status for checkout, provisioning, domains, enrollment, migration, upgrades, and restore. Signed tenant webhooks record event identifiers, timestamps, signatures, attempts, responses, retry schedule, and replay actions with secret redaction.

Control-plane recovery covers database, configuration, encrypted metadata, and release state. Repeatable restore drills address management-node loss, workload-node loss, corruption, and failed updates. A generated backup is not considered verified until restoration succeeds.

## Integration contracts

**OnePloy Bridge** connects WordPress to the public Storefront API and OnePloy-hosted checkout; it is not a billing database. The shipped plugin keeps its shared signing secret outside WordPress options, produces short-lived server-signed checkout handoffs, and never places private credentials or authoritative payment state in the browser. Gutenberg, Elementor, shortcodes, common form plugins, and generic forms can render pricing, checkout, domain search, and status while preserving original form submission. Last-known public catalog data may be cached; checkout handoffs and payment state may not.

`@oneploy/storefront` will provide typed catalog, plans, pricing, applications, domains, checkout, and status clients plus optional React pricing, currency, domain, checkout, and app-catalog primitives. Private administrative credentials will never enter browser bundles.

New commerce UI is locale-aware, supports localized content and transactional communication, formats currencies/dates correctly, supports IDN/Punycode domains, and uses RTL-capable layout primitives.

## Navigation contracts

Platform administration is isolated from customer navigation and grouped around Platform, Infrastructure, Workloads, Marketplace, Domains, Commerce, Usage, AI, Integrations, Operations, and System. Nested navigation is preferred over an overloaded sidebar.

The reseller workspace exposes only its tenants, catalog and pricing, customer commercial records, domains, brand/nameserver settings, wallet, and support. Customer navigation remains familiar and gains contextual Marketplace, Domains, Usage, Billing, Plan, AI, API/MCP, and Managed Apps entries only where applicable.

## Release acceptance gates

OnePloy may be called production-ready only after evidence exists for blank-VPS and repeat installation, login, managed node enrollment, Git/public repository/Docker/Compose application deployment, previews, services, databases, backup and restore, subscription checkout and reconciliation, quota concurrency, domain sandbox flow, tenant and reseller isolation, update and failed-update recovery, OnePloy artifact ownership, security scans, and disaster recovery.

External credentials do not block repository implementation. Provider contracts, encrypted configuration, validation, fixtures, mocks, webhook security, error handling, and documentation are completed first; live payment, registrar, DNS, AI, S3, signing, and SMTP acceptance is then marked **EXTERNAL BLOCKER**, never faked.

## Current delivery boundary

The currently verified implementation is limited to the audit table's **SHIPPED** entries and the narrower shipped items documented in [ROADMAP.md](ROADMAP.md). All **PARTIAL** and **PLANNED** sections remain release work. This specification is a contract and status ledger, not evidence that every described feature has shipped.
