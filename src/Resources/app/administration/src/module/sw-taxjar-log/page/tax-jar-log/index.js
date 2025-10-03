const {Component, Mixin} = Shopware;
const {Criteria} = Shopware.Data;

import template from './tax-jar-log-list.html.twig';

Component.register('sw-tax-log-module-list', {
    template,

    inject: [
        'repositoryFactory',
        'filterFactory'
    ],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('listing'),
        Mixin.getByName('placeholder')
    ],

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    data() {
        return {
            total: 0,
            page: 1,
            limit: 30,
            taxJarLogCollection: null,
            storeKey: 'grid.filter.tax.log',
            repository: null,
            isLoading: false,
            processSuccess: false,
            defaultFilters: [
                'tax-log-date-filter',
                'tax-log-type-filter',
                'tax-log-order-number-filter',
                'tax-log-order-id-filter',
                'tax-log-customer-name-filter',
                'tax-log-customer-email-filter',
                'tax-log-customer-ip-filter',
                'tax-log-request-filter',
                'tax-log-response-filter'
            ],
            activeFilterNumber: 0,
            filterCriteria: [],
        };
    },

    created() {
    },

    computed: {
        getRepository() {
            return this.repositoryFactory.create('s25_taxjar_log');
        },

        getColumns() {
            return this.getColumnsList();
        },

        listFilters() {
            return this.filterFactory.create('s25_taxjar_log', {
                'tax-log-date-filter': {
                    property: 'createdAt',
                    dateType: 'datetime-local',
                    label: 'Creation Time',
                    placeholder: 'Creation Time',
                    showTimeframe: true
                },
                'tax-log-type-filter': {
                    property: 'type',
                    label: 'Request Type',
                    type: 'text-filter',
                    placeholder: 'Request Type',
                },
                'tax-log-order-number-filter': {
                    property: 'orderNumber',
                    label: 'Order Number',
                    type: 'text-filter',
                    placeholder: 'Order Number',
                },
                'tax-log-order-id-filter': {
                    property: 'orderId',
                    label: 'Order Id',
                    type: 'text-filter',
                    placeholder: 'Order Id',
                },
                'tax-log-customer-name-filter': {
                    property: 'customerName',
                    label: 'Customer Name',
                    type: 'text-filter',
                    placeholder: 'Customer Name',
                },
                'tax-log-customer-email-filter': {
                    property: 'customerEmail',
                    label: 'Customer Email',
                    type: 'text-filter',
                    placeholder: 'Customer Email',
                },
                'tax-log-customer-ip-filter': {
                    property: 'remoteIp',
                    label: 'Customer IP',
                    type: 'text-filter',
                    placeholder: 'Customer IP',
                },
                'tax-log-request-filter': {
                    property: 'request',
                    label: 'Request',
                    type: 'text-filter',
                    placeholder: 'Request',
                },
                'tax-log-response-filter': {
                    property: 'response',
                    type: 'text-filter',
                    label: 'Response',
                    placeholder: 'Response',
                }
            });
        },

        defaultCriteria() {
            const defaultCriteria = new Criteria(this.page, this.limit);
            defaultCriteria.setTerm(this.term);
            defaultCriteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            this.filterCriteria.forEach(filter => {
                defaultCriteria.addFilter(filter);
            });
            return defaultCriteria;
        },
    },

    watch: {
        defaultCriteria: {
            handler() {
                this.getList();
            },
            deep: true,
        },
    },

    methods: {
        basicHeaders() {
            const headers = {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Authorization: `Bearer ${Shopware.Service('loginService').getToken()}`
            };
            return headers;
        },
        exportLog(){

            let header = {
                method:"GET",
                headers:this.basicHeaders(),
                redirect: 'follow'
            };
            let apiBasePath = Shopware.Context.api.basePath;
            let url = apiBasePath + "/api/_action/tax-jar/export-log";
            fetch(url,header)
                .then(response => response.json())
                .then(repos => {
                    let csv = 'Customer Name,Customer Email,Customer IP,Order Number,Order Id,Request,Response,Creation Time\n';
                    repos.forEach((item) => {
                        csv += '"'+item.customerName+'",';
                        csv += '"'+item.customerEmail+'",';
                        csv += '"'+item.remoteIp+'",';
                        csv += '"'+item.orderNumber+'",';
                        csv += '"'+item.orderId+'",';
                        csv += '"'+item.request+'",';
                        csv += '"'+item.response+'",';
                        csv += item.createdAt;
                        csv += "\n";
                    });
                    const anchor = document.createElement('a');
                    anchor.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
                    anchor.target = '_blank';
                    anchor.download = 'ExportTaxJarLog.csv';
                    anchor.click();
                }).catch((error)=>{
                this.createNotificationError({
                    message: error+'.Please review logs for more detail!'
                });
            });
        },
        async getList() {
            this.isLoading = true;
            const criteria = await Shopware.Service('filterService')
                .mergeWithStoredFilters(this.storeKey, this.defaultCriteria);
            this.activeFilterNumber = criteria.filters.length;
            try {
                const items = await this.getRepository.search(this.defaultCriteria);
                this.total = items.total;
                this.taxJarLogCollection = items;
                this.isLoading = false;
                this.selection = {};
            } catch {
                this.isLoading = false;
            }
        },
        updateCriteria(criteria) {
            this.page = 1;
            this.filterCriteria = criteria;
        },
        getColumnsList() {
            return [
                {
                    property: 'createdAt',
                    dataIndex: 'createdAt',
                    label: 'Creation Time',
                    allowResize: true,
                    sortable: true,
                },
                {
                    property: 'type',
                    dataIndex: 'type',
                    label: this.$t('sw-tax-log-module.list.requestType'),
                    allowResize: true,
                    sortable: true,
                },
                {
                    property: 'orderNumber',
                    dataIndex: 'orderNumber',
                    label: this.$t('sw-tax-log-module.list.orderNumber'),
                    allowResize: true,
                    sortable: true,
                },
                {
                    property: 'orderId',
                    dataIndex: 'orderId',
                    label: this.$t('sw-tax-log-module.list.orderId'),
                    allowResize: true,
                    sortable: true,
                },
                {
                    property: 'customerName',
                    dataIndex: 'customerName',
                    label: this.$t('sw-tax-log-module.list.customerName'),
                    allowResize: true,
                    sortable: true,
                },
                {
                    property: 'customerEmail',
                    dataIndex: 'customerEmail',
                    label: this.$t('sw-tax-log-module.list.customerEmail'),
                    allowResize: true,
                    sortable: true,
                },
                {
                    property: 'remoteIp',
                    dataIndex: 'remoteIp',
                    label: this.$t('sw-tax-log-module.list.remoteIp'),
                    allowResize: true,
                    sortable: true,
                },
                {
                    property: 'request',
                    dataIndex: 'request',
                    label: this.$t('sw-tax-log-module.list.titleColumn'),
                    allowResize: true,
                    sortable: true,
                },
                {
                    property: 'response',
                    dataIndex: 'response',
                    label: this.$t('sw-tax-log-module.list.descColumn'),
                    allowResize: true,
                    sortable: true,
                }
            ];
        }
    }
});
