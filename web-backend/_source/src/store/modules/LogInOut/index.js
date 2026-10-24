import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {};

const actions = {
    action_Login({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let jsonMsg = JSON.stringify({
            username: data.username,
            password: [REDACTED]
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/login",
                {
                    params: params,
                    key: key
                },
                {
                    headers: { "Accept-Language": lang }
                }
            )
            .then(function(res) {
                if (res.data.code == 1000) {
                    sessionStorage.setItem("user_id", res.data.data.id);
                    sessionStorage.setItem("username", res.data.data.username);
                    sessionStorage.setItem("token", res.data.data.token);
                    router.push("/index")
                } else if (res.data.code !== 1000) {
                    alert('Login: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    action_Logout({ commit }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/logout",
                {
                    params: params,
                    key: key
                },
                {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer " + token
                    }
                }
            )
            .then(res => {
                window.location.assign("/Login");
                window.sessionStorage.clear();
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
