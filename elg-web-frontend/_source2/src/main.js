// The Vue build version to load with the `import` command
// (runtime-only or standalone) has been set in webpack.base.conf with an alias.
import Vue from "vue";
import App from "./App";
import router from "./router";
import axios from "axios";
import Vuex from "vuex";
import store from "./store";
import VueI18n from "vue-i18n";
import BlockUI from "vue-blockui";

// GA
import VueAnalytics from "vue-analytics";
import "@/assets/js/jquery-3.4.1.min.js";
import BootstrapVue from "bootstrap-vue";
import vueEaser from "vue-eraser"; // 刮刮卡
import EXIF from "exif-js"; //圖片上傳被翻轉用
import "@/assets/js/jquery.min.js";
import "@/assets/js/jquery.cookie.js";
import "bootstrap/dist/css/bootstrap.min.css";
import "bootstrap/dist/js/bootstrap.js";
import "bootstrap/dist/js/bootstrap.bundle.min.js";
import "bootstrap-vue/dist/bootstrap-vue.css";
import "font-awesome/css/font-awesome.css";
// 手機不休眠
// import './assets/js/NoSleep.min.js'
import "../static/js/NoSleep.min.js";
// 刮刮卡
import "./assets/js/jquery.eraser.js";
// 輪盤
// import VLottery from "./components/v_world/Lottery2";
import VLottery from "./components/Lottery2";
// 日期選擇
import "vue-datepicker-local/dist/vue-datepicker-local.js";
import "vue-datepicker-local/dist/vue-datepicker-local.css";
// veeValidate (多語未設)
import VeeValidate, {
    Validator
} from "vee-validate";
import tw from "vee-validate/dist/locale/zh_TW";

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

// Validator.addLocale(zh)
const config = {
    locale: localStorage.getItem("LOCALE") == "zh-tw" ?
        "tw" : localStorage.getItem("LOCALE"),
    events: "input|blur",
    dictionary: {
        tw,
        en
    }
};
Vue.use(VeeValidate, config);

// i18n 語系檔
import en from "./lang/en.json";
import zhTw from "./lang/zh-tw.json";
import zhCn from "./lang/zh-cn.json";
import idId from "./lang/id-ID.json";
import msMy from "./lang/ms-MY.json";
axios.defaults.baseURL = "https://example.invalid";

// init
Vue.use(BlockUI);
Vue.use(BootstrapVue);
Vue.use(VueI18n, axios, Vuex);
Vue.use(EXIF);
// Vue.use(VueCookies)
// eslint-disable-next-line no-undef
Vue.component("v-lottery", VLottery);
Vue.component("vue-eraser", vueEaser);

// 語系設定，如果沒有設定過，就用預設
var language = "en";
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

Vue.config.productionTip = false;

// 全域變數
Vue.prototype.$http = axios;
Vue.prototype.$API_MD5_KEY = "&zSMvVTK4gbDvCcU";
Vue.prototype.$API_AES_KEY = "M53B?xKnTVtVnjM^";
Vue.prototype.$API_IV = "3579653693526433";
Vue.prototype.$LANGUAGE = {
    en: "English",
    "zh-tw": "繁體中文"
};

/* eslint-disable no-new */
export const app = new Vue({
    el: "#app",
    router,
    i18n,
    store,

    components: {
        App
    },
    template: "<App/>"
});






import Global from "@/Global";
import secret from "@/utils/secret";
import enums from "@/utils/enums";
import formatHelper from "@/utils/format_utils";
import md5 from "js-md5";


Vue.prototype.$enums = enums;


Vue.filter('ToMoneyDisplayFormat', function (value) {

    // 加上千分位符號
    return formatHelper.To_Money_Format(value);

})



Vue.filter('ToEnumLocalizationText', function (value, enumName) {

    let defaultUnknowText = i18n.t("ErrorMsg_Unknow_Enums");
    let targetEnumObject = enums[enumName];

    if (targetEnumObject == null) {
        return defaultUnknowText
    }

    let find = Object.keys(targetEnumObject).find(key => targetEnumObject[key] === value);

    if (find == null) {
        return defaultUnknowText;
    }

    var i18Key = 'enums.' + enumName + '["' + find + '"]';
    var text = i18n.t(i18Key);

    if (text == i18Key) {
        return defaultUnknowText;
    }

    return text;
})
