import Global from '@/Global'
import * as types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'

const state = {
    menu: []
}

const actions = {
    actionMenu({ commit }) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify('')
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/userMenu/list', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    commit(types.GET_MENU, res.data.data)
                } else {
                    alert(res.data.message)
                }
            })
            .catch(error => {
                console.log(error)
            })
        }
    },
}

const mutations = {
    [types.GET_MENU](state, menu) {
        state.menu = menu
        // console.log('menu',state.menu)
    }
}

const getters = {
    getMenu: state => state.menu
}
export default {
    state,
    actions,
    mutations,
    getters,
}