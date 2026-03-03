import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: 'EndpointList',
    component: () => import('../pages/EndpointList.vue'),
  },
  {
    path: '/endpoints/new',
    name: 'EndpointNew',
    component: () => import('../pages/EndpointEditor.vue'),
    props: { isNew: true },
  },
  {
    path: '/endpoints/:id/edit',
    name: 'EndpointEdit',
    component: () => import('../pages/EndpointEditor.vue'),
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

export default router
