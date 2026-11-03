/**
 * Registers the Flitt method renderer.
 *
 * The push MUST stay at module top level: Magento_Checkout's list.js reads
 * renderer-list only while creating renderers and never subscribes to it —
 * a push from inside a component method would silently render nothing.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'shubo_flitt',
        component: 'Shubo_Flitt/js/view/payment/method-renderer/flitt'
    });

    return Component.extend({});
});
