# WC SmartSearch - Plugin di Ricerca per WooCommerce

## Panoramica
Plugin WordPress/WooCommerce per ricerca intelligente con scoring avanzato a 3 livelli, fuzzy search, filtri dinamici, product boosting, banner promozionali, analytics e sistema di raccomandazioni prodotti.

## Struttura del Progetto

```
wc-smartsearch/
├── wc-smartsearch.php              # File principale plugin (entry point, hooks, enqueue)
├── uninstall.php                   # Pulizia su disinstallazione
├── readme.txt                      # WordPress readme
├── classes/
│   ├── class-wcss-engine.php       # Motore di ricerca (scoring, fuzzy, variazioni IT)
│   ├── class-wcss-cache.php        # Cache con WordPress transients
│   ├── class-wcss-analytics.php    # Tracking ricerche, eventi, webhook
│   └── class-wcss-correlations.php # Raccomandazioni prodotti da ordini
├── includes/
│   ├── class-wcss-installer.php    # Attivazione/disattivazione, creazione tabelle DB
│   └── class-wcss-cron.php         # Handler cron WordPress
├── admin/
│   ├── class-wcss-admin.php        # Admin panel, AJAX handlers admin
│   └── views/dashboard.php         # Template dashboard admin (tabs)
├── ajax/
│   └── class-wcss-ajax.php         # Endpoint AJAX frontend
├── cron/
│   └── calculate_correlations.php  # Cron standalone per correlazioni
├── templates/
│   └── searchbar.php               # Template overlay ricerca
├── assets/
│   ├── js/
│   │   ├── smartsearch.js          # JS frontend (vanilla, ~56KB)
│   │   └── admin-dashboard.js      # JS admin (jQuery)
│   ├── css/
│   │   ├── smartsearch.css         # CSS frontend (~54KB)
│   │   └── admin-dashboard.css     # CSS admin
│   └── img/banners/                # Upload banner
└── docs/
    └── n8n-workflow.json           # Workflow n8n esempio
```

## Architettura

### Database
Il plugin crea 6 tabelle custom (prefisso `wcss_`):
- `wcss_search_log` - Log ricerche
- `wcss_analytics` - Eventi analytics
- `wcss_boosted_products` - Prodotti con boost
- `wcss_banners` - Banner promozionali
- `wcss_synonyms` - Sinonimi configurabili
- `wcss_correlations` - Correlazioni prodotti

### Opzioni
Tutte le impostazioni in un singolo record `wcss_options` (array serializzato).
La funzione `wcss_get_options()` usa cache statica PHP per zero query aggiuntive.

### AJAX Endpoints
Tutti registrati come `wp_ajax_` / `wp_ajax_nopriv_`:
- `wcss_search` - Ricerca prodotti
- `wcss_suggestions` - Autocomplete
- `wcss_filters` - Filtri disponibili
- `wcss_banners` - Banner per query
- `wcss_analytics` - Tracking eventi
- `wcss_recommendations` - Prodotti consigliati

### Scoring a 3 Livelli
1. **Priorità 0** (1000+ punti): Tutte le parole nel nome prodotto
2. **Priorità 1** (700-900 punti): Query esatta in nome/SKU/brand
3. **Priorità 2** (<200 punti): Match parziale con penalità

## Sviluppo

### Requisiti
- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.2+
- MySQL 5.6+

### Convenzioni Codice
- Classi PHP: prefisso `WCSS_`, file `class-wcss-*.php`
- Funzioni globali: prefisso `wcss_`
- Azioni AJAX: prefisso `wcss_`
- Transients: prefisso `wcss_`
- Tabelle DB: prefisso `wcss_`
- CSS classes: prefisso `wcss-`
- JS: vanilla per frontend, jQuery per admin

### Testing
Non c'è una suite di test automatizzati. Per testare:
1. Attivare il plugin su un sito WooCommerce con prodotti
2. Verificare che l'overlay di ricerca si apra
3. Testare la ricerca con vari termini
4. Verificare filtri, suggerimenti, infinite scroll
5. Controllare il pannello admin (SmartSearch nel menu)

### Sicurezza
- Tutti gli endpoint AJAX verificano nonce (`check_ajax_referer`)
- Admin AJAX verifica capability (`manage_woocommerce`)
- Input sanitizzati con `sanitize_text_field`, `intval`, `esc_url_raw`
- Output escaped con `esc_html`, `esc_attr`, `esc_url`
- Cron HTTP protetto da token
- Hook checkout/pagamento con try/catch Throwable (fail-safe)

### Compatibilità
- HPOS (High-Performance Order Storage): dichiarata e supportata
- Multi-lingua: supporto completo con text domain `wc-smartsearch`
- Checkout: hook fail-safe che non bloccano mai checkout/pagamenti
