import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import router from "@/router"

const state = {
    orderType_list: [],
    order_list: [],
    order_view: {},
};

const actions = {
    // 儲值方式列表
    action_orderType_list({ commit },data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify("");
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/orderType/list",
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
                    commit(types.ORDER_TYPE_LIST, res.data.data)
                } else if (res.data.code > 9997) {
                    window.location.assign("/Login");
                    sessionStorage.clear()
                } else if (res.data.code !== 1000) {
                    alert('orderType list: '+ res.data.message)
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    },
};

const mutations = {
    [types.ORDER_TYPE_LIST](state, orderType_list) {
        state.orderType_list = orderType_list
    },
    [types.ORDER_LIST](state, order_list) {
        state.order_list = order_list
    },
    [types.ORDER_VIEW](state, order_view) {
        state.order_view = order_view
    },
};

const getters = {
    getOrderType_list: state => state.orderType_list,
    getOrder_list: state => state.order_list,
    getOrder_view: state => state.order_view,
};
export default {
    state,
    actions,
    mutations,
    getters
};