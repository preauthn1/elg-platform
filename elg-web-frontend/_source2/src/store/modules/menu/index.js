import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    menuAry: []
};

const actions = {
    // 產生 MENU 選單
    actionMenu({ commit }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");

        axios
            .post(
                Global.$API_URL + "api/gameTypes",
                {
                    params: secret.Encrypt(""),
                    key: md5(secret.Encrypt("") + md5_key)
                },
                {
                    headers: {
                        "Accept-Language": lang
                    }
                }
            )
            .then(res => {
                if (res.data.code == 1000) {
                    // 成功 commit 遊戲類型列表
                    commit(types.MENUTYPE, res.data.data);
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
    [types.MENUTYPE](state, res) {
        state.menuAry = res;
    }
};

const getters = {
    getMenu: state => state.menuAry
};
export default {
    state,
    getters,
    actions,
    mutations
};
