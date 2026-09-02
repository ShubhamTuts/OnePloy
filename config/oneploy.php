<?php

return [
    /*
     * Keep fork updates disabled until OnePloy owns and verifies its release
     * metadata, images, upgrade script, signatures, and rollback channel.
     */
    // This intentionally cannot be enabled through environment configuration.
    // A future OnePloy release-channel implementation must replace this value
    // only after its metadata, images, signatures, scripts, and rollback path
    // are owned and verified by OnePloy.
    'updates_enabled' => false,

    /*
     * The fork must never send installation telemetry to Coolify's endpoint.
     * Introduce an owned, opt-in endpoint before changing this value.
     */
    'upstream_telemetry_enabled' => false,

    'support_email' => env('ONEPLOY_SUPPORT_EMAIL'),
    'support_url' => env('ONEPLOY_SUPPORT_URL', 'https://github.com/ShubhamTuts/OnePloy/issues'),
];
