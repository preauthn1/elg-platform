// The Vue build version to load with the `import` command
// (runtime-only or standalone) has been set in webpack.base.conf with an alias.
import Vue from 'vue'
import App from './App'
import VueI18n from 'vue-i18n'//語系
import Vuex from 'vuex'
import axios from 'axios'
import VueAxios from 'vue-axios'
import CryptoJS from 'crypto-js'//api加解密
import router from './router'
import store from './store'
import BlockUI from 'vue-blockui'
import ElementUI from 'element-ui';
import 'element-ui/lib/theme-chalk/index.css';
import VueBlu from 'vue-blu'
import 'vue-blu/dist/css/vue-blu.min.css'
Vue.use(VueBlu)
import LangEn from './lang/en'
import LangTw from './lang/zh-tw'
import { state } from './store/mutations';

Vue.config.productionTip = false
Vue.use(VueI18n)
Vue.use(Vuex)
Vue.use(VueAxios,axios,CryptoJS)
Vue.use(ElementUI);
Vue.use(BlockUI);

/* eslint-disable no-new */

const i18n = new VueI18n({
	locale: 'zh-tw', 
	messages:{
		'en': LangEn,
		'zh-tw': LangTw
	},
	silentTranslationWarn: true
})

export const app = new Vue({
	el: '#app',
	router,
	i18n,
	store,
	components: { App },
	template: '<App/>',
	data() {
		return {
		
		}
	},
})
