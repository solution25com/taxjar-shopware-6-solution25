import template from './sw-tax-service-provider.html.twig';

Shopware.Component.override('sw-settings-tax-detail', {
    template,

    methods: {
        onChangeDefaultTaxRate() {
            const taxId = this.tax?.id || this.taxId;
            const newDefaultTax = !this.isDefaultTaxRate ? taxId : '';

            this.config['core.tax.defaultTaxRate'] = newDefaultTax;
            this.changeDefaultTaxRate = false;
        },
    },
});

