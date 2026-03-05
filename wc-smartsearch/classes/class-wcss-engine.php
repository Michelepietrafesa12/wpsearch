<?php
/**
 * WC SmartSearch Engine
 *
 * Core search engine with 3-level scoring, fuzzy search,
 * Italian language variations, and unit normalization.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSS_Engine {

    /** @var array Italian singular/plural rules */
    private static $italian_rules = [
        ['o', 'i'],     // prodotto/prodotti
        ['a', 'e'],     // barretta/barrette
        ['e', 'i'],     // azione/azioni
        ['ia', 'ie'],   // energia/energie
        ['co', 'chi'],  // pacco/pacchi
        ['go', 'ghi'],  // fungo/funghi
    ];

    /** @var array Unit normalization patterns */
    private static $unit_patterns = [
        '/(\d+)\s*g(?:r)?/i'                            => '$1 g',
        '/(\d+)\s*ml/i'                                  => '$1 ml',
        '/(\d+)\s*kg/i'                                  => '$1 kg',
        '/(\d+)\s*(?:caps?|cps|capsule|capsules)/i'      => '$1 caps',
        '/(\d+)\s*(?:tabs?|compresse|comprimidos)/i'     => '$1 tab',
        '/(\d+)\s*(?:bust[ei]?n?[ae]?|bustine|sachets)/i'=> '$1 bustine',
        '/(\d+)\s*(?:fl\.?oz)/i'                         => '$1 floz',
        '/(\d+)\s*(?:l|lt|litri)/i'                      => '$1 l',
    ];

    /** @var array Unit search expansions */
    private static $unit_expansions = [
        'g'       => ['g', 'gr'],
        'ml'      => ['ml'],
        'kg'      => ['kg'],
        'caps'    => ['caps', 'cps', 'capsule', 'capsules', 'cap'],
        'tab'     => ['tab', 'tabs', 'compresse', 'comprimidos'],
        'bustine' => ['bustine', 'bustina', 'sachets'],
        'l'       => ['l', 'lt', 'litri'],
    ];

    /**
     * Perform search with scoring.
     *
     * @param string $query  Search query.
     * @param array  $args   Search arguments.
     * @return array Search results.
     */
    public function search($query, $args = []) {
        global $wpdb;

        $defaults = [
            'offset'       => 0,
            'limit'        => 24,
            'category'     => [],
            'manufacturer' => [],
            'price_min'    => 0,
            'price_max'    => 0,
        ];
        $args = wp_parse_args($args, $defaults);
        $options = wcss_get_options();

        // Normalize query
        $query = $this->normalize_query($query);
        $words = $this->get_search_words($query);

        if (empty($words)) {
            return $this->empty_result();
        }

        // Expand words with Italian variations and synonyms
        $expanded_words = $this->expand_words($words, $options);

        // Build SQL query with pre-ordering
        $sql_result = $this->build_search_sql($query, $words, $expanded_words, $args, $options);

        if (empty($sql_result['products'])) {
            // Try fuzzy search if enabled
            if (!empty($options['fuzzy_enabled'])) {
                $sql_result = $this->fuzzy_search($query, $words, $args, $options);
            }
        }

        // Apply PHP scoring
        $scored = $this->score_products($sql_result['products'], $query, $words, $options);

        // Apply boosting
        $scored = $this->apply_boosting($scored, $query);

        // Sort by score descending
        usort($scored, function ($a, $b) {
            return $b['_score'] <=> $a['_score'];
        });

        $total_count = count($scored);

        // Apply offset/limit
        $paged = array_slice($scored, $args['offset'], $args['limit']);

        // Get facets
        $facets = $this->get_facets($scored);

        // Get banners
        $banners = $this->get_banners($query);

        // Did you mean
        $did_you_mean = [];
        if ($total_count < 3) {
            $did_you_mean = $this->get_did_you_mean($query, $words);
        }

        return [
            'products'    => $paged,
            'total'       => count($paged),
            'total_count' => $total_count,
            'offset'      => $args['offset'],
            'limit'       => $args['limit'],
            'has_more'    => ($args['offset'] + $args['limit']) < $total_count,
            'facets'      => $facets,
            'banners'     => $banners,
            'did_you_mean'=> $did_you_mean,
        ];
    }

    /**
     * Normalize the search query.
     */
    private function normalize_query($query) {
        $query = mb_strtolower(trim($query));
        $query = preg_replace('/\s+/', ' ', $query);
        // Normalize units in query
        foreach (self::$unit_patterns as $pattern => $replacement) {
            $query = preg_replace($pattern, $replacement, $query);
        }
        return $query;
    }

    /**
     * Get individual search words.
     */
    private function get_search_words($query) {
        $words = preg_split('/\s+/', $query);
        return array_filter($words, function ($w) {
            return mb_strlen($w) >= 1;
        });
    }

    /**
     * Expand words with Italian variations, synonyms, and unit expansions.
     */
    private function expand_words($words, $options) {
        $expanded = [];
        foreach ($words as $word) {
            $variants = [$word];

            // Italian singular/plural
            $variants = array_merge($variants, $this->get_italian_variants($word));

            // Unit expansions
            if (preg_match('/^(\d+)\s*(\w+)$/', $word, $m)) {
                $num = $m[1];
                $unit = mb_strtolower($m[2]);
                foreach (self::$unit_expansions as $canonical => $alts) {
                    if (in_array($unit, $alts) || $unit === $canonical) {
                        foreach ($alts as $alt) {
                            $variants[] = $num . $alt;
                            $variants[] = $num . ' ' . $alt;
                        }
                    }
                }
            }

            // Synonyms
            if (!empty($options['synonyms_enabled'])) {
                $variants = array_merge($variants, $this->get_synonyms($word));
            }

            $expanded[$word] = array_unique($variants);
        }
        return $expanded;
    }

    /**
     * Get Italian singular/plural variants.
     */
    private function get_italian_variants($word) {
        $variants = [];
        foreach (self::$italian_rules as $rule) {
            $singular = $rule[0];
            $plural = $rule[1];

            // Check if word ends with singular form
            if (mb_substr($word, -mb_strlen($singular)) === $singular) {
                $variants[] = mb_substr($word, 0, -mb_strlen($singular)) . $plural;
            }
            // Check if word ends with plural form
            if (mb_substr($word, -mb_strlen($plural)) === $plural) {
                $variants[] = mb_substr($word, 0, -mb_strlen($plural)) . $singular;
            }
        }
        return $variants;
    }

    /**
     * Get synonyms from database.
     */
    private function get_synonyms($word) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcss_synonyms';

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT synonym FROM {$table} WHERE word = %s AND active = 1
             UNION
             SELECT word FROM {$table} WHERE synonym = %s AND active = 1",
            $word, $word
        ));

        return $results ?: [];
    }

    /**
     * Build the main search SQL query.
     */
    private function build_search_sql($query, $words, $expanded_words, $args, $options) {
        global $wpdb;

        $max_results = intval($options['max_results'] ?? 200);

        // Separate arrays for values in SQL order: SELECT, WHERE, filters, LIMIT
        $select_values = [];
        $where_values = [];
        $filter_values = [];

        // Brand join
        $brand_join = "LEFT JOIN {$wpdb->term_relationships} tr_brand ON p.ID = tr_brand.object_id
                       LEFT JOIN {$wpdb->term_taxonomy} tt_brand ON tr_brand.term_taxonomy_id = tt_brand.term_taxonomy_id
                           AND tt_brand.taxonomy IN ('pa_brand', 'product_brand', 'pa_marca')
                       LEFT JOIN {$wpdb->terms} t_brand ON tt_brand.term_id = t_brand.term_id";

        // Count how many word-groups match in the title (for pre-ordering) - SELECT clause (comes first in SQL)
        $word_match_cases = [];
        foreach ($expanded_words as $original => $variants) {
            $title_or = [];
            foreach ($variants as $variant) {
                $like = '%' . $wpdb->esc_like($variant) . '%';
                $title_or[] = "p.post_title LIKE %s";
                $select_values[] = $like;
            }
            $word_match_cases[] = 'CASE WHEN (' . implode(' OR ', $title_or) . ') THEN 1 ELSE 0 END';
        }
        $title_match_count = implode(' + ', $word_match_cases);

        // Build WHERE conditions: each word must match in at least ONE field
        $where_conditions = [];
        foreach ($expanded_words as $original => $variants) {
            $word_or = [];
            foreach ($variants as $variant) {
                $like = '%' . $wpdb->esc_like($variant) . '%';
                foreach (['p.post_title', 'p.post_content', 'p.post_excerpt', 'pm_sku.meta_value', 't_brand.name'] as $col) {
                    $word_or[] = "{$col} LIKE %s";
                    $where_values[] = $like;
                }
            }
            $where_conditions[] = '(' . implode(' OR ', $word_or) . ')';
        }
        $any_word_where = implode(' OR ', $where_conditions);

        // Category filter
        $cat_join = '';
        $cat_where = '';
        if (!empty($args['category'])) {
            $cat_ids = array_map('intval', (array)$args['category']);
            $cat_placeholders = implode(',', array_fill(0, count($cat_ids), '%d'));
            $cat_join = "INNER JOIN {$wpdb->term_relationships} tr_cat ON p.ID = tr_cat.object_id
                         INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tr_cat.term_taxonomy_id = tt_cat.term_taxonomy_id
                             AND tt_cat.taxonomy = 'product_cat'";
            $cat_where = " AND tt_cat.term_id IN ({$cat_placeholders})";
            foreach ($cat_ids as $cid) {
                $filter_values[] = $cid;
            }
        }

        // Brand filter
        $mfr_where = '';
        if (!empty($args['manufacturer'])) {
            $mfr_ids = array_map('intval', (array)$args['manufacturer']);
            $mfr_placeholders = implode(',', array_fill(0, count($mfr_ids), '%d'));
            $mfr_where = " AND t_brand.term_id IN ({$mfr_placeholders})";
            foreach ($mfr_ids as $mid) {
                $filter_values[] = $mid;
            }
        }

        // Price filter
        $price_where = '';
        if (!empty($args['price_min'])) {
            $price_where .= " AND CAST(pm_price.meta_value AS DECIMAL(10,2)) >= %f";
            $filter_values[] = floatval($args['price_min']);
        }
        if (!empty($args['price_max'])) {
            $price_where .= " AND CAST(pm_price.meta_value AS DECIMAL(10,2)) <= %f";
            $filter_values[] = floatval($args['price_max']);
        }

        // Merge values in SQL placeholder order: SELECT → WHERE → filters → LIMIT
        $prepare_values = array_merge($select_values, $where_values, $filter_values, [$max_results]);

        $sql = "SELECT DISTINCT p.ID,
                    p.post_title,
                    p.post_content,
                    p.post_excerpt,
                    p.post_date,
                    pm_sku.meta_value AS sku,
                    pm_price.meta_value AS price,
                    pm_regular.meta_value AS regular_price,
                    pm_sale.meta_value AS sale_price,
                    pm_stock.meta_value AS stock_status,
                    pm_total_sales.meta_value AS total_sales,
                    t_brand.name AS brand_name,
                    ({$title_match_count}) AS title_word_matches
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
                LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
                LEFT JOIN {$wpdb->postmeta} pm_regular ON p.ID = pm_regular.post_id AND pm_regular.meta_key = '_regular_price'
                LEFT JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id AND pm_sale.meta_key = '_sale_price'
                LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
                LEFT JOIN {$wpdb->postmeta} pm_total_sales ON p.ID = pm_total_sales.post_id AND pm_total_sales.meta_key = 'total_sales'
                {$brand_join}
                {$cat_join}
                WHERE p.post_type IN ('product', 'product_variation')
                    AND p.post_status = 'publish'
                    AND ({$any_word_where})
                    {$cat_where}
                    {$mfr_where}
                    {$price_where}
                ORDER BY title_word_matches DESC, p.post_title ASC
                LIMIT %d";

        // Single prepare() call with all values
        $results = $wpdb->get_results($wpdb->prepare($sql, ...$prepare_values), ARRAY_A);

        // Format products
        $products = [];
        if ($results) {
            foreach ($results as $row) {
                $products[] = $this->format_product($row);
            }
        }

        return ['products' => $products];
    }

    /**
     * Format a database row into a product array.
     */
    private function format_product($row) {
        $product_id = intval($row['ID']);
        $thumbnail_id = get_post_thumbnail_id($product_id);
        $image_url = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'woocommerce_thumbnail') : wc_placeholder_img_src('woocommerce_thumbnail');

        $price = floatval($row['price'] ?? 0);
        $regular_price = floatval($row['regular_price'] ?? 0);
        $sale_price = !empty($row['sale_price']) ? floatval($row['sale_price']) : 0;

        // Get categories
        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'all']);
        $cat_data = [];
        foreach ($categories as $cat) {
            $cat_data[] = [
                'id'   => $cat->term_id,
                'name' => $cat->name,
                'slug' => $cat->slug,
            ];
        }

        return [
            'id'             => $product_id,
            'name'           => $row['post_title'] ?? '',
            'url'            => get_permalink($product_id),
            'image'          => $image_url,
            'price'          => $price,
            'regular_price'  => $regular_price,
            'sale_price'     => $sale_price,
            'on_sale'        => $sale_price > 0 && $sale_price < $regular_price,
            'sku'            => $row['sku'] ?? '',
            'stock_status'   => $row['stock_status'] ?? 'instock',
            'total_sales'    => intval($row['total_sales'] ?? 0),
            'brand'          => $row['brand_name'] ?? '',
            'categories'     => $cat_data,
            'description'    => $row['post_excerpt'] ?? '',
            'post_date'      => $row['post_date'] ?? '',
            '_score'         => 0,
        ];
    }

    /**
     * Score products using the 3-level priority system.
     */
    private function score_products($products, $query, $words, $options) {
        $total_words = count($words);

        foreach ($products as &$product) {
            $score = 0;
            $name_lower = mb_strtolower($product['name']);
            $sku_lower = mb_strtolower($product['sku']);
            $brand_lower = mb_strtolower($product['brand']);
            $desc_lower = mb_strtolower($product['description']);

            // Count words matching in name
            $name_word_matches = 0;
            foreach ($words as $word) {
                $variants = array_merge([$word], $this->get_italian_variants($word));
                foreach ($variants as $variant) {
                    if (mb_strpos($name_lower, $variant) !== false) {
                        $name_word_matches++;
                        break;
                    }
                }
            }

            // PRIORITY 0 - Complete match (all words in name)
            if ($total_words > 0 && $name_word_matches === $total_words) {
                $score = 1000;

                // Bonus: exact query in name
                if (mb_strpos($name_lower, $query) !== false) {
                    $score += 300;
                }

                // Bonus: name starts with query
                if (mb_strpos($name_lower, $query) === 0) {
                    $score += 100;
                }

                // Bonus: words in brand
                foreach ($words as $word) {
                    if (mb_strpos($brand_lower, $word) !== false) {
                        $score += 10;
                    }
                }

                // Bonus: words in SKU/reference
                foreach ($words as $word) {
                    if (mb_strpos($sku_lower, $word) !== false) {
                        $score += 10;
                    }
                }
            }
            // PRIORITY 1 - Exact query match (as string) in any field
            elseif (mb_strpos($name_lower, $query) !== false) {
                $score = 800;
                if (mb_strpos($name_lower, $query) === 0) {
                    $score += 100;
                }
            } elseif (mb_strpos($sku_lower, $query) !== false) {
                $score = 750;
            } elseif (mb_strpos($brand_lower, $query) !== false) {
                $score = 700;
            }
            // PRIORITY 2 - Partial match
            else {
                foreach ($words as $word) {
                    $variants = array_merge([$word], $this->get_italian_variants($word));
                    $found_in_name = false;
                    $found_in_sku = false;
                    $found_in_brand = false;

                    foreach ($variants as $variant) {
                        if (!$found_in_name && mb_strpos($name_lower, $variant) !== false) {
                            $score += 40;
                            $found_in_name = true;
                        }
                        if (!$found_in_sku && mb_strpos($sku_lower, $variant) !== false) {
                            $score += 35;
                            $found_in_sku = true;
                        }
                        if (!$found_in_brand && mb_strpos($brand_lower, $variant) !== false) {
                            $score += 30;
                            $found_in_brand = true;
                        }
                    }
                }

                $match_ratio = $total_words > 0 ? $name_word_matches / $total_words : 0;
                if ($match_ratio >= 0.75) {
                    $score += 100;
                } elseif ($match_ratio >= 0.5) {
                    $score += 50;
                } elseif ($match_ratio < 0.5) {
                    $score = (int)($score * 0.3);
                }
            }

            // BONUS: Description match (all levels)
            foreach ($words as $word) {
                if (mb_strpos($desc_lower, $word) !== false) {
                    $score += 3;
                }
            }

            // BONUS: Bestseller
            $sales = intval($product['total_sales']);
            if ($sales > 100) {
                $score += 20;
            } elseif ($sales > 50) {
                $score += 15;
            } elseif ($sales > 10) {
                $score += 10;
            }

            // BONUS: New/recent product
            $days_old = (time() - strtotime($product['post_date'])) / 86400;
            if ($days_old < 7) {
                $score += 15;
            } elseif ($days_old < 30) {
                $score += 10;
            }

            // PENALTY: Out of stock (-30%)
            if ($product['stock_status'] === 'outofstock') {
                $score = (int)($score * 0.7);
            }

            $product['_score'] = $score;
        }
        unset($product);

        return $products;
    }

    /**
     * Apply product boosting.
     */
    private function apply_boosting($products, $query) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcss_boosted_products';

        $now = current_time('mysql');
        $boosts = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, multiplier, keywords
             FROM {$table}
             WHERE active = 1
               AND (start_date IS NULL OR start_date <= %s)
               AND (end_date IS NULL OR end_date >= %s)",
            $now, $now
        ), ARRAY_A);

        if (empty($boosts)) {
            return $products;
        }

        $boost_map = [];
        $inject_ids = [];

        foreach ($boosts as $boost) {
            $pid = intval($boost['product_id']);
            $mult = floatval($boost['multiplier']);
            $keywords = array_filter(array_map('trim', explode(',', mb_strtolower($boost['keywords']))));

            // Check if keywords match (empty keywords = always boost)
            $apply = empty($keywords);
            if (!$apply) {
                foreach ($keywords as $kw) {
                    if (mb_strpos($query, $kw) !== false) {
                        $apply = true;
                        break;
                    }
                }
            }

            if ($apply) {
                $boost_map[$pid] = $mult;
                $inject_ids[] = $pid;
            }
        }

        // Apply multiplier to existing products
        $existing_ids = [];
        foreach ($products as &$product) {
            $existing_ids[] = $product['id'];
            if (isset($boost_map[$product['id']])) {
                $product['_score'] = (int)($product['_score'] * $boost_map[$product['id']]);
                $product['_boosted'] = true;
            }
        }
        unset($product);

        // Inject boosted products not in results
        $missing_ids = array_diff($inject_ids, $existing_ids);
        if (!empty($missing_ids)) {
            foreach ($missing_ids as $pid) {
                $wc_product = wc_get_product($pid);
                if (!$wc_product || $wc_product->get_status() !== 'publish') {
                    continue;
                }
                $injected = [
                    'id'             => $pid,
                    'name'           => $wc_product->get_name(),
                    'url'            => $wc_product->get_permalink(),
                    'image'          => wp_get_attachment_image_url($wc_product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src('woocommerce_thumbnail'),
                    'price'          => floatval($wc_product->get_price()),
                    'regular_price'  => floatval($wc_product->get_regular_price()),
                    'sale_price'     => $wc_product->get_sale_price() ? floatval($wc_product->get_sale_price()) : 0,
                    'on_sale'        => $wc_product->is_on_sale(),
                    'sku'            => $wc_product->get_sku(),
                    'stock_status'   => $wc_product->get_stock_status(),
                    'total_sales'    => intval($wc_product->get_total_sales()),
                    'brand'          => '',
                    'categories'     => [],
                    'description'    => $wc_product->get_short_description(),
                    'post_date'      => $wc_product->get_date_created() ? $wc_product->get_date_created()->format('Y-m-d H:i:s') : '',
                    '_score'         => (int)(500 * $boost_map[$pid]),
                    '_boosted'       => true,
                ];
                $products[] = $injected;
            }
        }

        return $products;
    }

    /**
     * Fuzzy search with SOUNDEX, pattern matching, consonant matching.
     */
    private function fuzzy_search($query, $words, $args, $options) {
        global $wpdb;

        $max_results = intval($options['max_results'] ?? 200);
        $conditions = [];
        $prepare_values = [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 3) {
                continue;
            }
            // SOUNDEX match
            $conditions[] = "SOUNDEX(p.post_title) = SOUNDEX(%s)";
            $prepare_values[] = $word;

            // Fuzzy pattern: add wildcards between characters
            $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
            if (count($chars) > 2) {
                $fuzzy_pattern = '%' . implode('%', array_map([$wpdb, 'esc_like'], $chars)) . '%';
                $conditions[] = "p.post_title LIKE %s";
                $prepare_values[] = $fuzzy_pattern;
            }

            // Consonants only (remove vowels for typo tolerance)
            $consonants = preg_replace('/[aeiou]/iu', '', $word);
            if (mb_strlen($consonants) >= 2) {
                $con_chars = preg_split('//u', $consonants, -1, PREG_SPLIT_NO_EMPTY);
                $consonant_pattern = '%' . implode('%', array_map([$wpdb, 'esc_like'], $con_chars)) . '%';
                $conditions[] = "p.post_title LIKE %s";
                $prepare_values[] = $consonant_pattern;
            }

            // Truncation: without first or last letter
            if (mb_strlen($word) > 3) {
                $without_first = mb_substr($word, 1);
                $without_last = mb_substr($word, 0, -1);
                $conditions[] = "p.post_title LIKE %s";
                $prepare_values[] = '%' . $wpdb->esc_like($without_first) . '%';
                $conditions[] = "p.post_title LIKE %s";
                $prepare_values[] = '%' . $wpdb->esc_like($without_last) . '%';
            }
        }

        if (empty($conditions)) {
            return ['products' => []];
        }

        $where = implode(' OR ', $conditions);
        $prepare_values[] = $max_results;

        $sql = "SELECT DISTINCT p.ID,
                    p.post_title,
                    p.post_content,
                    p.post_excerpt,
                    p.post_date,
                    pm_sku.meta_value AS sku,
                    pm_price.meta_value AS price,
                    pm_regular.meta_value AS regular_price,
                    pm_sale.meta_value AS sale_price,
                    pm_stock.meta_value AS stock_status,
                    pm_total_sales.meta_value AS total_sales,
                    '' AS brand_name
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
                LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
                LEFT JOIN {$wpdb->postmeta} pm_regular ON p.ID = pm_regular.post_id AND pm_regular.meta_key = '_regular_price'
                LEFT JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id AND pm_sale.meta_key = '_sale_price'
                LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
                LEFT JOIN {$wpdb->postmeta} pm_total_sales ON p.ID = pm_total_sales.post_id AND pm_total_sales.meta_key = 'total_sales'
                WHERE p.post_type IN ('product', 'product_variation')
                    AND p.post_status = 'publish'
                    AND ({$where})
                ORDER BY p.post_title ASC
                LIMIT %d";

        // Single prepare() call with all values
        $results = $wpdb->get_results($wpdb->prepare($sql, ...$prepare_values), ARRAY_A);
        $products = [];
        if ($results) {
            foreach ($results as $row) {
                $products[] = $this->format_product($row);
            }
        }

        return ['products' => $products];
    }

    /**
     * Get search suggestions/autocomplete.
     */
    public function get_suggestions($query) {
        global $wpdb;

        $query = $this->normalize_query($query);
        $suggestions = [];

        $like_contain = '%' . $wpdb->esc_like($query) . '%';

        // Product names matching
        $product_names = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_title FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'
               AND post_title LIKE %s
             ORDER BY post_title ASC
             LIMIT 5",
            $like_contain
        ));

        foreach ($product_names as $name) {
            $suggestions[] = [
                'query'   => $name,
                'count'   => 0,
                'results' => 0,
                'type'    => 'product',
            ];
        }

        // Brand names matching
        $brands = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT t.name FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy IN ('pa_brand', 'product_brand', 'pa_marca')
               AND t.name LIKE %s
             ORDER BY t.name ASC
             LIMIT 3",
            $like_contain
        ));

        foreach ($brands as $brand) {
            $suggestions[] = [
                'query'   => $brand,
                'count'   => 0,
                'results' => 0,
                'type'    => 'brand',
            ];
        }

        return $suggestions;
    }

    /**
     * Get "Did you mean" suggestions.
     */
    private function get_did_you_mean($query, $words) {
        global $wpdb;

        $suggestions = [];

        // Similar product names
        $like = '%' . $wpdb->esc_like(mb_substr($query, 0, 3)) . '%';
        $products = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_title FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'
               AND post_title LIKE %s
             LIMIT 20",
            $like
        ));

        foreach ($products as $name) {
            $name_lower = mb_strtolower($name);
            $distance = levenshtein(mb_strtolower($query), $name_lower);
            if ($distance <= 3 && $distance > 0) {
                $suggestions[] = $name;
            }
        }

        // Similar brands
        $brands = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT t.name FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy IN ('pa_brand', 'product_brand', 'pa_marca')
               AND t.name LIKE %s
             LIMIT 10",
            $like
        ));

        foreach ($brands as $brand) {
            $distance = levenshtein(mb_strtolower($query), mb_strtolower($brand));
            if ($distance <= 3 && $distance > 0) {
                $suggestions[] = $brand;
            }
        }

        return array_unique(array_slice($suggestions, 0, 5));
    }

    /**
     * Get facets (filters data) from scored products.
     */
    private function get_facets($products) {
        $brands = [];
        $categories = [];
        $min_price = PHP_FLOAT_MAX;
        $max_price = 0;

        foreach ($products as $product) {
            // Brands
            if (!empty($product['brand'])) {
                $brand_key = mb_strtolower($product['brand']);
                if (!isset($brands[$brand_key])) {
                    $brands[$brand_key] = ['name' => $product['brand'], 'count' => 0];
                }
                $brands[$brand_key]['count']++;
            }

            // Categories
            foreach ($product['categories'] as $cat) {
                if (!isset($categories[$cat['id']])) {
                    $categories[$cat['id']] = [
                        'id'    => $cat['id'],
                        'name'  => $cat['name'],
                        'slug'  => $cat['slug'],
                        'count' => 0,
                    ];
                }
                $categories[$cat['id']]['count']++;
            }

            // Price range
            $price = floatval($product['price']);
            if ($price > 0) {
                $min_price = min($min_price, $price);
                $max_price = max($max_price, $price);
            }
        }

        // Sort brands by count
        usort($brands, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        // Sort categories by count
        usort($categories, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return [
            'brands'     => array_values($brands),
            'categories' => array_values($categories),
            'price_min'  => $min_price === PHP_FLOAT_MAX ? 0 : $min_price,
            'price_max'  => $max_price,
        ];
    }

    /**
     * Get active banners for the query.
     */
    public function get_banners($query) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcss_banners';
        $now = current_time('mysql');

        $banners = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE active = 1
               AND (start_date IS NULL OR start_date <= %s)
               AND (end_date IS NULL OR end_date >= %s)
             ORDER BY sort_order ASC",
            $now, $now
        ), ARRAY_A);

        $result = ['top' => [], 'middle' => [], 'bottom' => []];

        foreach ($banners as $banner) {
            $keywords = array_filter(array_map('trim', explode(',', mb_strtolower($banner['keywords']))));

            // Empty keywords = show always, otherwise check match
            $show = empty($keywords);
            if (!$show) {
                foreach ($keywords as $kw) {
                    if (mb_strpos($query, $kw) !== false) {
                        $show = true;
                        break;
                    }
                }
            }

            if ($show) {
                $pos = $banner['position'] ?: 'top';
                $result[$pos][] = [
                    'id'        => intval($banner['id']),
                    'title'     => $banner['title'],
                    'image_url' => $banner['image_url'],
                    'link_url'  => $banner['link_url'],
                ];
            }
        }

        return $result;
    }

    /**
     * Get available filters.
     */
    public function get_available_filters() {
        global $wpdb;

        // All brands
        $brands = $wpdb->get_results(
            "SELECT t.term_id as id, t.name, COUNT(tr.object_id) as count
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID AND p.post_status = 'publish' AND p.post_type = 'product'
             WHERE tt.taxonomy IN ('pa_brand', 'product_brand', 'pa_marca')
             GROUP BY t.term_id
             ORDER BY t.name ASC",
            ARRAY_A
        );

        // All product categories
        $categories = $wpdb->get_results(
            "SELECT t.term_id as id, t.name, t.slug, tt.parent, COUNT(tr.object_id) as count
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID AND p.post_status = 'publish' AND p.post_type = 'product'
             WHERE tt.taxonomy = 'product_cat'
             GROUP BY t.term_id
             ORDER BY t.name ASC",
            ARRAY_A
        );

        // Price range
        $price_range = $wpdb->get_row(
            "SELECT MIN(CAST(pm.meta_value AS DECIMAL(10,2))) as min_price,
                    MAX(CAST(pm.meta_value AS DECIMAL(10,2))) as max_price
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_price'
               AND p.post_type = 'product'
               AND p.post_status = 'publish'
               AND pm.meta_value > 0",
            ARRAY_A
        );

        return [
            'brands'     => $brands ?: [],
            'categories' => $categories ?: [],
            'price_min'  => floatval($price_range['min_price'] ?? 0),
            'price_max'  => floatval($price_range['max_price'] ?? 0),
        ];
    }

    /**
     * Return empty result structure.
     */
    private function empty_result() {
        return [
            'products'    => [],
            'total'       => 0,
            'total_count' => 0,
            'offset'      => 0,
            'limit'       => 24,
            'has_more'    => false,
            'facets'      => ['brands' => [], 'categories' => [], 'price_min' => 0, 'price_max' => 0],
            'banners'     => ['top' => [], 'middle' => [], 'bottom' => []],
            'did_you_mean'=> [],
        ];
    }
}
