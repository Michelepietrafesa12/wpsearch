<?php
/**
 * WC SmartSearch Admin
 *
 * Admin dashboard with tabs: Settings, Boosting, Banners, Analytics, Correlations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSS_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'register_settings']);

        // Admin AJAX actions
        add_action('wp_ajax_wcss_admin_save_boost', [$this, 'save_boost']);
        add_action('wp_ajax_wcss_admin_delete_boost', [$this, 'delete_boost']);
        add_action('wp_ajax_wcss_admin_toggle_boost', [$this, 'toggle_boost']);
        add_action('wp_ajax_wcss_admin_save_banner', [$this, 'save_banner']);
        add_action('wp_ajax_wcss_admin_delete_banner', [$this, 'delete_banner']);
        add_action('wp_ajax_wcss_admin_toggle_banner', [$this, 'toggle_banner']);
        add_action('wp_ajax_wcss_admin_save_synonym', [$this, 'save_synonym']);
        add_action('wp_ajax_wcss_admin_delete_synonym', [$this, 'delete_synonym']);
        add_action('wp_ajax_wcss_admin_calculate_correlations', [$this, 'calculate_correlations']);
        add_action('wp_ajax_wcss_admin_flush_cache', [$this, 'flush_cache']);
        add_action('wp_ajax_wcss_admin_search_products', [$this, 'search_products']);
    }

    /**
     * Add admin menu.
     */
    public function add_menu() {
        add_menu_page(
            __('SmartSearch', 'wc-smartsearch'),
            __('SmartSearch', 'wc-smartsearch'),
            'manage_woocommerce',
            'wcss-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-search',
            56
        );
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_wcss-dashboard') {
            return;
        }

        wp_enqueue_style('wcss-admin', WCSS_PLUGIN_URL . 'assets/css/admin-dashboard.css', [], WCSS_VERSION);
        wp_enqueue_media();

        wp_enqueue_script('wcss-admin', WCSS_PLUGIN_URL . 'assets/js/admin-dashboard.js', ['jquery'], WCSS_VERSION, true);
        wp_localize_script('wcss-admin', 'wcss_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wcss_admin_nonce'),
            'i18n'     => [
                'confirm_delete' => __('Sei sicuro di voler eliminare questo elemento?', 'wc-smartsearch'),
                'saved'          => __('Salvato!', 'wc-smartsearch'),
                'error'          => __('Errore durante il salvataggio', 'wc-smartsearch'),
                'calculating'    => __('Calcolo in corso...', 'wc-smartsearch'),
                'calculated'     => __('Correlazioni calcolate!', 'wc-smartsearch'),
            ],
        ]);
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('wcss_options_group', 'wcss_options', [
            'sanitize_callback' => [$this, 'sanitize_options'],
        ]);
    }

    /**
     * Sanitize options.
     */
    public function sanitize_options($input) {
        $sanitized = [];
        $sanitized['enabled']                    = !empty($input['enabled']) ? 1 : 0;
        $sanitized['min_chars']                  = max(1, intval($input['min_chars'] ?? 2));
        $sanitized['max_results']                = max(10, min(500, intval($input['max_results'] ?? 200)));
        $sanitized['fuzzy_enabled']              = !empty($input['fuzzy_enabled']) ? 1 : 0;
        $sanitized['synonyms_enabled']           = !empty($input['synonyms_enabled']) ? 1 : 0;
        $sanitized['filters_enabled']            = !empty($input['filters_enabled']) ? 1 : 0;
        $sanitized['cache_enabled']              = !empty($input['cache_enabled']) ? 1 : 0;
        $sanitized['cache_ttl']                  = max(60, intval($input['cache_ttl'] ?? 300));
        $sanitized['recommendations_enabled']    = !empty($input['recommendations_enabled']) ? 1 : 0;
        $sanitized['recommendations_days']       = max(30, intval($input['recommendations_days'] ?? 180));
        $sanitized['recommendations_min_orders'] = max(1, intval($input['recommendations_min_orders'] ?? 2));
        $sanitized['custom_css']                 = wp_strip_all_tags($input['custom_css'] ?? '');
        return $sanitized;
    }

    /**
     * Render the admin dashboard.
     */
    public function render_dashboard() {
        $options = wcss_get_options();
        $allowed_tabs = ['settings', 'boosting', 'banners', 'synonyms', 'correlations'];
        $active_tab = isset($_GET['tab']) && in_array(wp_unslash($_GET['tab']), $allowed_tabs, true)
            ? sanitize_text_field(wp_unslash($_GET['tab']))
            : 'settings';

        // Get data only for the active tab to reduce DB load
        $boosts = [];
        $banners = [];
        $synonyms = [];
        $corr_stats = ['total_correlations' => 0, 'total_products' => 0, 'avg_score' => 0, 'last_update' => __('Mai calcolato', 'wc-smartsearch')];

        try {
            switch ($active_tab) {
                case 'boosting':
                    $boosts = $this->get_boosts();
                    break;
                case 'banners':
                    $banners = $this->get_banners();
                    break;
                case 'synonyms':
                    $synonyms = $this->get_synonyms();
                    break;
                case 'correlations':
                    $correlations = new WCSS_Correlations();
                    $corr_stats = $correlations->get_stats();
                    break;
            }
        } catch (\Throwable $e) {
            error_log('WC SmartSearch admin error: ' . $e->getMessage());
        }

        include WCSS_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Get all boosts.
     */
    private function get_boosts() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT b.*, p.post_title as product_name
             FROM {$wpdb->prefix}wcss_boosted_products b
             LEFT JOIN {$wpdb->posts} p ON b.product_id = p.ID
             ORDER BY b.created_at DESC",
            ARRAY_A
        );
    }

    /**
     * Get all banners.
     */
    private function get_banners() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wcss_banners ORDER BY sort_order ASC, created_at DESC",
            ARRAY_A
        );
    }

    /**
     * Get all synonyms.
     */
    private function get_synonyms() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wcss_synonyms ORDER BY word ASC",
            ARRAY_A
        );
    }

    // ---- AJAX handlers ----

    /**
     * Save a boost.
     */
    public function save_boost() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        global $wpdb;

        $start_date = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
        $end_date = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));

        $data = [
            'product_id' => intval(wp_unslash($_POST['product_id'] ?? 0)),
            'multiplier' => floatval(wp_unslash($_POST['multiplier'] ?? 2.0)),
            'keywords'   => sanitize_text_field(wp_unslash($_POST['keywords'] ?? '')),
            'start_date' => (!empty($start_date) && strtotime($start_date) !== false) ? $start_date : null,
            'end_date'   => (!empty($end_date) && strtotime($end_date) !== false) ? $end_date : null,
            'active'     => 1,
        ];

        if (empty($data['product_id'])) {
            wp_send_json_error(['message' => 'Product ID required']);
        }

        $id = intval(wp_unslash($_POST['boost_id'] ?? 0));

        if ($id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'wcss_boosted_products',
                $data,
                ['id' => $id],
                ['%d', '%f', '%s', '%s', '%s', '%d'],
                ['%d']
            );
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert(
                $wpdb->prefix . 'wcss_boosted_products',
                $data,
                ['%d', '%f', '%s', '%s', '%s', '%d', '%s']
            );
            $id = $wpdb->insert_id;
        }

        wp_send_json_success(['id' => $id]);
    }

    /**
     * Delete a boost.
     */
    public function delete_boost() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $wpdb->delete($wpdb->prefix . 'wcss_boosted_products', ['id' => $id], ['%d']);
        wp_send_json_success();
    }

    /**
     * Toggle a boost.
     */
    public function toggle_boost() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT active FROM {$wpdb->prefix}wcss_boosted_products WHERE id = %d", $id
        ));
        $new_status = $current ? 0 : 1;
        $wpdb->update($wpdb->prefix . 'wcss_boosted_products', ['active' => $new_status], ['id' => $id]);
        wp_send_json_success(['active' => $new_status]);
    }

    /**
     * Save a banner.
     */
    public function save_banner() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        global $wpdb;

        $position = isset($_POST['position']) ? sanitize_text_field(wp_unslash($_POST['position'])) : 'top';
        $b_start = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
        $b_end = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));

        $data = [
            'title'      => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'image_url'  => esc_url_raw(wp_unslash($_POST['image_url'] ?? '')),
            'link_url'   => esc_url_raw(wp_unslash($_POST['link_url'] ?? '')),
            'position'   => in_array($position, ['top', 'middle', 'bottom'], true) ? $position : 'top',
            'keywords'   => sanitize_text_field(wp_unslash($_POST['keywords'] ?? '')),
            'start_date' => (!empty($b_start) && strtotime($b_start) !== false) ? $b_start : null,
            'end_date'   => (!empty($b_end) && strtotime($b_end) !== false) ? $b_end : null,
            'sort_order' => intval(wp_unslash($_POST['sort_order'] ?? 0)),
            'active'     => 1,
        ];

        if (empty($data['image_url'])) {
            wp_send_json_error(['message' => 'Image URL required']);
        }

        $id = intval(wp_unslash($_POST['banner_id'] ?? 0));

        if ($id > 0) {
            $wpdb->update($wpdb->prefix . 'wcss_banners', $data, ['id' => $id], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d'], ['%d']);
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($wpdb->prefix . 'wcss_banners', $data, ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s']);
            $id = $wpdb->insert_id;
        }

        wp_send_json_success(['id' => $id]);
    }

    /**
     * Delete a banner.
     */
    public function delete_banner() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $wpdb->delete($wpdb->prefix . 'wcss_banners', ['id' => $id], ['%d']);
        wp_send_json_success();
    }

    /**
     * Toggle a banner.
     */
    public function toggle_banner() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT active FROM {$wpdb->prefix}wcss_banners WHERE id = %d", $id
        ));
        $new_status = $current ? 0 : 1;
        $wpdb->update($wpdb->prefix . 'wcss_banners', ['active' => $new_status], ['id' => $id]);
        wp_send_json_success(['active' => $new_status]);
    }

    /**
     * Save a synonym.
     */
    public function save_synonym() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        global $wpdb;

        $data = [
            'word'    => sanitize_text_field(wp_unslash($_POST['word'] ?? '')),
            'synonym' => sanitize_text_field(wp_unslash($_POST['synonym'] ?? '')),
            'active'  => 1,
        ];

        if (empty($data['word']) || empty($data['synonym'])) {
            wp_send_json_error(['message' => 'Word and synonym required']);
        }

        $id = intval(wp_unslash($_POST['synonym_id'] ?? 0));

        if ($id > 0) {
            $wpdb->update($wpdb->prefix . 'wcss_synonyms', $data, ['id' => $id], ['%s', '%s', '%d'], ['%d']);
        } else {
            $wpdb->insert($wpdb->prefix . 'wcss_synonyms', $data, ['%s', '%s', '%d']);
            $id = $wpdb->insert_id;
        }

        wp_send_json_success(['id' => $id]);
    }

    /**
     * Delete a synonym.
     */
    public function delete_synonym() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $wpdb->delete($wpdb->prefix . 'wcss_synonyms', ['id' => $id], ['%d']);
        wp_send_json_success();
    }

    /**
     * Calculate correlations.
     */
    public function calculate_correlations() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        $options = wcss_get_options();
        $correlations = new WCSS_Correlations();
        $stats = $correlations->calculate(
            intval($options['recommendations_days'] ?? 180),
            intval($options['recommendations_min_orders'] ?? 2)
        );

        wp_send_json_success($stats);
    }

    /**
     * Flush cache.
     */
    public function flush_cache() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        $cache = new WCSS_Cache();
        $cache->flush();

        wp_send_json_success();
    }

    /**
     * Search products for autocomplete in admin.
     */
    public function search_products() {
        check_ajax_referer('wcss_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';

        if (empty($term)) {
            wp_send_json([]);
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like($term) . '%';
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status = 'publish'
               AND (post_title LIKE %s OR ID = %d)
             ORDER BY post_title ASC
             LIMIT 20",
            $like, intval($term)
        ), ARRAY_A);

        $products = [];
        foreach ($results as $row) {
            $products[] = [
                'id'    => intval($row['ID']),
                'label' => $row['post_title'] . ' (#' . $row['ID'] . ')',
                'value' => intval($row['ID']),
            ];
        }

        wp_send_json($products);
    }
}
