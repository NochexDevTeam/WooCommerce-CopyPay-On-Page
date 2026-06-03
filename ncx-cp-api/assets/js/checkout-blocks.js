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

    function buildIframePlaceholderStyles() {
        var t = settings.typography || {};
        return {
            color: t.placeholder || '#9ca3af',
            'font-size': t.sizeInput || '16px',
            'font-family': t.fontFamily || 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        };
    }

    function syncPaymentContainerField() {
        var field = document.getElementById('ncx_cp_payment_container');
        if (field) {
            field.value = paymentContainer;
        }
    }

    function setPaymentContainer(kind) {
        paymentContainer = kind === 'registration' ? 'registration' : 'card';
        syncPaymentContainerField();
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

    function getWpwlScope() {
        return wpwlRoot || document;
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

    function applyPaymentContainerKind(kind) {
        if (kind === 'registration') {
            paymentMode = 'registration';
        }
        setPaymentContainer(kind);
        return kind === 'registration' ? 'wpwl-container-registration' : 'wpwl-container-card';
    }

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

    function log() {
        if (window.console) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[NCX-Blocks]');
            console.log.apply(console, args);
        }
    }

    function requestCheckoutId(callback) {
        var body = new URLSearchParams();
        body.append('action', 'ncx_cp_request_checkout_id');
        body.append('security', settings.nonce || '');
        body.append('context', 'blocks');

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
                    callback(msg);
                }
            })
            .catch(function (err) {
                log('Checkout ID request error:', err);
                callback(err.message || 'Network error');
            });
    }

    /* ── React component ─────────────────────────────────── */

    var CardForm = function (props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse      = props.emitResponse;
        var onPaymentSetup    = eventRegistration.onPaymentSetup;

        /* Refs – widgetRef is a raw DOM node React never touches */
        var wrapperRef    = useRef(null);
        var widgetRef     = useRef(null);   // created via DOM, outside React tree
        var checkoutIdRef = useRef(null);
        var widgetReadyRef = useRef(false);
        var mountedRef     = useRef(false);

        var _s = useState(false); var loading = _s[0]; var setLoading = _s[1];
        var _e = useState('');    var error   = _e[0]; var setError   = _e[1];

        /* Create the unmanaged widget container once the wrapper mounts */
        useEffect(function () {
            if (wrapperRef.current && !widgetRef.current) {
                var div = document.createElement('div');
                div.id = 'ncx-cp-widget-root';
                wrapperRef.current.appendChild(div);
                widgetRef.current = div;

                var containerField = document.createElement('input');
                containerField.type = 'hidden';
                containerField.id = 'ncx_cp_payment_container';
                containerField.name = 'ncx_cp_payment_container';
                containerField.value = 'card';
                wrapperRef.current.appendChild(containerField);
            }
        }, []);

        /* Mount the OPP widget once we have a checkout ID */
        var mountWidget = useCallback(function (checkoutId) {
            if (!widgetRef.current || mountedRef.current) return;
            mountedRef.current = true;
            checkoutIdRef.current = checkoutId;

            // Configure wpwlOptions
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
                onReady: function () {
                    widgetReadyRef.current = true;
                    setLoading(false);
                    log('Widget ready');

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
                onError: function (err) {
                    log('Widget error', err);
                    setError('Card form error. Please refresh and try again.');
                    if (err && err.name === 'InvalidCheckoutIdError') {
                        mountedRef.current = false;
                        widgetReadyRef.current = false;
                        initWidget();
                    }
                }
            };

            // Create payment form element inside the unmanaged div
            var formEl = document.createElement('form');
            formEl.className = 'paymentWidgets';
            formEl.setAttribute('data-brands', settings.brands || 'VISA MASTER');
            widgetRef.current.innerHTML = '';
            widgetRef.current.appendChild(formEl);

            // Load OPP script
            var script = document.createElement('script');
            script.src = settings.regionHost + '/v1/paymentWidgets.js?checkoutId=' + encodeURIComponent(checkoutId);
            script.async = true;
            document.head.appendChild(script);
        }, []);

        /* Request checkout ID and mount */
        var initWidget = useCallback(function () {
            setLoading(true);
            setError('');
            requestCheckoutId(function (err, id) {
                if (err) {
                    setError(typeof err === 'string' ? err : 'Could not initialise payment.');
                    setLoading(false);
                    return;
                }
                log('Checkout ID:', id);
                mountWidget(id);
            });
        }, [mountWidget]);

        /* Init on mount */
        useEffect(function () {
            initWidget();
        }, [initWidget]);

        /* Handle payment submission – pass checkout data to server */
        useEffect(function () {
            var unsubscribe = onPaymentSetup(function () {
                if (!widgetReadyRef.current) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Card form is not ready yet.',
                    };
                }

                getExecutePaymentContainer();
                syncPaymentContainerField();

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

        /*
         * Render: a single wrapper div that React owns.
         * The OPP widget lives in widgetRef (appended via DOM in useEffect),
         * so React never tries to reconcile its children.
         * Only the status message is a React-managed child.
         */
        var status = null;
        if (error) {
            status = createElement('div', { key: 'err', style: { color: '#ef4444', padding: '12px 0' } }, error);
        } else if (loading) {
            status = createElement('div', { key: 'load', style: { padding: '16px 0', color: '#6b7280' } }, 'Loading secure card form\u2026');
        }

        return createElement('div', {
            ref: wrapperRef,
            id: 'ncx-cp-blocks-container',
            className: 'ncx-cp-inline-wrapper',
        }, status);
    };

    /* ── Label component ─────────────────────────────────── */

    var Label = function () {
        return createElement('span', null, settings.title || 'Pay with card');
    };

    /* ── Register ─────────────────────────────────────────── */

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
