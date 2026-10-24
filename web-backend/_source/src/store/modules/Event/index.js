import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {
    event_list: [],
    event_view: {},
    prizeType_list: [],
    prizeType_view: {},
    eventType_list: [],
    eventAction_list: []
};

const actions = {
    // 活動列表
    action_event_list({ commit },data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            page: data.page,
            per_page: data.per_page
        }
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/event/list",
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
                    commit(types.EVENT_LIST, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('event list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 活動資料
    action_event_view({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/event/view",
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
                    commit(types.EVENT_VIEW, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('event_view: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 活動新增
    action_event_add({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            type_id: data.type_id,
            title: data.title,
            content: data.content,
            details: data.details,
            prize_type_id: data.prize_type_id,
            prize_name: data.prize_name,
            prize_background: data.prize_background,
            prize_number: data.prize_number,
            prize_is_repeatedly: data.prize_is_repeatedly,
            prize_expire_date: data.prize_expire_date,
            prizes: data.prizes,
            assign: data.assign
        }
        if(data.languages){json['languages'] = data.languages}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/event/add",
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
                    alert('event Add: '+ res.data.message)
                    // window.history.go(0)
                    router.push('/event/index')
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('event_add:\n'+ errordata)
                    }else{
                        alert('event_add: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 活動編輯
    action_event_edit({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            id: data.id,
            details: data.details,
            prize_is_repeatedly: data.prize_is_repeatedly,
            assign: data.assign
        }
        if(data.type_id){json['type_id'] = data.type_id}
        if(data.title){json['title'] = data.title}
        if(data.languages){json['languages'] = data.languages}
        if(data.content){json['content'] = data.content}
        if(data.prize_type_id){json['prize_type_id'] = data.prize_type_id}
        if(data.prize_name){json['prize_name'] = data.prize_name}
        if(data.prize_background){json['prize_background'] = data.prize_background}
        if(data.prize_number){json['prize_number'] = data.prize_number}
        if(data.prize_expire_date){json['prize_expire_date'] = data.prize_expire_date}
        if(data.prizes){json['prizes'] = data.prizes}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/event/edit",
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
                    alert('event Edit: '+ res.data.message)
                    // window.history.go(0)
                    router.push('/event/index')
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('event_edit:\n'+ errordata)
                    }else{
                        alert('event_edit: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },

    // 獎勵類型列表
    action_prizeType_list({ commit },status) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            status: status
        }
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/prizeType/list",
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
                    commit(types.PRIZE_TYPE_LIST, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('prizeType list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 獎勵類型資料
    action_prizeType_view({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/prizeType/view",
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
                    commit(types.PRIZE_TYPE_VIEW, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('prizeType_view: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 獎勵類型新增
    action_prizeType_add({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            name: data.name,
            status: status,
        }
        if(data.names){json['names'] = data.names}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/prizeType/add",
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
                    alert('prizeType Add: '+ res.data.message)
                    window.history.go(0)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('prizeType_add:\n'+ errordata)
                    }else{
                        alert('prizeType_add: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 獎勵類型編輯
    action_prizeType_edit({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            id: data.id,
        }
        if(data.name){json['name'] = data.name}
        if(data.names){json['names'] = data.names}
        if(data.status!==null){json['status'] = data.status}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/prizeType/edit",
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
                    alert('prizeType Edit: '+ res.data.message)
                    window.history.go(0)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('prizeType_edit:\n'+ errordata)
                    }else{
                        alert('prizeType_edit: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },

    // 條件類型
    action_param_list({ commit, dispatch },data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let idx = 0;
        let json = {
            key: data[idx]
        }
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/param/list",
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
                    if(data[idx] == 'event_type') { // 活動step3 獎勵方式
                        commit(types.EVENT_TYPE_LIST, res.data.data)
                    }else if(data[idx] == 'event_action') { //活動step2 選擇活動方式
                        commit(types.EVENT_ACTION_LIST, res.data.data)
                    }
                    const nextDataAry = data.slice(1)
                    nextDataAry.length > 0 && dispatch("action_param_list", nextDataAry)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('param list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
};

const mutations = {
    [types.EVENT_LIST](state, event_list) {
        state.event_list = event_list
    },
    [types.EVENT_VIEW](state, event_view) {
        state.event_view = event_view
    },
    [types.PRIZE_TYPE_LIST](state, prizeType_list) {
        state.prizeType_list = prizeType_list
    },
    [types.PRIZE_TYPE_VIEW](state, prizeType_view) {
        state.prizeType_view = prizeType_view
    },
    [types.EVENT_TYPE_LIST](state, eventType_list) {
        state.eventType_list = eventType_list
    },
    [types.EVENT_ACTION_LIST](state, eventAction_list) {
        state.eventAction_list = eventAction_list
    },
    
};

const getters = {
    getEvent_list: state => state.event_list,
    getEvent_view: state => state.event_view,
    getPrizeType_list: state => state.prizeType_list,
    getPrizeType_view: state => state.prizeType_view,
    getEventType_list: state => state.eventType_list,
    getEventAction_list: state => state.eventAction_list,
};
export default {
    state,
    actions,
    mutations,
    getters
};