/**
 * WC SmartSearch - Admin Dashboard JavaScript
 *
 * Handles AJAX interactions for boost, banner, synonym management,
 * correlation calculation, cache flushing, and product autocomplete.
 *
 * Depends on: jQuery, wp.media, wcss_admin (localized object).
 */
(function ($) {
    'use strict';

    var admin = wcss_admin;

    /* ======================================================================
     * Helpers
     * ==================================================================== */

    /**
     * Display a status notice that fades out after a delay.
     *
     * @param {string}  selector  Target element selector.
     * @param {string}  message   Text to display.
     * @param {string}  type      'success' or 'error'.
     */
    function showNotice(selector, message, type) {
        var $el = $(selector);
        $el.removeClass('success error')
           .addClass(type)
           .html(message)
           .stop(true)
           .fadeIn(200);

        if (type === 'success') {
            $el.delay(4000).fadeOut(400);
        }
    }

    /**
     * Wrapper around $.post that automatically sends the nonce.
     */
    function ajaxPost(action, data, onSuccess) {
        data.action = action;
        data.nonce  = admin.nonce;

        $.post(admin.ajax_url, data, function (response) {
            if (response.success) {
                onSuccess(response.data);
            } else {
                var msg = (response.data && response.data.message) ? response.data.message : admin.i18n.error;
                alert(msg);
            }
        }).fail(function () {
            alert(admin.i18n.error);
        });
    }

    /* ======================================================================
     * 1. Product Search Autocomplete (Boost form)
     * ==================================================================== */

    function initProductAutocomplete() {
        var $search = $('#boost-product-search');
        if (!$search.length) {
            return;
        }

        var debounceTimer;
        var $dropdown = $('<ul class="wcss-autocomplete-list"></ul>')
            .css({
                position: 'absolute',
                zIndex: 100000,
                background: '#fff',
                border: '1px solid #dcdcde',
                borderRadius: '0 0 4px 4px',
                maxHeight: 220,
                overflowY: 'auto',
                display: 'none',
                listStyle: 'none',
                margin: 0,
                padding: 0,
                width: $search.outerWidth()
            })
            .insertAfter($search);

        $search.on('input', function () {
            var term = $.trim($search.val());
            clearTimeout(debounceTimer);

            if (term.length < 2) {
                $dropdown.hide().empty();
                return;
            }

            debounceTimer = setTimeout(function () {
                $.getJSON(admin.ajax_url, {
                    action: 'wcss_admin_search_products',
                    nonce:  admin.nonce,
                    term:   term
                }, function (results) {
                    $dropdown.empty();
                    if (!results || !results.length) {
                        $dropdown.hide();
                        return;
                    }
                    $.each(results, function (_, item) {
                        $('<li></li>')
                            .text(item.label)
                            .css({ padding: '8px 12px', cursor: 'pointer' })
                            .on('mouseenter', function () { $(this).css('background', '#f0f0f1'); })
                            .on('mouseleave', function () { $(this).css('background', '#fff'); })
                            .on('mousedown', function () {
                                $search.val(item.label);
                                $('#boost-product-id').val(item.id);
                                $dropdown.hide();
                            })
                            .appendTo($dropdown);
                    });
                    $dropdown.show();
                });
            }, 300);
        });

        $search.on('blur', function () {
            setTimeout(function () { $dropdown.hide(); }, 200);
        });
    }

    /* ======================================================================
     * 2-4. Boost CRUD
     * ==================================================================== */

    function initBoostHandlers() {
        // Save boost
        $('#wcss-save-boost').on('click', function () {
            var data = {
                boost_id:   $('#boost-id').val(),
                product_id: $('#boost-product-id').val(),
                multiplier: $('#boost-multiplier').val(),
                keywords:   $('#boost-keywords').val(),
                start_date: $('#boost-start-date').val(),
                end_date:   $('#boost-end-date').val()
            };

            if (!data.product_id || data.product_id === '0') {
                alert(admin.i18n.error);
                return;
            }

            ajaxPost('wcss_admin_save_boost', data, function () {
                location.reload();
            });
        });

        // Delete boost
        $(document).on('click', '.wcss-delete-boost', function () {
            if (!confirm(admin.i18n.confirm_delete)) {
                return;
            }
            var id = $(this).data('id');
            ajaxPost('wcss_admin_delete_boost', { id: id }, function () {
                $('tr[data-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
            });
        });

        // Toggle boost
        $(document).on('click', '.wcss-toggle-boost', function () {
            var $btn = $(this);
            var id   = $btn.data('id');
            ajaxPost('wcss_admin_toggle_boost', { id: id }, function (data) {
                if (data.active) {
                    $btn.addClass('active').text($btn.text().replace(/\S+/, 'Attivo'));
                } else {
                    $btn.removeClass('active').text($btn.text().replace(/\S+/, 'Disattivato'));
                }
            });
        });
    }

    /* ======================================================================
     * 5-7. Banner CRUD (with WP Media Uploader)
     * ==================================================================== */

    function initBannerHandlers() {
        var mediaFrame;

        // WP media uploader for banner image
        $('#wcss-upload-banner').on('click', function (e) {
            e.preventDefault();

            if (mediaFrame) {
                mediaFrame.open();
                return;
            }

            mediaFrame = wp.media({
                title:    'Seleziona immagine banner',
                button:   { text: 'Usa questa immagine' },
                multiple: false
            });

            mediaFrame.on('select', function () {
                var attachment = mediaFrame.state().get('selection').first().toJSON();
                $('#banner-image-url').val(attachment.url);
                $('#banner-preview').html('<img src="' + attachment.url + '">');
            });

            mediaFrame.open();
        });

        // Live preview when URL is typed manually
        $('#banner-image-url').on('change', function () {
            var url = $.trim($(this).val());
            if (url) {
                $('#banner-preview').html('<img src="' + url + '">');
            } else {
                $('#banner-preview').empty();
            }
        });

        // Save banner
        $('#wcss-save-banner').on('click', function () {
            var data = {
                banner_id:  $('#banner-id').val(),
                title:      $('#banner-title').val(),
                image_url:  $('#banner-image-url').val(),
                link_url:   $('#banner-link-url').val(),
                position:   $('#banner-position').val(),
                keywords:   $('#banner-keywords').val(),
                start_date: $('#banner-start-date').val(),
                end_date:   $('#banner-end-date').val(),
                sort_order: $('#banner-sort-order').val()
            };

            if (!data.image_url) {
                alert(admin.i18n.error);
                return;
            }

            ajaxPost('wcss_admin_save_banner', data, function () {
                location.reload();
            });
        });

        // Delete banner
        $(document).on('click', '.wcss-delete-banner', function () {
            if (!confirm(admin.i18n.confirm_delete)) {
                return;
            }
            var id = $(this).data('id');
            ajaxPost('wcss_admin_delete_banner', { id: id }, function () {
                $('tr[data-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
            });
        });

        // Toggle banner
        $(document).on('click', '.wcss-toggle-banner', function () {
            var $btn = $(this);
            var id   = $btn.data('id');
            ajaxPost('wcss_admin_toggle_banner', { id: id }, function (data) {
                if (data.active) {
                    $btn.addClass('active').text($btn.text().replace(/\S+/, 'Attivo'));
                } else {
                    $btn.removeClass('active').text($btn.text().replace(/\S+/, 'Disattivato'));
                }
            });
        });
    }

    /* ======================================================================
     * 8-9. Synonym CRUD
     * ==================================================================== */

    function initSynonymHandlers() {
        // Save synonym
        $('#wcss-save-synonym').on('click', function () {
            var data = {
                synonym_id: $('#synonym-id').val(),
                word:       $('#synonym-word').val(),
                synonym:    $('#synonym-synonym').val()
            };

            if (!data.word || !data.synonym) {
                alert(admin.i18n.error);
                return;
            }

            ajaxPost('wcss_admin_save_synonym', data, function () {
                location.reload();
            });
        });

        // Delete synonym
        $(document).on('click', '.wcss-delete-synonym', function () {
            if (!confirm(admin.i18n.confirm_delete)) {
                return;
            }
            var id = $(this).data('id');
            ajaxPost('wcss_admin_delete_synonym', { id: id }, function () {
                $('tr[data-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
            });
        });
    }

    /* ======================================================================
     * 10. Calculate Correlations
     * ==================================================================== */

    function initCorrelationHandlers() {
        $('#wcss-calculate-correlations').on('click', function () {
            var $btn    = $(this);
            var $result = $('#wcss-correlation-result');

            $btn.prop('disabled', true).find('.dashicons').addClass('spin');
            $result.removeClass('success error')
                   .html(admin.i18n.calculating)
                   .fadeIn(200);

            ajaxPost('wcss_admin_calculate_correlations', {}, function (data) {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');

                var msg = admin.i18n.calculated;
                if (data) {
                    msg += '<br>' +
                           'Correlazioni: ' + (data.total_correlations || 0) +
                           ' | Prodotti: ' + (data.total_products || 0) +
                           ' | Ordini analizzati: ' + (data.orders_analyzed || 0);
                }
                showNotice('#wcss-correlation-result', msg, 'success');
            });
        });
    }

    /* ======================================================================
     * 11. Flush Cache
     * ==================================================================== */

    function initCacheHandlers() {
        $('#wcss-flush-cache').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true);

            ajaxPost('wcss_admin_flush_cache', {}, function () {
                $btn.prop('disabled', false);
                // Insert a temporary notice after the button
                var $notice = $('<span class="wcss-notice success" style="display:inline-block;margin-left:10px;padding:6px 12px;"></span>')
                    .text(admin.i18n.saved)
                    .insertAfter($btn)
                    .delay(3000)
                    .fadeOut(400, function () { $(this).remove(); });
            });
        });
    }

    /* ======================================================================
     * Spinning animation for dashicons-update during correlation calc
     * ==================================================================== */
    $('<style>')
        .text('@keyframes wcss-spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}.spin{animation:wcss-spin 1s linear infinite;display:inline-block;}')
        .appendTo('head');

    /* ======================================================================
     * Initialization
     * ==================================================================== */

    $(document).ready(function () {
        initProductAutocomplete();
        initBoostHandlers();
        initBannerHandlers();
        initSynonymHandlers();
        initCorrelationHandlers();
        initCacheHandlers();
    });

})(jQuery);
