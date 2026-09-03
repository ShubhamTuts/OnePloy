# Install OnePloy on Ubuntu (production)

This installs the **OnePloy control plane** on a blank Ubuntu 22.04 or 24.04 VPS. It clones this repository, builds OnePloy images locally, starts PostgreSQL, Redis, realtime, the official open-source PowerDNS Authoritative Server, and the app, then configures the instance domain so Traefik can issue Let's Encrypt SSL.

The runtime requires no paid control-panel, database, queue, proxy, SSL, or DNS software license. OnePloy and its included infrastructure are open source. PayPal transaction fees, domain registry charges through ConnectReseller, VPS charges, and optional external email services are usage costs, not OnePloy software licenses.

## VPS size

- x86_64 VPS with 4 vCPU / 8 GB RAM recommended (the installer rejects less than 4 GB)
- 40 GB disk recommended (the installer rejects less than 30 GB free)
- Ubuntu 22.04 or 24.04
- Ports **22, 53 TCP/UDP, 80, 443** open. Port **8000** is the HTTP dashboard until SSL is active.

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

If the public nameserver host records and glue already exist, configure them in the same installation command:

```bash
curl -fsSL https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/scripts/oneploy-install.sh | sudo bash -s -- --fqdn app.oneploy.dev --email admin@oneploy.dev --nameservers ns1.oneploy.dev,ns2.oneploy.dev
```

The script clones the `main` branch into `/opt/oneploy`, builds OnePloy-owned images, starts the open-source stack, configures Traefik and PowerDNS, and exits successfully only after the container and public HTTPS health checks pass.

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
4. Create two nameserver host records such as `ns1.oneploy.dev` and `ns2.oneploy.dev`, point them to your DNS server IP, create registrar glue records, and pass `--nameservers` during install or set `ONEPLOY_NAMESERVERS=ns1.oneploy.dev,ns2.oneploy.dev`.
5. Store `/data/coolify/source/.env` offline. It contains the app key, PowerDNS API key, and database password.

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
- `CONNECTRESELLER_API_KEY`; fund the registrar balance and allowlist the control-plane public IP in ConnectReseller. `CONNECTRESELLER_BRAND_ID` is retained for account compatibility but is not sent by the v11 ESHOP registration API.
- `ONEPLOY_DOMAIN_PRICES`, using integer minor units, for example `{"com":{"USD":1299,"INR":119900},"net":{"USD":1499}}`. OnePloy never guesses a retail price from a registrar cost.
- At least two comma-separated `ONEPLOY_NAMESERVERS`. The bundled PowerDNS API URL and key are generated automatically.
- Delegate the nameserver hostnames and create registrar glue records before enabling customer checkout.
- SMTP settings in the dashboard **Settings → Email**

Then restart the stack:

```bash
cd /data/coolify/source
sudo docker compose --env-file .env -f docker-compose.yml -f docker-compose.oneploy.yml up -d
```

The PayPal browser return is never treated as payment proof. OnePloy captures server-to-server, verifies webhook signatures through PayPal, checks checkout ownership, amount and currency, prevents replay, and reconciles missed returns. After verified payment, a unique queue job creates or reuses the ConnectReseller customer, registers the domain, activates the PowerDNS zone, records the lifecycle state, and sends a transactional confirmation when email is configured.

Registrant contact data is encrypted at rest and is sent to ConnectReseller only after the customer accepts the registry-data consent checkbox. The paid registrar order is never automatically retried after a timeout; an uncertain result is placed in `manual_review` to prevent duplicate registry charges.
