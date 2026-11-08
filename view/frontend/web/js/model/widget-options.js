/**
 * Store-side look of the embedded Flitt form — empty here on purpose.
 *
 * A storefront theme overrides this file (Magento's theme fallback:
 * app/design/frontend/<Vendor>/<theme>/Shubo_Flitt/web/js/model/widget-options.js)
 * to pass checkout-vue's presentation keys: `options` (e.g. theme, lang,
 * show_title), `messages` and `css_variable`. The renderer merges them under
 * its own config: `params` (the session token) is ignored and every option key
 * the module sets (methods, methods_disabled, wallet_methods_enabled,
 * full_screen, show_pay_button) stays the module's — the payment flow depends
 * on them.
 */
define([], function () {
    'use strict';

    return {};
});
