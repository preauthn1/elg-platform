import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {
    game_list: [],
    game_view: {},
    pagination: [], // 遊戲列分頁
};

const actions = {
    // 遊戲列表
    action_game_list({ commit },data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            page: data.page,
            per_page: data.per_page
        }
        if(data.name){json['name'] = data.name}
        if(data.vendor_id){json['vendor_id'] = data.vendor_id}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/game/list",
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
                    commit(types.GAME_LIST, res.data.data)
                    commit(types.GET_GAME_LIST_PAGE, res.data.pagination)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('game list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 遊戲資料
    action_game_view({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/game/view",
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
                    commit(types.GAME_VIEW, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('game_view: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 遊戲新增
    action_game_add({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            vendor_id: data.vendor_id,
            type_id: data.type_id,
            game_type: data.game_type,
            name: data.name,
            status: data.status,
        }
        if(data.names){json['names'] = data.names}
        if(data.image){json['image'] = data.image}
        if(data.is_new!==null){json['is_new'] = data.is_new}
        if(data.is_hot!==null){json['is_hot'] = data.is_hot}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/game/add",
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
                    alert('game Add: '+ res.data.message)
                    router.push('/game/index')
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('game_add:\n'+ errordata)
                    }else{
                        alert('game_add: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 遊戲編輯
    action_game_edit({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            id: data.id,
            status: data.status,
        }
        if(data.is_new!==null){json['is_new'] = data.is_new}
        if(data.is_hot!==null){json['is_hot'] = data.is_hot}
        if(data.vendor_id){json['vendor_id'] = data.vendor_id}
        if(data.name){json['name'] = data.name}
        if(data.names){json['names'] = data.names}
        if(data.type_id){json['type_id'] = data.type_id}
        if(data.game_type){json['game_type'] = data.game_type}
        if(data.image){json['image'] = data.image}
        
        console.log('json',json)
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/game/edit",
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
                    alert('game Edit: '+ res.data.message)
                    if(window.location.pathname == '/game/index') {
                        router.go(0)
                    }else {
                        router.push('/game/index')
                    }
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('game_edit:\n'+ errordata)
                    }else{
                        alert('game_edit: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 遊戲刪除
    action_game_delete({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id,
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/game/delete",
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
                    alert('game Delete: '+ res.data.message)
                    window.history.go(0)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('game_delete:\n'+ errordata)
                    }else{
                        alert('game_delete: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
};

const mutations = {
    [types.GAME_LIST](state, game_list) {
        state.game_list = game_list
    },
    [types.GAME_VIEW](state, game_view) {
        state.game_view = game_view
    },
    [types.GET_GAME_LIST_PAGE](state, pagination) {
        state.pagination = pagination;
        // console.log( 'gamelist',state.gameList)
    },
};

const getters = {
    getGame_List: state => state.game_list,
    getGame_View: state => state.game_view,
    getGamePagination: state => state.pagination,
};
export default {
    state,
    actions,
    mutations,
    getters
};