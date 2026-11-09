# Changing the module

How-tos for the usual changes. Read `decisions.md` first, and the class
docblocks of the area you touch.

## Add an admin setting

1. Field in `etc/adminhtml/system.xml` with a merchant-facing `<comment>` and
   deliberate scope flags (the group and section must allow the same scope).
   A secret: `type="obscure"`, backend
   `Magento\Config\Model\Config\Backend\Encrypted`, and register the path as
   sensitive in `etc/di.xml` (`Magento\Config\Model\Config\TypePool`).
2. Default in `etc/config.xml` if an operator may tune it.
3. A `private const string FIELD_*` and a typed getter (`?int $storeId`) in
   `Gateway/Config`, in the group it belongs to; guard zero/empty values
   with a named default where 0 means "not set". Nothing else reads config
   paths.
4. Describe it in `reference/configuration.md` and add the Georgian text to
   `i18n/ka_GE.csv`.

## Call another Flitt endpoint

1. Check the endpoint, fields and whether its response is signed in the
   `flitt-integration-skill`; never guess.
2. Add the path to `Model/Endpoint` and decide `requiresSignedResponse()`.
3. A public method on `Model/FlittClient` that calls the private `post()`
   (validation, logging, signature, `FlittApiException` already handled).
4. Build the payload outside the client (a builder class), amounts through
   `Model/Money`.
5. A response that changes money state is only trusted when signed; an
   unsigned answer is confirmed with a signed `/status/order_id` read.

## Add a gateway command

1. A concrete `CommandInterface` in `Gateway/Command`, registered in
   `ShuboFlittCommandPool` (`etc/di.xml`) under the key Magento calls.
2. The matching `can_*` flag in `etc/config.xml` — the Adapter **silently
   skips** a command whose flag is off.
3. Take `OrderLock` around anything that talks to Flitt about money; move
   ledger state with `Ledger::transition()` (forward-only).

## Log something new

Through the class's injected logger (bound to `ShuboFlittLogger` in
`etc/di.xml`); any payload through `Model/LogSanitizer`. More keys to mask,
or another excerpt length, are di.xml arguments of `LogSanitizer`
(`maskedKeys`, `bodyLimit`) — the built-in secrets are always masked.

## Frontend

The renderer is `view/frontend/web/js/view/payment/method-renderer/flitt.js`.
Keep its structural rules (see its header): mount on preselect and select,
string selector, handlers bound once per mount, generation-counter fencing,
failure states with a retry path. A new external host goes into
`etc/csp_whitelist.xml` (checkout CSP is enforced).

## Style the embedded card form (theme or another module)

No copy of the renderer or its template is needed for the form's look and
texts:

1. **Layout XML** (themes and modules): in the theme's
   `Shubo_Flitt/layout/checkout_index_index.xml` (or a module's
   `checkout_index_index.xml`), add `config` → `widgetOptions` to the jsLayout
   item `shubo-flitt-payments` (same nesting as the module's own file; full
   example in the README). The value is Flitt's checkout-vue configuration —
   `options` (theme preset/layout, `show_*`, `card_icons`, …), `messages`
   (keyed by Flitt locale), `css_variable`, … as documented by Flitt. Strings
   take `translate="true"`; they are translated when the checkout layout is
   built, so the phrase must be in a module's or language pack's `i18n` (a
   theme's own CSV is not loaded yet when that theme is applied later, e.g.
   as Hyvä's checkout fallback). An XML `number` arrives as a string: numeric
   strings under `css_variable` are turned into numbers by the renderer
   (`withNumericCssVariables()`) — the widget hangs on a string size; any
   other number Flitt needs goes in through the JS route below.
2. **Computed in the browser** (a module that needs JS): a mixin on the
   renderer overriding `getCustomWidgetOptions()` — call `_super()` and
   extend its result.
3. **From PHP** (per store, from settings): a checkout layout processor that
   writes the same `widgetOptions` into the jsLayout.

`buildOptions()` = custom options with the flow keys removed
(`withoutFlowOptions()`: `params` and `FLOW_OPTION_KEYS` in the renderer —
methods, wallet rails, pay button, `full_screen`, `autosubmit`, endpoints,
subscription…; one `console.warn` names what was dropped), then the
module's pinned options on top. Adding a key to the flow list is a
behaviour change: record it in `decisions.md`.

For CSS, the embed area has `data-state` = the renderer's `embedState`
(`idle`, `loading`, `ready`, `failed`, `paid`, `blocked`). The widget
container `#shubo-flitt-embed` is hidden once paid. Markup changes still
mean a theme copy of `payment/flitt.html`; keep every binding of the
module's template in it.

## Verify a change

- `phpcs` (Magento2 standard, 0 errors), `phpstan` level 2, and
  `bin/magento setup:di:compile` after clearing `generated/code` and
  `generated/metadata`.
- On a store with the public test merchant (`1549901` / `test`): place an
  embed order (and a redirect one if the change touches it), check the
  order state, invoice, ledger row and `var/log/shubo_flitt.log`; settle
  redirect orders with a synthetic callback (`operations.md`).
- Admin money ops on a test order when the change touches capture, refund
  or void.
- Update this skill when behaviour, a setting, a decision or a known limit
  changes.
