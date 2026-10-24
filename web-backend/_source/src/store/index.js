import Vue from 'vue'
import Vuex from 'vuex'
Vue.use(Vuex)

import LogInOut from './modules/LogInOut'
import List from './modules/List'
import User from './modules/User'
import Agent from './modules/Agent'
import Report from './modules/Report'
import AdvertisementPath from './modules/AdvertisementPath'
import Advertisement from './modules/Advertisement'
import Game from './modules/Game'
import GameType from './modules/GameType'
import Vendor from './modules/Vendor'
import Country from './modules/Country'
import Currency from './modules/Currency'
import Template from './modules/Template'
import Event from './modules/Event'
import Order from './modules/Order'
export default new Vuex.Store({
    modules: {
        LogInOut,
        List,
        User,
        Agent,
        Report,
        AdvertisementPath,
        Advertisement,
        Game,
        GameType,
        Vendor,
        Country,
        Currency,
        Template,
        Event,
        Order,
    },

    // 嚴格模式，禁止直接修改 state
    strict: true,
})