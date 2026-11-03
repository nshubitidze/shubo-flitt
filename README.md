# Shubo_Flitt — Flitt (TBC Bank) payments for Magento 2

A payment method for Magento Open Source 2.4 that takes card payments
through **Flitt** (flitt.com), the payment gateway of TBC Bank in Georgia.

- **Checkout:** Flitt's card form embedded in the Magento checkout, or a
  redirect to Flitt's hosted payment page. Optional Apple Pay and Google Pay
  buttons in the embedded form.
- **Money held first:** every checkout only holds the amount; the module
  captures it on the server when the order is placed (Authorize & Capture)
  or when you invoice it (Authorize Only). A payment whose order was never
  created is a hold that is released automatically, free of charge.
- **Verified on the server:** every order, invoice and refund is checked
  against Flitt's signed payment status — never against what the browser
  reports. Flitt's server callback and a cron job settle orders whose
  customer never came back, and place the order for a paid checkout whose
  browser was closed.
- **Admin:** Magento's own buttons — Invoice → Capture Online, online
  Credit Memo (full or partial refunds), Void and Cancel.
- **Also:** saved cards for logged-in customers (Magento Vault), GEL / USD /
  EUR, an order-id prefix for several shops on one Flitt account, Georgian
  translation.

## Requirements

- Magento Open Source 2.4.9 (Luma checkout; Hyvä is not supported), PHP
  8.3–8.5, Magento cron running.
- A Flitt merchant account with **pre-authorization enabled** (and recurring
  payments for saved cards). For testing, Flitt's public test merchant
  `1549901` with secret key `test` (see Flitt's docs for test cards).
- The embedded form needs the store on a real domain (Flitt refuses
  `localhost` and IP addresses); Apple Pay and Google Pay need HTTPS, Apple
  Pay also domain verification through Flitt support.

## Install

```
# from the Magento root
composer require flittpayments/php-sdk:^1.0
mkdir -p app/code/Shubo && git clone https://github.com/nshubitidze/shubo-flitt.git app/code/Shubo/Flitt
# or: composer require shubo/module-flitt (when installed from a Composer repository)

bin/magento module:enable Shubo_Flitt
bin/magento setup:upgrade
bin/magento setup:di:compile          # production mode
bin/magento setup:static-content:deploy   # production mode
bin/magento cache:flush
```

## Configure

**Stores → Configuration → Sales → Payment Methods → TBC Bank (Flitt)**

1. Enabled, Merchant ID and Payment Secret Key from the Flitt portal.
2. Checkout Type: Embedded Card Form (recommended) or Redirect to Payment Page.
3. Payment Action: Authorize & Capture (charge at order placement) or
   Authorize Only (charge when you invoice with Capture Online).
4. Optional: Google Pay / Apple Pay, Saved Cards, Allowed Currencies, Order
   ID Prefix.

Every setting has an explanation under it in the admin. For production,
configure the hosted page in the Flitt portal for cards only (other payment
types cannot hold money first).

## Good to know

- Flitt allows one capture per payment: invoice the full order amount.
  Refunds may be partial and repeated.
- A redirect order stays "Pending Payment" while its Flitt payment page can
  still be paid (10 hours by default); the cron cancels it afterwards.
  Magento's own "Pending Payment Order Lifetime" (default 480 minutes) is
  shorter — set it to at least 660 minutes.
- Log: `var/log/shubo_flitt.log`; one row per Flitt payment in the table
  `shubo_flitt_order`.

## Development

Architecture, decisions, code conventions and how to change the module are
in `.claude/skills/shubo-flitt-gateway/` (written for both people and AI
coding assistants). Flitt API notes are in
`.claude/skills/flitt-integration-skill/`.

## License

Apache License 2.0 — see `LICENSE`. Flitt and TBC Bank are trademarks of
their owners; this module is not affiliated with them.
