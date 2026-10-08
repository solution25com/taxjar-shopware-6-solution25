const { Application } = Shopware;
const ApiService = Shopware.Classes.ApiService;

export default class TaxJarRefundApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/taxjar/order') {
        super(httpClient, loginService, apiEndpoint);
    }

    getRefunds(orderId) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/${orderId}/refunds`, {
                headers: this.getBasicHeaders(),
            })
            .then(response => ApiService.handleResponse(response));
    }

    getRefundResponse(orderId, transactionId) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/${orderId}/refunds/response`, {
                params: { transactionId },
                headers: this.getBasicHeaders(),
            })
            .then(response => ApiService.handleResponse(response));
    }

    sendRefunds(orderId) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/${orderId}/refunds/send`, {}, {
                headers: this.getBasicHeaders(),
            })
            .then(response => ApiService.handleResponse(response));
    }
}

Application.addServiceProvider('taxJarRefundApiService', (container) => {
    const initContainer = Application.getContainer('init');

    return new TaxJarRefundApiService(
        initContainer.httpClient,
        container.loginService,
    );
});
