<?php
/**
 * Plugin Name: OnePloy Bridge
 * Description: Live catalog, domain search, and checkout buttons for WordPress. Product truth stays in OnePloy.
 * Version: 0.1.0
 * Author: OnePloy
 * License: Apache-2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

function oneploy_bridge_settings()
{
    return [
        'api_url' => rtrim((string) get_option('oneploy_api_url', ''), '/'),
        'public_key' => (string) get_option('oneploy_public_key', ''),
    ];
}

function oneploy_bridge_get($path)
{
    $settings = oneploy_bridge_settings();
    if ($settings['api_url'] === '') {
        return null;
    }
    $response = wp_remote_get($settings['api_url'].$path, ['timeout' => 12]);
    if (is_wp_error($response)) {
        return null;
    }

    return json_decode(wp_remote_retrieve_body($response), true);
}

add_shortcode('oneploy_pricing', function () {
    $data = oneploy_bridge_get('/api/storefront/v1/catalogue');
    if (! $data) {
        return '<p>OnePloy catalog is temporarily unavailable.</p>';
    }
    $html = '<div class="oneploy-pricing">';
    foreach ($data['products'] ?? [] as $product) {
        $html .= '<section><h2>'.esc_html($product['name']).'</h2>';
        foreach ($product['plans'] ?? [] as $plan) {
            $price = $plan['price']['formatted'] ?? '';
            $html .= '<article><h3>'.esc_html($plan['name']).'</h3><p>'.esc_html($price).'</p></article>';
        }
        $html .= '</section>';
    }

    return $html.'</div>';
});

add_shortcode('oneploy_status', function () {
    $data = oneploy_bridge_get('/api/storefront/v1/status');

    return '<p>OnePloy status: '.esc_html($data['status'] ?? 'unknown').'</p>';
});

add_action('admin_menu', function () {
    add_options_page('OnePloy Bridge', 'OnePloy Bridge', 'manage_options', 'oneploy-bridge', function () {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('oneploy_bridge')) {
            update_option('oneploy_api_url', esc_url_raw($_POST['oneploy_api_url'] ?? ''));
            update_option('oneploy_public_key', sanitize_text_field($_POST['oneploy_public_key'] ?? ''));
            echo '<div class="updated"><p>Saved.</p></div>';
        }
        $api = esc_attr(get_option('oneploy_api_url', 'https://app.oneploy.dev'));
        echo '<div class="wrap"><h1>OnePloy Bridge</h1><form method="post">';
        wp_nonce_field('oneploy_bridge');
        echo '<p><label>API URL <input name="oneploy_api_url" value="'.$api.'" class="regular-text"></label></p>';
        echo '<p><label>Public key <input name="oneploy_public_key" value="'.esc_attr(get_option('oneploy_public_key')).'" class="regular-text"></label></p>';
        echo '<p>Keep the server-side secret in wp-config.php as ONEPLOY_BRIDGE_SECRET. Never put it in JavaScript.</p>';
        echo '<p><button class="button button-primary">Save</button></p></form></div>';
    });
});
