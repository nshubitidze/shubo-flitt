# Changelog

All notable changes to this module are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the module uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-11-09

First public release.

### Added

- Card payments through Flitt (TBC Bank): Flitt's card form embedded in the
  Magento checkout, or a redirect to Flitt's hosted payment page.
- Optional Apple Pay and Google Pay buttons in the embedded form.
- Every checkout only holds the amount; the module captures it on the
  server when the order is placed (Authorize & Capture) or when it is
  invoiced (Authorize Only). A hold whose order was never created is
  released automatically.
- Every order, invoice and refund is checked against Flitt's signed payment
  status. Flitt's server callback and a cron job settle orders whose
  customer never came back, and place the order for a paid checkout whose
  browser was closed.
- Magento's own admin actions: Invoice → Capture Online, online credit
  memos (full or partial refunds), Void and Cancel.
- Saved cards for logged-in customers (Magento Vault).
- GEL, USD and EUR; an order-id prefix for several shops on one Flitt
  account; Georgian translation.
- Card form options for themes through layout XML (`widgetOptions`), a
  retry after the Flitt script fails to load, and a loader while the
  redirect starts.

[Unreleased]: https://github.com/nshubitidze/shubo-flitt/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/nshubitidze/shubo-flitt/releases/tag/v1.0.0
