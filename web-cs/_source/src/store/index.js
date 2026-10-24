import Vue from 'vue'
import Vuex from 'vuex'
import axios from 'axios'
import VueAxios from 'vue-axios'

import post from './modules/post'

Vue.use(Vuex)
Vue.use(VueAxios, axios)
export default new Vuex.Store({
  modules: {
    post,
  },

  // 嚴格模式，禁止直接修改 state
  strict: true,
})
