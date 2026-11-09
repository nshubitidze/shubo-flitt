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

## Never a second payment for one quote

A quote may carry several sessions (re-mints after totals or login changes, a second tab with an older form).
The token endpoint refuses to mint while any of them is approved and not fully reversed, and the state endpoint
reports such an older approved session as `blocked` (contact us), never `unpaid`. Flitt's signed status decides,
not the ledger alone: the callback may arrive later than the next request.

Why: paying, failing to place, changing the total and reloading — or paying in a second tab — minted a fresh
session and offered a fresh form, and the customer could pay twice (seen with Flitt's test merchant: a 14.90 hold
and a 13.90 capture for one order). The orphaned hold is the reconciler's to reverse.

## Payments wait while the total is being re-checked

A totals change sets `refreshing` at once; Place Order and wallet taps are refused until the token check answers
(same token → go on; new token → the form is re-created for the new amount).

Why: a click inside the 800 ms debounce paid the old amount, or the form was destroyed in the middle of its own
submit and the typed card was lost without a message.

## A payment starts only when the order can be placed right after it

`canStartPayment()` (Place Order in embed mode and the wallet click gate) also requires Magento's
`isPlaceOrderActionAllowed()` — a billing address — and says so if not. After a successful payment the form shows
the `paid` state before placing, so a placement that cannot start still tells the customer what to do.

Why: a Google Pay tap with no billing address saved went through; core then refused to place without a message,
and the customer was left with a paid card, no order and no explanation.

## Enter in a card field goes through placeOrder()

The widget submits its card form by itself on Enter (on `keyup`, in its own input handler). A capture-phase
`keydown`/`keyup` listener on the container takes both key events of an Enter in an `<input>` before the widget sees
them and calls `placeOrder()` on the `keydown` — so Enter runs the same checks as a Place Order click (validators,
agreements, billing address, totals check) and then `submit()`s the widget. Other keys pass through.

Why: Enter in the card form started a payment without any of those checks — with no billing address the customer
paid, and the order could only be placed after saving one.

## A decline is also shown under the form

An `error` from the widget after `ready` is a payment decline: the same token stays payable, nothing is reset, and
`showDecline()` puts "Your payment was declined: <Flitt's reason>" in its own message area under the card form
(not Magento's message list, which hides itself after 5 s) and scrolls it into view. It clears on the next payment
attempt (Place Order, Enter, an allowed wallet tap), on success and on remount.

Why: the widget's own decline popup is laid out against the whole page, not the window — on a long checkout it
opened below the visible area, and the customer saw nothing happen.

## One timer for the whole mount, timeouts on our AJAX calls

The 30 s ready timer starts when mounting starts (script + token + ready), and the token and state requests time
out after 45 s (above the server's Flitt API timeout).

Why: Magento's RequireJS has no load timeout, and jQuery AJAX none by default — a request that never answered left
"Loading…" on screen forever with no "Try again".

## A fresh form while paid asks the server first

When the widget becomes ready while `paid` is set (switch method and back, re-created form), the renderer asks the
state endpoint instead of showing the form; the "already paid" warning shows only when the customer leaves the
card method. Place Order (embed) is active only in the `ready` and `paid` states.

Why: the widget stayed hidden (paid) with no message — an empty card area.

## Card tokens live only in the vault

The ledger (`shubo_flitt_order`) does not store the `rectoken`; the column was dropped. A card token is kept only
as the Magento vault token, created at settle from Flitt's signed status when the customer ticked "save this card"
(unticked by default). Logs mask `rectoken`, `response_signature_string` (it lists every signed value in test mode)
and `sender_email`.

Why: every callback wrote the rectoken into the ledger whether or not the customer consented, and kept it — a
chargeable credential (`/recurring` has no 3-D Secure) outside the vault, for customers who never asked to save a card.
