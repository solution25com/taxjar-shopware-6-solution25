// src/Resources/app/administration/src/module/sw-nexus-module/index.js

const { Module } = Shopware;
import './page/sw-nexus-page';
import enGB from './snippet/en-GB';
import deDE from './snippet/de-DE';


Module.register('sw-nexus-module', {
    type: 'plugin',
    title: 'sw-nexus-module.general.mainMenuItemList',
    description: 'sw-nexus-module.general.descriptionTextModule',
    snippets: {
        'en-GB': enGB,
        'de-De': deDE
    },

    routes: {
        list: {
            component: 'sw-nexus-page',
            path: 'list',
            meta: {
                parentPath: 'sw.settings.index'
            }
        }
    },

    settingsItem: [
        {
            name: 'sw-nexus-module-menu',
            label: 'sw-nexus-module.general.mainMenuItemList',
            to: 'sw.nexus.module.list',
            group: 'plugins',
            icon: 'regular-cog'
        }
    ],

    navigation: [
        {
            id: 'sw-nexus-module',
            label: 'sw-nexus-module.general.mainMenuItemList',
            color: '#ff68b4',
            icon: 'regular-cog',
            path: 'sw.nexus.module.list',
            position: 100,
            parent: 'sw-order',
            privilege: 'order.viewer',
        }
    ]
});
