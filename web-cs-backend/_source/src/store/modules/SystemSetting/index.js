import Global from '@/Global'
import * as types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'

const state = {
    systemSetting: []
}

const actions = {
    // 系統設定詳情
    action_SystemView({ commit }) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify('')
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/setting/view', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    commit(types.GET_SYSTEM_SETTING, res.data.data)
                } else {
                    console.log('系統尚未設定')
                    alert('系統尚未設定')
                }
            })
            .catch(error => {
                console.log(error)
            })
        }
    },
    // 儲存系統設定
    action_SystemSave({ commit },setting) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            if(!setting.roomBGI){
                setting.roomBGI = ''
            }
            let jsonMsg = JSON.stringify({
                "name": setting.roomName,
                "background_image": setting.roomBGI,
                "background_color": setting.roomBGC,
                "header_background_color": setting.headerBGC,
                "header_font_color": setting.headerText,
                "api_url": setting.apiUrl,
            })
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/setting/save', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    alert('儲存成功')
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
    [types.GET_SYSTEM_SETTING](state, systemSetting) {
        state.systemSetting = systemSetting
    },
    roomName(state, roomName) {
        state.systemSetting.name = roomName
    },
    roomBGI(state, roomBGI) {
        state.systemSetting.background_image = roomBGI
    },
    roomBGC(state, roomBGC) {
        state.systemSetting.background_color = roomBGC
    },
    headerBGC(state, headerBGC) {
        state.systemSetting.header_background_color = headerBGC
    },
    headerText(state, headerText) {
        state.systemSetting.header_font_color = headerText
    },
    apiUrl(state, apiUrl) {
        state.systemSetting.api_url = apiUrl
    },
}

const getters = {
    get_SystemSetting: state => state.systemSetting
}
export default {
    state,
    actions,
    mutations,
    getters,
}