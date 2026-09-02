# Install OnePloy on Ubuntu (production)

This installs the **OnePloy control plane** on a blank Ubuntu 22.04 or 24.04 VPS. It clones this repository, builds Docker images locally (it does not pull CoolLabs Coolify images), starts PostgreSQL, Redis, realtime, and the app, then configures the instance domain so Traefik can issue Let's Encrypt SSL.

## VPS size

- 4 vCPU / 8 GB RAM minimum (8 GB+ strongly recommended because the first image build is heavy)
- 40 GB disk minimum
- Ubuntu 22.04 or 24.04
- Ports **22, 80, 443** open. Port **8000** is the HTTP dashboard until SSL is active.

## DNS (do this first)

Create an A record:

```
app.oneploy.dev  →  YOUR_VPS_PUBLIC_IPV4
```

Optional AAAA for IPv6. Wait until `dig +short app.oneploy.dev` returns the VPS IP.

## One command (after you push this branch to GitHub)

SSH in as root, then:

```bash
curl -fsSL https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/scripts/oneploy-install.sh | sudo bash -s -- --fqdn app.oneploy.dev --email admin@oneploy.dev
```

Optional first admin user:

```bash
sudo ROOT_USERNAME=admin \
  ROOT_USER_EMAIL=you@oneploy.dev \
  ROOT_USER_PASSWORD='choose-a-long-password' \
  bash -c 'curl -fsSL https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/scripts/oneploy-install.sh | bash -s -- --fqdn app.oneploy.dev --email you@oneploy.dev'
```

The first run **builds** `oneploy/app`, `oneploy/realtime`, and `oneploy/helper`. Expect **15–40 minutes**.

## If GitHub does not have the latest commit yet

On the VPS:

```bash
git clone --branch main https://github.com/ShubhamTuts/OnePloy.git /opt/oneploy
sudo FQDN=app.oneploy.dev EMAIL=admin@oneploy.dev bash /opt/oneploy/scripts/oneploy-install.sh
```

## After install

1. Open `http://YOUR_IP:8000` and create the root user if you did not set `ROOT_USER_*`.
2. When DNS is live, `https://app.oneploy.dev` is served by `coolify-proxy` (Traefik) with Let's Encrypt.
3. Add extra compute nodes from **Servers** (existing UX). This VPS is also `localhost`.
4. Store `/data/coolify/source/.env` offline. It contains the app key and database password.

## Upgrade

```bash
sudo bash /data/coolify/source/oneploy-upgrade.sh
```

This pulls git, rebuilds OnePloy images, dumps the control-plane database, and restarts. It never contacts CoolLabs.

## Payment, domains, email (external)

The panel works without these. Live checkout, domain purchase, and mail need keys in `/data/coolify/source/.env` and a stack restart:

- `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`
- `RAZORPAY_KEY`, `RAZORPAY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`
- `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`
- `CONNECTRESELLER_API_KEY`
- SMTP settings in the dashboard **Settings → Email**
