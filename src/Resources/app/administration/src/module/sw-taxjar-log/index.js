const { Module } = Shopware;
import './page/tax-jar-log'; // Component registered for module which will used for showing data

import enGB from './snippet/en-GB';
import deDE from './snippet/de-DE';

Module.register('sw-tax-log-module', {
    type: 'plugin',
    title: 'sw-tax-log-module.general.mainMenuItemList',
    description: 'sw-tax-log-module.general.descriptionTextModule',
    snippets: {
        'en-GB': enGB,
        'de-De': deDE
    },

    routes: {
        'list': {
            component: 'sw-tax-log-module-list',
            path: 'list',
            meta: {
                parentPath: 'sw.settings.index'
            }
        }
    },

    settingsItem: [
        {
            name: 'sw-tax-log-module-menu',
            label: 'sw-tax-log-module.general.mainMenuItemList',
            to: 'sw.tax.log.module.list',
            group: 'plugins',
            icon: 'regular-cog'
        }
    ],
    navigation: [{
        id: 'sw-tax-jar-log',
        label: 'sw-tax-log-module.general.mainMenuItemList',
        color: '#ff68b4',
        icon: 'regular-cog',
        path: 'sw.tax.log.module.list',
        position: 100,
        parent: 'sw-order',
        privilege: 'order.viewer',
    }]
});
