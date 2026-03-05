<?php
/**
 * WC SmartSearch AJAX Handler
 *
 * Handles all AJAX endpoints for search, suggestions, filters, banners, and recommendations.
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
            return;
        }

        try {
            // Check cache
            $cache = new WCSS_Cache();
            $args = [
                'offset'       => $offset,
                'limit'        => $limit,
                'category'     => isset($_GET['category']) ? array_map('intval', explode(',', sanitize_text_field(wp_unslash($_GET['category'])))) : [],
                'manufacturer' => isset($_GET['manufacturer']) ? array_map('intval', explode(',', sanitize_text_field(wp_unslash($_GET['manufacturer'])))) : [],
                'price_min'    => isset($_GET['price_min']) ? floatval(wp_unslash($_GET['price_min'])) : 0,
                'price_max'    => isset($_GET['price_max']) ? floatval(wp_unslash($_GET['price_max'])) : 0,
            ];

            $cache_key = $cache->build_key($query, $args);
            $cached = $cache->get($cache_key);

            if ($cached !== false) {
                wp_send_json($cached);
                return;
            }

            // Perform search
            $engine = new WCSS_Engine();
            $results = $engine->search($query, $args);

            // Cache results
            $cache->set($cache_key, $results);

            wp_send_json($results);
        } catch (\Throwable $e) {
            error_log('WC SmartSearch search error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Search error'], 500);
        }
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
            return;
        }

        try {
            $engine = new WCSS_Engine();
            $suggestions = $engine->get_suggestions($query);

            wp_send_json([
                'success'     => true,
                'suggestions' => $suggestions,
            ]);
        } catch (\Throwable $e) {
            error_log('WC SmartSearch suggestions error: ' . $e->getMessage());
            wp_send_json(['success' => true, 'suggestions' => []]);
        }
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
            return;
        }

        try {
            $engine = new WCSS_Engine();
            $filters = $engine->get_available_filters();

            $cache->set('available_filters', $filters, 600);

            wp_send_json(['success' => true, 'filters' => $filters]);
        } catch (\Throwable $e) {
            error_log('WC SmartSearch filters error: ' . $e->getMessage());
            wp_send_json(['success' => true, 'filters' => ['brands' => [], 'categories' => [], 'price_min' => 0, 'price_max' => 0]]);
        }
    }

    /**
     * Handle banners request.
     * GET: action=wcss_banners&q={query}
     */
    public function handle_banners() {
        check_ajax_referer('wcss_nonce', 'nonce');

        $query = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

        try {
            $engine = new WCSS_Engine();
            $banners = $engine->get_banners($query);
            wp_send_json(['success' => true, 'banners' => $banners]);
        } catch (\Throwable $e) {
            error_log('WC SmartSearch banners error: ' . $e->getMessage());
            wp_send_json(['success' => true, 'banners' => ['top' => [], 'middle' => [], 'bottom' => []]]);
        }
    }

    /**
     * Handle recommendations request.
     * GET: action=wcss_recommendations&product_id={id} or &product_ids={id1,id2}
     */
    public function handle_recommendations() {
        check_ajax_referer('wcss_nonce', 'nonce');

        try {
            $correlations = new WCSS_Correlations();

            // Single product (product page)
            if (isset($_GET['product_id'])) {
                $product_id = intval($_GET['product_id']);
                $products = $correlations->get_correlated($product_id, 12);
                wp_send_json(['success' => true, 'products' => $products]);
                return;
            }

            // Multiple products (cart)
            if (isset($_GET['product_ids'])) {
                $product_ids = array_map('intval', explode(',', sanitize_text_field(wp_unslash($_GET['product_ids']))));
                $products = $correlations->get_cart_correlated($product_ids, 12);
                wp_send_json(['success' => true, 'products' => $products]);
                return;
            }
        } catch (\Throwable $e) {
            error_log('WC SmartSearch recommendations error: ' . $e->getMessage());
        }

        wp_send_json(['success' => true, 'products' => []]);
    }

    /**
     * Handle bestsellers request (shown when overlay opens with no query).
     * GET: action=wcss_bestsellers&limit={10}
     */
    public function handle_bestsellers() {
        check_ajax_referer('wcss_nonce', 'nonce');

        $limit = isset($_GET['limit']) ? min(absint($_GET['limit']), 20) : 10;

        $cache = new WCSS_Cache();
        $cached = $cache->get('bestsellers_' . $limit);

        if ($cached !== false) {
            wp_send_json(['success' => true, 'products' => $cached]);
            return;
        }

        try {
            $wc_products = wc_get_products([
                'status'   => 'publish',
                'limit'    => $limit,
                'orderby'  => 'popularity',
                'order'    => 'DESC',
                'visibility' => 'visible',
            ]);

            $products = [];

            foreach ($wc_products as $wc_product) {
                $image_id = $wc_product->get_image_id();
                $products[] = [
                    'id'            => $wc_product->get_id(),
                    'name'          => $wc_product->get_name(),
                    'url'           => $wc_product->get_permalink(),
                    'image'         => $image_id ? wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail') : wc_placeholder_img_src('woocommerce_thumbnail'),
                    'price'         => floatval($wc_product->get_price()),
                    'regular_price' => floatval($wc_product->get_regular_price()),
                    'sale_price'    => $wc_product->get_sale_price() ? floatval($wc_product->get_sale_price()) : 0,
                    'on_sale'       => $wc_product->is_on_sale(),
                    'price_html'    => $wc_product->get_price_html(),
                    'brand'         => '',
                    'brand_id'      => 0,
                ];
            }

            $cache->set('bestsellers_' . $limit, $products, 600);

            wp_send_json(['success' => true, 'products' => $products]);
        } catch (\Throwable $e) {
            error_log('WC SmartSearch bestsellers error: ' . $e->getMessage());
            wp_send_json(['success' => true, 'products' => []]);
        }
    }
}
