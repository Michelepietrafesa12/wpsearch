<?php
/**
 * Search bar template - renders the search trigger and overlay container.
 * The overlay content is populated dynamically by JavaScript.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- WC SmartSearch Trigger -->
<div class="wcss-search-trigger" id="wcss-search-trigger" role="button" tabindex="0" aria-label="<?php esc_attr_e('Cerca prodotti', 'wc-smartsearch'); ?>">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8"></circle>
        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
    </svg>
    <span class="wcss-search-trigger-text"><?php esc_html_e('Cerca prodotti...', 'wc-smartsearch'); ?></span>
</div>

<!-- WC SmartSearch Overlay (lazy loaded by JS) -->
<div class="wcss-overlay" id="wcss-overlay" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Ricerca prodotti', 'wc-smartsearch'); ?>">
    <!-- Search header -->
    <div class="wcss-search-header">
        <div class="wcss-search-input-wrapper">
            <svg class="wcss-search-icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text"
                   class="wcss-search-input"
                   id="wcss-search-input"
                   placeholder="<?php esc_attr_e('Cerca prodotti, marche, categorie...', 'wc-smartsearch'); ?>"
                   autocomplete="off"
                   autocorrect="off"
                   autocapitalize="off"
                   spellcheck="false"
                   aria-label="<?php esc_attr_e('Cerca prodotti', 'wc-smartsearch'); ?>">
            <button class="wcss-clear-input" id="wcss-clear-input" aria-label="<?php esc_attr_e('Cancella ricerca', 'wc-smartsearch'); ?>" style="display:none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <button class="wcss-close-btn" id="wcss-close-btn" aria-label="<?php esc_attr_e('Chiudi ricerca', 'wc-smartsearch'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    <!-- Suggestions dropdown -->
    <div class="wcss-suggestions" id="wcss-suggestions" role="listbox" style="display:none;"></div>

    <!-- Main content area -->
    <div class="wcss-main-content">
        <!-- Mobile filters toggle -->
        <button class="wcss-filters-toggle" id="wcss-filters-toggle" style="display:none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="4" y1="21" x2="4" y2="14"></line>
                <line x1="4" y1="10" x2="4" y2="3"></line>
                <line x1="12" y1="21" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12" y2="3"></line>
                <line x1="20" y1="21" x2="20" y2="16"></line>
                <line x1="20" y1="12" x2="20" y2="3"></line>
            </svg>
            <span id="wcss-filters-toggle-text"><?php esc_html_e('Mostra filtri', 'wc-smartsearch'); ?></span>
        </button>

        <div class="wcss-results-wrapper">
            <!-- Filters sidebar -->
            <aside class="wcss-filters-sidebar" id="wcss-filters-sidebar" style="display:none;">
                <div class="wcss-filters-header">
                    <h3><?php esc_html_e('Filtri', 'wc-smartsearch'); ?></h3>
                    <button class="wcss-filters-close" id="wcss-filters-close" aria-label="<?php esc_attr_e('Chiudi filtri', 'wc-smartsearch'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>

                <!-- Price filter -->
                <div class="wcss-filter-group" id="wcss-filter-price">
                    <h4 class="wcss-filter-title"><?php esc_html_e('Prezzo', 'wc-smartsearch'); ?></h4>
                    <div class="wcss-price-range">
                        <div class="wcss-price-inputs">
                            <input type="number" id="wcss-price-min" placeholder="Min" min="0" step="0.01">
                            <span>—</span>
                            <input type="number" id="wcss-price-max" placeholder="Max" min="0" step="0.01">
                        </div>
                        <input type="range" id="wcss-price-slider-min" min="0" max="1000" value="0" step="1">
                        <input type="range" id="wcss-price-slider-max" min="0" max="1000" value="1000" step="1">
                    </div>
                </div>

                <!-- Brand filter -->
                <div class="wcss-filter-group" id="wcss-filter-brands">
                    <h4 class="wcss-filter-title"><?php esc_html_e('Marca', 'wc-smartsearch'); ?></h4>
                    <div class="wcss-filter-list" id="wcss-brand-list"></div>
                </div>

                <!-- Category filter -->
                <div class="wcss-filter-group" id="wcss-filter-categories">
                    <h4 class="wcss-filter-title"><?php esc_html_e('Categorie', 'wc-smartsearch'); ?></h4>
                    <div class="wcss-filter-list" id="wcss-category-list"></div>
                </div>

                <div class="wcss-filters-actions">
                    <button class="wcss-btn wcss-btn-primary" id="wcss-apply-filters"><?php esc_html_e('Applica filtri', 'wc-smartsearch'); ?></button>
                    <button class="wcss-btn wcss-btn-secondary" id="wcss-reset-filters"><?php esc_html_e('Reset', 'wc-smartsearch'); ?></button>
                </div>
            </aside>

            <!-- Mobile filters backdrop -->
            <div class="wcss-mobile-filters-backdrop" id="wcss-mobile-filters-backdrop"></div>

            <!-- Results container -->
            <div class="wcss-results-container" id="wcss-results-container">
                <!-- Banners top -->
                <div class="wcss-banners-top" id="wcss-banners-top"></div>

                <!-- Results count and did you mean -->
                <div class="wcss-results-info" id="wcss-results-info" style="display:none;">
                    <span class="wcss-results-count" id="wcss-results-count"></span>
                    <div class="wcss-did-you-mean" id="wcss-did-you-mean" style="display:none;"></div>
                </div>

                <!-- Product grid -->
                <div class="wcss-results-grid" id="wcss-results-grid"></div>

                <!-- Banners bottom -->
                <div class="wcss-banners-bottom" id="wcss-banners-bottom"></div>

                <!-- Loading spinner -->
                <div class="wcss-loading" id="wcss-loading" style="display:none;">
                    <div class="wcss-spinner"></div>
                    <span><?php esc_html_e('Caricamento...', 'wc-smartsearch'); ?></span>
                </div>

                <!-- No results -->
                <div class="wcss-no-results" id="wcss-no-results" style="display:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        <line x1="8" y1="8" x2="14" y2="14"></line>
                        <line x1="14" y1="8" x2="8" y2="14"></line>
                    </svg>
                    <p><?php esc_html_e('Nessun risultato trovato', 'wc-smartsearch'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
