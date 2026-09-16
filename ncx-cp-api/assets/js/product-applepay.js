/**
 * NCX CopyAndPay – Apple Pay express buy on single product pages.
 *
 * Isolated from checkout-inline.js / checkout-blocks.js so checkout flows are unchanged.
 */
(function ($) {
    'use strict';

    var settings = window.ncxCpProductApplePay || {};
    var state = {
        checkoutId: null,
        widgetLoading: false,
        widgetReady: false,
        variationId: 0,
    };

    // Returns true when Apple Pay is enabled in the localized settings payload.
    function isApplePayEnabled() {
        var ap = settings.applePay || {};
        return ap.enabled === '1' || ap.enabled === 1 || ap.enabled === true;
    }

    // Returns true when this browser/device can present Apple Pay (Safari + Wallet).
    function isApplePayDeviceSupported() {
        if (typeof window.ApplePaySession === 'undefined') {
            return false;
        }
        try {
            return !!window.ApplePaySession.canMakePayments();
        } catch (e) {
            return false;
        }
    }

    // True when gateway Apple Pay is on and the shopper's device can use it.
    function shouldShowApplePayTrigger() {
        return isApplePayEnabled()
            && isApplePayDeviceSupported()
            && settings.isLoggedIn === '1'
            && settings.profileComplete === '1';
    }

    // Writes debug lines to the browser console when available.
    function log() {
        if (settings.consoleLogging !== '1') {
            return;
        }
        if (window.console && window.console.log) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[NCX-Product-AP]');
            window.console.log.apply(window.console, args);
        }
    }

    // Posts a lightweight Apple Pay client event to the server for diagnostics.
    function sendApplePayClientLog(event, details) {
        var ap = settings.applePay || {};
        if (!ap.clientLogUrl || (settings.applePayClientLog !== '1' && event !== 'productOnCancel')) {
            return;
        }

        return $.ajax({
            url: ap.clientLogUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                security: ap.nonce || settings.nonce,
                checkout_id: state.checkoutId || '',
                event: event,
                details: JSON.stringify(details || {}),
            },
        });
    }

    // Reads quantity and variation data from the product add-to-cart form.
    function getProductFormPayload() {
        var $form = $('form.cart');
        var payload = {
            security: settings.nonce,
            product_id: settings.productId,
            quantity: 1,
            variation_id: 0,
            variation: {},
            wallet_mode: 'intent',
        };

        if ($form.length) {
            var qty = parseInt($form.find('input.qty').val(), 10);
            if (!isNaN(qty) && qty > 0) {
                payload.quantity = qty;
            }

            var variationId = parseInt($form.find('input[name="variation_id"]').val(), 10);
            if (!isNaN(variationId) && variationId > 0) {
                payload.variation_id = variationId;
            }

            $form.find('.variations select').each(function () {
                var name = $(this).attr('name');
                if (name) {
                    payload.variation[name] = $(this).val() || '';
                }
            });
        }

        if (state.variationId > 0) {
            payload.variation_id = state.variationId;
        }

        return payload;
    }

    // Shows an inline error above the product Apple Pay area.
    function showError(message) {
        var $wrap = $('#ncx-cp-product-applepay-wrap');
        $wrap.find('.ncx-cp-product-ap-error').remove();
        if (message) {
            $wrap.prepend(
                $('<p class="ncx-cp-product-ap-error" style="color:#b91c1c;margin:0 0 8px;"></p>').text(message)
            );
        }
    }

    // Clears the Apple Pay pay slot and restores the trigger button.
    function resetProductApplePayUi() {
        state.checkoutId = null;
        state.widgetLoading = false;
        state.widgetReady = false;
        $('#ncx-cp-product-applepay-pay').empty().hide();
        $('#ncx-cp-product-applepay-trigger').show();
        $('#ncx-cp-product-googlepay-wrap').show();
        unloadCopyAndPayWidget();
        renderTriggerButton();
    }

    // Tears down any mounted OPP widget and removes its script tag.
    function unloadCopyAndPayWidget() {
        if (window.wpwl && typeof window.wpwl.unload === 'function') {
            try {
                window.wpwl.unload();
            } catch (e) {
                log('wpwl.unload error', e);
            }
        }

        $('script[src*="paymentWidgets.js"][data-ncx-product-applepay="1"]').remove();
    }

    // Builds wpwlOptions for the product-page Apple Pay-only widget.
    function buildApplePayOnlyWpwlOptions() {
        var ap = settings.applePay || {};
        var applePayConfig = {
            displayName: ap.displayName || '',
            buttonType: ap.buttonType || 'pay',
            buttonStyle: ap.buttonStyle || 'white-outline',
            buttonSource: ap.buttonSource || 'js',
            countryCode: ap.countryCode || 'GB',
            currencyCode: ap.currencyCode || 'GBP',
            merchantCapabilities: ap.merchantCapabilities || ['supports3DS'],
            supportedNetworks: ap.supportedNetworks || ['visa', 'masterCard', 'amex'],
            total: {
                label: ap.totalLabel || ap.displayName || 'Total',
            },
        };

        if (ap.requiredBillingContactFields) {
            applePayConfig.requiredBillingContactFields = ap.requiredBillingContactFields;
        }
        if (ap.requiredShippingContactFields && ap.requiredShippingContactFields.length) {
            applePayConfig.requiredShippingContactFields = ap.requiredShippingContactFields;
        }

        applePayConfig.onCancel = function () {
            log('Apple Pay onCancel');
            var request = sendApplePayClientLog('productOnCancel', {});
            if (request && typeof request.always === 'function') {
                request.always(resetProductApplePayUi);
            } else {
                resetProductApplePayUi();
            }
        };

        return {
            applePay: applePayConfig,
            // Suppress OPP's own large spin.js loader; the compact
            // "Preparing Apple Pay…" row is the only loader we show.
            spinner: {
                lines: 1,
                length: 0,
                width: 0,
                radius: 0,
                scale: 0,
                corners: 0,
                speed: 1,
                color: 'transparent',
                opacity: 0
            },
            onReady: function () {
                state.widgetReady = true;
                $('#ncx-cp-product-applepay-pay .ncx-cp-applepay-preparing').remove();
                log('Apple Pay widget ready');
            },
            onError: function (error) {
                error = error || {};
                log('Apple Pay onError', error);
                sendApplePayClientLog('productOnError', error);
                showError('Apple Pay error. Please try again or use checkout.');
                resetProductApplePayUi();
            },
        };
    }

    // Mounts the OPP Apple Pay widget into the product-page pay slot.
    function mountApplePayWidget(checkoutId, regionHost) {
        var $slot = $('#ncx-cp-product-applepay-pay');
        if (!$slot.length) {
            return;
        }

        regionHost = regionHost || settings.regionHost;
        state.checkoutId = checkoutId;
        state.widgetLoading = true;
        state.widgetReady = false;

        $slot.show().html('<p class="ncx-cp-applepay-preparing">Preparing Apple Pay\u2026</p>');

        unloadCopyAndPayWidget();
        window.wpwlOptions = buildApplePayOnlyWpwlOptions();

        var ap = settings.applePay || {};
        var $form = $('<form class="paymentWidgets"></form>');
        $form.attr('data-brands', ap.brand || 'APPLEPAY');
        $slot.append($form);
        $slot.append('<p class="ncx-cp-applepay-back"><button type="button" class="button ncx-cp-product-back-to-trigger">Cancel</button></p>');

        var script = document.createElement('script');
        script.src = regionHost + '/v1/paymentWidgets.js?checkoutId=' + encodeURIComponent(checkoutId);
        script.async = false;
        script.setAttribute('data-ncx-product-applepay', '1');
        script.onload = function () {
            state.widgetLoading = false;
            sendApplePayClientLog('productWidgetLoaded', { checkoutId: checkoutId });
        };
        script.onerror = function () {
            showError('Unable to load Apple Pay. Please refresh and try again.');
            resetProductApplePayUi();
            sendApplePayClientLog('productWidgetLoadError', { checkoutId: checkoutId });
        };
        document.head.appendChild(script);
    }

    // Requests a product buy-now order + Apple Pay checkout session from the server.
    function requestProductApplePaySession() {
        if (state.widgetLoading) {
            return;
        }

        showError('');
        state.widgetLoading = true;
        $('#ncx-cp-product-googlepay-wrap').hide();

        var payload = getProductFormPayload();

        $.ajax({
            url: settings.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: payload,
        }).done(function (response) {
            state.widgetLoading = false;
            if (!response || !response.success || !response.data || !response.data.checkoutId) {
                var message = (response && response.data && response.data.message)
                    ? response.data.message
                    : 'Could not prepare Apple Pay.';
                if (response && response.data && response.data.redirect) {
                    window.location.href = response.data.redirect;
                    return;
                }
                showError(message);
                $('#ncx-cp-product-googlepay-wrap').show();
                return;
            }

            $('#ncx-cp-product-applepay-trigger').hide();
            mountApplePayWidget(response.data.checkoutId, response.data.regionHost || settings.regionHost);
        }).fail(function (xhr) {
            state.widgetLoading = false;
            var message = 'Could not prepare Apple Pay.';
            try {
                var json = xhr.responseJSON;
                if (json && json.data) {
                    if (json.data.redirect) {
                        window.location.href = json.data.redirect;
                        return;
                    }
                    if (json.data.message) {
                        message = json.data.message;
                    }
                }
            } catch (e) {}
            showError(message);
            $('#ncx-cp-product-googlepay-wrap').show();
        });
    }

    // Renders the Apple Pay trigger button that starts the express buy.
    function renderTriggerButton() {
        var $trigger = $('#ncx-cp-product-applepay-trigger');
        if (!$trigger.length || !shouldShowApplePayTrigger()) {
            $('#ncx-cp-product-applepay-wrap').hide();
            return;
        }

        $('#ncx-cp-product-applepay-wrap').show();

        if (settings.isVariable === '1' && state.variationId <= 0) {
            $trigger.html(
                '<p class="ncx-cp-inline-note" style="margin:8px 0 0;">Choose product options to use Apple Pay.</p>'
            );
            return;
        }

        // Product pages use the full-width button rather than the compact mark the
        // checkout renders, so both wallets read as a matched pair beside the cart form.
        var appleLogo = '<svg viewBox="0 0 20 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
            + '<path fill="currentColor" d="M16.3 12.7c0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.8-2-1.6-.2-3.1.9-3.9.9-.8 0-2-.9-3.3-.9-1.7 0-3.3 1-4.1 2.5-1.8 3.1-.5 7.6 1.3 10.1.9 1.2 1.9 2.6 3.2 2.5 1.3-.1 1.8-.8 3.3-.8 1.5 0 2 .8 3.3.8 1.4 0 2.2-1.2 3.1-2.4 1-1.4 1.4-2.7 1.4-2.8-.1 0-2.7-1-2.7-4.1zM13.8 4.1c.7-.8 1.2-2 1-3.1-1 0-2.2.7-2.9 1.5-.6.7-1.2 1.9-1 3 1.1.1 2.2-.6 2.9-1.4z"/>'
            + '</svg>';

        $trigger.html(
            '<button type="button" class="ncx-cp-applepay-trigger" id="ncx-cp-product-start-applepay" aria-label="Pay with Apple Pay">'
            + 'Pay with ' + appleLogo + 'Pay'
            + '</button>'
        );
    }

    // Initialises product-page Apple Pay UI when the script loads.
    function init() {
        if (!shouldShowApplePayTrigger() || !$('#ncx-cp-product-applepay-wrap').length) {
            return;
        }

        renderTriggerButton();

        var $form = $('form.cart');
        if (settings.isVariable === '1' && $form.length) {
            $form.on('found_variation', function (event, variation) {
                state.variationId = variation && variation.variation_id ? parseInt(variation.variation_id, 10) : 0;
                renderTriggerButton();
            });
            $form.on('reset_data hide_variation', function () {
                state.variationId = 0;
                renderTriggerButton();
            });
        }
    }

    $(document).on('click', '#ncx-cp-product-start-applepay', function (event) {
        event.preventDefault();
        if (settings.isVariable === '1' && state.variationId <= 0) {
            showError('Please choose product options before using Apple Pay.');
            return;
        }
        requestProductApplePaySession();
    });

    $(document).on('click', '.ncx-cp-product-back-to-trigger', function (event) {
        event.preventDefault();
        resetProductApplePayUi();
    });

    $(function () {
        init();
    });
}(jQuery));
