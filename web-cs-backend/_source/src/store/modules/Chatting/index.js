import Global from '@/Global'
import * as types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'

const state = {
    
}

const actions = {
    action_sendMessage({ commit }, data) { //發送訊息
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify({
                "code": data.code, "type":data.type, "content": data.content
            })
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/sendMessage', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    // console.log('sendMessage',res.data)
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
    
}

const getters = {
    
}
export default {
    state,
    actions,
    mutations,
    getters,
}