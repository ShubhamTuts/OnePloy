<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$cache_keys = get_option('oneploy_bridge_cache_keys', []);
foreach (is_array($cache_keys) ? $cache_keys : [] as $cache_key) {
    delete_transient($cache_key);
    delete_transient($cache_key.'_stale');
}

delete_option('oneploy_bridge_cache_keys');
delete_option('oneploy_bridge_options');
