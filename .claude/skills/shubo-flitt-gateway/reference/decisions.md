# Decisions

Settled choices and why. Changing one is a design change, not a refactor:
do not undo it as a side effect of other work.

## The Flitt SDK only for the signature hash

`flittpayments/php-sdk` is a dependency, but only
`Flitt\Helper\ApiHelper::generateSignature` is used, wrapped by
`Gateway/Signature` (the module's single SDK touchpoint). HTTP, payloads and
response handling are ours (`FlittClient` over Magento's Curl).

Why: reusing the vendor's hash guarantees signature compatibility (the
fiddly, correctness-critical part); the SDK's static/global-state classes
and its HTTP stay out of the charge path. The signature algorithm is stable,
so the pinned `^1.0` needs no watching.

## Gateway framework, without the transport ceremony

Adapter + command pool + value handler pool + typed `Config`, with concrete
`CommandInterface` classes for the money operations. No
`TransferFactory` / `BuilderComposite` / `HandlerChain` virtual-type chains.

Why: the command/capability core is what makes Magento's native buttons and
order states work; the transport plumbing adds dozens of virtual types and
hides the flow without making it more correct.

## Embed is pay-first, redirect is order-first

Embed: the customer pays in the form inside checkout, then the order is
placed and verified against Flitt's signed status in the same request.
Redirect: the order is placed first (`pending_payment`), then the customer
pays on Flitt's hosted page and the callback / return / reconciler settles it.

Why: in the embed the payment can be verified before an order exists, so no
unpaid orders are created; the hosted page needs an order to return to.

## Authorize-first: checkout only ever holds money

Every mint sends `preauth=Y`, whatever the Payment Action. The charge is a
server-side capture — at placement (Authorize & Capture) or by the merchant
(Authorize Only, Invoice → Capture Online).

Why: a payment whose order never materialises (browser closed, placement
failed) is then a hold that can be released fee-free, never taken money.
Requires pre-authorization on the merchant account (Flitt error 1027
otherwise).

Whether a payment is held is read from the signed
`additional_info.capture_status`, never from `payment_method`: `hold` →
capture, whatever the rail; a wallet payment with no `capture_status` →
treated as a one-stage charge (nothing to capture; a void is then a refund).
Why: Flitt's docs limit preauth and capture to "the card payment method",
which we first read as `payment_method = card`, and skipped the capture for
Google Pay — but a Google Pay payment made with `preauth=Y` came back as a
hold and was accepted by `/capture` (it pays with a card underneath). Keyed
on the method, such an order was invoiced in Magento while the money was
only held.

## Signed data decides, the browser only triggers

JS calls `placeOrder` after the widget's success event, but the server
verifies the signed `/status/order_id` envelope (order binding, merchant,
exact amount and currency, no reversal, preauth mode) before any order or
invoice is written. Capture results are confirmed by a signed status read,
because Flitt's capture response is unsigned.

## No custom admin UI, no custom customer pages

Money operations sit behind Magento's own buttons (Capture Online, online
Refund, Void, Cancel); the return leg lands on the standard success page or
the cart with a message. There is no "payment pending" page — core has none,
and with `delayed=Y` the browser may legitimately never come back; the
callback and the reconciler are the source of truth.

## An order whose Flitt session can still be paid is never cancelled

A redirect order stays `pending_payment` after a Cancel click, the Back
button or a declined attempt, and the cart is restored. The reconciler
cancels it only after the session's lifetime plus the grace period.

Why: with `delayed=Y` Flitt keeps the payment page payable (and emails the
customer an "unpaid order" link) after the customer leaves. Cancelling early
let a late payment land on a cancelled order. If that still happens (another
process cancelled the order), the return says so honestly (`LatePaid`) and
the reconciler reverses the money.

The same rule is why `etc/config.xml` raises core's
`sales/orders/delete_pending_after` default from 480 to 660 minutes: core's
own cleanup cron would otherwise cancel redirect orders after 8 hours while
Flitt keeps their page payable for 10. It changes a store-wide default, not a
saved value, so a merchant can still choose otherwise; protecting only Flitt
orders from core's cron would need a plugin on core's cleanup, more code for
little gain.

## The return leg never depends on the customer session

The order is identified from the signed return payload and the ledger; the
session is only a fallback. The cross-site POST return is turned into a
session-less redirect to the same route as GET (`ReturnSessionChecker`).

Why: the cross-site POST carries no SameSite=Lax cookie; letting it start a
session overwrote the customer's cookie and logged them out.

## One capture per hold, many partial refunds

`can_capture_partial=0`: Flitt allows one capture per preauth (a partial
capture returns the remainder automatically). Refunds are `/reverse` calls,
several partial ones allowed, made idempotent by `reverse_id`.

## Allowed currencies are a setting

A multiselect of the currencies Flitt processes for Georgian merchants (GEL,
USD, EUR); the method is hidden for any other order currency.

## Order ids carry a per-shop prefix and a time tail

`{prefix}_{increment}_{unixtime}`: one Magento order may have several Flitt
orders (re-mints), several shops may share one Flitt merchant (prefix), and
the tail is bumped monotonically because Flitt rejects a repeated id (1013).

## The card form's look comes from layout XML; the flow stays pinned

Themes and other modules pass Flitt widget options through the layout-XML
`widgetOptions` of the `shubo-flitt-payments` component (or a mixin on
`getCustomWidgetOptions()`), merged under the module's pinned options. Keys
that decide what is paid or how the payment starts are dropped.

Why: layout XML is the one route both themes and modules have (a theme has
no di.xml), it translates with `translate="true"`, and it needs no copy of
the renderer or its template, so module updates keep working in styled
stores. The payment flow (card only, Magento's Place Order as the single
trigger, the minted token) was verified with these pins; a look change must
not be able to alter it.

## A failed SDK load is retried for real

"Try again" after Flitt's `checkout.js` failed to load (network, ad blocker,
Flitt down) loads it again: the errback marks the failure and `loadSdk()`
calls `require.undef()` right before the next `require()`.

Why: RequireJS caches a failed module, so every retry failed at once until a
page reload. Undefining inside the errback itself leaves Magento's RequireJS
resolver waiting for the failed module, so the page loader never stops.

## Redirect mode keeps the page covered until Flitt's page loads

`afterPlaceOrder()` starts the full-page loader again before
`location.replace()`, as core's redirect-on-success does.

Why: the place-order request stops the loader and re-enables Place Order as
soon as it returns; until the hosted page loaded, the checkout showed again
and could be clicked.

## The widget is hidden once paid

`#shubo-flitt-embed` is hidden while `paid` (success event, or a resumed paid
session); the module's own messages and the place-order loader take over.

Why: the widget's own receipt ("payment accepted", payment id) showed beside
our "payment confirmed" message and under the place-order loader.

## Numeric css_variable strings become numbers

Layout XML hands every number over as a string. With
`css_variable.border_radius: "6"` the widget never became ready and fired no
error. The renderer converts numeric strings under `css_variable` only
(colours stay strings); other options are passed as given.
