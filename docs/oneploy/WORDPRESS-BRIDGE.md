# OnePloy WordPress Bridge administrator guide

The OnePloy Bridge turns a WordPress marketing site into a safe storefront surface for OnePloy. It renders public pricing and platform status, searches domains, enriches existing forms with domain results, and sends customers into OnePloy's authenticated checkout. WordPress never creates an order, decides the authoritative price, or confirms payment.

## Architecture and security boundary

- Public catalogue, domain availability, and status data come from the OnePloy Storefront API.
- Pricing responses are cached in WordPress and have a one-day last-known-good fallback. Checkout state and payment state are never cached.
- A checkout click asks WordPress for a fresh, short-lived HMAC-signed handoff. The signing secret remains server-side and is never added to WordPress options, page markup, or JavaScript.
- OnePloy verifies the signature, age, key ID, marketing-site origin, active published price, authenticated user, and Team administrator role before creating a checkout session.
- Stripe and PayPal use provider-hosted approval URLs. Razorpay uses its hosted browser widget, but only a verified server-to-server webhook may mark a payment successful or begin provisioning.
- Do not put a Laravel Sanctum token, payment-provider secret, or the bridge secret in a shortcode, page builder, browser bundle, or WordPress database.

## Requirements

- A public HTTPS OnePloy application URL.
- A public HTTPS WordPress marketing URL.
- WordPress 6.2 or later and PHP 7.4 or later.
- Matching bridge key ID and secret on OnePloy and WordPress.
- At least one configured OnePloy payment provider for checkout.

## 1. Configure OnePloy

Set these values in the OnePloy production environment. A standard VPS installation stores them in `/data/coolify/source/.env`:

```dotenv
ONEPLOY_WORDPRESS_BRIDGE_KEY_ID=default
ONEPLOY_WORDPRESS_BRIDGE_SECRET=replace-with-a-long-random-secret
ONEPLOY_WORDPRESS_BRIDGE_TTL_SECONDS=900
ONEPLOY_MARKETING_SITE_URL=https://www.example.com
```

`ONEPLOY_MARKETING_SITE_URL` is an exact allowed origin. Include the real scheme, hostname, and non-default port if one is used, but no path. The installer generates the secret when it is absent. To generate one manually:

```bash
openssl rand -hex 32
```

After editing an installed instance, recreate the application container so Compose loads the changed environment, then clear Laravel's configuration cache:

```bash
cd /data/coolify/source
docker compose --env-file .env -f docker-compose.yml -f docker-compose.oneploy.yml up -d --no-deps --force-recreate coolify
docker compose --env-file .env -f docker-compose.yml -f docker-compose.oneploy.yml exec coolify php artisan config:clear
```

Key rotation requires a short coordinated maintenance window because only one active key is supported. Update the WordPress constant and the OnePloy environment together, recreate the OnePloy application container, and test checkout immediately. Existing handoff links are rejected after the key changes and otherwise expire after the configured TTL.

## 2. Package and install the plugin

From the OnePloy repository, create the installable ZIP and checksum:

```bash
bash scripts/package-wordpress-bridge.sh
(cd dist && sha256sum -c oneploy-bridge-1.0.0.zip.sha256)
```

In WordPress, open **Plugins > Add New Plugin > Upload Plugin**, upload `dist/oneploy-bridge-1.0.0.zip`, and activate **OnePloy Bridge**. The archive contains only the plugin PHP, readme, uninstall handler, browser assets, and `ADMIN-GUIDE.md`.

Put the same signing secret in `wp-config.php` before the “stop editing” line:

```php
define('ONEPLOY_BRIDGE_SECRET', 'replace-with-the-same-long-random-secret');
```

An environment variable named `ONEPLOY_BRIDGE_SECRET` is also supported. The constant takes precedence. Keep `wp-config.php` outside the public web root when the hosting layout permits it, restrict file permissions, and exclude it from public backups.

Open **Settings > OnePloy Bridge** and configure:

- **OnePloy API URL:** the HTTPS OnePloy origin used for `/api/storefront/v1/*`.
- **Application URL:** the same HTTPS OnePloy origin used for login and checkout.
- **Bridge key ID:** normally `default`; it must match `ONEPLOY_WORDPRESS_BRIDGE_KEY_ID`.
- **Currency and billing interval:** defaults used by shortcodes.
- **Cache lifetime:** public catalogue cache duration.
- **Default button label:** the standard checkout call to action.

Use **Test connection and clear cache** on that page. This validates public Storefront reachability; it does not perform a live payment.

For customer acquisition, create the root account first and enable **Settings > Advanced > Registration**. A new visitor who starts on WordPress can then register in OnePloy and resume the intended checkout. Leave public registration disabled for private/internal instances.

## 3. Pricing tables and direct checkout

Add shortcodes with a Gutenberg **Shortcode** block, an Elementor **Shortcode** widget, or a page builder that executes WordPress shortcodes.

Render a responsive pricing table with checkout buttons:

```text
[oneploy_pricing product="app-hosting" currency="USD" interval="monthly" columns="3" checkout="yes" provider="stripe" button_text="Start now" show_features="yes" campaign="homepage-pricing"]
```

Optional `product` and `plan` values are catalogue slugs. `columns` accepts 1–4, `provider` accepts `paypal`, `stripe`, or `razorpay`, and `checkout="no"` renders cards without purchase buttons.

Add one direct checkout button by product and plan slug:

```text
[oneploy_checkout product="app-hosting" plan="pro" currency="USD" interval="monthly" provider="stripe" label="Start Pro" campaign="hero"]
```

Or pin the button to a published Storefront price ID:

```text
[oneploy_checkout price_id="123" provider="razorpay" label="Buy now" campaign="launch"]
```

The price ID is resolved against current public catalogue truth before the button is rendered and revalidated by OnePloy before an order is created. A visitor who is not signed in is sent to OnePloy login and then returned to the hosted confirmation page. A signed-in non-administrator receives `403`; billing cannot be started for another Team through WordPress.

Theme authors can render the same shortcodes in PHP:

```php
echo do_shortcode('[oneploy_pricing product="app-hosting" currency="USD"]');
```

## 4. Standalone domain search

Add a complete search widget anywhere shortcodes are supported:

```text
[oneploy_domain_search currency="USD" checkout="yes" placeholder="yourbrand.com" button_text="Search"]
```

The widget requests public availability and pricing directly from OnePloy without credentials. If a domain is available, its call to action opens the OnePloy Domains page with the normalized domain prefilled. Registration, contacts, consent, payment, and provisioning remain inside authenticated OnePloy.

Use `checkout="no"` when the marketing page should display availability without the OnePloy call to action.

## 5. Connect domain search to any form

The bridge can observe an existing form without taking over its submit event. Give the form, domain field, and result container stable selectors:

```html
<form id="lead-form" data-oneploy-domain-form>
    <label>Domain <input name="domain" data-oneploy-domain-input></label>
    <div id="domain-results" data-oneploy-domain-results aria-live="polite"></div>
    <button type="submit">Request a proposal</button>
</form>
```

Elements marked with `data-oneploy-domain-form`, `data-oneploy-domain-input`, and `data-oneploy-domain-results` are initialized automatically when the bridge assets are present. The original form submission continues normally.

When markup comes from another plugin and cannot be edited, bind it with selectors:

```text
[oneploy_domain_search mode="bind" form_selector="#lead-form" input_selector="[name=domain]" results_selector="#domain-results" currency="USD" checkout="no"]
```

After a successful lookup the bridge adds or updates these hidden fields inside that form:

- `oneploy_domain_available`
- `oneploy_domain_currency`
- `oneploy_domain_amount_minor`

It also dispatches `oneploy:domain-result` with the API result in `event.detail`, or `oneploy:domain-error` with the attempted domain and a safe `message`. Custom analytics or CRM code can listen without changing bridge internals:

```js
document.addEventListener('oneploy:domain-result', (event) => {
    console.log(event.detail);
});
```

### Contact Form 7

Give the form a stable wrapper ID in the page editor, use a field named `domain`, add a result container after the form, then place this binder shortcode on the same page:

```text
[oneploy_domain_search mode="bind" form_selector="#contact-domain-lead form" input_selector="[name=domain]" results_selector="#domain-results" checkout="no"]
```

Add hidden fields named `oneploy_domain_available`, `oneploy_domain_currency`, and `oneploy_domain_amount_minor` to the Contact Form 7 form when those values must appear in mail templates.

### WPForms

Set the WPForms form CSS ID to `wpforms-domain-lead`. Use the generated input ID shown in the browser inspector and a result element outside the form:

```text
[oneploy_domain_search mode="bind" form_selector="#wpforms-form-123" input_selector="#wpforms-123-field_4" results_selector="#domain-results" checkout="no"]
```

Replace `123` and `field_4` with the IDs generated by WPForms. If hidden OnePloy metadata must be saved as WPForms entries, create matching hidden fields and map them with a small `oneploy:domain-result` listener.

### Gravity Forms

Use the form and field IDs emitted by Gravity Forms:

```text
[oneploy_domain_search mode="bind" form_selector="#gform_7" input_selector="#input_7_3" results_selector="#domain-results" checkout="no"]
```

Replace `7` and `3` with the real form and field IDs. The bridge observes the form and never calls `preventDefault()` on its submission.

### Elementor forms

For an Elementor Pro form, assign the form widget CSS ID `elementor-domain-lead`, set the domain field ID to `domain`, add an HTML widget with `<div id="domain-results" data-oneploy-domain-results aria-live="polite"></div>`, and add:

```text
[oneploy_domain_search mode="bind" form_selector="#elementor-domain-lead form" input_selector="[name='form_fields[domain]']" results_selector="#domain-results" checkout="no"]
```

If Elementor changes generated field names, inspect the rendered page and update the selectors rather than editing the bridge plugin.

## 6. Platform status

Render the public health indicator:

```text
[oneploy_status]
```

Status is informational. It does not replace operational alerting or provider-health monitoring.

## Caching and page caches

Pricing and status are safe to place behind a WordPress page cache or CDN. Each checkout button links to a no-cache WordPress server endpoint containing only public price/provider metadata. WordPress creates the signed handoff during that navigation and redirects to OnePloy, so cached HTML contains neither a signing secret, expiring nonce, nor signed checkout URL. Direct checkout also works when JavaScript is disabled.

Saving plugin settings clears bridge catalogue transients. Deactivation does not delete configuration. Deleting the plugin runs `uninstall.php`, which removes the current site's OnePloy Bridge settings and tracked cache entries but cannot remove the secret constant from `wp-config.php`.

Network activation on WordPress multisite is not supported in version 1.0.0. Activate and configure the bridge separately on each site so uninstall remains site-scoped.

## Troubleshooting

- **Connection test fails:** confirm both configured URLs use HTTPS, `/api/storefront/v1/status` is reachable publicly, the VPS firewall permits HTTPS, and WordPress can make outbound HTTPS requests.
- **No plans are shown:** verify the product/plan slug, currency, interval, active product, published plan version, and effective active price in OnePloy. Save bridge settings to clear its catalogue cache.
- **Checkout says it is not configured:** confirm `ONEPLOY_BRIDGE_SECRET` is readable by WordPress and exactly matches `ONEPLOY_WORDPRESS_BRIDGE_SECRET`.
- **Checkout returns 403:** verify the key IDs match, the two secrets match, server clocks are synchronized, `ONEPLOY_MARKETING_SITE_URL` matches the WordPress origin exactly, and the link was opened before its TTL expired.
- **Checkout provider is unavailable:** configure and enable the selected provider in OnePloy, or select another provider in the shortcode.
- **Domain results do not appear:** verify the rendered selectors, check that only one element uses each custom ID, and inspect the browser console and the Storefront domain-search response.
- **A form stops submitting:** remove third-party custom handlers temporarily. The bridge does not prevent the original submit; another script or invalid form validation is normally responsible.

## Production acceptance checklist

1. Test the WordPress connection over HTTPS.
2. Verify pricing reflects the intended currency, interval, and active published prices.
3. Complete an authenticated sandbox checkout for every enabled provider and confirm only a valid webhook changes payment state.
4. Test signed-in administrator, signed-out visitor, member denial, expired link, and tampered link flows.
5. Test domain available, unavailable, suggestion, invalid input, and upstream failure states.
6. Submit each connected form and verify its original delivery plus the expected hidden metadata.
7. Purge WordPress/CDN caches after changing shortcode content or bridge settings.
8. Back up `wp-config.php` securely and record a key-rotation procedure.

Repository tests cover Laravel authorization, signing, expiry, nonce ownership, provider initiation, public API shape, plugin syntax, assets, documentation, and packaging surface. Before declaring a specific site live, also install the generated ZIP on the target WordPress/PHP versions and complete this checklist there; that external runtime acceptance cannot be proven without the target WordPress and payment-provider credentials.
