---
name: shubo-flitt-gateway
description: How the Shubo_Flitt Magento 2 payment module works — Flitt (TBC Bank) card payments via embedded form or hosted redirect, authorize-first holds and server-side capture, signed callbacks and the shubo_flitt_order ledger, return leg and reconciler cron, native admin capture/refund/void, Apple/Google Pay, saved cards (Magento_Vault + rectoken). Use when installing, configuring, testing, debugging, reviewing or extending Shubo_Flitt, or when a Magento order paid with shubo_flitt / shubo_flitt_vault behaves unexpectedly.
---

# Shubo_Flitt — module skill

Knowledge of **this module's** design and operation. For Flitt API facts
(endpoints, fields, statuses, signature rules, error codes) use the sibling
`flitt-integration-skill` — never invent Flitt behaviour.

Scope: embed and redirect checkout, callback reconciliation, return leg and
reconciler, admin money ops, wallets, saved cards. Not built: adding a card
without a purchase (Flitt `verification=Y`), platform commission (Flitt split
settlement — a separate module if ever), unit tests.

## The five invariants (read these even if nothing else)

1. **Signed Flitt data is the only truth about money.** The browser/JS never
   decides success; placement, callbacks, returns and the cron all re-check the
   signed `/status/order_id` envelope (or a signature-verified callback).
2. **Authorize-first.** Every checkout mints `preauth=Y` — money is only HELD;
   the charge is a server-side capture (`HoldCapture`), confirmed via signed
   status because Flitt's capture response is unsigned. An orphaned payment
   is a fee-free reversible hold, never taken money. (Wallets are the
   exception: one-stage charge.)
3. **One ledger row per Flitt mint** (`shubo_flitt_order`), forward-only state
   `minted → declined/expired → approved → settled → reversed`, advanced
   atomically. The callback can only find its store (and secret) through it.
4. **One lock per Magento order** (`OrderLock`, keyed by increment id) around
   every money-adjacent step: mint, settle, capture, refund, reconcile.
5. **Native Magento surfaces only** — standard success/cart pages, native
   Invoice/Credit Memo/Void/Cancel buttons; the module implements the commands
   behind them.

## When to read what

| Task | Read |
|---|---|
| Understand the wiring / find the class that does X | `reference/architecture.md` |
| Admin fields, defaults, capability flags, what each toggle does | `reference/configuration.md` |
| Embed, redirect, callback, return, reconciler, capture/refund/void, vault, wallets — step by step | `reference/flows.md` |
| Why it is built this way (settled choices — do not undo them as a side effect) | `reference/decisions.md` |
| Add a field, a Flitt call, a pool command; verify a change | `reference/changing.md` |
| Install, local environment, synthetic callbacks, logs and ledger, production | `reference/operations.md` |
| Something is wrong (stuck order, error code, form won't load, customer logged out…) | `reference/troubleshooting.md` |
| Code style and gates | `reference/conventions.md` |
