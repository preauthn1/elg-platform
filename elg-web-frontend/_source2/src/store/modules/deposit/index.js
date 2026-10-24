import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    orderTypes: [],
    orderId: ""
};

const actions = {
    /// 儲值銀行列表 ///
    actionOrderTypes({ commit }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/orderTypes",
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
                if (res.data.code == 1000) {
                    // 1000 = 成功
                    let orderTypes = res.data.data;
                    // 成功 COMMIT 訂單類型列表
                    commit(types.GET_ORDERTYPES, orderTypes);
                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    commit(types.SHOW_API_RES, resData);
                }
            })
            .catch(error => {
                console.log("error", error);
            });
    },
    /// 儲值 ///
    actionAddPoint({ commit }, obj) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            order_type_id: obj.ordertypeId,
            amount: obj.amount,
            callback: window.location.origin
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/order/create",
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
                if (res.data.code == 1000) {
                    // 1000 = 成功
                    let orderId = res.data.order_id;
                    // 成功 COMMIT 訂單編號
                    commit(types.GET_ORDERID, orderId);
                    let url = res.data.data;
                    // 兩秒後轉跳 儲值頁面
                    setTimeout(() => {
                        window.location = url;
                    }, 2000);
                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    commit(types.SHOW_API_RES, resData);
                }
            })
            .catch(error => {
                console.log(error);
            });
    }
};

const mutations = {
    [types.GET_ORDERTYPES](state, orderTypes) {
        state.orderTypes = orderTypes;
    },
    [types.GET_ORDERID](state, orderId) {
        state.orderId = orderId;
    }
};

const getters = {
    getOrderTypes: state => state.orderTypes,
    getOrderId: state => state.orderId
};
export default {
    state,
    actions,
    mutations,
    getters
};
