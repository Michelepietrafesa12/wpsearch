<?php
/**
 * WC SmartSearch Uninstall
 *
 * Removes all plugin data on uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Remove options
delete_option('wcss_options');
delete_option('wcss_version');

// Drop custom tables
$tables = [
    $wpdb->prefix . 'wcss_search_log',
    $wpdb->prefix . 'wcss_analytics',
    $wpdb->prefix . 'wcss_boosted_products',
    $wpdb->prefix . 'wcss_banners',
    $wpdb->prefix . 'wcss_synonyms',
    $wpdb->prefix . 'wcss_correlations',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Clear scheduled events
wp_clear_scheduled_hook('wcss_calculate_correlations');
wp_clear_scheduled_hook('wcss_cleanup_analytics');
