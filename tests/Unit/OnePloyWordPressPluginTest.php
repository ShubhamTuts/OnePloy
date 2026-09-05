<?php

use Symfony\Component\Process\Process;

test('the WordPress bridge plugin is distributable and syntax valid', function () {
    $root = dirname(__DIR__, 2);
    $plugin = $root.'/wordpress-bridge/oneploy-bridge.php';
    $process = new Process([PHP_BINARY, '-l', $plugin]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(file_get_contents($plugin))->toContain(
            'Version: 1.0.0',
            "add_shortcode('oneploy_pricing'",
            "add_shortcode('oneploy_checkout'",
            "add_shortcode('oneploy_domain_search'",
            "add_shortcode('oneploy_status'",
            'register_setting(',
            'wp_safe_remote_get(',
            "hash_hmac('sha256'",
            'ONEPLOY_BRIDGE_SECRET',
            "add_query_arg('settings-updated', 'true'",
            'admin_post_nopriv_oneploy_bridge_checkout',
        );
});

test('browser assets support domain search without exposing the bridge secret', function () {
    $root = dirname(__DIR__, 2);
    $script = file_get_contents($root.'/wordpress-bridge/assets/oneploy-bridge.js');
    $styles = file_get_contents($root.'/wordpress-bridge/assets/oneploy-bridge.css');
    $plugin = file_get_contents($root.'/wordpress-bridge/oneploy-bridge.php');

    expect($script)->toContain(
        '[data-oneploy-domain-form]',
        '[data-oneploy-domain-input]',
        '[data-oneploy-domain-results]',
        'oneploy:domain-result',
        'fetch(',
        'AbortController',
        'MutationObserver',
    )->not->toContain('ONEPLOY_BRIDGE_SECRET')
        ->and($script)->not->toContain('checkoutNonce', 'initializeCheckoutButtons')
        ->and($plugin)->not->toContain('wp_ajax_nopriv_oneploy_bridge_checkout_url')
        ->and($plugin)->not->toMatch('/wp_localize_script\([^;]+ONEPLOY_BRIDGE_SECRET/s')
        ->and($styles)->toContain('.oneploy-pricing', '.oneploy-domain-search');
});

test('administrator documentation covers every supported marketing integration', function () {
    $root = dirname(__DIR__, 2);
    $guide = file_get_contents($root.'/docs/oneploy/WORDPRESS-BRIDGE.md');
    $readme = file_get_contents($root.'/wordpress-bridge/readme.txt');

    expect($guide)->toContain(
        '[oneploy_pricing',
        '[oneploy_checkout',
        '[oneploy_domain_search',
        'Contact Form 7',
        'WPForms',
        'Gravity Forms',
        'Elementor',
        'Gutenberg',
        'data-oneploy-domain-form',
        'ONEPLOY_WORDPRESS_BRIDGE_SECRET',
    )->and($readme)->toContain('Installation', 'Shortcodes', 'Security');
});

test('the release package script includes only the distributable plugin surface', function () {
    $root = dirname(__DIR__, 2);
    $script = file_get_contents($root.'/scripts/package-wordpress-bridge.sh');
    $installer = file_get_contents($root.'/scripts/oneploy-install.sh');

    expect($script)->toContain(
        'oneploy-bridge.php',
        'readme.txt',
        'uninstall.php',
        'assets',
        'ADMIN-GUIDE.md',
        'sha256sum',
        'basename',
    )->not->toContain('.env')
        ->and($installer)->toMatch('/apt-get install -y [^\n]*\bzip\b/');
});

test('multisite network activation is explicitly rejected so uninstall remains site scoped', function () {
    $plugin = file_get_contents(dirname(__DIR__, 2).'/wordpress-bridge/oneploy-bridge.php');

    expect($plugin)->toContain('Network: false', 'is_multisite()', '$network_wide', 'must be activated separately');
});
