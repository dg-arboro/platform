import { Module } from 'src/core/shopware';
import './page/sw-catalog-list';
import './page/sw-catalog-detail';
import './page/sw-catalog-create';

Module.register('sw-catalog', {
    type: 'core',
    name: 'Catalogues',
    description: 'The module for managing catalogues.',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#FF85C2',
    icon: 'default-package-closed',

    routes: {
        index: {
            component: 'sw-catalog-list',
            path: 'index'
        },

        detail: {
            component: 'sw-catalog-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'sw.catalog.index'
            }
        },

        create: {
            component: 'sw-catalog-create',
            path: 'create',
            meta: {
                parentPath: 'sw.catalog.index'
            }
        }
    },

    navigation: [{
        path: 'sw.catalog.index',
        label: 'sw-catalog.general.mainMenuItemIndex',
        color: '#FF85C2',
        icon: 'default-package-closed'
    }, {
        path: 'sw.catalog.create',
        parent: 'sw.catalog.index',
        label: 'sw-catalog.general.mainMenuItemCreate',
        color: '#FF85C2'
    }]
});
