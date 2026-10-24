import Vue from "vue";
import Global from "@/Global";
// import * as types from '../mutations_type'
import store from "@/store";
import secret from "@/utils/secret";
import axios from "axios";
import VueAxios from "vue-axios";
import md5 from "js-md5";

// Vue.use(axios)

const state = {
    nickname: ""
    // agentuser: sessionStorage.getItem('CS_API_USER'),
    // agentkey: sessionStorage.getItem('CS_API_KEY'),
};
// 聊天室
const actions = {
    //call api

    post({ commit }, data) {
        var sign_data = JSON.parse(sessionStorage.getItem("chatMember"));

        let lang = localStorage.getItem("LOCALE");
        let md5_key = Global.$CHATAPI_MD5_KEY;
        let iv = Global.$CHATAPI_IV;
        let aes_key = Global.$CHATAPI_AES_KEY;
        let api_user = sign_data.agentuser;
        let api_key = [REDACTED];
        let third_party_id = sign_data.thirdpartyId;
        let timestamp = Date.now();
        let postValue = {
            API_USER: api_user,
            API_KEY: [REDACTED],
            TIMESTAMP: timestamp,
            THIRD_PARTY_ID: third_party_id
        };

        $.each(example.invalid, function(index, value) {
            postValue[index] = value;
        });

        let jsonMsg = JSON.stringify(postValue);

        let params = secret.ChEncrypt(jsonMsg);

        let key = md5(params + md5_key);
        let sign = md5(
            api_user +
                md5_key +
                api_key +
                iv +
                timestamp +
                aes_key +
                third_party_id
        );

        return axios
            .post(
                Global.$CHATAPI_URL + data.url,
                {
                    params: params,
                    key: key
                },
                {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Signature " + sign
                    }
                }
            )
            .catch(error => {
                console.log("error", error);
            });
    }
};
const mutations = {};

const getters = {};
export default {
    state,
    actions,
    mutations,
    getters
};
