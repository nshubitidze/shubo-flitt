# Flows

Amounts to Flitt are **minor units** (15.00 GEL → 1500). Flitt order ids are
`{prefix}_{increment}_{unixtime}` — one Magento order can have several (re-mints).

## 1. Embed checkout — PAY-FIRST (`checkout_type=embed`)

1. Payment step renders → renderer POSTs `flitt/checkout/token`.
   `TokenManager` mints (or reuses) a token for the **quote** (reserved
   increment id, `preauth=Y`, `server_callback_url`), records a ledger row
   `minted`, caches the session on the quote payment (`flitt_order_id`,
   `flitt_token_*`). Reuse only while amount/currency/preauth/callback URL/
   rectoken flag all match and the mint is fresh.
   No new session is minted while another session of the quote is approved
   (`PaidSessionFinder`, live status): the endpoint answers **409** with the
   payment state instead, and the form shows "paid" or "contact us".
2. Widget mounts in `#shubo-flitt-embed`; one 30 s timer covers the script,
   the token and the widget's ready event. Totals change → pay buttons wait
   (`refreshing`) → re-tokenize (debounced), re-mount only if the token changed.
3. Customer clicks **Place Order** → Magento validators + the billing address
   must be set (`canStartPayment()`; the same gate for wallet taps) → widget `submit()`
   → Flitt payment (3DS overlay possible). A decline keeps the same token
   retryable (`delayed=Y`).
4. Widget `success` → JS calls standard `placeOrder` (JS triggers only);
   the widget is hidden from here on (`paid`).
5. Server: placement runs the pool command —
   - `authorize_capture`: `CaptureStrategyCommand` → `VerifyChargeCommand` →
     `PaymentVerifier` (signed status: approved, binding, merchant, exact
     amount/currency, no reversal, `preauth=Y`) → `HoldCapture` captures and
     confirms via signed `additional_info.capture_status` → invoice paid.
   - `authorize`: `VerifyAuthorizationCommand` → verify → OPEN auth txn.
6. `MarkLedgerSettled` (`checkout_submit_all_after`) → ledger `settled`.

Placement failure after payment (e.g. stock) or a page refresh →
`flitt/checkout/state` (`PaymentStateResolver`: none/unpaid/paid/blocked/
unknown) lets the JS resume placement instead of charging again. Browser dies
after paying → ledger stays `approved` → reconciler places the order
(`PaidQuotePlacer`) or reverses the hold (§5).

## 2. Redirect checkout — ORDER-FIRST (`checkout_type=redirect`)

1. `can_initialize` is true → `InitializeCommand`: order `pending_payment`,
   confirmation email suppressed, `flitt_checkout_mode=redirect` stamped.
2. JS `afterPlaceOrder` (full-page loader on again) → `flitt/redirect/start` → `RedirectUrlManager`
   mints/reuses a hosted-page URL for the ORDER (ledger `minted`, URL cached on
   the order payment) under `OrderLock` → 302 to `pay.flitt.com`. Minting over
   an approved/settled session is refused (`PaymentInFlightException`).
   Abort before anything was ever minted → order cancelled + quote restored.
3. Customer pays on the hosted page; Flitt returns the browser to
   `response_url` (success) or `cancel_url` — both `flitt/redirect/complete`
   (§4) — and POSTs the signed callback (§3). Whichever lands first settles;
   both are idempotent under the lock.

## 3. Callback — `POST flitt/callback/index`

Trust chain, strictly before any mutation: parse (JSON; legacy form-encoded
fallback) → `order_id` → ledger row (unknown → **200 ignored**, no existence
oracle) → store → secret (missing → 503) → **signature** (bad → 400) →
merchant id (mismatch → 400) → `OrderLock` (busy → 503) → record facts
(`payment_id`, status, card mask/type/bin, rectoken) → classify:

| Flitt status / situation | Result |
|---|---|
| verification order | 200, acknowledged, never settles |
| approved, no Magento order yet | ledger `approved` (embed resume state), 200 |
| approved, order `pending_payment`, same session | `OrderSettler` settles (capture or open auth) → 200 `settled`; failure → 503 |
| approved, superseded session | parked `approved` for reversal, 200 |
| approved, order already settled | 200 `already settled` (idempotent replay) |
| approved, order canceled | parked for reversal (reconciler reverses), 200 |
| declined / expired / processing / created | ledger state recorded, order untouched, 200 |
| unexpected exception | 503 (Flitt retries 2/60/300/600/3600/86400 s) |

`OrderSettler` is the single settle service: re-verifies via `PaymentVerifier`,
captures via `HoldCapture` when `authorize_capture`, registers the
notification with **base-currency** amounts, sends the order email, persists
the saved card (`TokenPersister`), ledger `settled`.

## 4. Browser return — `flitt/redirect/complete` (GET + POST)

- Flitt returns via a **cross-site POST**, which carries no SameSite=Lax session
  cookie. `ReturnSessionChecker` prevents a session from starting on that POST
  (a new guest cookie would log the customer out); the controller answers with a
  session-less 302 to the same route as GET carrying the signed payload, and the
  GET runs inside the customer's real session.
- `ReturnHandler` identifies the payment: ① signed payload (ledger → secret →
  signature, the callback's trust chain) ② the session's own last order ③ else
  Unknown. The verdict comes from fresh order state under lock + signed status:

| Disposition | When | Landing |
|---|---|---|
| Success | paid (settled now if needed) | `checkout/onepage/success` (session keys seeded) |
| Failed | not paid — created/processing/declined (session still payable: order stays `pending_payment`); expired/reversed/never minted (order cancelled) | cart restored + error |
| LatePaid | paid after the order was already cancelled | cart + "payment will be refunded"; reconciler reverses |
| Processing | money in flight / settle transient | cart + neutral notice |
| Unknown | nothing trustworthy | cart + generic notice, nothing disclosed |

Back button from the hosted page skips the controller entirely;
`RestoreCartOnReturn` (predispatch cart/checkout) restores the quote when the
last order is a pending redirect-mode Flitt order.

## 5. Reconciler — `shubo_flitt_reconcile` cron, every 15 min

Per ledger row, under `OrderLock`, decided on the signed status, batch cap 50:

- **Stale mints** (`minted`/`declined` past lifetime + grace): paid after all →
  settle the pending order (missed callback) or park `approved`; dead →
  ledger `expired`/`declined` + cancel the stranded pending order. Flitt
  answers "Order not found" (1018 — it never created the session, e.g.
  after the merchant account was switched) → `expired` + cancel too, so the
  row is not polled forever.
- **Parked holds** (`approved` idle past grace): matching pending order →
  settle; placed order but observer missed → mark settled; paid embed session
  with no order → `PaidQuotePlacer` places it (same quote, same reserved
  increment, exact money match; a guest quote first gets the customer
  fields core's `placeOrder()` would set — e-mail from the billing address —
  and the confirmation e-mail is sent, because core sends it only in the
  storefront/web API areas); otherwise → **reverse in full**
  (`reverse_id rec-{increment}`), ledger `reversed`.

Core's `sales_clean_orders` cron cancels `pending_payment` orders after
`sales/orders/delete_pending_after` minutes since their last update (core
default 480 — shorter than the 10 h session lifetime). The module's
`etc/config.xml` sets that default to **660** (lifetime + grace), so core
does not cancel a redirect order whose page is still payable. A value saved
in the admin wins; if it is shorter, the LatePaid path still covers the race
(late payment → reversed in full).

## 6. Admin money ops (native buttons)

| Button | Pool | Behaviour |
|---|---|---|
| Invoice → Capture Online (Authorize Only orders) | `capture` → `CaptureCommand` | Full amount only (partial refused — Flitt allows ONE capture per hold); re-verify, fence against already-captured, capture + signed confirmation; txn `{payment_id}-capture` |
| Credit Memo → Refund (online) | `refund` → `RefundCommand` | `/reverse` in ORDER-currency minor units; multiple partial refunds; `reverse_id = cm{amount}-r{pre_reversed}`; guard: Flitt `reversal_amount` must equal the module's own reversed counter; ledger `reversed` when fully reversed |
| Void / Cancel (open hold) | `void`/`cancel` → `VoidCommand` | Full reverse of the uncaptured hold (fee-free); `reverse_id void-{increment}`; no-op if the hold is already dead; refuses if captured ("use a credit memo") |

Authorize & Capture orders are invoiced automatically at settle — no admin
action. A Google Pay payment is held and captured like a card; only a wallet
payment that reports no `capture_status` counts as charged in one stage, and
then "void" is a refund.

## 7. Saved cards (Magento_Vault) and wallets

- **Issue:** logged-in customer + vault active → every mint sends
  `required_rectoken=Y`; the consent checkbox decides **persistence** at
  settle (`TokenPersister` at both settle sites; token from the signed
  envelope or the ledger's callback facts).
- **Pay:** the `shubo_flitt_vault` method → `vault_authorize`/`vault_sale` →
  `RecurringCharge`: `POST /recurring` with the rectoken and `preauth=Y`
  (synchronous SIGNED envelope is authoritative), then capture for
  `vault_sale`. Core Vault resets the payment method to `shubo_flitt`, so
  callbacks, refunds, voids and the reconciler all apply unchanged. Dead-token
  errors 1035/1078/1082 soft-delete the vault entry. No 3DS on `/recurring`.
  The same in both checkout types: a saved-card payment never takes redirect
  mode's order-first `initialize` path (`CanInitializeHandler`).
- **My Account → Stored Payment Methods:** `CardRenderer` claims
  `shubo_flitt` tokens (core silently hides unclaimed ones).
- **Wallets:** toggles add Google/Apple Pay to the embed. Wallet taps bypass
  `placeOrder`, so a capture-phase click gate runs Magento validators first;
  success shares the card completion path. Enter in a card field (the widget
  would submit on its own) is routed through `placeOrder` by a capture-phase
  key gate. `HoldCapture` captures a wallet
  payment whose signed `capture_status` is `hold`, and skips the capture only
  when a wallet payment reports no `capture_status` (one-stage charge).
