import Vue from 'vue'
import Vuex from 'vuex'
import axios from 'axios'
// import VueAxios from 'vue-axios'

import Menu from './modules/menu'
import List from './modules/List'
import HomeGames from './modules/homeGames'
import Vendors from './modules/vendors'
import Login from './modules/login'
import Logout from './modules/logout'
import Signup from './modules/signup'
import Password from './modules/password'
import Global from './modules/Global'
import Deposit from './modules/deposit'
import Points from './modules/points'
import Playgame from './modules/playgame'
import RecordList from './modules/recordList'
import Setting from './modules/setting'
import Events from './modules/events'
import GameApp from './modules/gameApp'
import Agent from './modules/agent'
import post from './modules/post'
Vue.use(Vuex)
// Vue.use(axios)
export default new Vuex.Store({
    modules: {
        Menu,
        List,
        HomeGames,
        Vendors,
        Login,
        Logout,
        Signup,
        Password,
        Global,
        Deposit,
        Points,
        Playgame,
        RecordList,
        Setting,
        Events,
        GameApp,
        Agent,
        post,
    },

    // 嚴格模式，禁止直接修改 state
    strict: true,
})
