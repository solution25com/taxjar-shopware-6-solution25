import template from './sw-settings-taxjar.html.twig';

const { Component, Mixin } = Shopware;

Component.register('sw-settings-taxjar', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },
    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },
        basicHeaders() {
            const headers = {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Authorization: `Bearer ${Shopware.Service('loginService').getToken()}`
            };
            return headers;
        },
        testConnection(){
            let inputData = this.$refs.systemConfig.actualConfigData[null];
            let apiBasePath = Shopware.Context.api.basePath;
            let url = apiBasePath + "/api/_action/tax-jar/test-connection";
            let token = inputData["solu1TaxJar.setting.liveApiToken"];
            if (inputData["solu1TaxJar.setting.sandboxMode"]) {
                token = inputData["solu1TaxJar.setting.sandboxApiToken"];
            }
            let raw = JSON.stringify({
                "token": token,
                "sandbox": inputData["solu1TaxJar.setting.sandboxMode"],
                "from_country":inputData["solu1TaxJar.setting.shippingFromCountry"],
                "from_zip":inputData["solu1TaxJar.setting.shippingFromZip"],
                "from_state":inputData["solu1TaxJar.setting.shippingFromState"],
                "from_city":inputData["solu1TaxJar.setting.shippingFromCity"],
                "from_street":inputData["solu1TaxJar.setting.shippingFromStreet"],
            });
            let header = {
                method:"POST",
                headers:this.basicHeaders(),
                body: raw,
                redirect: 'follow'
            };
            fetch(url,header) .then(response => response.json())
                .then(repos => {
                try {
                    if(repos.error) {
                        let errorMessage = repos.detail;
                        errorMessage = errorMessage.replace(/from_street/g, 'Shipping From Street')
                            .replace(/from_country/g, 'Shipping From Country')
                            .replace(/from_zip/g, 'Shipping From ZipCode')
                            .replace(/from_state/g, 'Shipping From State Code')
                            .replace(/from_city/g, 'Shipping From City');
                        if (errorMessage.includes('Shipping From State Code')) {
                            errorMessage += '.Please provide valid Shipping From State Code Ex: WI for Wisconsin!'
                        }
                        this.createNotificationError({
                            message: errorMessage
                        });
                    } else {
                        this.createNotificationSuccess({
                            message: this.$tc('sw-settings-taxjar.testConnection.success')
                        });
                    }
                } catch (error) {
                    this.createNotificationError({
                        message: error
                    });
                }
            }).catch((error)=>{
                this.createNotificationError({
                    message: error+'.Please review logs for more detail!'
                });
            });
        },
        validateInput(){
            let inputData = this.$refs.systemConfig.actualConfigData[null];
            let hasError = false;
            if (!inputData["solu1TaxJar.setting.sandboxMode"]) {
                if (!inputData["solu1TaxJar.setting.liveApiToken"]) {
                    this.createNotificationError({
                        message: 'Provide Valid Live API Token'
                    });
                    hasError = true;
                }
            } else {
                if (!inputData["solu1TaxJar.setting.sandboxApiToken"]) {
                    this.createNotificationError({
                        message: 'Provide Valid Sandbox API Token'
                    });
                    hasError = true;
                }
            }

            if (!inputData["solu1TaxJar.setting.defaultProductTaxCode"]) {
                this.createNotificationError({
                    message: 'Provide Valid Product Tax Code'
                });
                hasError = true;
            }
            if (!inputData["solu1TaxJar.setting.shippingFromStreet"]) {
                this.createNotificationError({
                    message: 'Provide Valid Shipping From Street'
                });
                hasError = true;
            }
            if (!inputData["solu1TaxJar.setting.shippingFromCity"]) {
                this.createNotificationError({
                    message: 'Provide Valid Shipping From City'
                });
                hasError = true;
            }
            if (!inputData["solu1TaxJar.setting.shippingFromZip"]) {
                this.createNotificationError({
                    message: 'Provide Valid Shipping From Zip'
                });
                hasError = true;
            }
            if (!inputData["solu1TaxJar.setting.shippingFromState"]) {
                this.createNotificationError({
                    message: 'Provide Valid Shipping From State Code'
                });
                hasError = true;
            } else {
                if (inputData["solu1TaxJar.setting.shippingFromState"].length > 3) {
                    this.createNotificationError({
                        message: 'Provide Valid Shipping From State Code Ex: WI For Wisconsin'
                    });
                    hasError = true;
                }
            }
            if (!inputData["solu1TaxJar.setting.shippingFromCountry"]) {
                this.createNotificationError({
                    message: 'Provide Valid Shipping From Country'
                });
                hasError = true;
            }
            if (hasError) {
               return false;
            }
            return true;
        },
        onSave() {
            this.isSaveSuccessful = false;
            if (!this.validateInput()) {
                this.isLoading = false;
                return;
            }
            this.isLoading = true;
            this.$refs.systemConfig.saveAll().then(() => {
                this.isLoading = false;
                this.isSaveSuccessful = true;
            }).catch((err) => {
                this.isLoading = false;
                this.createNotificationError({
                    message: err,
                });
            });
        },
        onLoadingChanged(loading) {
            this.isLoading = loading;
        }
    },
});
