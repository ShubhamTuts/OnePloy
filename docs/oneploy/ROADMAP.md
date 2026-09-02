# OnePloy delivery roadmap

Status date: 2026-09-02

This document records the verified repository boundary. A feature is marked complete only when its schema, authorization, user path, tests, operations, and rollback behavior exist.

## Architecture decision

OnePloy will remain a modular Laravel control plane. A dedicated management node owns identity, policy, scheduling, billing, and audit state. Any reachable Ubuntu VPS, cloud VM, bare-metal host, or local-rack server can join a compute pool through SSH and Docker. Provider APIs are optional adapters for provisioning and lifecycle automation; they are not required for ordinary server enrollment.

The management node must not silently become a customer workload node. Compute nodes report health, allocatable capacity, labels, cost metadata, ownership, maintenance state, and drain state. Placement must be deterministic, auditable, quota-aware, and safe under concurrent requests.

## Verified now

- Existing server, application, service, database, deployment, proxy, backup, notification, API, and MCP capabilities from the upstream base.
- Super Admin, Reseller, Tenant, and Sub-user platform roles plus owner/admin/deployer/member team roles.
- Reseller ownership and lifecycle models, tenant lifecycle state, static plan definitions, and quota fields.
- Atomic reseller-owned tenant creation with a locked capacity check, default-plan assignment, owner attachment, and audit event.
- Inactive tenant/reseller checks at resource creation and application/service deployment boundaries.
- OnePloy application shell, metadata, logo, package identity, repository documentation, and Apache attribution.
- Fork-safe defaults that disable upstream telemetry and update installation, replace upstream feedback routing, and archive inherited publishing workflows outside the active GitHub Actions directory.

## Still required before a production launch

### 1. Product and entitlement kernel

- Persist `Product`, immutable `PlanVersion`, `Price`, `Entitlement`, `Subscription`, and `UsageLedger` records.
- Replace static plan arrays with versioned snapshots so historical invoices never change when a plan changes.
- Enforce application, database, service, container, CPU, memory, disk, backup, domain, and member limits at every web, API, MCP, webhook, job, and scheduler boundary.
- Add reservation/idempotency semantics so concurrent creates cannot exceed quotas.

### 2. Identity, tenant administration, and reseller workspace

- Super Admin screens for reseller approval, suspension, aggregate quota, impersonation with audit, and support access.
- Reseller screens for tenant creation, branding, pricing/markup, credit, invoices, domains, and support.
- Tenant screens for members, fine-grained roles, projects, billing, usage, domains, and audit history.
- Invitation expiry, enforced MFA options, service accounts, scoped API keys, and negative cross-tenant authorization tests.

### 3. Native commerce and billing

- Provider-neutral order, invoice, invoice-line, payment, refund, credit-note, wallet, tax, and ledger models.
- Automated payment adapter(s) plus manual invoices, offline payment recording, approval, partial payment, retry, grace period, and dunning.
- Usage metering and reseller wholesale/retail accounting. Financial records must be append-only and use integer minor units with explicit currencies.

### 4. Compute pools and multi-server scheduling

- `ComputePool`, `ComputeNode`, heartbeat/capacity snapshots, workload reservations, placement decisions, and drain/migration operations.
- Signed node enrollment, credential rotation, availability-zone/label constraints, affinity rules, and maintenance mode.
- Scheduler scoring for health, available CPU/memory/disk, placement constraints, cost, and load spread.
- Docker cgroup limits, admission control, reconciliation, retry, rollback, and auditable placement explanations.
- Backups and restore drills that survive loss of either a workload node or the control plane.

### 5. Domains, DNS, and ConnectReseller

- Provider-neutral registrar and DNS contracts with idempotent commands, webhook/event ingestion, retries, reconciliation, and encrypted credentials.
- Domain search, suggestions, registration, renewal, transfer, contacts, nameservers, privacy, expiry alerts, redemption, and ledger-linked orders.
- A ConnectReseller adapter against its official API, beginning with availability, registration, renewal, contact, child-nameserver, and funds operations. Store the API key only in the encrypted credential system and never in logs or job payloads.
- Contract tests against recorded redacted fixtures and a sandbox/allowlisted-IP acceptance environment.

References: [ConnectReseller integration options](https://www.connectreseller.com/integration-options/), [API key setup](https://helpdesk.connectreseller.com/portal/en/kb/articles/how-to-create), and [domain suggestion API](https://helpdesk.connectreseller.com/portal/en/kb/articles/domain-suggestions-api).

### 6. Workflows, support, and automation

- Persisted workflow definitions with approval steps, idempotency keys, compensation, retries, and an operator-visible execution timeline.
- Ticketing, announcements, maintenance windows, incident communication, and knowledge base.
- AI assistance only through tenant-scoped tools with explicit confirmation for destructive or billable operations, complete audit trails, rate limits, and cost budgets.

### 7. Release engineering and installation

- Fork-owned multi-architecture application images, SBOMs, provenance, vulnerability gates, signed releases, and rollback artifacts.
- A OnePloy installer and updater that fetch only OnePloy-controlled manifests and images.
- Automated acceptance tests on a blank supported Ubuntu VPS and on an on-premises/rack node behind common NAT/firewall layouts.
- Upgrade tests from the source branch, backup-before-migrate, migration rollback or forward-repair runbooks, and disaster-recovery drills.

## Release gates

OnePloy is production-ready only after the focused tests, full PHP suite, frontend build, fresh-VPS installation, upgrade/rollback, cross-tenant security, billing reconciliation, scheduler concurrency, backup restore, and dependency/container security checks pass in CI with published evidence.
