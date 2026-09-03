<?php

return [
    /*
     * Keep fork updates disabled until OnePloy owns and verifies its release
     * metadata, images, upgrade script, signatures, and rollback channel.
     * oneploy-upgrade.sh is the supported path; inherited CoolLabs polling stays off.
     */
    'updates_enabled' => false,

    'own_releases' => env('ONEPLOY_OWN_RELEASES', true),

    'platform' => env('ONEPLOY_PLATFORM', true),

    'upstream_telemetry_enabled' => false,

    'support_email' => env('ONEPLOY_SUPPORT_EMAIL'),
    'support_url' => env('ONEPLOY_SUPPORT_URL', 'https://github.com/ShubhamTuts/OnePloy/issues'),
    'status_url' => env('ONEPLOY_STATUS_URL', 'https://status.oneploy.dev'),
    'docs_url' => env('ONEPLOY_DOCS_URL', 'https://github.com/ShubhamTuts/OnePloy#readme'),

    'storefront' => [
        'default_currency' => env('ONEPLOY_DEFAULT_CURRENCY', 'USD'),
        'currencies' => array_values(array_filter(array_map('trim', explode(',', (string) env('ONEPLOY_CURRENCIES', 'USD,INR,EUR,GBP,AUD,CAD,AED,SGD'))))),
    ],

    'domains' => [
        'connectreseller_api_url' => env('CONNECTRESELLER_API_URL', 'https://api.connectreseller.com/ConnectReseller/dc'),
        'connectreseller_api_key' => env('CONNECTRESELLER_API_KEY'),
        'connectreseller_brand_id' => env('CONNECTRESELLER_BRAND_ID'),
        'retail_prices' => json_decode((string) env('ONEPLOY_DOMAIN_PRICES', '{}'), true) ?: [],
        'default_currency' => env('ONEPLOY_DOMAIN_CURRENCY', 'USD'),
        'markup_percent' => (float) env('ONEPLOY_DOMAIN_MARKUP_PERCENT', 20),
    ],

    'dns' => [
        'powerdns_api_url' => env('POWERDNS_API_URL'),
        'powerdns_api_key' => env('POWERDNS_API_KEY'),
        'powerdns_server_id' => env('POWERDNS_SERVER_ID', 'localhost'),
        'nameservers' => array_values(array_filter(array_map('trim', explode(',', (string) env('ONEPLOY_NAMESERVERS', ''))))),
        'dnssec' => filter_var(env('POWERDNS_DNSSEC', true), FILTER_VALIDATE_BOOL),
    ],

    'payments' => [
        'default_provider' => env('ONEPLOY_PAYMENT_PROVIDER', 'paypal'),
        'stripe_secret' => env('STRIPE_SECRET'),
        'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'razorpay_key' => env('RAZORPAY_KEY'),
        'razorpay_secret' => env('RAZORPAY_SECRET'),
        'razorpay_webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'paypal_client_id' => env('PAYPAL_CLIENT_ID'),
        'paypal_secret' => env('PAYPAL_SECRET'),
        'paypal_webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'paypal_mode' => env('PAYPAL_MODE', 'sandbox'),
        'paypal_base_url' => env('PAYPAL_BASE_URL')
            ?: (env('PAYPAL_MODE', 'sandbox') === 'live'
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com'),
    ],
];
