import template from './sw-order-detail-base.html.twig';

Shopware.Component.override('sw-order-detail-base', {
    template,
    methods: {
        getTaxRate(taxAmount){
            return parseFloat((taxAmount*100)/this.order.amountNet).toFixed(2);
        }
    }
});