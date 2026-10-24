import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {
    numberOfPeople: {}
};

const actions = {
    // 營運商下線/會員人數
    action_numberOfPeople({ commit }, agent_id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            agent_id: agent_id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/report/numberOfPeople",
                {
                    params: params,
                    key: key
                },
                {
                    headers: { 
                        "Accept-Language": lang,
                        Authorization: "Bearer " + token
                    },
                }
            )
            .then(function(res) {
                if (res.data.code == 1000) {
                    commit(types.NUMBER_OF_PEOPLE, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('Number Of People: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    
};

const mutations = {
    [types.NUMBER_OF_PEOPLE](state, numberOfPeople) {
        state.numberOfPeople = numberOfPeople
    }
};

const getters = {
    getNumber_of_People: state => state.numberOfPeople
};
export default {
    state,
    actions,
    mutations,
    getters
};