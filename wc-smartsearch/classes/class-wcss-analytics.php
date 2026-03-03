<?php
/**
 * WC SmartSearch Analytics
 *
 * Handles search tracking, analytics events, and webhook integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSS_Analytics {

    /**
     * Log a search query.
     *
     * @param string $query         Search query.
     * @param int    $results_count Number of results.
     * @param string $session_id    Session ID.
     */
    public function log_search($query, $results_count = 0, $session_id = '') {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'wcss_search_log',
            [
                'query'         => sanitize_text_field($query),
                'results_count' => intval($results_count),
                'user_id'       => get_current_user_id(),
                'session_id'    => sanitize_text_field($session_id),
                'ip_address'    => $this->get_client_ip(),
                'created_at'    => current_time('mysql'),
            ],
            ['%s', '%d', '%d', '%s', '%s', '%s']
        );

        // Update results_count for suggestions
        $wpdb->update(
            $wpdb->prefix . 'wcss_search_log',
            ['results_count' => $results_count],
            ['query' => $query],
            ['%d'],
            ['%s']
        );
    }

    /**
     * Track an analytics event.
     *
     * @param string $event_type Event type.
     * @param array  $data       Event data.
     */
    public function track_event($event_type, $data = []) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'wcss_analytics',
            [
                'event_type'  => sanitize_text_field($event_type),
                'query'       => sanitize_text_field($data['query'] ?? ''),
                'product_id'  => intval($data['product_id'] ?? 0),
                'order_id'    => intval($data['order_id'] ?? 0),
                'order_total' => floatval($data['order_total'] ?? 0),
                'session_id'  => sanitize_text_field($data['session_id'] ?? ''),
                'user_id'     => get_current_user_id(),
                'extra_data'  => !empty($data['extra']) ? wp_json_encode($data['extra']) : null,
                'created_at'  => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%d', '%f', '%s', '%d', '%s', '%s']
        );

        // Send webhook if configured
        $this->send_webhook($event_type, $data);
    }

    /**
     * Track conversion.
     *
     * @param int    $order_id   Order ID.
     * @param string $session_id Session ID.
     * @param float  $total      Order total.
     */
    public function track_conversion($order_id, $session_id, $total) {
        $this->track_event('conversion', [
            'order_id'    => $order_id,
            'order_total' => $total,
            'session_id'  => $session_id,
        ]);
    }

    /**
     * Get popular searches.
     *
     * @param int $limit Number of results.
     * @param int $days  Days to look back.
     * @return array Popular searches.
     */
    public function get_popular_searches($limit = 20, $days = 30) {
        global $wpdb;

        $days = absint($days);
        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT query, COUNT(*) as search_count,
                    AVG(results_count) as avg_results,
                    MAX(created_at) as last_searched
             FROM {$wpdb->prefix}wcss_search_log
             WHERE created_at >= %s
             GROUP BY query
             ORDER BY search_count DESC
             LIMIT %d",
            $date_from, $limit
        ), ARRAY_A);
    }

    /**
     * Get search statistics.
     *
     * @param int $days Days to look back.
     * @return array Statistics.
     */
    public function get_stats($days = 30) {
        global $wpdb;

        $days = absint($days);
        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $total_searches = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wcss_search_log WHERE created_at >= %s",
            $date_from
        ));

        $unique_queries = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT query) FROM {$wpdb->prefix}wcss_search_log WHERE created_at >= %s",
            $date_from
        ));

        $no_results = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wcss_search_log WHERE created_at >= %s AND results_count = 0",
            $date_from
        ));

        $conversions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wcss_analytics WHERE event_type = 'conversion' AND created_at >= %s",
            $date_from
        ));

        $conversion_revenue = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(order_total) FROM {$wpdb->prefix}wcss_analytics WHERE event_type = 'conversion' AND created_at >= %s",
            $date_from
        ));

        $clicks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wcss_analytics WHERE event_type = 'click' AND created_at >= %s",
            $date_from
        ));

        $add_to_carts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wcss_analytics WHERE event_type = 'add_to_cart' AND created_at >= %s",
            $date_from
        ));

        // Daily breakdown
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as searches
             FROM {$wpdb->prefix}wcss_search_log
             WHERE created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $date_from
        ), ARRAY_A);

        return [
            'total_searches'     => intval($total_searches),
            'unique_queries'     => intval($unique_queries),
            'no_results'         => intval($no_results),
            'conversions'        => intval($conversions),
            'conversion_revenue' => floatval($conversion_revenue ?: 0),
            'clicks'             => intval($clicks),
            'add_to_carts'       => intval($add_to_carts),
            'daily'              => $daily ?: [],
        ];
    }

    /**
     * Send webhook notification.
     *
     * @param string $event_type Event type.
     * @param array  $data       Event data.
     */
    private function send_webhook($event_type, $data) {
        $options = wcss_get_options();
        $webhook_url = $options['analytics_webhook_url'] ?? '';

        if (empty($webhook_url)) {
            return;
        }

        // SSRF protection: validate URL and reject private/internal addresses
        if (!wp_http_validate_url($webhook_url)) {
            return;
        }

        $payload = [
            'event'     => $event_type,
            'data'      => $data,
            'timestamp' => current_time('c'),
            'site_url'  => get_site_url(),
        ];

        wp_remote_post($webhook_url, [
            'body'      => wp_json_encode($payload),
            'headers'   => ['Content-Type' => 'application/json'],
            'timeout'   => 5,
            'blocking'  => false,
        ]);
    }

    /**
     * Cleanup old analytics data.
     *
     * @param int $days Days to keep.
     */
    public function cleanup($days = 90) {
        global $wpdb;

        $days = absint($days);
        $date = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wcss_search_log WHERE created_at < %s",
            $date
        ));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wcss_analytics WHERE created_at < %s",
            $date
        ));
    }

    /**
     * Get client IP address.
     */
    private function get_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }
        // Hash IP for GDPR compliance (not stored in plain text)
        return wp_hash($ip);
    }
}
