import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {
    userMenu: [],
    langList: [],
    countryList: []
};

const actions = {
    // 使用者選單列表
    action_userMenu_list({ commit }, user_id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            user_id: user_id
        });
        console.log('userMenu',lang)
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/userMenu/list",
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
                    // console.log('userMenu_list',res.data.data)
                    commit(types.USER_MENU,res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('userMenu_list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 語言列
    action_languages({ commit }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/languages",
                {
                    params: params,
                    key: key
                },
                {
                    headers: { 
                        "Accept-Language": lang
                    },
                }
            )
            .then(function(res) {
                if (res.data.code == 1000) {
                    // console.log('LANGUAGES',res.data.data)
                    commit(types.LANGUAGES,res.data.data)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 國家列表
    action_country({ commit }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/country/list",
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
                    commit(types.COUNTRY,res.data.data)
                    let sessionCountry = JSON.stringify(res.data.data)
                    sessionStorage.setItem('country_list',sessionCountry)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
};

const mutations = {
    [types.USER_MENU](state, menu) {
        state.userMenu = menu
    },
    [types.LANGUAGES](state, lang) {
        state.langList = lang
    },
    [types.COUNTRY](state, country) {
        state.countryList = country
    }
};

const getters = {
    getUserMenu: state => state.userMenu,
    getLanguages: state => state.langList,
    getCountry: state => state.countryList
};
export default {
    state,
    actions,
    mutations,
    getters
};