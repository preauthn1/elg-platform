import Vue from 'vue'
import Router from 'vue-router'
import paths from './paths'
import NProgress from 'nprogress'
import 'nprogress/nprogress.css'

Vue.use(Router)
const router = new Router({
  base: '/',
  //   mode: 'hash',
  mode: 'history',
  linkActiveClass: 'active',
  routes: paths,
})
// router gards
router.beforeEach((to, from, next) => {
  NProgress.start()
  // 驗證是否有登入

  var isLogin = sessionStorage.getItem('user_token')

  if (!isLogin) {
    // 排除不需登入的頁面
    if (to.path !== '/login' && to.path.substring(0, 10) !== '/WebLogin/') {
      isLogin ? next() : next('/login')
    } else {
      next()
    }
  } else {
    // console.log('isLogin true')
    next()
  }
})

router.afterEach((to, from) => {
  // ...
  NProgress.done()
})

export default router
