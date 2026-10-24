import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {
    vendor_list: [],
    vendor_view: {},
    agent_vendor_list: [],
};

const actions = {
    // 遊戲商列表
    action_vendor_list({ commit },status) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            status:status
        }
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/vendor/list",
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
                    commit(types.VENDOR_LIST, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('Vendor list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // vendor view
    action_vendor_view({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/vendor/view",
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
                    commit(types.VENDOR_VIEW, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('vendor_view: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    //vendor edit
    action_vendor_edit({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            id: data.id,
            status: data.status,
        }
        if(data.percentage){json['percentage'] = data.percentage}
        if(data.name){json['name'] = data.name}
        if(data.rate){json['rate'] = data.rate}
        if(data.vendorImg){json['image'] = data.vendorImg}
        if(data.vendorIcon){json['app_icon'] = data.vendorIcon}
        if(data.sort){json['sort'] = data.sort}
        console.log('json',json)
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/vendor/edit",
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
                    alert('vendor Edit: '+ res.data.message)
                    if(window.location.pathname == '/vendor/index') {
                        router.go(0)
                    } else {
                        router.push('/vendor/index')
                    }
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('vendor_edit:\n'+ errordata)
                    }else{
                        alert('vendor_edit: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 第一層代理遊戲商設定列表
    action_agentVendor_list({ commit },id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            vendor_id:id
        }
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agentVendor/list",
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
                    commit(types.AGENT_VENDOR_LIST, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('Vendor list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 代理遊戲商顯示設定
    action_agentVendor_setting({ commit, dispatch }, data) {
            let md5_key = Global.$API_MD5_KEY;
            let lang = localStorage.getItem("LOCALE");
            let token = [REDACTED]"token");
            let idx = 0;
            let json = {
                vendor_id: data[idx].vendor_id,
                agent_id: data[idx].agent_id,
                status: data[idx].vendor_status,
            }
            if(example.invalid){json['info'] = example.invalid}
            let jsonMsg = JSON.stringify(json);
            let params = secret.Encrypt(jsonMsg);
            let key = md5(params + md5_key);
            axios
                .post(Global.$API_URL + "api/agentVendor/setting",
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
                        // alert('agentVendor_setting: '+ res.data.message)
                        // window.history.go(0)
                        const nextDataAry = data.slice(1)
                        nextDataAry.length > 0 && dispatch("action_agentVendor_setting", nextDataAry)
                    } else if (res.data.code > 9997) {
                        window.location.assign("/Login");
                        sessionStorage.clear()
                    } else if (res.data.code !== 1000) {
                        let error = res.data.data
                        if(error){
                            let errordata = error.join("\n")
                            alert('agentVendor_setting:\n'+ errordata)
                        }else{
                            alert('agentVendor_setting: '+ res.data.message)
                        }
                    }
                })
                .catch(function(error) {
                    console.log(error);
                });
    },
};

const mutations = {
    [types.VENDOR_LIST](state, vendor_list) {
        state.vendor_list = vendor_list
    },
    [types.VENDOR_VIEW](state, vendor_view) {
        state.vendor_view = vendor_view
    },
    [types.AGENT_VENDOR_LIST](state, agent_vendor_list) {
        state.agent_vendor_list = agent_vendor_list
    },
};

const getters = {
    getVendor_List: state => state.vendor_list,
    getVendor_View: state => state.vendor_view,
    getAgent_Vendor_List: state => state.agent_vendor_list,
};
export default {
    state,
    actions,
    mutations,
    getters
};