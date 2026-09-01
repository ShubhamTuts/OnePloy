# OnePloy (Webkahost) — License review & Phase 0 discovery

Base: `ShubhamTuts/coolify` fork of `coollabsio/coolify` (Laravel 12 code on Laravel 10 structure, Livewire 3, Tailwind 4, PostgreSQL, Redis/Horizon, Docker over SSH).

## 1. License review

`LICENSE` in this repo is the **unmodified Apache License 2.0**, `Copyright [2025] [Andras Bacsai]`. There are no additional terms, no Commons Clause, no BSL/SSPL rider, and no "no hosted service / no resale" clause anywhere in `LICENSE`, `README.md`, or `CONTRIBUTING.md`.

Consequences for OnePloy:

- Commercial use, modification, sublicensing, and offering the software as a **hosted, multi-tenant paid service** are permitted (Apache-2.0 §2 grants use/reproduce/prepare derivative works/sublicense/distribute for any purpose, commercial included). No commercial agreement with the upstream author is required by the license.
- Obligations we must satisfy when we distribute or ship modified code (Apache-2.0 §4): keep the `LICENSE` file, retain existing copyright/patent/attribution notices, add a `NOTICE`-style statement of modification, and state prominently that files were changed. Running the modified code as a network service without redistributing binaries technically does not trigger §4, but we will comply anyway.
- **Trademarks are NOT licensed** (Apache-2.0 §6). "Coolify" name, logo, and `coolify.io` branding may not be used for OnePloy/Webkahost. This is exactly what Phase 5 (rebrand) removes; the rebrand is therefore a *license requirement*, not just cosmetics.
- Not covered by the license and still worth a decision before launch: upstream's own cloud offering is a competitor, and their public communications discourage reselling. That is a business/relationship concern, not a legal blocker.

**Verdict: no stop condition.** The only hard constraints are attribution/notice retention and complete removal of Coolify trademarks/branding.

## 2. Phase 0 — how Coolify's tenancy actually works today

### 2.1 Identity and tenancy models

| Concern | Where |
| --- | --- |
| User | `app/Models/User.php` — Fortify + Sanctum + 2FA. `booted()` auto-creates a personal `Team` (role `owner`) for every new user. |
| Team (tenant unit) | `app/Models/Team.php` — `hasMany` projects, servers, private keys, s3s, cloud provider tokens; `hasOne` subscription; `belongsToMany` users through `team_user` with a `role` pivot. |
| Team membership role | `team_user.role` string, values from `app/Enums/Role.php` (`member` 1 < `admin` 2 < `owner` 3, with `lt()`/`gt()`). |
| Invitations | `app/Models/TeamInvitation.php`, `app/Livewire/Team/InviteLink.php` (role chosen at invite time, escalation checked inline). |
| Instance/root context | Team `id = 0`. `User::isInstanceAdmin()` = admin/owner of team 0. Also sentinel rows `Server 0`, `InstanceSettings 0`, `StandalonePostgresql 0`, `StandaloneDocker 0`. |
| Session team | `session('currentTeam')`, `User::currentTeam()` (cached 1h under `user:{id}:team:{teamId}`), refreshed by `App\Http\Middleware\DecideWhatToDoWithUser`. |

There is **no layer above Team**. Cloud billing (`Subscription`, Stripe only) hangs off Team, and `Team::limits` returns `custom_server_limit ?? 2` on cloud, unlimited (`999999999999`) when `self_hosted` or team 0.

### 2.2 Resource hierarchy and scoping

`Team → Project → Environment → {Application | Service | Standalone<DB>}`, deployed to a `StandaloneDocker`/`SwarmDocker` **destination** which belongs to a `Server`.

Scoping is *not* a global query scope. It is done ad-hoc in three ways:

1. Static helpers per model: `Server::ownedByCurrentTeam()` (`whereTeamId(currentTeam()->id)`), `Project::ownedByCurrentTeam()`, `StandaloneDocker::ownedByCurrentTeam()`, `find_destination_for_current_team()`.
2. Policies (~30 in `app/Policies`, mapped in `AuthServiceProvider`), which resolve the owning team by walking relations — e.g. `ApplicationPolicy::getTeamId()` → `$application->environment->project->team`. Read = "user belongs to that team", write/deploy = `User::isAdminOfTeam($teamId)`.
3. Gates: `createAnyResource` (`ResourceCreatePolicy`), `canAccessTerminal`.

Implication: **team isolation in Coolify is per-call, enforced by convention.** Any new OnePloy code path must repeat it; there is no safety net.

### 2.3 Deployment triggers

All deploy paths funnel through `queue_application_deployment()` in `bootstrap/helpers/applications.php`, which inserts an `ApplicationDeploymentQueue` row and dispatches `App\Jobs\ApplicationDeploymentJob` (Redis/Horizon; `deployments` queue on cloud, `high` self-hosted). Callers:

- UI: `Livewire/Project/Application/{Heading,Rollback,Previews}`, `Livewire/Project/Shared/Destination`.
- Git webhooks: `app/Http/Controllers/Webhook/{Github,Gitlab,Gitea,Bitbucket}.php` (+ `ProcessGithubPullRequestWebhook`), routes in `routes/webhooks.php` (`/source/github/events`, `.../manual`).
- REST API: `Api/ApplicationsController`, `Api/DeployController` (Sanctum + `ApiAbility` `deploy`).
- MCP tools: `app/Mcp/Tools/{Deploy,Control}.php`.
- Self-restart from inside `ApplicationDeploymentJob`.

The only checks inside `queue_application_deployment()` are the per-server queue limit (`server.settings.deployment_queue_limit`, default 25) and duplicate-commit de-dup. **No quota check, no subscription check, no tenant-status check** — subscription state is only consulted in `DecideWhatToDoWithUser` (web session redirect to `subscription.index`) and only when `isCloud()`. Webhook and API deploys bypass it entirely.

### 2.4 Git source integration

`GithubApp` / `GitlabApp` models carry per-team sources plus `is_system_wide` (visible to every team) and `is_public`. `Team::sources()` returns `team_id = current OR is_system_wide`. GitHub App install/callback lives in `Webhook\Github::{redirect,install}`; push/PR events resolve the app by webhook secret and repository, then queue deployments. This already supports the OnePloy model: one platform-wide GitHub App usable by all tenants, or per-tenant apps.

### 2.5 Findings that shape the whole design

1. **`Server` belongs to exactly one team** (`servers.team_id`, `Server::ownedByCurrentTeam()`). A single shared host serving many tenants therefore has *no* representation today. This is the single biggest structural gap: we need a platform-owned server that is exposed to tenants as a deploy target without granting them server management, SSH/terminal, proxy, or metrics access. (Duplicating server rows per team is not viable — proxy config, Docker cleanup, backups and Sentinel all key off the server row.)
2. **Quotas do not exist** beyond `custom_server_limit`. CPU/memory/disk/container caps must be added and enforced at both create time and every deploy path (§2.3).
3. **Subscription gating is web-session-only and cloud-only.** Billing gating must move into the deploy funnel itself.
4. **Team roles are 3 levels**, so the requested `deployer` tier is new; `member` maps to `viewer`.
5. **Signup always creates a personal team**, registration self-disables after the first user (`is_registration_enabled` in `InstanceSettings`) — public self-service signup must re-enable and extend this path (Phase 2).
6. Bug found while reading authorization code: `ResourceCreatePolicy::CREATABLE_RESOURCES` lists `GithubApp::class` without importing `App\Models\GithubApp`, so the constant holds `App\Policies\GithubApp` and `create(GithubApp::class)` is always `false`. Fixed in this branch.

### 2.6 Mapping to the OnePloy four-level model

| OnePloy level | Implementation |
| --- | --- |
| Super Admin | root team (`id = 0`) admin/owner — already the instance-admin concept |
| Reseller | **new** `Reseller` model owning a set of Teams, with its own quotas/pricing/status |
| Tenant | one `Team` + new tenant columns (plan, status, quotas, reseller attribution) |
| Sub-user | `team_user.role` ∈ `viewer(member)` / `deployer` / `admin` / `owner` |

## 3. Phase 1 — role hierarchy and tenant model (implemented)

### 3.1 What was added

| Piece | File |
| --- | --- |
| Platform role enum (SubUser < Tenant < Reseller < SuperAdmin) | `app/Enums/PlatformRole.php` |
| Tenant lifecycle enum (active / suspended / terminated) | `app/Enums/TenantStatus.php` |
| `deployer` tier + `label()` / `gte()` | `app/Enums/Role.php` |
| Reseller model (owner, tenants, aggregate quotas, markup, status) | `app/Models/Reseller.php` |
| Tenant behaviour on Team (plan, status, quotas, reseller) | `app/Traits/IsTenant.php` |
| Plans and quota keys | `config/tenancy.php` |
| Schema | `database/migrations/2026_09_01_1200*` |
| Authorization | `app/Policies/ResellerPolicy.php`, `TeamPolicy::{viewAsPlatform,manageTenant}`, gate `viewPlatformAdmin` |

### 3.2 Authorization rules encoded

- Super Admin = root-team admin/owner (`User::isSuperAdmin()`); may manage every tenant and reseller.
- A reseller may edit its own profile and manage **its own** tenants, but may **not** raise its own quotas, change its own status, or delete itself — those are Super Admin only.
- A suspended reseller loses tenant management, and all of its tenants become inactive (`Team::isTenantActive()` walks up to the reseller).
- A tenant owner may run their team but may **not** change their own plan, quotas, or lifecycle status (`TeamPolicy::manageTenant` is platform/reseller only).
- Quotas and plan are not mass assignable: they go through `Team::setQuotas()` / `Team::assignPlan()` / `Reseller::setQuotas()`.
- Quota resolution: team override → plan value → `null` (unlimited). Root team is always unlimited.
- `deployer` can deploy applications and services and manage deployments, but has no admin rights (it is below `admin` in every existing `['admin','owner']` check, e.g. API elevated tokens, S3 notifications, MCP team resolution).

### 3.3 Feasibility and blockers for later phases

- **Not a blocker, but the largest item:** the shared single server (§2.5.1). Recommended shape for Phase 4: keep one platform-owned `Server` (root team) and introduce a tenant-facing deploy-target abstraction, so `ServerPolicy` never grants tenants server/terminal/proxy access. This should be designed before Phase 2 quota enforcement, since quotas are measured per server.
- **Phase 2/3 enforcement point:** all deploy paths funnel through `queue_application_deployment()`. Enforcing tenant status + subscription + quotas there (plus at resource-create time) covers UI, webhooks, API and MCP in one place.
- **Phase 6 billing:** Coolify's existing `Subscription` model is Stripe/cloud specific and gated by `isCloud()`; the self-contained billing module should own its own tables rather than extending it.
