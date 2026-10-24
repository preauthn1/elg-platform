import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {};

const actions = {
    actionLogout({ commit }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/logout",
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
                // 登出 pc版轉跳lobby mobile版轉跳login
                if (sessionStorage.getItem("PC_MOBILE") == "PC") {
                    window.location.assign("/Lobby");
                } else {
                    window.location.assign("/login");
                }
                // 清除session
                window.sessionStorage.clear();
            });
    }
};

const mutations = {};

const getters = {};
export default {
    state,
    actions,
    mutations,
    getters
};
