/**
 * Flitt checkout-vue embed SDK.
 *
 * /latest/ is the ONLY published channel — the bundle hardcodes its webpack
 * publicPath to pay.flitt.com/latest/checkout-vue/, so vendoring/pinning is
 * impossible; note the bundle commithash when testing a change against it.
 * Loaded lazily by the method renderer,
 * never as a define() dependency, so customers only download it when the
 * Flitt method actually mounts.
 *
 * ⚠ checkout.js assigns window.checkout unconditionally — the same global
 * Magento's minicart.phtml sets (read by sidebar.js/catalog widgets). No
 * conflict stock: the Luma checkout page renders no minicart and nothing
 * there reads window.checkout, and the SDK never loads on pages that do.
 * But a project that customizes a minicart ONTO the checkout page will get
 * its window.checkout clobbered once the embed mounts.
 */
var config = {
    paths: {
        flittCheckout: 'https://pay.flitt.com/latest/checkout-vue/checkout'
    },
    shim: {
        flittCheckout: {
            exports: 'checkout'
        }
    }
};
