# Code conventions

How the module's code is written. New code follows the same rules.

## PHP

- `declare(strict_types=1);` in every file. PHP 8.3+: typed class constants
  (`public const string EMBED = 'embed';`), backed enums for closed value
  sets (`Endpoint`, `ReturnDisposition`), constructor property promotion
  with `private readonly`, trailing commas, one argument per line.
- No magic strings or numbers repeated across code — name them as
  constants. HTTP status codes come from `Laminas\Http\Response`
  (`HttpResponse::STATUS_CODE_200`).
- PHPDoc on every method (`@param`, `@return`, `@throws`) with short class
  names imported by `use`; array shapes like `array<string, mixed>`.
- Named arguments for flags: `getSecretKey($storeId, strict: true)`.
- `static fn` for closures that do not need `$this`.
- Cast raw input (config values, request data, API answers), not what a
  typed API already returns.
- Value objects: small classes with `public readonly` promoted properties
  (`Result`, `ReturnResult`).
- User-facing and exception messages through `__()`, English source,
  Georgian in `i18n/ka_GE.csv`. Exceptions extend `LocalizedException`
  (or Magento's `CommandException` inside gateway commands).
- No `ObjectManager::getInstance()`, no `final` classes (plugins must be able
  to intercept), no superglobals.
- Comments explain **why** (Flitt quirks, Magento traps, invariants), never
  what the next line does; class docblocks state the class's role and
  invariants. Write them for an outside reader.

## Module rules

- `Gateway/Config` is the only code that knows config paths
  (`private const string FIELD_*`, typed getters with `?int $storeId`,
  `strict:` credential getters that throw when unconfigured).
- Money is integer **minor units** + ISO currency at the Flitt boundary;
  `Model/Money` is the one conversion from Magento's decimal totals.
- All HTTP to Flitt goes through `Model/FlittClient`; payloads are built
  outside it (`CheckoutPayloadBuilder`, `RecurringCharge`). The SDK is used
  only through `Gateway/Signature`.
- Every logged payload goes through `Model/LogSanitizer`; the logger is the
  `ShuboFlittLogger` virtual type, bound per class in `etc/di.xml`.
- Ledger states only move forward, enforced in the UPDATE's WHERE
  (`Ledger::transition()`); one `OrderLock` per order around money work.
- Core entities through repositories; payment `additional_information` is
  saved with `OrderPaymentRepository` (`orderRepository->save()` drops it).
- Declarative schema only: `etc/db_schema.xml` (every column commented) +
  `etc/db_schema_whitelist.json`; no `setup_version`.
- Plugins over preferences, observers on real events, area-scoped wiring in
  `etc/frontend/`; a comment says why when something must be global
  (placement runs in `webapi_rest`). Never edit core.
- Every external host the storefront loads is in `etc/csp_whitelist.xml`.
- Every `Magento\<Module>\` / `Magento_<Module>` used is listed in
  `composer.json` and in `etc/module.xml` `<sequence>`.
- Frontend (Luma/Knockout): AMD `define`, `'use strict'`, handlers bound
  once at mount; the browser never decides business state.

## Gates

- `phpcs --standard=Magento2` (0 errors; no new warnings),
  `phpstan` level 2 with `bitexpert/phpstan-magento`, and
  `bin/magento setup:di:compile` after clearing `generated/`.
- The same checks run on every pull request and on `master`
  (`.github/workflows/checks.yml`): `setup:di:compile` in a fresh Magento
  2.4.9 with the module, phpstan from `phpstan.neon.dist` with only the
  packages `composer.json` requires (a missing dependency shows up there),
  and the coding standard (errors fail, warnings are shown).
