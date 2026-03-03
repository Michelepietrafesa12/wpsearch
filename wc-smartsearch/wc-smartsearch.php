<?php
/**
 * Plugin Name: WC SmartSearch
 * Plugin URI: https://github.com/wc-smartsearch
 * Description: Motore di ricerca intelligente per WooCommerce con scoring avanzato, fuzzy search, filtri dinamici, product boosting, banner promozionali e prodotti consigliati.
 * Version: 2.2.0
 * Author: SmartSearch
 * Author URI: https://github.com/wc-smartsearch
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-smartsearch
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCSS_VERSION', '2.2.0');
define('WCSS_PLUGIN_FILE', __FILE__);
define('WCSS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCSS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCSS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active.
 */
function wcss_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>' . esc_html__('WC SmartSearch', 'wc-smartsearch') . '</strong> ' . esc_html__('richiede WooCommerce attivo.', 'wc-smartsearch') . '</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Plugin activation.
 */
function wcss_activate() {
    require_once WCSS_PLUGIN_DIR . 'includes/class-wcss-installer.php';
    WCSS_Installer::activate();
}
register_activation_hook(__FILE__, 'wcss_activate');

/**
 * Plugin deactivation.
 */
function wcss_deactivate() {
    require_once WCSS_PLUGIN_DIR . 'includes/class-wcss-installer.php';
    WCSS_Installer::deactivate();
}
register_deactivation_hook(__FILE__, 'wcss_deactivate');

/**
 * Plugin uninstall handled in uninstall.php.
 */

/**
 * Initialize the plugin.
 */
function wcss_init() {
    if (!wcss_check_woocommerce()) {
        return;
    }

    // Load classes
    require_once WCSS_PLUGIN_DIR . 'classes/class-wcss-engine.php';
    require_once WCSS_PLUGIN_DIR . 'classes/class-wcss-cache.php';
    require_once WCSS_PLUGIN_DIR . 'classes/class-wcss-correlations.php';
    require_once WCSS_PLUGIN_DIR . 'includes/class-wcss-cron.php';

    // Load AJAX handler
    require_once WCSS_PLUGIN_DIR . 'ajax/class-wcss-ajax.php';

    // Load admin
    if (is_admin()) {
        require_once WCSS_PLUGIN_DIR . 'admin/class-wcss-admin.php';
        new WCSS_Admin();
    }

    // Frontend hooks
    add_action('wp_enqueue_scripts', 'wcss_enqueue_assets');
    add_action('wp_footer', 'wcss_render_searchbar');
    add_action('woocommerce_after_single_product_summary', 'wcss_render_product_recommendations', 25);
    add_action('woocommerce_after_cart_table', 'wcss_render_cart_recommendations');

    // Shortcode for search trigger (use in Bricks or any builder)
    add_shortcode('wcss_trigger', 'wcss_shortcode_trigger');

    // AJAX hooks
    $ajax = new WCSS_Ajax();
    add_action('wp_ajax_wcss_search', [$ajax, 'handle_search']);
    add_action('wp_ajax_nopriv_wcss_search', [$ajax, 'handle_search']);
    add_action('wp_ajax_wcss_suggestions', [$ajax, 'handle_suggestions']);
    add_action('wp_ajax_nopriv_wcss_suggestions', [$ajax, 'handle_suggestions']);
    add_action('wp_ajax_wcss_filters', [$ajax, 'handle_filters']);
    add_action('wp_ajax_nopriv_wcss_filters', [$ajax, 'handle_filters']);
    add_action('wp_ajax_wcss_banners', [$ajax, 'handle_banners']);
    add_action('wp_ajax_nopriv_wcss_banners', [$ajax, 'handle_banners']);
    add_action('wp_ajax_wcss_recommendations', [$ajax, 'handle_recommendations']);
    add_action('wp_ajax_nopriv_wcss_recommendations', [$ajax, 'handle_recommendations']);

}
add_action('plugins_loaded', 'wcss_init');

/**
 * Enqueue frontend assets.
 */
function wcss_enqueue_assets() {
    $options = wcss_get_options();
    if (empty($options['enabled'])) {
        return;
    }

    wp_enqueue_style(
        'wcss-frontend',
        WCSS_PLUGIN_URL . 'assets/css/smartsearch.css',
        [],
        WCSS_VERSION
    );

    if (!empty($options['custom_css'])) {
        wp_add_inline_style('wcss-frontend', wp_strip_all_tags($options['custom_css']));
    }

    wp_enqueue_script(
        'wcss-frontend',
        WCSS_PLUGIN_URL . 'assets/js/smartsearch.js',
        [],
        WCSS_VERSION,
        true
    );

    $product_id = 0;
    $cart_product_ids = [];

    if (is_product()) {
        $product_id = get_the_ID();
    }

    if (function_exists('WC') && WC()->cart) {
        foreach (WC()->cart->get_cart() as $item) {
            $cart_product_ids[] = $item['product_id'];
        }
    }

    wp_localize_script('wcss-frontend', 'wcss_params', [
        'ajax_url'           => admin_url('admin-ajax.php'),
        'nonce'              => wp_create_nonce('wcss_nonce'),
        'min_chars'          => intval($options['min_chars'] ?? 2),
        'max_results'        => intval($options['max_results'] ?? 200),
        'results_per_page'   => 24,
        'debounce_delay'     => 300,
        'cache_ttl'          => 300,
        'currency_symbol'    => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '€',
        'product_id'         => $product_id,
        'cart_product_ids'   => $cart_product_ids,
        'recommendations'    => !empty($options['recommendations_enabled']),
        'i18n'               => [
            'search_placeholder' => __('Cerca prodotti...', 'wc-smartsearch'),
            'no_results'         => __('Nessun risultato trovato', 'wc-smartsearch'),
            'loading'            => __('Caricamento...', 'wc-smartsearch'),
            'did_you_mean'       => __('Forse cercavi:', 'wc-smartsearch'),
            'filters'            => __('Filtri', 'wc-smartsearch'),
            'price'              => __('Prezzo', 'wc-smartsearch'),
            'brand'              => __('Marca', 'wc-smartsearch'),
            'categories'         => __('Categorie', 'wc-smartsearch'),
            'apply_filters'      => __('Applica filtri', 'wc-smartsearch'),
            'reset_filters'      => __('Reset', 'wc-smartsearch'),
            'add_to_cart'        => __('Aggiungi al carrello', 'wc-smartsearch'),
            'added'              => __('Aggiunto!', 'wc-smartsearch'),
            'also_bought'        => __('Chi ha acquistato questo ha comprato anche', 'wc-smartsearch'),
            'complete_order'     => __('Completa il tuo ordine', 'wc-smartsearch'),
            'results_count'      => __('%d risultati', 'wc-smartsearch'),
            'show_filters'       => __('Mostra filtri', 'wc-smartsearch'),
            'hide_filters'       => __('Nascondi filtri', 'wc-smartsearch'),
        ],
    ]);
}

/**
 * Shortcode [wcss_trigger] - renders the search trigger button.
 * Use this in Bricks theme header/navbar or any page builder.
 *
 * Attributes:
 *   text  - Button text (default: "Cerca prodotti...")
 *   class - Extra CSS classes
 *   icon  - "yes" or "no" to show/hide icon (default: "yes")
 *
 * Example: [wcss_trigger text="Cerca" class="my-custom-class"]
 */
function wcss_shortcode_trigger($atts) {
    $options = wcss_get_options();
    if (empty($options['enabled'])) {
        return '';
    }

    $atts = shortcode_atts([
        'text'  => __('Cerca prodotti...', 'wc-smartsearch'),
        'class' => '',
        'icon'  => 'yes',
    ], $atts, 'wcss_trigger');

    // Flag that shortcode was used, so footer won't render duplicate trigger
    global $wcss_trigger_rendered;
    $wcss_trigger_rendered = true;

    $classes = 'wcss-search-trigger';
    if (!empty($atts['class'])) {
        $classes .= ' ' . sanitize_html_class($atts['class']);
    }

    $icon = '';
    if ($atts['icon'] !== 'no') {
        $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
    }

    $text_html = '';
    if (!empty($atts['text'])) {
        $text_html = '<span class="wcss-search-trigger-text">' . esc_html($atts['text']) . '</span>';
    }

    return '<div class="' . esc_attr($classes) . '" role="button" tabindex="0" aria-label="' . esc_attr__('Cerca prodotti', 'wc-smartsearch') . '">'
        . $icon . $text_html
        . '</div>';
}

/**
 * Render search overlay in footer.
 * If the [wcss_trigger] shortcode was used, skip the trigger button
 * (only render the overlay and mobile filters panels).
 */
function wcss_render_searchbar() {
    $options = wcss_get_options();
    if (empty($options['enabled'])) {
        return;
    }

    global $wcss_trigger_rendered;

    // Render trigger only if shortcode was NOT used
    if (empty($wcss_trigger_rendered)) {
        ?>
        <div class="wcss-search-trigger" id="wcss-search-trigger" role="button" tabindex="0" aria-label="<?php esc_attr_e('Cerca prodotti', 'wc-smartsearch'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <span class="wcss-search-trigger-text"><?php esc_html_e('Cerca prodotti...', 'wc-smartsearch'); ?></span>
        </div>
        <?php
    }

    // Always render overlay (skip the trigger part of the template)
    include WCSS_PLUGIN_DIR . 'templates/searchbar.php';
}

/**
 * Render product recommendations on single product page.
 */
function wcss_render_product_recommendations() {
    try {
        $options = wcss_get_options();
        if (empty($options['recommendations_enabled'])) {
            return;
        }
        $product_id = get_the_ID();
        if (!$product_id) {
            return;
        }
        echo '<div id="wcss-product-recommendations" data-product-id="' . esc_attr($product_id) . '"></div>';
    } catch (\Throwable $e) {
        // Fail-safe: never break product page
    }
}

/**
 * Render cart recommendations.
 */
function wcss_render_cart_recommendations() {
    try {
        $options = wcss_get_options();
        if (empty($options['recommendations_enabled'])) {
            return;
        }
        $product_ids = [];
        if (function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $product_ids[] = $item['product_id'];
            }
        }
        if (empty($product_ids)) {
            return;
        }
        echo '<div id="wcss-cart-recommendations" data-product-ids="' . esc_attr(implode(',', $product_ids)) . '"></div>';
    } catch (\Throwable $e) {
        // Fail-safe: never break cart/checkout
    }
}

/**
 * Get plugin options with static cache.
 */
function wcss_get_options() {
    static $options = null;
    if ($options === null) {
        $defaults = [
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
            'custom_css'                 => '',
        ];
        $saved = get_option('wcss_options', []);
        $options = wp_parse_args($saved, $defaults);
    }
    return $options;
}

/**
 * Declare HPOS compatibility.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
