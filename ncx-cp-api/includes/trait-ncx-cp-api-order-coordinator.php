<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-owned single-order coordinator for NCX checkout attempts.
 */
trait NCX_CP_API_Order_Coordinator {

    private const ATTEMPT_SESSION_KEY = 'ncx_cp_checkout_attempt_uuid';
    private const CANONICAL_ORDER_SESSION_KEY = 'ncx_cp_canonical_order_id';
    private const ATTEMPT_META_KEY = '_ncx_cp_attempt_uuid';
    private const SESSION_SCOPE_META_KEY = '_ncx_cp_session_scope';
    private const SESSION_EXPIRES_META_KEY = '_ncx_cp_session_expires';
    private const LIFECYCLE_META_KEY = '_ncx_cp_lifecycle';
    private const GENERATION_META_KEY = '_ncx_cp_checkout_generation';
    private const CART_REVISION_META_KEY = '_ncx_cp_cart_revision'; // Legacy only.
    private const ORDER_FINGERPRINT_META_KEY = '_ncx_cp_order_fingerprint';
    private const PROVIDER_FINGERPRINT_META_KEY = '_ncx_cp_provider_fingerprint';
    private const RESERVATION_TOKEN_META_KEY = '_ncx_cp_provider_reservation';
    private const RESERVATION_STATE_META_KEY = '_ncx_cp_provider_reservation_state';
    private const RESERVATION_EXPIRES_META_KEY = '_ncx_cp_provider_reservation_expires';
    private const PROVIDER_CHECKOUT_CREATED_META_KEY = '_ncx_cp_provider_checkout_created';
    private const PROVIDER_CHECKOUT_KIND_META_KEY = '_ncx_cp_provider_checkout_kind';
    private const AMBIGUOUS_META_KEY = '_ncx_cp_payment_ambiguous';
    private const AMBIGUOUS_RECHECK_META_KEY = '_ncx_cp_ambiguous_recheck_at';
    private const HANDOFF_RECHECK_META_KEY = '_ncx_cp_handoff_recheck_at';
    private const PAYMENT_STARTED_META_KEY = '_ncx_cp_payment_started_at';
    private const HANDOFF_GENERATION_META_KEY = '_ncx_cp_payment_started_generation';
    private const PROVIDER_ATTEMPTS_META_KEY = '_ncx_cp_provider_session_attempts';
    private const PROVIDER_ATTEMPTS_WINDOW_META_KEY = '_ncx_cp_provider_session_window_start';
    private const ATTEMPT_REGISTRY_PREFIX = 'ncx_cp_attempt_registry_';
    private const LOCK_OPTION_PREFIX = 'ncx_cp_attempt_lock_';
    private const PREFETCH_BREAKER_OPTION = 'ncx_cp_prefetch_breaker_until';
    private const PREFETCH_COUNTER_TRANSIENT = 'ncx_cp_prefetch_creates_5m';
    private const ALERT_META_KEY = '_ncx_cp_invariant_alerted';
    private const BUY_NOW_CART_SESSION_KEY = 'ncx_cp_buy_now_cart_snapshot';
    // Merchant-tunable via the Payment attempt limit setting. The bounds are a
    // hard floor and ceiling: the limit exists to stop one order minting provider
    // sessions without end, so it can be relaxed for testing but never disabled.
    private const DEFAULT_PROVIDER_SESSION_ATTEMPTS = 10;
    private const MIN_PROVIDER_SESSION_ATTEMPTS = 3;
    private const MAX_PROVIDER_SESSION_ATTEMPTS = 30;
    private const PROVIDER_ATTEMPTS_WINDOW_SECONDS = 10 * MINUTE_IN_SECONDS;
    private const LOCK_TTL_SECONDS = 30;
    private const LOCK_WAIT_MS = 2000;
    private const RESERVATION_TTL_SECONDS = 75;
    // The provider expires a checkout 30 minutes after it is created. Reuse has
    // to stop well before that, or the widget is handed a session the provider
    // has already killed ("checkoutId ... is invalid"). Half the provider
    // window leaves the shopper at least 15 minutes to complete the form.
    private const PROVIDER_CHECKOUT_TTL_SECONDS = 15 * MINUTE_IN_SECONDS;
    private const PAYMENT_FREEZE_SECONDS = 10 * MINUTE_IN_SECONDS;
    private const HANDOFF_GRACE_SECONDS = 60;
    // Poll interval handed to the browser once the grace has passed and only a
    // later provider answer can release the order.
    private const IN_FLIGHT_POLL_SECONDS = 15;
    // Minimum gap between provider re-reads of a frozen order. The browser
    // retries while it waits, so without this each shopper would fan out.
    private const AMBIGUOUS_RECHECK_SECONDS = 30;
    // Same idea for a session still inside the hand-off grace: the grace is now
    // asked about rather than waited out, so it needs its own read throttle or
    // every reload buys another provider round-trip.
    private const HANDOFF_RECHECK_SECONDS = 15;
    // How long a handed-off session may sit "pending" before it is read as an
    // abandoned 3DS2 challenge rather than one still in progress. A completed
    // authentication reaches the provider within seconds, and the challenge is
    // a cross-origin iframe inside our own form: once the shopper closes or
    // reloads the page it is gone and there is no route back to it. Past this
    // window a pending session can therefore no longer authorise, so holding
    // the order only stops a shopper paying. Raise it to trade recovery speed
    // for margin against a challenge completed just before the page was lost.
    private const ABANDONED_CHALLENGE_SECONDS = 90;
    private const ATTEMPT_TTL_SECONDS = 2 * DAY_IN_SECONDS;

    private function register_order_coordinator_hooks(): void {
        add_filter('cron_schedules', [$this, 'add_ncx_five_minute_cron_schedule']);
        add_filter('woocommerce_create_order', [$this, 'filter_create_order_reuse_canonical'], 10, 2);
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '6.0', '>=')) {
            add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'adopt_store_api_checkout_order'], 5, 2);
        }
    }

    /**
     * @param array<string, int> $schedules
     * @return array<string, array<string, mixed>>
     */
    public function add_ncx_five_minute_cron_schedule(array $schedules): array {
        if (!isset($schedules['ncx_cp_every_five_minutes'])) {
            $schedules['ncx_cp_every_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every five minutes (NCX)', 'ncx-cp-api'),
            ];
        }

        return $schedules;
    }

    /**
     * @param mixed $order_id
     * @param mixed $checkout
     * @return mixed
     */
    public function filter_create_order_reuse_canonical($order_id, $checkout = null) {
        if (!$this->is_classic_ncx_checkout_request()) {
            return $order_id;
        }

        $canonical = $this->get_canonical_ncx_order();
        if (!$canonical instanceof WC_Order) {
            return $order_id;
        }

        if (!$this->order_matches_current_attempt($canonical)) {
            return $order_id;
        }

        if ($canonical->is_paid() || in_array($canonical->get_status(), ['processing', 'completed', 'refunded', 'cancelled'], true)) {
            return $order_id;
        }

        $this->promote_canonical_order_to_intent($canonical, 'card');

        return (int) $canonical->get_id();
    }

    /**
     * @param mixed $order
     * @param mixed $request
     */
    public function adopt_store_api_checkout_order($order, $request = null): void {
        if (!$order instanceof WC_Order) {
            return;
        }
        $payment_method = '';
        if (is_object($request) && is_callable([$request, 'get_param'])) {
            $payment_method = (string) $request->get_param('payment_method');
        }
        if ('' === $payment_method) {
            $payment_method = (string) $order->get_payment_method();
        }
        if ('' === $payment_method && function_exists('WC') && WC()->session) {
            $payment_method = (string) WC()->session->get('chosen_payment_method');
        }
        if ($this->id !== $payment_method) {
            return;
        }

        $canonical = $this->get_canonical_ncx_order();
        if (!$canonical instanceof WC_Order || (int) $canonical->get_id() === (int) $order->get_id()) {
            if ($order->get_payment_method() === $this->id || '' === (string) $order->get_payment_method()) {
                $this->bind_order_to_current_attempt($order);
                $this->promote_canonical_order_to_intent($order, 'card');
            }
            return;
        }

        if (!$this->order_matches_current_attempt($canonical)) {
            return;
        }

        $canonical->add_order_note(__('Store API presented a different order; NCX payment was stopped for review.', 'ncx-cp-api'));
        $canonical->save();
        $order->add_order_note(__('NCX canonical-order conflict; payment was not started.', 'ncx-cp-api'));
        $order->save();

        $exception = '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException';
        if (class_exists($exception)) {
            throw new $exception(
                'ncx_cp_order_conflict',
                __('Checkout order changed. Please refresh and try again.', 'ncx-cp-api'),
                409
            );
        }
        throw new \Exception(__('Checkout order changed. Please refresh and try again.', 'ncx-cp-api'));
    }

    private function is_classic_ncx_checkout_request(): bool {
        if (function_exists('is_checkout') && !is_checkout()) {
            return false;
        }

        $posted = isset($_POST['payment_method'])
            ? sanitize_text_field(wp_unslash($_POST['payment_method']))
            : '';
        if ('' !== $posted) {
            return $this->id === $posted;
        }

        return function_exists('WC') && WC()->session
            && $this->id === (string) WC()->session->get('chosen_payment_method');
    }

    private function get_or_create_checkout_attempt_uuid(bool $rotate = false): string {
        $scope = $this->current_session_scope_hash();
        if ('' === $scope) {
            return $this->generate_attempt_uuid();
        }

        $option = self::ATTEMPT_REGISTRY_PREFIX . substr($scope, 0, 40);
        $current = $this->read_raw_option($option);
        $record = $this->decode_attempt_record($current);
        if (!$rotate && $record && (int) $record['expires'] > time()) {
            $attempt = (string) $record['attempt'];
            WC()->session->set(self::ATTEMPT_SESSION_KEY, $attempt);
            return $attempt;
        }

        $uuid = $this->generate_attempt_uuid();
        $payload = wp_json_encode([
            'attempt' => $uuid,
            'expires' => time() + self::ATTEMPT_TTL_SECONDS,
        ]);

        if ('' === $current) {
            if (!$this->insert_named_option($option, (string) $payload)) {
                $winner = $this->decode_attempt_record($this->read_raw_option($option));
                if ($winner) {
                    $uuid = (string) $winner['attempt'];
                }
            }
        } elseif (!$this->compare_and_swap_option($option, $current, (string) $payload)) {
            $winner = $this->decode_attempt_record($this->read_raw_option($option));
            if ($winner) {
                $uuid = (string) $winner['attempt'];
            }
        }

        WC()->session->set(self::ATTEMPT_SESSION_KEY, $uuid);

        return $uuid;
    }

    private function current_session_scope_hash(): string {
        if (!function_exists('WC') || !WC()->session) {
            return '';
        }

        $customer_id = method_exists(WC()->session, 'get_customer_id')
            ? (string) WC()->session->get_customer_id()
            : '';
        if ('' === $customer_id) {
            return '';
        }

        return hash_hmac('sha256', 'woo-session:' . $customer_id, wp_salt('auth'));
    }

    private function decode_attempt_record(string $payload): ?array {
        if ('' === $payload) {
            return null;
        }
        $record = json_decode($payload, true);
        if (!is_array($record)
            || !$this->is_valid_attempt_uuid((string) ($record['attempt'] ?? ''))
            || (int) ($record['expires'] ?? 0) <= 0
        ) {
            return null;
        }
        return $record;
    }

    private function generate_attempt_uuid(): string {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        return strtolower(wp_generate_password(32, false, false));
    }

    private function is_valid_attempt_uuid(string $uuid): bool {
        return (bool) preg_match('/^[a-f0-9-]{16,36}$/i', $uuid);
    }

    /**
     * @param array<string, mixed> $billing_contact
     * @param array<string, mixed> $shipping_contact
     * @return array{order: WC_Order|WP_Error, reused: bool, generation?: int, checkout_id?: string}
     */
    private function coordinate_wallet_order(string $mode, array $billing_contact = [], array $shipping_contact = []): array {
        $mode = 'prefetch' === $mode ? 'prefetch' : 'intent';

        if ('prefetch' === $mode && $this->is_prefetch_suspended()) {
            return [
                'order'  => new WP_Error(
                    'ncx_cp_prefetch_suspended',
                    __('Checkout is busy. Please tap Pay to continue.', 'ncx-cp-api'),
                    ['status' => 429]
                ),
                'reused' => false,
            ];
        }

        $throttle = $this->assert_attempt_creation_allowed();
        if (is_wp_error($throttle)) {
            return ['order' => $throttle, 'reused' => false];
        }

        $attempt = $this->get_or_create_checkout_attempt_uuid();
        $lock = $this->acquire_attempt_lock($attempt);
        if (is_wp_error($lock)) {
            return ['order' => $lock, 'reused' => false];
        }

        try {
            $order_result = $this->locate_or_create_canonical_order($billing_contact, $shipping_contact, $attempt, $mode);
            if (is_wp_error($order_result['order'])) {
                return $order_result;
            }

            /** @var WC_Order $order */
            $order = $order_result['order'];

            if ('intent' === $mode) {
                $this->promote_canonical_order_to_intent($order, 'wallet');
            } else {
                $this->mark_order_prefetch($order);
            }

            $this->store_canonical_order_pointer((int) $order->get_id());

            return $order_result;
        } finally {
            $this->release_attempt_lock($lock);
        }
    }

    /**
     * @param array<string, mixed> $billing_contact
     * @param array<string, mixed> $shipping_contact
     * @return array{order: WC_Order|WP_Error, reused: bool}
     */
    private function locate_or_create_canonical_order(array $billing_contact, array $shipping_contact, string $attempt = '', string $mode = 'intent'): array {
        if ('' === $attempt) {
            $attempt = $this->get_or_create_checkout_attempt_uuid();
        }
        $existing = $this->find_canonical_order_for_attempt($attempt);

        if ($existing instanceof WC_Order) {
            // A freeze records an unknown outcome, so ask the provider again
            // before refusing: it may have settled since. Only a definitive
            // answer releases the order; see recheck_frozen_payment().
            if ('yes' === (string) $existing->get_meta(self::AMBIGUOUS_META_KEY, true)) {
                $recheck = $this->recheck_frozen_payment($existing);
                if (null !== $recheck) {
                    return ['order' => $recheck, 'reused' => true];
                }

                $released = wc_get_order($existing->get_id());
                if ($released instanceof WC_Order) {
                    $existing = $released;
                }
            }

            // Held for verification or manual review: starting another order
            // here risks a second charge for the same goods, so fail closed.
            if ('on-hold' === $existing->get_status()) {
                return [
                    'order'  => new WP_Error(
                        'ncx_cp_order_frozen',
                        __('A payment for this order is being verified. Please contact us before trying again.', 'ncx-cp-api'),
                        ['status' => 409]
                    ),
                    'reused' => true,
                ];
            }

            // A settled order must never be reused, but the shopper is entitled
            // to buy again: retire the attempt and start a new canonical order.
            // Rotation normally happens when the result is processed, which the
            // server-to-server notification path cannot do (it has no session).
            if ($this->order_payment_is_closed($existing)) {
                $this->rotate_attempt_after_paid($existing);
                $rotated = $this->get_or_create_checkout_attempt_uuid();
                if ($rotated === $attempt) {
                    return [
                        'order'  => new WP_Error(
                            'ncx_cp_order_frozen',
                            __('This order is already paid. Please reload the page to start a new order.', 'ncx-cp-api'),
                            ['status' => 409]
                        ),
                        'reused' => true,
                    ];
                }

                return $this->locate_or_create_canonical_order($billing_contact, $shipping_contact, $rotated, $mode);
            }

            $refreshed = $this->refresh_order_from_cart($existing, $billing_contact, $shipping_contact);
            if (is_wp_error($refreshed)) {
                $this->log_event('warning', 'Canonical order cart sync failed; keeping existing order', [
                    'order_id' => $existing->get_id(),
                ]);
                $this->store_canonical_order_pointer((int) $existing->get_id());

                return [
                    'order'  => $existing,
                    'reused' => true,
                ];
            }

            $this->bind_order_to_current_attempt($refreshed);
            $this->store_canonical_order_pointer((int) $refreshed->get_id());

            return [
                'order'  => $refreshed,
                'reused' => true,
            ];
        }

        $quota = $this->consume_attempt_creation_quota();
        if (is_wp_error($quota)) {
            return ['order' => $quota, 'reused' => false];
        }

        $created = $this->create_canonical_order_from_cart($billing_contact, $shipping_contact, $attempt);
        if (is_wp_error($created)) {
            return ['order' => $created, 'reused' => false];
        }

        $this->store_canonical_order_pointer((int) $created->get_id());

        return [
            'order'  => $created,
            'reused' => false,
        ];
    }

    /**
     * @param array<string, mixed> $billing_contact
     * @param array<string, mixed> $shipping_contact
     * @return WC_Order|WP_Error
     */
    private function create_canonical_order_from_cart(array $billing_contact, array $shipping_contact, string $attempt) {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return new WP_Error('ncx_cp_empty_cart', __('Cart is empty.', 'ncx-cp-api'));
        }

        $status = $this->get_prefetch_order_status();
        $customer_id = get_current_user_id();
        try {
            $order = new WC_Order();
            $order->set_customer_id($customer_id > 0 ? $customer_id : 0);
            $order->set_status($status);
            $order->set_payment_method($this);
            $order->update_meta_data(self::ATTEMPT_META_KEY, $attempt);
            $order->update_meta_data(self::SESSION_SCOPE_META_KEY, $this->current_session_scope_hash());
            $order->update_meta_data(self::SESSION_EXPIRES_META_KEY, (string) (time() + self::ATTEMPT_TTL_SECONDS));
            $order->update_meta_data(self::LIFECYCLE_META_KEY, 'prefetch');
            $order->save();
        } catch (\Exception $e) {
            return new WP_Error('ncx_cp_order_create', $e->getMessage());
        }
        $this->store_canonical_order_pointer((int) $order->get_id());

        $synced = $this->sync_order_from_cart($order, $billing_contact, $shipping_contact);
        if (is_wp_error($synced)) {
            $this->log_event('warning', 'Created canonical order but cart sync failed', [
                'order_id' => $order->get_id(),
            ]);

            return $order;
        }

        $this->bind_order_to_current_attempt($synced, $attempt);
        return $synced;
    }

    private function get_prefetch_order_status(): string {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];

        return isset($statuses['wc-checkout-draft']) ? 'checkout-draft' : 'pending';
    }

    private function bind_order_to_current_attempt(WC_Order $order, string $attempt = ''): void {
        if ('' === $attempt) {
            $attempt = $this->get_or_create_checkout_attempt_uuid();
        }

        $order->update_meta_data(self::ATTEMPT_META_KEY, $attempt);
        $scope = $this->current_session_scope_hash();
        if ('' !== $scope) {
            $order->update_meta_data(self::SESSION_SCOPE_META_KEY, $scope);
            $order->update_meta_data(self::SESSION_EXPIRES_META_KEY, (string) (time() + self::ATTEMPT_TTL_SECONDS));
        }
        $order->set_payment_method($this);
        $revision = $this->current_cart_revision();
        if ('' !== $revision) {
            $order->update_meta_data(self::CART_REVISION_META_KEY, $revision);
        }
        $fingerprint = $this->build_order_fingerprint($order);
        $previous = (string) $order->get_meta(self::ORDER_FINGERPRINT_META_KEY, true);
        if ('' !== $fingerprint && '' !== $previous && !hash_equals($previous, $fingerprint)) {
            $this->invalidate_provider_checkout($order, false);
        }
        if ('' !== $fingerprint) {
            $order->update_meta_data(self::ORDER_FINGERPRINT_META_KEY, $fingerprint);
        }
        $order->save();
    }

    private function build_order_fingerprint(WC_Order $order): string {
        $items = [];
        foreach (['line_item', 'fee', 'shipping', 'coupon', 'tax'] as $type) {
            foreach ($order->get_items($type) as $item) {
                $items[] = [
                    'type' => $type,
                    'id' => method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0,
                    'variation' => method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0,
                    'name' => method_exists($item, 'get_name') ? (string) $item->get_name() : '',
                    'quantity' => method_exists($item, 'get_quantity') ? (string) $item->get_quantity() : '',
                    'total' => method_exists($item, 'get_total') ? (string) $item->get_total() : '',
                    'tax' => method_exists($item, 'get_total_tax') ? (string) $item->get_total_tax() : '',
                ];
            }
        }

        $data = [
            'amount' => $this->format_amount($order->get_total()),
            'currency' => $order->get_currency(),
            'cart_hash' => $order->get_cart_hash(),
            'shipping_total' => (string) $order->get_shipping_total(),
            'shipping_tax' => (string) $order->get_shipping_tax(),
            'discount_total' => (string) $order->get_discount_total(),
            'discount_tax' => (string) $order->get_discount_tax(),
            'total_tax' => (string) $order->get_total_tax(),
            'items' => $items,
        ];

        return hash_hmac('sha256', (string) wp_json_encode($data), wp_salt('auth'));
    }

    private function current_cart_revision(): string {
        if (!function_exists('WC') || !WC()->cart) {
            return '';
        }

        return (string) WC()->cart->get_cart_hash();
    }

    private function find_canonical_order_for_attempt(string $attempt): ?WC_Order {
        $from_session = $this->get_canonical_ncx_order();
        if ($from_session instanceof WC_Order && $this->order_matches_attempt($from_session, $attempt)) {
            return $from_session;
        }

        $orders = wc_get_orders([
            'limit'      => 5,
            'status'     => ['checkout-draft', 'pending', 'failed', 'on-hold'],
            'return'     => 'objects',
            'meta_query' => [
                [
                    'key'   => self::ATTEMPT_META_KEY,
                    'value' => $attempt,
                ],
            ],
            'payment_method' => $this->id,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        foreach ($orders as $order) {
            if ($order instanceof WC_Order && $this->order_matches_attempt($order, $attempt)) {
                return $order;
            }
        }

        return null;
    }

    private function get_canonical_ncx_order(): ?WC_Order {
        if (!function_exists('WC') || !WC()->session) {
            return null;
        }

        $order_id = (int) WC()->session->get(self::CANONICAL_ORDER_SESSION_KEY);
        if ($order_id <= 0) {
            $legacy = (int) WC()->session->get(self::APPLE_PAY_PENDING_ORDER_SESSION_KEY);
            if ($legacy > 0) {
                $order_id = $legacy;
            }
        }

        if ($order_id <= 0) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return null;
        }

        if ($order->get_payment_method() !== $this->id) {
            return null;
        }

        return $order;
    }

    private function store_canonical_order_pointer(int $order_id): void {
        if ($order_id <= 0 || !function_exists('WC') || !WC()->session) {
            return;
        }

        WC()->session->set(self::CANONICAL_ORDER_SESSION_KEY, $order_id);
        WC()->session->set(self::APPLE_PAY_PENDING_ORDER_SESSION_KEY, $order_id);
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '6.0', '>=')) {
            WC()->session->set('store_api_draft_order', $order_id);
        }

        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order && $this->order_matches_current_attempt($order)) {
            // Core's wc_clear_cart_after_payment() runs on every front-end page
            // load and empties the cart whenever this key names an order whose
            // status is not failed, pending or cancelled — it reads any other
            // status as "the payment must have gone through". A prefetch order
            // is an unpaid checkout-draft, so publishing it here costs the
            // shopper their cart on their next page view. Only advertise the
            // order once it carries a status core recognises as still unpaid;
            // WooCommerce reads store_api_draft_order for held-stock checks, and
            // filter_create_order_reuse_canonical owns order reuse regardless.
            if ($order->has_status(['pending', 'failed', 'cancelled'])) {
                WC()->session->set('order_awaiting_payment', $order_id);
            }
        }
    }

    private function clear_canonical_order_pointer(?int $order_id = null): void {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        $stored = (int) WC()->session->get(self::CANONICAL_ORDER_SESSION_KEY);
        if ($order_id && $stored > 0 && $stored !== $order_id) {
            return;
        }

        $awaiting = (int) WC()->session->get('order_awaiting_payment');
        if ($awaiting > 0) {
            $awaiting_order = wc_get_order($awaiting);
            if ($awaiting_order instanceof WC_Order && $awaiting_order->get_payment_method() !== $this->id) {
                WC()->session->__unset(self::CANONICAL_ORDER_SESSION_KEY);
                WC()->session->__unset(self::APPLE_PAY_PENDING_ORDER_SESSION_KEY);
                return;
            }
            if ($order_id && $awaiting !== $order_id) {
                WC()->session->__unset(self::CANONICAL_ORDER_SESSION_KEY);
                WC()->session->__unset(self::APPLE_PAY_PENDING_ORDER_SESSION_KEY);
                return;
            }
            if ($awaiting_order instanceof WC_Order && $this->order_matches_current_attempt($awaiting_order)) {
                WC()->session->__unset('order_awaiting_payment');
            }
        }

        WC()->session->__unset(self::CANONICAL_ORDER_SESSION_KEY);
        WC()->session->__unset(self::APPLE_PAY_PENDING_ORDER_SESSION_KEY);
        $store_draft = (int) WC()->session->get('store_api_draft_order');
        if (!$order_id || $store_draft === $order_id) {
            WC()->session->__unset('store_api_draft_order');
        }
    }

    private function order_matches_current_attempt(WC_Order $order): bool {
        return $this->order_matches_attempt($order, $this->get_or_create_checkout_attempt_uuid());
    }

    private function order_matches_attempt(WC_Order $order, string $attempt): bool {
        if ($order->get_payment_method() !== $this->id) {
            return false;
        }

        $stored = (string) $order->get_meta(self::ATTEMPT_META_KEY, true);

        return $this->is_valid_attempt_uuid($stored) && hash_equals($stored, $attempt);
    }

    /**
     * An order that is settled, or whose outcome we could not classify, must
     * never be reused for payment. Ambiguity is deliberately not time-bounded:
     * it is cleared by a definitive provider outcome or by manual review.
     */
    private function order_payment_is_closed(WC_Order $order): bool {
        return $order->is_paid()
            || in_array($order->get_status(), ['processing', 'completed', 'on-hold'], true)
            || 'yes' === (string) $order->get_meta(self::AMBIGUOUS_META_KEY, true);
    }

    /**
     * Whether a handed-off payment could still be settling at the provider.
     * This blocks minting a *replacement* session only; reusing the session
     * already handed to the browser stays allowed so an unsubmitted attempt
     * (client-side card validation, closed wallet sheet) can be retried.
     */
    private function payment_execution_in_flight(WC_Order $order): bool {
        return $this->payment_handoff_age($order, self::PAYMENT_FREEZE_SECONDS);
    }

    /**
     * Short window immediately after a hand-off, used to absorb double
     * submissions without stranding a shopper whose attempt never reached the
     * provider.
     */
    private function payment_handoff_within_grace(WC_Order $order): bool {
        return $this->payment_handoff_age($order, self::HANDOFF_GRACE_SECONDS);
    }

    private function payment_handoff_age(WC_Order $order, int $window): bool {
        if ('payment_started' !== (string) $order->get_meta(self::LIFECYCLE_META_KEY, true)) {
            return false;
        }

        $started = (int) $order->get_meta(self::PAYMENT_STARTED_META_KEY, true);
        if ($started <= 0) {
            $modified = $order->get_date_modified();
            $started = $modified ? (int) $modified->getTimestamp() : 0;
        }

        return $started > 0 && (time() - $started) < $window;
    }

    private function mark_order_prefetch(WC_Order $order): void {
        if ('intent' === (string) $order->get_meta(self::LIFECYCLE_META_KEY, true)) {
            return;
        }

        // Never downgrade a live hand-off: that would drop the in-flight guard.
        if ($this->payment_execution_in_flight($order)) {
            return;
        }

        $order->update_meta_data(self::LIFECYCLE_META_KEY, 'prefetch');
        $draft_status = $this->get_prefetch_order_status();
        if ($draft_status === $order->get_status() || 'failed' === $order->get_status()) {
            $order->save();
            return;
        }

        if (in_array($order->get_status(), ['pending', 'checkout-draft'], true)) {
            if ('checkout-draft' === $draft_status && 'pending' === $order->get_status()) {
                $order->save();
                return;
            }
        }

        $order->save();
    }

    private function promote_canonical_order_to_intent(WC_Order $order, string $source): void {
        if ($this->order_payment_is_closed($order) || $this->payment_execution_in_flight($order)) {
            return;
        }

        $order->update_meta_data(self::LIFECYCLE_META_KEY, 'intent');
        if (!in_array($order->get_status(), ['pending', 'failed', 'on-hold'], true)) {
            $order->set_status('pending', __('Customer started payment.', 'ncx-cp-api'));
        } elseif ('failed' === $order->get_status()) {
            $order->set_status('pending', __('Customer retrying payment.', 'ncx-cp-api'));
        }
        $order->save();
    }

    /**
     * @return string|WP_Error Owner token or error.
     */
    private function acquire_attempt_lock(string $attempt = '') {
        if ('' === $attempt) {
            $attempt = $this->get_or_create_checkout_attempt_uuid();
        }
        $option  = $this->lock_option_name($attempt);
        $deadline = microtime(true) + (self::LOCK_WAIT_MS / 1000);
        $owner = $this->generate_attempt_uuid();

        do {
            $this->expire_stale_attempt_lock($option);
            if ($this->insert_lock_option($option, $owner)) {
                return ['attempt' => $attempt, 'option' => $option, 'owner' => $owner];
            }

            $existing = $this->get_canonical_ncx_order();
            if ($existing instanceof WC_Order && $this->order_matches_current_attempt($existing)) {
                usleep(150000);
                continue;
            }

            usleep(150000);
        } while (microtime(true) < $deadline);

        $this->log_event('warning', 'Attempt lock timeout', []);

        return new WP_Error(
            'ncx_cp_lock_timeout',
            __('Checkout is busy. Please try again.', 'ncx-cp-api'),
            ['status' => 409]
        );
    }

    private function insert_lock_option(string $option, string $owner): bool {
        $payload = wp_json_encode([
            'owner'   => $owner,
            'expires' => time() + self::LOCK_TTL_SECONDS,
        ]);

        return $this->insert_named_option($option, (string) $payload);
    }

    private function expire_stale_attempt_lock(string $option): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ));
        if (!is_string($row) || '' === $row) {
            return;
        }

        $data = json_decode($row, true);
        if (!is_array($data) || (int) ($data['expires'] ?? 0) > time()) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $option,
            $row
        ));
    }

    private function release_attempt_lock($lock): void {
        if (!is_array($lock) || empty($lock['owner']) || empty($lock['option'])) {
            return;
        }

        global $wpdb;
        $option = (string) $lock['option'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ));
        if (!is_string($row)) {
            return;
        }

        $data = json_decode($row, true);
        if (!is_array($data) || (string) ($data['owner'] ?? '') !== (string) $lock['owner']) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $option,
            $row
        ));
    }

    private function lock_option_name(string $attempt): string {
        return self::LOCK_OPTION_PREFIX . substr(preg_replace('/[^a-f0-9]/i', '', $attempt), 0, 32);
    }

    private function read_raw_option(string $option): string {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ));
        return is_string($value) ? $value : '';
    }

    /**
     * Atomic test-and-set on an option row.
     *
     * The unique index on option_name is the whole mechanism: a duplicate key
     * IS the answer, and every caller reads false as "another request got here
     * first". wpdb cannot know that and treats it as a failed query, so
     * print_error() logs every contended insert as a WordPress database error
     * and, when WP_DEBUG_DISPLAY is on, echoes an HTML div into the response —
     * which lands in front of the JSON that the wallet and checkout-ID
     * endpoints return. Errors are therefore suppressed across the insert and
     * classified afterwards, so a genuine database fault is still reported.
     * Do not pre-check with a SELECT instead; that reopens the race this
     * closes.
     */
    private function insert_named_option(string $option, string $value): bool {
        global $wpdb;

        $suppressed = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $option,
            $value
        ));
        $error = (string) $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);

        if (false === $result && '' !== $error && !$this->is_duplicate_key_error($error)) {
            // Option name and driver message only. option_value carries the
            // lock owner token and is never logged.
            $this->log_event('warning', 'Option insert failed', [
                'option' => $option,
                'error'  => $error,
            ]);
        }

        return false !== $result && (int) $result > 0;
    }

    // Matched on the message because wpdb exposes no driver error number.
    private function is_duplicate_key_error(string $error): bool {
        return false !== stripos($error, 'duplicate entry');
    }

    private function compare_and_swap_option(string $option, string $old, string $new): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $new,
            $option,
            $old
        ));
        return 1 === (int) $result;
    }

    /**
     * @return true|WP_Error
     */
    private function assert_attempt_creation_allowed() {
        return true;
    }

    private function consume_attempt_creation_quota() {
        $ip_hash = $this->hashed_remote_ip();
        $user_id = get_current_user_id();
        $windows = [
            '10m' => [10 * MINUTE_IN_SECONDS, 10],
            '1h'  => [HOUR_IN_SECONDS, 50],
        ];

        foreach ($windows as $label => [$ttl, $max]) {
            $keys = ['ip_' . $ip_hash];
            if ($user_id > 0) {
                $keys[] = 'user_' . hash_hmac('sha256', (string) $user_id, wp_salt('auth'));
            }

            foreach ($keys as $scope) {
                $result = $this->atomic_fixed_window_increment(
                    'ncx_cp_counter_' . $label . '_' . substr($scope, 0, 48),
                    $ttl,
                    $max
                );
                if (is_wp_error($result)) {
                    return $result;
                }
            }
        }

        return true;
    }

    private function consume_prefetch_provider_quota() {
        $result = $this->atomic_fixed_window_increment(
            'ncx_cp_counter_prefetch_global',
            5 * MINUTE_IN_SECONDS,
            100
        );
        if (is_wp_error($result)) {
            update_option(self::PREFETCH_BREAKER_OPTION, time() + (15 * MINUTE_IN_SECONDS), false);
            $this->log_event('warning', 'Prefetch circuit breaker engaged', []);
        }
        return $result;
    }

    private function atomic_fixed_window_increment(string $option, int $ttl, int $max) {
        for ($try = 0; $try < 8; $try++) {
            $old = $this->read_raw_option($option);
            $record = '' !== $old ? json_decode($old, true) : null;
            $now = time();
            $count = is_array($record) && (int) ($record['expires'] ?? 0) > $now
                ? (int) ($record['count'] ?? 0)
                : 0;
            $expires = is_array($record) && (int) ($record['expires'] ?? 0) > $now
                ? (int) $record['expires']
                : $now + $ttl;

            if ($count >= $max) {
                return new WP_Error(
                    'ncx_cp_rate_limited',
                    __('Too many checkout attempts. Please wait and try again.', 'ncx-cp-api'),
                    ['status' => 429, 'retry_after' => max(1, $expires - $now)]
                );
            }

            $new = (string) wp_json_encode(['count' => $count + 1, 'expires' => $expires]);
            if ('' === $old ? $this->insert_named_option($option, $new) : $this->compare_and_swap_option($option, $old, $new)) {
                return $count + 1;
            }
        }

        return new WP_Error(
            'ncx_cp_counter_busy',
            __('Checkout is busy. Please try again.', 'ncx-cp-api'),
            ['status' => 409]
        );
    }

    private function is_prefetch_suspended(): bool {
        $until = (int) get_option(self::PREFETCH_BREAKER_OPTION, 0);

        return $until > time();
    }

    private function hashed_remote_ip(): string {
        $ip = '';
        if (class_exists('WC_Geolocation')) {
            $ip = (string) WC_Geolocation::get_ip_address();
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    /**
     * @param string $kind card|applepay|googlepay — the widget brand this
     *                     session will be mounted for.
     */
    private function reserve_provider_checkout(WC_Order $order, string $kind) {
        $kind = $this->normalize_provider_checkout_kind($kind);
        $attempt = (string) $order->get_meta(self::ATTEMPT_META_KEY, true);
        if (!$this->is_valid_attempt_uuid($attempt)) {
            return new WP_Error('ncx_cp_attempt_missing', __('Checkout attempt is invalid.', 'ncx-cp-api'));
        }

        $lock = $this->acquire_attempt_lock($attempt);
        if (is_wp_error($lock)) {
            return $lock;
        }

        try {
            $fresh = wc_get_order($order->get_id());
            if (!$fresh instanceof WC_Order || !$this->order_matches_attempt($fresh, $attempt)) {
                return new WP_Error('ncx_cp_order_changed', __('Checkout changed. Please try again.', 'ncx-cp-api'), ['status' => 409]);
            }
            if ($this->order_payment_is_closed($fresh)) {
                return new WP_Error('ncx_cp_order_frozen', __('A payment is already in progress for this checkout.', 'ncx-cp-api'), ['status' => 409]);
            }

            $fingerprint = $this->build_order_fingerprint($fresh);
            $stored_fingerprint = (string) $fresh->get_meta(self::PROVIDER_FINGERPRINT_META_KEY, true);
            $checkout_id = $this->normalize_opp_checkout_id((string) $fresh->get_meta(self::CHECKOUT_META_KEY, true));
            $state = (string) $fresh->get_meta(self::RESERVATION_STATE_META_KEY, true);
            $stored_kind = $this->normalize_provider_checkout_kind(
                (string) $fresh->get_meta(self::PROVIDER_CHECKOUT_KIND_META_KEY, true)
            );
            $committed_generation = (int) $fresh->get_meta(self::GENERATION_META_KEY, true);
            $handed_off_generation = (int) $fresh->get_meta(self::HANDOFF_GENERATION_META_KEY, true);

            // Reuse is only safe before the session is handed to the provider,
            // and only while the provider still recognises it. Once executed or
            // expired the provider rejects the checkout ("checkoutId ... is
            // invalid"), so re-serving it to the browser breaks the widget
            // outright — and, because nothing else ages a committed session
            // out, does so on every retry until the cart changes.
            //
            // Kind must match as well. The card payload carries card.holder,
            // standingInstruction, saved-card registrations and 3DS parameters;
            // the APPLEPAY and GOOGLEPAY brands reject those with OPP
            // 200.300.404 "invalid account data for given payment brand". The
            // fingerprint is cart-derived, so without this test a wallet click
            // after the card form loaded is served the card session and can
            // never complete. An absent kind (pre-upgrade session, or one
            // created outside this coordinator) is not reusable.
            if ('committed' === $state
                && '' !== $checkout_id
                && '' !== $stored_kind
                && $stored_kind === $kind
                && hash_equals($stored_fingerprint, $fingerprint)
                && $handed_off_generation !== $committed_generation
                && $this->provider_checkout_within_ttl($fresh)
            ) {
                return [
                    'reused' => true,
                    'checkout_id' => $checkout_id,
                    'generation' => $committed_generation,
                    'fingerprint' => $fingerprint,
                    'kind' => $stored_kind,
                ];
            }

            $expires = (int) $fresh->get_meta(self::RESERVATION_EXPIRES_META_KEY, true);
            if ('pending' === $state && $expires > time() && hash_equals($stored_fingerprint, $fingerprint)) {
                return new WP_Error('ncx_cp_provider_busy', __('Payment setup is already in progress. Please try again.', 'ncx-cp-api'), ['status' => 409]);
            }

            // Past the reuse branch, satisfying this request means minting a new
            // generation. Refuse while a hand-off could still settle at the
            // provider (3DS, pending, or the short double-submit grace). A
            // replacement session here is a second charge window.
            if ($this->payment_handoff_within_grace($fresh) || $this->payment_execution_in_flight($fresh)) {
                return $this->payment_in_flight_error($fresh);
            }

            // Rate limit, not a lifetime quota. A lifetime cap bricks the order
            // after a handful of legitimate retries (each decline, reload, or
            // shipping change mints a generation) with no way back.
            $count = (int) $fresh->get_meta(self::PROVIDER_ATTEMPTS_META_KEY, true);
            $window_start = (int) $fresh->get_meta(self::PROVIDER_ATTEMPTS_WINDOW_META_KEY, true);
            if ($window_start <= 0 || (time() - $window_start) >= self::PROVIDER_ATTEMPTS_WINDOW_SECONDS) {
                $count = 0;
                $window_start = time();
            }
            $limit = $this->get_provider_session_attempt_limit();
            if ($count >= $limit) {
                $retry_after = max(
                    1,
                    ($window_start + self::PROVIDER_ATTEMPTS_WINDOW_SECONDS) - time()
                );
                $this->log_event('warning', 'Provider session rate limit reached', [
                    'order_id'    => (int) $fresh->get_id(),
                    'count'       => $count,
                    'limit'       => $limit,
                    'generation'  => $committed_generation,
                    'kind'        => $kind,
                    'retry_after' => $retry_after,
                ]);
                return new WP_Error(
                    'ncx_cp_provider_retry_cap',
                    sprintf(
                        /* translators: %d: seconds until another payment session may be prepared */
                        __('Too many payment attempts. Please wait %d seconds and try again.', 'ncx-cp-api'),
                        $retry_after
                    ),
                    ['status' => 429, 'retry_after' => $retry_after]
                );
            }

            $generation = max(0, $committed_generation) + 1;
            $token = $this->generate_attempt_uuid();
            $fresh->delete_meta_data(self::CHECKOUT_META_KEY);
            $fresh->delete_meta_data(self::PROVIDER_CHECKOUT_CREATED_META_KEY);
            $fresh->delete_meta_data(self::PROVIDER_CHECKOUT_KIND_META_KEY);
            if ('payment_started' === (string) $fresh->get_meta(self::LIFECYCLE_META_KEY, true)) {
                // The previous hand-off is abandoned; a late callback for it is
                // refused by the generation check. Return the order to intent so
                // the new session is not treated as already executing.
                $fresh->update_meta_data(self::LIFECYCLE_META_KEY, 'intent');
                $fresh->delete_meta_data(self::PAYMENT_STARTED_META_KEY);
            }
            $fresh->update_meta_data(self::GENERATION_META_KEY, (string) $generation);
            $fresh->update_meta_data(self::PROVIDER_FINGERPRINT_META_KEY, $fingerprint);
            $fresh->update_meta_data(self::RESERVATION_TOKEN_META_KEY, $token);
            $fresh->update_meta_data(self::RESERVATION_STATE_META_KEY, 'pending');
            $fresh->update_meta_data(self::RESERVATION_EXPIRES_META_KEY, (string) (time() + self::RESERVATION_TTL_SECONDS));
            $fresh->update_meta_data(self::PROVIDER_ATTEMPTS_META_KEY, (string) ($count + 1));
            $fresh->update_meta_data(self::PROVIDER_ATTEMPTS_WINDOW_META_KEY, (string) $window_start);
            $fresh->save();

            return [
                'reused' => false,
                'token' => $token,
                'generation' => $generation,
                'fingerprint' => $fingerprint,
                'kind' => $kind,
            ];
        } finally {
            $this->release_attempt_lock($lock);
        }
    }

    /**
     * Only the three brands this plugin creates sessions for are reusable.
     * Anything else — including an empty value — normalises to '' and is
     * therefore never matched by the reuse branch.
     */
    private function normalize_provider_checkout_kind(string $kind): string {
        return in_array($kind, ['card', 'applepay', 'googlepay'], true) ? $kind : '';
    }

    private function commit_provider_checkout(WC_Order $order, array $reservation, string $checkout_id) {
        $checkout_id = $this->normalize_opp_checkout_id($checkout_id);
        if ('' === $checkout_id || empty($reservation['token'])) {
            return new WP_Error('ncx_cp_provider_commit_invalid', __('Payment setup response was invalid.', 'ncx-cp-api'));
        }

        $attempt = (string) $order->get_meta(self::ATTEMPT_META_KEY, true);
        $lock = $this->acquire_attempt_lock($attempt);
        if (is_wp_error($lock)) {
            return $lock;
        }

        try {
            $fresh = wc_get_order($order->get_id());
            if (!$fresh instanceof WC_Order
                || (string) $fresh->get_meta(self::RESERVATION_TOKEN_META_KEY, true) !== (string) $reservation['token']
                || (int) $fresh->get_meta(self::GENERATION_META_KEY, true) !== (int) $reservation['generation']
                || !hash_equals((string) $fresh->get_meta(self::PROVIDER_FINGERPRINT_META_KEY, true), (string) $reservation['fingerprint'])
                || !hash_equals($this->build_order_fingerprint($fresh), (string) $reservation['fingerprint'])
            ) {
                $this->log_event('warning', 'Provider checkout response lost reservation race', ['order_id' => $order->get_id()]);
                return new WP_Error('ncx_cp_provider_stale', __('Checkout changed while payment was prepared. Please try again.', 'ncx-cp-api'), ['status' => 409]);
            }

            $fresh->update_meta_data(self::CHECKOUT_META_KEY, $checkout_id);
            $fresh->update_meta_data(self::RESERVATION_STATE_META_KEY, 'committed');
            // Recorded so the reuse branch can refuse to serve this session to
            // a different widget brand. A reservation without a kind leaves the
            // meta absent, which the reuse branch treats as not reusable.
            $kind = $this->normalize_provider_checkout_kind((string) ($reservation['kind'] ?? ''));
            if ('' !== $kind) {
                $fresh->update_meta_data(self::PROVIDER_CHECKOUT_KIND_META_KEY, $kind);
            } else {
                $fresh->delete_meta_data(self::PROVIDER_CHECKOUT_KIND_META_KEY);
            }
            // The reuse window is measured from here: the reservation expiry is
            // about creating the session, this is about how long the provider
            // will still honour it.
            $fresh->update_meta_data(self::PROVIDER_CHECKOUT_CREATED_META_KEY, (string) time());
            $fresh->delete_meta_data(self::RESERVATION_EXPIRES_META_KEY);
            $fresh->save();
            return (int) $fresh->get_meta(self::GENERATION_META_KEY, true);
        } finally {
            $this->release_attempt_lock($lock);
        }
    }

    private function abandon_provider_reservation(WC_Order $order, array $reservation): void {
        if (empty($reservation['token'])) {
            return;
        }
        $attempt = (string) $order->get_meta(self::ATTEMPT_META_KEY, true);
        $lock = $this->acquire_attempt_lock($attempt);
        if (is_wp_error($lock)) {
            return;
        }
        try {
            $fresh = wc_get_order($order->get_id());
            if ($fresh instanceof WC_Order
                && (string) $fresh->get_meta(self::RESERVATION_TOKEN_META_KEY, true) === (string) $reservation['token']
            ) {
                $fresh->update_meta_data(self::RESERVATION_STATE_META_KEY, 'failed');
                $fresh->delete_meta_data(self::RESERVATION_EXPIRES_META_KEY);
                $fresh->save();
            }
        } finally {
            $this->release_attempt_lock($lock);
        }
    }

    private function persist_provider_checkout(WC_Order $order, string $checkout_id): int {
        $reservation = [
            'token' => (string) $order->get_meta(self::RESERVATION_TOKEN_META_KEY, true),
            'generation' => (int) $order->get_meta(self::GENERATION_META_KEY, true),
            'fingerprint' => (string) $order->get_meta(self::PROVIDER_FINGERPRINT_META_KEY, true),
            'kind' => (string) $order->get_meta(self::PROVIDER_CHECKOUT_KIND_META_KEY, true),
        ];
        $result = $this->commit_provider_checkout($order, $reservation, $checkout_id);
        return is_wp_error($result) ? 0 : (int) $result;
    }

    /**
     * The committed session, for checking that the ID the browser posted at
     * Place Order is the one this order owns.
     *
     * Deliberately NOT subject to PROVIDER_CHECKOUT_TTL_SECONDS. That TTL
     * governs whether a session may be *served* to a browser that needs a form
     * rendered; this answers whether a session the widget has already mounted
     * is ours. Applying the TTL here would reject a shopper who spent longer
     * than the window filling the form, and would invalidate the session that
     * mounted widget is about to execute. Whether an older session still
     * settles is the provider's decision, not ours.
     */
    private function reusable_provider_checkout_id(WC_Order $order): string {
        $fingerprint = $this->build_order_fingerprint($order);
        $stored_fingerprint = (string) $order->get_meta(self::PROVIDER_FINGERPRINT_META_KEY, true);
        $checkout_id = (string) $order->get_meta(self::CHECKOUT_META_KEY, true);
        $generation = (int) $order->get_meta(self::GENERATION_META_KEY, true);
        $handed_off = (int) $order->get_meta(self::HANDOFF_GENERATION_META_KEY, true);
        if ('committed' !== (string) $order->get_meta(self::RESERVATION_STATE_META_KEY, true)
            || '' === $checkout_id
            || '' === $stored_fingerprint
            || !hash_equals($stored_fingerprint, $fingerprint)
            || $handed_off === $generation
        ) {
            return '';
        }

        return $this->normalize_opp_checkout_id($checkout_id);
    }

    /**
     * Whether the committed provider session is young enough to be served to a
     * browser that needs a payment form rendered.
     *
     * A session committed before this control existed carries no timestamp and
     * is treated as stale, so an order stuck on a dead checkout ID recovers on
     * the next request rather than looping until its cart changes.
     */
    private function provider_checkout_within_ttl(WC_Order $order): bool {
        $created = (int) $order->get_meta(self::PROVIDER_CHECKOUT_CREATED_META_KEY, true);
        if ($created <= 0) {
            return false;
        }

        return (time() - $created) < self::PROVIDER_CHECKOUT_TTL_SECONDS;
    }

    /**
     * Discards a committed session that the browser reported the provider
     * rejected, so the next request mints a replacement instead of re-serving
     * the same dead checkout ID.
     *
     * Fails closed in three ways: a value that does not match the committed
     * session is ignored, a session that was already handed to the provider is
     * left for resolve_abandoned_handoff() to account for, and nothing here
     * mints — it only clears, so the reservation path keeps ownership of every
     * freeze, grace and rate-limit decision.
     */
    private function discard_rejected_provider_checkout(WC_Order $order, string $reported_checkout_id): void {
        $reported = $this->normalize_opp_checkout_id($reported_checkout_id);
        if ('' === $reported) {
            return;
        }

        $attempt = (string) $order->get_meta(self::ATTEMPT_META_KEY, true);
        if (!$this->is_valid_attempt_uuid($attempt)) {
            return;
        }

        $lock = $this->acquire_attempt_lock($attempt);
        if (is_wp_error($lock)) {
            return;
        }

        try {
            $fresh = wc_get_order($order->get_id());
            if (!$fresh instanceof WC_Order || !$this->order_matches_attempt($fresh, $attempt)) {
                return;
            }
            if ($this->order_payment_is_closed($fresh)) {
                return;
            }

            $committed = $this->normalize_opp_checkout_id((string) $fresh->get_meta(self::CHECKOUT_META_KEY, true));
            if ('' === $committed || !hash_equals($committed, $reported)) {
                return;
            }

            $generation = (int) $fresh->get_meta(self::GENERATION_META_KEY, true);
            $handed_off = (int) $fresh->get_meta(self::HANDOFF_GENERATION_META_KEY, true);
            if ($generation <= 0 || $handed_off === $generation) {
                return;
            }

            $this->invalidate_provider_checkout($fresh, false);
            $fresh->save();
            $this->log_event('info', 'Discarded provider session the widget reported as invalid', [
                'order_id'   => $fresh->get_id(),
                'generation' => $generation,
            ]);
        } finally {
            $this->release_attempt_lock($lock);
        }
    }

    private function increment_provider_session_attempts(WC_Order $order) {
        return true;
    }

    private function payment_generation_is_current(WC_Order $order, array $payment): bool {
        $expected = (string) $order->get_meta(self::GENERATION_META_KEY, true);
        if ('' === $expected) {
            return '' === (string) $order->get_meta(self::ATTEMPT_META_KEY, true);
        }

        $custom = $payment['customParameters'] ?? [];
        $got = (string) ($custom['SHOPPER_generation'] ?? '');
        if ('' === $got) {
            return false;
        }

        return hash_equals($expected, $got);
    }

    private function rotate_attempt_after_paid(WC_Order $order): void {
        $this->clear_canonical_order_pointer((int) $order->get_id());
        $this->get_or_create_checkout_attempt_uuid(true);
    }

    private function mark_payment_started(WC_Order $order): void {
        // Reservation and commit write through their own reloads, so mark the
        // hand-off on fresh state rather than a copy read before those saves.
        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }

        $order->update_meta_data(self::LIFECYCLE_META_KEY, 'payment_started');
        $order->update_meta_data(self::PAYMENT_STARTED_META_KEY, (string) time());
        // A throttle left over from an earlier hand-off would otherwise suppress
        // the first provider read of this one.
        $order->delete_meta_data(self::HANDOFF_RECHECK_META_KEY);
        // Record which generation was executed so the spent provider session is
        // never handed back to the browser as a reusable one.
        $order->update_meta_data(
            self::HANDOFF_GENERATION_META_KEY,
            (string) (int) $order->get_meta(self::GENERATION_META_KEY, true)
        );
        $order->save();
    }

    private function invalidate_provider_checkout(WC_Order $order, bool $definitive): void {
        $order->delete_meta_data(self::CHECKOUT_META_KEY);
        $order->delete_meta_data(self::PROVIDER_CHECKOUT_CREATED_META_KEY);
        $order->delete_meta_data(self::PROVIDER_CHECKOUT_KIND_META_KEY);
        $order->delete_meta_data(self::RESERVATION_TOKEN_META_KEY);
        $order->delete_meta_data(self::RESERVATION_EXPIRES_META_KEY);
        $order->update_meta_data(self::RESERVATION_STATE_META_KEY, $definitive ? 'consumed' : 'invalidated');
    }

    private function mark_definitive_payment_failure(WC_Order $order, string $note = ''): void {
        $this->invalidate_provider_checkout($order, true);
        $order->delete_meta_data(self::AMBIGUOUS_META_KEY);
        $order->update_meta_data(self::LIFECYCLE_META_KEY, 'intent');
        // The provider gave a final answer, so the shopper is starting over
        // rather than hammering session creation: let them have a full window.
        $order->delete_meta_data(self::PROVIDER_ATTEMPTS_META_KEY);
        $order->delete_meta_data(self::PROVIDER_ATTEMPTS_WINDOW_META_KEY);
        $order->update_status('failed', $note);
        $order->save();
    }

    /**
     * Discards a cancelled wallet attempt without treating it as a declined order.
     *
     * WooCommerce emails administrators on pending/on-hold -> failed. Keeping
     * the unpaid status stable prevents repeated wallet-sheet closes from
     * producing a Failed-order email on every retry.
     */
    private function mark_cancelled_payment_attempt(WC_Order $order, string $note = ''): void {
        $this->invalidate_provider_checkout($order, true);
        $order->delete_meta_data(self::AMBIGUOUS_META_KEY);
        $order->update_meta_data(self::LIFECYCLE_META_KEY, 'intent');
        $order->delete_meta_data(self::PAYMENT_STARTED_META_KEY);
        $order->delete_meta_data(self::PROVIDER_ATTEMPTS_META_KEY);
        $order->delete_meta_data(self::PROVIDER_ATTEMPTS_WINDOW_META_KEY);
        if ('' !== $note) {
            $order->add_order_note($note);
        }
        $order->save();
    }

    /**
     * Resolves an abandoned provider hand-off before a replacement session is
     * minted, so we never assume an executed checkout went unpaid.
     *
     * Deliberately runs outside the attempt lock: it performs a provider
     * round-trip whose timeout exceeds the lock TTL, and the reservation
     * re-reads order state under the lock immediately afterwards.
     *
     * @return WP_Error|null WP_Error when no new session may be created.
     */
    private function resolve_abandoned_handoff(WC_Order $order): ?WP_Error {
        $fresh = wc_get_order($order->get_id());
        if (!$fresh instanceof WC_Order) {
            return null;
        }

        $generation = (int) $fresh->get_meta(self::GENERATION_META_KEY, true);
        $handed_off = (int) $fresh->get_meta(self::HANDOFF_GENERATION_META_KEY, true);
        if ($generation <= 0 || $handed_off !== $generation) {
            // The live session was never handed to the provider.
            return null;
        }
        if ('yes' === (string) $fresh->get_meta(self::AMBIGUOUS_META_KEY, true)) {
            // Already held for review; the reservation refuses it.
            return null;
        }
        // The grace used to refuse without asking anyone, which cost a returning
        // shopper a minute even when the provider already knew the challenge was
        // cancelled. Ask instead, but throttled, and treat "no payment" as
        // inconclusive for the duration — see apply_provider_payment_outcome().
        $within_grace = $this->payment_handoff_within_grace($fresh);
        if ($within_grace && !$this->claim_handoff_recheck($fresh)) {
            return $this->payment_in_flight_error($fresh);
        }

        $checkout_id = $this->normalize_opp_checkout_id((string) $fresh->get_meta(self::CHECKOUT_META_KEY, true));
        if ('' === $checkout_id) {
            return $this->payment_execution_in_flight($fresh)
                ? $this->payment_in_flight_error($fresh)
                : null;
        }

        return $this->apply_provider_payment_outcome($fresh, $checkout_id, $within_grace);
    }

    /**
     * Rate-limits provider reads of a session still inside the hand-off grace.
     *
     * The timestamp is written before the round-trip, not after, so a slow or
     * failed call still spaces out the next one.
     *
     * @return bool True when this caller may read the provider.
     */
    private function claim_handoff_recheck(WC_Order $order): bool {
        $next = (int) $order->get_meta(self::HANDOFF_RECHECK_META_KEY, true);
        if ($next > time()) {
            return false;
        }

        $order->update_meta_data(
            self::HANDOFF_RECHECK_META_KEY,
            (string) (time() + self::HANDOFF_RECHECK_SECONDS)
        );
        $order->save();

        return true;
    }

    /**
     * Reads the provider's verdict on a session already handed off, and applies
     * it to the order.
     *
     * This is the single place that decides whether a shopper has already paid.
     * The hand-off path and the frozen-order re-check both come through here on
     * purpose: two copies of this judgement would eventually disagree, and the
     * cost of disagreeing is charging someone twice.
     *
     * @param bool $within_grace Whether the hand-off is recent enough that the
     *                           browser could still be posting the payment, in
     *                           which case "no payment on this checkout" is a
     *                           race rather than an answer.
     * @return WP_Error|null WP_Error when no new session may be created.
     */
    private function apply_provider_payment_outcome(WC_Order $fresh, string $checkout_id, bool $within_grace = false): ?WP_Error {
        $payment = $this->fetch_payment_details('/v1/checkouts/' . $checkout_id . '/payment', $fresh);
        if (is_wp_error($payment)) {
            // A reload during 3DS often cannot read a payment yet. Do not freeze
            // the order for that; refuse a replacement session until the window
            // ends or a later fetch returns a definitive unpaid outcome.
            if ($this->payment_execution_in_flight($fresh)) {
                return $this->payment_in_flight_error($fresh);
            }

            $this->freeze_ambiguous_payment($fresh, sprintf(
                /* translators: %s: error code */
                __('Could not establish the outcome of the previous payment attempt (%s); retry frozen.', 'ncx-cp-api'),
                $payment->get_error_code()
            ));

            return $this->unconfirmed_previous_payment_error();
        }

        $code = (string) ($payment['result']['code'] ?? '');
        $state = $this->determine_state($payment);

        if ('success' === $state) {
            $verification = $this->verify_payment_against_order($fresh, $payment);
            if (is_wp_error($verification)) {
                $this->freeze_ambiguous_payment($fresh, sprintf(
                    /* translators: %s: verification failure reason */
                    __('Previous attempt reported success but failed verification (%s); retry frozen.', 'ncx-cp-api'),
                    $verification->get_error_code()
                ));

                return $this->unconfirmed_previous_payment_error();
            }

            $fresh->payment_complete((string) ($payment['id'] ?? ''));
            $fresh->add_order_note(sprintf(
                /* translators: %s: provider result code */
                __('COPYandPAY approved (%s); recovered while preparing a new payment session.', 'ncx-cp-api'),
                $code
            ));
            $this->rotate_attempt_after_paid($fresh);
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }

            return new WP_Error(
                'ncx_cp_order_already_paid',
                __('This order has already been paid. Please refresh the page.', 'ncx-cp-api'),
                ['status' => 409]
            );
        }

        if ('pending' === $state) {
            if ($this->payment_handoff_age($fresh, self::ABANDONED_CHALLENGE_SECONDS)) {
                // Recent enough that the shopper could still be on the
                // challenge, or that a completed one has yet to register.
                return $this->payment_in_flight_error($fresh);
            }

            // Still pending well past the point where a completed challenge
            // would have registered, so nothing was authenticated and the
            // session that could have authorised is unreachable. Treat it like
            // any other abandoned attempt: release the order, leave the status
            // alone so no failed-order email goes out, and let the shopper
            // start again.
            $this->mark_cancelled_payment_attempt($fresh, sprintf(
                /* translators: %s: provider result code */
                __('COPYandPAY reported no completed authentication (%s) after the abandon window; previous attempt released.', 'ncx-cp-api'),
                $code
            ));

            return null;
        }

        if ($this->is_payment_cancellation($payment)) {
            $this->mark_cancelled_payment_attempt($fresh, sprintf(
                /* translators: %s: provider result code */
                __('COPYandPAY cancelled (%s).', 'ncx-cp-api'),
                $code
            ));

            return null;
        }

        if ($this->is_definitive_payment_failure($payment)) {
            $this->mark_definitive_payment_failure($fresh, sprintf(
                /* translators: %s: provider result code */
                __('COPYandPAY declined (%s).', 'ncx-cp-api'),
                $code
            ));

            return null;
        }

        if (in_array($code, self::NO_PROVIDER_PAYMENT_CODES, true)) {
            if ($within_grace) {
                // Indistinguishable from a payment the browser is still posting:
                // the provider has not written a record yet. Releasing here is
                // the double-submit case the grace exists for, so wait it out.
                return $this->payment_in_flight_error($fresh);
            }

            $fresh->add_order_note(sprintf(
                /* translators: %s: provider result code */
                __('Previous checkout session carried no payment (%s); preparing a new one.', 'ncx-cp-api'),
                $code
            ));
            $this->invalidate_provider_checkout($fresh, false);
            // Definitively unpaid, so any earlier hold no longer applies. The
            // cancellation and decline helpers clear this too; without it a
            // frozen order could never be released down this branch.
            $fresh->delete_meta_data(self::AMBIGUOUS_META_KEY);
            $fresh->update_meta_data(self::LIFECYCLE_META_KEY, 'intent');
            $fresh->delete_meta_data(self::PAYMENT_STARTED_META_KEY);
            $fresh->save();

            return null;
        }

        $this->freeze_ambiguous_payment($fresh, sprintf(
            /* translators: %s: provider result code */
            __('Unclassified COPYandPAY result (%s) for the previous attempt; retry frozen.', 'ncx-cp-api'),
            $code
        ));

        return $this->unconfirmed_previous_payment_error();
    }

    /**
     * Re-reads a frozen order's outcome from the provider.
     *
     * A freeze records that the outcome was *unknown*, not that the payment
     * failed, so only a definitive provider answer may release it. Everything
     * short of that keeps the order frozen: releasing on a guess is exactly how
     * a shopper ends up paying twice. Deliberately not time-based.
     *
     * @return WP_Error|null Null once the order is released and may be reused.
     */
    private function recheck_frozen_payment(WC_Order $order): ?WP_Error {
        $checkout_id = $this->normalize_opp_checkout_id((string) $order->get_meta(self::CHECKOUT_META_KEY, true));
        if ('' === $checkout_id) {
            // Nothing to ask the provider about, so the hold stands.
            return $this->unconfirmed_previous_payment_error();
        }

        $next_check = (int) $order->get_meta(self::AMBIGUOUS_RECHECK_META_KEY, true);
        if ($next_check > time()) {
            return $this->unconfirmed_previous_payment_error();
        }

        // Written before the call, not after, so a slow or failed round-trip
        // still spaces out the next one.
        $order->update_meta_data(self::AMBIGUOUS_RECHECK_META_KEY, (string) (time() + self::AMBIGUOUS_RECHECK_SECONDS));
        $order->save();

        $outcome = $this->apply_provider_payment_outcome($order, $checkout_id);
        if (null !== $outcome) {
            return $outcome;
        }

        // Null means a definitive unpaid outcome, which clears the hold through
        // the mark_* helpers. Confirm that on reloaded state rather than trust
        // it: an order that is still flagged must not be handed a new session.
        $reloaded = wc_get_order($order->get_id());
        if (!$reloaded instanceof WC_Order
            || 'yes' === (string) $reloaded->get_meta(self::AMBIGUOUS_META_KEY, true)
        ) {
            return $this->unconfirmed_previous_payment_error();
        }

        return null;
    }

    private function unconfirmed_previous_payment_error(): WP_Error {
        return new WP_Error(
            'ncx_cp_order_frozen',
            __('We could not confirm your previous payment. Please contact us before trying again.', 'ncx-cp-api'),
            ['status' => 409]
        );
    }

    /**
     * Retryable refusal: the hand-off may still settle, so no replacement
     * session may be minted yet. `retry_after` lets the browser wait out the
     * grace instead of stranding the shopper on a terminal message; outside the
     * grace it is a poll interval, because only a later provider answer can
     * release the order.
     */
    private function payment_in_flight_error(?WC_Order $order = null): WP_Error {
        $retry_after = self::IN_FLIGHT_POLL_SECONDS;
        if ($order instanceof WC_Order) {
            $started = (int) $order->get_meta(self::PAYMENT_STARTED_META_KEY, true);
            if ($started > 0) {
                $remaining = ($started + self::HANDOFF_GRACE_SECONDS) - time();
                if ($remaining > 0) {
                    $retry_after = $remaining + 1;
                }
            }
        }

        return new WP_Error(
            'ncx_cp_payment_in_flight',
            __('Your payment is still being processed. Please wait — do not pay again.', 'ncx-cp-api'),
            ['status' => 409, 'retry_after' => max(1, (int) $retry_after)]
        );
    }

    private function freeze_ambiguous_payment(WC_Order $order, string $note = ''): void {
        $order->update_meta_data(self::AMBIGUOUS_META_KEY, 'yes');
        $order->update_meta_data(self::LIFECYCLE_META_KEY, 'payment_started');
        if ('' !== $note) {
            $order->add_order_note($note);
        }
        $order->save();
    }

    /**
     * @return array<string, mixed>|true
     */
    private function snapshot_cart_contents() {
        if (!function_exists('WC') || !WC()->cart) {
            return true;
        }

        $items = [];
        foreach (WC()->cart->get_cart() as $key => $item) {
            $items[$key] = [
                'product_id'   => (int) ($item['product_id'] ?? 0),
                'quantity'     => (float) ($item['quantity'] ?? 1),
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'variation'    => is_array($item['variation'] ?? null) ? $item['variation'] : [],
            ];
        }

        $coupons = WC()->cart->get_applied_coupons();
        $snapshot = [
            'items'   => $items,
            'coupons' => $coupons,
        ];

        if (WC()->session) {
            WC()->session->set(self::BUY_NOW_CART_SESSION_KEY, $snapshot);
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed>|true $snapshot
     */
    private function restore_cart_contents($snapshot): void {
        if (!is_array($snapshot) || !function_exists('WC') || !WC()->cart) {
            return;
        }

        WC()->cart->empty_cart();
        foreach ($snapshot['items'] ?? [] as $item) {
            WC()->cart->add_to_cart(
                (int) ($item['product_id'] ?? 0),
                max(1, (float) ($item['quantity'] ?? 1)),
                (int) ($item['variation_id'] ?? 0),
                is_array($item['variation'] ?? null) ? $item['variation'] : []
            );
        }

        foreach ((array) ($snapshot['coupons'] ?? []) as $coupon) {
            WC()->cart->apply_coupon($coupon);
        }

        WC()->cart->calculate_totals();
        if (WC()->session) {
            WC()->session->__unset(self::BUY_NOW_CART_SESSION_KEY);
        }
    }

    private function is_product_eligible_for_buy_now(?WC_Product $product): bool {
        if (!$product instanceof WC_Product) {
            return false;
        }

        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            return false;
        }

        if (!$product->is_type(['simple', 'variable', 'variation'])) {
            return false;
        }

        $type = $product->get_type();
        if (false !== strpos($type, 'subscription') || in_array($type, ['bundle', 'composite', 'booking'], true)) {
            return false;
        }

        if ($product->get_meta('_product_addons') || $product->meta_exists('_product_addons')) {
            $addons = $product->get_meta('_product_addons');
            if (!empty($addons)) {
                return false;
            }
        }

        return true;
    }

    private function send_coordinator_error(WP_Error $error): void {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
        $headers = [];
        if (is_array($data) && isset($data['retry_after']) && in_array($status, [409, 429], true)) {
            $headers['Retry-After'] = (string) (int) $data['retry_after'];
        }

        if (!empty($headers) && function_exists('status_header')) {
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        $payload = [
            'message' => $error->get_error_message(),
            'code'    => $error->get_error_code(),
        ];
        if (is_array($data) && isset($data['retry_after'])) {
            $payload['retryAfter'] = (int) $data['retry_after'];
        }
        // Notices drained from the session still have to reach the shopper,
        // even when this request itself fails.
        if (!empty($this->pending_shopper_notices)) {
            $payload['notices'] = $this->pending_shopper_notices;
        }

        wp_send_json_error($payload, $status > 0 ? $status : 400);
    }

    private function requested_wallet_mode(): string {
        $mode = isset($_POST['wallet_mode']) ? sanitize_text_field(wp_unslash($_POST['wallet_mode'])) : 'intent';

        return 'prefetch' === $mode ? 'prefetch' : 'intent';
    }

    public function client_console_logging_enabled(): bool {
        return $this->console_logging && current_user_can('manage_woocommerce');
    }

    /**
     * @return array<string, string>
     */
    public function get_client_runtime_flags(): array {
        return [
            'consoleLogging'     => $this->client_console_logging_enabled() ? '1' : '0',
            'applePayClientLog'  => $this->apple_pay_logging ? '1' : '0',
            'googlePayClientLog' => $this->google_pay_logging ? '1' : '0',
        ];
    }

    private function run_stale_order_cleanup(): void {
        $cutoff = time() - DAY_IN_SECONDS;
        $orders = wc_get_orders([
            'limit'          => 100,
            'status'         => ['checkout-draft', 'pending'],
            'payment_method' => $this->id,
            'date_modified'  => '<' . gmdate('Y-m-d H:i:s', $cutoff),
            'return'         => 'objects',
        ]);

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            if ($order->get_payment_method() !== $this->id) {
                continue;
            }
            if ($order->get_transaction_id()) {
                continue;
            }
            if ($order->is_paid() || in_array($order->get_status(), ['on-hold', 'failed', 'processing', 'completed'], true)) {
                continue;
            }
            if ('' === (string) $order->get_meta(self::ATTEMPT_META_KEY, true)) {
                continue;
            }
            if ((int) $order->get_meta(self::SESSION_EXPIRES_META_KEY, true) >= time()) {
                continue;
            }
            if ($order->get_meta(self::CHECKOUT_META_KEY, true)
                || $order->get_meta(self::GENERATION_META_KEY, true)
                || $order->get_meta(self::RESERVATION_TOKEN_META_KEY, true)
                || $order->get_meta(self::PAYMENT_STARTED_META_KEY, true)
                || 'yes' === (string) $order->get_meta(self::AMBIGUOUS_META_KEY, true)
            ) {
                continue;
            }

            $lifecycle = (string) $order->get_meta(self::LIFECYCLE_META_KEY, true);
            if ('prefetch' === $lifecycle && 'checkout-draft' === $order->get_status()) {
                $order->delete(false);
                $this->log_event('info', 'Trashed expired prefetch draft', ['order_id' => $order->get_id()]);
                continue;
            }

            if ('intent' === $lifecycle && 'pending' === $order->get_status()) {
                $order->update_status('cancelled', __('Abandoned unpaid checkout was cancelled.', 'ncx-cp-api'));
                $this->log_event('info', 'Cancelled abandoned intent order', ['order_id' => $order->get_id()]);
            }
        }

        $this->cleanup_expired_coordinator_options();
    }

    private function cleanup_expired_coordinator_options(): void {
        global $wpdb;
        $prefixes = [
            self::ATTEMPT_REGISTRY_PREFIX,
            'ncx_cp_counter_',
        ];

        foreach ($prefixes as $prefix) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100",
                $wpdb->esc_like($prefix) . '%'
            ), ARRAY_A);
            foreach ((array) $rows as $row) {
                $data = json_decode((string) ($row['option_value'] ?? ''), true);
                if (!is_array($data) || (int) ($data['expires'] ?? 0) >= time()) {
                    continue;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                    (string) $row['option_name'],
                    (string) $row['option_value']
                ));
            }
        }
    }

    private function run_invariant_monitor(): void {
        if (!$this->enable_dupe_check) {
            return;
        }

        $orders = wc_get_orders([
            'limit'          => 100,
            'status'         => ['pending', 'on-hold', 'checkout-draft'],
            'payment_method' => $this->id,
            'date_created'   => '>' . gmdate('Y-m-d H:i:s', time() - (15 * MINUTE_IN_SECONDS)),
            'return'         => 'objects',
        ]);

        $by_attempt = [];
        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            $attempt = (string) $order->get_meta(self::ATTEMPT_META_KEY, true);
            if (!$this->is_valid_attempt_uuid($attempt)) {
                continue;
            }
            $by_attempt[$attempt][] = $order;
        }

        $admin_email = get_option('woocommerce_email_from_address');
        if (!is_email((string) $admin_email)) {
            $admin_email = get_option('admin_email');
        }

        foreach ($by_attempt as $group) {
            if (count($group) < 2) {
                continue;
            }

            /** @var WC_Order $extra */
            foreach (array_slice($group, 1) as $extra) {
                if ('yes' === (string) $extra->get_meta(self::ALERT_META_KEY, true)) {
                    continue;
                }

                $ids = array_map(static function ($o) {
                    return (int) $o->get_id();
                }, $group);
                $totals = array_map(static function ($o) {
                    return (string) $o->get_total();
                }, $group);

                $this->log_event('warning', 'NCX unpaid-order invariant violated', [
                    'order_ids' => $ids,
                ]);

                wp_mail(
                    (string) $admin_email,
                    __('NCX unpaid order invariant alert', 'ncx-cp-api'),
                    sprintf(
                        "Multiple unpaid NCX orders share one checkout attempt.\nOrder IDs: %s\nTotals: %s\n",
                        implode(', ', $ids),
                        implode(', ', $totals)
                    )
                );

                $extra->update_meta_data(self::ALERT_META_KEY, 'yes');
                $extra->save();
            }
        }
    }
}
