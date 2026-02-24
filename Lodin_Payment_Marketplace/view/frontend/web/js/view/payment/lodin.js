define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'lodin',
                component: 'Lodin_Payment/js/view/payment/method-renderer/lodin'
            }
        );
        return Component.extend({});
    }
);
