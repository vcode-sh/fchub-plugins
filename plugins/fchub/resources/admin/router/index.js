import { createRouter, createWebHashHistory } from 'vue-router'
import OverviewPage from '../pages/OverviewPage.vue'
import ProductsPage from '../pages/ProductsPage.vue'
import SystemPage from '../pages/SystemPage.vue'

// Three screens. There is no fourth, and there is no settings page — FCHub has
// nothing to configure, which is the nicest thing anyone will say about it.
const routes = [
  { path: '/', name: 'overview', component: OverviewPage, meta: { title: 'Overview' } },
  { path: '/products', name: 'products', component: ProductsPage, meta: { title: 'Products' } },
  { path: '/system', name: 'system', component: SystemPage, meta: { title: 'System' } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export default createRouter({
  history: createWebHashHistory(),
  routes,
})
