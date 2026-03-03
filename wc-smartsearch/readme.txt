=== WC SmartSearch ===
Contributors: smartsearch
Tags: woocommerce, search, ajax, autocomplete, product search
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.2
WC requires at least: 3.0
WC tested up to: 8.0
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Motore di ricerca intelligente per WooCommerce con scoring avanzato, fuzzy search, filtri dinamici, product boosting, banner e prodotti consigliati.

== Description ==

WC SmartSearch è un plugin di ricerca avanzata per WooCommerce che sostituisce la ricerca standard con un motore intelligente dotato di:

* **Ricerca con Scoring a 3 Livelli** - Priorità completa, match esatto, match parziale
* **Fuzzy Search** - Tolleranza errori di digitazione
* **Variazioni Italiane** - Supporto automatico singolare/plurale
* **Normalizzazione Unità di Misura** - 350g = 350 g = 350gr
* **Suggerimenti Autocomplete** - Ricerche popolari, prodotti, marche
* **"Forse Cercavi..."** - Suggerimenti alternativi con distanza Levenshtein
* **Filtri Dinamici** - Prezzo, marca, categorie con UI ottimizzata
* **Product Boosting** - Aumenta visibilità prodotti specifici
* **Banner Promozionali** - Banner contestuali nei risultati
* **Infinite Scroll** - Caricamento 24 prodotti per volta
* **Analytics** - Tracking ricerche, click, conversioni con webhook n8n
* **Prodotti Consigliati** - "Chi ha acquistato..." basato su ordini reali
* **Penalità Esauriti** - Prodotti non disponibili penalizzati -30%
* **Dark Mode** - Supporto tema scuro
* **HPOS Compatible** - Compatibile con High-Performance Order Storage

== Installation ==

1. Carica la cartella `wc-smartsearch` nella directory `/wp-content/plugins/`
2. Attiva il plugin dalla schermata 'Plugin' di WordPress
3. Configura le impostazioni da SmartSearch nel menu admin

== Changelog ==

= 2.2.0 =
* NEW: Slider prodotti consigliati in pagina prodotto
* NEW: Slider prodotti consigliati nel carrello
* NEW: Sistema correlazioni basato su ordini reali
* NEW: Cron job per calcolo automatico correlazioni
* NEW: Penalità -30% per prodotti esauriti
* NEW: Hook fail-safe con try/catch Throwable

= 2.1.0 =
* NEW: Sistema di scoring a 3 livelli di priorità
* NEW: Variazioni singolare/plurale italiano
* NEW: Normalizzazione unità di misura
* NEW: Infinite scroll AJAX
* NEW: Suggerimenti autocomplete

= 2.0.0 =
* NEW: Overlay fullscreen per ricerca
* NEW: Fuzzy search con tolleranza errori
* NEW: Filtri dinamici
* NEW: Product boosting
* NEW: Banner promozionali
* NEW: Analytics con webhook n8n

= 1.0.0 =
* Rilascio iniziale
