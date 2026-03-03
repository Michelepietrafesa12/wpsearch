<?php
/**
 * WC SmartSearch - Cron Job: Calculate Product Correlations
 *
 * Can be executed via:
 * 1. CLI: php /path/to/wp-content/plugins/wc-smartsearch/cron/calculate_correlations.php
 * 2. HTTP: https://site.com/wp-content/plugins/wc-smartsearch/cron/calculate_correlations.php?token=TOKEN
 * 3. WordPress Cron: Automatically scheduled daily at 3:00 AM
 *
 * Crontab example:
 * 0 3 * * * /usr/bin/php /var/www/html/wp-content/plugins/wc-smartsearch/cron/calculate_correlations.php >> /var/log/wcss_cron.log 2>&1
 *
 * Generate token:
 * php -r "require_once('/path/to/wp-config.php'); echo hash_hmac('sha256', 'wcss_cron', AUTH_KEY);"
 */

// Determine WordPress root
$wp_load_paths = [
    dirname(__FILE__) . '/../../../../wp-load.php',       // Standard plugin location
    dirname(__FILE__) . '/../../../wp-load.php',          // Non-standard
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    // Try to find wp-load.php by going up directories
    $dir = dirname(__FILE__);
    for ($i = 0; $i < 10; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/wp-load.php')) {
            require_once $dir . '/wp-load.php';
            $wp_loaded = true;
            break;
        }
    }
}

if (!$wp_loaded) {
    echo "Error: Could not find wp-load.php\n";
    exit(1);
}

// Security check for HTTP access
if (php_sapi_name() !== 'cli') {
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    $expected_token = hash_hmac('sha256', 'wcss_cron', AUTH_KEY);

    if (!hash_equals($expected_token, $token)) {
        http_response_code(403);
        echo "Forbidden: Invalid token\n";
        exit(1);
    }
}

// Require plugin files
require_once dirname(__FILE__) . '/../wc-smartsearch.php';
require_once dirname(__FILE__) . '/../classes/class-wcss-correlations.php';

// Check WooCommerce
if (!class_exists('WooCommerce')) {
    echo "Error: WooCommerce is not active\n";
    exit(1);
}

// Get options
$options = wcss_get_options();
$days = intval($options['recommendations_days'] ?? 180);
$min_orders = intval($options['recommendations_min_orders'] ?? 2);

echo "WC SmartSearch - Calculating correlations...\n";
echo "Period: {$days} days\n";
echo "Min orders: {$min_orders}\n";
echo "Started: " . gmdate('Y-m-d H:i:s') . "\n";

$correlations = new WCSS_Correlations();
$result = $correlations->calculate($days, $min_orders);

echo "Completed: " . gmdate('Y-m-d H:i:s') . "\n";
echo "Total correlations: {$result['total_correlations']}\n";
echo "Products with correlations: {$result['total_products']}\n";
echo "Average score: {$result['avg_score']}\n";

exit(0);
