import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import router from "@/router";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    avatar: "",
    nickname: ""
};

const actions = {
    // 上傳 頭像 Avatar
    actionAvatar({ commit }, avatar) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            image: avatar
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/update",
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
                    window.sessionStorage.setItem("avatar", avatar);
                    // 成功 commit 頭像
                    commit(types.GET_AVATAR, avatar);
                    // window.history.go(-1)
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
    // 設定暱稱 nickname
    actionNickname({ commit }, nickname) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            nickname: nickname
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/update",
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
                    window.sessionStorage.setItem("nickname", nickname);
                    // 成功 commit 暱稱
                    commit(types.GET_NICKNAME, nickname);
                    window.history.go(-1);
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
    [types.GET_AVATAR](state, avatar) {
        state.avatar = avatar;
    },
    [types.GET_NICKNAME](state, nickname) {
        state.nickname = nickname;
    }
};

const getters = {
    getAvatar: state => state.avatar,
    getNickname: state => state.nickname
};
export default {
    state,
    getters,
    actions,
    mutations
};
