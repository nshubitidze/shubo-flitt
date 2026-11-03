# Flitt API — reference

The parts of Flitt's API the module uses, tagged [docs], [code] or [live]
(see `../SKILL.md`). Full field lists: the linked pages under
https://docs.flitt.com/api/.

## Host and environments

- API base: `https://pay.flitt.com/api` (module setting `api_url`, default
  in `etc/config.xml`). There is **no separate sandbox host**: a test
  merchant (or a merchant in test mode) uses the same host. [docs][code]
- Public test merchant: `merchant_id` `1549901`, secret `test`; test cards
  (approve/decline, with and without 3-D Secure):
  https://docs.flitt.com/api/testing/ [docs]
- Embed widget and its assets: `https://pay.flitt.com/latest/checkout-vue/`
  (`checkout.js`, `checkout.css`). [docs][code]
- Callbacks come from `54.154.216.60` and `3.75.125.89` (allow them in a
  firewall). [docs]

## Envelope — https://docs.flitt.com/api/request/

- Request: `POST`, `Content-Type: application/json`, body
  `{"request": {...}}`. Every request carries `merchant_id`, `signature` and
  `version`; the module sends `version` `"1.0.1"` (the docs' default; `1.0`
  is deprecated). [docs][code]
- Response: `{"response": {...}}`. `response_status` is `success` or
  `failure` and describes only the HTTP request — the payment outcome is in
  `order_status`, `capture_status` or `reverse_status`. [docs]
- Failure envelope: `{"response": {"response_status": "failure",
  "error_code": 1014, "error_message": "...", "request_id": "..."}}`, on
  HTTP 200. [docs][live 2026-07-14]
- The client (`FlittClient::post()`) rejects, in order: transport error,
  non-200 HTTP, non-JSON, missing `response`, `response_status` ≠
  `success` (keeping `error_code` on `FlittApiException`), and a bad or
  missing signature where the endpoint requires one. It logs `request_id`
  on every failure. [code]

## Signature — https://docs.flitt.com/api/building-signature/

1. Take all parameters of the request (including `merchant_id` and
   `version`) or of the response/callback. [docs]
2. Drop `signature` and `response_signature_string`. [docs][code]
3. Drop parameters whose value is empty (`""` or null); a value `0` stays.
   [docs][code]
4. Sort the rest by key (alphabetical) and take the values in that order.
   [docs]
5. Join `secret_key` and the values with `|`:
   `secret|v1|v2|…`. [docs]
6. `sha1` of the UTF-8 string, lowercase hex = `signature`. [docs]

- Responses and callbacks are verified with the same scheme; nested
  `additional_info` is a JSON *string*, signed as-is. [docs]
- In test mode responses also carry `response_signature_string` (the signed
  string with the secret masked) to debug mismatches. [docs]
- The hash is delegated to the vendor SDK (`Flitt\Helper\ApiHelper`,
  scheme `1.0`); `Gateway/Signature` rejects non-scalar values and compares
  with `hash_equals`. The SDK's scheme `2.0` (base64 envelope) is not used.
  [code]

## Endpoints the module calls

| Endpoint (`Model/Endpoint.php`) | Used for | Response signed? |
|---|---|---|
| `POST /checkout/url` | create order, hosted-page URL (redirect mode) | no — TLS only [docs][live 2026-07-14] |
| `POST /checkout/token` | create order, token for the embed widget | no — TLS only [docs][live 2026-07-14] |
| `POST /status/order_id` | the authoritative state of an order | yes, required [docs][code] |
| `POST /capture/order_id` | capture a held amount | no; treated as advisory [docs][code] |
| `POST /reverse/order_id` | release a hold / refund | yes, required [docs][live 2026-07-18] |
| `POST /recurring` | charge a saved card (rectoken) | yes, required [docs][code] |

A signature that is present is always verified, even where it is not
required. [code]

### Create order — `/checkout/url`, `/checkout/token`

https://docs.flitt.com/api/create-order/ · parameters:
https://docs.flitt.com/api/order-parameters/

Request fields the module sends (`Model/CheckoutPayloadBuilder.php`) [code]:
`order_id` (`{prefix}_{increment}_{unixtime}`, prefix optional), `order_desc`,
`amount` (minor units), `currency`, `lang`, `lifetime`, `preauth` (`Y`
always), `merchant_data`, `sender_email` (when known), `required_rectoken`
(`Y` for a logged-in customer when saved cards are on), and
`server_callback_url`. Redirect mode also sends `response_url` and
`cancel_url` (both the same return route); embed mode sends neither.
`delayed` is not sent, so Flitt's default `Y` applies.

- `order_id` must be unique per merchant (else 1013); up to 1024 chars. [docs]
- `lifetime`: seconds until an unpaid order turns `expired`; default 36000,
  documented max 69120000. Module default 36000. [docs][code]
- `lang`: two-letter page language (`ka`, `en`, `ru`, …); the module maps the
  store locale to the documented list, default `ka`. [docs][code]
- Response: `/checkout/url` → `checkout_url` (+ `payment_id`);
  `/checkout/token` → `token`. The module reads only `checkout_url` /
  `token`. [docs][code]

### Order status — https://docs.flitt.com/api/order-status/

Request: `order_id`. Response: the full order envelope
(https://docs.flitt.com/api/order-parameters/, response tab). [docs]
Fields the module reads [code]: `order_id`, `merchant_id`, `order_status`,
`amount`, `actual_amount`, `currency`, `reversal_amount` (total of all
reversals [docs]), `payment_id` (Magento transaction id), `masked_card`,
`card_type`, `rrn`, `approval_code`, `rectoken`, `rectoken_lifetime`
(`DD.MM.YYYY hh:mm:ss` [docs]), `additional_info`.
Before any money step the module checks `order_id`, `merchant_id`,
`amount`, `currency` and `reversal_amount` against what it expects. [code]

`additional_info` is a JSON string [docs]; the module reads:
- `capture_status` (`captured` when done) and `capture_amount` — **major
  units** (`1.0` = 100 minor), unlike every top-level amount.
  https://docs.flitt.com/api/get-capture-status/ [docs][code]
- `payment_method`: `card`, `apple` or `googlepay` [docs]; absent = treated
  as card [code].

### Capture — https://docs.flitt.com/api/create-capture/

- Request: `order_id`, `amount`, `currency` (`amount` = `actual_amount` for a
  full capture). Response: `capture_status` `hold` (not final yet) or
  `captured`, `response_code`, `response_description`; the documented
  response has no `signature`. [docs]
- Only for holds made with `preauth=Y`, only for card payments; **one
  capture per hold**; a partial capture returns the rest to the payer
  automatically. Retrying with the same amount is safe and returns the
  current `capture_status`; a different amount after a capture → 1109. [docs]
- The module captures the full amount only, treats `hold` as "try again",
  and accepts a capture only when the signed status shows `captured` with
  the exact `capture_amount` (`Model/Checkout/HoldCapture.php`). [code]

### Reverse — https://docs.flitt.com/api/create-reversal/

- Request: `order_id`, `amount`, `currency`, optional `reverse_id` (≤ 50
  chars, idempotency key per `order_id`), optional `comment`, `email`. [docs]
- Response: `reverse_status`, `reversal_amount`, `reverse_id`,
  `transaction_id`, `signature`. [docs] The module accepts only
  `reverse_status` = `approved`. [code]
- An **uncaptured hold** can only be reversed in full; that release is
  instant and carries no acquiring fee. A captured payment allows several
  partial reverses up to `actual_amount`. [docs]
- `reverse_id` values the module uses: refund `cm{amount}-r{alreadyReversed}`,
  void `void-{increment}`, reconciler `rec-{increment}`. [code]

### Recurring (saved card) — https://docs.flitt.com/api/create-order-recurring/

- Request (https://docs.flitt.com/api/order-parameters-recurring/): `order_id`,
  `order_desc`, `amount`, `currency`, `rectoken` (mandatory), optional
  `lifetime`, `merchant_data`, `preauth`, `sender_email`,
  `server_callback_url`. `lang` and `required_rectoken` are not parameters
  of this endpoint. [docs] The module sends exactly these (`preauth=Y`),
  then captures like a card checkout (`Model/Vault/RecurringCharge.php`). [code]
- Host-to-host only: no redirect and **no 3-D Secure**. The answer is the
  full signed order envelope, which the module treats as authoritative (a
  callback may also follow). [docs][code]

## Statuses

- `order_status` [docs]: `created` (no payment details yet), `processing`
  (in progress — with `delayed=Y`: an attempt was declined, the order is
  still payable), `declined` (with `delayed=Y`: declined and the lifetime is
  over), `approved` (money held or charged), `expired` (lifetime over,
  unpaid), `reversed` (fully reversed; `reversal_amount` = `actual_amount`).
  https://docs.flitt.com/api/delayed/
- `capture_status`: `hold`, `captured`. [docs]
- `reverse_status`: the docs reuse the order-status list; only `approved`
  counts as done. [docs][code]
- `verification_status` (`verified`, `incorrect`, `failed`, `created`) is set
  on card-verification orders (`verification=Y`, auto-reversed after
  approval). The module never sends `verification=Y` and treats any callback
  with a non-empty `verification_status` as "not a sale". [docs][code]

## Callback — https://docs.flitt.com/api/callbacks/

- `POST` to `server_callback_url`, `Content-Type: application/json;
  charset=UTF-8`, the order fields as the body, signed like a response;
  30 s timeout; **redirects are not followed**. [docs]
- Only HTTP 200 counts as delivered; otherwise Flitt retries after 2, 60,
  300, 600, 3600 and 86400 seconds. [docs]
- With `delayed=Y` one callback per payment attempt. [docs]
- Module (`Model/Callback/Processor.php`): accepts a flat JSON body, a
  `{"response": {...}}`-wrapped one or form fields; resolves the store from
  its own table, verifies the signature and `merchant_id` before anything
  else; answers 200 (final), 400 (bad payload/signature — retry cannot
  help) or 503 (transient — let Flitt retry). The URL is built from the
  store's secure base URL so that nothing redirects. [code]

## Browser return — `response_url`, `cancel_url`

- `response_url`: where the hosted page sends the customer after payment,
  with the payment result by POST or GET; the posted result is signed like a
  callback. `cancel_url`: where the page's Cancel button goes. [docs]
  https://docs.flitt.com/api/payment-flow/
- With `delayed=Y` the customer may never come back to `response_url`; rely
  on the callback. [docs]
- The module points both at one route and decides from the signed payload
  and the signed status, never from which link was used. [code]

## Wallets (Apple Pay, Google Pay)

- In the embed widget: `wallet_methods_enabled` (`apple`, `google`; default
  both). https://docs.flitt.com/api/embedded-custom/ [docs]
- Preauth and capture exist only for cards; other methods are charged in
  one stage regardless of `preauth`. The method is in
  `additional_info.payment_method`. [docs] The module skips the capture for
  non-card payments. [code]
- Apple Pay on the web needs domain verification: get the
  `apple-developer-merchantid-domain-association` file from Flitt support,
  serve it under `/.well-known/` on every (sub)domain, ask Flitt to activate
  the domain. https://docs.flitt.com/api/applepay-web/ [docs]

## Embed widget (checkout-vue) — https://docs.flitt.com/api/embedded-custom/

- `checkout(selector, {options: {...}, params: {token}})` with the token from
  `/checkout/token`. [docs]
- Options the module sets: `methods: ['card']`, `methods_disabled` (docs list
  `card`, `most_popular`, `banks`, `wallets`), `wallet_methods_enabled`,
  `full_screen: false`, `show_pay_button: false` (Magento's Place Order is the
  trigger). [docs][code] Events: see `live-findings.md`.
- Widget error codes (2000–2040, e.g. 2000 issuer decline, 2009 already paid,
  2013 invalid card number): https://docs.flitt.com/api/response-codes/ [docs]

## API error codes — https://docs.flitt.com/api/response-codes/

| Code | Meaning [docs] | Module [code] |
|---|---|---|
| 1007 | parameter incorrect | generic failure; a bogus rectoken also gives 1007 [live 2026-07-24] |
| 1013 | duplicate `order_id` | order-id time tail always moves forward on a new session |
| 1014 | invalid signature | — |
| 1018 | order not found (status, reverse) | reconciler leaves the row for its next run |
| 1027 | preauth not allowed | must be enabled on the merchant; admin field note |
| 1035 / 1078 / 1082 | token not found / deactivated by bank / expired | saved card removed |
| 1059 / 1060 | rectoken / recurring not allowed for merchant | admin field note: ask Flitt |
| 1109 | already captured with a different amount | refused before calling |
| 1126 | not in the docs' list | reused `reverse_id` after a successful reverse [live 2026-07-18] |

Any other `error_code` on `/recurring` is a definitive decline. [code]
