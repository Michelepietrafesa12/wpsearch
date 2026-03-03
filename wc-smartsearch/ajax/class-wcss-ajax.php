<?php
/**
 * WC SmartSearch AJAX Handler
 *
 * Handles all AJAX endpoints for search, suggestions, filters, banners, and analytics.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSS_Ajax {

    /**
     * Handle search request.
     * GET: action=wcss_search&q={query}&offset={0}&limit={24}
     */
    public function handle_search() {
        check_ajax_referer('wcss_nonce', 'nonce');

        $query = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $offset = isset($_GET['offset']) ? absint($_GET['offset']) : 0;
        $limit = isset($_GET['limit']) ? min(absint($_GET['limit']), 50) : 24;

        $options = wcss_get_options();
        $min_chars = intval($options['min_chars'] ?? 2);

        if (mb_strlen($query) < $min_chars) {
            wp_send_json([
                'products'    => [],
                'total'       => 0,
                'total_count' => 0,
                'offset'      => $offset,
                'limit'       => $limit,
                'has_more'    => false,
                'facets'      => ['brands' => [], 'categories' => [], 'price_min' => 0, 'price_max' => 0],
                'banners'     => ['top' => [], 'middle' => [], 'bottom' => []],
                'did_you_mean'=> [],
            ]);
        }

        // Check cache
        $cache = new WCSS_Cache();
        $args = [
            'offset'       => $offset,
            'limit'        => $limit,
            'category'     => isset($_GET['category']) ? array_map('intval', explode(',', sanitize_text_field(wp_unslash($_GET['category'])))) : [],
            'manufacturer' => isset($_GET['manufacturer']) ? array_map('intval', explode(',', sanitize_text_field(wp_unslash($_GET['manufacturer'])))) : [],
            'price_min'    => isset($_GET['price_min']) ? floatval($_GET['price_min']) : 0,
            'price_max'    => isset($_GET['price_max']) ? floatval($_GET['price_max']) : 0,
        ];

        $cache_key = $cache->build_key($query, $args);
        $cached = $cache->get($cache_key);

        if ($cached !== false) {
            wp_send_json($cached);
        }

        // Perform search
        $engine = new WCSS_Engine();
        $results = $engine->search($query, $args);

        // Cache results
        $cache->set($cache_key, $results);

        // Log search (only first page)
        if ($offset === 0 && !empty($options['analytics_enabled'])) {
            $analytics = new WCSS_Analytics();
            $session_id = isset($_COOKIE['wcss_session']) ? sanitize_text_field($_COOKIE['wcss_session']) : '';
            $analytics->log_search($query, $results['total_count'], $session_id);
        }

        wp_send_json($results);
    }

    /**
     * Handle suggestions request.
     * GET: action=wcss_suggestions&q={query}
     */
    public function handle_suggestions() {
        check_ajax_referer('wcss_nonce', 'nonce');

        $query = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

        if (mb_strlen($query) < 1) {
            wp_send_json(['success' => true, 'suggestions' => []]);
        }

        $engine = new WCSS_Engine();
        $suggestions = $engine->get_suggestions($query);

        wp_send_json([
            'success'     => true,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Handle filters request.
     * GET: action=wcss_filters
     */
    public function handle_filters() {
        check_ajax_referer('wcss_nonce', 'nonce');

        $cache = new WCSS_Cache();
        $cached = $cache->get('available_filters');

        if ($cached !== false) {
            wp_send_json(['success' => true, 'filters' => $cached]);
        }

        $engine = new WCSS_Engine();
        $filters = $engine->get_available_filters();

        $cache->set('available_filters', $filters, 600);

        wp_send_json(['success' => true, 'filters' => $filters]);
    }

    /**
     * Handle banners request.
     * GET: action=wcss_banners&q={query}
     */
    public function handle_banners() {
        check_ajax_referer('wcss_nonce', 'nonce');

        $query = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

        $engine = new WCSS_Engine();
        // We access banners through a search with empty results
        $reflection = new ReflectionMethod($engine, 'get_banners');
        $reflection->setAccessible(true);
        $banners = $reflection->invoke($engine, $query);

        wp_send_json(['success' => true, 'banners' => $banners]);
    }

    /**
     * Handle analytics event.
     * POST: action=wcss_analytics
     */
    public function handle_analytics() {
        check_ajax_referer('wcss_nonce', 'nonce');

        $options = wcss_get_options();
        if (empty($options['analytics_enabled'])) {
            wp_send_json(['success' => true]);
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data)) {
            // Try POST params
            $data = [
                'event_type' => isset($_POST['event_type']) ? sanitize_text_field(wp_unslash($_POST['event_type'])) : '',
                'query'      => isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '',
                'product_id' => isset($_POST['product_id']) ? intval($_POST['product_id']) : 0,
                'session_id' => isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '',
            ];
        }

        $event_type = sanitize_text_field($data['event_type'] ?? '');
        $allowed_events = ['search', 'click', 'add_to_cart', 'conversion'];

        if (!in_array($event_type, $allowed_events, true)) {
            wp_send_json_error(['message' => 'Invalid event type']);
        }

        $analytics = new WCSS_Analytics();
        $analytics->track_event($event_type, [
            'query'      => sanitize_text_field($data['query'] ?? ''),
            'product_id' => intval($data['product_id'] ?? 0),
            'session_id' => sanitize_text_field($data['session_id'] ?? ''),
        ]);

        wp_send_json(['success' => true]);
    }

    /**
     * Handle recommendations request.
     * GET: action=wcss_recommendations&product_id={id} or &product_ids={id1,id2}
     */
    public function handle_recommendations() {
        check_ajax_referer('wcss_nonce', 'nonce');

        $correlations = new WCSS_Correlations();

        // Single product (product page)
        if (isset($_GET['product_id'])) {
            $product_id = intval($_GET['product_id']);
            $products = $correlations->get_correlated($product_id, 12);
            wp_send_json(['success' => true, 'products' => $products]);
        }

        // Multiple products (cart)
        if (isset($_GET['product_ids'])) {
            $product_ids = array_map('intval', explode(',', sanitize_text_field(wp_unslash($_GET['product_ids']))));
            $products = $correlations->get_cart_correlated($product_ids, 12);
            wp_send_json(['success' => true, 'products' => $products]);
        }

        wp_send_json(['success' => true, 'products' => []]);
    }
}
