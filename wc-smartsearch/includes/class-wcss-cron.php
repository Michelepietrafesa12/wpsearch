<?php
/**
 * WC SmartSearch Cron Handler
 *
 * Handles WordPress scheduled events.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSS_Cron {

    /**
     * Register cron hooks.
     */
    public static function init() {
        add_action('wcss_calculate_correlations', [__CLASS__, 'calculate_correlations']);
    }

    /**
     * Calculate product correlations.
     */
    public static function calculate_correlations() {
        try {
            $options = wcss_get_options();
            $correlations = new WCSS_Correlations();
            $correlations->calculate(
                intval($options['recommendations_days'] ?? 180),
                intval($options['recommendations_min_orders'] ?? 2)
            );
        } catch (\Throwable $e) {
            error_log('WC SmartSearch Cron Error (correlations): ' . $e->getMessage());
        }
    }
}

WCSS_Cron::init();
