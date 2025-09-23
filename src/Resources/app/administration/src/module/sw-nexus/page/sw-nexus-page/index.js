import template from './sw-nexus-page.html.twig';
// import './sw-nexus-page.scss';

const { Component } = Shopware;

Component.register('sw-nexus-page', {
    template,

    inject: ['nexusApiService'],

    data() {
        return {
            regions: [],
            columns: [
                { property: 'country', label: 'Country' },
                { property: 'country_code', label: 'Country Code' },
                { property: 'region', label: 'Region' },
                { property: 'region_code', label: 'Region Code' },
            ]
        };
    },

    methods: {
        getData() {
            this.nexusApiService.getStates()
                .then(response => {
                    if (response && response.data && response.data.regions) {
                        this.regions = response.data.regions.map((item, index) => ({
                            id: index,
                            ...item
                        }));
                    } else {
                        this.regions = [];
                    }
                })
                .catch(err => {
                    console.error('Error fetching states', err);
                    this.regions = [];
                });
        }
    },

    mounted() {
        this.getData();
    }
});
