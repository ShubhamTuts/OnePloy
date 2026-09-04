<?php

test('the one click installer uses the supported Docker repository and hard gates HTTPS', function () {
    $root = dirname(__DIR__, 2);
    $installer = file_get_contents($root.'/scripts/oneploy-install.sh');
    $compose = file_get_contents($root.'/docker-compose.oneploy.yml');
    $upgrade = file_get_contents($root.'/scripts/oneploy-upgrade.sh');
    $dockerfile = file_get_contents($root.'/docker/production/Dockerfile');

    expect($installer)
        ->toContain('https://download.docker.com/linux/ubuntu/gpg')
        ->not->toContain('https://get.docker.com')
        ->toContain('HTTPS_READY=false')
        ->toContain('SSL: active and verified through Traefik / Let\'s Encrypt.')
        ->toContain('AUTHORIZED_KEYS=')
        ->and($compose)
        ->toContain('APP_BIND_ADDRESS')
        ->toContain('127.0.0.1:')
        ->and($upgrade)
        ->toContain('Database backup failed. Upgrade aborted')
        ->toContain('Upgrade health check failed.')
        ->and($dockerfile)
        ->toContain('ARG SERVERSIDEUP_PHP_VERSION=8.5-fpm-nginx-alpine');
});

test('the shipped theme wordmarks are the exact approved assets', function () {
    $public = dirname(__DIR__, 2).'/public';

    expect(hash_file('sha256', $public.'/oneploy-wordmark-dark.png'))
        ->toBe('34559ee0ad19f0935e8949da6d613ee65dc404dc769f0fdf8b39c5991f0ba753')
        ->and(hash_file('sha256', $public.'/oneploy-wordmark-light.png'))
        ->toBe('436c84548a119f8b191fd29917d28ece64fa7801f89f357f03a8f0cc6f657612');
});

test('release documents contain no unresolved conflict markers', function () {
    $documentation = file_get_contents(dirname(__DIR__, 2).'/docs/oneploy/PLATFORM-V1.md')
        .file_get_contents(dirname(__DIR__, 2).'/docs/oneploy/ROADMAP.md');

    expect($documentation)
        ->not->toContain('<<<<<<<')
        ->not->toContain('=======')
        ->not->toContain('>>>>>>>');
});

test('foundation CI validates the complete OnePloy product test surface', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/oneploy-foundation.yml');

    expect($workflow)
        ->toContain("php-version: '8.5'")
        ->toContain('npm run build')
        ->toContain('app/Services/OnePloy')
        ->toContain('app/Models/Oneploy*.php')
        ->toContain('php artisan test --compact')
        ->toContain('tests/Feature/OnePloy*Test.php')
        ->toContain('tests/Unit/OnePloy*Test.php');
});
