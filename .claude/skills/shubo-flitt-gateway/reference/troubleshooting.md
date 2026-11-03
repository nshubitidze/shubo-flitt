# Troubleshooting

Start every investigation with the module log (`var/log/shubo_flitt.log`),
the ledger row, and the live signed status for the Flitt order id — never
reason from what the browser showed.

## Symptoms

| Symptom | Likely cause | Check / fix |
|---|---|---|
| Embed form never appears / "payment form could not be loaded" | Storefront on `localhost`/IP (Flitt 403s the gateway iframe); CSP blocked `pay.flitt.com` frame-src; token mint failed | Use a real hostname; browser console for CSP; log for `/checkout/token` errors |
| Method missing at checkout | Order currency not in `allowed_currencies`; method inactive; `ConfigProvider` returns `[]` when unavailable | Currency + `active`; flush cache |
| Checkout JS looks stale after a change | Static content version cache | `rm pub/static/deployed_version.txt` + cache flush; `dev:source-theme:deploy` if `pub/static/frontend` is gone |
| Placement refused after a successful payment | `PaymentVerifier` rejected (amount/currency drift after totals change, superseded mint, reversal) | Log `verify:` line; `/flitt/checkout/state` classifies; totals changes must re-tokenize |
| Order stuck `pending_payment` (redirect) | Callback can't reach the site (local env / firewall), return leg failed | Ledger + live status: paid → synthetic callback locally, or wait for the reconciler (lifetime + grace); check `server_callback_url` reachability in prod |
| Paid at Flitt, no Magento order (embed) | Browser died after payment | Ledger `approved`; reconciler places via `PaidQuotePlacer` after grace, else reverses |
| Customer logged out after returning from hosted page | Session started on the cross-site POST | `ReturnSessionChecker` plugin must be active (di.xml); POST must 302 to GET |
| Cart empty after Back from hosted page | Observer not firing | `RestoreCartOnReturn` on `checkout_cart_index`/`checkout_index_index` predispatch |
| Browser never returns from hosted page; `ERR_CONNECTION_TIMED_OUT` on `secure-redirect.cloudipsp.com` | Flitt's return relay unreachable (seen in testing on 2026-09-28) | Flitt-side; money safe (hold approved, settles by callback/reconciler); report to Flitt. Working again on 2026-11-03 |
| Invoice → Capture Online refused "single full capture" | Partial invoice | Invoice the full amount (Flitt allows one capture per hold) |
| Capture Online refused "already captured at Flitt" | Duplicate capture / out-of-band capture | Reconcile the invoice manually; don't capture again |
| Online Refund button missing | Invoice has no `transaction_id` | Only invoices captured through the module get it |
| Void button missing | No OPEN auth txn (Authorize & Capture orders are captured) | Use a credit memo |
| Callback 400 `invalid signature` | Wrong secret for the store, tampered body | Store-scoped secret in config; ledger store_id |
| Callback 503 | Lock busy, missing secret, settle transient | Flitt retries automatically; check the error line |
| New di.xml argument has no effect | Stale `generated/metadata` | `rm -rf generated/code/* generated/metadata/*`, recompile |
| Saved card invisible in My Account | No renderer claims the token | `CardRenderer` + `vault_cards_listaction.xml` |
| Google Pay / Apple Pay sheet opens but the payment is declined or fails | Domain not activated for the wallet (Flitt support: Apple domain-association file, Google domain registration); on the test merchant, Google's TEST card was declined with 1015 | Callback/log `response_code`; `additional_info.payment_method`; see `configuration.md` (wallet toggles) |

## Flitt error codes seen in practice

| Code | Meaning here |
|---|---|
| 1013 | Duplicate order id (same-second re-mint) — `MintPolicy` bumps the id tail monotonically |
| 1018 | Order not found (status for an id Flitt never saw — e.g. after switching the merchant account, or suite fixtures). The reconciler marks such a stale mint `expired` once its lifetime + grace has passed |
| 1027 | Preauth not enabled on the merchant account |
| 1035 / 1078 / 1082 | Rectoken invalid/expired — vault entry purged |
| 1059 / 1060 | Recurring/rectoken not enabled on the merchant |
| 1126 | Reused `reverse_id` after a successful reverse (Flitt rejects, not idempotent) |

Full list: Flitt docs, https://docs.flitt.com/api/response-codes/.

## Test-mode quirks (Flitt test merchant 1549901)

- The 3DS **emulator** ("Bank Server Emulation → Continue", frame
  `pay.flitt.com/test/…`) can appear for any card, including ones documented
  as non-3DS.
- Hosted page shows extra rails (pay-by-bank, installments, wallets) — the
  production merchant must be configured card-only.
- Docs' example signature hashes are stale — the algorithm is authoritative.
- Google Pay's TEST sheet pays with Google's test cards; its Amex `…0005`
  was declined (1015). Apple Pay: a real card in Apple Wallet is turned into
  a test token by Flitt (docs).
- Failure envelopes arrive with HTTP 200 (`response_status: failure`).

## Failures this design prevents (regression list)

Classic failures of Flitt checkout integrations; keep them impossible.

1. Renderer dead after a failed payment → failures are first-class states with retry.
2. Redirect return logged customers out → session-less return (see above).
3. Back button emptied the cart while the order stayed pending → cart restore + reconciler.
4. Embed didn't mount when preselected → mount on preselect AND select.
5. "Order could not be placed" after success-on-retry → handlers bound once at mount.
6. Wallet success left the user in checkout → single completion path.
7. Dead admin "Capture" button → native Invoice → Capture Online only.
8. Saved card in redirect mode: "A server error stopped your order from being placed" (core `Vault::initialize()` throws "Not implemented" because the vault method inherits the gateway's `can_initialize`) → `CanInitializeHandler` answers false for `shubo_flitt_vault` payments.
