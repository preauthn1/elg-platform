import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import { set } from "shelljs";

const state = {
    vendor_info: [],
    vendor_games: [],
    isVendorID4: false // 豪龍賠率表彈窗
};

const actions = {
    // 取遊戲商登入資訊
    action_vendorInfo({ commit }, vendorID) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let vendor_id = vendorID;

        let jsonMsg = JSON.stringify({
            vendor_id: vendor_id
        });

        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/vendorInfo",
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
                    // 成功 commit 遊戲商登入資訊
                    commit(types.GET_VENDOR_INFO, res.data.data);

                    if (res.data.data["url"]) {
                        // 豪龍 SBO
                        if (vendor_id == "4" || vendor_id == "18") {
                            //判斷IOS ANDROID
                            $(function() {
                                var u = navigator.userAgent,
                                    app = navigator.appVersion;
                                var isAndroid =
                                    u.indexOf("Android") > -1 ||
                                    u.indexOf("Linux") > -1; //g
                                var isIOS = !!u.match(
                                    /\(i[^;]+;( U;)? CPU.+Mac OS X/
                                );
                                if (isAndroid) {
                                    $("#iframeG").attr(
                                        "src",
                                        res.data.data.url
                                    );
                                }
                                if (isIOS) {
                                    // 轉跳BUTTONCLICK頁面後點擊彈窗
                                    window.location = "/ButtonClick";
                                }
                            });
                        } else {
                            // game頁面 iframe url
                            $("#formOpenGame").attr("action", res.data.url);
                            setTimeout(() => {
                                $("#formOpenGame").submit();
                            }, 2000);
                        }
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
                console.log(error);
            });
    },
    //GampApp 參與活動的遊戲列表
    action_vendorGames({ commit }, vendorID) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let vendor_id = vendorID;
        let jsonMsg = JSON.stringify({
            vendor_id: vendor_id,
            page: 1,
            per_page: 30
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/event/vendorGames",
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
                    let eventGames = res.data.data;
                    // 成功 commit GampApp 參與活動的遊戲列表
                    commit(types.GET_VENDOR_GAMES, eventGames);
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

    // 重設遊戲商密碼
    action_resetVendorPass({ commit }, new_password) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let vendor_id = sessionStorage.getItem("vendor_id");
        let jsonMsg = JSON.stringify({
            vendor_id: vendor_id,
            new_password: [REDACTED]
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/resetVendorPassword",
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
                    // 成功 commit 彈出提示彈窗
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message,
                        type: "vendorPass"
                    };
                    commit(types.SHOW_API_RES, resData);
                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message,
                        type: "vendorError"
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
    [types.GET_VENDOR_INFO](state, info) {
        state.vendor_info = info;
    },
    [types.GET_VENDOR_GAMES](state, vendorGames) {
        state.vendor_games = vendorGames;
    },
    // 豪龍賠率表彈窗控制
    [types.SHOW_VENDORID4_OPEN](state, id) {
        state.isVendorID4 = true;
    },
    [types.HIDE_VENDORID4](state) {
        state.isVendorID4 = false;
    }
};

const getters = {
    getVendor_Info: state => state.vendor_info,
    getVendor_Games: state => state.vendor_games,
    get_isVendorID4: state => state.isVendorID4
};
export default {
    state,
    actions,
    mutations,
    getters
};
