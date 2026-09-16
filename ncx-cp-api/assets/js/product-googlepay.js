/**
 * NCX CopyAndPay – Google Pay express buy on single product pages.
 *
 * Isolated from checkout-inline.js / checkout-blocks.js so checkout flows are unchanged.
 */
(function ($) {
    'use strict';

    var settings = window.ncxCpProductGooglePay || {};
    var state = {
        checkoutId: null,
        widgetLoading: false,
        widgetReady: false,
        variationId: 0,
        requestGeneration: 0,
    };

    // Returns true when Google Pay is enabled in the localized settings payload.
    function isGooglePayEnabled() {
        var ap = settings.googlePay || {};
        return ap.enabled === '1' || ap.enabled === 1 || ap.enabled === true;
    }

    // Google Pay does not expose a synchronous browser-global readiness API.
    // OPP performs the definitive isReadyToPay check when its widget loads.
    function isGooglePayDeviceSupported() {
        return window.isSecureContext
            || window.location.hostname === 'localhost'
            || window.location.hostname === '127.0.0.1';
    }

    // True when gateway Google Pay is on and the shopper's device can use it.
    function shouldShowGooglePayTrigger() {
        return isGooglePayEnabled()
            && isGooglePayDeviceSupported()
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
            args.unshift('[NCX-Product-GP]');
            window.console.log.apply(window.console, args);
        }
    }

    // Posts a lightweight Google Pay client event to the server for diagnostics.
    function sendGooglePayClientLog(event, details) {
        var ap = settings.googlePay || {};
        if (!ap.clientLogUrl || (settings.googlePayClientLog !== '1' && event !== 'productOnCancel')) {
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

    // Shows an inline error above the product Google Pay area.
    function showError(message) {
        var $wrap = $('#ncx-cp-product-googlepay-wrap');
        $wrap.find('.ncx-cp-product-ap-error').remove();
        if (message) {
            $wrap.prepend(
                $('<p class="ncx-cp-product-ap-error" style="color:#b91c1c;margin:0 0 8px;"></p>').text(message)
            );
        }
    }

    // Clears the Google Pay pay slot and restores the trigger button.
    function resetProductGooglePayUi() {
        state.requestGeneration++;
        state.checkoutId = null;
        state.widgetLoading = false;
        state.widgetReady = false;
        $('#ncx-cp-product-googlepay-pay').empty().hide();
        $('#ncx-cp-product-googlepay-trigger').show();
        $('#ncx-cp-product-applepay-wrap').show();
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

        $('script[src*="paymentWidgets.js"][data-ncx-product-googlepay="1"]').remove();
    }

    // Builds wpwlOptions for the product-page Google Pay-only widget.
    function buildGooglePayOnlyWpwlOptions() {
        var ap = settings.googlePay || {};
        var googlePayConfig = {
            environment: ap.environment || 'TEST',
            merchantId: ap.merchantId || '',
            gatewayMerchantId: ap.gatewayMerchantId || '',
            merchantName: ap.merchantName || ap.displayName || '',
            allowedAuthMethods: ap.allowedAuthMethods || ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
            allowedCardNetworks: ap.allowedCardNetworks || ['MASTERCARD', 'VISA'],
            buttonColor: ap.buttonColor || 'white',
            buttonType: ap.buttonType || 'pay',
            buttonSizeMode: ap.buttonSizeMode || 'fill',
            billingAddressRequired: ap.billingAddressRequired !== false,
            billingAddressParameters: ap.billingAddressParameters || { format: 'FULL', phoneNumberRequired: true },
        };

        googlePayConfig.onCancel = function () {
            log('Google Pay onCancel');
            var request = sendGooglePayClientLog('productOnCancel', {});
            if (request && typeof request.always === 'function') {
                request.always(resetProductGooglePayUi);
            } else {
                resetProductGooglePayUi();
            }
        };

        return {
            googlePay: googlePayConfig,
            // Suppress OPP's own large spin.js loader; the compact
            // "Preparing Google Pay…" row is the only loader we show.
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
                $('#ncx-cp-product-googlepay-pay .ncx-cp-googlepay-preparing').remove();
                log('Google Pay widget ready');
            },
            onError: function (error) {
                error = error || {};
                log('Google Pay onError', error);
                sendGooglePayClientLog('productOnError', error);
                showError('Google Pay error. Please try again or use checkout.');
                resetProductGooglePayUi();
            },
        };
    }

    // Mounts the OPP Google Pay widget into the product-page pay slot.
    function mountGooglePayWidget(checkoutId, regionHost) {
        var $slot = $('#ncx-cp-product-googlepay-pay');
        if (!$slot.length) {
            return;
        }

        regionHost = regionHost || settings.regionHost;
        state.checkoutId = checkoutId;
        state.widgetLoading = true;
        state.widgetReady = false;

        $slot.show().html('<p class="ncx-cp-googlepay-preparing">Preparing Google Pay\u2026</p>');

        unloadCopyAndPayWidget();
        window.wpwlOptions = buildGooglePayOnlyWpwlOptions();

        var ap = settings.googlePay || {};
        var $form = $('<form class="paymentWidgets"></form>');
        $form.attr('data-brands', ap.brand || 'GOOGLEPAY');
        $slot.append($form);
        $slot.append('<p class="ncx-cp-googlepay-back"><button type="button" class="button ncx-cp-product-googlepay-back">Cancel</button></p>');

        var script = document.createElement('script');
        script.src = regionHost + '/v1/paymentWidgets.js?checkoutId=' + encodeURIComponent(checkoutId);
        script.async = false;
        script.setAttribute('data-ncx-product-googlepay', '1');
        script.onload = function () {
            state.widgetLoading = false;
            sendGooglePayClientLog('productWidgetLoaded', { checkoutId: checkoutId });
        };
        script.onerror = function () {
            showError('Unable to load Google Pay. Please refresh and try again.');
            resetProductGooglePayUi();
            sendGooglePayClientLog('productWidgetLoadError', { checkoutId: checkoutId });
        };
        document.head.appendChild(script);
    }

    // Requests a product buy-now order + Google Pay checkout session from the server.
    function requestProductGooglePaySession() {
        if (state.widgetLoading) {
            return;
        }

        showError('');
        state.widgetLoading = true;
        $('#ncx-cp-product-applepay-wrap').hide();
        var requestGeneration = ++state.requestGeneration;

        var payload = getProductFormPayload();

        $.ajax({
            url: settings.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: payload,
        }).done(function (response) {
            if (requestGeneration !== state.requestGeneration) {
                return;
            }
            state.widgetLoading = false;
            if (!response || !response.success || !response.data || !response.data.checkoutId) {
                var message = (response && response.data && response.data.message)
                    ? response.data.message
                    : 'Could not prepare Google Pay.';
                if (response && response.data && response.data.redirect) {
                    window.location.href = response.data.redirect;
                    return;
                }
                showError(message);
                $('#ncx-cp-product-applepay-wrap').show();
                return;
            }

            $('#ncx-cp-product-googlepay-trigger').hide();
            mountGooglePayWidget(response.data.checkoutId, response.data.regionHost || settings.regionHost);
        }).fail(function (xhr) {
            if (requestGeneration !== state.requestGeneration) {
                return;
            }
            state.widgetLoading = false;
            var message = 'Could not prepare Google Pay.';
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
            $('#ncx-cp-product-applepay-wrap').show();
        });
    }

    // Renders the custom "Buy Now with Google Pay" trigger button.
    function renderTriggerButton() {
        var $trigger = $('#ncx-cp-product-googlepay-trigger');
        if (!$trigger.length || !shouldShowGooglePayTrigger()) {
            $('#ncx-cp-product-googlepay-wrap').hide();
            return;
        }

        $('#ncx-cp-product-googlepay-wrap').show();

        if (settings.isVariable === '1' && state.variationId <= 0) {
            $trigger.html(
                '<p class="ncx-cp-inline-note" style="margin:8px 0 0;">Choose product options to use Google Pay.</p>'
            );
            return;
        }

        var googlePayLogo = '<svg viewBox="0 0 130 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
            + '<g transform="translate(-3.652,-3.652) scale(0.913)">'
            + '<path fill="#FFC107" d="M43.6 20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3 0 5.7 1.1 7.8 3l5.7-5.7C34 6 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20c11.7 0 19.4-8.2 19.4-19.7 0-1.5-.2-2.9-.4-4.3z"/>'
            + '<path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3 0 5.7 1.1 7.8 3l5.7-5.7C34 6 29.3 4 24 4c-7.7 0-14.4 4.4-17.7 10.7z"/>'
            + '<path fill="#4CAF50" d="M24 44c5.1 0 9.9-1.9 13.4-5.1l-6.2-5.2C29.2 35.2 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.5 16.2 44 24 44z"/>'
            + '<path fill="#1976D2" d="M43.6 20H24v8h11.3c-.8 2.4-2.3 4.3-4.2 5.7l6.2 5.2c3.6-3.3 6.1-8.3 6.1-14.6 0-1.5-.1-2.9-.4-4.3z"/>'
            + '</g>'
            + '<text x="42.2" y="36.6" textLength="87.8" lengthAdjust="spacingAndGlyphs" fill="currentColor" font-family="Arial, sans-serif" font-size="51">Pay</text>'
            + '</svg>';

        $trigger.html(
            '<button type="button" class="ncx-cp-googlepay-trigger" id="ncx-cp-product-start-googlepay" aria-label="Pay with Google Pay">'
            + 'Pay with ' + googlePayLogo
            + '</button>'
        );
    }

    // Initialises product-page Google Pay UI when the script loads.
    function init() {
        if (!shouldShowGooglePayTrigger() || !$('#ncx-cp-product-googlepay-wrap').length) {
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

    $(document).on('click', '#ncx-cp-product-start-googlepay', function (event) {
        event.preventDefault();
        if (settings.isVariable === '1' && state.variationId <= 0) {
            showError('Please choose product options before using Google Pay.');
            return;
        }
        requestProductGooglePaySession();
    });

    $(document).on('click', '.ncx-cp-product-googlepay-back', function (event) {
        event.preventDefault();
        resetProductGooglePayUi();
    });

    $(function () {
        init();
    });
}(jQuery));
