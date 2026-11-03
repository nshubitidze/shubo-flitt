# Architecture

## Why it looks like this

Built on Magento's **Payment Gateway framework** (`Magento\Payment\Model\Method\Adapter`
+ di.xml virtualTypes), but deliberately small: the Braintree-style
`Http\Client` / `TransferFactory` / `BuilderComposite` / `HandlerChain`
ceremony is skipped: a handful of virtualTypes and concrete command classes,
one pipeline, approve/capture logic in one place.

The vendor SDK `flittpayments/php-sdk` (pinned `^1.0`) is used for **one
thing only**: the signature hash (`Flitt\Helper\ApiHelper::generateSignature`),
wrapped by `Gateway/Signature`. All HTTP is ours (`Model/FlittClient` over
Magento `Curl`) — the SDK's static/global-state classes are never used.

## Gateway wiring (`etc/di.xml`)

```
ShuboFlittFacade (Payment\Model\Method\Adapter, code shubo_flitt)
├─ valueHandlerPool  ShuboFlittValueHandlerPool
│    default         → ShuboFlittConfigValueHandler → Gateway\Config  (payment/shubo_flitt/*)
│    can_initialize  → Gateway\ValueHandler\CanInitializeHandler      (true only in redirect mode)
├─ validatorPool     ShuboFlittValidatorPool
│    currency        → Gateway\Validator\CurrencyValidator            (allowed_currencies)
└─ commandPool       ShuboFlittCommandPool
     authorize       → VerifyAuthorizationCommand   (embed placement, Authorize Only)
     capture         → CaptureStrategyCommand       (router, see below)
     refund          → RefundCommand                (online credit memo → /reverse)
     void, cancel    → VoidCommand                  (release an open hold → /reverse)
     initialize      → InitializeCommand            (redirect: order → pending_payment)
     vault_authorize → VaultAuthorizeCommand        (saved card, Authorize Only)
     vault_sale      → VaultSaleCommand             (saved card, Authorize & Capture)

ShuboFlittVaultFacade (Vault\Model\Method\Vault, code shubo_flitt_vault)
     vaultProvider = ShuboFlittFacade; commands via ShuboFlittCommandManager,
     registered in CommandManagerPool under the PROVIDER code "shubo_flitt".
```

`CaptureStrategyCommand` (pool `capture`) routes: open authorization
transaction → `CaptureCommand` (admin Invoice → Capture Online); order still
being placed → `VerifyChargeCommand` (embed placement, Authorize & Capture);
persisted order with no auth txn → **refused** (pending redirect orders settle
only via callback/return/reconciler).

Magento calls commands by **pool key**, not class name; `authorize_capture`
placement runs ONE command (capture) — authorization rows exist only in
Authorize Only mode.

## Class map

| Area | Class | Role |
|---|---|---|
| Config | `Gateway/Config` | The only code that knows `payment/shubo_flitt/*` field names; typed getters, safe defaults, `strict:` credential getters |
| Signature | `Gateway/Signature` | sign/verify (timing-safe), rejects non-scalar values; sole SDK touchpoint |
| API | `Model/FlittClient` | `getOrderStatus`, `createCheckoutUrl`, `createCheckoutToken`, `captureOrder`, `reverseOrder`, `createRecurringOrder`; one private `post()` with the full validation/logging chain |
| API | `Model/Endpoint` (enum) | Paths + `requiresSignedResponse()`: status/reverse/recurring signed; checkout url/token and capture not |
| API | `Exception/FlittApiException` | Every client failure; `getApiErrorCode()` set only for definitive Flitt failure envelopes |
| Payload | `Model/CheckoutPayloadBuilder` | Order/quote → create-order params (`order_id` = `{prefix}_{increment}_{unixtime}`, amount minor units, `preauth=Y`, URLs, `required_rectoken`) |
| Money | `Model/Money` | The one major ↔ minor unit conversion (`toMinorUnits()`; all accepted currencies have two decimals) |
| Mint | `Model/Checkout/MintPolicy` | Shared freshness window + order-id arithmetic (monotonic tail to avoid Flitt 1013 duplicates) |
| Mint | `Model/Checkout/TokenManager` | Embed token mint/reuse per quote; caches the session on the quote payment (`INFO_*` keys) |
| Mint | `Model/Checkout/RedirectUrlManager` | Redirect URL mint/reuse per order (saves via `OrderPaymentRepository`) |
| Verify | `Model/Checkout/PaymentVerifier` | Signed status must prove: approved, order_id binding, merchant, exact amount+currency, no reversal, minted preauth mode |
| Money | `Model/Checkout/HoldCapture` | Capture a verified hold, confirm via signed `additional_info.capture_status`; skips capture for one-stage wallet rails |
| Resume | `Model/Checkout/PaymentStateResolver` | Quote-level classification for JS: `none/unpaid/paid/blocked/unknown` |
| Callback | `Controller/Callback/Index` → `Model/Callback/Processor` | CSRF-exempt POST; ledger → store → secret → signature, then classify; `Result` 200/400/503 |
| Settle | `Model/Callback/OrderSettler` | THE single settle service (callback, return, reconciler) |
| URLs | `Model/Callback/CallbackUrl` | `server_callback_url`, `response_url`/`cancel_url` (redirect-free — Flitt doesn't follow redirects) |
| Return | `Controller/Redirect/Complete` → `Model/Checkout/ReturnHandler` | Browser return verdict (`ReturnDisposition`: Success/Processing/Failed/LatePaid/Unknown) |
| Return | `Plugin/ReturnSessionChecker` | Blocks session start on the cross-site POST return (keeps customers logged in) |
| Return | `Observer/RestoreCartOnReturn` | Back-button from hosted page: restores the cart for a pending redirect order |
| Redirect | `Controller/Redirect/Start`, `Gateway/Command/InitializeCommand` | Order-first handoff to the hosted page |
| Ledger | `Model/Ledger` | `recordMint`, `find`, `recordCallback`, `transition` (atomic, forward-only), `dueRows` |
| Ledger | `Observer/MarkLedgerSettled` | `checkout_submit_all_after` (global — placement runs in webapi_rest) → ledger `settled` |
| Lock | `Model/OrderLock` | `FLITT_ORD_` + sha1(increment id), 10s |
| Cron | `Cron/PendingOrderReconciler` | */15: stale mints and parked approved holds (see flows.md) |
| Cron | `Model/Checkout/PaidQuotePlacer` | Places the order for a paid embed session whose browser died |
| Vault | `Model/Vault/RecurringCharge`, `Model/Vault/TokenPersister`, `Model/Ui/TokenUiComponentProvider`, `Block/Customer/CardRenderer` | Charge by rectoken; persist tokens at settle; checkout tile; My Account list |
| UI | `Model/Ui/ConfigProvider` | `CODE`/`VAULT_CODE` constants + `window.checkoutConfig.payment.shubo_flitt` |
| Log | `Model/LogSanitizer` | mask (`rectoken`, `card_number`, `cvv2`, case-insensitive; more keys and the excerpt length via di.xml arguments `maskedKeys` / `bodyLimit`), `line()` anti log-forging, `excerpt()` |

## Frontend

- `view/frontend/web/js/view/payment/method-renderer/flitt.js` — KO renderer:
  mounts the Flitt checkout-vue widget (`pay.flitt.com/latest/checkout-vue`,
  unpinnable) on preselect AND select, string selector `#shubo-flitt-embed`,
  handlers bound once at mount, generation-counter fencing, 30s ready timeout,
  totals-change re-tokenize (800 ms debounce), one completion path for card +
  wallets, capture-phase validator gate for wallet taps, redirect mode →
  `afterPlaceOrder` handoff.
- `vault.js` — saved-card tile; `flitt.html` — template (the `css: {'_active': isActive()}` binding is load-bearing).
- `etc/csp_whitelist.xml` — pay.flitt.com (+ Apple/Google Pay hosts); frame-src is load-bearing (the widget's API transport is a hidden iframe).

## Routes (frontName `flitt`)

| Route | Method | Purpose |
|---|---|---|
| `flitt/checkout/token` | POST (form key) | Mint/reuse the embed token for the quote |
| `flitt/checkout/state` | POST (form key) | Classify the quote's payment session for the JS |
| `flitt/redirect/start` | GET | Redirect-mode handoff: mint URL, 302 to the hosted page |
| `flitt/redirect/complete` | GET + POST (CSRF-exempt) | Browser return (`response_url` and `cancel_url`) |
| `flitt/callback/index` | POST (CSRF-exempt) | Flitt server-to-server callback |
