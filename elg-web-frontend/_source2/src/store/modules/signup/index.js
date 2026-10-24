import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    newAccount: "", // 帳號
    newPassword: "", // 密碼
    rePassword: "" // 密碼確認
};

const actions = {
    // 會員註冊
    actionCreate({ commit }, formMsg) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let jsonMsg = JSON.stringify(formMsg);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/create",
                {
                    params: params,
                    key: key
                },
                {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer "
                    }
                }
            )
            .then(function(res) {
                if (res.data.code == 1000) {
                    // 1000 = 成功
                    // 取divice id
                    var device_id = sessionStorage.getItem("device");

                    let data = {
                        account: formMsg.username,
                        password: [REDACTED],
                        device_id: device_id
                    };
                    let secretPW = formMsg.password;
                    // password 加密
                    localStorage.setItem("secretPW", secret.Encrypt(secretPW));
                    // 註冊成功直接登入
                    store.dispatch("actionLogin", data);
                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    commit(types.SHOW_API_RES, resData);
                }
            })
            .catch(function(error) {
                console.log(error);
            });
    }
};

const mutations = {
    newAccount(state, val) {
        state.newAccount = val;
    },
    newPassword(state, val) {
        state.newPassword = [REDACTED];
    },
    rePassword(state, val) {
        state.rePassword = [REDACTED];
    }
};

const getters = {};
export default {
    state,
    actions,
    mutations,
    getters
};
