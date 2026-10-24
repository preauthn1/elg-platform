import Global from '@/Global'
import * as types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'

const state = {
    report_SR02: []
}

const actions = {
    action_report_SR02({ commit }, data ) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify({
                "username": data.username, 
                "start_date": data.start_time,
                "end_date": data.end_time,
            })
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/report/sr02', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token },
            })
            .then(res => {
                if (res.data.code == 1000) {
                    commit(types.GET_REPORT_SR02, res.data)
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
    [types.GET_REPORT_SR02](state, report) {
        state.report_SR02 = report
    },
}

const getters = {
    get_sr02Report: state => state.report_SR02
}
export default {
    state,
    actions,
    mutations,
    getters,
}