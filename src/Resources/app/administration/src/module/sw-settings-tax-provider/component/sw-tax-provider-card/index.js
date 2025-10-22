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
            return new Criteria();
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        changeTaxProvider(id) {
            this.taxProviderRepository.get(id, Context.api).then((item) => {
                this.currentTaxProvider = item;
                if (this.currentTaxProvider) {
                    this.taxExtension = this.taxMappingRepository.create(Shopware.Context.api);
                    if (this.tax.extensions.taxExtension) {
                        this.taxExtension.taxId = this.tax.id;
                        this.taxExtension.providerId = this.currentTaxProvider.id;
                        this.taxMappingRepository.delete(this.tax.extensions.taxExtension.id).then(() => {});
                        this.taxMappingRepository.save(this.taxExtension).then(() => {});
                    } else {
                        this.taxExtension.taxId = this.tax.id;
                        this.taxExtension.providerId = this.currentTaxProvider.id;
                        this.taxMappingRepository.save(this.taxExtension).then(() => {});
                    }
                } else {
                    this.taxMappingRepository.delete(this.tax.extensions.taxExtension.id,  Context.api).then(() => {});
                }
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
