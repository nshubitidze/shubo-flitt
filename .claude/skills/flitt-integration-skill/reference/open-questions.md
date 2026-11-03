# Open questions

What is still unknown about Flitt. Do not guess these in code; when one is
answered (by Flitt or live), move the answer into `live-findings.md` or
`api.md` with its source and remove it here.

## Saved cards

- On a **production** merchant: are rectokens issued without asking Flitt
  support (codes 1059/1060 suggest they may need enabling), and what
  `rectoken_lifetime` comes back — a date, or empty as in the docs examples?
- Time zone of `rectoken_lifetime`; the module assumes UTC.
- Can the `rectoken` echoed in a `/recurring` response differ from the one
  sent (token rotation)? The module does not update the stored card.

## Callbacks and returns

- The wire format of a real callback: the docs show flat JSON; the module
  also accepts a `{"response": ...}` wrapper and form fields because older
  gateways sent those. Not yet observed against a public URL.
- Does Flitt send a callback for a merchant-initiated reverse or capture
  (e.g. a `reversed` callback after a full refund)? The module does not
  depend on it.
- Is the `secure-redirect.cloudipsp.com` return relay outage permanent or
  was it temporary? Not reported to Flitt yet.
- The docs allow the return by POST or GET; only POST has been seen.

## Holds and payments

- How long does an uncaptured `preauth=Y` hold stay on the card before the
  issuer releases it? Not documented; `lifetime` covers only the unpaid
  order. The module reverses orphan holds itself.
- A completed Apple Pay / Google Pay payment has not been observed: the live
  value of `additional_info.payment_method`, and whether `preauth=Y` is
  ignored for wallets as the docs imply.
- 3-D Secure on a production merchant: which issuer (ACS) origins the
  challenge loads — test mode showed only `pay.flitt.com` (matters for the
  checkout CSP `frame-src`).
- `reverse_status` `created`/`processing`: listed by the docs, never seen;
  the module treats anything but `approved` as not done.

## Widget

- Does Flitt publish a versioned path of `checkout-vue`? Only `/latest/` is
  known, so a Flitt release can change the checkout without notice.

## Not used by the module

- Split payments / settlement (`/api/settlement`, the `2.0` signature
  envelope), card verification orders (`verification=Y`), subscriptions and
  reports — no facts are kept here for them.
