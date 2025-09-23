// src/Resources/app/administration/src/core/service/api/nexus-api.services.js

const { Application } = Shopware;
const ApiService = Shopware.Classes.ApiService;

class NexusApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'nexus') {
        super(httpClient, loginService, apiEndpoint);
    }
    getStates() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/states`, {
                headers: this.getBasicHeaders()
            })
            .then(response => ApiService.handleResponse(response));
    }
}

Application.addServiceProvider('nexusApiService', (container) => {
    const initContainer = Application.getContainer('init');

    return new NexusApiService(
        initContainer.httpClient,
        container.loginService
    );
});

export default NexusApiService;
