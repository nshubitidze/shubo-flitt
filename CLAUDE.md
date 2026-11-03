# Shubo_Flitt

Flitt (TBC Bank) payment method for Magento Open Source 2.4.9 (PHP 8.3–8.5,
Luma/Knockout checkout, not Hyvä). Card payments through Flitt's embedded
form (pay first, then the order is placed) or its hosted page (order first),
plus Apple/Google Pay and saved cards.

Every checkout only holds the money (`preauth=Y`); the module captures it on
the server and confirms every money step with Flitt's signed status. Flitt's
callback, the browser return and a reconciler cron settle what the browser
missed. Every Flitt payment session is a row in `shubo_flitt_order`.

Code conventions: @.claude/skills/shubo-flitt-gateway/reference/conventions.md

## Where to read

- `.claude/skills/shubo-flitt-gateway/` — how the module works and how to
  change it: architecture, settings, flows, decisions and why, how-tos,
  operations, troubleshooting. Start with its `SKILL.md`.
- `.claude/skills/flitt-integration-skill/` — Flitt API facts and how the
  API actually behaves. Every Flitt fact comes from here.

## Working rules

- Read the class docblocks and the skill's `decisions.md` before changing an
  area; do not undo a decision as a side effect.
- Never guess Flitt behaviour: if the Flitt skill does not cover it, check
  Flitt's docs (https://docs.flitt.com/) or treat it as an open question.
  Record new observed behaviour in its `reference/live-findings.md` with a
  `[live YYYY-MM-DD]` tag.
- Signed envelopes are the only truth about money; the browser only triggers.
- Never log secrets, rectokens or card data — payloads go through
  `LogSanitizer`.
- Test only with Flitt's public test merchant (`1549901` / `test`). The
  embedded form needs a real hostname (no `localhost`/IP). No credentials in
  this repository.
- A change is done when the gates are green, it is shown working on a store,
  and the module skill describes the new state.
