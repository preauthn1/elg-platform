import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {
    agent_list: [],
    agent_pagination: [],
    agent_view: {},
    agent_template_list: [],
    agent_domain_list: []
};

const actions = {
    // 營運商列表
    action_agent_list({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            page: data.page,
            per_page: data.per_page
        }
        if(data.parent) { json['parent']= data.parent}
        if(data.status!==null) { json['status']= data.status}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agent/list",
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
                    commit(types.AGENT_LIST, res.data.data)
                    commit(types.GET_AGENT_LIST_PAGE, res.data.pagination)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('agent list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // agent view
    action_agent_view({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agent/view",
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
                    commit(types.AGENT_VIEW, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('agent view: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // agent add
    action_agent_add({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let json = {
            username: data.username,
            email: data.email,
            password: [REDACTED],
            password_confirmation: data.password_confirmation,
            country_id: data.country_id,
            status: data.status,
            has_pc: data.has_pc, 
            has_mobile: data.has_mobile, 
            has_chat: data.has_chat, 
            has_online_payment: data.has_online_payment,
            has_app: data.has_app,
            frontend_register: data.frontend_register,
            transaction_all: data.transaction_all,
            agent_backend_transaction: data.agent_backend_transaction
        }
        if(data.nickname){json['nickname'] = data.nickname} 
        if(data.city && data.city!==''){json['city'] = data.city} 
        if(data.address && data.address!=='') {json['address'] = data.address} 
        if(data.mobile && data.mobile!=='') {json['mobile'] = data.mobile} 
        if(data.percentage && data.percentage!=='') {json['percentage'] = data.percentage} 
        
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agent/add",
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
                    alert('Agent Add: '+ res.data.message)
                    router.push('/agent/index')
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('agent_add:\n'+ errordata)
                    }else{
                        alert('agent_add: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // agent edit
    action_agent_edit({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: data.id,
            nickname: data.nickname,
            email: data.email,
            password: [REDACTED],
            password_confirmation: data.password_confirmation,
            country_id: data.country_id,
            city: data.city,
            address: data.address,
            mobile: data.mobile,
            percentage: data.percentage,
            status: data.status,
            has_pc: data.has_pc,
            has_mobile: data.has_mobile,
            has_chat: data.has_chat,
            has_online_payment: data.has_online_payment,
            has_app: data.has_app,
            frontend_register: data.frontend_register,
            transaction_all: data.transaction_all,
            agent_backend_transaction: data.agent_backend_transaction,
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agent/edit",
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
                    alert('Agent Edit: '+ res.data.message)
                    
                    if(window.location.pathname == '/agent/index') { //已在'/agent/index'編輯 則重整頁面就好(for index開啟狀態用)
                        router.go(0)
                    } else { //若不在'/agent/index'編輯 則導'/agent/index'
                        router.push('/agent/index')
                    }
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('agent_Edit:\n'+ errordata)
                    }else{
                        alert('agent_Edit: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 營運商停用 
    action_agent_delete({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id,
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(Global.$API_URL + "api/agent/delete",
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
                    alert('Agent Delete: '+ res.data.message)
                    window.history.go(0)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('Agent delete:\n'+ errordata)
                    }else{
                        alert('Agent delete: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 營運商模板列表
    action_agentTemplate_list({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            agent_id: data.agent_id,
            page: data.page,
            per_page: data.per_page
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agentTemplate/list",
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
                    commit(types.AGENT_TEMPLATE_LIST, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('agent template list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 營運商模板開關
    action_agentTemplate_setting({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            agent_id: data.agent_id,
            template_id: data.template_id,
            status: data.status
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agentTemplate/setting",
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
                    alert('Template setting: '+ res.data.message)
                    store.dispatch('action_agentTemplate_list',
                        {agent_id:data.agent_id, page:1, per_page:100}
                    )
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('Template setting: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 域名列表
    action_agentDomain_list({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            agent_id: data.agent_id,
            domain: data.domain,
            template_id: data.template_id,
            status: data.status,
            page: data.page,
            per_page: data.per_page,
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agentDomain/list",
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
                    commit(types.AGENT_DOMAIN_LIST, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('Domain list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 新增域名
    action_agentDomain_Add({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            agent_id: data.agent_id,
            domain: data.domain,
            status: data.status,
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agentDomain/add",
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
                    alert('Add Domain: '+ res.data.message)
                    store.dispatch('action_agentDomain_list',{
                        agent_id: data.agent_id,
                        page:1,
                        per_page:50
                    })
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('Add Domain:\n'+ errordata)
                    }else{
                        alert('Add Domain: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 編輯域名
    action_agentDomain_Edit({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: data.id,
            agent_id: data.agent_id,
            domain: data.domain,
            status: data.status,
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agentDomain/edit",
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
                    alert('Edit Domain: '+ res.data.message)
                    store.dispatch('action_agentDomain_list',{
                        agent_id: data.agent_id,
                        page:1,
                        per_page:50
                    })
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('Add Domain:\n'+ errordata)
                    }else{
                        alert('Add Domain: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 刪除域名
    action_agentDomain_Delete({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: data.id,
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/agentDomain/delete",
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
                    alert('Delete Domain: '+ res.data.message)
                    store.dispatch('action_agentDomain_list',{
                        agent_id: data.agent_id,
                        page:1,
                        per_page:50
                    })
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('Delete Domain:\n'+ errordata)
                    }else{
                        alert('Delete Domain: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
};

const mutations = {
    [types.AGENT_LIST](state, agent_list) {
        state.agent_list = agent_list
    },
    [types.GET_AGENT_LIST_PAGE](state, pagination) {
        state.agent_pagination = pagination;
    },
    [types.AGENT_VIEW](state, agent_view) {
        state.agent_view = agent_view
    },
    AGENT_VIEW_CLEAR(state) {
        state.agent_view = {
            country_id: 4,
            status: 0,
            has_pc: 0,
            has_mobile: 0,
            has_chat: 0,
            has_online_payment: 0,
            has_app: 0,
            frontend_register: 0,
            transaction_all: 0,
            agent_backend_transaction: 0,
        }
    },
    [types.AGENT_TEMPLATE_LIST](state, template_list) {
        state.agent_template_list = template_list
    },
    [types.AGENT_DOMAIN_LIST](state, domain_list) {
        state.agent_domain_list = domain_list
    }
};

const getters = {
    getAgent_List: state => state.agent_list,
    getAgent_Pagination: state => state.agent_pagination,
    getAgent_View: state => state.agent_view,
    getAgent_Template_List: state => state.agent_template_list,
    getAgent_Domain_List: state => state.agent_domain_list
};
export default {
    state,
    actions,
    mutations,
    getters
};