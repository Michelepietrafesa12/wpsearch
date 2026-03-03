<?php
/**
 * Search overlay template - renders the fullscreen overlay and mobile filters.
 * The trigger button is rendered separately (by shortcode or footer function).
 * CSS class names must match assets/css/smartsearch.css exactly.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- WC SmartSearch Overlay -->
<div class="wcss-overlay" id="wcss-overlay" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Ricerca prodotti', 'wc-smartsearch'); ?>">
    <div class="wcss-overlay-inner">
        <!-- Search header -->
        <div class="wcss-search-header">
            <div class="wcss-search-form">
                <svg class="wcss-search-icon-inside" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
                       aria-label="<?php esc_attr_e('Cerca prodotti', 'wc-smartsearch'); ?>"
                       aria-autocomplete="list"
                       aria-controls="wcss-suggestions">
                <button class="wcss-search-clear" id="wcss-search-clear" type="button" aria-label="<?php esc_attr_e('Cancella ricerca', 'wc-smartsearch'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <button class="wcss-close-btn" id="wcss-close-btn" type="button" aria-label="<?php esc_attr_e('Chiudi ricerca', 'wc-smartsearch'); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>

            <!-- Suggestions dropdown (inside header for absolute positioning) -->
            <div class="wcss-suggestions" id="wcss-suggestions" role="listbox"></div>
        </div>

        <!-- Scrollable results wrapper -->
        <div class="wcss-results-wrapper" id="wcss-results-wrapper">
            <div class="wcss-results-container">
                <!-- Filters sidebar (desktop, hidden on tablet/mobile via CSS) -->
                <aside class="wcss-filters-sidebar" id="wcss-filters-sidebar"></aside>

                <!-- Main results area -->
                <div class="wcss-results-main" id="wcss-results-main">
                    <!-- Filters toggle (tablet/mobile only, hidden on desktop via CSS) -->
                    <button class="wcss-filters-toggle" id="wcss-filters-toggle" type="button">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line>
                            <line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line>
                            <line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line>
                        </svg>
                        <span><?php esc_html_e('Mostra filtri', 'wc-smartsearch'); ?></span>
                    </button>

                    <!-- Results header -->
                    <div class="wcss-results-header" id="wcss-results-header" style="display:none;">
                        <span class="wcss-results-count" id="wcss-results-count"></span>
                    </div>

                    <!-- Did you mean -->
                    <div class="wcss-did-you-mean" id="wcss-did-you-mean" style="display:none;"></div>

                    <!-- Banners top -->
                    <div class="wcss-banner-top" id="wcss-banner-top"></div>

                    <!-- Product grid -->
                    <div class="wcss-results-grid" id="wcss-results-grid"></div>

                    <!-- Banners bottom -->
                    <div class="wcss-banner-bottom" id="wcss-banner-bottom"></div>

                    <!-- Loading -->
                    <div class="wcss-loading" id="wcss-loading" style="display:none;">
                        <div class="wcss-loading-spinner"></div>
                        <span class="wcss-loading-text"><?php esc_html_e('Caricamento...', 'wc-smartsearch'); ?></span>
                    </div>

                    <!-- No results -->
                    <div class="wcss-no-results" id="wcss-no-results" style="display:none;">
                        <svg class="wcss-no-results-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            <line x1="8" y1="8" x2="14" y2="14"></line>
                            <line x1="14" y1="8" x2="8" y2="14"></line>
                        </svg>
                        <h3 class="wcss-no-results-title"><?php esc_html_e('Nessun risultato trovato', 'wc-smartsearch'); ?></h3>
                        <p class="wcss-no-results-message"><?php esc_html_e('Prova con termini diversi o controlla l\'ortografia.', 'wc-smartsearch'); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Filters Panel -->
<div class="wcss-mobile-filters-backdrop" id="wcss-mobile-filters-backdrop"></div>
<div class="wcss-mobile-filters" id="wcss-mobile-filters">
    <div class="wcss-mobile-filters-header">
        <h3><?php esc_html_e('Filtri', 'wc-smartsearch'); ?></h3>
        <button class="wcss-mobile-filters-close" id="wcss-mobile-filters-close" type="button" aria-label="<?php esc_attr_e('Chiudi filtri', 'wc-smartsearch'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>
    <div class="wcss-mobile-filters-body" id="wcss-mobile-filters-body"></div>
    <div class="wcss-mobile-filters-footer">
        <button class="wcss-filter-apply-btn" id="wcss-mobile-apply-filters" type="button"><?php esc_html_e('Applica filtri', 'wc-smartsearch'); ?></button>
        <button class="wcss-filter-reset-btn" id="wcss-mobile-reset-filters" type="button"><?php esc_html_e('Reset', 'wc-smartsearch'); ?></button>
    </div>
</div>
