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
        // One retry per mount cycle when the provider rejects the session.
        // Without a bound this loops forever against the AJAX endpoint.
        invalidCheckoutRetried: false,
        submitting: false,
        paymentContainer: 'card',
        paymentMode: 'registration',
        applePayCheckoutId: null,
        applePayWidgetLoading: false,
        applePayWidgetReady: false,
        activeWidget: 'card',
        applePayPrefetchedData: null,
        applePayPrefetchPromise: null,
        applePayTotalAtFetch: null,
        applePayRequestSeq: 0,
        googlePayCheckoutId: null,
        googlePayWidgetReady: false,
        googlePayPrefetchedData: null,
        googlePayPrefetchPromise: null,
        googlePayRequestSeq: 0,
        checkoutIdFetchStartedAt: 0,
        inFlightRetries: 0,
        inFlightTimer: null,
    };

    var MIN_PAYMENT_FLOOR = 0.5;
    var CHECKOUT_ID_COALESCE_MS = 2000;
    // The coordinator refuses a new session while a hand-off could still settle.
    // Waiting it out beats stranding the shopper, but the retry must be bounded:
    // only the provider can release the order, and it may never do so.
    var IN_FLIGHT_MAX_RETRIES = 6;
    var IN_FLIGHT_FALLBACK_MS = 15000;

    // Returns the effective minimum amount from settings, never below the 50p floor.
    function getConfiguredMinimumPaymentAmount() {
        var min = parseFloat(settings.minimumPaymentAmount);
        if (isNaN(min) || min < MIN_PAYMENT_FLOOR) {
            return MIN_PAYMENT_FLOOR;
        }
        return min;
    }

    // Returns the server-generated notice shown when payment is blocked for being too small.
    function getMinimumPaymentNotice() {
        if (settings.minimumPaymentMessage) {
            return settings.minimumPaymentMessage;
        }
        return 'Amount less than the minimum is not permitted.';
    }

    // Reads the order review total from the DOM in major currency units.
    function getClassicCheckoutTotal() {
        var totalEl = document.querySelector('.order-total .woocommerce-Price-amount, .order-total .amount');
        if (!totalEl) {
            return null;
        }
        var parsed = parseFloat((totalEl.textContent || '').replace(/[^0-9.\-]/g, ''));
        return isNaN(parsed) ? null : parsed;
    }

    // Returns true when the classic checkout cart total is strictly below the minimum.
    function isCartBelowMinimumPayment() {
        if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.total) {
            var total = parseFloat(String(wc_checkout_params.total).replace(/[^0-9.\-]/g, ''));
            if (!isNaN(total) && total < getConfiguredMinimumPaymentAmount()) {
                return true;
            }
        }
        var domTotal = getClassicCheckoutTotal();
        if (domTotal !== null && domTotal < getConfiguredMinimumPaymentAmount()) {
            return true;
        }
        return false;
    }

    // True when the WooCommerce block checkout is on the page. The block
    // checkout has its own script (checkout-blocks.js); this classic script must
    // not act on its DOM (it renders an element with the same id).
    function isBlockCheckout() {
        return !!document.getElementById('ncx-cp-blocks-container');
    }

    // Keeps the hidden payment-container field aligned with state.paymentContainer.
    function syncPaymentContainerHidden() {
        var field = document.getElementById('ncx_cp_payment_container');
        if (field) {
            field.value = state.paymentContainer;
        }
    }

    // Records whether the shopper is paying with a new card or a saved registration.
    function setPaymentContainer(kind) {
        state.paymentContainer = kind === 'registration' ? 'registration' : 'card';
        syncPaymentContainerHidden();
    }

    // Returns the DOM subtree that currently hosts the active OPP widget.
    function getWpwlScope() {
        var frame = document.getElementById('ncx-cp-inline-frame');
        return frame || document;
    }

    // Returns true when an element is visible in the layout (not hidden or display:none).
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

    // Returns the currently selected saved-card registration group in the OPP widget.
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

    // Returns true when the shopper has entered a CVV for the selected saved card.
    function registrationCvvHasValue(scope) {
        var group = getSelectedRegistrationGroup(scope);
        if (!group) {
            return false;
        }
        var cvv = group.querySelector('input.wpwl-control-cvv');
        return !!(cvv && String(cvv.value || '').trim().length > 0);
    }

    // Detects whether the shopper intends to pay with a saved card registration.
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

    // Resolves whether the active OPP container is card entry or saved registration.
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

    // Applies the chosen container kind and returns the matching wpwl container id.
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

    // Returns the wpwl container id that executePayment should target.
    function getExecutePaymentContainer() {
        return applyPaymentContainerKind(getPaymentContainerKind(getWpwlScope()));
    }

    // Re-reads the active OPP container after shopper interaction with the widget.
    function scheduleContainerResolve() {
        resolveActiveWpwlContainer();
        setTimeout(resolveActiveWpwlContainer, 150);
    }

    // Wires click/focus listeners so card vs saved-card mode stays in sync with OPP.
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

    // Normalises server settings flags that may arrive as strings or numbers into booleans.
    function toBool(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    settings.createRegistration = toBool(settings.createRegistration);
    settings.allowCardSaving = toBool(settings.allowCardSaving);
    settings.loggedIn = toBool(settings.loggedIn);

    // Returns iframe placeholder CSS tokens derived from the gateway typography settings.
    function buildIframePlaceholderStyles() {
        var t = settings.typography || {};
        return {
            color: t.placeholder || '#9ca3af',
            'font-size': t.sizeInput || '16px',
            'font-family': t.fontFamily || 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        };
    }

    // Returns true when Apple Pay is enabled in the gateway settings payload.
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
        return isApplePayEnabled() && isApplePayDeviceSupported();
    }

    // Hides the Apple Pay trigger area on unsupported devices.
    function hideApplePayTriggerArea() {
        var frame = document.getElementById('ncx-cp-applepay-frame');
        if (frame) {
            frame.innerHTML = '';
            frame.style.display = 'none';
        }
    }

    // Posts a lightweight Apple Pay client event to the server for diagnostics.
    function sendApplePayClientLog(event, details, checkoutId) {
        var ap = settings.applePay || {};
        if (!ap.clientLogUrl || (settings.applePayClientLog !== '1' && event !== 'onCancel')) {
            return;
        }
        return $.ajax({
            url: ap.clientLogUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                security: ap.nonce || settings.nonce,
                checkout_id: checkoutId || state.applePayCheckoutId || '',
                event: event,
                details: JSON.stringify(details || {}),
            },
        });
    }

    // Builds wpwlOptions for an Apple Pay-only widget mount (no card fields).
    // OPP spins up its own large spin.js starburst in the middle of the widget
    // container while paymentWidgets.js boots. The compact "Preparing…" row is
    // the only loader we want, so keep OPP's suppressed instead of running two.
    var HIDDEN_WPWL_SPINNER = {
        lines: 1,
        length: 0,
        width: 0,
        radius: 0,
        scale: 0,
        corners: 0,
        speed: 1,
        color: 'transparent',
        opacity: 0
    };

    function buildApplePayOnlyWpwlOptions() {
        if (!isApplePayEnabled()) {
            return {};
        }

        var ap = settings.applePay;
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
            log('NCX: Apple Pay onCancel fired');
            var request = sendApplePayClientLog('onCancel', {});
            if (request && typeof request.always === 'function') {
                request.always(resetApplePayToCard);
            } else {
                resetApplePayToCard();
            }
        };

        return {
            applePay: applePayConfig,
            spinner: HIDDEN_WPWL_SPINNER,
            onReady: function () {
                clearApplePayPreparingMessage();
            },
            onError: function (error) {
                error = error || {};
                log('NCX: Apple Pay wpwl onError fired', error);
                sendApplePayClientLog('onError', {
                    name: error.name,
                    message: typeof error.message === 'object' ? JSON.stringify(error.message) : error.message,
                    brand: error.brand,
                    event: error.event,
                });
            },
        };
    }

    /* ── Logging ─────────────────────────────────────────── */
    // Writes prefixed debug lines to the browser console when logging is enabled.
    function log() {
        if (settings.consoleLogging !== '1') {
            return;
        }
        if (window.ncxCpApiLog) {
            try { window.ncxCpApiLog.apply(window, arguments); } catch (e) {}
            return;
        }
        if (window.console && window.console.log) {
            try {
                window.console.log.apply(window.console, Array.prototype.slice.call(arguments));
            } catch (e) {}
        }
    }

    log('NCX: checkout-inline.js loaded');

    /* ── Debug: write status into the frame container ───── */
    // Renders a status or error message inside the card payment frame.
    function setFrameStatus(msg, isError) {
        var frame = document.getElementById('ncx-cp-inline-frame');
        if (frame) {
            frame.innerHTML = '<p class="ncx-cp-inline-note" style="color:' + (isError ? '#b91c1c' : '#6b7280') + ';">' + msg + '</p>';
        }
    }

    // Renders a status or error message inside the Apple Pay trigger frame.
    function setApplePayFrameStatus(msg, isError) {
        var frame = document.getElementById('ncx-cp-applepay-frame');
        if (frame) {
            frame.innerHTML = '<p class="ncx-cp-inline-note" style="color:' + (isError ? '#b91c1c' : '#6b7280') + ';">' + msg + '</p>';
        }
    }

    // Tears down any mounted OPP widget and removes its script tag from the page.
    function unloadCopyAndPayWidget() {
        if (window.wpwl && typeof window.wpwl.unload === 'function') {
            try { window.wpwl.unload(); } catch (e) { log('NCX: wpwl.unload error', e); }
        }

        var oldScripts = document.querySelectorAll('script[src*="paymentWidgets.js"]');
        for (var i = 0; i < oldScripts.length; i++) {
            oldScripts[i].parentNode.removeChild(oldScripts[i]);
        }

        // wpwl.unload() leaves its spin.js node behind when the widget is torn
        // down mid-load, which strands a spinner on the page for good.
        var strays = document.querySelectorAll(
            '#ncx-cp-inline-wrapper .spinner,#ncx-cp-inline-frame .spinner,'
            + '#ncx-cp-applepay-frame .spinner,#ncx-cp-googlepay-frame .spinner,'
            + '.wpwl-container .spinner,.wpwl-spinner'
        );
        for (var s = 0; s < strays.length; s++) {
            if (strays[s].parentNode) {
                strays[s].parentNode.removeChild(strays[s]);
            }
        }
    }

    // Adds a preconnect hint so the browser can open OPP sooner.
    function ensureOppPreconnect(regionHost) {
        regionHost = regionHost || settings.regionHost;
        if (!regionHost) {
            return;
        }
        try {
            var origin = new URL(regionHost).origin;
            if (!document.querySelector('link[data-ncx-opp-preconnect="1"]')) {
                var link = document.createElement('link');
                link.rel = 'preconnect';
                link.href = origin;
                link.setAttribute('data-ncx-opp-preconnect', '1');
                document.head.appendChild(link);
            }
        } catch (e) {}
    }

    // Clears any cached Apple Pay checkout session so the next request is fresh.
    function invalidateApplePayPrefetch() {
        state.applePayPrefetchedData = null;
        state.applePayPrefetchPromise = null;
        state.applePayTotalAtFetch = null;
    }

    // Clears the card checkout ID from the WooCommerce form so a stray Place
    // Order cannot post a session the coordinator no longer owns after a wallet
    // mint. Reset-to-card writes a fresh card ID via requestCheckoutId().
    function clearPostedCheckoutId() {
        var hiddenField = document.getElementById('ncx_cp_checkout_id');
        if (hiddenField) {
            hiddenField.value = '';
        }
        state.checkoutId = null;
    }

    // Preloads the OPP widget script for a known Apple Pay checkout id.
    function preloadApplePayWidgetScript(checkoutId, regionHost) {
        if (!checkoutId || !regionHost) {
            return;
        }
        var scriptUrl = regionHost + '/v1/paymentWidgets.js?checkoutId=' + encodeURIComponent(checkoutId);
        var selector = 'link[data-ncx-applepay-preload="' + checkoutId + '"]';
        if (document.querySelector(selector)) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'preload';
        link.as = 'script';
        link.href = scriptUrl;
        link.setAttribute('data-ncx-applepay-preload', checkoutId);
        document.head.appendChild(link);
    }

    // Returns the WooCommerce classic checkout form element.
    function getClassicCheckoutForm() {
        var $form = $('form.woocommerce-checkout');
        if (!$form.length) {
            $form = $('form.checkout');
        }
        return $form;
    }

    function isValidEmailAddress(value) {
        value = String(value || '').trim();
        return value !== '' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    function clearEmailValidationUi() {
        var $form = getClassicCheckoutForm();
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        clearCheckoutValidationError();
        $form.find('#billing_email').removeClass('woocommerce-invalid woocommerce-invalid-required-field woocommerce-invalid-email');
        $('.ncx-cp-checkout-validation-error, .ncx-cp-product-ap-error').remove();
    }

    var emailClearTimer = null;
    $(document).on('input change', '#billing_email, #email', function () {
        var value = $(this).val();
        if (emailClearTimer) {
            clearTimeout(emailClearTimer);
        }
        emailClearTimer = setTimeout(function () {
            if (isValidEmailAddress(value)) {
                clearEmailValidationUi();
            }
        }, 200);
    });

    // Serialises the classic checkout form for server-side validation.
    function getClassicCheckoutPostData() {
        var $form = getClassicCheckoutForm();
        if (!$form.length) {
            return '';
        }
        return $form.serialize();
    }

    // Scrolls the shopper to the first incomplete classic checkout field.
    function scrollToClassicCheckoutFields() {
        var $form = getClassicCheckoutForm();
        if (!$form.length) {
            return;
        }
        var $target = $form.find('.woocommerce-invalid, .woocommerce-invalid-required-field').first();
        if (!$target.length) {
            $target = $form.find('#billing_email, #billing_first_name').first();
        }
        if ($target.length) {
            if (typeof $.scroll_to_notices === 'function') {
                $.scroll_to_notices($target.closest('.form-row, p, fieldset').length ? $target.closest('.form-row, p, fieldset') : $form);
            } else {
                $target[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            $target.trigger('focus');
        }
    }

    // True on the order-pay endpoint, where an existing order is being paid.
    function isPayForOrder() {
        return settings.payForOrder === '1' && !!settings.orderId && !!settings.orderKey;
    }

    // Validates required billing fields before Apple Pay or place order.
    function validateClassicCheckoutFields() {
        // The pay-for-order form carries no billing fields; the address is
        // already stored on the order being paid.
        if (isPayForOrder()) {
            return null;
        }

        var $form = getClassicCheckoutForm();
        if (!$form.length) {
            return null;
        }

        $form.find('.input-text, select, input:checkbox, textarea').trigger('validate').blur();

        if (typeof $form.valid === 'function' && $form.data('validator')) {
            if (!$form.valid()) {
                scrollToClassicCheckoutFields();
                return 'Please complete all required checkout fields before continuing.';
            }
            return null;
        }

        var missing = [];
        $form.find('[name^="billing_"]').each(function () {
            var $field = $(this);
            if ($field.is(':hidden') || $field.attr('type') === 'checkbox') {
                return;
            }
            if ($field.prop('required') || $field.attr('aria-required') === 'true') {
                if (!$field.val() || String($field.val()).trim() === '') {
                    missing.push($field);
                    $field.addClass('woocommerce-invalid woocommerce-invalid-required-field');
                }
            }
        });

        if ($form.find('#billing_email').length) {
            var emailVal = String($form.find('#billing_email').val() || '').trim();
            if (!emailVal || !isValidEmailAddress(emailVal)) {
                missing.push($form.find('#billing_email'));
                $form.find('#billing_email').addClass('woocommerce-invalid');
                return 'Please enter a valid email address.';
            }
        }

        if (missing.length) {
            scrollToClassicCheckoutFields();
            return 'Please complete your billing details before continuing.';
        }

        return null;
    }

    // Shows a validation message above the Apple Pay trigger area.
    function showCheckoutValidationError(message) {
        var frame = document.getElementById('ncx-cp-applepay-frame')
            || document.getElementById('ncx-cp-googlepay-frame');
        if (!frame) {
            return;
        }
        var existing = frame.querySelector('.ncx-cp-checkout-validation-error');
        if (existing) {
            existing.remove();
        }
        var p = document.createElement('p');
        p.className = 'ncx-cp-checkout-validation-error';
        p.style.cssText = 'display:block;margin:0 0 8px;color:#b91c1c;font-size:14px;line-height:1.4;';
        p.textContent = message;
        frame.insertBefore(p, frame.firstChild);
        frame.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Removes any validation message from the Apple Pay trigger area.
    function clearCheckoutValidationError() {
        var frames = [
            document.getElementById('ncx-cp-applepay-frame'),
            document.getElementById('ncx-cp-googlepay-frame'),
        ];
        for (var i = 0; i < frames.length; i++) {
            if (!frames[i]) {
                continue;
            }
            var existing = frames[i].querySelector('.ncx-cp-checkout-validation-error');
            if (existing) {
                existing.remove();
            }
        }
    }

    // Creates or reuses an Apple Pay checkout session via the server AJAX endpoint.
    function fetchApplePayCheckoutData(forceRefresh) {
        if (!isApplePayEnabled()) {
            return $.Deferred().reject('Apple Pay disabled').promise();
        }

        if (!forceRefresh && state.applePayPrefetchedData) {
            return $.Deferred().resolve(state.applePayPrefetchedData).promise();
        }

        if (!forceRefresh && state.applePayPrefetchPromise) {
            return state.applePayPrefetchPromise;
        }

        var ap = settings.applePay || {};
        if (!ap.processUrl) {
            return $.Deferred().reject('Apple Pay process URL missing').promise();
        }

        ensureOppPreconnect(settings.regionHost);
        sendApplePayClientLog('requestOrderCheckout', { prefetch: !forceRefresh });

        var processData = {
            security: ap.nonce || settings.nonce,
            checkout_post: getClassicCheckoutPostData(),
            wallet_mode: forceRefresh ? 'intent' : 'prefetch',
        };

        // Pay for an existing order rather than rebuilding one from the cart.
        if (isPayForOrder()) {
            processData.order_id = settings.orderId;
            processData.order_key = settings.orderKey;
        }

        state.applePayPrefetchPromise = $.ajax({
            url: ap.processUrl,
            method: 'POST',
            dataType: 'json',
            data: processData,
            timeout: 30000,
        }).then(function (response) {
            if (response && response.success && response.data && response.data.checkoutId) {
                if (settings.environment && response.data.environment && response.data.environment !== settings.environment) {
                    return $.Deferred().reject('Payment settings changed. Please refresh the page and try again.');
                }
                state.applePayPrefetchedData = response.data;
                state.applePayTotalAtFetch = getClassicCheckoutTotal();
                sendApplePayClientLog('orderCheckoutCreated', response.data || {});
                preloadApplePayWidgetScript(
                    response.data.checkoutId,
                    response.data.regionHost || settings.regionHost
                );
                return response.data;
            }

            var msg = 'Unknown error';
            if (response && response.data) {
                if (typeof response.data === 'string') {
                    msg = response.data;
                } else if (response.data.message) {
                    msg = response.data.message;
                }
            }
            sendApplePayClientLog('orderCheckoutFailed', { message: msg });
            return $.Deferred().reject(msg);
        }).fail(function (xhr, textStatus) {
            sendApplePayClientLog('orderCheckoutAjaxFailure', {
                status: xhr && xhr.status,
                textStatus: textStatus,
            });
        }).always(function () {
            state.applePayPrefetchPromise = null;
        });

        return state.applePayPrefetchPromise;
    }

    // Removes every Apple Pay entry point this script owns, so the trigger can
    // be re-rendered without ever leaving a second button behind.
    function removeApplePayEntryPoints() {
        if (isBlockCheckout()) {
            return;
        }
        var triggers = document.querySelectorAll('#ncx-cp-start-applepay, .ncx-cp-inline-wrapper .ncx-cp-applepay-trigger');
        for (var i = 0; i < triggers.length; i++) {
            if (triggers[i].parentNode) {
                triggers[i].parentNode.removeChild(triggers[i]);
            }
        }
        var slot = document.getElementById('ncx-cp-applepay-pay');
        if (slot && slot.parentNode) {
            slot.parentNode.removeChild(slot);
        }
    }

    // Renders the Apple Pay trigger button above the card form.
    function renderApplePaySwitch() {
        if (isBlockCheckout()) {
            return;
        }

        var frame = document.getElementById('ncx-cp-applepay-frame');
        if (!frame || !shouldShowApplePayTrigger()) {
            hideApplePayTriggerArea();
            return;
        }

        // The trigger is the only Apple Pay entry point in card mode; clear any
        // earlier button or pay box first so it can never render twice.
        removeApplePayEntryPoints();

        frame.style.display = '';
        // Trigger button stays in its natural position: above the card form.
        $('#place_order').show();
        clearCheckoutValidationError();
        frame.innerHTML = applePayTriggerMarkup();
        ensureOppPreconnect(settings.regionHost);
        // Apple Pay prefetch is deferred until the card widget is ready so it
        // does not compete with the card checkout session on initial load.
    }

    // Returns the HTML for the "Check out with Apple Pay" trigger button.
    // Apple's mark artwork is a fixed-ratio image of a complete button, so it cannot
    // stretch to the card form width. The outlined button is used instead so this
    // trigger and the Google Pay trigger render at the same width.
    function applePayTriggerMarkup() {
        var appleLogo = '<svg viewBox="0 0 20 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
            + '<path fill="currentColor" d="M16.3 12.7c0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.8-2-1.6-.2-3.1.9-3.9.9-.8 0-2-.9-3.3-.9-1.7 0-3.3 1-4.1 2.5-1.8 3.1-.5 7.6 1.3 10.1.9 1.2 1.9 2.6 3.2 2.5 1.3-.1 1.8-.8 3.3-.8 1.5 0 2 .8 3.3.8 1.4 0 2.2-1.2 3.1-2.4 1-1.4 1.4-2.7 1.4-2.8-.1 0-2.7-1-2.7-4.1zM13.8 4.1c.7-.8 1.2-2 1-3.1-1 0-2.2.7-2.9 1.5-.6.7-1.2 1.9-1 3 1.1.1 2.2-.6 2.9-1.4z"/>'
            + '</svg>';
        return '<button type="button" class="ncx-cp-applepay-trigger" id="ncx-cp-start-applepay" aria-label="Pay with Apple Pay">'
            + appleLogo + 'Pay'
            + '</button>';
    }

    // The actual Apple Pay payment button is mounted inside the payment box,
    // right after the trigger frame, so it sits under the "Pay with card" area
    // and the shopper never has to scroll down to the Place Order button.
    function ensureApplePayPaySlot() {
        var wrapper = document.getElementById('ncx-cp-inline-wrapper');
        var anchor = document.getElementById('ncx-cp-applepay-frame')
            || document.getElementById('ncx-cp-inline-frame');
        if (!wrapper || !anchor) {
            return null;
        }
        var slot = document.getElementById('ncx-cp-applepay-pay');
        if (!slot) {
            slot = document.createElement('div');
            slot.id = 'ncx-cp-applepay-pay';
            slot.className = 'ncx-cp-applepay-pay';
        }
        if (anchor.nextSibling !== slot) {
            wrapper.insertBefore(slot, anchor.nextSibling);
        }
        return slot;
    }

    // Returns the HTML for the "Pay by card instead" back button.
    function applePayBackButtonMarkup() {
        return '<button type="button" class="button" id="ncx-cp-back-to-card" style="width:100%;margin-top:8px;">Pay by card instead</button>';
    }

    // Returns the transient "Preparing Apple Pay…" message markup.
    function applePayPreparingMarkup() {
        return '<p class="ncx-cp-applepay-preparing">Preparing Apple Pay\u2026</p>';
    }

    // Removes the transient "Preparing Apple Pay…" message from the pay slot.
    function clearApplePayPreparingMessage() {
        var els = document.querySelectorAll('.ncx-cp-applepay-preparing');
        for (var i = 0; i < els.length; i++) {
            if (els[i].parentNode) {
                els[i].parentNode.removeChild(els[i]);
            }
        }
    }

    // Returns the HTML shell shown in the Place Order area during Apple Pay setup.
    function applePayPaySlotShellMarkup() {
        return applePayPreparingMarkup()
            + '<p class="ncx-cp-applepay-back">' + applePayBackButtonMarkup() + '</p>';
    }

    // Tears down Apple Pay mode and restores the card widget and trigger.
    function resetApplePayToCard() {
        if (isBlockCheckout() || state.submitting) {
            return;
        }

        log('NCX: switching back to card widget');
        // Invalidate any checkout session still in flight so a late response
        // cannot mount Apple Pay again behind the restored trigger.
        state.applePayRequestSeq++;
        state.activeWidget = 'card';
        state.applePayCheckoutId = null;
        state.applePayWidgetReady = false;
        state.widgetReady = false;
        invalidateApplePayPrefetch();
        unloadCopyAndPayWidget();
        // Remove the Apple Pay pay slot entirely so no empty bordered box remains.
        var slot = document.getElementById('ncx-cp-applepay-pay');
        if (slot && slot.parentNode) {
            slot.parentNode.removeChild(slot);
        }
        // Restore the card + trigger frames hidden when Apple Pay was selected.
        var cardFrame = document.getElementById('ncx-cp-inline-frame');
        if (cardFrame) {
            cardFrame.style.display = '';
        }
        var triggerFrame = document.getElementById('ncx-cp-applepay-frame');
        if (triggerFrame) {
            triggerFrame.style.display = '';
        }
        var googlePayFrame = document.getElementById('ncx-cp-googlepay-frame');
        if (googlePayFrame) {
            googlePayFrame.style.display = '';
        }
        $('#place_order').show();
        renderApplePaySwitch();
        renderGooglePaySwitch();
        requestCheckoutId(null, true);
    }

    // True while the shopper is still waiting on the Apple Pay session that this
    // request id belongs to. Responses from an abandoned attempt are dropped.
    function isApplePayRequestCurrent(requestId) {
        return state.activeWidget === 'applepay' && requestId === state.applePayRequestSeq;
    }

    // Switches from card entry to the Apple Pay payment flow.
    function switchToApplePay() {
        if (isBlockCheckout() || !shouldShowApplePayTrigger() || state.submitting) {
            return;
        }

        if (isCartBelowMinimumPayment()) {
            showCheckoutValidationError(getMinimumPaymentNotice());
            return;
        }

        var validationError = validateClassicCheckoutFields();
        if (validationError) {
            showCheckoutValidationError(validationError);
            scrollToClassicCheckoutFields();
            return;
        }
        clearCheckoutValidationError();

        log('NCX: switching to Apple Pay widget');
        var requestId = ++state.applePayRequestSeq;
        state.activeWidget = 'applepay';
        state.widgetReady = false;
        unloadCopyAndPayWidget();
        clearPostedCheckoutId();
        $('#place_order').hide();

        // Hide the trigger; show the pay box immediately (no loading text).
        var triggerFrame = document.getElementById('ncx-cp-applepay-frame');
        if (triggerFrame) {
            triggerFrame.innerHTML = '';
            triggerFrame.style.display = 'none';
        }
        // Hide the (now empty) card form frame so it isn't a big empty box.
        var cardFrame = document.getElementById('ncx-cp-inline-frame');
        if (cardFrame) {
            cardFrame.innerHTML = '';
            cardFrame.style.display = 'none';
        }
        var googlePayFrame = document.getElementById('ncx-cp-googlepay-frame');
        if (googlePayFrame) {
            googlePayFrame.innerHTML = '';
            googlePayFrame.style.display = 'none';
        }

        var slot = ensureApplePayPaySlot();
        if (slot) {
            slot.innerHTML = applePayPaySlotShellMarkup();
        }

        fetchApplePayCheckoutData(true).done(function (data) {
            // The shopper may have gone back to card while this was in flight.
            if (!isApplePayRequestCurrent(requestId)) {
                log('NCX: ignoring stale Apple Pay checkout session');
                return;
            }
            mountApplePayWidget(data.checkoutId, data.regionHost || settings.regionHost);
        }).fail(function (msg) {
            if (!isApplePayRequestCurrent(requestId)) {
                return;
            }
            var message = typeof msg === 'string' ? msg : 'Apple Pay error. Please refresh and try again.';
            showCheckoutValidationError(message);
            resetApplePayToCard();
        });
    }

    /* ── Widget mounting ─────────────────────────────────── */

    // Requests a cart-based checkout ID from the server (phase-one widget mount).
    // Renders notices the server drained from the WooCommerce session. The
    // classic checkout normally prints and clears them itself, so this is only
    // reached on flows that redirect without a full page render.
    function renderDrainedNotices(messages) {
        if (!messages || !messages.length) {
            return;
        }
        var host = document.getElementById('ncx-cp-inline-wrapper')
            || document.getElementById('ncx-cp-inline-frame');
        if (!host) {
            return;
        }

        var box = document.getElementById('ncx-cp-drained-notices');
        if (!box) {
            box = document.createElement('div');
            box.id = 'ncx-cp-drained-notices';
            box.className = 'ncx-cp-drained-notices';
            box.setAttribute('role', 'alert');
            box.style.cssText = 'margin:0 0 12px;padding:10px 12px;border:1px solid #cc1818;'
                + 'border-radius:4px;background:#fdf1f1;color:#8a1c1c;font-size:14px;';
            host.insertBefore(box, host.firstChild);
        }

        box.innerHTML = '';
        for (var i = 0; i < messages.length; i++) {
            var line = document.createElement('p');
            line.style.margin = '0';
            // textContent, never innerHTML: this text travels through the
            // session queue and must not be able to inject markup.
            line.textContent = String(messages[i]);
            box.appendChild(line);
        }
    }

    function cancelInFlightRetry() {
        if (state.inFlightTimer) {
            clearTimeout(state.inFlightTimer);
            state.inFlightTimer = null;
        }
        state.inFlightRetries = 0;
    }

    // Waits out a retryable "payment still settling" refusal and remounts the
    // card form as soon as the coordinator releases the order.
    function scheduleInFlightRetry(payload) {
        if (state.inFlightRetries >= IN_FLIGHT_MAX_RETRIES) {
            setFrameStatus('We are still waiting for your previous payment to be confirmed. Please reload the page in a minute, and contact us before paying again.', true);
            cancelInFlightRetry();
            return;
        }

        var seconds = payload && parseInt(payload.retryAfter, 10);
        var delay = seconds > 0 ? (seconds * 1000) : IN_FLIGHT_FALLBACK_MS;
        state.inFlightRetries++;
        log('NCX: payment in flight, retrying in', delay, 'ms (attempt ' + state.inFlightRetries + ')');
        setFrameStatus(coordinatorErrorMessage(payload, 'Your payment is still being processed. Please wait.'), false);

        state.inFlightTimer = setTimeout(function () {
            state.inFlightTimer = null;
            requestCheckoutId(null, true);
        }, delay);
    }

    // Pulls the shopper-facing string out of a wp_send_json_error body.
    function coordinatorErrorMessage(payload, fallback) {
        if (!payload) {
            return fallback;
        }
        if (typeof payload === 'string' && payload) {
            return payload;
        }
        if (payload.message) {
            return payload.message;
        }
        if (payload[0]) {
            return payload[0];
        }
        return fallback;
    }

    // invalidCheckoutId, when given, is a session the provider just refused to
    // render, so the server can drop it rather than serve it back.
    // force: skip the load-burst coalesce (reset-to-card, failed Place Order).
    function requestCheckoutId(invalidCheckoutId, force) {
        if (state.submitting) {
            log('NCX: payment in progress, skipping checkout ID request');
            return;
        }
        if (state.activeWidget !== 'card') {
            log('NCX: wallet active, skipping card checkout ID request');
            return;
        }
        if (state.widgetLoading) {
            log('NCX: already loading, skipping');
            return;
        }
        var now = Date.now();
        if (!force && !invalidCheckoutId && state.checkoutIdFetchStartedAt
            && (now - state.checkoutIdFetchStartedAt) < CHECKOUT_ID_COALESCE_MS) {
            log('NCX: coalescing checkout ID request');
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

        state.checkoutIdFetchStartedAt = now;
        state.widgetLoading = true;
        state.widgetReady = false;
        setFrameStatus('Requesting checkout session\u2026', false);
        log('NCX: requesting checkout ID via', settings.ajaxUrl);

        var payload = {
            security: settings.nonce,
            wallet_mode: 'prefetch',
        };
        if (invalidCheckoutId) {
            payload.invalid_checkout_id = invalidCheckoutId;
        }

        return $.ajax({
            url: settings.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: payload,
            timeout: 30000,
        })
        .done(function (response) {
            log('NCX: AJAX response', response);
            if (response && response.data && response.data.notices) {
                renderDrainedNotices(response.data.notices);
            }
            if (response && response.success && response.data && response.data.checkoutId) {
                if (settings.environment && response.data.environment && response.data.environment !== settings.environment) {
                    log('NCX: checkout environment mismatch', response.data.environment, settings.environment);
                    setFrameStatus('Payment settings changed. Please refresh the page and try again.', true);
                    return;
                }
                cancelInFlightRetry();
                state.checkoutId = response.data.checkoutId;
                log('NCX: checkout ID received', state.checkoutId);
                mountWidget(state.checkoutId);
            } else {
                var data = response && response.data;
                if (data && data.code === 'ncx_cp_payment_in_flight') {
                    scheduleInFlightRetry(data);
                    return;
                }
                var msg = coordinatorErrorMessage(data, 'Unknown error');
                log('NCX: checkout ID request failed –', msg);
                setFrameStatus(msg, true);
            }
        })
        .fail(function (xhr, textStatus, errorThrown) {
            log('NCX: AJAX failed', textStatus, errorThrown, 'HTTP', xhr.status, xhr.responseText);
            var json = xhr.responseJSON;
            var failData = json && json.data ? json.data : null;
            if (failData && failData.code === 'ncx_cp_payment_in_flight') {
                scheduleInFlightRetry(failData);
                return;
            }
            var coordinator = failData ? coordinatorErrorMessage(failData, '') : '';
            if (coordinator) {
                setFrameStatus(coordinator, true);
                return;
            }
            var detail = textStatus;
            if (xhr.status === 0) {
                detail = 'Network error (blocked or offline)';
            } else if (xhr.status === 403) {
                detail = 'Nonce expired – please refresh the page';
            } else if (xhr.status === 400 || xhr.status === 409 || xhr.status === 429) {
                detail = 'Payment could not be started. Please wait a moment and try again.';
            } else if (xhr.status === 500) {
                detail = 'Server error (' + xhr.status + ')';
            }
            setFrameStatus(detail, true);
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

        if (state.activeWidget !== 'card') {
            log('NCX: not mounting card widget while Apple Pay is active');
            return;
        }

        log('NCX: mounting widget for', checkoutId, 'from', settings.regionHost);

        // Clear previous card widget only.
        frame.innerHTML = '';

        unloadCopyAndPayWidget();

        // Set hidden field.
        var hiddenField = document.getElementById('ncx_cp_checkout_id');
        if (hiddenField) {
            hiddenField.value = checkoutId;
        }

        // Configure wpwlOptions BEFORE loading the script (card-only; no Apple Pay).
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
            spinner: HIDDEN_WPWL_SPINNER,
            onReady: function () {
                state.widgetReady = true;
                state.invalidCheckoutRetried = false;
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
                setPaymentVerificationUi(true);
                // The challenge is on screen, so the processing note has served
                // its purpose. Place Order stays hidden until payment resolves.
                $('#ncxCpBtnReplace').remove();
                tagThreeDsChallengeFrame();
                // OPP sometimes swaps the iframe in just after this fires.
                setTimeout(tagThreeDsChallengeFrame, 150);
                // Last resort, once the challenge has had time to lay out.
                setTimeout(function () {
                    tagThreeDsChallengeFrame(true);
                }, 800);
            },
            onError: function (error) {
                log('NCX: widget error', error);
                var rejectedCheckoutId = state.checkoutId;
                var wasVerifying = isPaymentVerificationActive();
                state.submitting = false;
                // A cancelled or failed 3DS leaves the challenge state applied,
                // which keeps the wallets, saved cards and the save-card box
                // hidden. Clear it here: unblockForm() only restores the button.
                setPaymentVerificationUi(false);
                unblockForm();

                if (error && error.name === 'InvalidCheckoutIdError') {
                    if (state.invalidCheckoutRetried) {
                        log('NCX: provider rejected the replacement session too – not retrying');
                        setFrameStatus('Payment could not be started. Please refresh the page and try again.', true);
                        return;
                    }
                    state.invalidCheckoutRetried = true;
                    log('NCX: checkout expired, requesting a replacement');
                    requestCheckoutId(rejectedCheckoutId);
                    return;
                }

                // The session was handed to the provider, so it can never be
                // submitted again: drop it before the shopper can post it, and
                // mount a fresh one once the coordinator releases the order.
                if (wasVerifying) {
                    clearPostedCheckoutId();
                    unloadCopyAndPayWidget();
                    requestCheckoutId(null, true);
                }
            },
        };

        // Create the form element FIRST (OPP looks for .paymentWidgets in DOM).
        var formEl = document.createElement('form');
        formEl.className = 'paymentWidgets';
        formEl.setAttribute('data-brands', settings.cardBrands || settings.brands || 'VISA MASTER');
        frame.appendChild(formEl);

        // Load OPP paymentWidgets.js.
        var scriptUrl = settings.regionHost + '/v1/paymentWidgets.js?checkoutId=' + encodeURIComponent(checkoutId);
        log('NCX: loading', scriptUrl);
        var script = document.createElement('script');
        script.src = scriptUrl;
        script.async = true;
        script.setAttribute('data-ncx-card', '1');
        script.onerror = function () {
            log('NCX: paymentWidgets.js failed to load');
            setFrameStatus('Unable to load card form script. Check your network connection.', true);
        };
        document.body.appendChild(script);
    }

    /**
     * Mount a dedicated Apple Pay-only OPP widget using an order-based checkout ID.
     */
    function mountApplePayWidget(checkoutId, regionHost) {
        if (isBlockCheckout() || state.activeWidget !== 'applepay') {
            log('NCX: not mounting Apple Pay widget – card entry is active');
            return;
        }

        var slot = ensureApplePayPaySlot();
        if (!slot) {
            log('NCX: Apple Pay pay slot not found (no #place_order)');
            return;
        }

        regionHost = regionHost || settings.regionHost;
        if (!regionHost) {
            log('NCX: Apple Pay regionHost not set');
            slot.innerHTML = '<p style="display:block;margin:0;color:#b91c1c;">Configuration error: regionHost missing.</p>';
            return;
        }

        log('NCX: mounting Apple Pay widget for', checkoutId, 'from', regionHost);
        state.activeWidget = 'applepay';
        state.applePayCheckoutId = checkoutId;
        state.applePayWidgetReady = false;
        slot.innerHTML = '';
        $('#place_order').hide();

        unloadCopyAndPayWidget();

        var preparingEl = document.createElement('p');
        preparingEl.className = 'ncx-cp-applepay-preparing';
        preparingEl.textContent = 'Preparing Apple Pay\u2026';
        slot.appendChild(preparingEl);

        var ap = settings.applePay || {};
        window.wpwlOptions = buildApplePayOnlyWpwlOptions();

        var formEl = document.createElement('form');
        formEl.className = 'paymentWidgets';
        formEl.setAttribute('data-brands', ap.brand || 'APPLEPAY');
        slot.appendChild(formEl);

        var backWrap = document.createElement('p');
        backWrap.className = 'ncx-cp-applepay-back';
        backWrap.innerHTML = applePayBackButtonMarkup();
        slot.appendChild(backWrap);

        var scriptUrl = regionHost + '/v1/paymentWidgets.js?checkoutId=' + encodeURIComponent(checkoutId);
        log('NCX: loading Apple Pay widget', scriptUrl);
        var script = document.createElement('script');
        script.src = scriptUrl;
        script.async = false;
        script.setAttribute('data-ncx-applepay', '1');
        script.onload = function () {
            state.applePayWidgetReady = true;
            log('NCX: Apple Pay widget script loaded');
            sendApplePayClientLog('widgetLoaded', { checkoutId: checkoutId });
        };
        script.onerror = function () {
            log('NCX: Apple Pay paymentWidgets.js failed to load');
            var paySlot = document.getElementById('ncx-cp-applepay-pay');
            if (paySlot) {
                paySlot.innerHTML = '<p style="display:block;margin:0 0 8px;color:#b91c1c;">Unable to load Apple Pay. Please refresh and try again.</p>'
                    + applePayBackButtonMarkup();
            }
            sendApplePayClientLog('widgetLoadError', { checkoutId: checkoutId });
        };
        document.head.appendChild(script);
    }

    /**
     * Create a WooCommerce order and a complete order-based OPP checkout for Apple Pay.
     */
    function requestApplePayCheckout() {
        var requestId = state.applePayRequestSeq;
        fetchApplePayCheckoutData(true).done(function (data) {
            if (!isApplePayRequestCurrent(requestId)) {
                return;
            }
            mountApplePayWidget(data.checkoutId, data.regionHost || settings.regionHost);
        }).fail(function (msg) {
            if (!isApplePayRequestCurrent(requestId)) {
                return;
            }
            var message = typeof msg === 'string' ? msg : 'Apple Pay error. Please refresh and try again.';
            setApplePayFrameStatus('Apple Pay error: ' + message, true);
        });
    }

    // Initialises the Apple Pay trigger if enabled and the frame is present.
    function initApplePay() {
        if (isBlockCheckout() || !isApplePayEnabled()) {
            return;
        }
        if (!document.getElementById('ncx-cp-applepay-frame')) {
            return;
        }
        if (!shouldShowApplePayTrigger()) {
            hideApplePayTriggerArea();
            return;
        }
        renderApplePaySwitch();
    }

    /* ── Google Pay ─────────────────────────────────────── */

    function isGooglePayEnabled() {
        var gp = settings.googlePay || {};
        return gp.enabled === '1' || gp.enabled === 1 || gp.enabled === true;
    }

    function isGooglePayDeviceSupported() {
        return window.isSecureContext
            || window.location.hostname === 'localhost'
            || window.location.hostname === '127.0.0.1';
    }

    function shouldShowGooglePayTrigger() {
        return isGooglePayEnabled() && isGooglePayDeviceSupported();
    }

    function googlePayLogoMarkup() {
        return '<svg viewBox="0 0 130 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
            + '<g transform="translate(-3.652,-3.652) scale(0.913)">'
            + '<path fill="#FFC107" d="M43.6 20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3 0 5.7 1.1 7.8 3l5.7-5.7C34 6 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20c11.7 0 19.4-8.2 19.4-19.7 0-1.5-.2-2.9-.4-4.3z"/>'
            + '<path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3 0 5.7 1.1 7.8 3l5.7-5.7C34 6 29.3 4 24 4c-7.7 0-14.4 4.4-17.7 10.7z"/>'
            + '<path fill="#4CAF50" d="M24 44c5.1 0 9.9-1.9 13.4-5.1l-6.2-5.2C29.2 35.2 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.5 16.2 44 24 44z"/>'
            + '<path fill="#1976D2" d="M43.6 20H24v8h11.3c-.8 2.4-2.3 4.3-4.2 5.7l6.2 5.2c3.6-3.3 6.1-8.3 6.1-14.6 0-1.5-.1-2.9-.4-4.3z"/>'
            + '</g>'
            + '<text x="42.2" y="36.6" textLength="87.8" lengthAdjust="spacingAndGlyphs" fill="currentColor" font-family="Arial, sans-serif" font-size="51">Pay</text>'
            + '</svg>';
    }

    function renderGooglePaySwitch() {
        var frame = document.getElementById('ncx-cp-googlepay-frame');
        if (!frame || !shouldShowGooglePayTrigger() || state.activeWidget !== 'card') {
            if (frame) {
                frame.innerHTML = '';
                frame.style.display = 'none';
            }
            return;
        }
        frame.style.display = '';
        frame.innerHTML = '<button type="button" class="ncx-cp-googlepay-trigger" id="ncx-cp-start-googlepay" aria-label="Pay with Google Pay">'
            + googlePayLogoMarkup() + '</button>';
        ensureOppPreconnect(settings.regionHost);
    }

    function sendGooglePayClientLog(event, details, checkoutId) {
        var gp = settings.googlePay || {};
        if (!gp.clientLogUrl || (settings.googlePayClientLog !== '1' && event !== 'onCancel')) {
            return;
        }
        return $.ajax({
            url: gp.clientLogUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                security: gp.nonce || settings.nonce,
                checkout_id: checkoutId || state.googlePayCheckoutId || '',
                event: event,
                details: JSON.stringify(details || {}),
            },
        });
    }

    function invalidateGooglePayPrefetch() {
        state.googlePayPrefetchedData = null;
        state.googlePayPrefetchPromise = null;
    }

    function fetchGooglePayCheckoutData(forceRefresh) {
        var gp = settings.googlePay || {};
        if (!isGooglePayEnabled() || !gp.processUrl) {
            return $.Deferred().reject('Google Pay is not configured').promise();
        }
        if (!forceRefresh && state.googlePayPrefetchedData) {
            return $.Deferred().resolve(state.googlePayPrefetchedData).promise();
        }
        if (!forceRefresh && state.googlePayPrefetchPromise) {
            return state.googlePayPrefetchPromise;
        }

        sendGooglePayClientLog('requestOrderCheckout', { prefetch: !forceRefresh });
        var processData = {
            security: gp.nonce || settings.nonce,
            checkout_post: getClassicCheckoutPostData(),
            wallet_mode: forceRefresh ? 'intent' : 'prefetch',
        };

        // Pay for the existing order rather than requiring a cart on order-pay.
        if (isPayForOrder()) {
            processData.order_id = settings.orderId;
            processData.order_key = settings.orderKey;
        }

        state.googlePayPrefetchPromise = $.ajax({
            url: gp.processUrl,
            method: 'POST',
            dataType: 'json',
            data: processData,
            timeout: 30000,
        }).then(function (response) {
            if (response && response.success && response.data && response.data.checkoutId) {
                if (settings.environment && response.data.environment && response.data.environment !== settings.environment) {
                    return $.Deferred().reject('Payment settings changed. Please refresh the page and try again.');
                }
                state.googlePayPrefetchedData = response.data;
                sendGooglePayClientLog('orderCheckoutCreated', response.data, response.data.checkoutId);
                return response.data;
            }
            var message = response && response.data && response.data.message
                ? response.data.message
                : 'Could not prepare Google Pay.';
            return $.Deferred().reject(message);
        }).always(function () {
            state.googlePayPrefetchPromise = null;
        });

        return state.googlePayPrefetchPromise;
    }

    function ensureGooglePayPaySlot() {
        var wrapper = document.getElementById('ncx-cp-inline-wrapper');
        var anchor = document.getElementById('ncx-cp-googlepay-frame');
        if (!wrapper || !anchor) {
            return null;
        }
        var slot = document.getElementById('ncx-cp-googlepay-pay');
        if (!slot) {
            slot = document.createElement('div');
            slot.id = 'ncx-cp-googlepay-pay';
            slot.className = 'ncx-cp-googlepay-pay';
        }
        if (anchor.nextSibling !== slot) {
            wrapper.insertBefore(slot, anchor.nextSibling);
        }
        return slot;
    }

    function clearGooglePayPreparingMessage() {
        $('.ncx-cp-googlepay-preparing').remove();
    }

    function isGooglePayRequestCurrent(requestId) {
        return state.activeWidget === 'googlepay' && requestId === state.googlePayRequestSeq;
    }

    function resetGooglePayToCard() {
        if (isBlockCheckout() || state.submitting) {
            return;
        }
        state.googlePayRequestSeq++;
        state.activeWidget = 'card';
        state.googlePayCheckoutId = null;
        state.googlePayWidgetReady = false;
        state.widgetReady = false;
        invalidateGooglePayPrefetch();
        unloadCopyAndPayWidget();
        var slot = document.getElementById('ncx-cp-googlepay-pay');
        if (slot && slot.parentNode) {
            slot.parentNode.removeChild(slot);
        }
        var cardFrame = document.getElementById('ncx-cp-inline-frame');
        if (cardFrame) {
            cardFrame.style.display = '';
        }
        var applePayFrame = document.getElementById('ncx-cp-applepay-frame');
        if (applePayFrame) {
            applePayFrame.style.display = '';
        }
        var googlePayFrame = document.getElementById('ncx-cp-googlepay-frame');
        if (googlePayFrame) {
            googlePayFrame.style.display = '';
        }
        $('#place_order').show();
        renderApplePaySwitch();
        renderGooglePaySwitch();
        requestCheckoutId(null, true);
    }

    function buildGooglePayOnlyWpwlOptions() {
        var gp = settings.googlePay || {};
        return {
            spinner: HIDDEN_WPWL_SPINNER,
            googlePay: {
                environment: gp.environment || 'TEST',
                merchantId: gp.merchantId || '',
                gatewayMerchantId: gp.gatewayMerchantId || '',
                merchantName: gp.merchantName || gp.displayName || '',
                allowedAuthMethods: gp.allowedAuthMethods || ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
                allowedCardNetworks: gp.allowedCardNetworks || ['MASTERCARD', 'VISA'],
                buttonColor: gp.buttonColor || 'white',
                buttonType: gp.buttonType || 'pay',
                buttonSizeMode: gp.buttonSizeMode || 'fill',
                billingAddressRequired: gp.billingAddressRequired !== false,
                billingAddressParameters: gp.billingAddressParameters || { format: 'FULL', phoneNumberRequired: true },
                onCancel: function () {
                    var request = sendGooglePayClientLog('onCancel', {});
                    if (request && typeof request.always === 'function') {
                        request.always(resetGooglePayToCard);
                    } else {
                        resetGooglePayToCard();
                    }
                },
            },
            onReady: function () {
                state.googlePayWidgetReady = true;
                clearGooglePayPreparingMessage();
            },
            onError: function (error) {
                error = error || {};
                sendGooglePayClientLog('onError', {
                    name: error.name,
                    message: typeof error.message === 'object' ? JSON.stringify(error.message) : error.message,
                    brand: error.brand,
                    event: error.event,
                });
            },
        };
    }

    function mountGooglePayWidget(checkoutId, regionHost) {
        var slot = ensureGooglePayPaySlot();
        if (!slot) {
            return;
        }
        regionHost = regionHost || settings.regionHost;
        state.activeWidget = 'googlepay';
        state.googlePayCheckoutId = checkoutId;
        state.googlePayWidgetReady = false;
        slot.innerHTML = '<p class="ncx-cp-googlepay-preparing">Preparing Google Pay\u2026</p>';
        $('#place_order').hide();
        unloadCopyAndPayWidget();
        window.wpwlOptions = buildGooglePayOnlyWpwlOptions();

        var formEl = document.createElement('form');
        formEl.className = 'paymentWidgets';
        formEl.setAttribute('data-brands', (settings.googlePay || {}).brand || 'GOOGLEPAY');
        slot.appendChild(formEl);

        var back = document.createElement('p');
        back.className = 'ncx-cp-googlepay-back';
        back.innerHTML = '<button type="button" class="button" id="ncx-cp-googlepay-back-to-card">Pay by card instead</button>';
        slot.appendChild(back);

        var script = document.createElement('script');
        script.src = regionHost + '/v1/paymentWidgets.js?checkoutId=' + encodeURIComponent(checkoutId);
        script.async = false;
        script.setAttribute('data-ncx-googlepay', '1');
        script.onload = function () {
            sendGooglePayClientLog('widgetLoaded', { checkoutId: checkoutId });
        };
        script.onerror = function () {
            slot.innerHTML = '<p style="color:#b91c1c;">Unable to load Google Pay. Please refresh and try again.</p>'
                + '<p class="ncx-cp-googlepay-back"><button type="button" class="button" id="ncx-cp-googlepay-back-to-card">Pay by card instead</button></p>';
            sendGooglePayClientLog('widgetLoadError', { checkoutId: checkoutId });
        };
        document.head.appendChild(script);
    }

    function switchToGooglePay() {
        if (isBlockCheckout() || !shouldShowGooglePayTrigger() || state.submitting) {
            return;
        }
        if (isCartBelowMinimumPayment()) {
            showCheckoutValidationError(getMinimumPaymentNotice());
            return;
        }
        var validationError = validateClassicCheckoutFields();
        if (validationError) {
            showCheckoutValidationError(validationError);
            scrollToClassicCheckoutFields();
            return;
        }
        clearCheckoutValidationError();

        var requestId = ++state.googlePayRequestSeq;
        state.activeWidget = 'googlepay';
        state.widgetReady = false;
        unloadCopyAndPayWidget();
        clearPostedCheckoutId();
        $('#place_order').hide();
        $('#ncx-cp-inline-frame,#ncx-cp-applepay-frame,#ncx-cp-googlepay-frame').empty().hide();
        var slot = ensureGooglePayPaySlot();
        if (slot) {
            slot.innerHTML = '<p class="ncx-cp-googlepay-preparing">Preparing Google Pay\u2026</p>'
                + '<p class="ncx-cp-googlepay-back"><button type="button" class="button" id="ncx-cp-googlepay-back-to-card">Pay by card instead</button></p>';
        }
        fetchGooglePayCheckoutData(true).done(function (data) {
            if (!isGooglePayRequestCurrent(requestId)) {
                return;
            }
            mountGooglePayWidget(data.checkoutId, data.regionHost || settings.regionHost);
        }).fail(function (message) {
            if (!isGooglePayRequestCurrent(requestId)) {
                return;
            }
            showCheckoutValidationError(String(message));
            resetGooglePayToCard();
        });
    }

    function initGooglePay() {
        if (isBlockCheckout() || !isGooglePayEnabled()) {
            return;
        }
        if (!document.getElementById('ncx-cp-googlepay-frame')) {
            return;
        }
        if (!shouldShowGooglePayTrigger()) {
            var frame = document.getElementById('ncx-cp-googlepay-frame');
            if (frame) {
                frame.innerHTML = '';
                frame.style.display = 'none';
            }
            return;
        }
        if (state.activeWidget === 'card') {
            renderGooglePaySwitch();
        }
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

        // The server hands this generation to the provider the moment the first
        // submission succeeds, so a second one can only reach the fail-closed
        // posted-vs-committed branch and fail the order. Place Order is hidden
        // for the duration; this also covers Enter and themes that re-show it.
        if (state.submitting) {
            log('NCX: submission already in progress, ignoring duplicate Place Order');
            return false;
        }

        // A previous hand-off is still settling and the coordinator has refused
        // a replacement session, so there is nothing to pay with yet. Submitting
        // would only fail the order on the server.
        if (state.inFlightTimer) {
            log('NCX: previous payment still settling, ignoring Place Order');
            setFrameStatus('Your payment is still being processed. Please wait — do not pay again.', true);
            return false;
        }

        var validationError = validateClassicCheckoutFields();
        if (validationError) {
            setFrameStatus(validationError, true);
            scrollToClassicCheckoutFields();
            return false;
        }

        log('NCX: Place Order intercepted', {checkoutId: state.checkoutId, widgetReady: state.widgetReady});

        state.submitting = true;
        setPaymentVerificationUi(true);

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
                resetCardPaymentUi();
            } else if (json.execute) {
                // Success with execute flag – trigger payment (nochexapi: pciFormSubmit).
                log('NCX: payment trigger – executing wpwl');
                executePayment();
                $.scroll_to_notices($('#ncx-cp-inline-frame'));
                // Place Order stays hidden and the processing note stays up: the
                // widget now owns the attempt and the server has handed this
                // generation off, so re-submitting can only fail the order.
                // unblockForm() restores both if the widget errors or cancels.
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
            resetCardPaymentUi();
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

    // Restores the checkout form and Place Order button after a failed or cancelled payment.
    function unblockForm() {
        var $form = $('form.checkout');
        $form.removeClass('processing');
        if (typeof $.fn.unblock === 'function') {
            $form.unblock();
        }
        $('#ncxCpBtnReplace').remove();
        $('#place_order').show();
    }

    // OPP injects the 3DS2 challenge as an iframe into the form it is
    // authorising, carrying no size of its own. Its card number, expiry and CVV
    // fields are iframes too, so the challenge is the one that is NOT a
    // wpwl-control-*; tagging it lets the stylesheet give it a usable height.
    // Class only: the challenge is cross-origin and is never read or scripted.
    /**
     * A 3DS2 flow creates more than one iframe: the visible issuer challenge
     * plus hidden helpers for the method and notification callbacks. Sizing a
     * helper like the challenge renders the bank's bare callback response as a
     * second, empty white panel beside the real one, so only tag frames that
     * are actually on screen. The challenge inherits the container width; the
     * helpers are sized to nothing.
     *
     * allowFallback tags everything when nothing looks like the challenge yet.
     * That is the last resort on purpose: an untagged challenge collapses to a
     * few pixels tall and cannot be completed, which is worse than a stray box.
     */
    function isRenderedThreeDsFrame(frame) {
        if (frame.classList.contains('ncx-cp-threeds-frame')) {
            // Already ours, so its size reflects our own CSS rather than OPP's.
            return true;
        }
        var style = window.getComputedStyle(frame);
        if (style.display === 'none' || style.visibility === 'hidden') {
            return false;
        }

        return frame.offsetWidth > 40;
    }

    function tagThreeDsChallengeFrame(allowFallback) {
        var wrapper = document.getElementById('ncx-cp-inline-wrapper');
        if (!wrapper) {
            return;
        }

        var frames = wrapper.querySelectorAll('iframe');
        var candidates = [];
        for (var i = 0; i < frames.length; i++) {
            if ((frames[i].getAttribute('class') || '').indexOf('wpwl-control') !== -1) {
                continue;
            }
            candidates.push(frames[i]);
        }
        if (!candidates.length) {
            return;
        }

        var visible = [];
        for (var j = 0; j < candidates.length; j++) {
            if (isRenderedThreeDsFrame(candidates[j])) {
                visible.push(candidates[j]);
            }
        }

        var chosen = visible.length ? visible : (allowFallback ? candidates : []);
        for (var k = 0; k < chosen.length; k++) {
            chosen[k].classList.add('ncx-cp-threeds-frame');
        }
    }

    function untagThreeDsChallengeFrames() {
        var frames = document.querySelectorAll('.ncx-cp-threeds-frame');
        for (var i = 0; i < frames.length; i++) {
            frames[i].classList.remove('ncx-cp-threeds-frame');
        }
    }

    // True while a 3DS2 challenge is on screen for this checkout.
    function isPaymentVerificationActive() {
        var wrapper = document.getElementById('ncx-cp-inline-wrapper');
        return !!wrapper && wrapper.classList.contains('ncx-cp-payment-verifying');
    }

    function setPaymentVerificationUi(active) {
        var wrapper = document.getElementById('ncx-cp-inline-wrapper');
        if (!wrapper) {
            return;
        }

        if (!active) {
            untagThreeDsChallengeFrames();
        }

        wrapper.classList.toggle('ncx-cp-payment-verifying', !!active);

        // Name the container being authorised so the CSS can hide the other
        // payment form while a 3DS2 challenge is on screen. Only ever the
        // executing container: OPP injects the challenge into the form it is
        // authorising, so the hidden form is never the one in use.
        wrapper.classList.remove('ncx-cp-verifying-card', 'ncx-cp-verifying-registration');
        if (active) {
            wrapper.classList.add(
                'registration' === state.paymentContainer
                    ? 'ncx-cp-verifying-registration'
                    : 'ncx-cp-verifying-card'
            );
        }
    }

    function resetCardPaymentUi() {
        state.submitting = false;
        state.checkoutId = null;
        state.widgetReady = false;
        state.widgetLoading = false;
        state.invalidCheckoutRetried = false;
        cancelInFlightRetry();
        invalidateApplePayPrefetch();
        invalidateGooglePayPrefetch();
        setPaymentVerificationUi(false);
        unloadCopyAndPayWidget();
        unblockForm();
        renderApplePaySwitch();
        renderGooglePaySwitch();
        requestCheckoutId(null, true);
    }

    /* ── Event bindings ──────────────────────────────────── */

    // Classic checkout: WooCommerce fires this after fragments update.
    $(document.body).on('updated_checkout', function () {
        if (isBlockCheckout()) {
            return;
        }
        log('NCX: updated_checkout event fired');
        if (!state.submitting) {
            var totalAtFetch = state.applePayTotalAtFetch;
            var currentTotal = getClassicCheckoutTotal();
            invalidateApplePayPrefetch();
            invalidateGooglePayPrefetch();

            var staleApplePay = state.activeWidget === 'applepay'
                && totalAtFetch !== null
                && currentTotal !== null
                && currentTotal !== totalAtFetch;
            var staleGooglePay = state.activeWidget === 'googlepay';

            if (staleApplePay) {
                log('NCX: cart total changed – resetting Apple Pay');
                resetApplePayToCard();
            } else if (staleGooglePay) {
                log('NCX: cart updated – resetting Google Pay');
                resetGooglePayToCard();
            } else {
                requestCheckoutId();
                if (isApplePayEnabled() && state.activeWidget === 'card') {
                    renderApplePaySwitch();
                }
                if (isGooglePayEnabled() && state.activeWidget === 'card') {
                    renderGooglePaySwitch();
                }
            }
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
        if (isBlockCheckout()) {
            log('NCX: block checkout detected – inline script standing down');
            return;
        }
        if (document.getElementById('ncx-cp-inline-frame')) {
            // Render the Apple Pay trigger and start prefetching the order/checkout
            // right away so the pay button is fast when tapped (no hover on mobile).
            if (isApplePayEnabled() && document.getElementById('ncx-cp-applepay-frame')) {
                ensureOppPreconnect(settings.regionHost);
                initApplePay();
            }
            if (isGooglePayEnabled() && document.getElementById('ncx-cp-googlepay-frame')) {
                ensureOppPreconnect(settings.regionHost);
                initGooglePay();
            }
            setTimeout(function () {
                if (!state.checkoutId && !state.widgetLoading) {
                    log('NCX: DOM ready fallback – requesting checkout ID');
                    requestCheckoutId();
                }
                if (isApplePayEnabled() && state.activeWidget === 'card' && !state.applePayCheckoutId && !state.applePayWidgetLoading) {
                    log('NCX: DOM ready fallback – rendering Apple Pay switch');
                    initApplePay();
                }
                if (isGooglePayEnabled() && state.activeWidget === 'card' && !state.googlePayCheckoutId) {
                    initGooglePay();
                }
            }, 1000);
        } else {
            log('NCX: #ncx-cp-inline-frame not found on DOM ready');
        }
    });

    // MutationObserver fallback for the CLASSIC checkout's lazy rendering.
    // Hard stop on block checkout: the block checkout has its own script
    // (checkout-blocks.js) and renders an element with the same id, which
    // previously caused this observer to loop infinitely.
    if (typeof MutationObserver !== 'undefined' && !isBlockCheckout()) {
        var observer = new MutationObserver(function () {
            // If the block checkout mounts later, stop immediately.
            if (isBlockCheckout()) {
                observer.disconnect();
                return;
            }
            if (document.getElementById('ncx-cp-inline-frame') && !state.checkoutId && !state.widgetLoading) {
                log('NCX: MutationObserver detected #ncx-cp-inline-frame');
                observer.disconnect();
                requestCheckoutId();
            }
            // Only render the Apple Pay trigger once. The guard below prevents the
            // feedback loop where rendering the trigger mutates the DOM and
            // re-triggers this observer.
            if (isApplePayEnabled()
                && document.getElementById('ncx-cp-applepay-frame')
                && !document.getElementById('ncx-cp-start-applepay')
                && state.activeWidget === 'card'
                && !state.applePayCheckoutId
                && !state.applePayWidgetLoading) {
                log('NCX: MutationObserver detected #ncx-cp-applepay-frame');
                initApplePay();
            }
            if (isGooglePayEnabled()
                && document.getElementById('ncx-cp-googlepay-frame')
                && !document.getElementById('ncx-cp-start-googlepay')
                && state.activeWidget === 'card'
                && !state.googlePayCheckoutId) {
                initGooglePay();
            }
        });
        observer.observe(document.body || document.documentElement, { childList: true, subtree: true });
        setTimeout(function () { observer.disconnect(); }, 30000);
    }

    // The block checkout renders containers and buttons with these same ids from
    // its own script, so every delegated handler stands down there. Without this
    // the classic script paints a second Apple Pay trigger into React's frame.
    $(document).on('click', '#ncx-cp-start-applepay', function (ev) {
        if (isBlockCheckout()) {
            return;
        }
        ev.preventDefault();
        switchToApplePay();
    });

    $(document).on('click', '#ncx-cp-back-to-card', function (ev) {
        if (isBlockCheckout()) {
            return;
        }
        ev.preventDefault();
        resetApplePayToCard();
    });

    $(document).on('click', '#ncx-cp-start-googlepay', function (ev) {
        if (isBlockCheckout()) {
            return;
        }
        ev.preventDefault();
        switchToGooglePay();
    });

    $(document).on('click', '#ncx-cp-googlepay-back-to-card', function (ev) {
        if (isBlockCheckout()) {
            return;
        }
        ev.preventDefault();
        resetGooglePayToCard();
    });

})(jQuery);
