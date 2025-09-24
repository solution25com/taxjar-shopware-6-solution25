// src/Resources/app/administration/src/module/sw-nexus-module/index.js

const { Module } = Shopware;
import './page/sw-nexus-page';

Module.register('sw-nexus-module', {
    type: 'plugin',
    title: 'sw-nexus-module.general.mainMenuItemList',
    description: 'sw-nexus-module.general.descriptionTextModule',
    routes: {
        list: {
            component: 'sw-nexus-page',
            path: 'list',
            meta: {
                parentPath: 'sw.settings.index'
            }
        }
    },

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
