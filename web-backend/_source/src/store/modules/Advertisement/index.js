import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {
    ad_list: [],
    ad_view: {}
};

const actions = {
    // 廣告列表
    action_advertisement_list({ commit, dispatch }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let idx = 0
        // json為必填data，
        // if(data.item){json['item']=data.item}，為選填如果有值才加入json物件內
        let json = {
            start_time: data.start_time,
            end_time: data.end_time,
        }
        if(data.path_id) { // 1.data.path_id為廣告id陣列ex:[1,3,4,6]
            json['path_id']= data.path_id[idx] // 2.api為個別單一id勞回資料，設idx為0，一次抓第一個id取廣告
        } 
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/advertisement/list",
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
                    commit(types.ADVERTISEMENT_LIST, res.data.data)

                    // 3.一次成功後slice去掉path_id陣列第一項為新陣列nextDataAry:[3,4,6]
                    let nextDataAry = data.path_id.slice(1) 
                    let nextData = { // 4.重組下次data
                        start_time: data.start_time,
                        end_time: data.end_time,
                        path_id: nextDataAry
                    }
                    // 5.新陣列nextDataAry若減為長度0 則不再執行撈列表
                    nextDataAry.length > 0 && dispatch("action_advertisement_list", nextData)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('advertisement list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 廣告明細
    action_advertisement_view({ commit }, id) {
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
                Global.$API_URL + "api/advertisement/view",
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
                    commit(types.ADVERTISEMENT_VIEW, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('advertisement view: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 新增廣告
    action_advertisement_add({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        // 物件json為必填data，
        // if(data.item){json['item']=data.item}，為選填如果有值才加入json物件內
        let json = {
            path_id: data.path_id,
            language_id: data.language_id,
            start_time: data.start_time,
            end_time: data.end_time,
        }
        if(data.country_id !== '[]'){json['country_id'] = data.country_id}
        if(data.agent_id!== '[]'){json['agent_id'] = data.agent_id}
        if(data.game_id){json['game_id'] = data.game_id}
        if(data.game_type_id){json['game_type_id'] = data.game_type_id}
        if(data.vendor_id){json['vendor_id'] = data.vendor_id}
        if(data.image){json['image'] = data.image}
        if(data.image_pc){json['image_pc'] = data.image_pc}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/advertisement/add",
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
                    alert('advertisement Add: '+ res.data.message)
                    router.push('/advertisement/index')
                } else if (res.data.code > 9998) {
                    alert(res.data.message)
                    // window.location.assign("/Login");
                    // sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('advertisement add:\n'+ errordata)
                    }else{
                        alert('advertisement add: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
    // 編輯廣告
    action_advertisement_edit({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        // 物件json為必填data，
        // if(data.item){json['item']=data.item}，為選填如果有值才加入json物件內
        let json = {
            id: data.id,
        }
        if(data.path_id){json['path_id'] = data.path_id}
        if(data.start_time){json['start_time'] = data.start_time}
        if(data.end_time){json['end_time'] = data.end_time}
        if(data.language_id){json['language_id'] = data.language_id}
        if(data.country_id !== '[]'){json['country_id'] = data.country_id}
        if(data.agent_id!== '[]'){json['agent_id'] = data.agent_id}
        if(data.game_id){json['game_id'] = data.game_id}
        if(data.game_type_id){json['game_type_id'] = data.game_type_id}
        if(data.vendor_id){json['vendor_id'] = data.vendor_id}
        if(data.image){json['image'] = data.image}
        if(data.image_pc){json['image_pc'] = data.image_pc}
        let jsonMsg = JSON.stringify(json);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/advertisement/edit",
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
                    alert('advertisement edit: '+ res.data.message)
                    router.push('/advertisement/index')
                } else if (res.data.code > 9998) {
                    alert(res.data.message)
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    let error = res.data.data
                    if(error){
                        let errordata = error.join("\n")
                        alert('advertisement edit:\n'+ errordata)
                    }else{
                        alert('advertisement edit: '+ res.data.message)
                    }
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
};

const mutations = {
    [types.ADVERTISEMENT_LIST](state, ad_list) {
        state.ad_list.push(ad_list[0])
    },
    clear_ad_list(state) {
        state.ad_list = []
    },
    [types.ADVERTISEMENT_VIEW](state, ad_view) {
        state.ad_view = ad_view
    },
};

const getters = {
    getAd_list: state => state.ad_list,
    getAd_view: state => state.ad_view
};
export default {
    state,
    actions,
    mutations,
    getters
};