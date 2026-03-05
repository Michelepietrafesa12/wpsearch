<?php
/**
 * WC SmartSearch Installer
 *
 * Handles activation, deactivation, and database table creation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSS_Installer {

    /**
     * Plugin activation.
     */
    public static function activate() {
        self::create_tables();
        self::set_default_options();

        // Schedule cron events
        if (!wp_next_scheduled('wcss_calculate_correlations')) {
            $local_time = strtotime('tomorrow 03:00:00', current_time('timestamp', false));
            $utc_time   = $local_time - (int)(get_option('gmt_offset', 0) * HOUR_IN_SECONDS);
            wp_schedule_event($utc_time, 'daily', 'wcss_calculate_correlations');
        }
        update_option('wcss_version', WCSS_VERSION);
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('wcss_calculate_correlations');
        flush_rewrite_rules();
    }

    /**
     * Create database tables.
     */
    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = [];

        // Boosted products table
        $sql[] = "CREATE TABLE {$wpdb->prefix}wcss_boosted_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            multiplier DECIMAL(3,1) DEFAULT 2.0,
            keywords VARCHAR(500) DEFAULT '',
            start_date DATETIME DEFAULT NULL,
            end_date DATETIME DEFAULT NULL,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_product (product_id),
            INDEX idx_active (active),
            INDEX idx_dates (start_date, end_date),
            INDEX idx_active_dates (active, start_date, end_date)
        ) {$charset_collate};";

        // Banners table
        $sql[] = "CREATE TABLE {$wpdb->prefix}wcss_banners (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) DEFAULT '',
            image_url VARCHAR(500) NOT NULL,
            link_url VARCHAR(500) DEFAULT '',
            position ENUM('top','middle','bottom') DEFAULT 'top',
            keywords VARCHAR(500) DEFAULT '',
            start_date DATETIME DEFAULT NULL,
            end_date DATETIME DEFAULT NULL,
            active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_active (active),
            INDEX idx_position (position),
            INDEX idx_dates (start_date, end_date),
            INDEX idx_active_pos_dates (active, position, start_date, end_date)
        ) {$charset_collate};";

        // Synonyms table
        $sql[] = "CREATE TABLE {$wpdb->prefix}wcss_synonyms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            word VARCHAR(100) NOT NULL,
            synonym VARCHAR(100) NOT NULL,
            active TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            INDEX idx_word (word),
            INDEX idx_active (active)
        ) {$charset_collate};";

        // Correlations table
        $sql[] = "CREATE TABLE {$wpdb->prefix}wcss_correlations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            correlated_product_id BIGINT UNSIGNED NOT NULL,
            score DECIMAL(10,4) DEFAULT 0,
            order_count INT UNSIGNED DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_pair (product_id, correlated_product_id),
            INDEX idx_product (product_id),
            INDEX idx_correlated (correlated_product_id),
            INDEX idx_score (score)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($sql as $query) {
            dbDelta($query);
        }
    }

    /**
     * Set default options.
     */
    private static function set_default_options() {
        if (get_option('wcss_options') === false) {
            add_option('wcss_options', [
                'enabled'                    => 1,
                'min_chars'                  => 2,
                'max_results'                => 200,
                'fuzzy_enabled'              => 1,
                'synonyms_enabled'           => 1,
                'filters_enabled'            => 1,
                'cache_enabled'              => 1,
                'cache_ttl'                  => 300,
                'recommendations_enabled'    => 1,
                'recommendations_days'       => 180,
                'recommendations_min_orders' => 2,
            ]);
        }
    }
}
