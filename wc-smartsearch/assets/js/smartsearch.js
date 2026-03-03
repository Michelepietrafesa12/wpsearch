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
    var P = window.wcss_params || {};

    var AJAX_URL         = P.ajax_url  || '/wp-admin/admin-ajax.php';
    var NONCE            = P.nonce     || '';
    var MIN_CHARS        = parseInt(P.min_chars, 10) || 2;
    var MAX_RESULTS      = parseInt(P.max_results, 10) || 200;
    var PER_PAGE         = parseInt(P.results_per_page, 10) || 24;
    var DEBOUNCE_DELAY   = parseInt(P.debounce_delay, 10) || 300;
    var CACHE_TTL        = (parseInt(P.cache_ttl, 10) || 300) * 1000; // ms
    var CURRENCY         = P.currency_symbol || '$';
    var I18N             = P.i18n || {};

    /* ---------------------------------------------------------------
     * 1. UTILITY HELPERS
     * ------------------------------------------------------------- */

    /** Debounce helper. Returns debounced function with .cancel(). */
    function debounce(fn, delay) {
        var timer = null;
        var debounced = function () {
            var ctx = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
        debounced.cancel = function () { clearTimeout(timer); };
        return debounced;
    }

    /** Shorthand for document.createElement + optional className. */
    function el(tag, className, attrs) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (attrs) {
            Object.keys(attrs).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        }
        return node;
    }

    /** Escape HTML entities. */
    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    /** Format price with currency symbol. */
    function formatPrice(value) {
        var num = parseFloat(value);
        if (isNaN(num)) return '';
        return CURRENCY + num.toFixed(2);
    }

    /** Generate a UUID-like session ID. */
    function generateSessionId() {
        return 'xxxx-xxxx-xxxx'.replace(/x/g, function () {
            return ((Math.random() * 16) | 0).toString(16);
        }) + '-' + Date.now().toString(36);
    }

    /** Read a cookie value by name. */
    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    /** Set a cookie. */
    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var d = new Date();
            d.setTime(d.getTime() + days * 86400000);
            expires = '; expires=' + d.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    /* ---------------------------------------------------------------
     * 2. SESSION TRACKING
     * ------------------------------------------------------------- */
    var Session = {
        id: '',
        searchSession: '',

        init: function () {
            this.id = getCookie('wcss_session');
            if (!this.id) {
                this.id = generateSessionId();
                setCookie('wcss_session', this.id, 30);
            }
            this.searchSession = getCookie('wcss_search_session');
            if (!this.searchSession) {
                this.searchSession = generateSessionId();
                setCookie('wcss_search_session', this.searchSession, 1);
            }
        }
    };

    /* ---------------------------------------------------------------
     * 3. CLIENT-SIDE CACHE
     * ------------------------------------------------------------- */
    var Cache = {
        _store: {},

        _key: function (action, params) {
            return action + ':' + JSON.stringify(params);
        },

        get: function (action, params) {
            var key = this._key(action, params);
            var entry = this._store[key];
            if (!entry) return null;
            if (Date.now() - entry.ts > CACHE_TTL) {
                delete this._store[key];
                return null;
            }
            return entry.data;
        },

        set: function (action, params, data) {
            var key = this._key(action, params);
            this._store[key] = { data: data, ts: Date.now() };
        },

        clear: function () {
            this._store = {};
        }
    };

    /* ---------------------------------------------------------------
     * 4. ABORT CONTROLLER MANAGER
     * ------------------------------------------------------------- */
    var Requests = {
        _controllers: {},

        /** Abort the previous request for this channel and return a new AbortSignal. */
        start: function (channel) {
            if (this._controllers[channel]) {
                this._controllers[channel].abort();
            }
            this._controllers[channel] = new AbortController();
            return this._controllers[channel].signal;
        },

        clear: function (channel) {
            delete this._controllers[channel];
        }
    };

    /* ---------------------------------------------------------------
     * 5. AJAX HELPERS
     * ------------------------------------------------------------- */

    /**
     * GET request to admin-ajax.php.
     * @param {string}   action    WP AJAX action name
     * @param {object}   params    Query params (q, offset, etc.)
     * @param {object}   opts      { signal, useCache }
     * @returns {Promise<object>}
     */
    function ajaxGet(action, params, opts) {
        opts = opts || {};
        var useCache = opts.useCache !== false;

        if (useCache) {
            var cached = Cache.get(action, params);
            if (cached) return Promise.resolve(cached);
        }

        var url = new URL(AJAX_URL, window.location.origin);
        url.searchParams.set('action', action);
        url.searchParams.set('nonce', NONCE);
        Object.keys(params).forEach(function (k) {
            if (params[k] !== '' && params[k] !== null && params[k] !== undefined) {
                url.searchParams.set(k, params[k]);
            }
        });

        return fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            signal: opts.signal || undefined
        })
        .then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        })
        .then(function (data) {
            if (useCache) Cache.set(action, params, data);
            return data;
        });
    }

    /**
     * POST request to admin-ajax.php.
     */
    function ajaxPost(action, body, opts) {
        opts = opts || {};
        var url = new URL(AJAX_URL, window.location.origin);
        url.searchParams.set('action', action);
        url.searchParams.set('nonce', NONCE);

        return fetch(url.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            signal: opts.signal || undefined
        })
        .then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        });
    }

    /* ---------------------------------------------------------------
     * 6. ANALYTICS
     * ------------------------------------------------------------- */
    var Analytics = {
        /** Track an event. event_type: search | click | add_to_cart */
        track: function (eventType, data) {
            var body = Object.assign({
                event_type: eventType,
                session_id: Session.id
            }, data || {});

            // Fire and forget -- we don't await this
            ajaxPost('wcss_analytics', body).catch(function () { /* silent */ });
        }
    };

    /* ---------------------------------------------------------------
     * 7. OVERLAY DOM (LAZY)
     * ------------------------------------------------------------- */
    var overlayBuilt = false;
    var DOM = {};

    function buildOverlay() {
        if (overlayBuilt) return;
        overlayBuilt = true;

        // Overlay root
        DOM.overlay = el('div', 'wcss-overlay');
        DOM.overlay.setAttribute('role', 'dialog');
        DOM.overlay.setAttribute('aria-label', I18N.search_placeholder || 'Search');
        DOM.overlay.setAttribute('aria-modal', 'true');

        // Header
        var header = el('div', 'wcss-overlay__header');

        // Search input wrapper
        var inputWrap = el('div', 'wcss-overlay__input-wrap');
        DOM.searchIcon = el('span', 'wcss-overlay__search-icon');
        DOM.searchIcon.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';

        DOM.input = el('input', 'wcss-overlay__input', {
            type: 'search',
            placeholder: I18N.search_placeholder || 'Search products...',
            autocomplete: 'off',
            'aria-autocomplete': 'list',
            'aria-controls': 'wcss-suggestions-list'
        });

        DOM.clearBtn = el('button', 'wcss-overlay__clear', { type: 'button', 'aria-label': 'Clear' });
        DOM.clearBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        DOM.clearBtn.style.display = 'none';

        inputWrap.appendChild(DOM.searchIcon);
        inputWrap.appendChild(DOM.input);
        inputWrap.appendChild(DOM.clearBtn);

        // Close button
        DOM.closeBtn = el('button', 'wcss-overlay__close', { type: 'button', 'aria-label': 'Close' });
        DOM.closeBtn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

        header.appendChild(inputWrap);
        header.appendChild(DOM.closeBtn);

        // Suggestions dropdown
        DOM.suggestionsWrap = el('div', 'wcss-suggestions');
        DOM.suggestionsWrap.id = 'wcss-suggestions-list';
        DOM.suggestionsWrap.setAttribute('role', 'listbox');
        DOM.suggestionsWrap.style.display = 'none';

        // Body (content area)
        var body = el('div', 'wcss-overlay__body');

        // Filters sidebar
        DOM.filtersPanel = el('aside', 'wcss-filters');
        DOM.filtersPanel.innerHTML = '<div class="wcss-filters__inner"></div>';

        // Mobile filter toggle
        DOM.mobileFilterBtn = el('button', 'wcss-overlay__mobile-filter-btn', { type: 'button' });
        DOM.mobileFilterBtn.textContent = I18N.show_filters || 'Show filters';

        // Main results area
        var mainArea = el('div', 'wcss-overlay__main');

        // Status bar (result count, did-you-mean)
        DOM.statusBar = el('div', 'wcss-overlay__status');

        // Banners top
        DOM.bannersTop = el('div', 'wcss-banners wcss-banners--top');

        // Product grid
        DOM.grid = el('div', 'wcss-grid');

        // Banners bottom
        DOM.bannersBottom = el('div', 'wcss-banners wcss-banners--bottom');

        // Loading spinner
        DOM.loader = el('div', 'wcss-loader');
        DOM.loader.innerHTML = '<div class="wcss-loader__spinner"></div><span>' + esc(I18N.loading || 'Loading...') + '</span>';
        DOM.loader.style.display = 'none';

        // "No results" message
        DOM.noResults = el('div', 'wcss-no-results');
        DOM.noResults.style.display = 'none';

        mainArea.appendChild(DOM.mobileFilterBtn);
        mainArea.appendChild(DOM.statusBar);
        mainArea.appendChild(DOM.bannersTop);
        mainArea.appendChild(DOM.grid);
        mainArea.appendChild(DOM.bannersBottom);
        mainArea.appendChild(DOM.loader);
        mainArea.appendChild(DOM.noResults);

        body.appendChild(DOM.filtersPanel);
        body.appendChild(mainArea);

        DOM.overlay.appendChild(header);
        DOM.overlay.appendChild(DOM.suggestionsWrap);
        DOM.overlay.appendChild(body);

        document.body.appendChild(DOM.overlay);

        // Store reference to main scrollable area for infinite scroll
        DOM.mainArea = mainArea;

        bindOverlayEvents();
    }

    /* ---------------------------------------------------------------
     * 8. OVERLAY OPEN / CLOSE
     * ------------------------------------------------------------- */
    var overlayOpen = false;

    function openOverlay() {
        buildOverlay();
        overlayOpen = true;
        DOM.overlay.classList.add('wcss-overlay--open');
        document.body.classList.add('wcss-body-no-scroll');
        DOM.input.focus();
    }

    function closeOverlay() {
        if (!overlayOpen) return;
        overlayOpen = false;
        DOM.overlay.classList.remove('wcss-overlay--open');
        document.body.classList.remove('wcss-body-no-scroll');
        Suggestions.hide();
        debouncedSearch.cancel();
    }

    /* ---------------------------------------------------------------
     * 9. SEARCH STATE
     * ------------------------------------------------------------- */
    var State = {
        query: '',
        offset: 0,
        totalLoaded: 0,
        totalCount: 0,
        hasMore: false,
        loading: false,
        products: [],
        facets: null,
        filters: {
            price_min: 0,
            price_max: 0,
            category: [],
            manufacturer: []
        }
    };

    function resetState() {
        State.offset = 0;
        State.totalLoaded = 0;
        State.totalCount = 0;
        State.hasMore = false;
        State.loading = false;
        State.products = [];
        State.facets = null;
    }

    function resetFilters() {
        State.filters = { price_min: 0, price_max: 0, category: [], manufacturer: [] };
    }

    /* ---------------------------------------------------------------
     * 10. SEARCH EXECUTION
     * ------------------------------------------------------------- */

    function executeSearch(append) {
        if (State.loading) return;

        var q = State.query.trim();
        if (q.length < MIN_CHARS) {
            clearResults();
            return;
        }

        if (!append) {
            resetState();
            State.query = q;
        }

        if (State.totalLoaded >= MAX_RESULTS) return;

        State.loading = true;
        showLoader(true);

        var signal = Requests.start('search');

        var params = {
            q: q,
            offset: State.offset,
            limit: PER_PAGE
        };

        // Append filter params
        if (State.filters.price_min > 0) params.price_min = State.filters.price_min;
        if (State.filters.price_max > 0) params.price_max = State.filters.price_max;
        if (State.filters.category.length) params.category = State.filters.category.join(',');
        if (State.filters.manufacturer.length) params.manufacturer = State.filters.manufacturer.join(',');

        ajaxGet('wcss_search', params, { signal: signal })
            .then(function (data) {
                State.loading = false;
                showLoader(false);
                Requests.clear('search');

                var products = data.products || [];
                State.totalCount = data.total_count || 0;
                State.hasMore = data.has_more || false;
                State.offset = (data.offset || 0) + products.length;
                State.totalLoaded += products.length;

                // Enforce max results
                if (State.totalLoaded >= MAX_RESULTS) {
                    State.hasMore = false;
                }

                if (!append) {
                    State.products = products;
                    State.facets = data.facets || null;
                    renderResults(data);

                    // Track search event (first page only)
                    Analytics.track('search', { query: q });
                } else {
                    State.products = State.products.concat(products);
                    appendProducts(products, data.banners);
                }
            })
            .catch(function (err) {
                if (err.name === 'AbortError') return; // expected
                State.loading = false;
                showLoader(false);
                console.error('[SmartSearch] Search error:', err);
            });
    }

    var debouncedSearch = debounce(function () {
        executeSearch(false);
    }, DEBOUNCE_DELAY);

    /* ---------------------------------------------------------------
     * 11. RENDER RESULTS
     * ------------------------------------------------------------- */

    function clearResults() {
        if (!overlayBuilt) return;
        DOM.grid.innerHTML = '';
        DOM.statusBar.innerHTML = '';
        DOM.bannersTop.innerHTML = '';
        DOM.bannersBottom.innerHTML = '';
        DOM.noResults.style.display = 'none';
        DOM.loader.style.display = 'none';
    }

    function renderResults(data) {
        clearResults();

        var products = data.products || [];
        var banners = data.banners || {};
        var didYouMean = data.did_you_mean || [];

        // Status bar: result count
        renderStatusBar(data.total_count || 0, didYouMean);

        // Top banners
        renderBanners(DOM.bannersTop, banners.top);

        // Bottom banners
        renderBanners(DOM.bannersBottom, banners.bottom);

        if (products.length === 0) {
            DOM.noResults.style.display = 'block';
            DOM.noResults.innerHTML = '<p>' + esc(I18N.no_results || 'No results found') + '</p>';
            return;
        }

        // Render products
        products.forEach(function (product, i) {
            DOM.grid.appendChild(createProductCard(product));

            // Middle banner after 4th product
            if (i === 3 && banners.middle && banners.middle.length) {
                renderBannersInline(DOM.grid, banners.middle);
            }
        });

        // Render filters sidebar
        if (State.facets) {
            renderFilters(State.facets);
        }
    }

    function appendProducts(products, banners) {
        var existingCount = DOM.grid.querySelectorAll('.wcss-product-card').length;

        products.forEach(function (product, i) {
            var globalIndex = existingCount + i;
            DOM.grid.appendChild(createProductCard(product));

            // Middle banners after every batch's 4th product position
            if (i === 3 && banners && banners.middle && banners.middle.length) {
                renderBannersInline(DOM.grid, banners.middle);
            }
        });
    }

    function renderStatusBar(totalCount, didYouMean) {
        DOM.statusBar.innerHTML = '';

        // Result count
        var countText = (I18N.results_count || '%d results').replace('%d', totalCount);
        var countEl = el('span', 'wcss-overlay__result-count');
        countEl.textContent = countText;
        DOM.statusBar.appendChild(countEl);

        // "Did you mean" suggestions (show when results < 3)
        if (didYouMean && didYouMean.length > 0 && totalCount < 3) {
            var dymWrap = el('div', 'wcss-did-you-mean');
            dymWrap.innerHTML = '<span>' + esc(I18N.did_you_mean || 'Did you mean:') + ' </span>';
            didYouMean.forEach(function (term, i) {
                if (i > 0) {
                    dymWrap.appendChild(document.createTextNode(', '));
                }
                var link = el('a', 'wcss-did-you-mean__link', { href: '#' });
                link.textContent = term;
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    DOM.input.value = term;
                    State.query = term;
                    Suggestions.hide();
                    executeSearch(false);
                });
                dymWrap.appendChild(link);
            });
            DOM.statusBar.appendChild(dymWrap);
        }
    }

    /* ---------------------------------------------------------------
     * 12. PRODUCT CARDS
     * ------------------------------------------------------------- */

    function createProductCard(product) {
        var card = el('div', 'wcss-product-card');
        card.setAttribute('data-product-id', product.id);

        // Image
        var imgWrap = el('a', 'wcss-product-card__image-wrap', { href: product.url || '#' });
        imgWrap.addEventListener('click', function () {
            Analytics.track('click', { query: State.query, product_id: product.id });
        });
        if (product.image) {
            var img = el('img', 'wcss-product-card__image', {
                src: product.image,
                alt: product.name || '',
                loading: 'lazy'
            });
            imgWrap.appendChild(img);
        }

        // Sale badge
        if (product.sale_price && parseFloat(product.sale_price) < parseFloat(product.price)) {
            var badge = el('span', 'wcss-product-card__sale-badge');
            badge.textContent = 'Sale';
            imgWrap.appendChild(badge);
        }
        card.appendChild(imgWrap);

        // Info section
        var info = el('div', 'wcss-product-card__info');

        // Brand
        if (product.brand) {
            var brand = el('span', 'wcss-product-card__brand');
            brand.textContent = product.brand;
            info.appendChild(brand);
        }

        // Name
        var name = el('a', 'wcss-product-card__name', { href: product.url || '#' });
        name.textContent = product.name || '';
        name.addEventListener('click', function () {
            Analytics.track('click', { query: State.query, product_id: product.id });
        });
        info.appendChild(name);

        // Price
        var priceWrap = el('div', 'wcss-product-card__price');
        if (product.sale_price && parseFloat(product.sale_price) < parseFloat(product.price)) {
            var origPrice = el('span', 'wcss-product-card__price-original');
            origPrice.textContent = formatPrice(product.price);
            priceWrap.appendChild(origPrice);

            var salePrice = el('span', 'wcss-product-card__price-sale');
            salePrice.textContent = formatPrice(product.sale_price);
            priceWrap.appendChild(salePrice);
        } else if (product.price) {
            var regularPrice = el('span', 'wcss-product-card__price-regular');
            regularPrice.textContent = formatPrice(product.price);
            priceWrap.appendChild(regularPrice);
        }
        info.appendChild(priceWrap);

        // Add to cart button
        var cartBtn = el('button', 'wcss-product-card__add-to-cart', { type: 'button' });
        cartBtn.textContent = I18N.add_to_cart || 'Add to cart';
        cartBtn.addEventListener('click', function () {
            addToCart(product.id, cartBtn);
        });
        info.appendChild(cartBtn);

        card.appendChild(info);

        return card;
    }

    /* ---------------------------------------------------------------
     * 13. ADD TO CART
     * ------------------------------------------------------------- */

    function addToCart(productId, btn) {
        if (btn.disabled) return;
        btn.disabled = true;
        btn.classList.add('wcss-product-card__add-to-cart--loading');
        var originalText = btn.textContent;

        var url = new URL(AJAX_URL, window.location.origin);
        url.searchParams.set('action', 'woocommerce_add_to_cart');
        url.searchParams.set('product_id', productId);
        url.searchParams.set('quantity', 1);

        fetch(url.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'product_id=' + productId + '&quantity=1'
        })
        .then(function (resp) { return resp.json(); })
        .then(function () {
            btn.classList.remove('wcss-product-card__add-to-cart--loading');
            btn.classList.add('wcss-product-card__add-to-cart--added');
            btn.textContent = I18N.added || 'Added!';

            Analytics.track('add_to_cart', { query: State.query, product_id: productId });

            // Trigger WooCommerce cart fragment refresh
            if (document.body) {
                document.body.dispatchEvent(new Event('wc_fragment_refresh'));
            }

            setTimeout(function () {
                btn.disabled = false;
                btn.classList.remove('wcss-product-card__add-to-cart--added');
                btn.textContent = originalText;
            }, 2000);
        })
        .catch(function () {
            btn.disabled = false;
            btn.classList.remove('wcss-product-card__add-to-cart--loading');
            btn.textContent = originalText;
        });
    }

    /* ---------------------------------------------------------------
     * 14. BANNERS
     * ------------------------------------------------------------- */

    function renderBanners(container, banners) {
        container.innerHTML = '';
        if (!banners || !banners.length) return;

        banners.forEach(function (banner) {
            var bannerEl = el('div', 'wcss-banner');
            if (banner.url) {
                var link = el('a', 'wcss-banner__link', {
                    href: banner.url,
                    target: banner.new_tab ? '_blank' : '_self',
                    rel: 'noopener'
                });
                if (banner.image) {
                    var img = el('img', 'wcss-banner__image', {
                        src: banner.image,
                        alt: banner.title || '',
                        loading: 'lazy'
                    });
                    link.appendChild(img);
                } else if (banner.html) {
                    link.innerHTML = banner.html;
                }
                bannerEl.appendChild(link);
            } else if (banner.image) {
                var img2 = el('img', 'wcss-banner__image', {
                    src: banner.image,
                    alt: banner.title || '',
                    loading: 'lazy'
                });
                bannerEl.appendChild(img2);
            } else if (banner.html) {
                bannerEl.innerHTML = banner.html;
            }
            container.appendChild(bannerEl);
        });
    }

    /** Render banners inline inside the product grid. */
    function renderBannersInline(container, banners) {
        if (!banners || !banners.length) return;

        var wrapper = el('div', 'wcss-banners wcss-banners--middle');
        renderBanners(wrapper, banners);
        container.appendChild(wrapper);
    }

    /* ---------------------------------------------------------------
     * 15. DYNAMIC FILTERS
     * ------------------------------------------------------------- */
    var Filters = {
        initialized: false,
        mobileOpen: false,

        render: function (facets) {
            var inner = DOM.filtersPanel.querySelector('.wcss-filters__inner');
            if (!inner) return;
            inner.innerHTML = '';

            this.initialized = true;

            // Price range slider
            if (facets.price_min !== undefined && facets.price_max !== undefined &&
                facets.price_max > facets.price_min) {
                inner.appendChild(this._buildPriceFilter(facets.price_min, facets.price_max));
            }

            // Brand checkboxes
            if (facets.brands && facets.brands.length) {
                inner.appendChild(this._buildCheckboxFilter(
                    I18N.brand || 'Brand',
                    'manufacturer',
                    facets.brands
                ));
            }

            // Category checkboxes
            if (facets.categories && facets.categories.length) {
                inner.appendChild(this._buildCheckboxFilter(
                    I18N.categories || 'Categories',
                    'category',
                    facets.categories
                ));
            }

            // Apply / Reset buttons
            var actions = el('div', 'wcss-filters__actions');

            var applyBtn = el('button', 'wcss-filters__apply-btn', { type: 'button' });
            applyBtn.textContent = I18N.apply_filters || 'Apply filters';
            applyBtn.addEventListener('click', function () {
                Filters.apply();
            });

            var resetBtn = el('button', 'wcss-filters__reset-btn', { type: 'button' });
            resetBtn.textContent = I18N.reset_filters || 'Reset';
            resetBtn.addEventListener('click', function () {
                Filters.reset();
            });

            actions.appendChild(applyBtn);
            actions.appendChild(resetBtn);
            inner.appendChild(actions);
        },

        _buildPriceFilter: function (min, max) {
            var section = el('div', 'wcss-filters__section');
            var heading = el('h4', 'wcss-filters__heading');
            heading.textContent = I18N.price || 'Price';
            section.appendChild(heading);

            var rangeWrap = el('div', 'wcss-filters__price-range');

            // Min input
            var minLabel = el('label', 'wcss-filters__price-label');
            minLabel.textContent = 'Min';
            var minInput = el('input', 'wcss-filters__price-input', {
                type: 'number',
                min: Math.floor(min),
                max: Math.ceil(max),
                value: State.filters.price_min || Math.floor(min),
                step: '1',
                'data-filter': 'price_min'
            });
            minLabel.appendChild(minInput);

            // Max input
            var maxLabel = el('label', 'wcss-filters__price-label');
            maxLabel.textContent = 'Max';
            var maxInput = el('input', 'wcss-filters__price-input', {
                type: 'number',
                min: Math.floor(min),
                max: Math.ceil(max),
                value: State.filters.price_max || Math.ceil(max),
                step: '1',
                'data-filter': 'price_max'
            });
            maxLabel.appendChild(maxInput);

            // Range slider (dual thumb using two range inputs)
            var sliderTrack = el('div', 'wcss-filters__slider-track');
            var sliderMin = el('input', 'wcss-filters__slider', {
                type: 'range',
                min: Math.floor(min),
                max: Math.ceil(max),
                value: State.filters.price_min || Math.floor(min),
                step: '1',
                'data-filter': 'price_min'
            });
            var sliderMax = el('input', 'wcss-filters__slider', {
                type: 'range',
                min: Math.floor(min),
                max: Math.ceil(max),
                value: State.filters.price_max || Math.ceil(max),
                step: '1',
                'data-filter': 'price_max'
            });

            // Sync slider <-> number inputs
            sliderMin.addEventListener('input', function () {
                var val = parseInt(sliderMin.value, 10);
                if (val > parseInt(sliderMax.value, 10)) {
                    sliderMin.value = sliderMax.value;
                    val = parseInt(sliderMax.value, 10);
                }
                minInput.value = val;
            });
            sliderMax.addEventListener('input', function () {
                var val = parseInt(sliderMax.value, 10);
                if (val < parseInt(sliderMin.value, 10)) {
                    sliderMax.value = sliderMin.value;
                    val = parseInt(sliderMin.value, 10);
                }
                maxInput.value = val;
            });
            minInput.addEventListener('change', function () {
                sliderMin.value = minInput.value;
            });
            maxInput.addEventListener('change', function () {
                sliderMax.value = maxInput.value;
            });

            sliderTrack.appendChild(sliderMin);
            sliderTrack.appendChild(sliderMax);

            rangeWrap.appendChild(minLabel);
            rangeWrap.appendChild(maxLabel);
            rangeWrap.appendChild(sliderTrack);

            section.appendChild(rangeWrap);
            return section;
        },

        _buildCheckboxFilter: function (label, filterKey, items) {
            var section = el('div', 'wcss-filters__section');
            var heading = el('h4', 'wcss-filters__heading');
            heading.textContent = label;
            section.appendChild(heading);

            var list = el('div', 'wcss-filters__checkbox-list');

            items.forEach(function (item) {
                var wrap = el('label', 'wcss-filters__checkbox-label');
                var checkbox = el('input', 'wcss-filters__checkbox', {
                    type: 'checkbox',
                    value: item.id || item.term_id || item.value || '',
                    'data-filter': filterKey
                });

                // Check if currently active
                var currentValues = State.filters[filterKey] || [];
                var checkValue = parseInt(checkbox.value, 10);
                if (currentValues.indexOf(checkValue) !== -1) {
                    checkbox.checked = true;
                }

                var text = document.createTextNode(' ' + (item.name || item.label || ''));
                var count = '';
                if (item.count !== undefined) {
                    count = ' (' + item.count + ')';
                }

                wrap.appendChild(checkbox);
                wrap.appendChild(text);
                if (count) {
                    var countSpan = el('span', 'wcss-filters__count');
                    countSpan.textContent = count;
                    wrap.appendChild(countSpan);
                }
                list.appendChild(wrap);
            });

            section.appendChild(list);
            return section;
        },

        /** Gather current filter values from the DOM and execute search. */
        apply: function () {
            var inner = DOM.filtersPanel.querySelector('.wcss-filters__inner');
            if (!inner) return;

            // Price
            var priceMinInput = inner.querySelector('input[data-filter="price_min"][type="number"]');
            var priceMaxInput = inner.querySelector('input[data-filter="price_max"][type="number"]');
            State.filters.price_min = priceMinInput ? parseFloat(priceMinInput.value) || 0 : 0;
            State.filters.price_max = priceMaxInput ? parseFloat(priceMaxInput.value) || 0 : 0;

            // Checkboxes for manufacturer
            State.filters.manufacturer = [];
            inner.querySelectorAll('input[data-filter="manufacturer"]:checked').forEach(function (cb) {
                State.filters.manufacturer.push(parseInt(cb.value, 10));
            });

            // Checkboxes for category
            State.filters.category = [];
            inner.querySelectorAll('input[data-filter="category"]:checked').forEach(function (cb) {
                State.filters.category.push(parseInt(cb.value, 10));
            });

            // Close mobile filter panel
            if (this.mobileOpen) {
                this.toggleMobile();
            }

            executeSearch(false);
        },

        reset: function () {
            resetFilters();

            // Uncheck all checkboxes and reset price inputs
            var inner = DOM.filtersPanel.querySelector('.wcss-filters__inner');
            if (inner) {
                inner.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                    cb.checked = false;
                });
                // Reset will re-render via search, so the sliders will update
            }

            if (this.mobileOpen) {
                this.toggleMobile();
            }

            executeSearch(false);
        },

        toggleMobile: function () {
            this.mobileOpen = !this.mobileOpen;
            DOM.filtersPanel.classList.toggle('wcss-filters--mobile-open', this.mobileOpen);
            DOM.mobileFilterBtn.textContent = this.mobileOpen
                ? (I18N.hide_filters || 'Hide filters')
                : (I18N.show_filters || 'Show filters');
        }
    };

    function renderFilters(facets) {
        Filters.render(facets);
    }

    /* ---------------------------------------------------------------
     * 16. AUTOCOMPLETE SUGGESTIONS
     * ------------------------------------------------------------- */
    var Suggestions = {
        items: [],
        activeIndex: -1,

        fetch: function (query) {
            if (query.length < 1) {
                this.hide();
                return;
            }

            var signal = Requests.start('suggestions');

            ajaxGet('wcss_suggestions', { q: query }, { signal: signal, useCache: true })
                .then(function (data) {
                    Requests.clear('suggestions');
                    var list = data.suggestions || [];
                    if (list.length > 0) {
                        Suggestions.show(list);
                    } else {
                        Suggestions.hide();
                    }
                })
                .catch(function (err) {
                    if (err.name === 'AbortError') return;
                    Suggestions.hide();
                });
        },

        show: function (items) {
            this.items = items;
            this.activeIndex = -1;

            DOM.suggestionsWrap.innerHTML = '';
            DOM.suggestionsWrap.style.display = 'block';

            items.forEach(function (item, i) {
                var row = el('div', 'wcss-suggestions__item', {
                    role: 'option',
                    'data-index': i
                });

                // Icon based on type
                var icon = el('span', 'wcss-suggestions__icon');
                if (item.type === 'product') {
                    icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>';
                } else if (item.type === 'brand') {
                    icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 7V5a4 4 0 00-8 0v2"/></svg>';
                } else {
                    icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
                }

                var text = el('span', 'wcss-suggestions__text');
                text.textContent = item.text || item.name || '';

                if (item.type) {
                    var typeBadge = el('span', 'wcss-suggestions__type');
                    typeBadge.textContent = item.type;
                    row.appendChild(icon);
                    row.appendChild(text);
                    row.appendChild(typeBadge);
                } else {
                    row.appendChild(icon);
                    row.appendChild(text);
                }

                row.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // prevent input blur
                    Suggestions.select(i);
                });

                row.addEventListener('mouseenter', function () {
                    Suggestions.highlight(i);
                });

                DOM.suggestionsWrap.appendChild(row);
            });
        },

        hide: function () {
            if (!overlayBuilt) return;
            DOM.suggestionsWrap.style.display = 'none';
            DOM.suggestionsWrap.innerHTML = '';
            this.items = [];
            this.activeIndex = -1;
        },

        highlight: function (index) {
            var rows = DOM.suggestionsWrap.querySelectorAll('.wcss-suggestions__item');
            rows.forEach(function (r) { r.classList.remove('wcss-suggestions__item--active'); });
            if (index >= 0 && index < rows.length) {
                rows[index].classList.add('wcss-suggestions__item--active');
                this.activeIndex = index;
            }
        },

        navigate: function (direction) {
            if (!this.items.length) return;
            var next = this.activeIndex + direction;
            if (next < 0) next = this.items.length - 1;
            if (next >= this.items.length) next = 0;
            this.highlight(next);

            // Ensure the highlighted item is visible
            var rows = DOM.suggestionsWrap.querySelectorAll('.wcss-suggestions__item');
            if (rows[next]) {
                rows[next].scrollIntoView({ block: 'nearest' });
            }
        },

        select: function (index) {
            var item = this.items[index];
            if (!item) return;

            var text = item.text || item.name || '';
            DOM.input.value = text;
            State.query = text;
            this.hide();
            executeSearch(false);
        }
    };

    /* ---------------------------------------------------------------
     * 17. INFINITE SCROLL
     * ------------------------------------------------------------- */

    function setupInfiniteScroll() {
        if (!DOM.mainArea) return;

        DOM.mainArea.addEventListener('scroll', function () {
            if (!State.hasMore || State.loading) return;
            if (State.totalLoaded >= MAX_RESULTS) return;

            var scrollTop = DOM.mainArea.scrollTop;
            var scrollHeight = DOM.mainArea.scrollHeight;
            var clientHeight = DOM.mainArea.clientHeight;

            // Trigger load when within 200px of the bottom
            if (scrollTop + clientHeight >= scrollHeight - 200) {
                executeSearch(true);
            }
        });
    }

    /* ---------------------------------------------------------------
     * 18. LOADER
     * ------------------------------------------------------------- */

    function showLoader(visible) {
        if (!overlayBuilt) return;
        DOM.loader.style.display = visible ? 'flex' : 'none';
    }

    /* ---------------------------------------------------------------
     * 19. EVENT BINDINGS (OVERLAY)
     * ------------------------------------------------------------- */

    function bindOverlayEvents() {
        // Close button
        DOM.closeBtn.addEventListener('click', closeOverlay);

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlayOpen) {
                if (DOM.suggestionsWrap.style.display !== 'none') {
                    Suggestions.hide();
                } else {
                    closeOverlay();
                }
            }
        });

        // Close on overlay background click
        DOM.overlay.addEventListener('click', function (e) {
            if (e.target === DOM.overlay) {
                closeOverlay();
            }
        });

        // Input events
        DOM.input.addEventListener('input', function () {
            var val = DOM.input.value;
            State.query = val;

            DOM.clearBtn.style.display = val.length > 0 ? 'flex' : 'none';

            if (val.length >= 1) {
                Suggestions.fetch(val);
            } else {
                Suggestions.hide();
            }

            if (val.length >= MIN_CHARS) {
                debouncedSearch();
            } else {
                debouncedSearch.cancel();
                clearResults();
            }
        });

        // Clear button
        DOM.clearBtn.addEventListener('click', function () {
            DOM.input.value = '';
            State.query = '';
            DOM.clearBtn.style.display = 'none';
            Suggestions.hide();
            clearResults();
            resetFilters();
            DOM.input.focus();
        });

        // Keyboard navigation for suggestions
        DOM.input.addEventListener('keydown', function (e) {
            if (DOM.suggestionsWrap.style.display === 'none') return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                Suggestions.navigate(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                Suggestions.navigate(-1);
            } else if (e.key === 'Enter') {
                if (Suggestions.activeIndex >= 0) {
                    e.preventDefault();
                    Suggestions.select(Suggestions.activeIndex);
                }
            }
        });

        // Hide suggestions on input blur (with delay so clicks register)
        DOM.input.addEventListener('blur', function () {
            setTimeout(function () {
                Suggestions.hide();
            }, 200);
        });

        // Mobile filter toggle
        DOM.mobileFilterBtn.addEventListener('click', function () {
            Filters.toggleMobile();
        });

        // Infinite scroll
        setupInfiniteScroll();
    }

    /* ---------------------------------------------------------------
     * 20. SEARCH TRIGGER BINDING
     * ------------------------------------------------------------- */

    function bindSearchTriggers() {
        // Bind to any element with class .wcss-search-trigger or
        // the WooCommerce default .widget_product_search, or data attribute
        var selectors = [
            '.wcss-search-trigger',
            '[data-wcss-trigger]',
            '.widget_product_search .search-field',
            '.widget_product_search .search-submit'
        ];

        document.addEventListener('click', function (e) {
            var target = e.target.closest(selectors.join(','));
            if (target) {
                e.preventDefault();
                e.stopPropagation();
                openOverlay();
            }
        });

        // Also intercept focus on WooCommerce search fields
        document.addEventListener('focusin', function (e) {
            if (e.target.matches && e.target.matches('.widget_product_search .search-field, .wc-block-product-search__field')) {
                e.preventDefault();
                e.target.blur();
                openOverlay();
            }
        });

        // Keyboard shortcut: Ctrl+K or Cmd+K to open search
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                if (overlayOpen) {
                    closeOverlay();
                } else {
                    openOverlay();
                }
            }
        });
    }

    /* ---------------------------------------------------------------
     * 21. PRODUCT RECOMMENDATIONS
     * ------------------------------------------------------------- */
    var Recommendations = {
        init: function () {
            if (!P.recommendations) return;

            // Single product page
            var productRec = document.getElementById('wcss-product-recommendations');
            if (productRec) {
                var productId = productRec.getAttribute('data-product-id') || P.product_id;
                if (productId) {
                    this.fetchAndRender(
                        { product_id: productId },
                        productRec,
                        I18N.also_bought || 'Customers also bought',
                        false
                    );
                }
            }

            // Cart page
            var cartRec = document.getElementById('wcss-cart-recommendations');
            if (cartRec) {
                var productIds = cartRec.getAttribute('data-product-ids') || '';
                if (!productIds && P.cart_product_ids && P.cart_product_ids.length) {
                    productIds = P.cart_product_ids.join(',');
                }
                if (productIds) {
                    this.fetchAndRender(
                        { product_ids: productIds },
                        cartRec,
                        I18N.complete_order || 'Complete your order',
                        true
                    );
                }
            }
        },

        fetchAndRender: function (params, container, title, showAddToCart) {
            ajaxGet('wcss_recommendations', params, { useCache: true })
                .then(function (data) {
                    var products = data.products || [];
                    if (!products.length) return;

                    container.innerHTML = '';
                    container.classList.add('wcss-recommendations');

                    var heading = el('h3', 'wcss-recommendations__title');
                    heading.textContent = title;
                    container.appendChild(heading);

                    var slider = el('div', 'wcss-recommendations__slider');
                    var track = el('div', 'wcss-recommendations__track');

                    products.forEach(function (product) {
                        var card = Recommendations.buildCard(product, showAddToCart);
                        track.appendChild(card);
                    });

                    slider.appendChild(track);

                    // Navigation arrows
                    var prevBtn = el('button', 'wcss-recommendations__nav wcss-recommendations__nav--prev', {
                        type: 'button',
                        'aria-label': 'Previous'
                    });
                    prevBtn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>';

                    var nextBtn = el('button', 'wcss-recommendations__nav wcss-recommendations__nav--next', {
                        type: 'button',
                        'aria-label': 'Next'
                    });
                    nextBtn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>';

                    prevBtn.addEventListener('click', function () {
                        var scrollAmount = track.clientWidth * 0.7;
                        track.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
                    });

                    nextBtn.addEventListener('click', function () {
                        var scrollAmount = track.clientWidth * 0.7;
                        track.scrollBy({ left: scrollAmount, behavior: 'smooth' });
                    });

                    slider.appendChild(prevBtn);
                    slider.appendChild(nextBtn);
                    container.appendChild(slider);
                })
                .catch(function (err) {
                    console.error('[SmartSearch] Recommendations error:', err);
                });
        },

        buildCard: function (product, showAddToCart) {
            var card = el('div', 'wcss-recommendations__card');

            var imgLink = el('a', 'wcss-recommendations__image-wrap', {
                href: product.url || '#'
            });
            if (product.image) {
                var img = el('img', 'wcss-recommendations__image', {
                    src: product.image,
                    alt: product.name || '',
                    loading: 'lazy'
                });
                imgLink.appendChild(img);
            }
            card.appendChild(imgLink);

            var info = el('div', 'wcss-recommendations__info');

            var name = el('a', 'wcss-recommendations__name', { href: product.url || '#' });
            name.textContent = product.name || '';
            info.appendChild(name);

            var price = el('div', 'wcss-recommendations__price');
            if (product.sale_price && parseFloat(product.sale_price) < parseFloat(product.price)) {
                price.innerHTML = '<span class="wcss-recommendations__price-original">' + esc(formatPrice(product.price)) + '</span>' +
                    '<span class="wcss-recommendations__price-sale">' + esc(formatPrice(product.sale_price)) + '</span>';
            } else if (product.price) {
                price.textContent = formatPrice(product.price);
            }
            info.appendChild(price);

            if (showAddToCart) {
                var cartBtn = el('button', 'wcss-recommendations__add-to-cart', { type: 'button' });
                cartBtn.textContent = I18N.add_to_cart || 'Add to cart';
                cartBtn.addEventListener('click', function () {
                    addToCart(product.id, cartBtn);
                });
                info.appendChild(cartBtn);
            }

            card.appendChild(info);
            return card;
        }
    };

    /* ---------------------------------------------------------------
     * 22. DARK MODE SUPPORT
     * ------------------------------------------------------------- */

    function initDarkMode() {
        // Read system preference and set data attribute on overlay for CSS hooks
        if (window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');

            function applyTheme(dark) {
                if (overlayBuilt) {
                    DOM.overlay.setAttribute('data-theme', dark ? 'dark' : 'light');
                }
                // Also set on recommendations containers
                document.querySelectorAll('.wcss-recommendations').forEach(function (el) {
                    el.setAttribute('data-theme', dark ? 'dark' : 'light');
                });
            }

            applyTheme(mq.matches);

            // Watch for changes
            if (mq.addEventListener) {
                mq.addEventListener('change', function (e) {
                    applyTheme(e.matches);
                });
            }
        }
    }

    /* ---------------------------------------------------------------
     * 23. INITIALIZATION
     * ------------------------------------------------------------- */

    function init() {
        // Session tracking
        Session.init();

        // Bind search triggers (always, even before overlay is built)
        bindSearchTriggers();

        // Product recommendations (if applicable containers exist)
        Recommendations.init();

        // Dark mode
        initDarkMode();
    }

    // Run on DOMContentLoaded or immediately if already loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* ---------------------------------------------------------------
     * 24. PUBLIC API (for external integrations)
     * ------------------------------------------------------------- */
    window.WCSmartSearch = {
        open: openOverlay,
        close: closeOverlay,
        search: function (query) {
            openOverlay();
            if (DOM.input) {
                DOM.input.value = query;
                State.query = query;
                executeSearch(false);
            }
        },
        clearCache: function () {
            Cache.clear();
        }
    };

})();
