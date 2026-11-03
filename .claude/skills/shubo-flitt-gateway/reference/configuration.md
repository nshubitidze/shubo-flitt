# Configuration

Admin: **Stores → Configuration → Sales → Payment Methods → TBC Bank (Flitt)**.
Config path `payment/shubo_flitt/*` (vault method: `payment/shubo_flitt_vault/*`).
All reads go through `Gateway/Config` — add new fields there, never read
config paths elsewhere.

## Admin fields

| Field (path suffix) | Default | Scope | Meaning |
|---|---|---|---|
| `active` | 0 | website | Enables the method |
| `title` | `TBC Bank` | store | Customer-facing name |
| `payment_action` | `authorize_capture` | website | **Settle timing, NOT preauth**: `authorize_capture` = capture the hold at placement → invoice paid; `authorize` = leave the hold open, merchant does Invoice → Capture Online. Checkout always holds (`preauth=Y`) either way — **pre-authorization must be enabled on the Flitt merchant** (else Flitt error 1027) |
| `merchant_id` | — | website | Flitt merchant id (digits) |
| `secret_key` | — | website | Payment secret; `obscure` + encrypted + registered sensitive (never in config dumps) |
| `order_id_prefix` | empty | website | Tag in the Flitt order id `{prefix}_{increment}_{unixtime}`; set a distinct one per shop when several Magento instances share one Flitt merchant. Alphanumeric |
| `checkout_type` | `embed` | website | `embed` = card form inside checkout (pay-first); `redirect` = Flitt hosted page (order-first) |
| `wallet_google_pay` / `wallet_apple_pay` | 0 | website | Wallet buttons in the EMBED form. One-stage charge (no reversible hold). Apple Pay also needs domain verification via Flitt support; both need HTTPS. Hosted-page rails are managed in the Flitt portal |
| `vault_active` → `payment/shubo_flitt_vault/active` | 0 | website | Saved cards: logged-in customers can save and reuse cards (Flitt rectoken; needs recurring enabled on the merchant) |
| `vault_title` → `payment/shubo_flitt_vault/title` | `Saved Cards (TBC Bank)` | store | Saved-card method title |
| `allowed_currencies` | `GEL` | website | Multiselect GEL/USD/EUR (Flitt Georgia). Method hidden for other order currencies (`CurrencyValidator`) |
| `lifetime` | 36000 | website | Seconds an unpaid Flitt order stays payable (Flitt default 10h). Also drives when the reconciler treats a mint as stale |
| `reconcile_grace` | 3600 | website | Seconds the reconciler waits past lifetime (stale mints) or since last change (parked holds) before acting — protects paid-but-not-placed sessions a customer may still resume |
| `debug` | 0 | website | Full masked request/response dumps in `var/log/shubo_flitt.log` (summaries + errors are always logged) |
| `sort_order` | 10 | website | Position among payment methods |

Zero/empty `lifetime`, `reconcile_grace`, `api_timeout` fall back to the
defaults above (never sent/used as 0).

## config.xml-only (ops tunables, no admin field)

| Path | Default | Note |
|---|---|---|
| `api_url` | `https://pay.flitt.com/api` | There is NO separate sandbox host — test vs live is decided by the merchant credentials |
| `api_timeout` | 30 | Seconds per API call (0 would mean "no timeout" in curl — guarded) |

## Capability flags (don't flip casually)

`can_authorize=1`, `can_capture=1` gate the placement-verification commands —
**with `can_authorize=0` the Adapter silently skips verification and places
unverified orders.** `can_capture_partial=0` (Flitt allows one capture per
hold). `can_refund=1`, `can_refund_partial_per_invoice=1`, `can_void=1`,
`can_cancel=1` power the native admin buttons. `can_use_internal=0` (no admin
orders / MOTO). `can_initialize` is **computed** (redirect mode only) by
`CanInitializeHandler`, not configured. Vault: `can_authorize_vault=1`,
`can_capture_vault=1` on the provider node.

## Setting values from the CLI

```bash
bin/magento config:set payment/shubo_flitt/active 1
bin/magento config:set payment/shubo_flitt/merchant_id 1549901     # Flitt public TEST merchant
bin/magento config:set payment/shubo_flitt/secret_key test         # encrypted by the backend model
bin/magento config:set payment/shubo_flitt/checkout_type embed
bin/magento cache:flush                                            # checkout config is cached
```

Store prerequisites: an allowed currency (GEL for the test merchant) as the
order currency; for the embed, the storefront on a real hostname (Flitt
refuses localhost/IP origins).

## Merchant-account prerequisites (Flitt side)

- Pre-authorization enabled (both payment actions hold first) — error 1027 otherwise.
- For saved cards: recurring/rectoken enabled (errors 1059/1060 are per-account flags).
- Hosted page: configure it **card-only** in the portal for production — other
  rails (pay-by-bank, installments) cannot preauth and would one-stage charge.
- Apple Pay: domain verification file via Flitt support.
