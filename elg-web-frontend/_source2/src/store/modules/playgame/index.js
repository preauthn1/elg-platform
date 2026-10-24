import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    gameUrl: "",
    get_eventgameD: "",
    gameData: "",
    ptData: "",
    isVendorID4: false // 豪龍賠率表彈窗
};

const actions = {
    // 取遊戲連結
    actionGameUrl({ commit }, data) {
        let token = [REDACTED]"token");
        let lang = localStorage.getItem("LOCALE");
        let md5_key = Global.$API_MD5_KEY;
        let vendor_id = data.vendorID;
        let jsonMsg = JSON.stringify({
            game_id: data.gameID
        });

        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/getGameUrl",
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
                    // 遊戲參與的活動詳情
                    commit(types.GET_EVENTGAME_DETAIL, res.data.event);

                    // PC或是MOBILE;
                    let PC_MOBILE = sessionStorage.getItem("PC_MOBILE");
                    // JOKER
                    if (vendor_id == "2") {
                        if (PC_MOBILE == "PC") {
                            // 桌機PC版 URL
                            $("#formOpenGame").attr("action", res.data.url.pc);
                        } else {
                            // Mobile版 URL
                            $("#formOpenGame").attr("action", res.data.data);
                        }
                        $("#formOpenGame").submit();
                    }

                    // AG
                    else if (vendor_id == "1") {
                        //判斷IOS ANDROID
                        $(function() {
                            var u = navigator.userAgent,
                                app = navigator.appVersion;
                            var isAndroid =
                                u.indexOf("Android") > -1 ||
                                u.indexOf("Linux") > -1; //g
                            var isIOS = !!u.match(
                                /\(i[^;]+;( U;)? CPU.+Mac OS X/
                            ); //ios终端
                            if (isAndroid) {
                                // 安卓直接 iframe開啟
                                $("#iframeG").attr("src", res.data.data);
                            } else if (isIOS) {
                                // 解決IFRAME無法直開
                                // 取網址
                                let game_url = res.data.data.split("/");
                                let splitURL = game_url[0] + "//" + game_url[2];
                                // 判斷是否彈窗打開過網址
                                var openGame = $.cookie("openGameAG");
                                // 如果打開過 直接 IFRAME打開
                                if (openGame) {
                                    $("#iframeG").attr("src", res.data.data);
                                } else {
                                    // 沒有打開過 先彈窗打開
                                    var openGame = window.open(splitURL);
                                    // 兩秒後關閉彈窗 然後在IFRAME打開
                                    setTimeout(function() {
                                        openGame.close();
                                        $("#iframeG").attr(
                                            "src",
                                            res.data.data
                                        );
                                    }, 2000);
                                    // 紀錄第一次曾經彈窗
                                    $.cookie("openGameAG", splitURL);
                                }
                            } else {
                                // 桌機PC版 URL
                                $("#iframeG").attr("src", res.data.url.pc);
                            }
                        });
                    } else if (vendor_id == "11" || vendor_id == "99") {
                        // XE88 彈窗
                        if (PC_MOBILE == "PC") {
                            window.open(res.data.url.pc);
                            window.history.go(-1);
                        } else {
                            window.open(res.data.data);
                            window.history.go(-1);
                        }
                    }
                    // PT
                    else if (vendor_id == "17") {
                        let ptData = res.data.data;
                        // commit pt 資料
                        commit(types.GET_PT_DATA, ptData);
                    } else if (vendor_id == "4" || vendor_id == "18") {
                        // IFRAME 打開 豪龍 SBO
                        $("#iframeG").attr("src", res.data.url.pc);
                        // PC版 豪龍匯率表彈窗
                        commit(types.SHOW_VENDORID4_PCOPEN);
                    } else {
                        // 其他
                        if (PC_MOBILE == "PC") {
                            $("#iframeG").attr("src", res.data.url.pc);
                        } else {
                            $("#iframeG").attr("src", res.data.data);
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
                console.log("url_error", error);
            });
    },
    // 進入game取game資料
    actionGame({ commit }, gameID) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");

        let jsonMsg = JSON.stringify({
            game_id: gameID
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/game",
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
                    let gameData = res.data.data;
                    // 成功 commit 遊戲資訊
                    commit(types.GET_GAME, gameData);
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
    [types.GET_GAMEURL](state, gameUrl) {
        state.gameUrl = gameUrl;
    },
    [types.GET_GAME](state, gameData) {
        state.gameData = gameData;
    },
    [types.GET_PT_DATA](state, ptData) {
        state.ptData = ptData;
    },
    [types.GET_EVENTGAME_DETAIL](state, get_eventgameD) {
        state.get_eventgameD = get_eventgameD;
    },
    // 豪龍賠率表彈窗控制
    [types.SHOW_VENDORID4_PCOPEN](state, id) {
        state.isVendorID4 = true;
    },
    [types.HIDE_VENDORID4_PC](state) {
        state.isVendorID4 = false;
    }
};

const getters = {
    getGameUrl: state => state.gameUrl,
    getGameData: state => state.gameData,
    getPtData: state => state.ptData,
    getEventGameD: state => state.get_eventgameD,
    get_isVendorID4PC: state => state.isVendorID4
};
export default {
    state,
    actions,
    mutations,
    getters
};
