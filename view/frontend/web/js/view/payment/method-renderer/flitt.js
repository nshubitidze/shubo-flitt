/**
 * Flitt payment method renderer — embed mount lifecycle, completion path,
 * totals watch and the redirect-mode handoff.
 *
 * Structural rules (verified against the shipped checkout-vue bundle):
 * - Mount on preselect AND select: the `active` observable is driven by the
 *   template's `css: {'_active': isActive()}` binding (Braintree pattern) —
 *   that binding is load-bearing; without it onActiveChange never fires and
 *   a preselected method never mounts.
 * - Always mount with the stable STRING selector: the SDK registry keys on
 *   it and auto-$destroys the previous instance; element-node mounts leak.
 * - The SDK has NO transport timeout and mount failures are console.error
 *   only: when its gateway iframe is blocked, neither `ready` nor `error`
 *   ever fires — the ready-timeout below is the only load-failure detection.
 * - Handlers are bound ONCE at mount, never in click handlers (there is no
 *   $off; accumulated handlers place the order twice). Stale async
 *   continuations are fenced by a generation counter.
 *
 * Extension point: a theme or module passes the widget's look (theme,
 * show_* flags, messages, css_variable…) through layout XML — the
 * `widgetOptions` config of the `shubo-flitt-payments` component in
 * checkout_index_index.xml — or by overriding getCustomWidgetOptions() in a
 * mixin. Keys that would change the payment flow are dropped, and the
 * module's pinned options always win (buildOptions()).
 */
define([
    'jquery',
    'ko',
    'mage/translate',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Ui/js/model/messageList',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Vault/js/view/payment/vault-enabler',
    'Magento_Checkout/js/model/full-screen-loader',
    'uiRegistry',
    'mage/cookies'
], function (
    $,
    ko,
    $t,
    quote,
    additionalValidators,
    globalMessageList,
    Component,
    VaultEnabler,
    fullScreenLoader,
    registry
) {
    'use strict';

    const CONTAINER_SELECTOR = '#shubo-flitt-embed';
    const SDK_CSS_URL = 'https://pay.flitt.com/latest/checkout-vue/checkout.css';
    const READY_TIMEOUT_MS = 30000;
    // Above the server's own Flitt API timeout, so a slow but working mint is not cut off.
    const AJAX_TIMEOUT_MS = 45000;
    const HTTP_CONFLICT = 409;
    const GENERIC_LOAD_ERROR = $t('The payment form could not be loaded. Please try again.');
    const SDK_MODULE = 'flittCheckout';

    /**
     * Widget option keys that decide WHAT is paid and HOW the payment is
     * triggered. Custom options (layout XML / getCustomWidgetOptions()) may
     * never set them: the module pins the ones it needs in buildOptions(),
     * and the rest must keep the SDK defaults this flow was verified with.
     * `params` (token, amount, order data) is dropped as a whole.
     */
    const FLOW_OPTION_KEYS = [
        'methods', 'methods_disabled', 'wallet_methods_enabled', 'active_tab', 'active_method',
        'full_screen', 'show_pay_button', 'autosubmit', 'disable_request', 'api_domain',
        'endpoint', 'subscription', 'amount_readonly'
    ];

    /**
     * RequireJS keeps a failed load in its registry: every later require()
     * of the same module fails at once, without a new request. Set by the
     * errback, cleared by undef() right before the next attempt — undefining
     * inside the errback itself leaves Magento's RequireJS resolver waiting
     * for the failed module, so the page loader never stops. Page-global,
     * like the RequireJS registry it mirrors.
     */
    let sdkLoadFailed = false;

    /**
     * Wallet buttons render INSIDE the widget and are their own payment
     * trigger — they never route through placeOrder(), so its validator
     * gate (agreements/billing BEFORE money moves) is enforced via a
     * capture-phase click listener instead. These selectors identify the
     * wallet button subtree; everything else (card inputs) passes through
     * untouched.
     */
    const WALLET_BUTTON_SELECTOR = [
        '.gpay-button', '[class*="gpay"]',
        'apple-pay-button', '[class*="apple-pay"]',
        '[class*="wallet"]'
    ].join(', ');

    return Component.extend({
        defaults: {
            template: 'Shubo_Flitt/payment/flitt',
            // Component that carries the layout-XML `widgetOptions` (this
            // module's checkout_index_index.xml): found by index, so a
            // layout that moves the payment step does not break it.
            widgetOptionsProvider: 'index = shubo-flitt-payments',
            active: false,
            imports: {
                onActiveChange: 'active'
            }
        },

        /**
         * @returns {Object} Chainable.
         */
        initObservable: function () {
            // refreshing: the totals changed and the form's token is being checked —
            // paying now could pay the old amount, so pay buttons wait for it.
            this._super().observe(['active', 'paid', 'refreshing']);

            // Redirect mode: placement succeeds into pending_payment and
            // afterPlaceOrder() navigates to the hosted-page handoff instead
            // of the default success-page redirect.
            this.redirectAfterPlaceOrder = this.isEmbedMode();

            // 'idle' | 'loading' | 'ready' | 'failed' | 'paid' | 'blocked' —
            // also exposed to CSS as data-state on the embed area.
            this.embedState = ko.observable('idle');
            this.embedError = ko.observable('');

            this._generation = 0;
            this._svc = null;
            this._readyTimer = null;

            // Capture-phase gate for wallet button clicks (bound once so
            // repeated addEventListener calls are no-ops and teardown can
            // remove it).
            this._walletGate = this.onEmbedClickCapture.bind(this);

            // Saved cards: the standard Magento_Vault consent
            // checkbox. Rendering is driven by window.checkoutConfig.vault
            // (logged-in customer + vault method active) — visitAdditionalData
            // is a no-op otherwise.
            this.vaultEnabler = new VaultEnabler();
            this.vaultEnabler.setPaymentCode(this.getFlittConfig().vaultCode);

            // One-shot completion latch: flips on the widget's success event,
            // after which placeOrder() routes to the REAL placement. Guards
            // against double-firing; the server is independently race-safe
            // (CartMutex + inactive quote). Observable because the template
            // hides the widget once paid: its own receipt would otherwise
            // show under the place-order loader and beside our paid message.
            this.paid(false);

            // Totals watch: a coupon / shipping change invalidates the
            // minted amount server-side, and the widget cannot take a new
            // amount after mount (setParams is a no-op once a token exists) —
            // the customer would pay an ORPHANED Flitt order the verifier
            // then rejects. Re-tokenize on real changes and re-mount only
            // when the server actually minted a different token.
            this._totalsKey = null;
            this._totalsTimer = null;
            this._mountedToken = null;
            quote.totals.subscribe(this.onTotalsChange.bind(this));

            return this;
        },

        /**
         * quote.totals emits on many server interactions that change nothing
         * payable — diff the payload before doing any work, then debounce the
         * bursts (estimate + save fire back-to-back).
         */
        onTotalsChange: function () {
            const key = this.totalsKey();

            if (key === this._totalsKey) {
                return;
            }

            this._totalsKey = key;

            // After payment the amount is settled — a late totals shift is
            // placement-verification's problem (exact-amount check), not a
            // reason to orphan the PAID order by re-minting (the paid-state
            // resume flow owns the UX).
            if (!this.isEmbedMode() || !this.active() || this.paid()) {
                return;
            }

            if (this.embedState() !== 'ready' && this.embedState() !== 'loading') {
                return;
            }

            clearTimeout(this._totalsTimer);
            this.refreshing(true);
            this._totalsTimer = setTimeout(this.refreshEmbedToken.bind(this), 800);
        },

        /**
         * @returns {String} The payable identity of the current totals.
         */
        totalsKey: function () {
            const totals = quote.totals() || {};

            return [
                totals['base_grand_total'],
                totals['grand_total'],
                totals['quote_currency_code']
            ].join('|');
        },

        /**
         * Ask the server for the quote's current token (idempotent: unchanged
         * inputs return the mounted token verbatim) and re-mount only on a
         * real change — protecting whatever the customer has typed from a
         * pointless form reset.
         */
        refreshEmbedToken: function () {
            const self = this;
            const generation = this._generation;

            if (this.paid()) {
                this.refreshing(false);

                return;
            }

            if (this.embedState() === 'loading') {
                // A mount is in flight against possibly-stale totals.
                this.retryEmbed();

                return;
            }

            this.fetchToken().done(function (token) {
                if (generation !== self._generation || self.paid()) {
                    return;
                }

                if (token !== self._mountedToken) {
                    self.retryEmbed();

                    return;
                }

                self.refreshing(false);
            }).fail(function (reason) {
                if (generation !== self._generation) {
                    return;
                }

                if (reason && reason.state) {
                    // the quote already has an approved session: no new form
                    self.teardownEmbed();
                    self.applyPaymentState(reason.state, self._generation);

                    return;
                }

                // Surface the failure through the normal failed/Retry state.
                self.retryEmbed();
            });
        },

        /**
         * In embed mode the button pays FIRST: validators gate the payment
         * (agreements/billing must pass BEFORE money moves), then the widget
         * takes over — its own UI owns the whole in-payment phase (progress,
         * 3DS challenge overlay, decline screens), and its internal loading
         * guard rejects concurrent submits. Actual order placement only runs
         * from the success handler via the paid-latch.
         *
         * Observed live: client-side field errors emit NO
         * event (widget shows them inline) — so this method must not lock
         * anything while waiting; the button simply stays clickable.
         *
         * @param {Object} [data]
         * @param {Event} [event]
         * @returns {Boolean}
         */
        placeOrder: function (data, event) {
            // A blocked session (paid but mismatched — needs manual review)
            // must place nothing and re-pay nothing.
            if (this.isEmbedMode() && this.embedState() === 'blocked') {
                return false;
            }

            if (!this.isEmbedMode() || this.paid()) {
                return this._super(data, event);
            }

            if (event) {
                event.preventDefault();
            }

            if (!this.validate() || !additionalValidators.validate() || !this.canStartPayment()) {
                return false;
            }

            if (this.embedState() !== 'ready' || !this._svc) {
                return false;
            }

            this._svc.submit();

            return false;
        },

        /**
         * Whether a payment may start now, besides the validators: the order
         * must be placeable right after it (Magento allows placing only with a
         * billing address — a paid order that cannot be placed would leave the
         * customer with no order and no message), and the form's token must
         * not be under a totals check.
         *
         * @returns {Boolean}
         */
        canStartPayment: function () {
            if (this.refreshing()) {
                return false;
            }

            if (!this.isPlaceOrderActionAllowed()) {
                this.messageContainer.addErrorMessage({
                    message: $t('Enter and save your billing address before paying.')
                });

                return false;
            }

            return true;
        },

        /**
         * Redirect mode only: the order is placed (pending_payment) — hand
         * the browser to the server-side hosted-page handoff. replace(), not
         * assign: Back must not land on the spent checkout step.
         *
         * The place-order request has already stopped the full-page loader
         * and the button is re-enabled right after this returns, so until
         * Flitt's page loads the checkout would show again, clickable. Start
         * the loader again, as core's redirect-on-success does.
         */
        afterPlaceOrder: function () {
            if (this.isEmbedMode()) {
                this._super();

                return;
            }

            fullScreenLoader.startLoader();
            window.location.replace(this.getFlittConfig().redirectUrl);
        },

        /**
         * Method config from window.checkoutConfig.payment.shubo_flitt
         * (Shubo\Flitt\Model\Ui\ConfigProvider).
         *
         * @returns {Object}
         */
        getFlittConfig: function () {
            return window.checkoutConfig.payment[this.getCode()] || {};
        },

        /**
         * Whether the embedded card form mode is configured (vs redirect).
         *
         * @returns {Boolean}
         */
        isEmbedMode: function () {
            return this.getFlittConfig().checkoutType === 'embed';
        },

        /**
         * Wallet rails enabled by the admin toggles, as the widget's
         * wallet_methods_enabled values.
         *
         * @returns {Array}
         */
        enabledWallets: function () {
            const config = this.getFlittConfig();
            const wallets = [];

            if (config.googlePay) {
                wallets.push('google');
            }

            if (config.applePay) {
                wallets.push('apple');
            }

            return wallets;
        },

        /**
         * Whether the save-card checkbox should render (vault method active
         * + logged-in customer — resolved by Magento_Vault's config).
         *
         * @returns {Boolean}
         */
        isVaultEnabled: function () {
            return this.vaultEnabler.isVaultEnabled();
        },

        /**
         * Ride the save-card consent along with placement — it lands in the
         * payment's additional_information via Magento_Vault's assigner and
         * decides whether the issued rectoken is persisted at settle.
         *
         * @returns {Object}
         */
        getData: function () {
            const data = this._super();

            // The default payload carries additional_data: NULL — the core
            // enabler's `'additional_data' in data` guard passes for null
            // and then writes into it. Normalize first.
            data['additional_data'] = data['additional_data'] || {};
            this.vaultEnabler.visitAdditionalData(data);

            return data;
        },

        /**
         * Evaluated by the template's `css: {'_active': isActive()}` binding —
         * KO re-runs it on every quote.paymentMethod change (isChecked is a
         * computed on it) and the side effect drives the `active` observable.
         *
         * @returns {Boolean}
         */
        isActive: function () {
            const active = this.getCode() === this.isChecked();

            this.active(active);

            return active;
        },

        /**
         * Fired by `imports` whenever `active` changes — including an initial
         * sync with false, so both branches must be idempotent.
         *
         * @param {Boolean} isActive
         */
        onActiveChange: function (isActive) {
            if (!isActive) {
                this.teardownEmbed(true);

                return;
            }

            this.initEmbed();
        },

        /**
         * Mount the embed unless one is already loading/mounted or the
         * session is settled (paid/blocked — nothing left to render).
         */
        initEmbed: function () {
            if (!this.isEmbedMode() || ['loading', 'ready', 'paid', 'blocked'].indexOf(this.embedState()) !== -1) {
                return;
            }

            this.mountEmbed();
        },

        /**
         * Load the SDK + mint a token concurrently, then mount.
         */
        mountEmbed: function () {
            const self = this;
            const generation = ++this._generation;

            this.embedState('loading');
            this.embedError('');
            this.refreshing(false);
            this._totalsKey = this.totalsKey();
            this.ensureSdkCss();
            // One timer for the whole mount — script, token and the widget's
            // ready event: a request that never answers (stalled connection;
            // RequireJS has no load timeout in Magento) must end in the
            // failed state with "Try again", not in an endless "Loading".
            this.startReadyTimer(generation);

            $.when(this.loadSdk(), this.fetchToken()).done(function (checkout, token) {
                if (generation !== self._generation) {
                    return;
                }

                self.mount(checkout, token, generation);
            }).fail(function (reason) {
                if (generation !== self._generation) {
                    return;
                }

                self.clearReadyTimer();

                if (reason && reason.state) {
                    // the quote already has an approved session: no new form
                    self.applyPaymentState(reason.state, generation);

                    return;
                }

                self.embedState('failed');
                self.embedError(typeof reason === 'string' && reason !== '' ? reason : GENERIC_LOAD_ERROR);
            });
        },

        /**
         * Mount the widget and bind its lifecycle events — exactly once.
         *
         * @param {Function} checkout - The SDK's global mount function.
         * @param {String} token
         * @param {Number} generation
         */
        mount: function (checkout, token, generation) {
            const self = this;

            const container = document.querySelector(CONTAINER_SELECTOR);

            if (!container) {
                this.embedState('failed');
                this.embedError(GENERIC_LOAD_ERROR);

                return;
            }

            // Same bound handler every time — re-adding is a spec'd no-op,
            // so remounts never stack gates.
            container.addEventListener('click', this._walletGate, true);

            this._svc = checkout(CONTAINER_SELECTOR, this.buildOptions(token));
            this._mountedToken = token;

            this._svc.$on('ready', function (model) {
                if (generation !== self._generation) {
                    return;
                }

                self.clearReadyTimer();

                // A mount against an ALREADY-APPROVED session (page refresh /
                // switch-back after a payment whose placement never finished)
                // renders the widget's own success view — no error fires.
                // Detect it from the ready payload and classify server-side;
                // the payload itself is advisory only.
                // A fresh form while a payment is already approved (the
                // customer switched method and back, or the form was
                // re-created): never offer it — ask the server what the quote's
                // payment state is.
                if (self.extractOrderStatus(model) === 'approved' || self.paid()) {
                    self.resolvePaidState(generation);

                    return;
                }

                self.embedState('ready');
            });

            // The payment outcome handlers are bound HERE, once per mount —
            // never in click handlers (no $off exists; per-click registration
            // places the order twice). The success payload is deliberately
            // ignored: placement is verified server-side against Flitt's
            // signed status API, never from JS data.
            this._svc.$on('success', function () {
                if (generation !== self._generation || self.paid()) {
                    return;
                }

                self.paid(true);
                clearTimeout(self._totalsTimer);
                self.refreshing(false);
                // Shown at once: if the placement cannot start (core refuses
                // without a message), the customer still sees that the payment
                // went through and that Place Order finishes the order.
                self.embedState('paid');
                self.placeOrder();
            });

            this._svc.$on('error', function (model) {
                if (generation !== self._generation) {
                    return;
                }

                if (self.embedState() === 'ready') {
                    // Post-ready error = a payment decline. The widget shows
                    // its own decline overlay and the same token stays
                    // retryable (Flitt delayed=Y keeps the order in
                    // 'processing') — nothing to reset on our side.
                    return;
                }

                self.clearReadyTimer();

                // 2009 "Order has already been paid" at mount = the resume
                // scenario (refresh / switch-back after payment whose
                // placement never finished) — classify server-side.
                if (self.extractErrorCode(model) === 2009) {
                    self.resolvePaidState(generation);

                    return;
                }

                // Any other pre-ready error = the form failed to initialize
                // (bad token, rejected app request).
                self.embedState('failed');
                self.embedError(self.extractErrorMessage(model));
            });

        },

        /**
         * Fail the mount after READY_TIMEOUT_MS unless the form became ready.
         * A late ready event still recovers to 'ready'.
         *
         * @param {Number} generation
         */
        startReadyTimer: function (generation) {
            const self = this;

            this.clearReadyTimer();
            this._readyTimer = setTimeout(function () {
                if (generation !== self._generation || self.embedState() !== 'loading') {
                    return;
                }

                self.embedState('failed');
                self.embedError($t('The payment form is taking too long to load. Please check your connection and try again.'));
            }, READY_TIMEOUT_MS);
        },

        /**
         * The widget configuration: custom options (look, texts) under the
         * module's pinned options, which always win.
         *
         * @param {String} token
         * @returns {Object}
         */
        buildOptions: function (token) {
            const custom = this.withNumericCssVariables(this.withoutFlowOptions(this.getCustomWidgetOptions()));

            return $.extend(true, custom, this.getPinnedOptions(token));
        },

        /**
         * Options from the theme or another module: the layout-XML
         * `widgetOptions` of the shubo-flitt-payments component. Override
         * this in a mixin for values that must be computed in the browser
         * (call _super() and extend its result). Returns a copy.
         *
         * @returns {Object} Flitt checkout-vue configuration: options, messages, css_variable, button…
         */
        getCustomWidgetOptions: function () {
            const provider = registry.get(this.widgetOptionsProvider);
            const custom = provider && provider.widgetOptions;

            return $.isPlainObject(custom) ? $.extend(true, {}, custom) : {};
        },

        /**
         * Drop the keys that would change the payment flow (FLOW_OPTION_KEYS,
         * `params`), with a console warning for the developer who set them.
         *
         * @param {Object} custom
         * @returns {Object}
         */
        withoutFlowOptions: function (custom) {
            const dropped = [];

            if (Object.prototype.hasOwnProperty.call(custom, 'params')) {
                delete custom.params;
                dropped.push('params');
            }

            if ($.isPlainObject(custom.options)) {
                FLOW_OPTION_KEYS.forEach(function (key) {
                    if (Object.prototype.hasOwnProperty.call(custom.options, key)) {
                        delete custom.options[key];
                        dropped.push('options.' + key);
                    }
                });
            }

            if (dropped.length) {
                // eslint-disable-next-line no-console
                console.warn('Shubo_Flitt: widget options ignored (they would change the payment flow): ' +
                    dropped.join(', '));
            }

            return custom;
        },

        /**
         * Layout XML delivers every number as a string, and the widget never
         * becomes ready (no error event either) when a css_variable size is
         * one ("6" instead of 6) — the 30 s ready timer then reports a failed
         * form. Numeric strings under css_variable become numbers; colours
         * and other values stay as they are.
         *
         * @param {Object} custom
         * @returns {Object}
         */
        withNumericCssVariables: function (custom) {
            if (!$.isPlainObject(custom['css_variable'])) {
                return custom;
            }

            Object.keys(custom['css_variable']).forEach(function (key) {
                const value = custom['css_variable'][key];

                if (typeof value === 'string' && /^-?\d+(\.\d+)?$/.test(value.trim())) {
                    custom['css_variable'][key] = Number(value);
                }
            });

            return custom;
        },

        /**
         * Pinned widget options. Every default that contradicts the flow is
         * overridden explicitly: the widget's own pay button is disabled
         * (Magento's Place Order stays the single, validator-gated trigger
         * for the CARD path). Wallet buttons are their own trigger
         * inside the widget — show_pay_button does not affect them, their
         * success fires the same 'success' event (single completion path),
         * and their clicks are validator-gated by onEmbedClickCapture.
         *
         * @param {String} token
         * @returns {Object}
         */
        getPinnedOptions: function (token) {
            const wallets = this.enabledWallets();

            // methods:['card'] alone still renders a menu of every rail the
            // merchant account has enabled — each non-card rail must be
            // disabled explicitly (seen live on the test merchant: Most
            // popular / Pay by bank / Installments). 'wallets' is the rail
            // GROUP toggle; wallet_methods_enabled picks rails within it
            // (its SDK default is ['apple','google'] — never omit it).
            const disabled = ['most_popular', 'banks', 'installments', 'loans'];

            if (!wallets.length) {
                disabled.push('wallets');
            }

            return {
                options: {
                    methods: ['card'],
                    'methods_disabled': disabled,
                    'wallet_methods_enabled': wallets,
                    'full_screen': false,
                    'show_pay_button': false
                },
                params: {
                    token: token
                }
            };
        },

        /**
         * Capture-phase validator gate for wallet buttons: agreements and
         * billing must pass BEFORE money moves, because a wallet tap pays at
         * once (no card form to submit). Runs before the widget's own
         * handlers; non-wallet targets (card inputs) pass through untouched.
         * Best-effort by design — if a wallet payment slips through anyway,
         * placement still validates and the paid-state resume machinery
         * owns the money (server-side truth).
         *
         * @param {Event} event
         */
        onEmbedClickCapture: function (event) {
            if (!this.enabledWallets().length || this.paid()) {
                return;
            }

            const target = event.target;

            if (!target || typeof target.closest !== 'function' || !target.closest(WALLET_BUTTON_SELECTOR)) {
                return;
            }

            if (this.validate() && additionalValidators.validate() && this.canStartPayment()) {
                return;
            }

            // Invalid checkout state: swallow the tap so the wallet sheet
            // never opens; the validators above (and canStartPayment()) already
            // surfaced their messages exactly as a Place Order click would.
            event.preventDefault();
            event.stopPropagation();
        },

        /**
         * Ask the server whether this quote's payment session is safe to
         * finish, safe to re-pay, or blocked — and shape the UI accordingly.
         * Used at mount (already-paid token) and after placement failures.
         *
         * @param {Number} generation
         */
        resolvePaidState: function (generation) {
            const self = this;

            this.fetchState().done(function (response) {
                if (generation !== self._generation) {
                    return;
                }

                self.applyPaymentState(response && response.state, generation);
            }).fail(function () {
                if (generation !== self._generation) {
                    return;
                }

                self.embedState('failed');
                self.embedError(GENERIC_LOAD_ERROR);
            });
        },

        /**
         * Shape the form after the server's payment-state answer.
         *
         * @param {String} state paid | blocked | unpaid | none | unknown
         * @param {Number} generation
         */
        applyPaymentState: function (state, generation) {
            if (generation !== this._generation) {
                return;
            }

            switch (state) {
                case 'paid':
                    this.paid(true);
                    this.embedState('paid');
                    break;

                case 'blocked':
                    this.paid(true);
                    this.embedState('blocked');
                    break;

                case 'unpaid':
                case 'none':
                    // Nothing actually paid — restart a fresh attempt.
                    this.paid(false);
                    this.retryEmbed();
                    break;

                default:
                    this.embedState('failed');
                    this.embedError(GENERIC_LOAD_ERROR);
            }
        },

        /**
         * Classify a placement failure that happened AFTER the money moved:
         * the standard error banner is already shown by the core flow; this
         * only decides whether the customer may finish, retry paying, or
         * must stop. Runs through the same server-truth endpoint placement
         * verification uses — never through JS guesses.
         *
         * @returns {void}
         */
        onPlacementFailed: function () {
            if (!this.isEmbedMode() || !this.paid()) {
                return;
            }

            this.resolvePaidState(this._generation);
        },

        /**
         * @returns {Object} Chainable deferred with the failure classifier attached.
         */
        getPlaceOrderDeferredObject: function () {
            return this._super().fail(this.onPlacementFailed.bind(this));
        },

        /**
         * POST /flitt/checkout/state for the current quote.
         *
         * @returns {Promise}
         */
        fetchState: function () {
            return $.ajax({
                url: this.getFlittConfig().stateUrl,
                type: 'POST',
                dataType: 'json',
                timeout: AJAX_TIMEOUT_MS,
                data: {
                    'form_key': $.mage.cookies.get('form_key')
                }
            });
        },

        /**
         * POST /flitt/checkout/token for the current quote. jQuery sends
         * X-Requested-With (passes CSRF); the form key rides along as the
         * documented alternative.
         *
         * @returns {Promise} Resolves the token string; rejects a message, or {state} when already paid.
         */
        fetchToken: function () {
            const deferred = $.Deferred();

            $.ajax({
                url: this.getFlittConfig().tokenUrl,
                type: 'POST',
                dataType: 'json',
                timeout: AJAX_TIMEOUT_MS,
                data: {
                    'form_key': $.mage.cookies.get('form_key')
                }
            }).done(function (response) {
                if (response && response.token) {
                    deferred.resolve(response.token);
                } else {
                    deferred.reject();
                }
            }).fail(function (xhr) {
                const body = xhr.responseJSON || {};

                // 409 = the quote already has an approved session: the state, not a new token
                deferred.reject(xhr.status === HTTP_CONFLICT && body.state ? {state: body.state} : body.error);
            });

            return deferred.promise();
        },

        /**
         * Lazy-load the SDK so it is only downloaded once the method mounts.
         *
         * @returns {Promise} Resolves the SDK mount function.
         */
        loadSdk: function () {
            const deferred = $.Deferred();

            // A failed earlier load (network, ad blocker, Flitt down) is
            // cached by RequireJS: forget it so this attempt really loads.
            if (sdkLoadFailed) {
                require.undef(SDK_MODULE);
                sdkLoadFailed = false;
            }

            require([SDK_MODULE], function (checkout) {
                deferred.resolve(checkout);
            }, function () {
                sdkLoadFailed = true;
                deferred.reject();
            });

            return deferred.promise();
        },

        /**
         * The SDK's stylesheet is fonts-only (card/CVV masking fonts) and is
         * NOT auto-injected by checkout.js — include it once per page.
         */
        ensureSdkCss: function () {
            if (document.querySelector('link[href="' + SDK_CSS_URL + '"]')) {
                return;
            }

            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = SDK_CSS_URL;
            document.head.appendChild(link);
        },

        /**
         * Destroy the widget and invalidate all in-flight async work.
         *
         * @param {Boolean} [leavingMethod] The customer switched to another payment method.
         */
        teardownEmbed: function (leavingMethod) {
            this._generation++;
            this.clearReadyTimer();
            clearTimeout(this._totalsTimer);
            this._mountedToken = null;

            if (this._svc) {
                this._svc.$destroy();
                this._svc = null;
            }

            // $destroy() detaches listeners but leaves the dead widget DOM in
            // the container — clear it so a later re-mount shows the loading
            // state instead of a stale, inert form.
            const container = document.querySelector(CONTAINER_SELECTOR);

            if (container) {
                container.removeEventListener('click', this._walletGate, true);
            }

            while (container && container.firstChild) {
                container.removeChild(container.firstChild);
            }

            this.embedState('idle');
            this.embedError('');
            this.refreshing(false);

            // paid deliberately SURVIVES teardown: a re-mount against the
            // paid token errors with 2009 and resolves back into the paid /
            // blocked state (the resume path). Warn when the customer walks
            // away from a completed payment — placing an order with another
            // method now would collect the money twice.
            if (this.paid() && leavingMethod) {
                globalMessageList.addErrorMessage({
                    message: $t('You have already paid by card. Return to the card payment method to finish your order, or contact us.')
                });
            }
        },

        clearReadyTimer: function () {
            if (this._readyTimer) {
                clearTimeout(this._readyTimer);
                this._readyTimer = null;
            }
        },

        /**
         * Full re-mount: a fresh token fetch (server reuses it while still
         * valid) and a same-selector mount, which auto-destroys any previous
         * instance inside the SDK.
         */
        retryEmbed: function () {
            this.teardownEmbed();
            this.mountEmbed();
        },

        /**
         * @param {Object} model
         * @returns {String} order_status from a Flitt Response model, '' when absent.
         */
        extractOrderStatus: function (model) {
            if (model && typeof model.attr === 'function') {
                return String(model.attr('order.order_data.order_status') || '');
            }

            return '';
        },

        /**
         * @param {Object} model
         * @returns {Number} Flitt error code, 0 when absent.
         */
        extractErrorCode: function (model) {
            if (model && typeof model.attr === 'function') {
                return Number(model.attr('error.code')) || 0;
            }

            return 0;
        },

        /**
         * The 'error' payload is a Flitt Response model exposing
         * attr('error.message') — or a raw Error for in-page exceptions.
         *
         * @param {Object} model
         * @returns {String}
         */
        extractErrorMessage: function (model) {
            let message = '';

            if (model && typeof model.attr === 'function') {
                message = model.attr('error.message') || '';
            }

            return message !== '' ? message : GENERIC_LOAD_ERROR;
        }
    });
});
