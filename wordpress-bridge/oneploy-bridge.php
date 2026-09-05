<?php
/**
 * Plugin Name: OnePloy Bridge
 * Plugin URI: https://github.com/ShubhamTuts/OnePloy
 * Description: Live OnePloy pricing, secure checkout handoffs, domain search, and form integrations for WordPress marketing sites.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: OnePloy
 * License: Apache-2.0
 * Text Domain: oneploy-bridge
 * Network: false
 */
if (! defined('ABSPATH')) {
    exit;
}

final class OnePloy_Bridge
{
    const VERSION = '1.0.0';

    const OPTION = 'oneploy_bridge_options';

    const CACHE_KEYS_OPTION = 'oneploy_bridge_cache_keys';

    public static function init()
    {
        add_action('init', [__CLASS__, 'register_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_menu', [__CLASS__, 'register_admin_page']);
        add_action('admin_post_oneploy_bridge_test', [__CLASS__, 'test_connection']);
        add_action('admin_post_oneploy_bridge_checkout', [__CLASS__, 'redirect_checkout']);
        add_action('admin_post_nopriv_oneploy_bridge_checkout', [__CLASS__, 'redirect_checkout']);
        add_action('update_option_'.self::OPTION, [__CLASS__, 'clear_cache']);
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), [__CLASS__, 'plugin_action_links']);

        add_shortcode('oneploy_pricing', [__CLASS__, 'render_pricing']);
        add_shortcode('oneploy_checkout', [__CLASS__, 'render_checkout']);
        add_shortcode('oneploy_domain_search', [__CLASS__, 'render_domain_search']);
        add_shortcode('oneploy_status', [__CLASS__, 'render_status']);
    }

    public static function activate($network_wide = false)
    {
        if (is_multisite() && $network_wide) {
            wp_die(esc_html__('OnePloy Bridge 1.0.0 must be activated separately on each WordPress site.', 'oneploy-bridge'));
        }
        if (get_option(self::OPTION, false) === false) {
            add_option(self::OPTION, self::defaults());
        }
    }

    public static function defaults()
    {
        return [
            'api_url' => 'https://app.oneploy.dev',
            'app_url' => 'https://app.oneploy.dev',
            'key_id' => 'default',
            'currency' => 'USD',
            'interval' => 'monthly',
            'cache_ttl' => 300,
            'button_label' => __('Start now', 'oneploy-bridge'),
        ];
    }

    public static function settings()
    {
        $saved = get_option(self::OPTION, []);

        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function register_assets()
    {
        wp_register_style('oneploy-bridge', plugins_url('assets/oneploy-bridge.css', __FILE__), [], self::VERSION);
        wp_register_script('oneploy-bridge', plugins_url('assets/oneploy-bridge.js', __FILE__), [], self::VERSION, true);
    }

    public static function enqueue_assets()
    {
        $settings = self::settings();
        wp_enqueue_style('oneploy-bridge');
        wp_enqueue_script('oneploy-bridge');
        wp_localize_script('oneploy-bridge', 'OnePloyBridge', [
            'apiUrl' => $settings['api_url'],
            'appUrl' => $settings['app_url'],
            'defaultCurrency' => $settings['currency'],
            'strings' => [
                'checking' => __('Checking…', 'oneploy-bridge'),
                'available' => __('is available', 'oneploy-bridge'),
                'unavailable' => __('is not available', 'oneploy-bridge'),
                'error' => __('Domain search is temporarily unavailable.', 'oneploy-bridge'),
                'continue' => __('Continue in OnePloy', 'oneploy-bridge'),
            ],
        ]);
    }

    public static function register_settings()
    {
        register_setting('oneploy_bridge', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => self::defaults(),
        ]);
        add_settings_section('oneploy_bridge_connection', __('Connection', 'oneploy-bridge'), function () {
            echo '<p>'.esc_html__('Public data is read from OnePloy. Checkout links are signed on this server.', 'oneploy-bridge').'</p>';
        }, 'oneploy-bridge');

        $fields = [
            'api_url' => [__('OnePloy API URL', 'oneploy-bridge'), 'url'],
            'app_url' => [__('OnePloy application URL', 'oneploy-bridge'), 'url'],
            'key_id' => [__('Bridge key ID', 'oneploy-bridge'), 'text'],
            'currency' => [__('Default currency', 'oneploy-bridge'), 'text'],
            'interval' => [__('Default interval', 'oneploy-bridge'), 'interval'],
            'cache_ttl' => [__('Catalog cache (seconds)', 'oneploy-bridge'), 'number'],
            'button_label' => [__('Default checkout label', 'oneploy-bridge'), 'text'],
        ];
        foreach ($fields as $name => $field) {
            add_settings_field('oneploy_bridge_'.$name, $field[0], [__CLASS__, 'render_setting_field'], 'oneploy-bridge', 'oneploy_bridge_connection', ['name' => $name, 'type' => $field[1]]);
        }
    }

    public static function sanitize_settings($input)
    {
        $old = self::settings();
        $input = is_array($input) ? $input : [];
        $api_url = self::sanitize_https_url(isset($input['api_url']) ? $input['api_url'] : '');
        $app_url = self::sanitize_https_url(isset($input['app_url']) ? $input['app_url'] : '');
        if (! $api_url || ! $app_url) {
            add_settings_error(self::OPTION, 'invalid_url', __('OnePloy API and application URLs must be valid HTTPS URLs.', 'oneploy-bridge'));
        }
        $currency = strtoupper(sanitize_text_field(isset($input['currency']) ? $input['currency'] : 'USD'));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : $old['currency'];
        $interval = self::interval(isset($input['interval']) ? $input['interval'] : 'monthly');
        $key_id = sanitize_text_field(isset($input['key_id']) ? $input['key_id'] : 'default');
        $key_id = preg_match('/^[A-Za-z0-9._-]{1,64}$/', $key_id) ? $key_id : $old['key_id'];

        return [
            'api_url' => $api_url ? $api_url : $old['api_url'],
            'app_url' => $app_url ? $app_url : $old['app_url'],
            'key_id' => $key_id,
            'currency' => $currency,
            'interval' => $interval,
            'cache_ttl' => min(3600, max(30, absint(isset($input['cache_ttl']) ? $input['cache_ttl'] : 300))),
            'button_label' => sanitize_text_field(isset($input['button_label']) ? $input['button_label'] : __('Start now', 'oneploy-bridge')),
        ];
    }

    private static function sanitize_https_url($url)
    {
        $url = untrailingslashit(esc_url_raw(wp_unslash((string) $url)));
        if (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https' || ! wp_parse_url($url, PHP_URL_HOST)) {
            return false;
        }

        return $url;
    }

    public static function render_setting_field($args)
    {
        $settings = self::settings();
        $name = $args['name'];
        $value = isset($settings[$name]) ? $settings[$name] : '';
        $field_name = self::OPTION.'['.$name.']';
        if ($args['type'] === 'interval') {
            echo '<select name="'.esc_attr($field_name).'">';
            foreach (['monthly' => __('Monthly', 'oneploy-bridge'), 'yearly' => __('Yearly', 'oneploy-bridge')] as $option => $label) {
                echo '<option value="'.esc_attr($option).'" '.selected($value, $option, false).'>'.esc_html($label).'</option>';
            }
            echo '</select>';

            return;
        }
        $type = in_array($args['type'], ['url', 'number'], true) ? $args['type'] : 'text';
        echo '<input class="regular-text" type="'.esc_attr($type).'" name="'.esc_attr($field_name).'" value="'.esc_attr($value).'"'.($type === 'number' ? ' min="30" max="3600"' : '').'>';
    }

    public static function register_admin_page()
    {
        add_options_page(__('OnePloy Bridge', 'oneploy-bridge'), __('OnePloy Bridge', 'oneploy-bridge'), 'manage_options', 'oneploy-bridge', [__CLASS__, 'render_admin_page']);
    }

    public static function render_admin_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage OnePloy Bridge.', 'oneploy-bridge'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('OnePloy Bridge', 'oneploy-bridge'); ?></h1>
            <?php settings_errors(); ?>
            <form action="options.php" method="post">
                <?php settings_fields('oneploy_bridge');
        do_settings_sections('oneploy-bridge');
        submit_button(); ?>
            </form>
            <h2><?php echo esc_html__('Security status', 'oneploy-bridge'); ?></h2>
            <p><?php echo self::secret() !== '' ? esc_html__('The server-side bridge secret is configured.', 'oneploy-bridge') : esc_html__('Checkout is disabled until ONEPLOY_BRIDGE_SECRET is defined in wp-config.php.', 'oneploy-bridge'); ?></p>
            <p><strong><?php echo esc_html__('Never save an administrator API token in WordPress.', 'oneploy-bridge'); ?></strong></p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="oneploy_bridge_test">
                <?php wp_nonce_field('oneploy_bridge_test');
        submit_button(__('Test connection and clear cache', 'oneploy-bridge'), 'secondary', 'submit', false); ?>
            </form>
            <h2><?php echo esc_html__('Quick start', 'oneploy-bridge'); ?></h2>
            <p><code>[oneploy_pricing product="app-hosting" currency="USD" interval="monthly" checkout="yes"]</code></p>
            <p><code>[oneploy_checkout product="app-hosting" plan="pro" provider="stripe" label="Start Pro"]</code></p>
            <p><code>[oneploy_domain_search currency="USD" checkout="yes"]</code></p>
            <p><?php echo esc_html__('See ADMIN-GUIDE.md in the plugin package for page-builder and form examples.', 'oneploy-bridge'); ?></p>
        </div>
        <?php
    }

    public static function test_connection()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to test OnePloy Bridge.', 'oneploy-bridge'));
        }
        check_admin_referer('oneploy_bridge_test');
        self::clear_cache();
        $status = self::api_get('/api/storefront/v1/status', [], 0);
        add_settings_error(self::OPTION, is_wp_error($status) ? 'connection_failed' : 'connection_ok', is_wp_error($status) ? $status->get_error_message() : __('OnePloy connection succeeded.', 'oneploy-bridge'), is_wp_error($status) ? 'error' : 'success');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(add_query_arg('settings-updated', 'true', admin_url('options-general.php?page=oneploy-bridge')));
        exit;
    }

    public static function render_pricing($attributes)
    {
        $settings = self::settings();
        $attributes = shortcode_atts([
            'product' => '', 'plan' => '', 'currency' => $settings['currency'], 'interval' => $settings['interval'],
            'columns' => '3', 'checkout' => 'yes', 'provider' => 'paypal', 'button_text' => $settings['button_label'],
            'show_features' => 'yes', 'campaign' => '',
        ], $attributes, 'oneploy_pricing');
        $attributes['currency'] = strtoupper(sanitize_text_field($attributes['currency']));
        $attributes['interval'] = self::interval($attributes['interval']);
        $attributes['provider'] = self::provider($attributes['provider']);
        $attributes['columns'] = min(4, max(1, absint($attributes['columns'])));
        $catalog = self::api_get('/api/storefront/v1/catalogue', ['currency' => $attributes['currency'], 'interval' => $attributes['interval']]);
        if (is_wp_error($catalog)) {
            return self::message($catalog->get_error_message(), 'error');
        }

        self::enqueue_assets();
        $products = isset($catalog['products']) && is_array($catalog['products']) ? $catalog['products'] : [];
        ob_start();
        echo '<div class="oneploy-pricing oneploy-columns-'.esc_attr($attributes['columns']).'">';
        $rendered = 0;
        foreach ($products as $product) {
            if (! is_array($product) || ($attributes['product'] && $attributes['product'] !== (isset($product['slug']) ? $product['slug'] : ''))) {
                continue;
            }
            $plans = isset($product['plans']) && is_array($product['plans']) ? $product['plans'] : [];
            foreach ($plans as $plan) {
                if (! is_array($plan) || ($attributes['plan'] && $attributes['plan'] !== (isset($plan['slug']) ? $plan['slug'] : '')) || empty($plan['price']['id'])) {
                    continue;
                }
                $rendered++;
                echo '<article class="oneploy-plan-card">';
                echo '<p class="oneploy-product-name">'.esc_html(self::safe_text(isset($product['name']) ? $product['name'] : '')).'</p>';
                echo '<h3>'.esc_html(self::safe_text(isset($plan['name']) ? $plan['name'] : '')).'</h3>';
                echo '<p class="oneploy-price"><strong>'.esc_html(self::safe_text(isset($plan['price']['formatted']) ? $plan['price']['formatted'] : '')).'</strong><span>/'.esc_html($attributes['interval']).'</span></p>';
                if ($attributes['show_features'] === 'yes' && ! empty($plan['features']) && is_array($plan['features'])) {
                    echo '<ul class="oneploy-features">';
                    foreach ($plan['features'] as $feature) {
                        if (is_scalar($feature)) {
                            echo '<li>'.esc_html((string) $feature).'</li>';
                        }
                    }
                    echo '</ul>';
                }
                if ($attributes['checkout'] === 'yes') {
                    echo self::checkout_button((int) $plan['price']['id'], $attributes['provider'], $attributes['button_text'], $attributes['campaign']);
                }
                echo '</article>';
            }
        }
        echo '</div>';
        $html = ob_get_clean();

        return $rendered ? $html : self::message(__('No matching active OnePloy prices were found.', 'oneploy-bridge'), 'notice');
    }

    public static function render_checkout($attributes, $content = null)
    {
        $settings = self::settings();
        $attributes = shortcode_atts([
            'price_id' => '', 'product' => '', 'plan' => '', 'currency' => $settings['currency'],
            'interval' => $settings['interval'], 'provider' => 'paypal', 'label' => $settings['button_label'], 'campaign' => '',
        ], $attributes, 'oneploy_checkout');
        $catalog = self::api_get('/api/storefront/v1/catalogue', [
            'currency' => strtoupper(sanitize_text_field($attributes['currency'])),
            'interval' => self::interval($attributes['interval']),
        ]);
        if (is_wp_error($catalog)) {
            return self::message($catalog->get_error_message(), 'error');
        }
        $price_id = self::find_price_id($catalog, $attributes);
        if (! $price_id) {
            return self::message(__('The selected OnePloy price is unavailable.', 'oneploy-bridge'), 'notice');
        }
        self::enqueue_assets();
        $label = $content !== null && trim($content) !== '' ? wp_strip_all_tags($content) : $attributes['label'];

        return self::checkout_button($price_id, self::provider($attributes['provider']), $label, $attributes['campaign']);
    }

    public static function render_domain_search($attributes)
    {
        $settings = self::settings();
        $attributes = shortcode_atts([
            'currency' => $settings['currency'], 'checkout' => 'yes', 'placeholder' => __('yourbrand.com', 'oneploy-bridge'),
            'button_text' => __('Search domain', 'oneploy-bridge'), 'mode' => 'widget', 'form_selector' => '',
            'input_selector' => '[name="domain"]', 'results_selector' => '',
        ], $attributes, 'oneploy_domain_search');
        self::enqueue_assets();
        $currency = strtoupper(sanitize_text_field($attributes['currency']));
        if ($attributes['mode'] === 'bind') {
            return '<span class="oneploy-domain-binder" hidden data-oneploy-domain-binder data-form-selector="'.esc_attr($attributes['form_selector']).'" data-input-selector="'.esc_attr($attributes['input_selector']).'" data-results-selector="'.esc_attr($attributes['results_selector']).'" data-currency="'.esc_attr($currency).'" data-checkout="'.esc_attr($attributes['checkout']).'"></span>';
        }

        ob_start(); ?>
        <form class="oneploy-domain-search" data-oneploy-domain-form data-oneploy-domain-widget="true" data-oneploy-currency="<?php echo esc_attr($currency); ?>" data-oneploy-checkout="<?php echo esc_attr($attributes['checkout']); ?>">
            <label><span class="screen-reader-text"><?php echo esc_html__('Domain name', 'oneploy-bridge'); ?></span><input type="text" name="domain" data-oneploy-domain-input placeholder="<?php echo esc_attr($attributes['placeholder']); ?>" autocomplete="off" required></label>
            <button type="submit"><?php echo esc_html($attributes['button_text']); ?></button>
            <div class="oneploy-domain-results" data-oneploy-domain-results aria-live="polite"></div>
        </form>
        <?php return ob_get_clean();
    }

    public static function render_status()
    {
        $status = self::api_get('/api/storefront/v1/status', [], 30);
        if (is_wp_error($status)) {
            return self::message(__('Status unavailable', 'oneploy-bridge'), 'notice');
        }
        $value = self::safe_text(isset($status['status']) ? $status['status'] : 'unknown');

        return '<p class="oneploy-status"><span aria-hidden="true"></span>'.esc_html(sprintf(__('OnePloy status: %s', 'oneploy-bridge'), $value)).'</p>';
    }

    public static function redirect_checkout()
    {
        nocache_headers();
        $price_id = isset($_GET['price_id']) ? absint($_GET['price_id']) : 0;
        $provider = self::provider(isset($_GET['provider']) ? wp_unslash($_GET['provider']) : 'paypal');
        $campaign = substr(sanitize_text_field(isset($_GET['campaign']) ? wp_unslash($_GET['campaign']) : ''), 0, 100);
        $return_url = esc_url_raw(isset($_GET['return_url']) ? wp_unslash($_GET['return_url']) : home_url('/'));
        if (! $price_id || strlen($return_url) > 2048 || ! self::same_site_url($return_url)) {
            wp_die(esc_html__('Invalid checkout request.', 'oneploy-bridge'), esc_html__('Checkout unavailable', 'oneploy-bridge'), ['response' => 422]);
        }
        if (self::checkout_rate_limited()) {
            wp_die(esc_html__('Too many checkout requests. Please wait a minute and try again.', 'oneploy-bridge'), esc_html__('Checkout temporarily limited', 'oneploy-bridge'), ['response' => 429]);
        }
        $url = self::signed_checkout_url($price_id, $provider, $campaign, $return_url);
        if (is_wp_error($url)) {
            wp_die(esc_html($url->get_error_message()), esc_html__('Checkout unavailable', 'oneploy-bridge'), ['response' => 503]);
        }
        wp_redirect(esc_url_raw($url), 302, 'OnePloy Bridge');
        exit;
    }

    private static function checkout_button($price_id, $provider, $label, $campaign)
    {
        $settings = self::settings();
        $fallback = add_query_arg('price_id', $price_id, $settings['app_url'].'/billing');
        $return_url = get_permalink();
        if (! $return_url || ! self::same_site_url($return_url)) {
            $return_url = home_url('/');
        }
        if (self::secret() !== '') {
            $fallback = add_query_arg([
                'action' => 'oneploy_bridge_checkout',
                'price_id' => (int) $price_id,
                'provider' => self::provider($provider),
                'campaign' => substr(sanitize_text_field($campaign), 0, 100),
                'return_url' => $return_url,
            ], admin_url('admin-post.php'));
        }

        return '<a class="oneploy-checkout-button" href="'.esc_url($fallback).'">'.esc_html($label).'</a>';
    }

    private static function signed_checkout_url($price_id, $provider, $campaign, $return_url)
    {
        $secret = self::secret();
        if ($secret === '') {
            return new WP_Error('missing_secret', __('Secure checkout is not configured on this WordPress site.', 'oneploy-bridge'));
        }
        $settings = self::settings();
        $payload = [
            'price_id' => (int) $price_id, 'provider' => self::provider($provider), 'issued_at' => time(),
            'nonce' => str_replace('-', '', wp_generate_uuid4()), 'key_id' => $settings['key_id'],
            'source' => 'wordpress', 'campaign' => substr(sanitize_text_field($campaign), 0, 100), 'return_url' => $return_url,
        ];
        ksort($payload);
        $signature = hash_hmac('sha256', http_build_query($payload, '', '&', PHP_QUERY_RFC3986), $secret);

        return add_query_arg(array_merge($payload, ['signature' => $signature]), $settings['app_url'].'/marketing/checkout');
    }

    private static function secret()
    {
        if (defined('ONEPLOY_BRIDGE_SECRET')) {
            return trim((string) constant('ONEPLOY_BRIDGE_SECRET'));
        }
        $secret = getenv('ONEPLOY_BRIDGE_SECRET');

        return $secret === false ? '' : trim((string) $secret);
    }

    private static function api_get($path, $query = [], $ttl = null)
    {
        $settings = self::settings();
        $ttl = $ttl === null ? (int) $settings['cache_ttl'] : (int) $ttl;
        $url = add_query_arg($query, $settings['api_url'].$path);
        $cache_key = 'oneploy_'.md5($url);
        if ($ttl > 0) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = wp_safe_remote_get($url, [
            'timeout' => 10,
            'redirection' => 2,
            'headers' => ['Accept' => 'application/json', 'User-Agent' => 'OnePloy-Bridge/'.self::VERSION.'; '.home_url('/')],
        ]);
        if (is_wp_error($response)) {
            return self::stale_or_error($cache_key, $response);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || strlen($body) > 1048576) {
            return self::stale_or_error($cache_key, new WP_Error('upstream_error', __('OnePloy is temporarily unavailable.', 'oneploy-bridge')));
        }
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return self::stale_or_error($cache_key, new WP_Error('invalid_response', __('OnePloy returned an invalid response.', 'oneploy-bridge')));
        }
        if ($ttl > 0) {
            set_transient($cache_key, $data, $ttl);
            set_transient($cache_key.'_stale', $data, DAY_IN_SECONDS);
            self::remember_cache_key($cache_key);
        }

        return $data;
    }

    private static function stale_or_error($cache_key, $error)
    {
        $stale = get_transient($cache_key.'_stale');

        return is_array($stale) ? $stale : $error;
    }

    private static function remember_cache_key($cache_key)
    {
        $keys = get_option(self::CACHE_KEYS_OPTION, []);
        $keys = is_array($keys) ? $keys : [];
        $keys[] = $cache_key;
        update_option(self::CACHE_KEYS_OPTION, array_slice(array_values(array_unique($keys)), -100), false);
    }

    public static function clear_cache($old_value = null, $value = null)
    {
        $keys = get_option(self::CACHE_KEYS_OPTION, []);
        foreach (is_array($keys) ? $keys : [] as $key) {
            delete_transient($key);
            delete_transient($key.'_stale');
        }
        delete_option(self::CACHE_KEYS_OPTION);
    }

    private static function find_price_id($catalog, $attributes)
    {
        $requested_id = absint($attributes['price_id']);
        $products = isset($catalog['products']) && is_array($catalog['products']) ? $catalog['products'] : [];
        foreach ($products as $product) {
            if (! is_array($product) || ($attributes['product'] && $attributes['product'] !== (isset($product['slug']) ? $product['slug'] : ''))) {
                continue;
            }
            $plans = isset($product['plans']) && is_array($product['plans']) ? $product['plans'] : [];
            foreach ($plans as $plan) {
                $price_id = isset($plan['price']['id']) ? absint($plan['price']['id']) : 0;
                if (($requested_id && $requested_id === $price_id) || (! $requested_id && (! $attributes['plan'] || $attributes['plan'] === (isset($plan['slug']) ? $plan['slug'] : '')))) {
                    return $price_id;
                }
            }
        }

        return 0;
    }

    private static function interval($interval)
    {
        $interval = sanitize_key($interval);

        return in_array($interval, ['monthly', 'yearly'], true) ? $interval : 'monthly';
    }

    private static function provider($provider)
    {
        $provider = sanitize_key($provider);

        return in_array($provider, ['paypal', 'stripe', 'razorpay'], true) ? $provider : 'paypal';
    }

    private static function same_site_url($url)
    {
        return strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) === 'https'
            && strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    }

    private static function checkout_rate_limited()
    {
        $remote_address = sanitize_text_field(isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : 'unknown');
        $key = 'oneploy_checkout_rate_'.md5($remote_address);
        $attempts = (int) get_transient($key);
        if ($attempts >= 120) {
            return true;
        }

        set_transient($key, $attempts + 1, MINUTE_IN_SECONDS);

        return false;
    }

    private static function safe_text($value)
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function message($message, $type)
    {
        return '<p class="oneploy-message oneploy-message-'.esc_attr($type).'">'.esc_html($message).'</p>';
    }

    public static function plugin_action_links($links)
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('options-general.php?page=oneploy-bridge')).'">'.esc_html__('Settings', 'oneploy-bridge').'</a>');

        return $links;
    }
}

register_activation_hook(__FILE__, ['OnePloy_Bridge', 'activate']);
OnePloy_Bridge::init();
