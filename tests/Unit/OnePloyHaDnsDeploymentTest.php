<?php

test('production DNS uses a primary and an independently deployable locked-down secondary', function () {
    $root = dirname(__DIR__, 2);
    $primary = file_get_contents($root.'/docker-compose.oneploy.yml');
    $secondary = file_get_contents($root.'/docker-compose.dns-secondary.yml');
    $environment = file_get_contents($root.'/.env.production');
    $installer = file_get_contents($root.'/scripts/oneploy-install.sh');
    $scheduler = file_get_contents($root.'/app/Console/Kernel.php');

    expect($primary)
        ->toContain('--primary=yes')
        ->toContain('--allow-axfr-ips=${POWERDNS_SECONDARY_IPS:?')
        ->and($secondary)
        ->toContain('--secondary=yes')
        ->toContain('--allow-notify-from=${POWERDNS_PRIMARY_IP:?')
        ->toContain('${POWERDNS_API_BIND_ADDRESS:-127.0.0.1}:${POWERDNS_API_PORT:-8081}:8081')
        ->toContain('no-new-privileges:true')
        ->toContain('oneploy-powerdns-secondary:/var/lib/powerdns')
        ->and($environment)
        ->toContain('ONEPLOY_DNS_REQUIRE_HA=true')
        ->toContain('ONEPLOY_DNS_SECONDARIES=[]')
        ->toContain('POWERDNS_SECONDARY_IPS=')
        ->and($installer)
        ->toContain('docker-compose.dns-secondary.yml')
        ->toContain('ONEPLOY_DNS_REQUIRE_HA')
        ->toContain('ONEPLOY_DNS_SECONDARIES')
        ->toContain('POWERDNS_SECONDARY_IPS')
        ->and($scheduler)
        ->toContain('ReconcilePendingDomainDnsJob')
        ->toContain('oneploy:expire-managed-capacity-reservations')
        ->toContain('withoutOverlapping(4)');
});
