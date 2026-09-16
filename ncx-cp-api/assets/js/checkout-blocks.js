/**
 * NCX CopyAndPay – WooCommerce Block Checkout payment method.
 *
 * Registers the gateway with the WC Blocks payment method registry so it
 * renders the OPP COPYandPAY card form inside the React-based checkout.
 */
(function () {
    'use strict';

    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var createElement         = wp.element.createElement;
    var useState              = wp.element.useState;
    var useEffect             = wp.element.useEffect;
    var useCallback           = wp.element.useCallback;
    var useRef                = wp.element.useRef;

    var settings = wc.wcSettings.getSetting('ncx_cp_api_data', {});

    if (!settings || !settings.regionHost) {
        return;
    }

    var GATEWAY_ID = 'ncx_cp_api';
    var paymentContainer = 'card';
    var paymentMode = 'registration';
    var wpwlRoot = null;
    var applePayCheckoutId = null;
    var applePayMountedRef = false;
    var applePayLoadingRef = false;
    var applePayPrefetchedData = null;
    var applePayPrefetchPromise = null;
    var googlePayCheckoutId = null;
    var googlePayPrefetchedData = null;
    var googlePayPrefetchPromise = null;
    var cardInitInFlight = false;
    var cardInitStartedAt = 0;
    var blocksActiveWidget = 'card';
    var MIN_PAYMENT_FLOOR = 0.5;
    var CHECKOUT_ID_COALESCE_MS = 2000;
    // See checkout-inline.js: bounded wait for a hand-off that may still settle.
    var IN_FLIGHT_MAX_RETRIES = 6;
    var IN_FLIGHT_FALLBACK_MS = 15000;

    // Returns iframe placeholder CSS tokens derived from the gateway typography settings.
    function buildIframePlaceholderStyles() {
        var t = settings.typography || {};
        return {
            color: t.placeholder || '#9ca3af',
            'font-size': t.sizeInput || '16px',
            'font-family': t.fontFamily || 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        };
    }

    // Wrapper classes for the widget container, including the selected checkout theme.
    function wrapperClassName() {
        return 'ncx-cp-inline-wrapper' + (settings.themeClass ? ' ' + settings.themeClass : '');
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

    // Posts a lightweight Apple Pay client event to the server for diagnostics.
    function sendApplePayClientLog(event, details, checkoutId) {
        var ap = settings.applePay || {};
        if (!ap.clientLogUrl || (settings.applePayClientLog !== '1' && event !== 'onCancel')) {
            return;
        }

        var body = new URLSearchParams();
        body.append('security', ap.nonce || settings.nonce || '');
        body.append('checkout_id', checkoutId || applePayCheckoutId || '');
        body.append('event', event);
        body.append('details', JSON.stringify(details || {}));

        return fetch(ap.clientLogUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: body.toString(),
        }).catch(function () {});
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
            log('Apple Pay onCancel fired');
            var request = sendApplePayClientLog('onCancel', {});
            var reset = function () {
                if (typeof window.ncxCpBlocksResetCard === 'function') {
                    window.ncxCpBlocksResetCard();
                }
            };
            request && typeof request.finally === 'function' ? request.finally(reset) : reset();
        };

        return {
            applePay: applePayConfig,
            spinner: HIDDEN_WPWL_SPINNER,
            onReady: function () {
                clearApplePayPreparingMessage();
            },
            onError: function (error) {
                error = error || {};
                log('Apple Pay wpwl onError fired', error);
                sendApplePayClientLog('onError', {
                    name: error.name,
                    message: typeof error.message === 'object' ? JSON.stringify(error.message) : error.message,
                    brand: error.brand,
                    event: error.event,
                });
            },
        };
    }

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

    // Reads the current cart total from the WooCommerce Blocks store in major currency units.
    function getBlocksCartTotalAmount() {
        try {
            var cartStore = wp.data.select('wc/store/cart');
            if (!cartStore || typeof cartStore.getCartTotals !== 'function') {
                return null;
            }
            var totals = cartStore.getCartTotals() || {};
            if (totals.total_price === undefined || totals.total_price === null) {
                return null;
            }
            var minor = parseFloat(String(totals.total_price));
            if (isNaN(minor)) {
                return null;
            }
            var currency = wc.wcSettings.getSetting('currency', {});
            var decimals = currency && typeof currency.minorUnit === 'number' ? currency.minorUnit : 2;
            return minor / Math.pow(10, decimals);
        } catch (e) {
            return null;
        }
    }

    // Returns true when the cart total is strictly below the configured minimum.
    function isCartBelowMinimumPayment() {
        var total = getBlocksCartTotalAmount();
        if (total === null) {
            return false;
        }
        return total < getConfiguredMinimumPaymentAmount();
    }

    // Keeps the hidden payment-container field aligned with the active OPP widget.
    function syncPaymentContainerField() {
        var field = document.getElementById('ncx_cp_payment_container');
        if (field) {
            field.value = paymentContainer;
        }
    }

    // Records whether the shopper is paying with a new card or a saved registration.
    function setPaymentContainer(kind) {
        paymentContainer = kind === 'registration' ? 'registration' : 'card';
        syncPaymentContainerField();
    }

    /*
     * Marks which payment form is being authorised, so the CSS can hide the
     * other one while a 3DS2 challenge is on screen.
     *
     * Only ever names the container being executed. OPP injects the challenge
     * into the form it is authorising, so the hidden form is always the one the
     * shopper is not using.
     */
    function setVerifyingContainer(el, kind) {
        if (!el) {
            return;
        }
        el.classList.remove('ncx-cp-verifying-card', 'ncx-cp-verifying-registration');
        if ('registration' === kind) {
            el.classList.add('ncx-cp-verifying-registration');
        } else if ('card' === kind) {
            el.classList.add('ncx-cp-verifying-card');
        }
    }

    function clearVerifyingContainer(el) {
        if (el) {
            el.classList.remove('ncx-cp-verifying-card', 'ncx-cp-verifying-registration');
        }
        untagThreeDsChallengeFrames();
    }

    // OPP injects the 3DS2 challenge as an iframe into the form it is
    // authorising, carrying no size of its own. Its card number, expiry and CVV
    // fields are iframes too, so the challenge is the one that is NOT a
    // wpwl-control-*; tagging it lets the stylesheet give it a usable height.
    // Class only: the challenge is cross-origin and is never read or scripted.
    // See checkout-inline.js: a 3DS2 flow also creates hidden helper iframes for
    // the method and notification callbacks. Sizing those like the challenge
    // renders the bank's bare callback response as a second empty white panel.
    function isRenderedThreeDsFrame(frame) {
        if (frame.classList.contains('ncx-cp-threeds-frame')) {
            return true;
        }
        var style = window.getComputedStyle(frame);
        if (style.display === 'none' || style.visibility === 'hidden') {
            return false;
        }

        return frame.offsetWidth > 40;
    }

    function tagThreeDsChallengeFrame(el, allowFallback) {
        if (!el) {
            return;
        }

        var frames = el.querySelectorAll('iframe');
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

        // Falling back to every frame is the last resort: an untagged challenge
        // collapses to a few pixels tall and cannot be completed.
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

    // Returns the DOM subtree that currently hosts the active OPP widget.
    function getWpwlScope() {
        return wpwlRoot || document;
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

        if (paymentMode === 'card') {
            return false;
        }

        if (scope.querySelector('.wpwl-group-registration.wpwl-selected')) {
            return true;
        }
        if (scope.querySelector('form.wpwl-form-registrations input[type="radio"]:checked')) {
            return true;
        }

        return paymentMode === 'registration';
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
            return paymentMode === 'registration' ? 'registration' : 'card';
        }
        return paymentMode === 'registration' ? 'registration' : 'card';
    }

    // Applies the chosen container kind and returns the matching wpwl container id.
    function applyPaymentContainerKind(kind) {
        if (kind === 'registration') {
            paymentMode = 'registration';
        }
        setPaymentContainer(kind);
        return kind === 'registration' ? 'wpwl-container-registration' : 'wpwl-container-card';
    }

    // Re-reads the active OPP container after shopper interaction with the widget.
    function resolveActiveWpwlContainer() {
        return applyPaymentContainerKind(getPaymentContainerKind(getWpwlScope()));
    }

    // Returns the wpwl container id that executePayment should target.
    function getExecutePaymentContainer() {
        return applyPaymentContainerKind(getPaymentContainerKind(getWpwlScope()));
    }

    // Defers container resolution briefly so OPP DOM updates can settle first.
    function scheduleContainerResolve() {
        resolveActiveWpwlContainer();
        setTimeout(resolveActiveWpwlContainer, 150);
    }

    // Wires click/focus listeners so card vs saved-card mode stays in sync with OPP.
    function initPaymentContainerTracking(root) {
        if (!root) {
            return;
        }

        wpwlRoot = root;

        var legacyBtn = root.querySelector('#ncx-container-change');
        if (legacyBtn) {
            legacyBtn.parentNode.removeChild(legacyBtn);
        }

        if (!root.querySelector('form.wpwl-form-registrations')) {
            paymentMode = 'card';
            setPaymentContainer('card');
            return;
        }

        paymentMode = 'registration';
        resolveActiveWpwlContainer();

        root.addEventListener('click', function (ev) {
            var target = ev.target;
            if (!target || !target.closest) {
                return;
            }

            if (target.closest('[data-action="show-initial-forms"]')) {
                paymentMode = 'card';
                scheduleContainerResolve();
                return;
            }

            if (target.closest('.wpwl-group-registration, label.wpwl-registration, .wpwl-wrapper-registration-registrationId input')) {
                paymentMode = 'registration';
                scheduleContainerResolve();
                return;
            }

            var payBtn = target.closest('.wpwl-button-pay');
            if (payBtn) {
                if (payBtn.getAttribute('data-action') === 'show-initial-forms') {
                    return;
                }
                if (payBtn.closest('.wpwl-form-registrations, .wpwl-container-registration')) {
                    paymentMode = 'registration';
                    scheduleContainerResolve();
                }
            }
        });

        root.addEventListener('change', function (ev) {
            var target = ev.target;
            if (target && target.closest && target.closest('form.wpwl-form-registrations input[type="radio"]')) {
                paymentMode = 'registration';
                scheduleContainerResolve();
            }
        });

        root.addEventListener('input', function (ev) {
            var target = ev.target;
            if (target && target.closest && target.closest('.wpwl-group-registration input.wpwl-control-cvv')) {
                paymentMode = 'registration';
            }
        });

        root.addEventListener('focusin', function (ev) {
            var target = ev.target;
            if (!target || !target.closest) {
                return;
            }
            if (target.closest('form.wpwl-form-card, .wpwl-container-card')) {
                paymentMode = 'card';
            } else if (target.closest('.wpwl-form-registrations, .wpwl-container-registration, .wpwl-group-registration')) {
                paymentMode = 'registration';
            }
        });
    }

    /* ── Helpers ─────────────────────────────────────────── */

    // Writes prefixed debug lines to the browser console when logging is enabled.
    function log() {
        if (settings.consoleLogging !== '1') {
            return;
        }
        if (window.ncxCpApiLog) {
            window.ncxCpApiLog.apply(window, arguments);
            return;
        }
        if (window.console) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[NCX-Blocks]');
            console.log.apply(console, args);
        }
    }

    // Tears down any mounted OPP widget and removes its script tag from the page.
    function unloadCopyAndPayWidget() {
        if (window.wpwl && typeof window.wpwl.unload === 'function') {
            try { window.wpwl.unload(); } catch (e) { log('wpwl.unload error', e); }
        }

        var oldScripts = document.querySelectorAll('script[src*="paymentWidgets.js"]');
        for (var i = 0; i < oldScripts.length; i++) {
            oldScripts[i].parentNode.removeChild(oldScripts[i]);
        }
    }

    // Renders notices the server drained from the WooCommerce session.
    // Blocks checkout never displays session notices itself, so without this a
    // shopper redirected back from a decline is given no reason for it.
    function renderDrainedNotices(messages) {
        if (!messages || !messages.length) {
            return;
        }
        var host = document.getElementById('ncx-cp-blocks-container')
            || document.getElementById('ncx-cp-widget-root');
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

    // Requests a cart-based checkout ID from the server (phase-one widget mount).
    // invalidCheckoutId, when given, is a session the provider just refused to
    // render, so the server can drop it rather than serve it back.
    function requestCheckoutId(callback, invalidCheckoutId) {
        var body = new URLSearchParams();
        body.append('security', settings.nonce || '');
        body.append('context', 'blocks');
        body.append('wallet_mode', 'prefetch');
        if (invalidCheckoutId) {
            body.append('invalid_checkout_id', invalidCheckoutId);
        }

        log('Requesting checkout ID from', settings.ajaxUrl);

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        })
            .then(function (r) {
                if (!r.ok) {
                    log('Checkout ID request HTTP error', r.status, r.statusText);
                }
                return r.text();
            })
            .then(function (text) {
                log('Checkout ID raw response:', text.substring(0, 300));
                var json;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    callback('Invalid server response (HTTP). Please reload the page.');
                    return;
                }
                if (json.data && json.data.notices) {
                    renderDrainedNotices(json.data.notices);
                }
                if (json.success && json.data && json.data.checkoutId) {
                    if (settings.environment && json.data.environment && json.data.environment !== settings.environment) {
                        callback('Payment settings changed. Please refresh the page and try again.');
                        return;
                    }
                    callback(null, json.data.checkoutId);
                } else {
                    var msg = 'Failed to obtain checkout ID';
                    if (json.data && json.data.message) {
                        msg = json.data.message;
                    } else if (typeof json.data === 'string' && json.data) {
                        msg = json.data;
                    }
                    // Third argument carries the coordinator's own code, so a
                    // retryable refusal can be told apart from a hard failure.
                    callback(msg, null, json.data && json.data.code ? json.data : null);
                }
            })
            .catch(function (err) {
                log('Checkout ID request error:', err);
                callback(err.message || 'Network error');
            });
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
        applePayPrefetchedData = null;
        applePayPrefetchPromise = null;
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

    /*
     * Read billing/shipping from the WooCommerce Blocks data stores.
     * Addresses live in the CART store (getCustomerData), not the checkout store.
     * We fall back to the checkout store and finally to the DOM for maximum
     * compatibility across WooCommerce versions.
     */
    function getBlocksAddresses() {
        var billing = {};
        var shipping = {};

        try {
            if (window.wp && wp.data) {
                var cartStore = wp.data.select('wc/store/cart');
                if (cartStore && typeof cartStore.getCustomerData === 'function') {
                    var customer = cartStore.getCustomerData() || {};
                    billing = customer.billingAddress || customer.billing_address || {};
                    shipping = customer.shippingAddress || customer.shipping_address || {};
                }

                if ((!billing || !billing.first_name)) {
                    var checkoutStore = wp.data.select('wc/store/checkout');
                    if (checkoutStore) {
                        if (typeof checkoutStore.getBillingAddress === 'function') {
                            billing = checkoutStore.getBillingAddress() || billing;
                        }
                        if (typeof checkoutStore.getShippingAddress === 'function') {
                            shipping = checkoutStore.getShippingAddress() || shipping;
                        }
                    }
                }
            }
        } catch (e) {
            log('getBlocksAddresses error', e);
        }

        billing = billing || {};
        shipping = shipping || {};

        // Email can live on the cart customer data or a dedicated contact field.
        if (!billing.email) {
            var emailField = document.getElementById('email')
                || document.querySelector('#contact-fields input[type="email"]')
                || document.querySelector('.wc-block-components-address-form__email input');
            if (emailField && emailField.value) {
                billing.email = emailField.value;
            }
        }

        return { billing: billing, shipping: shipping };
    }

    // Returns true when the cart requires a shipping address at checkout.
    function blocksNeedsShipping() {
        try {
            if (window.wp && wp.data) {
                var cartStore = wp.data.select('wc/store/cart');
                if (cartStore && typeof cartStore.getNeedsShipping === 'function') {
                    return !!cartStore.getNeedsShipping();
                }
                if (cartStore && typeof cartStore.getCartData === 'function') {
                    var cart = cartStore.getCartData() || {};
                    return !!cart.needsShipping;
                }
            }
        } catch (e) {}
        return false;
    }

    // Serialises billing/shipping from Blocks stores for server-side validation.
    function getBlocksCheckoutPayload() {
        var payload = {};
        try {
            var addresses = getBlocksAddresses();
            payload.checkout_billing = JSON.stringify(addresses.billing || {});
            payload.checkout_shipping = JSON.stringify(addresses.shipping || {});
            // Blocks keeps shipping in sync with billing unless the shopper
            // expands a separate shipping address; we always send shipping so the
            // server can validate it when the cart needs shipping.
            payload.ship_to_different_address = blocksNeedsShipping() ? '1' : '0';
        } catch (e) {
            log('getBlocksCheckoutPayload error', e);
        }
        return payload;
    }

    // Scrolls the shopper to the first incomplete Blocks address/contact field.
    function scrollBlocksToCheckoutFields() {
        var el = document.querySelector(
            '.wc-block-checkout__contact-fields, .wc-block-components-address-form, .wc-block-checkout__billing-fields, .wc-block-checkout__shipping-fields'
        );
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    // Returns true for empty, null, or whitespace-only strings.
    function isBlank(value) {
        return !value || !String(value).trim();
    }

    // Validates required billing (and shipping when needed) fields before payment.
    function validateBlocksCheckoutFields() {
        try {
            var addresses = getBlocksAddresses();
            var billing = addresses.billing || {};

            // If the store data isn't available at all, don't block the shopper –
            // the server-side validation is the real gate.
            if (!billing || Object.keys(billing).length === 0) {
                return null;
            }

            if (isBlank(billing.email) || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(billing.email).trim())) {
                return 'Please enter a valid email address.';
            }
            if (isBlank(billing.first_name)) {
                return 'Please enter your first name.';
            }
            if (isBlank(billing.last_name)) {
                return 'Please enter your last name.';
            }
            if (isBlank(billing.address_1)) {
                return 'Please enter your address.';
            }
            if (isBlank(billing.city)) {
                return 'Please enter your city.';
            }
            if (isBlank(billing.postcode)) {
                return 'Please enter your postcode.';
            }
            if (isBlank(billing.country)) {
                return 'Please select your country.';
            }

            if (blocksNeedsShipping()) {
                var shipping = addresses.shipping || {};
                // Blocks mirrors billing into shipping unless a separate shipping
                // address is provided; only validate when shipping has its own data.
                var hasSeparateShipping = !isBlank(shipping.first_name)
                    || !isBlank(shipping.address_1)
                    || !isBlank(shipping.city)
                    || !isBlank(shipping.postcode);

                if (hasSeparateShipping) {
                    if (isBlank(shipping.first_name)) {
                        return 'Please enter the shipping first name.';
                    }
                    if (isBlank(shipping.last_name)) {
                        return 'Please enter the shipping last name.';
                    }
                    if (isBlank(shipping.address_1)) {
                        return 'Please enter the shipping address.';
                    }
                    if (isBlank(shipping.city)) {
                        return 'Please enter the shipping city.';
                    }
                    if (isBlank(shipping.postcode)) {
                        return 'Please enter the shipping postcode.';
                    }
                    if (isBlank(shipping.country)) {
                        return 'Please select the shipping country.';
                    }
                }
            }

            return null;
        } catch (e) {
            log('validateBlocksCheckoutFields error', e);
            return null;
        }
    }

    // Creates or reuses an Apple Pay checkout session via the server AJAX endpoint.
    function fetchApplePayCheckoutData(forceRefresh, callback) {
        if (!isApplePayEnabled()) {
            callback('Apple Pay disabled', null);
            return;
        }

        if (!forceRefresh && applePayPrefetchedData) {
            callback(null, applePayPrefetchedData);
            return;
        }

        if (!forceRefresh && applePayPrefetchPromise) {
            applePayPrefetchPromise.then(function (data) {
                callback(null, data);
            }).catch(function (err) {
                callback(err && err.message ? err.message : String(err), null);
            });
            return;
        }

        var ap = settings.applePay || {};
        if (!ap.processUrl) {
            callback('Apple Pay process URL missing', null);
            return;
        }

        ensureOppPreconnect(settings.regionHost);
        applePayLoadingRef = true;
        sendApplePayClientLog('requestOrderCheckout', { prefetch: !forceRefresh });

        var body = new URLSearchParams();
        body.append('security', ap.nonce || settings.nonce || '');
        body.append('wallet_mode', forceRefresh ? 'intent' : 'prefetch');
        var checkoutPayload = getBlocksCheckoutPayload();
        Object.keys(checkoutPayload).forEach(function (key) {
            body.append(key, checkoutPayload[key]);
        });

        applePayPrefetchPromise = fetch(ap.processUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.success && json.data && json.data.checkoutId) {
                    if (settings.environment && json.data.environment && json.data.environment !== settings.environment) {
                        throw new Error('Payment settings changed. Please refresh the page and try again.');
                    }
                    applePayPrefetchedData = json.data;
                    sendApplePayClientLog('orderCheckoutCreated', json.data || {}, json.data.checkoutId);
                    preloadApplePayWidgetScript(
                        json.data.checkoutId,
                        json.data.regionHost || settings.regionHost
                    );
                    return json.data;
                }

                var msg = 'Failed to prepare Apple Pay';
                if (json.data && json.data.message) {
                    msg = json.data.message;
                }
                sendApplePayClientLog('orderCheckoutFailed', { message: msg });
                throw new Error(msg);
            })
            .catch(function (err) {
                sendApplePayClientLog('orderCheckoutAjaxFailure', {
                    message: err && err.message ? err.message : String(err),
                });
                throw err;
            })
            .finally(function () {
                applePayLoadingRef = false;
                applePayPrefetchPromise = null;
            });

        applePayPrefetchPromise.then(function (data) {
            callback(null, data);
        }).catch(function (err) {
            callback(err && err.message ? err.message : String(err), null);
        });
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
        return '<p class="ncx-cp-applepay-preparing">Preparing Apple Pay\u2026</p>'
            + '<p class="ncx-cp-applepay-back"><button type="button" class="button" id="ncx-cp-back-to-card" style="width:100%;margin-top:8px;">Pay by card instead</button></p>';
    }

    // Locates the Blocks or classic Place Order button in the checkout DOM.
    function findBlocksPlaceOrderButton() {
        return document.querySelector('.wc-block-components-checkout-place-order-button')
            || document.querySelector('#place-order')
            || document.querySelector('button[name="woocommerce_checkout_place_order"]')
            || document.getElementById('place_order');
    }

    // Hides the native Place Order button while Apple Pay owns the pay slot.
    function hideBlocksPlaceOrder() {
        var btn = findBlocksPlaceOrderButton();
        if (btn) {
            btn.style.display = 'none';
        }
    }

    // Restores the native Place Order button after leaving Apple Pay mode.
    function showBlocksPlaceOrder() {
        var btn = findBlocksPlaceOrderButton();
        if (btn) {
            btn.style.display = '';
        }
    }

    // Mounts the Apple Pay pay slot as a raw-DOM child of the React-owned
    // #ncx-cp-applepay-frame (whose children React never reconciles). This keeps
    // the Apple Pay button under the card area so the shopper never scrolls to
    // Place Order, while preserving the shared .ncx-cp-applepay-pay styling.
    function ensureApplePayPaySlot() {
        var frame = document.getElementById('ncx-cp-applepay-frame');
        if (!frame) {
            return null;
        }
        frame.style.display = '';
        var slot = document.getElementById('ncx-cp-applepay-pay');
        if (!slot) {
            slot = document.createElement('div');
            slot.id = 'ncx-cp-applepay-pay';
            slot.className = 'ncx-cp-applepay-pay';
        }
        if (slot.parentNode !== frame) {
            frame.appendChild(slot);
        }
        return slot;
    }

    // Removes the Apple Pay pay slot child (keeps the React frame node intact).
    function removeApplePayPaySlot() {
        var slot = document.getElementById('ncx-cp-applepay-pay');
        if (slot && slot.parentNode) {
            slot.parentNode.removeChild(slot);
        }
    }

    // Strips any Apple Pay trigger that was written into the frame by raw DOM.
    // React renders the only legitimate trigger, above the frame, so anything
    // found here would show up as a second Apple Pay button.
    function removeStrayApplePayTriggers() {
        var frame = document.getElementById('ncx-cp-applepay-frame');
        if (!frame) {
            return;
        }
        var strays = frame.querySelectorAll('.ncx-cp-applepay-trigger');
        for (var i = 0; i < strays.length; i++) {
            if (strays[i].parentNode) {
                strays[i].parentNode.removeChild(strays[i]);
            }
        }
    }

    // Populates the Apple Pay pay slot with the preparing/back-to-card shell.
    function showApplePayPayShell(callback) {
        var slot = ensureApplePayPaySlot();
        if (slot) {
            slot.innerHTML = applePayPaySlotShellMarkup();
            if (callback) {
                callback(slot);
            }
            return true;
        }
        return false;
    }

    // Retries pay-slot insertion until Blocks has rendered the Place Order button.
    function showApplePayPayShellWithRetry(callback, attemptsLeft) {
        if (showApplePayPayShell(callback)) {
            return;
        }
        if (attemptsLeft <= 0) {
            log('Apple Pay pay slot not found after retries');
            if (callback) {
                callback(null);
            }
            return;
        }
        setTimeout(function () {
            showApplePayPayShellWithRetry(callback, attemptsLeft - 1);
        }, 100);
    }

    // Clears and hides the card widget root while Apple Pay is active.
    function hideCardWidgetRoot(widgetRoot) {
        if (widgetRoot) {
            widgetRoot.innerHTML = '';
            widgetRoot.style.display = 'none';
        }
    }

    // Shows the card widget root again when returning from Apple Pay mode.
    function showCardWidgetRoot(widgetRoot) {
        if (widgetRoot) {
            widgetRoot.style.display = '';
        }
    }

    // Convenience wrapper that fetches Apple Pay checkout data on demand.
    function requestApplePayCheckout(callback) {
        fetchApplePayCheckoutData(true, callback);
    }

    // Loads the OPP Apple Pay widget into the pay slot for a given checkout id.
    function mountApplePayWidget(checkoutId, regionHost) {
        if (blocksActiveWidget !== 'applepay') {
            log('Not mounting Apple Pay widget – card entry is active');
            return;
        }

        var slot = ensureApplePayPaySlot();
        if (!slot) {
            log('Apple Pay pay slot not found (no blocks place-order button)');
            return;
        }

        regionHost = regionHost || settings.regionHost;
        applePayCheckoutId = checkoutId;
        applePayMountedRef = true;
        blocksActiveWidget = 'applepay';
        slot.innerHTML = '';
        hideBlocksPlaceOrder();
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
        backWrap.innerHTML = '<button type="button" class="button" id="ncx-cp-back-to-card" style="width:100%;margin-top:8px;">Pay by card instead</button>';
        slot.appendChild(backWrap);

        var script = document.createElement('script');
        script.src = regionHost + '/v1/paymentWidgets.js?checkoutId=' + encodeURIComponent(checkoutId);
        script.async = false;
        script.setAttribute('data-ncx-applepay', '1');
        script.onload = function () {
            log('Apple Pay widget script loaded');
            sendApplePayClientLog('widgetLoaded', { checkoutId: checkoutId });
        };
        script.onerror = function () {
            log('Apple Pay paymentWidgets.js failed to load');
            slot.innerHTML = '<p class="ncx-cp-applepay-preparing" style="color:#b91c1c;">Unable to load Apple Pay. Please refresh and try again.</p>'
                + '<p class="ncx-cp-applepay-back"><button type="button" class="button" id="ncx-cp-back-to-card" style="width:100%;margin-top:8px;">Pay by card instead</button></p>';
            sendApplePayClientLog('widgetLoadError', { checkoutId: checkoutId });
        };
        document.head.appendChild(script);
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

    function sendGooglePayClientLog(event, details, checkoutId) {
        var gp = settings.googlePay || {};
        if (!gp.clientLogUrl || (settings.googlePayClientLog !== '1' && event !== 'onCancel')) {
            return;
        }
        var body = new URLSearchParams();
        body.append('security', gp.nonce || settings.nonce || '');
        body.append('checkout_id', checkoutId || googlePayCheckoutId || '');
        body.append('event', event);
        body.append('details', JSON.stringify(details || {}));
        return fetch(gp.clientLogUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        }).catch(function () {});
    }

    function invalidateGooglePayPrefetch() {
        googlePayPrefetchedData = null;
        googlePayPrefetchPromise = null;
    }

    function fetchGooglePayCheckoutData(forceRefresh, callback) {
        var gp = settings.googlePay || {};
        if (!isGooglePayEnabled() || !gp.processUrl) {
            callback('Google Pay is not configured', null);
            return;
        }
        if (!forceRefresh && googlePayPrefetchedData) {
            callback(null, googlePayPrefetchedData);
            return;
        }
        if (!forceRefresh && googlePayPrefetchPromise) {
            googlePayPrefetchPromise.then(function (data) { callback(null, data); })
                .catch(function (err) { callback(err.message || String(err), null); });
            return;
        }

        var body = new URLSearchParams();
        body.append('security', gp.nonce || settings.nonce || '');
        body.append('wallet_mode', forceRefresh ? 'intent' : 'prefetch');
        var payload = getBlocksCheckoutPayload();
        Object.keys(payload).forEach(function (key) { body.append(key, payload[key]); });

        googlePayPrefetchPromise = fetch(gp.processUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (!json.success || !json.data || !json.data.checkoutId) {
                throw new Error(json.data && json.data.message ? json.data.message : 'Could not prepare Google Pay.');
            }
            if (settings.environment && json.data.environment && settings.environment !== json.data.environment) {
                throw new Error('Payment settings changed. Please refresh the page and try again.');
            }
            googlePayPrefetchedData = json.data;
            sendGooglePayClientLog('orderCheckoutCreated', json.data, json.data.checkoutId);
            return json.data;
        }).finally(function () {
            googlePayPrefetchPromise = null;
        });

        googlePayPrefetchPromise.then(function (data) { callback(null, data); })
            .catch(function (err) { callback(err.message || String(err), null); });
    }

    function ensureGooglePayPaySlot() {
        var frame = document.getElementById('ncx-cp-googlepay-frame');
        if (!frame) {
            return null;
        }
        frame.style.display = '';
        var slot = document.getElementById('ncx-cp-googlepay-pay');
        if (!slot) {
            slot = document.createElement('div');
            slot.id = 'ncx-cp-googlepay-pay';
            slot.className = 'ncx-cp-googlepay-pay';
            frame.appendChild(slot);
        }
        return slot;
    }

    function removeGooglePayPaySlot() {
        var slot = document.getElementById('ncx-cp-googlepay-pay');
        if (slot && slot.parentNode) {
            slot.parentNode.removeChild(slot);
        }
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
                    var reset = function () {
                        if (typeof window.ncxCpBlocksResetCard === 'function') {
                            window.ncxCpBlocksResetCard();
                        }
                    };
                    request && typeof request.finally === 'function' ? request.finally(reset) : reset();
                },
            },
            onReady: function () {
                var preparing = document.querySelectorAll('.ncx-cp-googlepay-preparing');
                for (var i = 0; i < preparing.length; i++) {
                    preparing[i].remove();
                }
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
        googlePayCheckoutId = checkoutId;
        blocksActiveWidget = 'googlepay';
        slot.innerHTML = '<p class="ncx-cp-googlepay-preparing">Preparing Google Pay\u2026</p>';
        hideBlocksPlaceOrder();
        unloadCopyAndPayWidget();
        window.wpwlOptions = buildGooglePayOnlyWpwlOptions();

        var form = document.createElement('form');
        form.className = 'paymentWidgets';
        form.setAttribute('data-brands', (settings.googlePay || {}).brand || 'GOOGLEPAY');
        slot.appendChild(form);
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
            slot.innerHTML = '<p class="ncx-cp-googlepay-preparing" style="color:#b91c1c;">Unable to load Google Pay. Please refresh and try again.</p>'
                + '<p class="ncx-cp-googlepay-back"><button type="button" class="button" id="ncx-cp-googlepay-back-to-card">Pay by card instead</button></p>';
            sendGooglePayClientLog('widgetLoadError', { checkoutId: checkoutId });
        };
        document.head.appendChild(script);
    }

    /* ── React component ─────────────────────────────────── */

    // Main Blocks checkout UI: card widget, Apple Pay trigger, and minimum-payment gate.
    var CardForm = function (props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse      = props.emitResponse;
        var onPaymentSetup    = eventRegistration.onPaymentSetup;

        /* Refs – widgetRef is a raw DOM node React never touches */
        var wrapperRef    = useRef(null);
        var widgetRef     = useRef(null);   // created via DOM, outside React tree
        var applePayFrameRef = useRef(null);
        var googlePayFrameRef = useRef(null);
        var checkoutIdRef = useRef(null);
        var widgetReadyRef = useRef(false);
        var mountedRef     = useRef(false);
        // One retry per mount cycle when the provider rejects the session.
        // Without a bound this loops forever against the AJAX endpoint.
        var invalidRetriedRef      = useRef(false);
        var rejectedCheckoutIdRef  = useRef(null);

        var _s = useState(false); var loading = _s[0]; var setLoading = _s[1];
        var _e = useState('');    var error   = _e[0]; var setError   = _e[1];
        var _ap = useState('');   var applePayError = _ap[0]; var setApplePayError = _ap[1];
        var _gp = useState('');   var googlePayError = _gp[0]; var setGooglePayError = _gp[1];
        var _aw = useState('card'); var activeWidget = _aw[0]; var setActiveWidget = _aw[1];
        var _bm = useState(false); var belowMinimum = _bm[0]; var setBelowMinimum = _bm[1];
        var inFlightRetriesRef = useRef(0);
        var inFlightTimerRef = useRef(null);

        /*
         * Widget containers (#ncx-cp-applepay-frame and #ncx-cp-widget-root) are
         * rendered as React elements with refs below. React owns those <div>s but
         * we only mutate their INNER content via raw DOM (the OPP widget), and we
         * never give them React children — the safe "uncontrolled container"
         * pattern. This prevents React reconciliation from removing the OPP
         * widget DOM when the status/button children re-render.
         */

        /* Mount the OPP widget once we have a checkout ID */
        var mountWidget = useCallback(function (checkoutId) {
            if (!widgetRef.current || mountedRef.current) return;
            if (blocksActiveWidget !== 'card') {
                log('Skipping card mount – Apple Pay is active');
                setLoading(false);
                return;
            }
            mountedRef.current = true;
            checkoutIdRef.current = checkoutId;
            unloadCopyAndPayWidget();

            // Configure wpwlOptions (card-only; no Apple Pay).
            window.wpwlOptions = {
                registrations: { requireCvv: true, hideInitialPaymentForms: true },
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
                    widgetReadyRef.current = true;
                    invalidRetriedRef.current = false;
                    setLoading(false);
                    if (widgetRef.current) {
                        widgetRef.current.classList.remove('ncx-cp-widget-loading');
                    }
                    log('Widget ready');
                    // NB: Apple Pay is NOT auto-prefetched here. Auto-prefetch
                    // created a full WooCommerce order + OPP checkout on every
                    // page load, which slowed checkout and left pending orders.
                    // The Apple Pay order is now prepared on intent (hover/touch
                    // of the Apple Pay button) instead.

                    var groupCN  = document.querySelector('.wpwl-group-cardNumber');
                    var groupExp = document.querySelector('.wpwl-group-expiry');
                    var groupCVV = document.querySelector('.wpwl-group-cvv');

                    if (groupCN && groupExp && groupCVV) {
                        var form = groupCN.parentNode;
                        var label = document.createElement('label');
                        label.className = 'ncx-cp-frame-label';
                        label.textContent = 'Enter card details';
                        form.insertBefore(label, groupCN);

                        var row = document.createElement('div');
                        row.className = 'ncx-cp-card-row';
                        form.insertBefore(row, groupCN);
                        row.appendChild(groupCN);
                        row.appendChild(groupExp);
                        row.appendChild(groupCVV);
                    }

                    if (widgetRef.current) {
                        if (widgetRef.current.querySelector('form.wpwl-form-registrations')) {
                            paymentMode = 'registration';
                        }
                        initPaymentContainerTracking(widgetRef.current);
                    }

                    // Save card checkbox (no name=createRegistration).
                    if (settings.allowCardSaving === '1' && settings.loggedIn === '1') {
                        var cardForm = document.querySelector('form.wpwl-form-card');
                        if (cardForm) {
                            var cardRow = cardForm.querySelector('.ncx-cp-card-row');
                            if (cardRow) {
                                var saveHtml = '<p class="ncx-cp-save-card">'
                                    + '<label><input type="checkbox" id="ncxSaveCardConsent" value="true"> <span>Securely store this card?</span></label>'
                                    + '</p>';
                                cardRow.insertAdjacentHTML('afterend', saveHtml);
                            }
                        }
                    }

                },
                onLoadThreeDIframe: function () {
                    // Parity with the classic path: confirm the verifying state
                    // once the challenge is actually on screen, in case the
                    // container changed between submission and the challenge.
                    log('3DS iframe loaded – challenge in progress');
                    if (wrapperRef.current) {
                        wrapperRef.current.classList.add('ncx-cp-payment-verifying');
                        setVerifyingContainer(wrapperRef.current, paymentContainer);
                        tagThreeDsChallengeFrame(wrapperRef.current);
                        // OPP sometimes swaps the iframe in just after this fires.
                        setTimeout(function () {
                            tagThreeDsChallengeFrame(wrapperRef.current);
                        }, 150);
                        // Last resort, once the challenge has had time to lay out.
                        setTimeout(function () {
                            tagThreeDsChallengeFrame(wrapperRef.current, true);
                        }, 800);
                    }
                },
                onError: function (err) {
                    log('Widget error', err);
                    if (widgetRef.current) {
                        widgetRef.current.classList.remove('ncx-cp-widget-loading');
                    }
                    // A cancelled or failed 3DS leaves the challenge state
                    // applied, which keeps the wallets and saved cards hidden.
                    var wasVerifying = !!wrapperRef.current
                        && wrapperRef.current.classList.contains('ncx-cp-payment-verifying');
                    if (wrapperRef.current) {
                        wrapperRef.current.classList.remove('ncx-cp-payment-verifying');
                        clearVerifyingContainer(wrapperRef.current);
                    }
                    showBlocksPlaceOrder();

                    if (err && err.name === 'InvalidCheckoutIdError') {
                        if (invalidRetriedRef.current) {
                            log('Provider rejected the replacement session too – not retrying');
                            setError('Payment could not be started. Please refresh the page and try again.');
                            setLoading(false);
                            return;
                        }
                        invalidRetriedRef.current = true;
                        rejectedCheckoutIdRef.current = checkoutIdRef.current;
                        mountedRef.current = false;
                        widgetReadyRef.current = false;
                        initWidget(true);
                        return;
                    }

                    // The session was handed to the provider and can never be
                    // submitted again; mount a fresh one once it is released.
                    if (wasVerifying) {
                        mountedRef.current = false;
                        widgetReadyRef.current = false;
                        checkoutIdRef.current = null;
                        unloadCopyAndPayWidget();
                        initWidget(true);
                        return;
                    }
                    setError('Card form error. Please refresh and try again.');
                }
            };

            // Create payment form element inside the unmanaged div.
            // Keep the raw OPP fields hidden behind a clean loader until onReady
            // so the shopper never sees the unstyled grey/white skeleton boxes.
            var formEl = document.createElement('form');
            formEl.className = 'paymentWidgets';
            formEl.setAttribute('data-brands', settings.cardBrands || settings.brands || 'VISA MASTER');
            widgetRef.current.innerHTML = '';
            widgetRef.current.classList.add('ncx-cp-widget-loading');
            widgetRef.current.appendChild(formEl);

            // Load OPP script
            var script = document.createElement('script');
            script.src = settings.regionHost + '/v1/paymentWidgets.js?checkoutId=' + encodeURIComponent(checkoutId);
            script.async = true;
            script.setAttribute('data-ncx-card', '1');
            document.head.appendChild(script);
        }, []);

        var cancelInFlightRetry = useCallback(function () {
            if (inFlightTimerRef.current) {
                clearTimeout(inFlightTimerRef.current);
                inFlightTimerRef.current = null;
            }
            inFlightRetriesRef.current = 0;
        }, []);

        /* Request checkout ID and mount */
        var initWidget = useCallback(function (force) {
            if (isCartBelowMinimumPayment()) {
                setBelowMinimum(true);
                setLoading(false);
                return;
            }
            setBelowMinimum(false);
            if (cardInitInFlight) {
                return;
            }
            var now = Date.now();
            if (!force && !rejectedCheckoutIdRef.current && cardInitStartedAt
                && (now - cardInitStartedAt) < CHECKOUT_ID_COALESCE_MS) {
                log('Coalescing card widget init');
                return;
            }
            cardInitStartedAt = now;
            blocksActiveWidget = 'card';
            setLoading(true);
            setError('');
            setActiveWidget('card');
            showCardWidgetRoot(widgetRef.current);
            mountedRef.current = false;
            widgetReadyRef.current = false;
            checkoutIdRef.current = null;
            cardInitInFlight = true;
            var rejected = rejectedCheckoutIdRef.current;
            rejectedCheckoutIdRef.current = null;
            requestCheckoutId(function (err, id, failure) {
                cardInitInFlight = false;
                if (err) {
                    // Retryable: the previous hand-off may still settle, so the
                    // coordinator will not mint yet. Wait it out, then remount.
                    if (failure && failure.code === 'ncx_cp_payment_in_flight') {
                        if (inFlightRetriesRef.current >= IN_FLIGHT_MAX_RETRIES) {
                            setError('We are still waiting for your previous payment to be confirmed. Please reload the page in a minute, and contact us before paying again.');
                            setLoading(false);
                            cancelInFlightRetry();
                            return;
                        }
                        var seconds = parseInt(failure.retryAfter, 10);
                        var delay = seconds > 0 ? (seconds * 1000) : IN_FLIGHT_FALLBACK_MS;
                        inFlightRetriesRef.current++;
                        log('Payment in flight, retrying in', delay, 'ms');
                        setError(failure.message || 'Your payment is still being processed. Please wait.');
                        inFlightTimerRef.current = setTimeout(function () {
                            inFlightTimerRef.current = null;
                            initWidget(true);
                        }, delay);
                        return;
                    }
                    setError(typeof err === 'string' ? err : 'Could not initialise payment.');
                    setLoading(false);
                    return;
                }
                cancelInFlightRetry();
                log('Checkout ID:', id);
                if (blocksActiveWidget !== 'card') {
                    setLoading(false);
                    return;
                }
                mountWidget(id);
            }, rejected);
        }, [mountWidget, cancelInFlightRetry]);

        var initApplePay = useCallback(function () {
            if (!shouldShowApplePayTrigger()) {
                return;
            }

            if (isCartBelowMinimumPayment()) {
                setBelowMinimum(true);
                setApplePayError(getMinimumPaymentNotice());
                return;
            }
            setBelowMinimum(false);

            var validationError = validateBlocksCheckoutFields();
            if (validationError) {
                setApplePayError(validationError);
                scrollBlocksToCheckoutFields();
                return;
            }
            setApplePayError('');

            blocksActiveWidget = 'applepay';
            setActiveWidget('applepay');
            setError('');
            widgetReadyRef.current = false;
            mountedRef.current = false;
            checkoutIdRef.current = null;
            unloadCopyAndPayWidget();
            hideCardWidgetRoot(widgetRef.current);
            // Clear (but keep visible) the in-box Apple Pay frame; the pay widget
            // mounts into it so it stays under the card area.
            if (applePayFrameRef.current) {
                applePayFrameRef.current.innerHTML = '';
                applePayFrameRef.current.style.display = '';
            }
            if (googlePayFrameRef.current) {
                googlePayFrameRef.current.innerHTML = '';
                googlePayFrameRef.current.style.display = 'none';
            }
            hideBlocksPlaceOrder();
            showApplePayPayShellWithRetry(function () {}, 20);
            fetchApplePayCheckoutData(true, function (err, data) {
                if (blocksActiveWidget !== 'applepay') {
                    return;
                }
                if (err) {
                    setApplePayError(typeof err === 'string' ? err : 'Could not initialise Apple Pay.');
                    return;
                }
                if (data && data.checkoutId) {
                    mountApplePayWidget(data.checkoutId, data.regionHost || settings.regionHost);
                }
            });
        }, []);

        var initGooglePay = useCallback(function () {
            if (!shouldShowGooglePayTrigger()) {
                return;
            }

            if (isCartBelowMinimumPayment()) {
                setBelowMinimum(true);
                setGooglePayError(getMinimumPaymentNotice());
                return;
            }
            setBelowMinimum(false);

            var validationError = validateBlocksCheckoutFields();
            if (validationError) {
                setGooglePayError(validationError);
                scrollBlocksToCheckoutFields();
                return;
            }
            setGooglePayError('');

            blocksActiveWidget = 'googlepay';
            setActiveWidget('googlepay');
            setError('');
            widgetReadyRef.current = false;
            mountedRef.current = false;
            checkoutIdRef.current = null;
            unloadCopyAndPayWidget();
            hideCardWidgetRoot(widgetRef.current);
            if (applePayFrameRef.current) {
                applePayFrameRef.current.innerHTML = '';
                applePayFrameRef.current.style.display = 'none';
            }
            if (googlePayFrameRef.current) {
                googlePayFrameRef.current.innerHTML = '';
                googlePayFrameRef.current.style.display = '';
            }
            hideBlocksPlaceOrder();
            var slot = ensureGooglePayPaySlot();
            if (slot) {
                slot.innerHTML = '<p class="ncx-cp-googlepay-preparing">Preparing Google Pay\u2026</p>'
                    + '<p class="ncx-cp-googlepay-back"><button type="button" class="button" id="ncx-cp-googlepay-back-to-card">Pay by card instead</button></p>';
            }
            fetchGooglePayCheckoutData(true, function (err, data) {
                if (blocksActiveWidget !== 'googlepay') {
                    return;
                }
                if (err) {
                    setGooglePayError(typeof err === 'string' ? err : 'Could not initialise Google Pay.');
                    var failedSlot = ensureGooglePayPaySlot();
                    if (failedSlot) {
                        failedSlot.innerHTML = '<p class="ncx-cp-googlepay-preparing" style="color:#b91c1c;">'
                            + String(err) + '</p>'
                            + '<p class="ncx-cp-googlepay-back"><button type="button" class="button" id="ncx-cp-googlepay-back-to-card">Pay by card instead</button></p>';
                    }
                    return;
                }
                if (data && data.checkoutId) {
                    mountGooglePayWidget(data.checkoutId, data.regionHost || settings.regionHost);
                }
            });
        }, []);

        /* Init on mount */
        useEffect(function () {
            var lastTotal = getBlocksCartTotalAmount();

            function onCartStoreChange() {
                setBelowMinimum(isCartBelowMinimumPayment());

                var total = getBlocksCartTotalAmount();
                if (total === null || total === lastTotal) {
                    return;
                }
                lastTotal = total;

                // A cached or mounted Apple Pay session is bound to the amount it
                // was created with, so neither can survive a change to the total.
                invalidateApplePayPrefetch();
                invalidateGooglePayPrefetch();
                if ((blocksActiveWidget === 'applepay' || blocksActiveWidget === 'googlepay') && typeof window.ncxCpBlocksResetCard === 'function') {
                    log('cart total changed – resetting wallet');
                    window.ncxCpBlocksResetCard();
                }
            }

            setBelowMinimum(isCartBelowMinimumPayment());
            var unsubscribe = wp.data.subscribe(onCartStoreChange);
            return unsubscribe;
        }, []);

        useEffect(function () {
            if (belowMinimum) {
                setLoading(false);
                setError('');
                unloadCopyAndPayWidget();
                return;
            }
            initWidget();
            ensureOppPreconnect(settings.regionHost);
        }, [initWidget, belowMinimum]);

        useEffect(function () {
            window.ncxCpBlocksResetCard = function () {
                blocksActiveWidget = 'card';
                if (wrapperRef.current) {
                    wrapperRef.current.classList.remove('ncx-cp-payment-verifying');
                    clearVerifyingContainer(wrapperRef.current);
                }
                removeApplePayPaySlot();
                removeGooglePayPaySlot();
                removeStrayApplePayTriggers();
                showBlocksPlaceOrder();
                if (applePayFrameRef.current) {
                    applePayFrameRef.current.innerHTML = '';
                    applePayFrameRef.current.style.display = '';
                }
                if (googlePayFrameRef.current) {
                    googlePayFrameRef.current.innerHTML = '';
                    googlePayFrameRef.current.style.display = '';
                }
                applePayCheckoutId = null;
                applePayMountedRef = false;
                invalidateApplePayPrefetch();
                googlePayCheckoutId = null;
                invalidateGooglePayPrefetch();
                mountedRef.current = false;
                widgetReadyRef.current = false;
                checkoutIdRef.current = null;
                unloadCopyAndPayWidget();
                initWidget(true);
            };

            return function () {
                cancelInFlightRetry();
                if (window.ncxCpBlocksResetCard) {
                    delete window.ncxCpBlocksResetCard;
                }
            };
        }, [initWidget, cancelInFlightRetry]);

        /* Handle payment submission – pass checkout data to server */
        useEffect(function () {
            var unsubscribe = onPaymentSetup(function () {
                if (isCartBelowMinimumPayment()) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: getMinimumPaymentNotice(),
                    };
                }

                if (!widgetReadyRef.current) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Card form is not ready yet.',
                    };
                }

                var validationError = validateBlocksCheckoutFields();
                if (validationError) {
                    scrollBlocksToCheckoutFields();
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: validationError,
                    };
                }

                getExecutePaymentContainer();
                syncPaymentContainerField();
                if (wrapperRef.current) {
                    wrapperRef.current.classList.add('ncx-cp-payment-verifying');
                    setVerifyingContainer(wrapperRef.current, paymentContainer);
                }

                var saveCard = '0';
                if (paymentContainer === 'card') {
                    var saveCheckbox = document.getElementById('ncxSaveCardConsent');
                    if (saveCheckbox && saveCheckbox.checked) {
                        saveCard = '1';
                    }
                }

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            ncx_cp_checkout_id: checkoutIdRef.current || '',
                            ncx_cp_create_registration: saveCard,
                            ncx_cp_payment_container: paymentContainer,
                        },
                    },
                };
            });

            return unsubscribe;
        }, [onPaymentSetup, emitResponse]);

        /* After server processes the order, execute the OPP card submission.
         * This ensures shopperResultUrl is set before OPP redirects. */
        useEffect(function () {
            var onCheckoutSuccess = eventRegistration.onCheckoutSuccess;
            if (!onCheckoutSuccess) {
                log('onCheckoutSuccess not available, will fall back to onPaymentSetup timing');
                return;
            }

            var unsubscribe = onCheckoutSuccess(function () {
                log('Checkout success – executing OPP payment...');
                if (window.wpwl && typeof window.wpwl.executePayment === 'function') {
                    var container = getExecutePaymentContainer();
                    log('Executing via', container, 'mode=' + paymentMode, 'container=' + paymentContainer);
                    var verifyHost = document.getElementById('ncx-cp-blocks-container');
                    if (verifyHost) {
                        verifyHost.classList.add('ncx-cp-payment-verifying');
                        setVerifyingContainer(verifyHost, paymentContainer);
                    }
                    window.wpwl.executePayment(container);
                } else {
                    log('wpwl.executePayment not available');
                }
                // Prevent WC Blocks from redirecting – OPP handles the redirect.
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                };
            });

            return unsubscribe;
        }, [eventRegistration, emitResponse]);

        useEffect(function () {
            var onCheckoutFail = eventRegistration.onCheckoutFail;
            if (!onCheckoutFail) {
                return;
            }
            return onCheckoutFail(function () {
                if (wrapperRef.current) {
                    wrapperRef.current.classList.remove('ncx-cp-payment-verifying');
                    clearVerifyingContainer(wrapperRef.current);
                }
                invalidateApplePayPrefetch();
                invalidateGooglePayPrefetch();
                mountedRef.current = false;
                widgetReadyRef.current = false;
                checkoutIdRef.current = null;
                unloadCopyAndPayWidget();
                initWidget();
            });
        }, [eventRegistration, initWidget]);

        /*
         * Render: a single wrapper div that React owns.
         * The OPP widget lives in widgetRef (appended via DOM in useEffect),
         * so React never tries to reconcile its children.
         * Only the status message is a React-managed child.
         */
        if (belowMinimum) {
            return createElement('div', {
                ref: wrapperRef,
                id: 'ncx-cp-blocks-container',
                className: wrapperClassName(),
            }, createElement('p', {
                className: 'ncx-cp-minimum-notice',
                style: {
                    textAlign: 'center',
                    fontSize: '1.1rem',
                    fontWeight: '600',
                    margin: '1rem 0',
                },
            }, getMinimumPaymentNotice()));
        }

        var status = null;
        if (activeWidget === 'card') {
            if (error) {
                status = createElement('div', { key: 'err', style: { color: '#ef4444', padding: '12px 0' } }, error);
            } else if (loading) {
                status = createElement('div', {
                    key: 'load',
                    className: 'ncx-cp-card-loader',
                }, [
                    createElement('span', { key: 'sp', className: 'ncx-cp-spinner' }),
                    createElement('span', { key: 'tx', className: 'ncx-cp-card-loader-text' }, 'Loading secure card form\u2026'),
                ]);
            }
        }

        var applePayStatus = null;
        if (isApplePayEnabled() && isApplePayDeviceSupported() && activeWidget === 'card') {
            var applePayChildren = [];

            // Show any validation/error message ABOVE the button, never replacing
            // it, so the Apple Pay button stays visible after a failed check.
            if (applePayError) {
                applePayChildren.push(createElement('div', {
                    key: 'ap-err',
                    style: { color: '#ef4444', padding: '0 0 8px' },
                }, applePayError));
            }

            var applePayButtonProps = {
                key: 'ap-button',
                type: 'button',
                className: 'ncx-cp-applepay-trigger',
                'aria-label': 'Pay with Apple Pay',
                onClick: function () {
                    initApplePay();
                },
            };
            // Apple's mark artwork is a fixed-ratio image of a complete button, so it
            // cannot stretch to the card form width. The outlined button is used instead
            // so this trigger and the Google Pay trigger render at the same width.
            var appleLogo = '<svg viewBox="0 0 20 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
                + '<path fill="currentColor" d="M16.3 12.7c0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.8-2-1.6-.2-3.1.9-3.9.9-.8 0-2-.9-3.3-.9-1.7 0-3.3 1-4.1 2.5-1.8 3.1-.5 7.6 1.3 10.1.9 1.2 1.9 2.6 3.2 2.5 1.3-.1 1.8-.8 3.3-.8 1.5 0 2 .8 3.3.8 1.4 0 2.2-1.2 3.1-2.4 1-1.4 1.4-2.7 1.4-2.8-.1 0-2.7-1-2.7-4.1zM13.8 4.1c.7-.8 1.2-2 1-3.1-1 0-2.2.7-2.9 1.5-.6.7-1.2 1.9-1 3 1.1.1 2.2-.6 2.9-1.4z"/>'
                + '</svg>';
            applePayButtonProps.dangerouslySetInnerHTML = { __html: appleLogo + 'Pay' };

            applePayChildren.push(createElement('button', applePayButtonProps));

            applePayStatus = createElement('div', { key: 'ap-wrap' }, applePayChildren);
        }

        var googlePayStatus = null;
        if (shouldShowGooglePayTrigger() && activeWidget === 'card') {
            var googlePayChildren = [];
            if (googlePayError) {
                googlePayChildren.push(createElement('div', {
                    key: 'gp-err',
                    style: { color: '#ef4444', padding: '0 0 8px' },
                }, googlePayError));
            }
            googlePayChildren.push(createElement('button', {
                key: 'gp-button',
                type: 'button',
                className: 'ncx-cp-googlepay-trigger',
                'aria-label': 'Pay with Google Pay',
                onClick: initGooglePay,
                dangerouslySetInnerHTML: { __html: googlePayLogoMarkup() },
            }));
            googlePayStatus = createElement('div', { key: 'gp-wrap' }, googlePayChildren);
        }

        return createElement('div', {
            ref: wrapperRef,
            id: 'ncx-cp-blocks-container',
            className: wrapperClassName(),
        }, [
            createElement('div', { key: 'ncx-status' }, [applePayStatus, googlePayStatus, status]),
            createElement('div', {
                key: 'ncx-ap-frame',
                id: 'ncx-cp-applepay-frame',
                className: 'ncx-cp-applepay-frame',
                ref: applePayFrameRef,
            }),
            createElement('div', {
                key: 'ncx-gp-frame',
                id: 'ncx-cp-googlepay-frame',
                className: 'ncx-cp-googlepay-frame',
                ref: googlePayFrameRef,
            }),
            createElement('div', {
                key: 'ncx-widget-root',
                id: 'ncx-cp-widget-root',
                ref: widgetRef,
                style: activeWidget === 'applepay' ? { display: 'none' } : undefined,
            }),
            createElement('input', {
                key: 'ncx-container-field',
                type: 'hidden',
                id: 'ncx_cp_payment_container',
                name: 'ncx_cp_payment_container',
                defaultValue: 'card',
            }),
        ]);
    };

    /* ── Label component ─────────────────────────────────── */

    // Renders the payment method title shown in the Blocks payment method list.
    var Label = function () {
        return createElement('span', null, settings.title || 'Pay with card');
    };

    /* ── Register ─────────────────────────────────────────── */

    document.addEventListener('click', function (ev) {
        var target = ev.target;
        if (!target || (target.id !== 'ncx-cp-back-to-card' && target.id !== 'ncx-cp-googlepay-back-to-card')) {
            return;
        }
        if (typeof window.ncxCpBlocksResetCard === 'function') {
            ev.preventDefault();
            window.ncxCpBlocksResetCard();
        }
    });

    registerPaymentMethod({
        name: GATEWAY_ID,
        label: createElement(Label, null),
        content: createElement(CardForm, null),
        edit: createElement('div', null, settings.title || 'NCX CopyAndPay (editor preview)'),
        canMakePayment: function () { return true; },
        ariaLabel: settings.title || 'Pay with card',
        supports: {
            features: settings.supports || ['products'],
        },
    });
})();
