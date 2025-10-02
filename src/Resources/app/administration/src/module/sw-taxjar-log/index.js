const { Module } = Shopware;
import './page/tax-jar-log';

Module.register('sw-tax-log-module', {
    type: 'plugin',
    title: 'sw-tax-log-module.general.mainMenuItemList',
    description: 'sw-tax-log-module.general.descriptionTextModule',

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
