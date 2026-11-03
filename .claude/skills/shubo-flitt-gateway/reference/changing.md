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
