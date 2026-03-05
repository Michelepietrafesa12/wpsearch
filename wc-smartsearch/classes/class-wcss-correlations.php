<?php
/**
 * WC SmartSearch Correlations
 *
 * Product recommendation engine based on real purchase data.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSS_Correlations {

    /**
     * Calculate product correlations from order history.
     *
     * @param int $days       Days of order history to analyze.
     * @param int $min_orders Minimum shared orders for a correlation.
     * @return array Statistics about the calculation.
     */
    public function calculate($days = 180, $min_orders = 2) {
        global $wpdb;

        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Check HPOS (High-Performance Order Storage)
        $use_hpos = $this->is_hpos_enabled();

        if ($use_hpos) {
            $order_items_sql = "
                SELECT oi1.product_id AS product_a, oi2.product_id AS product_b, COUNT(DISTINCT o.id) AS shared_orders
                FROM {$wpdb->prefix}wc_orders o
                INNER JOIN {$wpdb->prefix}wc_order_product_lookup oi1 ON o.id = oi1.order_id
                INNER JOIN {$wpdb->prefix}wc_order_product_lookup oi2 ON o.id = oi2.order_id
                WHERE o.status IN ('wc-completed', 'wc-processing')
                  AND o.date_created_gmt >= %s
                  AND oi1.product_id < oi2.product_id
                GROUP BY oi1.product_id, oi2.product_id
                HAVING shared_orders >= %d
            ";
        } else {
            $order_items_sql = "
                SELECT oim1.meta_value+0 AS product_a, oim2.meta_value+0 AS product_b, COUNT(DISTINCT p.ID) AS shared_orders
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->prefix}woocommerce_order_items oi1 ON p.ID = oi1.order_id AND oi1.order_item_type = 'line_item'
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim1 ON oi1.order_item_id = oim1.order_item_id AND oim1.meta_key = '_product_id'
                INNER JOIN {$wpdb->prefix}woocommerce_order_items oi2 ON p.ID = oi2.order_id AND oi2.order_item_type = 'line_item'
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi2.order_item_id = oim2.order_item_id AND oim2.meta_key = '_product_id'
                WHERE p.post_type = 'shop_order'
                  AND p.post_status IN ('wc-completed', 'wc-processing')
                  AND p.post_date >= %s
                  AND oim1.meta_value+0 < oim2.meta_value+0
                GROUP BY product_a, product_b
                HAVING shared_orders >= %d
            ";
        }

        $pairs = $wpdb->get_results($wpdb->prepare($order_items_sql, $date_from, $min_orders), ARRAY_A);

        if (empty($pairs)) {
            return [
                'total_correlations' => 0,
                'total_products'     => 0,
                'avg_score'          => 0,
            ];
        }

        // Clear old correlations
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wcss_correlations");

        // Calculate scores and insert
        $total_correlations = 0;
        $total_score = 0;
        $products = [];

        foreach ($pairs as $pair) {
            $product_a = intval($pair['product_a']);
            $product_b = intval($pair['product_b']);
            $shared = intval($pair['shared_orders']);

            // Score: log-weighted shared orders
            $score = round(log($shared + 1) * 100, 4);

            // Insert both directions
            $wpdb->replace(
                $wpdb->prefix . 'wcss_correlations',
                [
                    'product_id'            => $product_a,
                    'correlated_product_id' => $product_b,
                    'score'                 => $score,
                    'order_count'           => $shared,
                    'updated_at'            => current_time('mysql'),
                ],
                ['%d', '%d', '%f', '%d', '%s']
            );

            $wpdb->replace(
                $wpdb->prefix . 'wcss_correlations',
                [
                    'product_id'            => $product_b,
                    'correlated_product_id' => $product_a,
                    'score'                 => $score,
                    'order_count'           => $shared,
                    'updated_at'            => current_time('mysql'),
                ],
                ['%d', '%d', '%f', '%d', '%s']
            );

            $total_correlations += 2;
            $total_score += $score * 2;
            $products[$product_a] = true;
            $products[$product_b] = true;
        }

        return [
            'total_correlations' => $total_correlations,
            'total_products'     => count($products),
            'avg_score'          => $total_correlations > 0 ? round($total_score / $total_correlations, 2) : 0,
        ];
    }

    /**
     * Get correlated products for a product.
     *
     * @param int $product_id Product ID.
     * @param int $limit      Number of results.
     * @return array Correlated products.
     */
    public function get_correlated($product_id, $limit = 12) {
        global $wpdb;

        $correlated = $wpdb->get_results($wpdb->prepare(
            "SELECT c.correlated_product_id, c.score, c.order_count,
                    p.post_title, p.post_status
             FROM {$wpdb->prefix}wcss_correlations c
             INNER JOIN {$wpdb->posts} p ON c.correlated_product_id = p.ID
             WHERE c.product_id = %d
               AND p.post_status = 'publish'
               AND p.post_type = 'product'
             ORDER BY c.score DESC
             LIMIT %d",
            $product_id, $limit
        ), ARRAY_A);

        if (!empty($correlated)) {
            return $this->format_recommendations($correlated);
        }

        // Fallback: same category products
        return $this->get_category_fallback($product_id, $limit);
    }

    /**
     * Get correlated products for multiple products (cart).
     *
     * @param array $product_ids Product IDs.
     * @param int   $limit       Number of results.
     * @return array Correlated products.
     */
    public function get_cart_correlated($product_ids, $limit = 12) {
        global $wpdb;

        if (empty($product_ids)) {
            return [];
        }

        $ids = array_map('intval', $product_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $correlated = $wpdb->get_results($wpdb->prepare(
            "SELECT c.correlated_product_id, SUM(c.score) as score, MAX(c.order_count) as order_count,
                    p.post_title, p.post_status
             FROM {$wpdb->prefix}wcss_correlations c
             INNER JOIN {$wpdb->posts} p ON c.correlated_product_id = p.ID
             WHERE c.product_id IN ({$placeholders})
               AND c.correlated_product_id NOT IN ({$placeholders})
               AND p.post_status = 'publish'
               AND p.post_type = 'product'
             GROUP BY c.correlated_product_id
             ORDER BY score DESC
             LIMIT %d",
            ...array_merge($ids, $ids, [$limit])
        ), ARRAY_A);

        if (!empty($correlated)) {
            return $this->format_recommendations($correlated);
        }

        // Fallback: bestsellers
        return $this->get_bestseller_fallback($product_ids, $limit);
    }

    /**
     * Format correlated products for frontend display.
     */
    private function format_recommendations($rows) {
        $products = [];
        foreach ($rows as $row) {
            $pid = intval($row['correlated_product_id']);
            $wc_product = wc_get_product($pid);
            if (!$wc_product) {
                continue;
            }

            $products[] = [
                'id'            => $pid,
                'name'          => $wc_product->get_name(),
                'url'           => $wc_product->get_permalink(),
                'image'         => wp_get_attachment_image_url($wc_product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src('woocommerce_thumbnail'),
                'price'         => floatval($wc_product->get_price()),
                'regular_price' => floatval($wc_product->get_regular_price()),
                'sale_price'    => $wc_product->get_sale_price() ? floatval($wc_product->get_sale_price()) : 0,
                'on_sale'       => $wc_product->is_on_sale(),
                'price_html'    => $wc_product->get_price_html(),
                'score'         => floatval($row['score']),
            ];
        }
        return $products;
    }

    /**
     * Fallback: get products from the same category.
     */
    private function get_category_fallback($product_id, $limit = 12) {
        $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'post__not_in'   => [$product_id],
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $terms,
            ]],
            'meta_key'       => 'total_sales',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
        ];

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $post) {
            $wc_product = wc_get_product($post->ID);
            if (!$wc_product) {
                continue;
            }
            $products[] = [
                'id'            => $post->ID,
                'name'          => $wc_product->get_name(),
                'url'           => $wc_product->get_permalink(),
                'image'         => wp_get_attachment_image_url($wc_product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src('woocommerce_thumbnail'),
                'price'         => floatval($wc_product->get_price()),
                'regular_price' => floatval($wc_product->get_regular_price()),
                'sale_price'    => $wc_product->get_sale_price() ? floatval($wc_product->get_sale_price()) : 0,
                'on_sale'       => $wc_product->is_on_sale(),
                'price_html'    => $wc_product->get_price_html(),
                'score'         => 0,
            ];
        }

        return $products;
    }

    /**
     * Fallback: get bestseller products.
     */
    private function get_bestseller_fallback($exclude_ids, $limit = 12) {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'post__not_in'   => $exclude_ids,
            'meta_key'       => 'total_sales',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
        ];

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $post) {
            $wc_product = wc_get_product($post->ID);
            if (!$wc_product) {
                continue;
            }
            $products[] = [
                'id'            => $post->ID,
                'name'          => $wc_product->get_name(),
                'url'           => $wc_product->get_permalink(),
                'image'         => wp_get_attachment_image_url($wc_product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src('woocommerce_thumbnail'),
                'price'         => floatval($wc_product->get_price()),
                'regular_price' => floatval($wc_product->get_regular_price()),
                'sale_price'    => $wc_product->get_sale_price() ? floatval($wc_product->get_sale_price()) : 0,
                'on_sale'       => $wc_product->is_on_sale(),
                'price_html'    => $wc_product->get_price_html(),
                'score'         => 0,
            ];
        }

        return $products;
    }

    /**
     * Get correlation statistics.
     */
    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'wcss_correlations';

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $products = $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM {$table}");
        $avg_score = $wpdb->get_var("SELECT AVG(score) FROM {$table}");
        $last_update = $wpdb->get_var("SELECT MAX(updated_at) FROM {$table}");

        return [
            'total_correlations' => intval($total),
            'total_products'     => intval($products),
            'avg_score'          => round(floatval($avg_score ?: 0), 2),
            'last_update'        => $last_update ?: __('Mai calcolato', 'wc-smartsearch'),
        ];
    }

    /**
     * Check if HPOS is enabled.
     */
    private function is_hpos_enabled() {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        return false;
    }
}
