/**
 * WC SmartSearch - Frontend JavaScript
 *
 * Fullscreen overlay search with AJAX, infinite scroll, autocomplete,
 * dynamic filters, banners, and product recommendations.
 *
 * Uses existing DOM from searchbar.php template. CSS classes must match
 * assets/css/smartsearch.css exactly.
 *
 * @version 2.3.0
 */
(function () {
    'use strict';

    /* ---------------------------------------------------------------
     * 0. PARAMS & CONSTANTS
     * ------------------------------------------------------------- */
    var P            = window.wcss_params || {};
    var AJAX_URL     = P.ajax_url  || '/wp-admin/admin-ajax.php';
    var NONCE        = P.nonce     || '';
    var MIN_CHARS    = parseInt(P.min_chars, 10) || 2;
    var MAX_RESULTS  = parseInt(P.max_results, 10) || 200;
    var PER_PAGE     = parseInt(P.results_per_page, 10) || 24;
    var DEBOUNCE_MS  = parseInt(P.debounce_delay, 10) || 300;
    var CACHE_TTL    = (parseInt(P.cache_ttl, 10) || 300) * 1000;
    var CURRENCY     = P.currency_symbol || '$';
    var I18N         = P.i18n || {};

    /* ---------------------------------------------------------------
     * 1. UTILITY HELPERS
     * ------------------------------------------------------------- */
    function debounce(fn, delay) {
        var t;
        var f = function () {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
        f.cancel = function () { clearTimeout(t); };
        return f;
    }

    function el(tag, cls, attrs) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (attrs) Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        return n;
    }

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s || ''));
        return d.innerHTML;
    }

    function formatPrice(v) {
        var n = parseFloat(v);
        return isNaN(n) ? '' : CURRENCY + n.toFixed(2);
    }

    function safeUrl(url) {
        if (!url || typeof url !== 'string') return '#';
        var lower = url.trim().toLowerCase();
        if (lower.indexOf('javascript:') === 0 || lower.indexOf('data:') === 0 || lower.indexOf('vbscript:') === 0) return '#';
        return url;
    }

    function svgIcon(paths, w) {
        w = w || 20;
        return '<svg width="' + w + '" height="' + w + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' + paths + '</svg>';
    }

    var ICON_PREV = svgIcon('<polyline points="15 18 9 12 15 6"/>', 18);
    var ICON_NEXT = svgIcon('<polyline points="9 18 15 12 9 6"/>', 18);

    /* ---------------------------------------------------------------
     * 3. CLIENT-SIDE CACHE
     * ------------------------------------------------------------- */
    var _cache = {};

    function cacheKey(action, params) { return action + ':' + JSON.stringify(params); }

    function cacheGet(action, params) {
        var e = _cache[cacheKey(action, params)];
        if (!e) return null;
        if (Date.now() - e.ts > CACHE_TTL) { delete _cache[cacheKey(action, params)]; return null; }
        return e.data;
    }

    function cacheSet(action, params, data) {
        _cache[cacheKey(action, params)] = { data: data, ts: Date.now() };
    }

    function cacheClear() { _cache = {}; }

    /* ---------------------------------------------------------------
     * 4. ABORT CONTROLLER
     * ------------------------------------------------------------- */
    var _controllers = {};

    function abortStart(ch) {
        if (_controllers[ch]) _controllers[ch].abort();
        _controllers[ch] = new AbortController();
        return _controllers[ch].signal;
    }

    function abortClear(ch) { delete _controllers[ch]; }

    /* ---------------------------------------------------------------
     * 5. AJAX HELPERS
     * ------------------------------------------------------------- */
    function ajaxGet(action, params, opts) {
        opts = opts || {};
        if (opts.useCache !== false) {
            var cached = cacheGet(action, params);
            if (cached) return Promise.resolve(cached);
        }
        var url = new URL(AJAX_URL, location.origin);
        url.searchParams.set('action', action);
        url.searchParams.set('nonce', NONCE);
        Object.keys(params).forEach(function (k) {
            if (params[k] !== '' && params[k] != null) url.searchParams.set(k, params[k]);
        });
        return fetch(url.toString(), { method: 'GET', credentials: 'same-origin', signal: opts.signal })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) { if (opts.useCache !== false) cacheSet(action, params, d); return d; });
    }

    function ajaxPost(action, body, opts) {
        opts = opts || {};
        var url = new URL(AJAX_URL, location.origin);
        url.searchParams.set('action', action);
        url.searchParams.set('nonce', NONCE);
        var formParts = [];
        Object.keys(body).forEach(function (k) {
            if (body[k] !== '' && body[k] != null) formParts.push(encodeURIComponent(k) + '=' + encodeURIComponent(body[k]));
        });
        return fetch(url.toString(), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formParts.join('&'), signal: opts.signal
        }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
    }

    /* ---------------------------------------------------------------
     * 6. ANALYTICS (reserved)
     * ------------------------------------------------------------- */


    /* ---------------------------------------------------------------
     * 7. OVERLAY DOM (find existing template elements)
     * ------------------------------------------------------------- */
    var overlayReady = false;
    var overlayOpen  = false;
    var D = {}; // DOM references

    function initOverlay() {
        if (overlayReady) return;

        D.overlay        = document.getElementById('wcss-overlay');
        if (!D.overlay) return;

        D.input          = document.getElementById('wcss-search-input');
        D.clearBtn       = document.getElementById('wcss-search-clear');
        D.closeBtn       = document.getElementById('wcss-close-btn');
        D.suggestions    = document.getElementById('wcss-suggestions');
        D.resultsWrapper = document.getElementById('wcss-results-wrapper');
        D.filtersSidebar = document.getElementById('wcss-filters-sidebar');
        D.filtersToggle  = document.getElementById('wcss-filters-toggle');
        D.resultsHeader  = document.getElementById('wcss-results-header');
        D.resultsCount   = document.getElementById('wcss-results-count');
        D.didYouMean     = document.getElementById('wcss-did-you-mean');
        D.bannerTop      = document.getElementById('wcss-banner-top');
        D.grid           = document.getElementById('wcss-results-grid');
        D.bannerBottom   = document.getElementById('wcss-banner-bottom');
        D.loader         = document.getElementById('wcss-loading');
        D.noResults      = document.getElementById('wcss-no-results');

        // Mobile filters
        D.mobileBackdrop = document.getElementById('wcss-mobile-filters-backdrop');
        D.mobileFilters  = document.getElementById('wcss-mobile-filters');
        D.mobileBody     = document.getElementById('wcss-mobile-filters-body');
        D.mobileClose    = document.getElementById('wcss-mobile-filters-close');
        D.mobileApply    = document.getElementById('wcss-mobile-apply-filters');
        D.mobileReset    = document.getElementById('wcss-mobile-reset-filters');

        overlayReady = true;
        bindOverlayEvents();
    }

    /* ---------------------------------------------------------------
     * 8. OVERLAY OPEN / CLOSE
     * ------------------------------------------------------------- */
    function openOverlay() {
        initOverlay();
        if (!D.overlay) return;
        overlayOpen = true;
        D.overlay.classList.add('active');
        document.body.classList.add('wcss-body-no-scroll');
        D.input.focus();
    }

    function closeOverlay() {
        if (!overlayOpen) return;
        overlayOpen = false;
        D.overlay.classList.remove('active');
        document.body.classList.remove('wcss-body-no-scroll');
        hideSuggestions();
        debouncedSearch.cancel();
    }

    /* ---------------------------------------------------------------
     * 9. SEARCH STATE
     * ------------------------------------------------------------- */
    var S = { query: '', offset: 0, loaded: 0, total: 0, hasMore: false, loading: false, products: [], facets: null,
        filters: { price_min: 0, price_max: 0, category: [], manufacturer: [] }
    };

    function resetState() {
        S.offset = 0; S.loaded = 0; S.total = 0; S.hasMore = false; S.loading = false; S.products = []; S.facets = null;
    }
    function resetFilters() {
        S.filters = { price_min: 0, price_max: 0, category: [], manufacturer: [] };
    }

    /* ---------------------------------------------------------------
     * 10. SEARCH EXECUTION
     * ------------------------------------------------------------- */
    function executeSearch(append) {
        if (S.loading) return;
        var q = S.query.trim();
        if (q.length < MIN_CHARS) { clearResults(); return; }

        if (!append) { resetState(); S.query = q; }
        if (S.loaded >= MAX_RESULTS) return;

        S.loading = true;
        showLoader(true);

        var sig = abortStart('search');
        var params = { q: q, offset: S.offset, limit: PER_PAGE };
        if (S.filters.price_min > 0)          params.price_min    = S.filters.price_min;
        if (S.filters.price_max > 0)          params.price_max    = S.filters.price_max;
        if (S.filters.category.length)        params.category     = S.filters.category.join(',');
        if (S.filters.manufacturer.length)    params.manufacturer = S.filters.manufacturer.join(',');

        ajaxGet('wcss_search', params, { signal: sig }).then(function (data) {
            S.loading = false;
            showLoader(false);
            abortClear('search');

            var prods = data.products || [];
            S.total   = data.total_count || 0;
            S.hasMore = data.has_more || false;
            S.offset  = (data.offset || 0) + prods.length;
            S.loaded += prods.length;
            if (S.loaded >= MAX_RESULTS) S.hasMore = false;

            if (!append) {
                S.products = prods;
                S.facets   = data.facets || null;
                renderResults(data);

            } else {
                S.products = S.products.concat(prods);
                appendProducts(prods, data.banners);
            }
        }).catch(function (e) {
            if (e.name === 'AbortError') return;
            S.loading = false;
            showLoader(false);
        });
    }

    var debouncedSearch = debounce(function () { executeSearch(false); }, DEBOUNCE_MS);

    /* ---------------------------------------------------------------
     * 11. RENDER RESULTS
     * ------------------------------------------------------------- */
    function clearResults() {
        if (!overlayReady) return;
        D.grid.innerHTML = '';
        D.resultsHeader.style.display = 'none';
        D.didYouMean.style.display = 'none';
        D.didYouMean.innerHTML = '';
        D.bannerTop.innerHTML = '';
        D.bannerBottom.innerHTML = '';
        D.noResults.style.display = 'none';
        D.loader.style.display = 'none';
        D.filtersSidebar.innerHTML = '';
    }

    function renderResults(data) {
        clearResults();
        var prods   = data.products || [];
        var banners = data.banners || {};
        var dym     = data.did_you_mean || [];

        renderStatusBar(data.total_count || 0, dym);
        renderBannerBlock(D.bannerTop, banners.top);
        renderBannerBlock(D.bannerBottom, banners.bottom);

        if (!prods.length) {
            D.noResults.style.display = 'flex';
            return;
        }
        prods.forEach(function (p, i) {
            D.grid.appendChild(productCard(p));
            if (i === 3 && banners.middle && banners.middle.length) inlineBanners(D.grid, banners.middle);
        });
        if (S.facets) Filters.render(S.facets);
    }

    function appendProducts(prods, banners) {
        prods.forEach(function (p, i) {
            D.grid.appendChild(productCard(p));
            if (i === 3 && banners && banners.middle && banners.middle.length) inlineBanners(D.grid, banners.middle);
        });
    }

    function renderStatusBar(total, dym) {
        D.resultsHeader.style.display = 'flex';
        D.resultsCount.textContent = (I18N.results_count || '%d risultati').replace('%d', total);

        if (dym && dym.length && total < 3) {
            D.didYouMean.style.display = 'flex';
            D.didYouMean.innerHTML = '';
            var label = el('span', 'wcss-did-you-mean-label');
            label.textContent = (I18N.did_you_mean || 'Forse cercavi:') + ' ';
            D.didYouMean.appendChild(label);
            dym.forEach(function (term, i) {
                if (i > 0) D.didYouMean.appendChild(document.createTextNode(', '));
                var a = el('a', '', { href: '#' });
                a.textContent = term;
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    D.input.value = term; S.query = term;
                    hideSuggestions(); executeSearch(false);
                });
                D.didYouMean.appendChild(a);
            });
        }
    }

    function showLoader(v) { if (overlayReady) D.loader.style.display = v ? 'flex' : 'none'; }

    /* ---------------------------------------------------------------
     * 12. PRODUCT CARDS
     * ------------------------------------------------------------- */
    function productCard(p) {
        var card = el('div', 'wcss-product-card', { 'data-product-id': p.id });
        var hasSale = p.on_sale || (p.sale_price && p.regular_price && parseFloat(p.sale_price) > 0 && parseFloat(p.sale_price) < parseFloat(p.regular_price));

        // Image container
        var imgWrap = el('a', 'wcss-product-image', { href: safeUrl(p.url) });
        if (p.image) {
            imgWrap.appendChild(el('img', '', { src: p.image, alt: p.name || '', loading: 'lazy' }));
        }
        if (hasSale) {
            var badge = el('span', 'wcss-product-badge wcss-product-badge--sale');
            badge.textContent = 'Sale';
            imgWrap.appendChild(badge);
        }
        card.appendChild(imgWrap);

        // Info
        var info = el('div', 'wcss-product-info');
        if (p.brand) {
            var b = el('span', 'wcss-product-brand');
            b.textContent = p.brand;
            info.appendChild(b);
        }

        var nm = el('a', 'wcss-product-name', { href: safeUrl(p.url) });
        nm.textContent = p.name || '';
        info.appendChild(nm);

        var pw = el('div', 'wcss-product-price');
        if (hasSale) {
            var orig = el('span', 'original'); orig.textContent = formatPrice(p.regular_price);
            var sale = el('span', 'sale');     sale.textContent = formatPrice(p.sale_price);
            pw.append(orig, sale);
        } else if (p.price) {
            var reg = el('span', 'regular'); reg.textContent = formatPrice(p.price);
            pw.appendChild(reg);
        }
        info.appendChild(pw);

        var btn = el('button', 'wcss-add-to-cart-btn', { type: 'button' });
        btn.textContent = I18N.add_to_cart || 'Aggiungi al carrello';
        btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); addToCart(p.id, btn); });
        info.appendChild(btn);

        card.appendChild(info);
        return card;
    }

    /* ---------------------------------------------------------------
     * 13. ADD TO CART
     * ------------------------------------------------------------- */
    function addToCart(pid, btn) {
        if (btn.disabled) return;
        btn.disabled = true;
        var orig = btn.textContent;

        // Use WooCommerce's wc-ajax endpoint (not admin-ajax.php)
        var url = P.wc_ajax_url ? P.wc_ajax_url.replace('%%endpoint%%', 'add_to_cart') : AJAX_URL.replace(/\/wp-admin\/admin-ajax\.php$/, '/') + '?wc-ajax=add_to_cart';

        fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'product_id=' + encodeURIComponent(pid) + '&quantity=1'
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.error) { btn.disabled = false; btn.textContent = orig; return; }
            btn.classList.add('added');
            btn.textContent = I18N.added || 'Aggiunto!';
            // Trigger cart fragment refresh via jQuery (WooCommerce listens via jQuery events)
            if (typeof jQuery !== 'undefined') {
                jQuery(document.body).trigger('wc_fragment_refresh');
                if (data.fragments) jQuery(document.body).trigger('added_to_cart', [data.fragments, data.cart_hash, jQuery(btn)]);
            }
            setTimeout(function () { btn.disabled = false; btn.classList.remove('added'); btn.textContent = orig; }, 2000);
        }).catch(function () { btn.disabled = false; btn.textContent = orig; });
    }

    /* ---------------------------------------------------------------
     * 14. BANNERS
     * ------------------------------------------------------------- */
    function renderBannerBlock(container, banners) {
        container.innerHTML = '';
        if (!banners || !banners.length) return;
        banners.forEach(function (b) {
            var wrap = el('div', 'wcss-banner');
            var content;
            var imgUrl = b.image_url || b.image || '';
            var linkUrl = b.link_url || b.url || '';
            if (imgUrl) {
                content = el('img', '', { src: imgUrl, alt: b.title || '', loading: 'lazy' });
            } else if (b.html) {
                content = el('div'); content.textContent = b.html;
            }
            if (linkUrl && content) {
                var a = el('a', '', { href: safeUrl(linkUrl), target: b.new_tab ? '_blank' : '_self', rel: 'noopener' });
                a.appendChild(content); wrap.appendChild(a);
            } else if (content) {
                wrap.appendChild(content);
            }
            container.appendChild(wrap);
        });
    }

    function inlineBanners(container, banners) {
        var w = el('div', 'wcss-banner-middle');
        renderBannerBlock(w, banners);
        container.appendChild(w);
    }

    /* ---------------------------------------------------------------
     * 15. DYNAMIC FILTERS
     * ------------------------------------------------------------- */
    var Filters = {
        mobileOpen: false,

        render: function (facets) {
            // Render into desktop sidebar
            D.filtersSidebar.innerHTML = '';

            // Price range
            if (facets.price_min !== undefined && facets.price_max !== undefined && facets.price_max > facets.price_min) {
                D.filtersSidebar.appendChild(this._priceFilter(facets.price_min, facets.price_max));
            }
            // Brands
            if (facets.brands && facets.brands.length) {
                D.filtersSidebar.appendChild(this._checkboxFilter(I18N.brand || 'Marca', 'manufacturer', facets.brands));
            }
            // Categories
            if (facets.categories && facets.categories.length) {
                D.filtersSidebar.appendChild(this._checkboxFilter(I18N.categories || 'Categorie', 'category', facets.categories));
            }
            // Actions
            var actions = el('div', 'wcss-filters-actions');
            var applyB = el('button', 'wcss-filter-apply-btn', { type: 'button' });
            applyB.textContent = I18N.apply_filters || 'Applica filtri';
            applyB.addEventListener('click', function () { Filters.apply(); });
            var resetB = el('button', 'wcss-filter-reset-btn', { type: 'button' });
            resetB.textContent = I18N.reset_filters || 'Reset';
            resetB.addEventListener('click', function () { Filters.reset(); });
            actions.append(applyB, resetB);
            D.filtersSidebar.appendChild(actions);
        },

        _priceFilter: function (min, max) {
            var group = el('div', 'wcss-filter-group');
            var title = el('h4', 'wcss-filter-title');
            title.textContent = I18N.price || 'Prezzo';
            title.addEventListener('click', function () { group.classList.toggle('collapsed'); });
            group.appendChild(title);

            var content = el('div', 'wcss-filter-content');
            var wrap = el('div', 'wcss-price-range');
            var floorMin = Math.floor(min), ceilMax = Math.ceil(max);

            // Labels
            var labels = el('div', 'wcss-price-range-labels');
            var lblMin = el('span'); lblMin.textContent = CURRENCY + (S.filters.price_min || floorMin);
            var lblMax = el('span'); lblMax.textContent = CURRENCY + (S.filters.price_max || ceilMax);
            labels.append(lblMin, lblMax);
            wrap.appendChild(labels);

            // Slider track
            var slider = el('div', 'wcss-price-range-slider');
            var track  = el('div', 'wcss-price-range-track');
            var sMin   = el('input', '', { type: 'range', min: floorMin, max: ceilMax, value: S.filters.price_min || floorMin, step: '1', 'data-filter': 'price_min' });
            var sMax   = el('input', '', { type: 'range', min: floorMin, max: ceilMax, value: S.filters.price_max || ceilMax, step: '1', 'data-filter': 'price_max' });

            function updateTrack() {
                var lo = parseInt(sMin.value, 10), hi = parseInt(sMax.value, 10);
                var range = ceilMax - floorMin;
                if (range > 0) {
                    track.style.left = ((lo - floorMin) / range * 100) + '%';
                    track.style.width = ((hi - lo) / range * 100) + '%';
                }
                lblMin.textContent = CURRENCY + lo;
                lblMax.textContent = CURRENCY + hi;
            }

            sMin.addEventListener('input', function () {
                if (parseInt(sMin.value, 10) > parseInt(sMax.value, 10)) sMin.value = sMax.value;
                updateTrack();
            });
            sMax.addEventListener('input', function () {
                if (parseInt(sMax.value, 10) < parseInt(sMin.value, 10)) sMax.value = sMin.value;
                updateTrack();
            });

            slider.append(track, sMin, sMax);
            wrap.appendChild(slider);
            content.appendChild(wrap);
            group.appendChild(content);
            updateTrack();
            return group;
        },

        _checkboxFilter: function (label, key, items) {
            var group = el('div', 'wcss-filter-group');
            var title = el('h4', 'wcss-filter-title');
            title.textContent = label;
            title.addEventListener('click', function () { group.classList.toggle('collapsed'); });
            group.appendChild(title);

            var content = el('div', 'wcss-filter-content');
            var active = S.filters[key] || [];

            items.forEach(function (item) {
                var lbl = el('label', 'wcss-filter-checkbox');
                var cb  = el('input', '', { type: 'checkbox', value: item.id || item.term_id || item.value || '', 'data-filter': key });
                if (active.indexOf(parseInt(cb.value, 10)) !== -1) cb.checked = true;
                var visual = el('span', 'wcss-checkbox-visual');
                var txt = el('span', 'wcss-filter-checkbox-label');
                txt.textContent = item.name || item.label || '';
                lbl.append(cb, visual, txt);
                if (item.count !== undefined) {
                    var cs = el('span', 'wcss-filter-checkbox-count');
                    cs.textContent = item.count;
                    lbl.appendChild(cs);
                }
                content.appendChild(lbl);
            });
            group.appendChild(content);
            return group;
        },

        _readFilters: function (container) {
            var pMinI = container.querySelector('input[data-filter="price_min"]');
            var pMaxI = container.querySelector('input[data-filter="price_max"]');
            S.filters.price_min = pMinI ? parseFloat(pMinI.value) || 0 : 0;
            S.filters.price_max = pMaxI ? parseFloat(pMaxI.value) || 0 : 0;

            S.filters.manufacturer = [];
            container.querySelectorAll('input[data-filter="manufacturer"]:checked').forEach(function (c) { S.filters.manufacturer.push(parseInt(c.value, 10)); });
            S.filters.category = [];
            container.querySelectorAll('input[data-filter="category"]:checked').forEach(function (c) { S.filters.category.push(parseInt(c.value, 10)); });
        },

        apply: function () {
            this._readFilters(D.filtersSidebar);
            if (this.mobileOpen) this.closeMobile();
            executeSearch(false);
        },

        reset: function () {
            resetFilters();
            D.filtersSidebar.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.checked = false; });
            if (this.mobileOpen) this.closeMobile();
            executeSearch(false);
        },

        openMobile: function () {
            if (!D.mobileFilters || !D.mobileBody) return;
            this.mobileOpen = true;
            // Clone desktop filter content into mobile panel, skip actions div (mobile has its own)
            D.mobileBody.innerHTML = '';
            Array.prototype.forEach.call(D.filtersSidebar.childNodes, function (node) {
                if (node.nodeType === 1 && node.classList && node.classList.contains('wcss-filters-actions')) return;
                D.mobileBody.appendChild(node.cloneNode(true));
            });
            // Re-attach title collapse/expand listeners lost during cloneNode
            D.mobileBody.querySelectorAll('.wcss-filter-title').forEach(function (title) {
                title.addEventListener('click', function () { title.parentNode.classList.toggle('collapsed'); });
            });
            D.mobileBackdrop.classList.add('active');
            D.mobileFilters.classList.add('active');
        },

        closeMobile: function () {
            if (!D.mobileFilters) return;
            this.mobileOpen = false;
            D.mobileBackdrop.classList.remove('active');
            D.mobileFilters.classList.remove('active');
        },

        applyMobile: function () {
            if (D.mobileBody) this._readFilters(D.mobileBody);
            this.closeMobile();
            executeSearch(false);
        },

        resetMobile: function () {
            resetFilters();
            if (D.mobileBody) D.mobileBody.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.checked = false; });
            this.closeMobile();
            executeSearch(false);
        }
    };

    /* ---------------------------------------------------------------
     * 16. AUTOCOMPLETE SUGGESTIONS
     * ------------------------------------------------------------- */
    var _sugItems = [];
    var _sugIdx   = -1;

    var _sugTypeIcons = {
        product: svgIcon('<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>', 16),
        brand:   svgIcon('<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 7V5a4 4 0 00-8 0v2"/>', 16)
    };
    var _sugDefaultIcon = svgIcon('<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>', 16);

    function fetchSuggestions(q) {
        if (q.length < 1) { hideSuggestions(); return; }
        var sig = abortStart('suggestions');
        ajaxGet('wcss_suggestions', { q: q }, { signal: sig, useCache: true }).then(function (data) {
            abortClear('suggestions');
            var list = data.suggestions || [];
            list.length ? showSuggestions(list) : hideSuggestions();
        }).catch(function (e) { if (e.name !== 'AbortError') hideSuggestions(); });
    }

    function showSuggestions(items) {
        _sugItems = items; _sugIdx = -1;
        D.suggestions.innerHTML = '';
        D.suggestions.classList.add('active');

        items.forEach(function (item, i) {
            var row = el('div', 'wcss-suggestion-item', { role: 'option', 'data-index': i });
            if (item.type) row.classList.add('wcss-suggestion-item--' + item.type);

            var icon = el('span', 'wcss-suggestion-item-icon');
            if (item.type === 'product' && item.image) {
                icon.innerHTML = '';
                icon.appendChild(el('img', '', { src: item.image, alt: item.query || '', loading: 'lazy' }));
            } else {
                icon.innerHTML = _sugTypeIcons[item.type] || _sugDefaultIcon;
            }

            var textWrap = el('span', 'wcss-suggestion-item-text');
            var label = el('span', 'wcss-suggestion-item-label');
            label.textContent = item.query || item.text || item.name || '';
            textWrap.appendChild(label);

            if (item.meta) {
                var meta = el('span', 'wcss-suggestion-item-meta');
                meta.textContent = item.meta;
                textWrap.appendChild(meta);
            }

            row.append(icon, textWrap);

            if (item.type === 'product' && item.price) {
                var pw = el('span', 'wcss-suggestion-price');
                var hasSale = item.on_sale || (item.sale_price && item.regular_price && parseFloat(item.sale_price) > 0 && parseFloat(item.sale_price) < parseFloat(item.regular_price));
                if (hasSale) {
                    var orig = el('span', 'wcss-suggestion-price-original');
                    orig.textContent = formatPrice(item.regular_price);
                    var sale = el('span', 'wcss-suggestion-price-sale');
                    sale.textContent = formatPrice(item.sale_price);
                    pw.append(orig, sale);
                } else {
                    pw.textContent = formatPrice(item.price);
                }
                row.appendChild(pw);
            }

            row.addEventListener('mousedown', function (e) { e.preventDefault(); selectSuggestion(i); });
            row.addEventListener('mouseenter', function () { highlightSuggestion(i); });
            D.suggestions.appendChild(row);
        });
    }

    function hideSuggestions() {
        if (!overlayReady) return;
        D.suggestions.classList.remove('active');
        D.suggestions.innerHTML = '';
        _sugItems = []; _sugIdx = -1;
    }

    function highlightSuggestion(idx) {
        var rows = D.suggestions.querySelectorAll('.wcss-suggestion-item');
        rows.forEach(function (r) { r.classList.remove('active'); });
        if (idx >= 0 && idx < rows.length) { rows[idx].classList.add('active'); _sugIdx = idx; }
    }

    function navigateSuggestions(dir) {
        if (!_sugItems.length) return;
        var next = _sugIdx + dir;
        if (next < 0) next = _sugItems.length - 1;
        if (next >= _sugItems.length) next = 0;
        highlightSuggestion(next);
        var rows = D.suggestions.querySelectorAll('.wcss-suggestion-item');
        if (rows[next]) rows[next].scrollIntoView({ block: 'nearest' });
    }

    function selectSuggestion(idx) {
        var item = _sugItems[idx];
        if (!item) return;

        // If it's a product with a URL, navigate directly to it
        if (item.type === 'product' && item.url) {
            hideSuggestions();
            window.location.href = item.url;
            return;
        }

        var text = item.query || item.text || item.name || '';
        D.input.value = text; S.query = text;
        hideSuggestions(); executeSearch(false);
    }

    /* ---------------------------------------------------------------
     * 17. INFINITE SCROLL
     * ------------------------------------------------------------- */
    function setupInfiniteScroll() {
        D.resultsWrapper.addEventListener('scroll', function () {
            if (!S.hasMore || S.loading || S.loaded >= MAX_RESULTS) return;
            if (D.resultsWrapper.scrollTop + D.resultsWrapper.clientHeight >= D.resultsWrapper.scrollHeight - 200) {
                executeSearch(true);
            }
        });
    }

    /* ---------------------------------------------------------------
     * 18. OVERLAY EVENT BINDINGS
     * ------------------------------------------------------------- */
    function bindOverlayEvents() {
        D.closeBtn.addEventListener('click', closeOverlay);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlayOpen) {
                D.suggestions.classList.contains('active') ? hideSuggestions() : closeOverlay();
            }
        });

        D.overlay.addEventListener('click', function (e) { if (e.target === D.overlay) closeOverlay(); });

        // Input
        D.input.addEventListener('input', function () {
            var v = D.input.value; S.query = v;
            v.length ? D.clearBtn.classList.add('visible') : D.clearBtn.classList.remove('visible');
            v.length >= 1 ? fetchSuggestions(v) : hideSuggestions();
            if (v.length >= MIN_CHARS) { debouncedSearch(); } else { debouncedSearch.cancel(); clearResults(); }
        });

        D.clearBtn.addEventListener('click', function () {
            D.input.value = ''; S.query = '';
            D.clearBtn.classList.remove('visible');
            hideSuggestions(); clearResults(); resetFilters(); D.input.focus();
        });

        // Keyboard nav for suggestions
        D.input.addEventListener('keydown', function (e) {
            if (!D.suggestions.classList.contains('active')) return;
            if (e.key === 'ArrowDown')  { e.preventDefault(); navigateSuggestions(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); navigateSuggestions(-1); }
            else if (e.key === 'Enter' && _sugIdx >= 0) { e.preventDefault(); selectSuggestion(_sugIdx); }
        });

        D.input.addEventListener('blur', function () { setTimeout(hideSuggestions, 200); });

        // Filters toggle (mobile)
        if (D.filtersToggle) {
            D.filtersToggle.addEventListener('click', function () { Filters.openMobile(); });
        }

        // Mobile filters events
        if (D.mobileClose) D.mobileClose.addEventListener('click', function () { Filters.closeMobile(); });
        if (D.mobileBackdrop) D.mobileBackdrop.addEventListener('click', function () { Filters.closeMobile(); });
        if (D.mobileApply) D.mobileApply.addEventListener('click', function () { Filters.applyMobile(); });
        if (D.mobileReset) D.mobileReset.addEventListener('click', function () { Filters.resetMobile(); });

        setupInfiniteScroll();
    }

    /* ---------------------------------------------------------------
     * 19. SEARCH TRIGGER BINDINGS
     * ------------------------------------------------------------- */
    function bindTriggers() {
        var sels = '.wcss-search-trigger, [data-wcss-trigger], .widget_product_search .search-field, .widget_product_search .search-submit';

        document.addEventListener('click', function (e) {
            var t = e.target.closest(sels);
            if (t) { e.preventDefault(); e.stopPropagation(); openOverlay(); }
        });

        document.addEventListener('focusin', function (e) {
            if (e.target.matches && e.target.matches('.widget_product_search .search-field, .wc-block-product-search__field')) {
                e.preventDefault(); e.target.blur(); openOverlay();
            }
        });

        // Ctrl+K / Cmd+K shortcut
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                overlayOpen ? closeOverlay() : openOverlay();
            }
        });
    }

    /* ---------------------------------------------------------------
     * 20. PRODUCT RECOMMENDATIONS
     * ------------------------------------------------------------- */
    var Recs = {
        init: function () {
            if (!P.recommendations) return;

            var prodEl = document.getElementById('wcss-product-recommendations');
            if (prodEl) {
                var pid = prodEl.getAttribute('data-product-id') || P.product_id;
                if (pid) this._load({ product_id: pid }, prodEl, I18N.also_bought || 'Chi ha acquistato questo ha comprato anche', false);
            }

            var cartEl = document.getElementById('wcss-cart-recommendations');
            if (cartEl) {
                var ids = cartEl.getAttribute('data-product-ids') || '';
                if (!ids && P.cart_product_ids && P.cart_product_ids.length) ids = P.cart_product_ids.join(',');
                if (ids) this._load({ product_ids: ids }, cartEl, I18N.complete_order || 'Completa il tuo ordine', true);
            }
        },

        _load: function (params, container, title, withCart) {
            ajaxGet('wcss_recommendations', params, { useCache: true }).then(function (data) {
                var prods = data.products || [];
                if (!prods.length) return;

                container.innerHTML = '';
                container.classList.add('wcss-recommendations');

                // Header with title and nav
                var header = el('div', 'wcss-recommendations-header');
                var h = el('h3', 'wcss-recommendations-title');
                h.textContent = title;
                header.appendChild(h);

                var nav = el('div', 'wcss-recommendations-nav');
                var prev = el('button', 'wcss-slider-arrow', { type: 'button', 'aria-label': 'Previous' });
                prev.innerHTML = ICON_PREV;
                var next = el('button', 'wcss-slider-arrow', { type: 'button', 'aria-label': 'Next' });
                next.innerHTML = ICON_NEXT;
                nav.append(prev, next);
                header.appendChild(nav);
                container.appendChild(header);

                // Slider
                var slider = el('div', 'wcss-recommendations-slider');
                prods.forEach(function (p) { slider.appendChild(Recs._card(p, withCart)); });

                var scrollAmt = function () { return slider.clientWidth * 0.7; };
                prev.addEventListener('click', function () { slider.scrollBy({ left: -scrollAmt(), behavior: 'smooth' }); });
                next.addEventListener('click', function () { slider.scrollBy({ left: scrollAmt(), behavior: 'smooth' }); });

                container.appendChild(slider);
            }).catch(function () { /* silent */ });
        },

        _card: function (p, withCart) {
            var card = el('div', 'wcss-recommendation-card');
            var hasSale = p.on_sale || (p.sale_price && p.regular_price && parseFloat(p.sale_price) > 0 && parseFloat(p.sale_price) < parseFloat(p.regular_price));

            var imgLink = el('a', 'wcss-product-image', { href: safeUrl(p.url) });
            if (p.image) imgLink.appendChild(el('img', '', { src: p.image, alt: p.name || '', loading: 'lazy' }));
            card.appendChild(imgLink);

            var info = el('div', 'wcss-product-info');
            var nm = el('a', 'wcss-product-name', { href: safeUrl(p.url) });
            nm.textContent = p.name || '';
            info.appendChild(nm);

            var pw = el('div', 'wcss-product-price');
            if (hasSale) {
                var orig = el('span', 'original'); orig.textContent = formatPrice(p.regular_price);
                var sale = el('span', 'sale');     sale.textContent = formatPrice(p.sale_price);
                pw.append(orig, sale);
            } else if (p.price) {
                var reg = el('span', 'regular'); reg.textContent = formatPrice(p.price);
                pw.appendChild(reg);
            }
            info.appendChild(pw);

            if (withCart) {
                var btn = el('button', 'wcss-add-to-cart-btn', { type: 'button' });
                btn.textContent = I18N.add_to_cart || 'Aggiungi al carrello';
                btn.addEventListener('click', function () { addToCart(p.id, btn); });
                info.appendChild(btn);
            }

            card.appendChild(info);
            return card;
        }
    };

    /* ---------------------------------------------------------------
     * 21. INIT
     * ------------------------------------------------------------- */
    function init() {
        bindTriggers();
        Recs.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* ---------------------------------------------------------------
     * 22. PUBLIC API
     * ------------------------------------------------------------- */
    window.WCSmartSearch = {
        open: openOverlay,
        close: closeOverlay,
        search: function (query) {
            openOverlay();
            if (D.input) { D.input.value = query; S.query = query; executeSearch(false); }
        },
        clearCache: cacheClear
    };

})();
