import template from './sw-nexus-page.html.twig';
// import './sw-nexus-page.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-nexus-page', {
    template,

    inject: ['nexusApiService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            regions: [],
            errorMessage: '',
            columns: [
                { property: 'country', label: 'Country' },
                { property: 'country_code', label: 'Country Code' },
                { property: 'region', label: 'Region' },
                { property: 'region_code', label: 'Region Code' },
            ]
        };
    },

    methods: {
        errorMessage: undefined,
        async getData() {
            this.isLoading = true;
            this.errorMessage = '';

            try {
                const response = await this.nexusApiService.getStates();

                if (!response?.data?.regions) {
                    this.regions = [];
                    this.errorMessage = 'No Nexus regions received. Please verify your TaxJar connection.';
                    this.createNotificationError({
                        title: 'Nexus Error',
                        message: this.errorMessage
                    });
                    return;
                }

                this.regions = response.data.regions.map((item, index) => ({
                    id: index,
                    ...item
                }));
            } catch (err) {
                this.regions = [];
                this.errorMessage = 'Failed to load Nexus regions. Please check your API credentials or configuration.';
                this.createNotificationError({
                    title: 'Nexus Error',
                    message: this.errorMessage
                });
                console.error('[Nexus API Error]:', err);
            } finally {
                this.isLoading = false;
            }
        }
    },

    mounted() {
        this.getData();
    }
});
