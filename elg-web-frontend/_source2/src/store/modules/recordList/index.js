import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import router from "@/router";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    record_points: []
};

const actions = {
    /// 轉點紀錄 //
    actionRecord_point({ commit }, range) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            vendor_id: "",
            start_time: range.start_time,
            end_time: range.end_time,
            page: 1,
            per_page: 50
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/transactions",
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
                    let record = res.data.data;
                    // 成功 commit 轉點紀錄列表
                    commit(types.GET_RECORD_POINTS, record);

                    /// 手機板時間查詢用
                    if (
                        window.sessionStorage.getItem("isSearchRecord_lock") ==
                        "1"
                    ) {
                        window.history.go(-1);
                    }
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
    // 客服成功紀錄
    actionRecord_Customer({ commit }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            page: 1,
            per_page: 999
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/transaction/customerService",
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
                    let record = res.data.data;
                    //    成功 commit 客服成功紀錄
                    commit(types.GET_RECORD_POINTS, record);
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
    // 紅包紀錄
    actionRecord_RedEnvelope({ commit }, type) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            type: type,
            page: 1,
            per_page: 999
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/transaction/redEnvelope",
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
                    let record = res.data.data;
                    // 成功 commit 紅包紀錄
                    commit(types.GET_RECORD_POINTS, record);
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
    }
};

const mutations = {
    [types.GET_RECORD_POINTS](state, record) {
        state.record_points = record;
    }
};

const getters = {
    getRecordPoints: state => state.record_points
};
export default {
    state,
    actions,
    mutations,
    getters
};
