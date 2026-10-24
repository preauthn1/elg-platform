// The Vue build version to load with the `import` command
// (runtime-only or standalone) has been set in webpack.base.conf with an alias.
import Vue from 'vue'
import App from './App'
import Vuetify from 'vuetify'
import Vuex from 'vuex'
import axios from 'axios'
import VueI18n from 'vue-i18n'
import store from './store'
import router from './router'
import 'babel-polyfill' // 低本版支持es6 promise
import VueAnalytics from 'vue-analytics'
import 'font-awesome/css/font-awesome.css'
import "./assets/css/style.css";
import './theme/default.styl'
import VeeValidate from 'vee-validate'
import colors from 'vuetify/es5/util/colors'
import Truncate from 'lodash.truncate'
import VueClipboard from "vue-clipboard2";

Vue.config.productionTip = false
// Helpers
// Global filters
Vue.filter('truncate', Truncate)

Vue.use(VueClipboard)


// VeeValidate 語系設定
import tw from './../node_modules/vee-validate/dist/locale/zh_TW'
import cn from './../node_modules/vee-validate/dist/locale/zh_CN'
import en from './../node_modules/vee-validate/dist/locale/en'
import id from './../node_modules/vee-validate/dist/locale/id'
import msmy from './../node_modules/vee-validate/dist/locale/ms_MY'


const validateConfig = {
  locale: localStorage.getItem('LOCALE') == 'zh-tw' ?
    'tw' : localStorage.getItem('LOCALE') == 'zh-cn' ?
    'cn' : localStorage.getItem('LOCALE') == 'en' ?
    'en' : localStorage.getItem('LOCALE') == 'id' ?
    'id' : 'msmy',
  events: 'input|blur',
  dictionary: {
    tw,
    en,
    cn,
    id,
    msmy
  },
}
Vue.use(VeeValidate, validateConfig, {
  fieldsBagName: 'formFields'
})

Vue.use(Vuex, axios)
Vue.use(VueI18n)
Vue.use(Vuetify, {
  // theme: {
  //   primary: colors.indigo.base, // #E53935
  //   secondary: colors.indigo.lighten4, // #FFCDD2
  //   accent: colors.indigo.base // #3F51B5
  // },
  options: {
    themeVariations: ['primary', 'secondary', 'accent'],
    extra: {
      mainToolbar: {
        color: 'primary',
      },
      sideToolbar: {},
      sideNav: 'primary',
      mainNav: 'primary lighten-1',
      bodyBg: '',
    },
  },
})
// Bootstrap application components

// 聊天室監聽系統: laravel-echo、example.invalid //
import Echo from 'laravel-echo'
example.invalid = require('example.invalid-client')
window.Echo = new Echo({
  broadcaster: 'example.invalid',
  host: process.env.WS_URL,
})

// 語系檔
import En from './lang/en.json'
import zhTw from './lang/zh-tw.json'
import zhCn from './lang/zh-cn.json'
import Id from './lang/id.json'
import msMy from './lang/ms-my.json'
import formatHelper from "./util/format_utils";

// 語系設定，如果沒有設定過，就用預設
var language = 'en'
if (localStorage.getItem('LOCALE')) {
  language = localStorage.getItem('LOCALE')
} else {
  localStorage.setItem('LOCALE', language)
}
const i18n = new VueI18n({
  locale: localStorage.getItem('LOCALE'),
  messages: {
    en: En,
    'zh-tw': zhTw,
    'zh-cn': zhCn,
    id: Id,
    'ms-my': msMy,
  },
  silentTranslationWarn: true,
})

// GA
if (process.env.GA) {
  Vue.use(VueAnalytics, {
    id: process.env.GA,
    disableScriptLoader: true,
    router,
    autoTracking: {
      pageviewOnload: false,
    },
  })
}

/* eslint-disable no-new */
new Vue({
  el: '#app',
  router,
  store,
  i18n,
  components: {
    App
  },
  template: '<App/>',
})


Vue.filter('ToMoneyDisplayFormat', function (value) {

  // 加上千分位符號
  return formatHelper.To_Money_Format(value);

})


// AXIOS 攔截
// axios.interceptors.request.use(
//     function(config) {
//         console.log('REQ_config',config)
//         return config
//     },
//     function(error) {
//         console.log('REQ_error',error)
//         return Promise.reject(error)
//     }
//   )
//   axios.interceptors.response.use(
//     function(response) {
//         console.log('RES_config',response)
//         return response
//     },
//     function(error) {
//         console.log('RES_error',error)
//         return Promise.reject(error)
//     }
//   )