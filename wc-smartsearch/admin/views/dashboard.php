<?php
/**
 * Admin Dashboard View
 *
 * @var array  $options     Plugin options.
 * @var string $active_tab  Active tab slug.
 * @var array  $boosts      Boosted products.
 * @var array  $banners     Banners.
 * @var array  $synonyms    Synonyms.
 * @var array  $corr_stats  Correlation stats.
 */

if (!defined('ABSPATH')) {
    exit;
}

$tabs = [
    'settings'     => __('Impostazioni', 'wc-smartsearch'),
    'boosting'     => __('Boosting', 'wc-smartsearch'),
    'banners'      => __('Banner', 'wc-smartsearch'),
    'synonyms'     => __('Sinonimi', 'wc-smartsearch'),
    'correlations' => __('Correlazioni', 'wc-smartsearch'),
];
?>
<div class="wrap wcss-admin-wrap">
    <h1><span class="dashicons dashicons-search"></span> <?php esc_html_e('WC SmartSearch', 'wc-smartsearch'); ?> <small>v<?php echo esc_html(WCSS_VERSION); ?></small></h1>

    <nav class="nav-tab-wrapper wcss-tabs">
        <?php foreach ($tabs as $slug => $label) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wcss-dashboard&tab=' . $slug)); ?>"
               class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="wcss-tab-content">

        <?php if ($active_tab === 'settings') : ?>
        <!-- SETTINGS TAB -->
        <form method="post" action="options.php">
            <?php settings_fields('wcss_options_group'); ?>
            <table class="form-table wcss-settings-table">
                <tr>
                    <th><?php esc_html_e('Abilita modulo', 'wc-smartsearch'); ?></th>
                    <td><label><input type="checkbox" name="wcss_options[enabled]" value="1" <?php checked($options['enabled']); ?>> <?php esc_html_e('Attiva SmartSearch', 'wc-smartsearch'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Caratteri minimi', 'wc-smartsearch'); ?></th>
                    <td><input type="number" name="wcss_options[min_chars]" value="<?php echo esc_attr($options['min_chars']); ?>" min="1" max="10" class="small-text"></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Risultati massimi', 'wc-smartsearch'); ?></th>
                    <td><input type="number" name="wcss_options[max_results]" value="<?php echo esc_attr($options['max_results']); ?>" min="10" max="500" class="small-text"></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Fuzzy Search', 'wc-smartsearch'); ?></th>
                    <td><label><input type="checkbox" name="wcss_options[fuzzy_enabled]" value="1" <?php checked($options['fuzzy_enabled']); ?>> <?php esc_html_e('Abilita ricerca tollerante', 'wc-smartsearch'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Sinonimi', 'wc-smartsearch'); ?></th>
                    <td><label><input type="checkbox" name="wcss_options[synonyms_enabled]" value="1" <?php checked($options['synonyms_enabled']); ?>> <?php esc_html_e('Abilita espansione sinonimi', 'wc-smartsearch'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Filtri dinamici', 'wc-smartsearch'); ?></th>
                    <td><label><input type="checkbox" name="wcss_options[filters_enabled]" value="1" <?php checked($options['filters_enabled']); ?>> <?php esc_html_e('Mostra filtri nei risultati', 'wc-smartsearch'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Cache', 'wc-smartsearch'); ?></th>
                    <td>
                        <label><input type="checkbox" name="wcss_options[cache_enabled]" value="1" <?php checked($options['cache_enabled']); ?>> <?php esc_html_e('Abilita cache risultati', 'wc-smartsearch'); ?></label>
                        <br>
                        <label><?php esc_html_e('TTL (secondi):', 'wc-smartsearch'); ?> <input type="number" name="wcss_options[cache_ttl]" value="<?php echo esc_attr($options['cache_ttl']); ?>" min="60" max="3600" class="small-text"></label>
                        <br><br>
                        <button type="button" class="button" id="wcss-flush-cache"><?php esc_html_e('Svuota Cache', 'wc-smartsearch'); ?></button>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Raccomandazioni', 'wc-smartsearch'); ?></th>
                    <td>
                        <label><input type="checkbox" name="wcss_options[recommendations_enabled]" value="1" <?php checked($options['recommendations_enabled']); ?>> <?php esc_html_e('Abilita prodotti consigliati', 'wc-smartsearch'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Periodo analisi ordini', 'wc-smartsearch'); ?></th>
                    <td><input type="number" name="wcss_options[recommendations_days]" value="<?php echo esc_attr($options['recommendations_days']); ?>" min="30" max="365" class="small-text"> <?php esc_html_e('giorni', 'wc-smartsearch'); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Acquisti minimi correlazione', 'wc-smartsearch'); ?></th>
                    <td><input type="number" name="wcss_options[recommendations_min_orders]" value="<?php echo esc_attr($options['recommendations_min_orders']); ?>" min="1" max="100" class="small-text"></td>
                </tr>
            </table>
            <?php submit_button(__('Salva Impostazioni', 'wc-smartsearch')); ?>
        </form>

        <?php elseif ($active_tab === 'boosting') : ?>
        <!-- BOOSTING TAB -->
        <div class="wcss-section">
            <h2><?php esc_html_e('Product Boosting', 'wc-smartsearch'); ?></h2>
            <p><?php esc_html_e('Aumenta la visibilita di prodotti specifici nei risultati di ricerca.', 'wc-smartsearch'); ?></p>

            <div class="wcss-form-card" id="wcss-boost-form">
                <h3><?php esc_html_e('Aggiungi Boost', 'wc-smartsearch'); ?></h3>
                <input type="hidden" id="boost-id" value="0">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Prodotto', 'wc-smartsearch'); ?></th>
                        <td><input type="text" id="boost-product-search" class="regular-text" placeholder="<?php esc_attr_e('Cerca prodotto...', 'wc-smartsearch'); ?>">
                            <input type="hidden" id="boost-product-id" value="0">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Moltiplicatore', 'wc-smartsearch'); ?></th>
                        <td>
                            <select id="boost-multiplier">
                                <option value="1.5">1.5x</option>
                                <option value="2" selected>2x</option>
                                <option value="3">3x</option>
                                <option value="5">5x</option>
                                <option value="10">10x</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Keywords target', 'wc-smartsearch'); ?></th>
                        <td><input type="text" id="boost-keywords" class="regular-text" placeholder="<?php esc_attr_e('Vuoto = boost sempre, oppure keyword1, keyword2', 'wc-smartsearch'); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Periodo', 'wc-smartsearch'); ?></th>
                        <td>
                            <input type="datetime-local" id="boost-start-date" placeholder="<?php esc_attr_e('Da', 'wc-smartsearch'); ?>">
                            <input type="datetime-local" id="boost-end-date" placeholder="<?php esc_attr_e('A', 'wc-smartsearch'); ?>">
                        </td>
                    </tr>
                </table>
                <button type="button" class="button button-primary" id="wcss-save-boost"><?php esc_html_e('Salva Boost', 'wc-smartsearch'); ?></button>
            </div>

            <table class="wp-list-table widefat fixed striped" id="wcss-boosts-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Prodotto', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Moltiplicatore', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Keywords', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Periodo', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Stato', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Azioni', 'wc-smartsearch'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($boosts)) : ?>
                        <tr><td colspan="6"><?php esc_html_e('Nessun boost configurato.', 'wc-smartsearch'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($boosts as $boost) : ?>
                        <tr data-id="<?php echo esc_attr($boost['id']); ?>">
                            <td><?php echo esc_html($boost['product_name'] ?? '#' . $boost['product_id']); ?></td>
                            <td><?php echo esc_html($boost['multiplier']); ?>x</td>
                            <td><?php echo esc_html($boost['keywords'] ?: '—'); ?></td>
                            <td>
                                <?php if ($boost['start_date']) : ?>
                                    <?php echo esc_html(wp_date('d/m/Y H:i', strtotime($boost['start_date']))); ?>
                                <?php endif; ?>
                                <?php if ($boost['end_date']) : ?>
                                    → <?php echo esc_html(wp_date('d/m/Y H:i', strtotime($boost['end_date']))); ?>
                                <?php endif; ?>
                                <?php if (!$boost['start_date'] && !$boost['end_date']) : ?>—<?php endif; ?>
                            </td>
                            <td>
                                <button class="button wcss-toggle-boost <?php echo $boost['active'] ? 'active' : ''; ?>" data-id="<?php echo esc_attr($boost['id']); ?>">
                                    <?php echo $boost['active'] ? esc_html__('Attivo', 'wc-smartsearch') : esc_html__('Disattivato', 'wc-smartsearch'); ?>
                                </button>
                            </td>
                            <td>
                                <button class="button wcss-delete-boost" data-id="<?php echo esc_attr($boost['id']); ?>"><?php esc_html_e('Elimina', 'wc-smartsearch'); ?></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($active_tab === 'banners') : ?>
        <!-- BANNERS TAB -->
        <div class="wcss-section">
            <h2><?php esc_html_e('Banner Promozionali', 'wc-smartsearch'); ?></h2>
            <p><?php esc_html_e('Mostra banner promozionali nei risultati di ricerca.', 'wc-smartsearch'); ?></p>

            <div class="wcss-form-card" id="wcss-banner-form">
                <h3><?php esc_html_e('Aggiungi Banner', 'wc-smartsearch'); ?></h3>
                <input type="hidden" id="banner-id" value="0">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Titolo', 'wc-smartsearch'); ?></th>
                        <td><input type="text" id="banner-title" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Immagine', 'wc-smartsearch'); ?></th>
                        <td>
                            <input type="url" id="banner-image-url" class="regular-text" placeholder="https://...">
                            <button type="button" class="button" id="wcss-upload-banner"><?php esc_html_e('Carica', 'wc-smartsearch'); ?></button>
                            <div id="banner-preview"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Link', 'wc-smartsearch'); ?></th>
                        <td><input type="url" id="banner-link-url" class="regular-text" placeholder="https://..."></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Posizione', 'wc-smartsearch'); ?></th>
                        <td>
                            <select id="banner-position">
                                <option value="top">Top</option>
                                <option value="middle">Middle (dopo 4 prodotti)</option>
                                <option value="bottom">Bottom</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Keywords target', 'wc-smartsearch'); ?></th>
                        <td><input type="text" id="banner-keywords" class="regular-text" placeholder="<?php esc_attr_e('Vuoto = mostra sempre', 'wc-smartsearch'); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Periodo', 'wc-smartsearch'); ?></th>
                        <td>
                            <input type="datetime-local" id="banner-start-date">
                            <input type="datetime-local" id="banner-end-date">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Ordine', 'wc-smartsearch'); ?></th>
                        <td><input type="number" id="banner-sort-order" value="0" min="0" class="small-text"></td>
                    </tr>
                </table>
                <button type="button" class="button button-primary" id="wcss-save-banner"><?php esc_html_e('Salva Banner', 'wc-smartsearch'); ?></button>
            </div>

            <table class="wp-list-table widefat fixed striped" id="wcss-banners-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Immagine', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Titolo', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Posizione', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Keywords', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Stato', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Azioni', 'wc-smartsearch'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($banners)) : ?>
                        <tr><td colspan="6"><?php esc_html_e('Nessun banner configurato.', 'wc-smartsearch'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($banners as $banner) : ?>
                        <tr data-id="<?php echo esc_attr($banner['id']); ?>">
                            <td><img src="<?php echo esc_url($banner['image_url']); ?>" style="max-width:80px;max-height:40px;"></td>
                            <td><?php echo esc_html($banner['title']); ?></td>
                            <td><?php echo esc_html(ucfirst($banner['position'])); ?></td>
                            <td><?php echo esc_html($banner['keywords'] ?: '—'); ?></td>
                            <td>
                                <button class="button wcss-toggle-banner <?php echo $banner['active'] ? 'active' : ''; ?>" data-id="<?php echo esc_attr($banner['id']); ?>">
                                    <?php echo $banner['active'] ? esc_html__('Attivo', 'wc-smartsearch') : esc_html__('Disattivato', 'wc-smartsearch'); ?>
                                </button>
                            </td>
                            <td>
                                <button class="button wcss-delete-banner" data-id="<?php echo esc_attr($banner['id']); ?>"><?php esc_html_e('Elimina', 'wc-smartsearch'); ?></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($active_tab === 'synonyms') : ?>
        <!-- SYNONYMS TAB -->
        <div class="wcss-section">
            <h2><?php esc_html_e('Sinonimi', 'wc-smartsearch'); ?></h2>
            <p><?php esc_html_e('Configura sinonimi per espandere le ricerche. Bidirezionali: "whey" ↔ "proteine" significa che cercando uno trova anche l\'altro.', 'wc-smartsearch'); ?></p>

            <div class="wcss-form-card" id="wcss-synonym-form">
                <h3><?php esc_html_e('Aggiungi Sinonimo', 'wc-smartsearch'); ?></h3>
                <input type="hidden" id="synonym-id" value="0">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Parola', 'wc-smartsearch'); ?></th>
                        <td><input type="text" id="synonym-word" class="regular-text" placeholder="whey"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Sinonimo', 'wc-smartsearch'); ?></th>
                        <td><input type="text" id="synonym-synonym" class="regular-text" placeholder="proteine"></td>
                    </tr>
                </table>
                <button type="button" class="button button-primary" id="wcss-save-synonym"><?php esc_html_e('Salva Sinonimo', 'wc-smartsearch'); ?></button>
            </div>

            <table class="wp-list-table widefat fixed striped" id="wcss-synonyms-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Parola', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Sinonimo', 'wc-smartsearch'); ?></th>
                        <th><?php esc_html_e('Azioni', 'wc-smartsearch'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($synonyms)) : ?>
                        <tr><td colspan="3"><?php esc_html_e('Nessun sinonimo configurato.', 'wc-smartsearch'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($synonyms as $syn) : ?>
                        <tr data-id="<?php echo esc_attr($syn['id']); ?>">
                            <td><?php echo esc_html($syn['word']); ?></td>
                            <td><?php echo esc_html($syn['synonym']); ?></td>
                            <td>
                                <button class="button wcss-delete-synonym" data-id="<?php echo esc_attr($syn['id']); ?>"><?php esc_html_e('Elimina', 'wc-smartsearch'); ?></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($active_tab === 'correlations') : ?>
        <!-- CORRELATIONS TAB -->
        <div class="wcss-section">
            <h2><?php esc_html_e('Prodotti Consigliati (Correlazioni)', 'wc-smartsearch'); ?></h2>
            <p><?php esc_html_e('Sistema di raccomandazioni basato sugli acquisti reali dei clienti.', 'wc-smartsearch'); ?></p>

            <div class="wcss-stats-grid">
                <div class="wcss-stat-card">
                    <span class="wcss-stat-number"><?php echo esc_html(number_format_i18n($corr_stats['total_correlations'])); ?></span>
                    <span class="wcss-stat-label"><?php esc_html_e('Correlazioni totali', 'wc-smartsearch'); ?></span>
                </div>
                <div class="wcss-stat-card">
                    <span class="wcss-stat-number"><?php echo esc_html(number_format_i18n($corr_stats['total_products'])); ?></span>
                    <span class="wcss-stat-label"><?php esc_html_e('Prodotti con correlazioni', 'wc-smartsearch'); ?></span>
                </div>
                <div class="wcss-stat-card">
                    <span class="wcss-stat-number"><?php echo esc_html($corr_stats['avg_score']); ?></span>
                    <span class="wcss-stat-label"><?php esc_html_e('Score medio', 'wc-smartsearch'); ?></span>
                </div>
                <div class="wcss-stat-card">
                    <span class="wcss-stat-number wcss-stat-small"><?php echo esc_html($corr_stats['last_update']); ?></span>
                    <span class="wcss-stat-label"><?php esc_html_e('Ultimo aggiornamento', 'wc-smartsearch'); ?></span>
                </div>
            </div>

            <div class="wcss-actions-bar">
                <button type="button" class="button button-primary button-hero" id="wcss-calculate-correlations">
                    <span class="dashicons dashicons-update"></span> <?php esc_html_e('Calcola Correlazioni Ora', 'wc-smartsearch'); ?>
                </button>
                <p class="description"><?php esc_html_e('Il calcolo viene eseguito automaticamente ogni notte alle 3:00. Usa questo pulsante per un aggiornamento manuale.', 'wc-smartsearch'); ?></p>
            </div>

            <div id="wcss-correlation-result" style="display:none;" class="wcss-notice"></div>
        </div>
        <?php endif; ?>

    </div>
</div>
