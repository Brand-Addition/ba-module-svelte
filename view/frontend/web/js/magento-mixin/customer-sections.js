define([
    'Magento_Customer/js/customer-data'
], function (customerData) {
    'use strict';

    return function (Component) {
        return Component.extend({
            initialize: function () {
                this._super();

                window.addEventListener('svelte:customer-sections-updated', (event) => {
                    const { sectionNames = [], sections = {} } = event.detail || {};

                    if (!Array.isArray(sectionNames) || sectionNames.length === 0) {
                        return;
                    }

                    const normalizedSectionNames = sectionNames.filter((sectionName) => {
                        return typeof sectionName === 'string' && sectionName.trim() !== '';
                    });

                    normalizedSectionNames.forEach((sectionName) => {
                        if (!Object.prototype.hasOwnProperty.call(sections, sectionName)) {
                            return;
                        }

                        if (typeof customerData.set === 'function') {
                            customerData.set(sectionName, sections[sectionName]);
                        }
                    });
                });

                return this;
            }
        });
    };
});
