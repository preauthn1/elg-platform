import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {
    gameType_list: [],
    gameType_view: {}
};

const actions = {
    action_gameType_list({ commit },status) {
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
                Global.$API_URL + "api/gameType/list",
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
                    commit(types.GAME_TYPE_LIST, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('gameType list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // gameType view
    action_gameType_view({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/gameType/view",
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
                    commit(types.GAME_TYPE_VIEW, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('gameType_view: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // gameType add
    action_gameType_add({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            parent: data.parent,
            name: data.name,
            status: data.status,
        }
        if(data.names.length>0){json['names'] = data.names}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/gameType/add",
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
                    alert('gameType Add: '+ res.data.message)
                    window.history.go(0)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('gameType_add:\n'+ errordata)
                    }else{
                        alert('gameType_add: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // gameType edit
    action_gameType_edit({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            id: data.id,
            status: data.status,
        }
        if(data.parent){json['parent'] = data.parent}
        if(data.name){json['name'] = data.name}
        if(data.names){json['names'] = data.names}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/gameType/edit",
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
                    alert('gameType Edit: '+ res.data.message)
                    window.history.go(0)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('gameType_edit:\n'+ errordata)
                    }else{
                        alert('gameType_edit: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // gameType delete
    action_gameType_delete({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id,
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/gameType/delete",
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
                    alert('gameType Delete: '+ res.data.message)
                    window.history.go(0)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('gameType_delete:\n'+ errordata)
                    }else{
                        alert('gameType_delete: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
};

const mutations = {
    [types.GAME_TYPE_LIST](state, gameType_list) {
        state.gameType_list = gameType_list
    },
    [types.GAME_TYPE_VIEW](state, gameType_view) {
        state.gameType_view = gameType_view
    },
    clearGAME_TYPE_View(state){
        state.gameType_view = {}
    }
};

const getters = {
    getGameType_List: state => state.gameType_list,
    getGameType_View: state => state.gameType_view,
};
export default {
    state,
    actions,
    mutations,
    getters
};