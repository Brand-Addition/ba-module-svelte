define([
    'Magento_Customer/js/customer-data'
], function (customerData) {
    'use strict';

    return function (Component) {
        return Component.extend({
            initialize: function () {
                this._super();

                console.log('BA_Svelte/js/magento-mixin/messages initialized');
                window.addEventListener('svelte:message', (event) => {
                    console.log('svelte:message event received', event);
                    const { type, text } = event.detail || {};

                    if (!type || !text) {
                        console.warn('svelte:message event missing type or text', event);
                        return;
                    }

                    const messages = customerData.get('messages')() || {
                        messages: []
                    };

                    messages.messages = messages.messages || [];

                    messages.messages.push({
                        type: type,
                        text: text
                    });

                    console.log('Updating messages', messages);
                    customerData.set('messages', messages);
                });

                return this;
            }
        });
    };
});