/**
 * Stored-card renderer. The core Magento_Vault base renderer is an
 * abstract shell — its getToken()/getMaskedCard()/getExpirationDate() return
 * empty strings, so without this extension the radio label renders blank and
 * placement posts an EMPTY public_hash (HTTP 400). The token facts arrive in
 * this component's config from Shubo\Flitt\Model\Ui\TokenUiComponentProvider
 * (publicHash + the token's details JSON) and the charge itself is entirely
 * server-side (vault_sale / vault_authorize commands).
 */
define([
    'Magento_Vault/js/view/payment/method-renderer/vault'
], function (VaultComponent) {
    'use strict';

    return VaultComponent.extend({
        defaults: {
            template: 'Magento_Vault/payment/form'
        },

        /**
         * @returns {String} Last 4 digits of the stored card.
         */
        getMaskedCard: function () {
            return this.details.maskedCC || '';
        },

        /**
         * @returns {String} The TOKEN's expiry (Flitt never discloses the card's own).
         */
        getExpirationDate: function () {
            return this.details.expirationDate || '';
        },

        /**
         * @returns {String} Magento cc type code driving the scheme icon.
         */
        getCardType: function () {
            return this.details.type || '';
        },

        /**
         * @returns {String} The public hash placement charges by.
         */
        getToken: function () {
            return this.publicHash;
        }
    });
});
