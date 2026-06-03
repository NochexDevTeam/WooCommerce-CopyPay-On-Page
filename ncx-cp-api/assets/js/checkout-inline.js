/**
 * NCX CopyAndPay – Checkout Inline JS
 *
 * Mirrors nochexapi's nochexapi-cardsv2.js pattern:
 *   Phase 1 (page load / updated_checkout): AJAX creates checkout session from cart → mount OPP widget.
 *   Phase 2 (Place Order): intercept via 'checkout_place_order' (generic event, same as nochexapi),
 *           fetch() to WC AJAX → server binds order data → JS calls wpwl.executePayment().
 */
(function ($) {
    'use strict';

    if (typeof $ === 'undefined') {
        console.error('NCX: jQuery not available');
        return;
    }

    var settings = window.ncxCpInline || {};
    var state = {
        checkoutId: null,
        widgetReady: false,
        widgetLoading: false,
        submitting: false,
        paymentContainer: 'card',
        paymentMode: 'registration',
    };

    function syncPaymentContainerHidden() {
        var field = document.getElementById('ncx_cp_payment_container');
        if (field) {
            field.value = state.paymentContainer;
        }
    }

    function setPaymentContainer(kind) {
        state.paymentContainer = kind === 'registration' ? 'registration' : 'card';
        syncPaymentContainerHidden();
    }

    function getWpwlScope() {
        var frame = document.getElementById('ncx-cp-inline-frame');
        return frame || document;
    }

    function isVisible(el) {
        if (!el) {
            return false;
        }
        var style = window.getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden') {
            return false;
        }
        return el.offsetParent !== null;
    }

    function getSelectedRegistrationGroup(scope) {
        var selected = scope.querySelector('.wpwl-group-registration.wpwl-selected');
        if (selected) {
            return selected;
        }
        var groups = scope.querySelectorAll('.wpwl-group-registration');
        if (groups.length === 1) {
            return groups[0];
        }
        return null;
    }

    function registrationCvvHasValue(scope) {
        var group = getSelectedRegistrationGroup(scope);
        if (!group) {
            return false;
        }
        var cvv = group.querySelector('input.wpwl-control-cvv');
        return !!(cvv && String(cvv.value || '').trim().length > 0);
    }

    function isRegistrationPaymentIntent(scope) {
        if (!scope.querySelector('form.wpwl-form-registrations')) {
            return false;
        }

        var active = document.activeElement;
        if (active && scope.contains(active)) {
            if (active.closest('form.wpwl-form-card, .wpwl-container-card')) {
                return false;
            }
            if (active.closest('.wpwl-form-registrations, .wpwl-container-registration, .wpwl-group-registration')) {
                return true;
            }
        }

        if (registrationCvvHasValue(scope)) {
            return true;
        }

        if (state.paymentMode === 'card') {
            return false;
        }

        if (scope.querySelector('.wpwl-group-registration.wpwl-selected')) {
            return true;
        }
        if (scope.querySelector('form.wpwl-form-registrations input[type="radio"]:checked')) {
            return true;
        }

        return state.paymentMode === 'registration';
    }

    function getPaymentContainerKind(scope) {
        if (!scope.querySelector('form.wpwl-form-registrations')) {
            return 'card';
        }
        if (isRegistrationPaymentIntent(scope)) {
            return 'registration';
        }

        var cardContainer = scope.querySelector('.wpwl-container-card');
        var regContainer = scope.querySelector('.wpwl-container-registration');
        var cardVisible = isVisible(cardContainer);
        var regVisible = isVisible(regContainer);

        if (cardVisible && !regVisible) {
            return 'card';
        }
        if (regVisible && !cardVisible) {
            return 'registration';
        }
        if (cardVisible && regVisible) {
            return state.paymentMode === 'registration' ? 'registration' : 'card';
        }
        return state.paymentMode === 'registration' ? 'registration' : 'card';
    }

    function applyPaymentContainerKind(kind) {
        if (kind === 'registration') {
            state.paymentMode = 'registration';
        }
        setPaymentContainer(kind);
        return kind === 'registration' ? 'wpwl-container-registration' : 'wpwl-container-card';
    }

    /**
     * Resolve which OPP container is active (card vs saved registration).
     */
    function resolveActiveWpwlContainer() {
        return applyPaymentContainerKind(getPaymentContainerKind(getWpwlScope()));
    }

    function getExecutePaymentContainer() {
        return applyPaymentContainerKind(getPaymentContainerKind(getWpwlScope()));
    }

    function scheduleContainerResolve() {
        resolveActiveWpwlContainer();
        setTimeout(resolveActiveWpwlContainer, 150);
    }

    function initPaymentContainerTracking() {
        var frame = document.getElementById('ncx-cp-inline-frame');
        if (!frame) {
            return;
        }

        var legacyBtn = frame.querySelector('#ncx-container-change');
        if (legacyBtn) {
            legacyBtn.parentNode.removeChild(legacyBtn);
        }

        if (!frame.querySelector('form.wpwl-form-registrations')) {
            state.paymentMode = 'card';
            setPaymentContainer('card');
            return;
        }

        state.paymentMode = 'registration';
        resolveActiveWpwlContainer();

        $(frame).off('click.ncxContainerSync');

        $(frame).on('click.ncxContainerSync', '[data-action="show-initial-forms"]', function () {
            state.paymentMode = 'card';
            scheduleContainerResolve();
        });

        $(frame).on('click.ncxContainerSync', '.wpwl-group-registration, label.wpwl-registration, .wpwl-wrapper-registration-registrationId input', function () {
            state.paymentMode = 'registration';
            scheduleContainerResolve();
        });

        $(frame).on('change.ncxContainerSync', 'form.wpwl-form-registrations input[type="radio"]', function () {
            state.paymentMode = 'registration';
            scheduleContainerResolve();
        });

        $(frame).on('input.ncxContainerSync', '.wpwl-group-registration input.wpwl-control-cvv', function () {
            state.paymentMode = 'registration';
        });

        $(frame).on('focusin.ncxContainerSync', function (ev) {
            var target = ev.target;
            if (!target || !target.closest) {
                return;
            }
            if (target.closest('form.wpwl-form-card, .wpwl-container-card')) {
                state.paymentMode = 'card';
            } else if (target.closest('.wpwl-form-registrations, .wpwl-container-registration, .wpwl-group-registration')) {
                state.paymentMode = 'registration';
            }
        });

        $(frame).on('click.ncxContainerSync', '.wpwl-button-pay', function (ev) {
            var btn = ev.currentTarget;
            if (btn.getAttribute('data-action') === 'show-initial-forms') {
                return;
            }
            if (btn.closest('.wpwl-form-registrations, .wpwl-container-registration')) {
                state.paymentMode = 'registration';
                scheduleContainerResolve();
            }
        });
    }

    function toBool(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    settings.createRegistration = toBool(settings.createRegistration);
    settings.allowCardSaving = toBool(settings.allowCardSaving);
    settings.loggedIn = toBool(settings.loggedIn);

    function buildIframePlaceholderStyles() {
        var t = settings.typography || {};
        return {
            color: t.placeholder || '#9ca3af',
            'font-size': t.sizeInput || '16px',
            'font-family': t.fontFamily || 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        };
    }

    /* ── Logging ─────────────────────────────────────────── */
    function log() {
        if (window.console && window.console.log) {
            try {
                var args = Array.prototype.slice.call(arguments);
                window.console.log.apply(window.console, args);
            } catch (e) {}
        }
    }

    log('NCX: checkout-inline.js loaded', settings);

    /* ── Debug: write status into the frame container ───── */
    function setFrameStatus(msg, isError) {
        var frame = document.getElementById('ncx-cp-inline-frame');
        if (frame) {
            frame.innerHTML = '<p class="ncx-cp-inline-note" style="color:' + (isError ? '#b91c1c' : '#6b7280') + ';">' + msg + '</p>';
        }
    }

    /* ── Widget mounting ─────────────────────────────────── */

    function requestCheckoutId() {
        if (state.submitting) {
            log('NCX: payment in progress, skipping checkout ID request');
            return;
        }
        if (state.widgetLoading) {
            log('NCX: already loading, skipping');
            return;
        }
        if (!settings.ajaxUrl) {
            log('NCX: ajaxUrl missing from settings', settings);
            setFrameStatus('Configuration error: ajaxUrl missing.', true);
            return;
        }
        if (!settings.nonce) {
            log('NCX: nonce missing from settings', settings);
            setFrameStatus('Configuration error: nonce missing.', true);
            return;
        }

        var frame = document.getElementById('ncx-cp-inline-frame');
        if (!frame) {
            log('NCX: #ncx-cp-inline-frame not found in DOM');
            return;
        }

        state.widgetLoading = true;
        state.widgetReady = false;
        setFrameStatus('Requesting checkout session\u2026', false);
        log('NCX: requesting checkout ID via', settings.ajaxUrl);

        $.ajax({
            url: settings.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ncx_cp_request_checkout_id',
                security: settings.nonce,
            },
            timeout: 30000,
        })
        .done(function (response) {
            log('NCX: AJAX response', response);
            if (response && response.success && response.data && response.data.checkoutId) {
                if (settings.environment && response.data.environment && response.data.environment !== settings.environment) {
                    log('NCX: checkout environment mismatch', response.data.environment, settings.environment);
                    setFrameStatus('Payment settings changed. Please refresh the page and try again.', true);
                    return;
                }
                state.checkoutId = response.data.checkoutId;
                log('NCX: checkout ID received', state.checkoutId);
                mountWidget(state.checkoutId);
            } else {
                var msg = 'Unknown error';
                if (response && response.data) {
                    if (typeof response.data === 'string') {
                        msg = response.data;
                    } else if (response.data.message) {
                        msg = response.data.message;
                    } else if (response.data[0]) {
                        msg = response.data[0];
                    }
                }
                log('NCX: checkout ID request failed –', msg);
                setFrameStatus('Card form error: ' + msg, true);
            }
        })
        .fail(function (xhr, textStatus, errorThrown) {
            log('NCX: AJAX failed', textStatus, errorThrown, 'HTTP', xhr.status, xhr.responseText);
            var detail = textStatus;
            if (xhr.status === 0) {
                detail = 'Network error (blocked or offline)';
            } else if (xhr.status === 403) {
                detail = 'Nonce expired – please refresh the page';
            } else if (xhr.status === 400 || xhr.status === 500) {
                detail = 'Server error (' + xhr.status + ')';
            }
            setFrameStatus('Card form error: ' + detail + '. Please refresh the page.', true);
        })
        .always(function () {
            state.widgetLoading = false;
        });
    }

    /**
     * Mount the OPP COPYandPAY widget.
     */
    function mountWidget(checkoutId) {
        var frame = document.getElementById('ncx-cp-inline-frame');
        if (!frame) {
            log('NCX: #ncx-cp-inline-frame not found');
            return;
        }
        if (!settings.regionHost) {
            log('NCX: regionHost not set');
            setFrameStatus('Configuration error: regionHost missing.', true);
            return;
        }

        log('NCX: mounting widget for', checkoutId, 'from', settings.regionHost);

        // Clear previous widget.
        frame.innerHTML = '';

        // Unload previous wpwl instance.
        if (window.wpwl && typeof window.wpwl.unload === 'function') {
            try { window.wpwl.unload(); } catch (e) { log('NCX: wpwl.unload error', e); }
        }

        // Remove old paymentWidgets.js scripts.
        var oldScripts = document.querySelectorAll('script[src*="paymentWidgets.js"]');
        for (var i = 0; i < oldScripts.length; i++) {
            oldScripts[i].parentNode.removeChild(oldScripts[i]);
        }

        // Set hidden field.
        var hiddenField = document.getElementById('ncx_cp_checkout_id');
        if (hiddenField) {
            hiddenField.value = checkoutId;
        }

        // Configure wpwlOptions BEFORE loading the script (matches nochexapi).
        window.wpwlOptions = {
            registrations: {
                requireCvv: true,
                hideInitialPaymentForms: true
            },
            iframeStyles: {
                'card-number-placeholder': buildIframePlaceholderStyles(),
                'cvv-placeholder': buildIframePlaceholderStyles(),
            },
            style: 'plain',
            disableSubmitOnEnter: true,
            showLabels: false,
            brandDetection: true,
            brandDetectionType: 'regex',
            requireCvv: true,
            showCVVHint: false,
            spinner: {
                color: '#2095ee',
                lines: 18,
                length: 5,
                width: 2,
                radius: 7,
                scale: 3.6,
                corners: 1,
                speed: 2.2
            },
            onReady: function () {
                state.widgetReady = true;
                log('NCX: OPP widget ready – card fields visible');

                var groupCN  = document.querySelector('.wpwl-group-cardNumber');
                var groupExp = document.querySelector('.wpwl-group-expiry');
                var groupCVV = document.querySelector('.wpwl-group-cvv');

                // Build connected single-row card input (Stripe-style).
                if (groupCN && groupExp && groupCVV) {
                    var form = groupCN.parentNode;

                    // Single label above the row.
                    var label = document.createElement('label');
                    label.className = 'ncx-cp-frame-label';
                    label.innerHTML = 'Enter card details';
                    form.insertBefore(label, groupCN);

                    // Create flex row container and move groups into it.
                    var row = document.createElement('div');
                    row.className = 'ncx-cp-card-row';
                    form.insertBefore(row, groupCN);
                    row.appendChild(groupCN);
                    row.appendChild(groupExp);
                    row.appendChild(groupCVV);
                }

                if (document.querySelector('form.wpwl-form-registrations')) {
                    state.paymentMode = 'registration';
                }

                initPaymentContainerTracking();

                // "Save card" checkbox for logged-in customers (no name=createRegistration — server uses hidden field).
                var $cardForm = $('form.wpwl-form-card');
                if (settings.allowCardSaving && settings.createRegistration && settings.loggedIn && $cardForm.length) {
                    var saveHtml = '<p class="ncx-cp-save-card">';
                    saveHtml += '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
                    saveHtml += '<input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" type="checkbox" id="ncxSaveCardConsent" value="true"> <span>Securely store this card?</span>';
                    saveHtml += '</label></p>';
                    $cardForm.find('.ncx-cp-card-row').after(saveHtml);

                    var consentInput = document.getElementById('ncx_cp_save_card_intent');
                    var consentCheckbox = document.getElementById('ncxSaveCardConsent');
                    if (consentInput && consentCheckbox) {
                        consentCheckbox.addEventListener('change', function () {
                            consentInput.value = consentCheckbox.checked ? '1' : '0';
                        });
                    }
                }

            },
            onReadyIframeCommunication: function () {
                // Inject dynamic brand icon container (matches nochexapi).
                var iframeField = this.$iframe[0];
                if (iframeField && iframeField.classList.contains('wpwl-control-cardNumber')) {
                    var ccNoContainer = iframeField.parentNode;
                    if (!document.getElementById('wpwlDynBrand')) {
                        var brandContainer = document.createElement('div');
                        brandContainer.id = 'wpwlDynBrand';
                        var dynBrandImg = document.createElement('img');
                        dynBrandImg.id = 'wpwlDynBrandImg';
                        dynBrandImg.style.cssText = 'max-height:22px;border-radius:unset;';
                        brandContainer.appendChild(dynBrandImg);
                        ccNoContainer.appendChild(brandContainer);
                    }
                }
            },
            onDetectBrand: function (brands) {
                // Update the dynamic brand icon.
                var img = document.getElementById('wpwlDynBrandImg');
                if (img) {
                    img.style.display = (brands.length > 0) ? 'block' : 'none';
                }
            },
            onAfterSubmit: function () {
                log('NCX: onAfterSubmit – payment submitted, awaiting redirect');
                return true;
            },
            onLoadThreeDIframe: function () {
                log('NCX: 3DS iframe loaded – challenge in progress');
            },
            onError: function (error) {
                log('NCX: widget error', error);
                state.submitting = false;
                unblockForm();
                if (error && error.name === 'InvalidCheckoutIdError') {
                    log('NCX: checkout expired, requesting new one');
                    requestCheckoutId();
                }
            },
        };

        // Create the form element FIRST (OPP looks for .paymentWidgets in DOM).
        var formEl = document.createElement('form');
        formEl.className = 'paymentWidgets';
        formEl.setAttribute('data-brands', settings.brands || 'VISA MASTER');
        frame.appendChild(formEl);

        // Load OPP paymentWidgets.js.
        var scriptUrl = settings.regionHost + '/v1/paymentWidgets.js?checkoutId=' + encodeURIComponent(checkoutId);
        log('NCX: loading', scriptUrl);
        var script = document.createElement('script');
        script.src = scriptUrl;
        script.async = true;
        script.onerror = function () {
            log('NCX: paymentWidgets.js failed to load');
            setFrameStatus('Unable to load card form script. Check your network connection.', true);
        };
        document.body.appendChild(script);
    }

    /* ── Form submission interception ────────────────────── */
    /*
     * Binds to the GENERIC 'checkout_place_order' event – same as nochexapi.
     * nochexapi-cardsv2.js L152: checkout_form.on('checkout_place_order', nochexapiCardsHandoff)
     *
     * Returning false  → prevents WC from submitting (our gateway).
     * Returning undefined → lets WC proceed (other gateways).
     */

    var gatewayId = settings.gatewayId || 'ncx_cp_api';
    log('NCX: will intercept checkout_place_order for gateway', gatewayId);

    /**
     * Submit handler – mirrors nochexapi's fetchOrdernochexapiCards().
     * Uses fetch() like nochexapi to avoid any jQuery.ajax / WC conflict.
     */
    function handlePlaceOrder() {
        // Only intercept if OUR payment method is selected (nochexapi pattern).
        var $form = $('form.woocommerce-checkout');
        if (!$form.length) {
            $form = $('form.checkout');
        }
        var selectedMethod = $form.find('input[name="payment_method"]:checked').val();
        if (selectedMethod !== gatewayId) {
            log('NCX: not our gateway (' + selectedMethod + '), passing through');
            return; // undefined – WC handles other gateways
        }

        log('NCX: Place Order intercepted', {checkoutId: state.checkoutId, widgetReady: state.widgetReady});

        state.submitting = true;

        // Block UI (same as nochexapi: hide Place Order, show processing text).
        $form.addClass('processing');
        $('#place_order').after('<p id="ncxCpBtnReplace" style="color:#CCC; text-align:center;">Processing, please wait\u2026</p>');
        $('#place_order').hide();

        getExecutePaymentContainer();
        syncPaymentContainerHidden();
        var checkoutData = $form.serialize();
        var checkoutUrl = (window.wc_checkout_params && window.wc_checkout_params.checkout_url)
            ? window.wc_checkout_params.checkout_url
            : '/?wc-ajax=checkout';

        log('NCX: submitting order via fetch() to', checkoutUrl);

        // Use fetch() like nochexapi (avoids any jQuery.ajax interference with WC).
        fetch(checkoutUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            cache: 'no-cache',
            body: checkoutData
        }).then(function (response) {
            log('NCX: fetch response status', response.status);
            return response.json();
        }).then(function (json) {
            log('NCX: checkout response', json);

            // Handle redirect if server returned one (nochexapi pattern).
            if (json.hasOwnProperty('result')) {
                if (json.result === 'success') {
                    // If redirect is not false/empty, follow it (only for non-execute responses).
                    if (json.redirect !== false && json.redirect && !json.execute) {
                        if (-1 === (json.redirect).indexOf('https://') || -1 === (json.redirect).indexOf('http://')) {
                            window.location = decodeURI(json.redirect);
                        } else {
                            window.location = json.redirect;
                        }
                        return false; // stop chain
                    }
                }
            }
            return json;
        }).then(function (json) {
            if (json === false) return false;
            // Remove old WC notices.
            $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
            return json;
        }).then(function (json) {
            if (json === false) return false;
            // Display any messages from the server.
            if (json.hasOwnProperty('messages')) {
                $('form.checkout').prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + json.messages + '</div>');
            }
            return json;
        }).then(function (json) {
            if (json === false) return false;

            $form.removeClass('processing');

            if (json.reload === true) {
                window.location.reload();
                return false;
            } else if (json.refresh === true) {
                $(document.body).trigger('update_checkout');
                $('#ncxCpBtnReplace').remove();
                $('#place_order').show();
                state.submitting = false;
                return false;
            }
            return json;
        }).then(function (json) {
            if (json === false) return;

            if (json.result === 'failure') {
                // Scroll to error notices (same as nochexapi).
                var err = $('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout');
                if (err.length > 0 || (err = $('form.checkout'))) {
                    $.scroll_to_notices(err);
                }
                $('form.checkout').find('.input-text, select, input:checkbox').trigger('validate').blur();
                $(document.body).trigger('checkout_error');
                $('#ncxCpBtnReplace').remove();
                $('#place_order').show();
                state.submitting = false;
            } else if (json.execute) {
                // Success with execute flag – trigger payment (nochexapi: pciFormSubmit).
                log('NCX: payment trigger – executing wpwl');
                executePayment();
                $.scroll_to_notices($('#ncx-cp-inline-frame'));
                $('#ncxCpBtnReplace').remove();
                $('#place_order').show();
            } else {
                // Success without execute – likely a redirect we missed.
                log('NCX: success but no execute flag, checking redirect');
                if (json.redirect) {
                    window.location = json.redirect;
                } else {
                    $('#ncxCpBtnReplace').remove();
                    $('#place_order').show();
                    state.submitting = false;
                }
            }
        }).catch(function (err) {
            log('NCX: fetch error', err);
            $form.removeClass('processing');
            $('#ncxCpBtnReplace').remove();
            $('#place_order').show();
            state.submitting = false;
        });

        return false; // ALWAYS prevent WC default submit for our gateway
    }

    // Bind to GENERIC checkout_place_order – same event nochexapi uses.
    // nochexapi binds inside jQuery(function(){ ... }) i.e. DOM-ready,
    // ensuring the form element exists.  We do the same.
    // nochexapi: checkout_form.on('checkout_place_order', nochexapiCardsHandoff)
    $(function () {
        var $checkoutForm = $('form.woocommerce-checkout');
        if (!$checkoutForm.length) {
            $checkoutForm = $('form.checkout');
        }
        if ($checkoutForm.length) {
            $checkoutForm.on('checkout_place_order', handlePlaceOrder);
            log('NCX: checkout_place_order bound on DOM ready');
        } else {
            log('NCX: checkout form not found on DOM ready, will bind on updated_checkout');
        }
    });

    /**
     * Execute payment via OPP widget.
     * Handles both new card (wpwl-container-card) and saved card (wpwl-container-registration).
     * Mirrors nochexapi's dual-container check.
     * Server returns a real redirect URL as fallback, but when 'execute' is true
     * our JS calls this function instead of following the redirect.
     */
    function executePayment() {
        if (window.wpwl && typeof window.wpwl.executePayment === 'function') {
            try {
                var container = getExecutePaymentContainer();
                log('NCX: executing payment via', container, '(mode=' + state.paymentMode + ', container=' + state.paymentContainer + ')');
                window.wpwl.executePayment(container);
            } catch (e) {
                log('NCX: executePayment error', e);
                state.submitting = false;
                unblockForm();
            }
        } else {
            log('NCX: wpwl.executePayment not available – widget may not have loaded');
            state.submitting = false;
            unblockForm();
        }
    }

    function unblockForm() {
        var $form = $('form.checkout');
        $form.removeClass('processing');
        if (typeof $.fn.unblock === 'function') {
            $form.unblock();
        }
        $('#ncxCpBtnReplace').remove();
        $('#place_order').show();
    }

    /* ── Event bindings ──────────────────────────────────── */

    // Classic checkout: WooCommerce fires this after fragments update.
    $(document.body).on('updated_checkout', function () {
        log('NCX: updated_checkout event fired');
        if (!state.submitting) {
            requestCheckoutId();
        } else {
            log('NCX: skipping – payment in progress');
        }
        // Re-bind to the (possibly recreated) form element.
        // Use form.woocommerce-checkout first (matches nochexapi), fallback to form.checkout.
        var $rebindForm = $('form.woocommerce-checkout');
        if (!$rebindForm.length) {
            $rebindForm = $('form.checkout');
        }
        $rebindForm.off('checkout_place_order', handlePlaceOrder).on('checkout_place_order', handlePlaceOrder);
    });

    // Fallback for page load.
    $(function () {
        log('NCX: DOM ready');
        if (document.getElementById('ncx-cp-inline-frame')) {
            setTimeout(function () {
                if (!state.checkoutId && !state.widgetLoading) {
                    log('NCX: DOM ready fallback – requesting checkout ID');
                    requestCheckoutId();
                }
            }, 1000);
        } else {
            log('NCX: #ncx-cp-inline-frame not found on DOM ready');
        }
    });

    // MutationObserver fallback for block checkout lazy rendering.
    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function () {
            if (document.getElementById('ncx-cp-inline-frame') && !state.checkoutId && !state.widgetLoading) {
                log('NCX: MutationObserver detected #ncx-cp-inline-frame');
                observer.disconnect();
                requestCheckoutId();
            }
        });
        observer.observe(document.body || document.documentElement, { childList: true, subtree: true });
        setTimeout(function () { observer.disconnect(); }, 30000);
    }

})(jQuery);
