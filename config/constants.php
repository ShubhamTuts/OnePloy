<?php

return [
    /*
     * Compatibility note: much of the inherited application currently reads
     * config('constants.coolify.*'). Keep the key until the internal codemod is
     * complete, but every runtime value below is owned by OnePloy.
     */
    'coolify' => [
        'version' => env('ONEPLOY_VERSION', env('COOLIFY_VERSION', '0.1.0-dev')),
        'helper_version' => env('ONEPLOY_HELPER_VERSION', 'latest'),
        'realtime_version' => env('ONEPLOY_REALTIME_VERSION', 'latest'),
        'railpack_version' => '0.23.0',
        'self_hosted' => env('SELF_HOSTED', true),
        'autoupdate' => env('AUTOUPDATE', false),
        'base_config_path' => env('BASE_CONFIG_PATH', '/data/oneploy'),
        'registry_url' => env('REGISTRY_URL', 'ghcr.io'),
        'helper_image' => env('HELPER_IMAGE', env('ONEPLOY_REGISTRY', 'ghcr.io/shubhamtuts').'/oneploy-helper'),
        'realtime_image' => env('REALTIME_IMAGE', env('ONEPLOY_REGISTRY', 'ghcr.io/shubhamtuts').'/oneploy-realtime'),
        'is_windows_docker_desktop' => env('IS_WINDOWS_DOCKER_DESKTOP', false),
        'cdn_url' => env('ONEPLOY_RELEASE_BASE_URL', 'https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main'),
        'avatar_cdn_url' => env('AVATAR_CDN_URL'),
        'versions_url' => env('ONEPLOY_RELEASE_MANIFEST_URL', 'https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/releases/stable.json'),
        'upgrade_script_url' => env('UPGRADE_SCRIPT_URL', 'https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/scripts/oneploy-upgrade.sh'),
        'releases_url' => env('RELEASES_URL', 'https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/releases/stable.json'),
    ],

    'urls' => [
        'docs' => env('ONEPLOY_DOCS_URL', 'https://github.com/ShubhamTuts/OnePloy#readme'),
        'contact' => env('ONEPLOY_SUPPORT_URL', 'https://github.com/ShubhamTuts/OnePloy/issues'),
    ],

    'services' => [
        'official' => env('ONEPLOY_SERVICE_TEMPLATES_URL', 'https://raw.githubusercontent.com/ShubhamTuts/OnePloy/main/templates/service-templates-latest.json'),
        'file_name' => 'service-templates-latest.json',
        'cache_key' => 'oneploy:service-templates-bundle',
    ],

    'terminal' => [
        'protocol' => env('TERMINAL_PROTOCOL'),
        'host' => env('TERMINAL_HOST'),
        'port' => env('TERMINAL_PORT'),
        'command_timeout' => 0,
    ],

    'pusher' => [
        'host' => env('PUSHER_HOST'),
        'port' => env('PUSHER_PORT'),
        'app_key' => env('PUSHER_APP_KEY'),
    ],

    'migration' => [
        'is_migration_enabled' => env('MIGRATION_ENABLED', true),
    ],

    'seeder' => [
        'is_seeder_enabled' => env('SEEDER_ENABLED', true),
    ],

    'horizon' => [
        'is_horizon_enabled' => env('HORIZON_ENABLED', true),
        'is_scheduler_enabled' => env('SCHEDULER_ENABLED', true),
    ],

    'nightwatch' => [
        'is_nightwatch_enabled' => env('NIGHTWATCH_ENABLED', false),
    ],

    'docker' => [
        'minimum_required_version' => '24.0',
        'stop_timeout_flag_since' => '28.0.0',
    ],

    'ssh' => [
        'mux_enabled' => env('MUX_ENABLED', env('SSH_MUX_ENABLED', true)),
        'mux_persist_time' => env('SSH_MUX_PERSIST_TIME', 3600),
        'mux_health_check_enabled' => env('SSH_MUX_HEALTH_CHECK_ENABLED', true),
        'mux_health_check_timeout' => env('SSH_MUX_HEALTH_CHECK_TIMEOUT', 5),
        'mux_lock_ttl' => env('SSH_MUX_LOCK_TTL', 30),
        'mux_lock_timeout' => env('SSH_MUX_LOCK_TIMEOUT', 10),
        'mux_orphan_min_age' => env('SSH_MUX_ORPHAN_MIN_AGE', 600),
        'mux_orphan_reap_enabled' => env('SSH_MUX_ORPHAN_REAP_ENABLED', false),
        'connection_timeout' => 10,
        'server_interval' => 20,
        'command_timeout' => env('SSH_COMMAND_TIMEOUT', 3600),
        'max_retries' => env('SSH_MAX_RETRIES', 3),
        'retry_base_delay' => env('SSH_RETRY_BASE_DELAY', 2),
        'retry_max_delay' => env('SSH_RETRY_MAX_DELAY', 30),
        'retry_multiplier' => env('SSH_RETRY_MULTIPLIER', 2),
    ],

    'invitation' => [
        'link' => [
            'expiration_days' => 3,
        ],
    ],

    'email_change' => [
        'verification_code_expiry_minutes' => 10,
    ],

    'sentry' => [
        'sentry_dsn' => env('SENTRY_DSN'),
    ],

    'sentinel' => [
        'dev_url' => env('DEV_SENTINEL_URL'),
        'push_force_interval_seconds' => env('SENTINEL_PUSH_FORCE_INTERVAL_SECONDS', 300),
    ],

    'proxy' => [
        'connect_networks_interval_seconds' => env('PROXY_CONNECT_NETWORKS_INTERVAL_SECONDS', 3600),
    ],

    'webhooks' => [
        'feedback_discord_webhook' => env('FEEDBACK_DISCORD_WEBHOOK'),
        'dev_webhook' => env('SERVEO_URL'),
    ],

    'bunny' => [
        'storage_api_key' => env('BUNNY_STORAGE_API_KEY'),
        'api_key' => env('BUNNY_API_KEY'),
    ],

    'server_checks' => [
        'notification_delay_min' => 120,
        'notification_delay_max' => 300,
        'notification_delay_scaling' => 0.2,
    ],
];
