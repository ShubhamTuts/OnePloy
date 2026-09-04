<?php

test('control plane disaster recovery is encrypted off host and independently verifiable', function () {
    $root = dirname(__DIR__, 2);
    $backup = file_get_contents($root.'/scripts/oneploy-backup.sh');
    $verify = file_get_contents($root.'/scripts/oneploy-backup-verify.sh');
    $restore = file_get_contents($root.'/scripts/oneploy-restore.sh');
    $upgrade = file_get_contents($root.'/scripts/oneploy-upgrade.sh');
    $installer = file_get_contents($root.'/scripts/oneploy-install.sh');

    expect($backup)
        ->toContain('set -euo pipefail')
        ->toContain('mktemp -d')
        ->toContain('pg_dump')
        ->toContain('-aes-256-cbc')
        ->toContain('-pbkdf2')
        ->toContain('sha256sum')
        ->toContain('ONEPLOY_BACKUP_DESTINATION')
        ->toContain('ONEPLOY_REQUIRE_OFFSITE_BACKUP')
        ->toContain('ONEPLOY_BACKUP_RETENTION_DAYS')
        ->toContain('.oneploy-expired')
        ->toContain('sha256sum -c')
        ->toContain('-mtime "+${RETENTION_DAYS}"')
        ->toContain('findmnt')
        ->and($verify)
        ->toContain('sha256sum -c')
        ->toContain('pg_restore --list')
        ->not->toContain('docker compose down')
        ->and($restore)
        ->toContain('--confirm-restore')
        ->toContain('pg_restore --clean --if-exists')
        ->toContain('oneploy-backup.sh')
        ->toContain('api/health')
        ->toContain('APP_PORT')
        ->and($upgrade)
        ->toContain('oneploy-backup.sh')
        ->and($installer)
        ->toContain('oneploy-backup-verify.sh')
        ->toContain('oneploy-restore.sh');
});

test('disaster recovery scripts refuse broad filesystem targets', function () {
    $root = dirname(__DIR__, 2);
    $backup = file_get_contents($root.'/scripts/oneploy-backup.sh');
    $restore = file_get_contents($root.'/scripts/oneploy-restore.sh');

    expect($backup)
        ->toContain('refusing unsafe backup destination')
        ->and($restore)
        ->toContain('refusing unsafe restore target')
        ->toContain('RESTORE_ROOT="/data/coolify"');
});
