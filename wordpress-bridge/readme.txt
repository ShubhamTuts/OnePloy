=== OnePloy Bridge ===
Contributors: oneploy
Tags: hosting, pricing, checkout, domains, marketing
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.0.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Render live OnePloy pricing, secure checkout buttons, domain availability, and domain-aware lead forms without exposing private credentials.

== Installation ==

1. Zip the contents of the `wordpress-bridge` directory so `oneploy-bridge.php` is at the archive root.
2. In WordPress, open Plugins > Add New > Upload Plugin and upload the ZIP.
3. Activate OnePloy Bridge.
4. Open Settings > OnePloy Bridge and enter the HTTPS API/application URL, bridge key ID, currency, and interval.
5. Add the same strong secret to OnePloy as `ONEPLOY_WORDPRESS_BRIDGE_SECRET` and to WordPress `wp-config.php` as `ONEPLOY_BRIDGE_SECRET`.
6. Set `ONEPLOY_MARKETING_SITE_URL` in OnePloy to the WordPress site origin.

Never place the bridge secret or a OnePloy administrator token in page content, JavaScript, a page-builder field, or a WordPress option.

== Shortcodes ==

Pricing table:
`[oneploy_pricing product="app-hosting" currency="USD" interval="monthly" checkout="yes" provider="stripe"]`

Direct checkout button:
`[oneploy_checkout product="app-hosting" plan="pro" provider="stripe" label="Start Pro"]`

Domain search:
`[oneploy_domain_search currency="USD" checkout="yes"]`

Platform status:
`[oneploy_status]`

See `ADMIN-GUIDE.md` in the plugin package for every attribute and examples for Gutenberg, Elementor, Contact Form 7, WPForms, Gravity Forms, PHP themes, and generic HTML forms.

== Security ==

The catalog and domain search use public read-only APIs. A no-cache WordPress redirect generates checkout URLs just in time, signs them with HMAC, and works without JavaScript. OnePloy revalidates their lifetime, nonce ownership, origin, and active price. Login, team authorization, authoritative pricing, payment initiation, and payment confirmation remain inside OnePloy. Browser code never receives the bridge secret or a OnePloy API token.

Network activation on WordPress multisite is not supported in version 1.0.0. Activate and configure the plugin separately on each site.

== Frequently Asked Questions ==

= Can WordPress charge cards directly? =

No. Checkout is hosted by OnePloy and the configured payment provider. This keeps account tokens and payment credentials out of WordPress.

= Can I bind domain search to an existing form? =

Yes. Use `[oneploy_domain_search mode="bind" ...]` as documented in the administrator guide. The bridge adds availability metadata without preventing the form's original submission.

== Changelog ==

= 1.0.0 =
* Added filtered pricing tables and plan features.
* Added fresh server-side signed checkout handoffs.
* Added standalone domain search and generic-form binding.
* Added transient cache, stale catalog fallback, diagnostics, responsive styles, and accessible status output.
