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

    'wordpress_bridge' => [
        'key_id' => env('ONEPLOY_WORDPRESS_BRIDGE_KEY_ID', 'default'),
        'secret' => env('ONEPLOY_WORDPRESS_BRIDGE_SECRET'),
        'marketing_url' => env('ONEPLOY_MARKETING_SITE_URL'),
        'ttl_seconds' => (int) env('ONEPLOY_WORDPRESS_BRIDGE_TTL_SECONDS', 900),
    ],

    'domains' => [
        'connectreseller_api_url' => env('CONNECTRESELLER_API_URL', 'https://api.connectreseller.com/ConnectReseller/ESHOP'),
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
        'primary_site' => env('ONEPLOY_DNS_PRIMARY_SITE'),
        'require_ha' => filter_var(env('ONEPLOY_DNS_REQUIRE_HA', false), FILTER_VALIDATE_BOOL),
        'secondaries' => json_decode((string) env('ONEPLOY_DNS_SECONDARIES', '[]'), true) ?: [],
        'nameservers' => array_values(array_filter(array_map('trim', explode(',', (string) env('ONEPLOY_NAMESERVERS', ''))))),
        'dnssec' => filter_var(env('POWERDNS_DNSSEC', true), FILTER_VALIDATE_BOOL),
        'public_resolvers' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'ONEPLOY_DNS_PUBLIC_RESOLVERS',
            'https://dns.google/resolve,https://cloudflare-dns.com/dns-query',
        ))))),
        'verification_batch_size' => (int) env('ONEPLOY_DNS_VERIFICATION_BATCH_SIZE', 100),
    ],

    'scheduler' => [
        'capacity_snapshot_max_age_seconds' => (int) env('ONEPLOY_CAPACITY_SNAPSHOT_MAX_AGE_SECONDS', 120),
        'reservation_ttl_seconds' => (int) env('ONEPLOY_RESERVATION_TTL_SECONDS', 300),
        'capacity_allocation_percent' => (int) env('ONEPLOY_CAPACITY_ALLOCATION_PERCENT', 80),
        'system_reserved_cpu_millis' => (int) env('ONEPLOY_SYSTEM_RESERVED_CPU_MILLIS', 500),
        'system_reserved_memory_mb' => (int) env('ONEPLOY_SYSTEM_RESERVED_MEMORY_MB', 1024),
        'system_reserved_disk_gb' => (int) env('ONEPLOY_SYSTEM_RESERVED_DISK_GB', 20),
        'snapshot_retention_per_node' => (int) env('ONEPLOY_CAPACITY_SNAPSHOT_RETENTION', 120),
        'probe_timeout_seconds' => (int) env('ONEPLOY_CAPACITY_PROBE_TIMEOUT_SECONDS', 20),
        'probe_batch_size' => (int) env('ONEPLOY_CAPACITY_PROBE_BATCH_SIZE', 100),
        'default_pool_slug' => env('ONEPLOY_DEFAULT_COMPUTE_POOL', 'default'),
        'default_pool_name' => env('ONEPLOY_DEFAULT_COMPUTE_POOL_NAME', 'Default Managed Pool'),
        'primary_region' => env('ONEPLOY_PRIMARY_REGION', 'local'),
        'default_workload_classes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ONEPLOY_COMPUTE_WORKLOAD_CLASSES', 'application,service,database')),
        ))),
    ],

    'ai_gateway' => [
        'enabled' => filter_var(env('ONEPLOY_AI_GATEWAY_ENABLED', false), FILTER_VALIDATE_BOOL),
        'rate_limit_per_minute' => (int) env('ONEPLOY_AI_GATEWAY_RATE_LIMIT', 30),
        'connect_timeout_seconds' => (int) env('ONEPLOY_AI_GATEWAY_CONNECT_TIMEOUT', 3),
        'timeout_seconds' => (int) env('ONEPLOY_AI_GATEWAY_TIMEOUT', 30),
        'connection_attempts' => (int) env('ONEPLOY_AI_GATEWAY_CONNECTION_ATTEMPTS', 2),
        'default_max_tokens' => (int) env('ONEPLOY_AI_GATEWAY_DEFAULT_MAX_TOKENS', 1024),
        'providers' => [
            'openai' => [
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'api_key' => env('OPENAI_API_KEY'),
            ],
        ],
        'models' => json_decode((string) env('ONEPLOY_AI_GATEWAY_MODELS', ''), true) ?: [
            'gpt-5-mini' => [
                'provider' => 'openai',
                'upstream_model' => 'gpt-5-mini',
            ],
        ],
    ],

    'payments' => [
        'default_provider' => env('ONEPLOY_PAYMENT_PROVIDER', 'paypal'),
        'stripe_secret' => env('STRIPE_SECRET'),
        'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'stripe_base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com'),
        'razorpay_key' => env('RAZORPAY_KEY'),
        'razorpay_secret' => env('RAZORPAY_SECRET'),
        'razorpay_webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'razorpay_base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com'),
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
