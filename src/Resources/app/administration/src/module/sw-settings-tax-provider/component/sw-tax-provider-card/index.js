import template from './sw-tax-provider-card.html.twig';
import './sw-tax-provider-card.scss';
const { Component, Context } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sw-tax-provider-card', {
    template,
    inject: ['repositoryFactory'],
    props: {
        tax: {
            type: Object,
            required: true,
        }
    },
    data() {
        return {
            taxProvider: null,
            currentTaxProvider: null,
        };
    },
    computed: {
        taxRepository() {
            return this.repositoryFactory.create('tax');
        },
        taxProviderRepository() {
            return this.repositoryFactory.create('s25_tax_service_provider');
        },
        taxMappingRepository() {
            return this.repositoryFactory.create('s25_tax_provider');
        },
        taxProviderCriteria() {
            const criteria = new Criteria();
            return criteria;
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        changeTaxProvider(id) {
            const mapping = this.tax.extensions.taxExtension;

            if (!id) {
                this.currentTaxProvider = null;
                if (mapping?.id) {
                    this.taxMappingRepository.delete(mapping.id, Context.api).then(() => {
                        this.tax.extensions.taxExtension = null;
                    });
                }

                return;
            }

            this.taxProviderRepository.get(id, Context.api).then((item) => {
                this.currentTaxProvider = item;
                if (!item) {
                    return;
                }

                const taxExtension = this.taxMappingRepository.create(Context.api);
                taxExtension.taxId = this.tax.id;
                taxExtension.providerId = item.id;

                const removeCurrent = mapping?.id
                    ? this.taxMappingRepository.delete(mapping.id, Context.api)
                    : Promise.resolve();

                removeCurrent
                    .then(() => this.taxMappingRepository.save(taxExtension, Context.api))
                    .then(() => {
                        this.tax.extensions.taxExtension = taxExtension;
                    });
            });
        },
        createdComponent() {
            if (this.currentTaxProvider) {
                this.taxProvider = this.currentTaxProvider;
                if (this.taxProvider.id) {
                    this.changeTaxProvider(this.taxProvider.id);
                }
            } else {
                this.taxProvider = this.taxProviderRepository.create();
                this.taxProvider.taxId = this.tax.id;
                if (this.tax.extensions.taxExtension) {
                    this.taxProvider.id = this.tax.extensions.taxExtension.providerId
                }

            }
        }
    },
});
