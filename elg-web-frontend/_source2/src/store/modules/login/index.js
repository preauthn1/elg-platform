import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import VueI18n from "vue-i18n";
const state = {
    customerData: "",
    isJoinEvent: false,
    isUnlockEvent: false,
    checkEvent: "",
    unlockEvent: "",
    getLoginEvent: "",
    isEventOver: false
};

const actions = {
    actionLogin({
        commit
    }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let jsonMsg = JSON.stringify({
            username: data.account,
            password: [REDACTED],
            device_id: data.device_id
        });

        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/login", {
                    params: params,
                    key: key
                }, {
                    headers: {
                        "Accept-Language": lang
                    }
                }
            )
            .then(function (res) {
                if (res.data.code == 1000) {
                    // 會員登入
                    // 1000 = 成功
                    // 記使用者帳號
                    localStorage.setItem("user_account", data.account);
                    let userPW = data.password;
                    // 記使用者密碼 加密
                    localStorage.setItem(
                        "user_password",
                        secret.Encrypt(userPW)
                    );
                    // 使用者名稱
                    sessionStorage.setItem("username", res.data.data.username);
                    // 使用者你稱
                    sessionStorage.setItem("nickname", res.data.data.nickname);
                    // 使用者平台點數
                    sessionStorage.setItem("balance", res.data.data.balance);
                    // 使用者照片
                    sessionStorage.setItem("avatar", res.data.data.image);
                    // 使用者是否進入活動鎖定模式
                    sessionStorage.setItem(
                        "eventMode",
                        res.data.data.event_mode
                    );
                    // 使用者MAIL
                    sessionStorage.setItem("email", res.data.data.email);
                    // 使用者最後登入時間
                    sessionStorage.setItem(
                        "last_login",
                        res.data.data.last_login
                    );
                    // 使用者TOKEN
                    sessionStorage.setItem("token", res.data.data.token);
                    // 使用者分享碼
                    sessionStorage.setItem(
                        "SHARE_CODE",
                        res.data.data.SHARE_CODE
                    );

                    sessionStorage.setItem(
                        "CS_API_USER",
                        res.data.data.CS_API_USER
                    );
                    sessionStorage.setItem(
                        "CS_API_KEY",
                        res.data.data.CS_API_KEY
                    );

                    //! 打TEMPLATE
                    let domainUrl = location.hostname;
                    let md5_key = Global.$API_MD5_KEY;
                    let lang = localStorage.getItem("LOCALE");
                    let token = [REDACTED]"token");
                    let jsonMsg = JSON.stringify({
                        domain: domainUrl
                    });
                    let params = secret.Encrypt(jsonMsg);
                    let key = md5(params + md5_key);
                    axios
                        .post(
                            Global.$API_URL + "api/template", {
                                params: params,
                                key: key
                            }, {
                                headers: {
                                    "Accept-Language": lang,
                                    Authorization: "Bearer " + token
                                }
                            }
                        )
                        .then(res => {
                            if (res.data.code == 1000) {
                                // 開關
                                sessionStorage.setItem(
                                    "frontend_register",
                                    res.data.data.frontend_register
                                );
                                sessionStorage.setItem(
                                    "has_chat",
                                    res.data.data.has_chat
                                );
                                sessionStorage.setItem(
                                    "has_pc",
                                    res.data.data.has_pc
                                );
                                sessionStorage.setItem(
                                    "has_mobile",
                                    res.data.data.has_mobile
                                );
                                sessionStorage.setItem(
                                    "has_agent_layout",
                                    res.data.data.has_agent_layout
                                );
                                sessionStorage.setItem(
                                    "has_online_payment",
                                    res.data.data.has_online_payment
                                );
                                sessionStorage.setItem(
                                    "transaction_all",
                                    res.data.data.transaction_all
                                );
                                sessionStorage.setItem(
                                    "has_app",
                                    res.data.data.has_app
                                );
                                sessionStorage.setItem(
                                    "loginSession",
                                    "SessionOK"
                                );
                            } else {
                                alert(res.data.message);
                            }
                        })
                        .catch(error => {
                            console.log(error);
                        });
                    //! 聊天室
                    var chatMember = JSON.stringify({
                        thirdpartyId: res.data.data.username,
                        agentuser: res.data.data.CS_API_USER,
                        agentkey: res.data.data.CS_API_KEY
                    });
                    sessionStorage.setItem("chatMember", chatMember);
                    //! 登入活動到期強制解鎖
                    // 是否強制解除活動模式
                    let forceUnlock = res.data.data.is_force_unlock_event_mode;
                    if (forceUnlock == 1) {
                        commit(types.SHOW_EVENT_OVER);
                        store.dispatch("actionUnLoginEvent", 1);
                        sessionStorage.setItem("eventMode", 0);
                    }
                    setTimeout(() => {
                        // 重設密碼 true 不直接跳轉
                        if (!data.isReset) {
                            if (forceUnlock == 1) {
                                setTimeout(() => {
                                    window.location.assign("/");
                                }, 8000);
                            } else {
                                window.location.assign("/");
                            }
                        }
                    }, 1000);
                } else if (res.data.code == 10001) {
                    // 10001 客服帳密前台登入成功
                    //! app前台登入客服
                    let customerData = res.data.data;
                    window.location =
                        Global.$CUSTOMER_SERVICE +
                        "WebLogin/" +
                        customerData.token +
                        "/" +
                        customerData.id +
                        "/1";
                } else if (res.data.code != 1000) {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    commit(types.SHOW_API_RES, resData);
                    // 手機未驗證
                    if (res.data.code == 9007) {
                        let member_id = res.data.data;
                        sessionStorage.setItem("id", member_id);
                    }
                }
            })
            .catch(function (error) {
                console.log(error);
            });
    },

    // 參加登入活動
    actionJoinLoginEvent({
        commit
    }, joinData) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);

        axios
            .post(
                Global.$API_URL + "api/joinLoginEvent", {
                    params: params,
                    key: key
                }, {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer " + token
                    }
                }
            )
            .then(res => {
                if (res.data.code == 1000) {
                    // joinData不等於1時 大廳彈窗參加reload
                    if (joinData != 1) {
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    }
                    //     抓參數3 pc IFRAME點JOININ 活動 IFRAME關閉RELOAD
                    if (joinData == 3) {
                        sessionStorage.setItem("eventIframe", 1);
                    }
                    sessionStorage.setItem("eventMode", 1);
                } else {
                    // JOINDATA 不等於1 時 有錯誤彈窗
                    if (joinData != 1) {
                        let resData = {
                            code: res.data.code,
                            msg: res.data.message
                        };

                        commit(types.SHOW_API_RES, resData);
                    }
                }
            })
            .catch(error => {
                console.log(error);
            });
    },
    // 登入活動解鎖
    actionUnLoginEvent({
        commit
    }, isforce) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            is_force: isforce
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/unlockLoginEvent", {
                    params: params,
                    key: key
                }, {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer " + token
                    }
                }
            )
            .then(res => {
                if (res.data.code == 1000) {
                    // 成功 強制解鎖
                    // 活動模式 SESSION 改 0
                    sessionStorage.setItem("eventMode", 0);
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else if (res.data.code == 10001) {
                    // 10001 活動尚未結束 強制解鎖彈窗
                    commit(types.SHOW_UNLOCK_EVENT);
                    commit(types.GET_UNLOCK_EVENT, res.data.data);
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
    },
    // 確認是否可以參加登入活動
    actionCheckLoginEvent({
        commit
    }, pathData) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/checkLoginEvent", {
                    params: params,
                    key: key
                }, {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer " + token
                    }
                }
            )
            .then(res => {
                if (res.data.code == 1000) {
                    // LOBBY頁面  確認是否可參加登入活動 帶參數1
                    if (pathData == 1) {
                        commit(types.SHOW_JOIN_EVENT);
                        let checkEvent = res.data.data;
                        commit(types.GET_CHECK_EVENT, checkEvent);
                    }
                    // Events 頁面 檢查資格轉跳 帶參數2
                    else if (pathData == 2) {
                        // Events 頁面 檢查資格轉跳
                        window.location = "/EventMode";
                    }
                } else {
                    // 在大廳首頁 無資格才彈窗
                    if (
                        location.pathname == "/" ||
                        location.pathname == "/Lobby"
                    ) {} else {
                        let resData = {
                            code: res.data.code,
                            msg: res.data.message
                        };
                        commit(types.SHOW_API_RES, resData);
                    }
                }
            })
            .catch(error => {
                console.log(error);
            });
    },
    // 取目前開放的登入活動
    actionGetLoginEvent({
        commit
    }) {
        //console.log(new Error().stack);
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/getLoginEvent", {
                    params: params,
                    key: key
                }, {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer " + token
                    }
                }
            )
            .then(res => {
                if (res.data.code == 1000) {
                    let getLoginEvent = res.data.data;
                    // 成功 commit 取目前開放的登入活動
                    commit(types.GET_LOGIN_EVENT, getLoginEvent);
                }
            })
            .catch(error => {
                console.log(error);
            });
    }
};

const mutations = {
    // 確認是否可以參加登入活動彈窗控制
    [types.SHOW_JOIN_EVENT](state) {
        state.isJoinEvent = true;
    },
    [types.HIDE_JOIN_EVENT](state) {
        state.isJoinEvent = false;
    },
    [types.GET_CHECK_EVENT](state, checkEvent) {
        state.checkEvent = checkEvent;
    },
    // 解鎖彈窗
    [types.SHOW_UNLOCK_EVENT](state) {
        state.isUnlockEvent = true;
    },
    [types.HIDE_UNLOCK_EVENT](state) {
        state.isUnlockEvent = false;
    },
    [types.GET_UNLOCK_EVENT](state, unlockEvent) {
        state.unlockEvent = unlockEvent;
    },
    [types.GET_LOGIN_EVENT](state, getLoginEvent) {
        state.getLoginEvent = getLoginEvent;
    },
    // 活動結束
    [types.SHOW_EVENT_OVER](state) {
        state.isEventOver = true;
    },
    [types.HIDE_EVENT_OVER](state) {
        state.isEventOver = false;
    }
};

const getters = {
    get_checkLoginEvent: state => state.isJoinEvent,
    get_checkEvent: state => state.checkEvent,
    get_isUnlockEvent: state => state.isUnlockEvent,
    get_unlockEvent: state => state.unlockEvent,
    get_getLoginEvent: state => state.getLoginEvent,
    get_eventOver: state => state.isEventOver
};
export default {
    state,
    actions,
    mutations,
    getters
};
