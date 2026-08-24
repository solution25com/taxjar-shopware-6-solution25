import './app/component/filter/sw-text-filter/';
import './sw-filter-panel-override/';
import './sw-order-detail-base-override/';
import './sw-settings-tax-detail-content-override/';
import './module/sw-settings-tax-provider/component/sw-tax-provider-card/';
import './module/sw-settings-taxjar/';
import './module/sw-taxjar-log/';
import './module/sw-nexus/index';
import NexusApiService from './module/sw-nexus/page/sw-nexus-page/services/NexusApiService'
import './module/sw-nexus/scss/sv-nexus-page.scss'

Shopware.Service('nexusApiService').register('nexusApiService', NexusApiService);