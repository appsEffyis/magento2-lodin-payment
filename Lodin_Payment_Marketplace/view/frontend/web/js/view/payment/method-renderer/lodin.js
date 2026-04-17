define(
    [
        'Magento_Checkout/js/view/payment/default'
    ],
    function (Component) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Lodin_Payment/payment/lodin',
                redirectAfterPlaceOrder: false
            },

            /**
             * Get payment method code
             */
            getCode: function() {
                return 'lodin';
            },

            /**
             * Check if payment is active
             */
            isActive: function() {
                return true;
            },

            /**
             * After place order callback
             */
            afterPlaceOrder: function () {
                window.location.replace(this.getRedirectUrl());
            },

            /**
             * Get redirect URL
             */
            getRedirectUrl: function() {
                return window.BASE_URL + 'lodin/payment/redirect';
            }
        });
    }
);
