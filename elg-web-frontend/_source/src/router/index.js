import Vue from 'vue'
import Router from 'vue-router'
import Home from '@/view/home'
import Main from '@/components/main'
import signUp from '@/view/signUp'
import game from '@/components/game'
import gamelist from '@/components/gamelist'
import points from '@/components/points'
import acct from '@/components/acct'
import record from '@/components/record'
import validate from '@/components/validate'
import point_success from '@/components/point_success'
import resetPassword from '@/components/resetPassword'
import events from '@/components/events'
import search from '@/components/search'
import deposit from '@/components/deposit'

Vue.use(Router)

export default new Router({
  mode: 'history',
  routes: [
    {
      path: '/',
      component: Home,
      children: [
        {
          path: '/',
          component: Main
        },
        {
          path: '/gamelist',
          component: gamelist
        },
        {
          path: '/signup',
          component: signUp
        },
        {
          path: '/signup/:code',
          component: signUp
        },
        {
          path: '/game',
          component: game
        },
        {
          path: '/points',
          component: points
        },
        {
          path: '/acct',
          component: acct
        },
        {
          path: '/record',
          component: record
        },
        {
          path: '/events',
          component: events
        },
        {
          path: '/validate',
          component: validate
        },
        {
          path: '/point_success',
          component: point_success
        },
        {
          path: 'resetPassword',
          component: resetPassword
        },
        {
          path: '/search',
          component: search
        },
        {
          path: '/deposit',
          component: deposit
        },
        
      ]
    }
  ]
})
