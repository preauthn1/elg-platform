import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {
    currency_list: [],
    currency_view: {},
};

const actions = {
    // 貨幣列表
    action_currency_list({ commit }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/currency/list",
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
                    commit(types.CURRENCY_LIST,res.data.data)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // currency view
    action_currency_view({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/currency/view",
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
                    commit(types.CURRENCY_VIEW, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('currency_view: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // currency add
    action_currency_add({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            code: data.code,
            name: data.name,
            rate: data.rate,
            status: data.status,
        }
        if(data.names.length>0){json['names'] = data.names}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/currency/add",
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
                    alert('currency Add: '+ res.data.message)
                    window.history.go(0)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('currency_add:\n'+ errordata)
                    }else{
                        alert('currency_add: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // currency edit
    action_currency_edit({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            id: data.id,
            status: data.status,
        }
        console.log('data',data)
        if(data.code){json['code'] = data.code}
        if(data.rate){json['rate'] = data.rate}
        if(data.name){json['name'] = data.name}
        if(data.names){json['names'] = data.names}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/currency/edit",
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
                    alert('currency Edit: '+ res.data.message)
                    window.history.go(0)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('currency_edit:\n'+ errordata)
                    }else{
                        alert('currency_edit: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // currency delete
    action_currency_delete({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id,
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/currency/delete",
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
                    alert('currency Delete: '+ res.data.message)
                    window.history.go(0)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('currency_delete:\n'+ errordata)
                    }else{
                        alert('currency_delete: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
};

const mutations = {
    [types.CURRENCY_LIST](state, currency_list) {
        state.currency_list = currency_list
    },
    [types.CURRENCY_VIEW](state, currency_view) {
        state.currency_view = currency_view
    },
    clearCurrency_View(state){
        state.currency_view = {}
    }
};

const getters = {
    getCurrency_List: state => state.currency_list,
    getCurrency_View: state => state.currency_view
};
export default {
    state,
    actions,
    mutations,
    getters
};