import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import { stat } from "fs";
import router from "../../../router";

const state = {
    //changepass 成功談窗
    isSuccess: false,
    // 寄出簡訊狀態
    isGetCode: false,
    // 計時
    time: 45,
    reSendCode: false,
    // 驗證完成紐狀態
    isFinish: false,
    // 重製密碼確認狀態
    isResetNewpass: false
};

const actions = {
    Timer({ commit }) {
        let timer = setInterval(() => {
            commit(types.TIMER);
            if (state.time == 0) {
                clearInterval(timer);
            }
        }, 1000);
    },
    // 寄出取驗證碼
    actionSendCode({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let jsonMsg = JSON.stringify(data);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/sendValidateCode",
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
            .then(res => {
                if (res.data.code == 1000) {
                    // 成功 commit 驗證碼
                    commit(types.GET_ISGET_CODE);
                    this.dispatch("Timer");
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
    // 確認驗證碼
    actionCheckCode({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let jsonMsg = JSON.stringify({
            token: [REDACTED]
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/checkValidateCode",
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
            .then(res => {
                if (res.data.code == 1000) {
                    // 1000 驗證碼正確
                    // TYPE = newPass 轉跳 密碼錯誤六次鎖定 設定密碼
                    if (data.type == "newPass") {
                        router.push("/SetPassword");
                        sessionStorage.setItem("username", res.data.data);
                    }
                    // 註冊後驗證
                    else if (data.type == "verify") {
                        var device_id = sessionStorage.getItem("device");
                        let secretPW = localStorage.getItem("secretPW");
                        let decryptPW = secret.Decrypt(secretPW);

                        let data = {
                            account: res.data.data,
                            password: [REDACTED],
                            device_id: device_id
                        };
                        // 驗證後直接登入
                        store.dispatch("actionLogin", data);
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
    // 重置密碼
    actionResetPassword({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let jsonMsg = JSON.stringify({
            token: [REDACTED],
            new_password: [REDACTED],
            new_password_confirmation: data.checkPassword
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/resetPassword",
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
            .then(res => {
                if (res.data.code == 1000) {
                    // 密碼更新彈窗
                    commit(types.GET_ISRESETNEWPASS);

                    // 重置密碼後直接登入
                    let loginData = {
                        account: sessionStorage.getItem("username"),
                        password: [REDACTED],
                        isReset: true
                    };

                    store.dispatch("actionLogin", loginData);
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

    // 更換密碼
    actionChangePassword({ commit }, passwords) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            password: [REDACTED],
            new_password: [REDACTED],
            new_password_confirmation: passwords.new_password_confirmation
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/changePassword",
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
                    // 更換密碼確認彈窗
                    commit(types.GET_ISSUCCESS);
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
    [types.GET_ISSUCCESS](state) {
        state.isSuccess = true;
    },
    [types.GET_ISGET_CODE](state) {
        state.isGetCode = true;
        state.reSendCode = false;
        state.time = 45;
    },
    [types.TIMER](state) {
        if (state.time > 0) {
            state.time--;
            if (state.time < 10) {
                state.time = "0" + state.time;
                if (state.time == 0) {
                    state.reSendCode = true;
                }
            }
        }
    },
    [types.GET_ISRESETNEWPASS](state) {
        state.isResetNewpass = true;
    },
    completeDisable(state, val) {
        state.isFinish = val;
    }
};

const getters = {
    isSuccess: state => state.isSuccess,
    isGetCode: state => state.isGetCode, // 寄出簡訊解鎖狀態
    getTime: state => state.time, // 倒數
    reSendCode: state => state.reSendCode, //重寄簡訊解鎖狀態
    isFinish: state => state.isFinish, // 驗證碼輸入-完成解鎖狀態
    isResetNewpass: state => state.isResetNewpass, //重製密碼確認狀態
    newPassName: state => state.newPassName
};
export default {
    state,
    actions,
    mutations,
    getters
};
