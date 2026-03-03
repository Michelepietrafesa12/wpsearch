/**
 * WC SmartSearch - Frontend JavaScript
 *
 * Fullscreen overlay search with AJAX, infinite scroll, autocomplete,
 * dynamic filters, banners, analytics, and product recommendations.
 *
 * @version 2.2.0
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

    function genId() {
        return 'xxxx-xxxx-xxxx'.replace(/x/g, function () {
            return ((Math.random() * 16) | 0).toString(16);
        }) + '-' + Date.now().toString(36);
    }

    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
    }

    function setCookie(name, val, days) {
        var exp = '';
        if (days) { var d = new Date(); d.setTime(d.getTime() + days * 864e5); exp = '; expires=' + d.toUTCString(); }
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = name + '=' + encodeURIComponent(val) + exp + '; path=/; SameSite=Lax' + secure;
    }

    function svgIcon(paths, w) {
        w = w || 20;
        return '<svg width="' + w + '" height="' + w + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' + paths + '</svg>';
    }

    var ICON_SEARCH = svgIcon('<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>');
    var ICON_CLOSE  = svgIcon('<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>', 24);
    var ICON_X      = svgIcon('<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>', 18);
    var ICON_PREV   = svgIcon('<polyline points="15 18 9 12 15 6"/>', 24);
    var ICON_NEXT   = svgIcon('<polyline points="9 18 15 12 9 6"/>', 24);

    /* ---------------------------------------------------------------
     * 2. SESSION TRACKING
     * ------------------------------------------------------------- */
    var Session = { id: '', searchSession: '' };

    function initSession() {
        Session.id = getCookie('wcss_session') || (function () { var v = genId(); setCookie('wcss_session', v, 30); return v; })();
        Session.searchSession = getCookie('wcss_search_session') || (function () { var v = genId(); setCookie('wcss_search_session', v, 1); return v; })();
    }

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
        return fetch(url.toString(), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body), signal: opts.signal
        }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
    }

    /* ---------------------------------------------------------------
     * 6. ANALYTICS
     * ------------------------------------------------------------- */
    function trackEvent(type, data) {
        ajaxPost('wcss_analytics', Object.assign({ event_type: type, session_id: Session.id }, data || {}))
            .catch(function () { /* silent */ });
    }

    /* ---------------------------------------------------------------
     * 7. OVERLAY DOM (LAZY BUILD)
     * ------------------------------------------------------------- */
    var overlayReady = false;
    var overlayOpen  = false;
    var D = {}; // DOM references

    function buildOverlay() {
        if (overlayReady) return;
        overlayReady = true;

        D.overlay = el('div', 'wcss-overlay', { role: 'dialog', 'aria-label': I18N.search_placeholder || 'Search', 'aria-modal': 'true' });

        // -- Header --
        var header   = el('div', 'wcss-overlay__header');
        var inputW   = el('div', 'wcss-overlay__input-wrap');
        var iconSpan = el('span', 'wcss-overlay__search-icon');
        iconSpan.innerHTML = ICON_SEARCH;

        D.input = el('input', 'wcss-overlay__input', { type: 'search', placeholder: I18N.search_placeholder || 'Search products...', autocomplete: 'off', 'aria-autocomplete': 'list', 'aria-controls': 'wcss-suggestions-list' });

        D.clearBtn = el('button', 'wcss-overlay__clear', { type: 'button', 'aria-label': 'Clear' });
        D.clearBtn.innerHTML = ICON_X;
        D.clearBtn.style.display = 'none';

        inputW.append(iconSpan, D.input, D.clearBtn);

        D.closeBtn = el('button', 'wcss-overlay__close', { type: 'button', 'aria-label': 'Close' });
        D.closeBtn.innerHTML = ICON_CLOSE;
        header.append(inputW, D.closeBtn);

        // -- Suggestions --
        D.suggestions = el('div', 'wcss-suggestions', { id: 'wcss-suggestions-list', role: 'listbox' });
        D.suggestions.style.display = 'none';

        // -- Body --
        var body = el('div', 'wcss-overlay__body');

        D.filtersPanel = el('aside', 'wcss-filters');
        D.filtersPanel.innerHTML = '<div class="wcss-filters__inner"></div>';

        D.mobileFilterBtn = el('button', 'wcss-overlay__mobile-filter-btn', { type: 'button' });
        D.mobileFilterBtn.textContent = I18N.show_filters || 'Show filters';

        D.main       = el('div', 'wcss-overlay__main');
        D.statusBar  = el('div', 'wcss-overlay__status');
        D.bannersTop = el('div', 'wcss-banners wcss-banners--top');
        D.grid       = el('div', 'wcss-grid');
        D.bannersBtm = el('div', 'wcss-banners wcss-banners--bottom');
        D.loader     = el('div', 'wcss-loader');
        D.loader.innerHTML = '<div class="wcss-loader__spinner"></div><span>' + esc(I18N.loading || 'Loading...') + '</span>';
        D.loader.style.display = 'none';
        D.noResults  = el('div', 'wcss-no-results');
        D.noResults.style.display = 'none';

        D.main.append(D.mobileFilterBtn, D.statusBar, D.bannersTop, D.grid, D.bannersBtm, D.loader, D.noResults);
        body.append(D.filtersPanel, D.main);
        D.overlay.append(header, D.suggestions, body);
        document.body.appendChild(D.overlay);

        bindOverlayEvents();
    }

    /* ---------------------------------------------------------------
     * 8. OVERLAY OPEN / CLOSE
     * ------------------------------------------------------------- */
    function openOverlay() {
        buildOverlay();
        overlayOpen = true;
        D.overlay.classList.add('wcss-overlay--open');
        document.body.classList.add('wcss-body-no-scroll');
        D.input.focus();
        applyThemeAttr();
    }

    function closeOverlay() {
        if (!overlayOpen) return;
        overlayOpen = false;
        D.overlay.classList.remove('wcss-overlay--open');
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
                trackEvent('search', { query: q });
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
        D.grid.innerHTML = ''; D.statusBar.innerHTML = '';
        D.bannersTop.innerHTML = ''; D.bannersBtm.innerHTML = '';
        D.noResults.style.display = 'none'; D.loader.style.display = 'none';
    }

    function renderResults(data) {
        clearResults();
        var prods   = data.products || [];
        var banners = data.banners || {};
        var dym     = data.did_you_mean || [];

        renderStatusBar(data.total_count || 0, dym);
        renderBannerBlock(D.bannersTop, banners.top);
        renderBannerBlock(D.bannersBtm, banners.bottom);

        if (!prods.length) {
            D.noResults.style.display = 'block';
            D.noResults.innerHTML = '<p>' + esc(I18N.no_results || 'No results found') + '</p>';
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
        D.statusBar.innerHTML = '';
        var c = el('span', 'wcss-overlay__result-count');
        c.textContent = (I18N.results_count || '%d results').replace('%d', total);
        D.statusBar.appendChild(c);

        if (dym && dym.length && total < 3) {
            var w = el('div', 'wcss-did-you-mean');
            w.innerHTML = '<span>' + esc(I18N.did_you_mean || 'Did you mean:') + ' </span>';
            dym.forEach(function (term, i) {
                if (i > 0) w.appendChild(document.createTextNode(', '));
                var a = el('a', 'wcss-did-you-mean__link', { href: '#' });
                a.textContent = term;
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    D.input.value = term; S.query = term;
                    hideSuggestions(); executeSearch(false);
                });
                w.appendChild(a);
            });
            D.statusBar.appendChild(w);
        }
    }

    function showLoader(v) { if (overlayReady) D.loader.style.display = v ? 'flex' : 'none'; }

    /* ---------------------------------------------------------------
     * 12. PRODUCT CARDS
     * ------------------------------------------------------------- */
    function productCard(p) {
        var card = el('div', 'wcss-product-card', { 'data-product-id': p.id });
        var hasSale = p.sale_price && parseFloat(p.sale_price) < parseFloat(p.price);

        // Image
        var imgWrap = el('a', 'wcss-product-card__image-wrap', { href: p.url || '#' });
        imgWrap.addEventListener('click', function () { trackEvent('click', { query: S.query, product_id: p.id }); });
        if (p.image) imgWrap.appendChild(el('img', 'wcss-product-card__image', { src: p.image, alt: p.name || '', loading: 'lazy' }));
        if (hasSale) { var badge = el('span', 'wcss-product-card__sale-badge'); badge.textContent = 'Sale'; imgWrap.appendChild(badge); }
        card.appendChild(imgWrap);

        // Info
        var info = el('div', 'wcss-product-card__info');
        if (p.brand) { var b = el('span', 'wcss-product-card__brand'); b.textContent = p.brand; info.appendChild(b); }

        var nm = el('a', 'wcss-product-card__name', { href: p.url || '#' });
        nm.textContent = p.name || '';
        nm.addEventListener('click', function () { trackEvent('click', { query: S.query, product_id: p.id }); });
        info.appendChild(nm);

        var pw = el('div', 'wcss-product-card__price');
        if (hasSale) {
            var orig = el('span', 'wcss-product-card__price-original'); orig.textContent = formatPrice(p.price);
            var sale = el('span', 'wcss-product-card__price-sale');     sale.textContent = formatPrice(p.sale_price);
            pw.append(orig, sale);
        } else if (p.price) {
            var reg = el('span', 'wcss-product-card__price-regular'); reg.textContent = formatPrice(p.price);
            pw.appendChild(reg);
        }
        info.appendChild(pw);

        var btn = el('button', 'wcss-product-card__add-to-cart', { type: 'button' });
        btn.textContent = I18N.add_to_cart || 'Add to cart';
        btn.addEventListener('click', function () { addToCart(p.id, btn); });
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
        btn.classList.add('wcss-product-card__add-to-cart--loading');
        var orig = btn.textContent;

        var url = new URL(AJAX_URL, location.origin);
        url.searchParams.set('action', 'woocommerce_add_to_cart');
        url.searchParams.set('nonce', NONCE);

        fetch(url.toString(), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'product_id=' + encodeURIComponent(pid) + '&quantity=1'
        }).then(function (r) { return r.json(); }).then(function () {
            btn.classList.remove('wcss-product-card__add-to-cart--loading');
            btn.classList.add('wcss-product-card__add-to-cart--added');
            btn.textContent = I18N.added || 'Added!';
            trackEvent('add_to_cart', { query: S.query, product_id: pid });
            document.body.dispatchEvent(new Event('wc_fragment_refresh'));
            setTimeout(function () { btn.disabled = false; btn.classList.remove('wcss-product-card__add-to-cart--added'); btn.textContent = orig; }, 2000);
        }).catch(function () { btn.disabled = false; btn.classList.remove('wcss-product-card__add-to-cart--loading'); btn.textContent = orig; });
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
            if (b.image) {
                content = el('img', 'wcss-banner__image', { src: b.image, alt: b.title || '', loading: 'lazy' });
            } else if (b.html) {
                content = el('div'); content.textContent = b.html;
            }
            if (b.url && content) {
                var a = el('a', 'wcss-banner__link', { href: b.url, target: b.new_tab ? '_blank' : '_self', rel: 'noopener' });
                a.appendChild(content); wrap.appendChild(a);
            } else if (content) {
                wrap.appendChild(content);
            }
            container.appendChild(wrap);
        });
    }

    function inlineBanners(container, banners) {
        var w = el('div', 'wcss-banners wcss-banners--middle');
        renderBannerBlock(w, banners);
        container.appendChild(w);
    }

    /* ---------------------------------------------------------------
     * 15. DYNAMIC FILTERS
     * ------------------------------------------------------------- */
    var Filters = {
        mobileOpen: false,

        render: function (facets) {
            var inner = D.filtersPanel.querySelector('.wcss-filters__inner');
            if (!inner) return;
            inner.innerHTML = '';

            // Price range
            if (facets.price_min !== undefined && facets.price_max !== undefined && facets.price_max > facets.price_min) {
                inner.appendChild(this._priceFilter(facets.price_min, facets.price_max));
            }
            // Brands
            if (facets.brands && facets.brands.length) {
                inner.appendChild(this._checkboxFilter(I18N.brand || 'Brand', 'manufacturer', facets.brands));
            }
            // Categories
            if (facets.categories && facets.categories.length) {
                inner.appendChild(this._checkboxFilter(I18N.categories || 'Categories', 'category', facets.categories));
            }
            // Buttons
            var actions = el('div', 'wcss-filters__actions');
            var applyB = el('button', 'wcss-filters__apply-btn', { type: 'button' });
            applyB.textContent = I18N.apply_filters || 'Apply filters';
            applyB.addEventListener('click', function () { Filters.apply(); });
            var resetB = el('button', 'wcss-filters__reset-btn', { type: 'button' });
            resetB.textContent = I18N.reset_filters || 'Reset';
            resetB.addEventListener('click', function () { Filters.reset(); });
            actions.append(applyB, resetB);
            inner.appendChild(actions);
        },

        _priceFilter: function (min, max) {
            var sec = el('div', 'wcss-filters__section');
            var h = el('h4', 'wcss-filters__heading'); h.textContent = I18N.price || 'Price';
            sec.appendChild(h);

            var wrap = el('div', 'wcss-filters__price-range');
            var floorMin = Math.floor(min), ceilMax = Math.ceil(max);

            // Number inputs
            var minLbl = el('label', 'wcss-filters__price-label'); minLbl.textContent = 'Min';
            var minInp = el('input', 'wcss-filters__price-input', { type: 'number', min: floorMin, max: ceilMax, value: S.filters.price_min || floorMin, step: '1', 'data-filter': 'price_min' });
            minLbl.appendChild(minInp);

            var maxLbl = el('label', 'wcss-filters__price-label'); maxLbl.textContent = 'Max';
            var maxInp = el('input', 'wcss-filters__price-input', { type: 'number', min: floorMin, max: ceilMax, value: S.filters.price_max || ceilMax, step: '1', 'data-filter': 'price_max' });
            maxLbl.appendChild(maxInp);

            // Range sliders (dual thumb)
            var track  = el('div', 'wcss-filters__slider-track');
            var sMin   = el('input', 'wcss-filters__slider', { type: 'range', min: floorMin, max: ceilMax, value: S.filters.price_min || floorMin, step: '1' });
            var sMax   = el('input', 'wcss-filters__slider', { type: 'range', min: floorMin, max: ceilMax, value: S.filters.price_max || ceilMax, step: '1' });

            sMin.addEventListener('input', function () {
                if (parseInt(sMin.value, 10) > parseInt(sMax.value, 10)) sMin.value = sMax.value;
                minInp.value = sMin.value;
            });
            sMax.addEventListener('input', function () {
                if (parseInt(sMax.value, 10) < parseInt(sMin.value, 10)) sMax.value = sMin.value;
                maxInp.value = sMax.value;
            });
            minInp.addEventListener('change', function () { sMin.value = minInp.value; });
            maxInp.addEventListener('change', function () { sMax.value = maxInp.value; });

            track.append(sMin, sMax);
            wrap.append(minLbl, maxLbl, track);
            sec.appendChild(wrap);
            return sec;
        },

        _checkboxFilter: function (label, key, items) {
            var sec = el('div', 'wcss-filters__section');
            var h = el('h4', 'wcss-filters__heading'); h.textContent = label;
            sec.appendChild(h);

            var list = el('div', 'wcss-filters__checkbox-list');
            var active = S.filters[key] || [];

            items.forEach(function (item) {
                var lbl = el('label', 'wcss-filters__checkbox-label');
                var cb  = el('input', 'wcss-filters__checkbox', { type: 'checkbox', value: item.id || item.term_id || item.value || '', 'data-filter': key });
                if (active.indexOf(parseInt(cb.value, 10)) !== -1) cb.checked = true;
                var txt = document.createTextNode(' ' + (item.name || item.label || ''));
                lbl.append(cb, txt);
                if (item.count !== undefined) {
                    var cs = el('span', 'wcss-filters__count'); cs.textContent = ' (' + item.count + ')';
                    lbl.appendChild(cs);
                }
                list.appendChild(lbl);
            });
            sec.appendChild(list);
            return sec;
        },

        apply: function () {
            var inner = D.filtersPanel.querySelector('.wcss-filters__inner');
            if (!inner) return;
            var pMinI = inner.querySelector('input[data-filter="price_min"][type="number"]');
            var pMaxI = inner.querySelector('input[data-filter="price_max"][type="number"]');
            S.filters.price_min = pMinI ? parseFloat(pMinI.value) || 0 : 0;
            S.filters.price_max = pMaxI ? parseFloat(pMaxI.value) || 0 : 0;

            S.filters.manufacturer = [];
            inner.querySelectorAll('input[data-filter="manufacturer"]:checked').forEach(function (c) { S.filters.manufacturer.push(parseInt(c.value, 10)); });
            S.filters.category = [];
            inner.querySelectorAll('input[data-filter="category"]:checked').forEach(function (c) { S.filters.category.push(parseInt(c.value, 10)); });

            if (this.mobileOpen) this.toggleMobile();
            executeSearch(false);
        },

        reset: function () {
            resetFilters();
            var inner = D.filtersPanel.querySelector('.wcss-filters__inner');
            if (inner) inner.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.checked = false; });
            if (this.mobileOpen) this.toggleMobile();
            executeSearch(false);
        },

        toggleMobile: function () {
            this.mobileOpen = !this.mobileOpen;
            D.filtersPanel.classList.toggle('wcss-filters--mobile-open', this.mobileOpen);
            D.mobileFilterBtn.textContent = this.mobileOpen ? (I18N.hide_filters || 'Hide filters') : (I18N.show_filters || 'Show filters');
        }
    };

    /* ---------------------------------------------------------------
     * 16. AUTOCOMPLETE SUGGESTIONS
     * ------------------------------------------------------------- */
    var _sugItems = [];
    var _sugIdx   = -1;

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
        D.suggestions.style.display = 'block';

        var typeIcons = {
            product: svgIcon('<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>', 16),
            brand:   svgIcon('<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 7V5a4 4 0 00-8 0v2"/>', 16)
        };
        var defaultIcon = svgIcon('<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>', 16);

        items.forEach(function (item, i) {
            var row = el('div', 'wcss-suggestions__item', { role: 'option', 'data-index': i });
            var icon = el('span', 'wcss-suggestions__icon');
            icon.innerHTML = typeIcons[item.type] || defaultIcon;
            var text = el('span', 'wcss-suggestions__text');
            text.textContent = item.text || item.name || '';
            row.append(icon, text);
            if (item.type) { var tb = el('span', 'wcss-suggestions__type'); tb.textContent = item.type; row.appendChild(tb); }
            row.addEventListener('mousedown', function (e) { e.preventDefault(); selectSuggestion(i); });
            row.addEventListener('mouseenter', function () { highlightSuggestion(i); });
            D.suggestions.appendChild(row);
        });
    }

    function hideSuggestions() {
        if (!overlayReady) return;
        D.suggestions.style.display = 'none';
        D.suggestions.innerHTML = '';
        _sugItems = []; _sugIdx = -1;
    }

    function highlightSuggestion(idx) {
        var rows = D.suggestions.querySelectorAll('.wcss-suggestions__item');
        rows.forEach(function (r) { r.classList.remove('wcss-suggestions__item--active'); });
        if (idx >= 0 && idx < rows.length) { rows[idx].classList.add('wcss-suggestions__item--active'); _sugIdx = idx; }
    }

    function navigateSuggestions(dir) {
        if (!_sugItems.length) return;
        var next = _sugIdx + dir;
        if (next < 0) next = _sugItems.length - 1;
        if (next >= _sugItems.length) next = 0;
        highlightSuggestion(next);
        var rows = D.suggestions.querySelectorAll('.wcss-suggestions__item');
        if (rows[next]) rows[next].scrollIntoView({ block: 'nearest' });
    }

    function selectSuggestion(idx) {
        var item = _sugItems[idx];
        if (!item) return;
        var text = item.text || item.name || '';
        D.input.value = text; S.query = text;
        hideSuggestions(); executeSearch(false);
    }

    /* ---------------------------------------------------------------
     * 17. INFINITE SCROLL
     * ------------------------------------------------------------- */
    function setupInfiniteScroll() {
        D.main.addEventListener('scroll', function () {
            if (!S.hasMore || S.loading || S.loaded >= MAX_RESULTS) return;
            if (D.main.scrollTop + D.main.clientHeight >= D.main.scrollHeight - 200) {
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
                D.suggestions.style.display !== 'none' ? hideSuggestions() : closeOverlay();
            }
        });

        D.overlay.addEventListener('click', function (e) { if (e.target === D.overlay) closeOverlay(); });

        // Input
        D.input.addEventListener('input', function () {
            var v = D.input.value; S.query = v;
            D.clearBtn.style.display = v.length ? 'flex' : 'none';
            v.length >= 1 ? fetchSuggestions(v) : hideSuggestions();
            if (v.length >= MIN_CHARS) { debouncedSearch(); } else { debouncedSearch.cancel(); clearResults(); }
        });

        D.clearBtn.addEventListener('click', function () {
            D.input.value = ''; S.query = '';
            D.clearBtn.style.display = 'none';
            hideSuggestions(); clearResults(); resetFilters(); D.input.focus();
        });

        // Keyboard nav for suggestions
        D.input.addEventListener('keydown', function (e) {
            if (D.suggestions.style.display === 'none') return;
            if (e.key === 'ArrowDown')  { e.preventDefault(); navigateSuggestions(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); navigateSuggestions(-1); }
            else if (e.key === 'Enter' && _sugIdx >= 0) { e.preventDefault(); selectSuggestion(_sugIdx); }
        });

        D.input.addEventListener('blur', function () { setTimeout(hideSuggestions, 200); });

        D.mobileFilterBtn.addEventListener('click', function () { Filters.toggleMobile(); });

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
                if (pid) this._load({ product_id: pid }, prodEl, I18N.also_bought || 'Customers also bought', false);
            }

            var cartEl = document.getElementById('wcss-cart-recommendations');
            if (cartEl) {
                var ids = cartEl.getAttribute('data-product-ids') || '';
                if (!ids && P.cart_product_ids && P.cart_product_ids.length) ids = P.cart_product_ids.join(',');
                if (ids) this._load({ product_ids: ids }, cartEl, I18N.complete_order || 'Complete your order', true);
            }
        },

        _load: function (params, container, title, withCart) {
            ajaxGet('wcss_recommendations', params, { useCache: true }).then(function (data) {
                var prods = data.products || [];
                if (!prods.length) return;

                container.innerHTML = '';
                container.classList.add('wcss-recommendations');

                var h = el('h3', 'wcss-recommendations__title'); h.textContent = title;
                container.appendChild(h);

                var slider = el('div', 'wcss-recommendations__slider');
                var track  = el('div', 'wcss-recommendations__track');
                prods.forEach(function (p) { track.appendChild(Recs._card(p, withCart)); });
                slider.appendChild(track);

                // Nav arrows
                var prev = el('button', 'wcss-recommendations__nav wcss-recommendations__nav--prev', { type: 'button', 'aria-label': 'Previous' });
                prev.innerHTML = ICON_PREV;
                var next = el('button', 'wcss-recommendations__nav wcss-recommendations__nav--next', { type: 'button', 'aria-label': 'Next' });
                next.innerHTML = ICON_NEXT;

                var scrollAmt = function () { return track.clientWidth * 0.7; };
                prev.addEventListener('click', function () { track.scrollBy({ left: -scrollAmt(), behavior: 'smooth' }); });
                next.addEventListener('click', function () { track.scrollBy({ left: scrollAmt(), behavior: 'smooth' }); });

                slider.append(prev, next);
                container.appendChild(slider);
            }).catch(function () { /* silent */ });
        },

        _card: function (p, withCart) {
            var card = el('div', 'wcss-recommendations__card');
            var hasSale = p.sale_price && parseFloat(p.sale_price) < parseFloat(p.price);

            var imgLink = el('a', 'wcss-recommendations__image-wrap', { href: p.url || '#' });
            if (p.image) imgLink.appendChild(el('img', 'wcss-recommendations__image', { src: p.image, alt: p.name || '', loading: 'lazy' }));
            card.appendChild(imgLink);

            var info = el('div', 'wcss-recommendations__info');
            var nm = el('a', 'wcss-recommendations__name', { href: p.url || '#' });
            nm.textContent = p.name || '';
            info.appendChild(nm);

            var pw = el('div', 'wcss-recommendations__price');
            if (hasSale) {
                pw.innerHTML = '<span class="wcss-recommendations__price-original">' + esc(formatPrice(p.price)) + '</span>'
                    + '<span class="wcss-recommendations__price-sale">' + esc(formatPrice(p.sale_price)) + '</span>';
            } else if (p.price) {
                pw.textContent = formatPrice(p.price);
            }
            info.appendChild(pw);

            if (withCart) {
                var btn = el('button', 'wcss-recommendations__add-to-cart', { type: 'button' });
                btn.textContent = I18N.add_to_cart || 'Add to cart';
                btn.addEventListener('click', function () { addToCart(p.id, btn); });
                info.appendChild(btn);
            }

            card.appendChild(info);
            return card;
        }
    };

    /* ---------------------------------------------------------------
     * 21. DARK MODE
     * ------------------------------------------------------------- */
    function applyThemeAttr() {
        if (!window.matchMedia) return;
        var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        var theme = dark ? 'dark' : 'light';
        if (overlayReady) D.overlay.setAttribute('data-theme', theme);
        document.querySelectorAll('.wcss-recommendations').forEach(function (e) { e.setAttribute('data-theme', theme); });
    }

    function initDarkMode() {
        if (!window.matchMedia) return;
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        applyThemeAttr();
        if (mq.addEventListener) {
            mq.addEventListener('change', applyThemeAttr);
        }
    }

    /* ---------------------------------------------------------------
     * 22. INIT
     * ------------------------------------------------------------- */
    function init() {
        initSession();
        bindTriggers();
        Recs.init();
        initDarkMode();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* ---------------------------------------------------------------
     * 23. PUBLIC API
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
