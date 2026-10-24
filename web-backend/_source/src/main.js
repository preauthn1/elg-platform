// The Vue build version to load with the `import` command
// (runtime-only or standalone) has been set in webpack.base.conf with an alias.
import Vue from 'vue'
import App from './App'
import router from './router'
import Vuex from 'vuex'
import VueI18n from 'vue-i18n'
import store from "./store";
import axios from "axios";
import VCharts from 'v-charts'

// import $ from 'jquery'
// import "@/assets/js/jquery-3.4.1.min.js";
Vue.use(Vuex)
Vue.use(VueI18n)
Vue.use(VCharts)
Vue.config.productionTip = false


// 語系設定，如果沒有設定過，就用預設
var language = "en";
if (localStorage.getItem("LOCALE")) {
    language = localStorage.getItem("LOCALE");
} else {
    localStorage.setItem("LOCALE", language);
}
// i18n 語系檔
import en from "./lang/en.json";
import zhTw from "./lang/zh-tw.json";
import zhCn from "./lang/zh-cn.json";
import idId from "./lang/id-id.json";
import msMy from "./lang/ms-my.json";
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
new Vue({
    el: '#app',
    router,
    i18n,
    store,
    components: { App },
    template: '<App/>'
})
