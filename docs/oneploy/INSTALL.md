# Install OnePloy on Ubuntu (production)

This installs the **OnePloy control plane** on a blank Ubuntu 22.04 or 24.04 VPS. It clones this repository, builds Docker images locally (it does not pull CoolLabs Coolify images), starts PostgreSQL, Redis, realtime, and the app, then configures the instance domain so Traefik can issue Let's Encrypt SSL.

## VPS size

- x86_64 VPS with 4 vCPU / 8 GB RAM recommended (the installer rejects less than 4 GB)
- 40 GB disk recommended (the installer rejects less than 30 GB free)
- Ubuntu 22.04 or 24.04
- Ports **22, 80, 443** open. Port **8000** is the HTTP dashboard until SSL is active.

## DNS (do this first)

Create an A record:

```
app.oneploy.dev  →  YOUR_VPS_PUBLIC_IPV4
```

Optional AAAA for IPv6. Wait until `dig +short app.oneploy.dev` returns the VPS IP.

## One command

SSH in as root, then:

```bash
curl -fsSL https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/scripts/oneploy-install.sh | sudo bash -s -- --fqdn app.oneploy.dev --email admin@oneploy.dev
```

The script clones the `main` branch into `/opt/oneploy`, builds OnePloy-owned images, starts the stack, configures Traefik, and exits successfully only after the container and public HTTPS health checks pass.

If you prefer an explicit Git clone, run:

```bash
sudo bash -c 'git clone --depth 1 --branch main https://github.com/ShubhamTuts/OnePloy.git /opt/oneploy && FQDN=app.oneploy.dev EMAIL=admin@oneploy.dev bash /opt/oneploy/scripts/oneploy-install.sh'
```

Optional first admin user:

```bash
sudo ROOT_USERNAME=admin \
  ROOT_USER_EMAIL=you@oneploy.dev \
  ROOT_USER_PASSWORD='choose-a-long-password' \
  bash -c 'curl -fsSL https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/scripts/oneploy-install.sh | bash -s -- --fqdn app.oneploy.dev --email you@oneploy.dev'
```

The first run **builds** `oneploy/app`, `oneploy/realtime`, and `oneploy/helper`. Expect **15–40 minutes**.

## After install

1. Open `https://app.oneploy.dev` and create the root user if you did not set `ROOT_USER_*`.
2. The recovery dashboard and realtime ports are bound to localhost; customer traffic enters through Traefik on ports 80/443.
3. Add extra compute nodes from **Servers** (existing UX). This VPS is also `localhost`.
4. Store `/data/coolify/source/.env` offline. It contains the app key and database password.

## Upgrade

```bash
sudo bash /data/coolify/source/oneploy-upgrade.sh
```

This pulls git, rebuilds OnePloy images, dumps the control-plane database, and restarts. It never contacts CoolLabs.

## PayPal, domains, authoritative DNS, and email

The hosting control plane works without commerce credentials. Before exposing paid checkout, edit `/data/coolify/source/.env` and configure:

- `PAYPAL_MODE=live`, `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, and `PAYPAL_WEBHOOK_ID`
- PayPal webhook URL: `https://app.oneploy.dev/webhooks/payments/paypal/oneploy`
- Subscribe the PayPal webhook to `PAYMENT.CAPTURE.COMPLETED`; reconciliation also checks approved or completed orders every five minutes.
- `CONNECTRESELLER_API_KEY` and `CONNECTRESELLER_BRAND_ID`; allowlist the control-plane public IP in ConnectReseller.
- `POWERDNS_API_URL`, `POWERDNS_API_KEY`, `POWERDNS_SERVER_ID`, and at least two comma-separated `ONEPLOY_NAMESERVERS`.
- Delegate the nameserver hostnames and create registrar glue records before assigning them to customer domains.
- SMTP settings in the dashboard **Settings → Email**

Then restart the stack:

```bash
cd /data/coolify/source
sudo docker compose --env-file .env -f docker-compose.yml -f docker-compose.oneploy.yml up -d
```

The PayPal browser return is never treated as payment proof. OnePloy captures server-to-server, verifies webhook signatures through PayPal, checks checkout ownership, amount and currency, prevents replay, and reconciles missed returns. PowerDNS zones can be activated only for a registered domain owned by the current team.

ConnectReseller availability uses its read-only availability endpoint. Automatic registration remains disabled until the operator explicitly authorizes sending registrant contact data to ConnectReseller and validates the provider's live account, balance, Brand ID, source-IP allowlist, supported TLDs, and pricing.
