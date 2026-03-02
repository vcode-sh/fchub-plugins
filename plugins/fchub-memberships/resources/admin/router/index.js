import { createRouter, createWebHashHistory } from 'vue-router';

const routes = [
    {
        path: '/',
        name: 'Dashboard',
        component: () => import('../pages/Dashboard.vue'),
    },
    {
        path: '/plans',
        name: 'PlanList',
        component: () => import('../pages/Plans/PlanList.vue'),
    },
    {
        path: '/plans/new',
        name: 'PlanNew',
        component: () => import('../pages/Plans/PlanEditor.vue'),
        props: { isNew: true },
    },
    {
        path: '/plans/:id/edit',
        name: 'PlanEdit',
        component: () => import('../pages/Plans/PlanEditor.vue'),
    },
    {
        path: '/members',
        name: 'MemberList',
        component: () => import('../pages/Members/MemberList.vue'),
    },
    {
        path: '/members/:id',
        name: 'MemberProfile',
        component: () => import('../pages/Members/MemberProfile.vue'),
    },
    {
        path: '/import',
        name: 'Import',
        component: () => import('../pages/Import/ImportWizard.vue'),
    },
    {
        path: '/content',
        name: 'ContentOverview',
        component: () => import('../pages/Content/ContentOverview.vue'),
    },
    {
        path: '/drip',
        name: 'DripOverview',
        component: () => import('../pages/Drip/DripOverview.vue'),
    },
    {
        path: '/drip/calendar',
        name: 'DripCalendar',
        component: () => import('../pages/Drip/DripCalendar.vue'),
    },
    {
        path: '/reports',
        name: 'Reports',
        component: () => import('../pages/Reports/Reports.vue'),
    },
    {
        path: '/settings',
        name: 'Settings',
        component: () => import('../pages/Settings.vue'),
    },
];

const router = createRouter({
    history: createWebHashHistory(),
    routes,
});

export default router;
