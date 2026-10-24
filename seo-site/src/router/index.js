import Vue from 'vue'
import VueRouter from 'vue-router'
import HomeView from '../views/HomeView.vue'

Vue.use(VueRouter)

const routes = [
  {
    path: '/',
    component: HomeView
  },
  {
    path: '/favorite',
    component: () => import('../views/FavoriteView.vue')
  },
  {
    path: '/game-type/:gameTypeId',
    component: () => import('../views/GameTypeView.vue')
  },
  {
    path: '/game/:gameId',
    component: () => import('../views/GameView.vue')
  },
]

const router = new VueRouter({
  mode: 'history',
  base: process.env.BASE_URL,
  routes
})

export default router
