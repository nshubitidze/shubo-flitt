# How Flitt behaves (observed)

What the API and the embed widget actually did, by topic. Observed with
Flitt's public test merchant (`1549901`, test mode, real test cards); each
fact carries the date it was seen. Where this disagrees with the docs, this
wins. A production merchant may behave differently.

## Access and origins

- The widget's gateway iframe answers **403 for `localhost` and IP-address
  origins**; any real-looking hostname works, even a made-up TLD. Test the
  embed on a hostname, never on `localhost`. [live 2026-07-16]
- A storefront on a non-standard port (`http://<host>:8080`) was accepted.
  [live 2026-09-28]

## Envelope and signature

- Failure envelopes always come with **HTTP 200**; only
  `response_status`/`error_code` tell. The API sits behind CloudFront.
  [live 2026-07-14]
- The **example signatures in the docs do not match** their own example
  strings (sha1 of the printed string ≠ the printed signature). The
  algorithm (docs' PHP/Python samples, the vendor SDK) is right — never
  change the code to match a docs example. [live 2026-07-14]
- Success responses of `/checkout/url` and `/checkout/token` carry **no
  signature**. [live 2026-07-14]
- The `/reverse/order_id` response is signed. [live 2026-07-18]
- `/status/order_id` for an `order_id` Flitt never created answers
  **1018** "Order not found". [live 2026-09-30]

## Creating orders

- Two sessions created for the same order within one second collided on
  `order_id` → **1013**; an id that only adds `time()` is not unique enough
  for back-to-back calls. [live 2026-07-15]
- A `lifetime` above the documented maximum (69120000) was accepted.
  [live 2026-07-14]
- The test merchant accepts `preauth=Y` (no 1027). [live 2026-07-17]

## Paying on the test merchant

- The test merchant raises a **3-D Secure emulator challenge even for cards
  the docs list as non-3DS or frictionless**. [live 2026-09-28]
- In the embed the challenge is a full-page overlay iframe on
  `pay.flitt.com` (a test-mode path); no other origin and no top-window
  navigation. The widget's `success` fires only after the challenge.
  [live 2026-07-16]
- A declined card leaves the order in **`processing`** and the **same token
  stays payable** (`delayed=Y`); the widget shows its own "Declined" view.
  [live 2026-07-16]

## Capture and reverse

- Captures were confirmed through the signed status: `additional_info`
  `capture_status: "captured"` and `capture_amount` in major units matched
  the held amount. The module never relies on the capture response itself.
  [live 2026-07-17]
- Reusing a `reverse_id` **after a successful reverse is an error**, 1126
  "Reverse with the same reverse_id already exists" — not an idempotent
  success. The docs' "safe retries" holds only for failed attempts.
  [live 2026-07-18]
- The reverse **response's `reversal_amount` is this reversal only**
  (second partial of 500 after 1000 returned 500); the status envelope's
  `reversal_amount` is the running total (1500). [live 2026-07-18]
- Reverses returned final states; the `created`/`processing` values the docs
  list for `reverse_status` were never seen. [live 2026-07-18]
- A full reverse of an uncaptured hold released it at once, including a hold
  about a day old. [live 2026-07-18]

## Hosted page and browser return

- After payment the hosted page sent the browser to `response_url` and the
  signed result could be verified there. The return is a **cross-site POST**,
  so a `SameSite=Lax` session cookie is not sent with it; continue on a
  top-level GET to get the customer's session back. [live 2026-07-18]
  [live 2026-07-24]
- The page's Cancel button sent the browser to `cancel_url`. [live 2026-07-18]
- **Return relay outage:** after paying on the hosted page the browser was
  sent to `http://secure-redirect.cloudipsp.com/submit/` (Flitt's legacy
  return relay), which timed out over HTTP and HTTPS while `pay.flitt.com`
  answered. The customer never reached `response_url` although the hold was
  approved; the callback or the reconciler has to settle such orders. The
  embed is not affected. [live 2026-09-28] **Working again:** the same relay
  passed the browser on to an `http://` store's `response_url` (pay and
  Cancel); to an `https://` store the browser went straight back, without
  the relay. [live 2026-11-03]
- To a public HTTPS store the callback arrived for every paid session (1–2
  callbacks each, all answered 200), also when the browser's return was
  blocked — the callback alone settled the order. After a full `/reverse`
  of a parked payment Flitt sent one more callback, `order_status`
  `reversed`, `reversal_amount` = the full amount. [live 2026-11-03]

## Saved cards (rectoken)

- The test merchant issues rectokens for `required_rectoken=Y` without any
  enablement by Flitt support (no 1059/1060). [live 2026-07-24]
- The signed **status envelope carries `rectoken` and `rectoken_lifetime`**,
  so a card can be saved without waiting for the callback. The lifetime was
  absolute: `"01.01.2030 00:00:00"` (docs examples show it empty).
  [live 2026-07-24]
- `/recurring` with `preauth=Y` returned an approved hold that was then
  captured normally. [live 2026-07-24]
- A bogus rectoken on `/recurring` fails with **1007**, not 1035.
  [live 2026-07-24]

## Embed widget (checkout-vue)

Read from the shipped bundle and confirmed in a browser; the docs do not
describe the widget's events.

- `/latest/` is the only published channel and the bundle hard-codes its
  public path (`pay.flitt.com/latest/checkout-vue/`) for its ~90 lazy chunks:
  it cannot be self-hosted or pinned to a version. `checkout.css` is needed
  separately. `checkout.js` sets the global `window.checkout`.
  [live 2026-07-16]
- Root events: `ready` (loaded), `success` (payment approved — cards and
  wallets alike), `error` (**both** a failed load and a declined payment).
  There is no `$off`; mounting again on the same selector destroys the old
  instance. A bad selector or options only log to the console.
  [live 2026-07-16]
- Card fields are plain inputs in the page, not iframes. [live 2026-07-16]
- **Client-side field errors emit no event** (inline messages only); an
  abandoned 3-D Secure challenge emits nothing for at least 2 minutes; a
  blocked gateway iframe emits nothing and has no timeout — the page needs
  its own timeout. [live 2026-07-16]
- `error` payloads expose `error.code` / `error.message` (e.g. 2000 issuer
  decline, 2013 invalid card number). [live 2026-07-16]
- Mounting a token whose order is already approved shows the widget's
  success view and fires `ready` with `order.order_data.order_status =
  approved` — not error 2009. [live 2026-07-17]
- `methods: ['card']` alone still lists every method the merchant has on;
  each must be turned off in `methods_disabled`, which also accepts the
  undocumented `installments` and `loans`. [live 2026-07-16]
- With wallets on, the widget loads Google Pay from `pay.google.com` (+
  `www.gstatic.com` images) and the Apple Pay SDK from
  `applepay.cdn-apple.com`. The Google Pay button refuses to render on plain
  HTTP (needs a secure context). [live 2026-07-24]
- On HTTPS with the test merchant both wallet buttons render inside the
  widget (Google's button image is an iframe from `pay.google.com`), with no
  CSP violations. A tap on Google Pay opens Google's payment sheet in a
  popup with `environment: TEST` and `merchantName: "Test merchant"` (the
  widget decides the environment, not the module). [live 2026-11-03]
- A Google Pay payment in that TEST sheet reached Flitt as
  `additional_info.payment_method` = **`googlepay`**, card Amex
  `378282…0005` (Google's TEST card), and was declined: `response_code`
  **1015** "Invalid card number", `order_status` `processing` (callback).
  The same session stayed payable by card. On the same merchant a typed
  Visa `4111 1111 1111 1111` (not in Flitt's test-card list) was approved,
  so test mode does not accept only its own list. [live 2026-11-03]
- **Google Pay with `preauth=Y` is a hold that must be captured**, in the
  embed and on the hosted page alike, contrary to the docs' "capture only for
  the card payment method". Paid with Google's TEST sheet and Visa
  `4111…1111` (approved; the Amex above is declined): the signed status had
  `payment_method` `googlepay`, `card_type` `VISA`, `tran_type` `purchase`,
  `capture_status` **`hold`**, `capture_amount` 0 — the same as a card hold
  before capture — and stayed so for 2.5 hours. `/capture/order_id` for the
  full amount answered `success` / `captured`; the status then showed
  `captured` with `capture_amount` = the amount. A full `/reverse` of an
  uncaptured Google Pay hold was approved and followed by a `reversed`
  callback. [live 2026-11-03] [live 2026-11-06]
- In Chrome the Apple Pay button opens Apple's "Scan Code with iPhone"
  window (`applepay.cdn-apple.com/applepaycode`, iOS 18+); on a Mac in
  Safari it is the normal Apple Pay sheet. [live 2026-11-03]
