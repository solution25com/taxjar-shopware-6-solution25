import template from './sw-nexus-page.html.twig';
// import './sw-nexus-page.scss';

const {Component} = Shopware;

Component.register('sw-nexus-page', {
    template,

    inject: ['nexusApiService'],

    data() {
        return {
            isLoading: false,
            regions: [],
            columns: [
                {property: 'country', label: 'Country'},
                {property: 'country_code', label: 'Country Code'},
                {property: 'region', label: 'Region'},
                {property: 'region_code', label: 'Region Code'},
            ]
        };
    },

    methods: {
        async getData() {
            this.isLoading = true;
            try {
                const response = await this.nexusApiService.getStates()
                if (!response || !response.data || !response.data.regions) {
                    this.regions = [];
                    this.isLoading = false;
                    return
                }
                this.regions = response.data.regions.map((item, index) => ({
                    id: index,
                    ...item
                }));
                this.isLoading = false;


            } catch (err) {
                this.regions = [];
                this.isLoading = false;
                const confitText = 'check your configs';
                console.log(confitText)
            }

        }
    },

    mounted() {
        this.getData();
    }
});
