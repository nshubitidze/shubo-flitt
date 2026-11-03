# Operations

Commands below run in the Magento root inside the PHP runtime (prefix with the
host project's container exec, e.g. `docker compose exec -T phpfpm`).

## Install

```bash
# as a git checkout (development)
git clone https://github.com/nshubitidze/shubo-flitt.git app/code/Shubo/Flitt
composer require flittpayments/php-sdk:^1.0
# or as a package: composer require shubo/module-flitt  (needs the repo configured)

bin/magento module:enable Shubo_Flitt
bin/magento setup:upgrade          # creates shubo_flitt_order
bin/magento setup:di:compile       # validate wiring (clear generated/ first if it complains)
bin/magento cache:flush
```

Then configure (see configuration.md) and make sure cron runs — the
reconciler is part of correctness, not an optional extra.

## Local dev environment checklist

1. Store currency GEL (base/default/allowed) for the test merchant.
2. Storefront on a **real hostname** resolving to the dev box (e.g.
   `magento.test` in the hosts file; non-80 ports work) and Magento base URLs
   set to it — Flitt's gateway iframe refuses `localhost`/IP origins.
3. If PHP runs in a container, map that hostname to the host there too
   (e.g. compose `extra_hosts: ["magento.test:host-gateway"]`) so server-side
   HTTP checks reach the store.
4. Test merchant `1549901` / secret `test`, `debug=1`.
5. Callbacks cannot reach a local machine — settle with a signed synthetic
   callback (below) or let the return leg / reconciler do it.
6. Card numbers for the test merchant: Flitt docs, https://docs.flitt.com/api/testing/
   (the 3DS emulator may appear for any card; "Continue" passes it).

## Settling locally with a synthetic callback

A local store cannot receive Flitt's callback. To exercise the real
callback path (signature check, settle, and a real capture of a real test
hold), POST to `flitt/callback/index` (`Content-Type: application/json`)
the output of `Shubo\Flitt\Gateway\Signature::sign($fields, <secret>)` —
the fields with `signature` added — from a small PHP script that bootstraps
Magento. `$fields` are those of the signed status for the Flitt order: at
least `order_id`, `order_status`, `merchant_id`, `amount`, `actual_amount`,
`currency`, `reversal_amount`, `payment_id`, `response_status`. The body may
also be nested as `{"response": {...}}`. The ledger row must exist (the Flitt order
was minted by this store). Without a callback, the return leg or the
reconciler settles the order (lifetime + grace).

Other local triggers: Invoice → Capture Online, Credit Memo → Refund and
Void/Cancel run from the admin as usual; the reconciler runs with
`bin/magento cron:run` (job `shubo_flitt_reconcile`) or by calling
`Shubo\Flitt\Cron\PendingOrderReconciler::execute()`.

## Observability

- Log: `var/log/shubo_flitt.log` — one INFO line per API call (`/path
  succeeded in Nms (order_id: …, order_status: …)`), every callback/return/
  reconciler decision, ERRORs with masked excerpts and Flitt `request_id`.
  `debug=1` adds full masked payload dumps.
- Ledger:
  ```sql
  SELECT entity_id, flitt_order_id, increment_id, state, amount, currency,
         payment_id, order_status, callback_count, updated_at
    FROM shubo_flitt_order ORDER BY entity_id DESC LIMIT 10;
  ```
  `callback_count=0` on a settled row means placement/return/reconciler settled it.
- Payment session facts: `sales_order_payment.additional_information`
  (`flitt_order_id`, `flitt_token_amount`, `flitt_checkout_mode`, …).
- Live Flitt truth for an order: `FlittClient::getOrderStatus('<flitt_order_id>')`.

## Production checklist

- Real merchant credentials per website; `debug=0`.
- Preauth enabled on the merchant; hosted page configured card-only.
- HTTPS storefront; `server_callback_url` publicly reachable (callbacks don't
  follow redirects — base URL must be the final one).
- Cron running; consider `sales/orders/delete_pending_after` ≥ 660 min.
- Apple Pay domain verification before enabling that toggle.
