---
name: flitt-integration-skill
description: Flitt (TBC Bank's payment gateway in Georgia, pay.flitt.com) API facts — the signed JSON request/response envelope, the SHA1 signature, hosted checkout URL and embed token, order status, preauth capture, reverse, saved-card (rectoken) charges, server callbacks and browser returns, order/capture/reverse statuses, error codes, the checkout-vue embed widget, and how the API actually behaves on Flitt's public test merchant. Use when changing, testing or debugging code that talks to Flitt, including the Shubo_Flitt Magento payment module.
---

# Flitt payment API

Facts about Flitt's API that the module relies on. **Never invent Flitt
behaviour**: if it is not here, it is in `reference/open-questions.md`, in
Flitt's public docs, or unknown.

Every fact carries its source:

- **[docs]** — Flitt's public API docs, https://docs.flitt.com/api/introduction/
  (pages are linked where a fact comes from; read them for full field lists).
- **[code]** — what this module does (`Model/FlittClient.php`,
  `Model/Endpoint.php`, `Gateway/Signature.php` and the callers named).
- **[live YYYY-MM-DD]** — observed against Flitt with the public test
  merchant; wins over [docs] where they disagree. Production may differ.

## Files

1. `reference/api.md` — host, envelope, signature, the six endpoints the
   module calls, statuses, callbacks, browser return, wallets, the embed
   widget options, error codes.
2. `reference/live-findings.md` — how the API and the widget actually
   behaved in testing, by topic.
3. `reference/open-questions.md` — what is still unknown.

## Five facts that shape any integration

1. **One host, one signed envelope.** Every call is a JSON `POST` to
   `https://pay.flitt.com/api/<path>` with `{"request": {...}}`, signed with a
   lowercase SHA1 over the secret and the sorted non-empty values. Test vs
   live is the merchant account, not the host. [docs][code]
2. **HTTP 200 does not mean success.** Failures come back as HTTP 200 with
   `response_status: "failure"` and an `error_code`. [docs][live 2026-07-14]
3. **Only some answers are signed.** Order status, reverse, recurring and
   callbacks are; checkout URL/token and capture are not. Money state is
   decided only from a signed envelope. [docs][code][live 2026-07-14]
4. **Amounts are integer minor units** (often as strings in responses) —
   except `additional_info.capture_amount`, which is in major units. [docs][code]
5. **`preauth=Y` holds, capture is card-only and single, wallets charge in
   one stage; `delayed=Y` (the default) keeps an order payable after a
   decline until its `lifetime` ends**, so one order can produce several
   attempts and callbacks. [docs][live 2026-07-16]

## Credentials

Only Flitt's **public** test merchant may appear in code, tests or docs:
merchant id `1549901`, secret key `test` (https://docs.flitt.com/api/testing/).
A real merchant's id and secret key are secrets: never in a repository, the
secret stored encrypted and never logged. Card numbers, CVV2 and rectokens
are masked in logs (`Model/LogSanitizer.php`).
