<?php
/**
 * WC SmartSearch Cache
 *
 * Handles search result caching using WordPress transients.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSS_Cache {

    /** @var string Cache key prefix */
    private $prefix = 'wcss_';

    /**
     * Get cached result.
     *
     * @param string $key Cache key.
     * @return mixed|false Cached data or false.
     */
    public function get($key) {
        $options = wcss_get_options();
        if (empty($options['cache_enabled'])) {
            return false;
        }
        return get_transient($this->prefix . md5($key));
    }

    /**
     * Set cache.
     *
     * @param string $key  Cache key.
     * @param mixed  $data Data to cache.
     * @param int    $ttl  TTL in seconds (default from options).
     */
    public function set($key, $data, $ttl = 0) {
        $options = wcss_get_options();
        if (empty($options['cache_enabled'])) {
            return;
        }
        if ($ttl <= 0) {
            $ttl = intval($options['cache_ttl'] ?? 300);
        }
        set_transient($this->prefix . md5($key), $data, $ttl);
    }

    /**
     * Delete specific cache entry.
     *
     * @param string $key Cache key.
     */
    public function delete($key) {
        delete_transient($this->prefix . md5($key));
    }

    /**
     * Flush all plugin caches.
     */
    public function flush() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_wcss_%'
                OR option_name LIKE '_transient_timeout_wcss_%'"
        );
    }

    /**
     * Generate cache key from search parameters.
     *
     * @param string $query Search query.
     * @param array  $args  Search arguments.
     * @return string Cache key.
     */
    public function build_key($query, $args = []) {
        return 'search_' . $query . '_' . md5(wp_json_encode($args));
    }
}
