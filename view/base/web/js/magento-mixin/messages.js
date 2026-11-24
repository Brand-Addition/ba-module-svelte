define([
    'Magento_Customer/js/customer-data'
], function (customerData) {
    'use strict';

    return function (Component) {
        return Component.extend({
            initialize: function () {
                this._super();

                window.addEventListener('svelte:message', (event) => {
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

                    customerData.set('messages', messages);
                });

                window.addEventListener('svelte:messages:clear', () => {
                    customerData.set('messages', {
                        messages: []
                    });
                });

                return this;
            }
        });
    };
});
