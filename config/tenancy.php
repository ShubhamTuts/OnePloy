<?php

/**
 * OnePloy tenancy defaults.
 *
 * Quota values are per tenant (team). `null` means unlimited. A quota set on the
 * team row overrides the plan value; the plan value is the fallback.
 */
return [
    'default_plan' => env('ONEPLOY_DEFAULT_PLAN', 'free'),

    /**
     * Quota columns on the teams table, in resolution order.
     */
    'quotas' => [
        'max_applications',
        'max_databases',
        'max_services',
        'max_containers',
        'max_cpus',
        'max_memory_mb',
        'max_disk_gb',
    ],

    'plans' => [
        'free' => [
            'name' => 'Free',
            'max_applications' => 1,
            'max_databases' => 1,
            'max_services' => 0,
            'max_containers' => 2,
            'max_cpus' => 0.5,
            'max_memory_mb' => 512,
            'max_disk_gb' => 2,
        ],
        'starter' => [
            'name' => 'Starter',
            'max_applications' => 5,
            'max_databases' => 2,
            'max_services' => 2,
            'max_containers' => 10,
            'max_cpus' => 2,
            'max_memory_mb' => 2048,
            'max_disk_gb' => 20,
        ],
        'pro' => [
            'name' => 'Pro',
            'max_applications' => 25,
            'max_databases' => 10,
            'max_services' => 10,
            'max_containers' => 50,
            'max_cpus' => 8,
            'max_memory_mb' => 16384,
            'max_disk_gb' => 200,
        ],
        'unlimited' => [
            'name' => 'Unlimited',
            'max_applications' => null,
            'max_databases' => null,
            'max_services' => null,
            'max_containers' => null,
            'max_cpus' => null,
            'max_memory_mb' => null,
            'max_disk_gb' => null,
        ],
    ],
];
