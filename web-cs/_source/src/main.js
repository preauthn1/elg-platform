// The Vue build version to load with the `import` command
// (runtime-only or standalone) has been set in webpack.base.conf with an alias.
import Vue from "vue";
import Vuex from "vuex";
import axios from "axios";
import VueAxios from "vue-axios";
import router from "./router";
import store from "./store";
import VueI18n from "vue-i18n";
import App from "./App";
// GA
import VueAnalytics from "vue-analytics";
import VueAppend from "vue-append";
import "jquery";
import "@/assets/js/jquery-3.4.1.min.js";
import "bootstrap/dist/css/bootstrap.css";
import "bootstrap/dist/js/bootstrap.js";
import BootstrapVue from "bootstrap-vue";
import "bootstrap-vue/dist/bootstrap-vue.css";
import Viewer from "v-viewer";

import BlockUI from "vue-blockui";
// import "@/assets/js/jquery.blockUI.js";
// import "../static/js/jquery.blockUI.min";
Vue.use(Vuex);
Vue.use(VueAxios, axios);
Vue.use(VueI18n);
Vue.use(BootstrapVue);
Vue.use(VueAppend);
Vue.use(BlockUI);
Vue.use(Viewer);

// 聊天室照片
Viewer.setDefaults({
  Options: {
    inline: true, //啟用 inline 模式 默认false
    button: true, //顯示右上角關閉按鈕 默认true
    navbar: true, //顯示縮圖導航 默认true
    title: false, //顯示當前圖片標題 默认true
    toolbar: true, // 顯示工具欄 默认true
    tooltip: true, // 顯示缩放百分比 默认true
    movable: true, //圖片是否可移动 默认true
    zoomable: true, //圖片是否可缩放 默认true
    rotatable: true, //圖片是否可旋轉 默认true
    scalable: true, //圖片是否可翻轉 默认true
    transition: true, //使用css3過度 默认true
    fullscreen: true, //是否全屏 默认true
    keyboard: true, //是否支援鍵盤 默认true
    url: "data-source" //設置大圖片的URL
  }
});
Vue.config.productionTip = false;
Vue.config.devtools = true;
// i18n 語系檔
import en from "./lang/en.json";
import zhTw from "./lang/zh-tw.json";
import zhCn from "./lang/zh-cn.json";
import idId from "./lang/id-ID.json";
import msMy from "./lang/ms-MY.json";
// GA初始化
if (process.env.GA) {
  Vue.use(VueAnalytics, {
    id: process.env.GA, // 从配置中读取
    disableScriptLoader: true, // 必须在html中完成初始化，这里显式禁止去下载ga脚本
    router, // 确保路由切换时可以自动统计
    autoTracking: {
      // pageviewOnLoad: false, // 当通过网址进来时已经GA在初始化时就发起一次pageview的统计，这里不要重复统计
      pageviewTemplate(route) {
        return {
          page: route.path,
          title: document.title,
          location: window.location.href
        };
      }
    }
  });
}
// 語系設定，如果沒有，就用預設
var language = "zh-tw";
if (localStorage.getItem("LOCALE")) {
  language = localStorage.getItem("LOCALE");
} else {
  localStorage.setItem("LOCALE", language);
}

const i18n = new VueI18n({
  locale: localStorage.getItem("LOCALE"),
  messages: {
    en: en,
    "zh-tw": zhTw,
    "zh-cn": zhCn,
    "id-id": idId,
    "ms-my": msMy
  },
  silentTranslationWarn: true
});
/* eslint-disable no-new */
// 全域變數
Vue.prototype.$axios = axios;
Vue.prototype.$LANGUAGE = {
  en: "English",
  "zh-tw": "繁體中文",
  "zh-cn": "简体中文",
  "ms-my": "Melayu",
  "id-id": "Orang indonesia"
};
import Echo from "laravel-echo";

example.invalid = require("example.invalid-client");

window.Echo = new Echo({
  broadcaster: "example.invalid",
  host: process.env.API_URL
  //   host: "https://example.invalid/"
});

// Vue.prototype.$http = axios
// Vue.prototype.$API_URL = 'http://example.invalid/'
// Vue.prototype.$API_MD5_KEY = 'k^QtGkDg7FXJ#@QC'
// Vue.prototype.$API_AES_KEY = 'mu5*rwp5j%3U4kZG'
// Vue.prototype.$API_IV = '9240533016891799'
// axios
//   .get('')
//   .then(res => {
//     console.table(res.data)
//   })
//   .catch(error => {
//     console.error(error)
//   })
export const app = new Vue({
  el: "#app",
  router,
  store,
  i18n,
  components: {
    App
  },
  template: "<App/>"
});
