import { createRouter, createWebHistory } from 'vue-router'

/**
 * Pages from plan section 7.7. Routes for phases not yet built are added with
 * their phase, so the nav never links to a screen that does not exist.
 */
export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
    { path: '/chat', name: 'chat', component: () => import('@/views/ChatView.vue') },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue') },
  ],
})
