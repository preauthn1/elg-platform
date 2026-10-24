import Vue from 'vue'
import Vuex from 'vuex'
import Login_out from './modules/Login_out'
import Menu from './modules/Menu'
import User from './modules/User'
import Member from './modules/Member'
import Transaction from './modules/Transaction'
import Chat from './modules/Chat'
import Chatting from './modules/Chatting'
import Notes from './modules/Notes'
import SystemSetting from './modules/SystemSetting'
import Report from './modules/Report'
import FlowAmount from './modules/FlowAmount'
import BankAccount from './modules/BankAccount'



Vue.use(Vuex)

export default new Vuex.Store({
    modules: {
        Login_out,
        Menu,
        User,
        Member,
        Transaction,
        Chat,
        Chatting,
        Notes,
        SystemSetting,
        Report,
        FlowAmount,
        BankAccount
    },
    strict: true,
})