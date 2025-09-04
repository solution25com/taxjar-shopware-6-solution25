import './page/sw-settings-taxjar';

const { Module } = Shopware;

Module.register('sw-settings-taxjar', {
    type: 'core',
    name: 'settings-taxjar',
    title: 'Tax service provider settings',
    description: 'Tax service provider settings',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#9AA8B5',
    icon: 'regular-cog',
    favicon: 'icon-module-settings.png',

    routes: {
        index: {
            component: 'sw-settings-taxjar',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'system.system_config',
            },
        },
    },

    settingsItem: {
        group: 'shop',
        to: 'sw.settings.taxjar.index',
        icon: 'regular-briefcase',
        privilege: 'system.system_config',
    },
});
