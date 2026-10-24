import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    acctInfo: {
        balance: "",
        mobile: "",
        email: ""
    },
    countries: [],
    timezones: [],
    languages: [],
    bannerAry: [],
    bannerAry4: [],
    lottery: []
};

const actions = {
    // 遊戲彩金列表
    action_lottery({
        commit
    }, number) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            number: number
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        if (token) {
            axios
                .post(
                    Global.$API_URL + "api/lottery", {
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
                        // 成功 commit 遊戲彩金列表(PC用)
                        commit(types.GET_LOTTERY, res.data.data);
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
        } else {
            // pc版用 假資料
            // 抓template 不同版本頁面顯示個數不同
            if (sessionStorage.getItem("Template") == "G733") {
                var fake_lottery = [{
                        id: 280,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Dolphin Reef",
                        lottery_pool: "99999999.999",
                        image: "//example.invalid/gameimages/landscape/rh8iwwntk3mie.png"
                    },
                    {
                        id: 284,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Sparta",
                        lottery_pool: "1486473.000",
                        image: "//example.invalid/gameimages/landscape/jbzd1cjsgh4dk.png"
                    },
                    {
                        id: 324,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Third Prince's Journey",
                        lottery_pool: "684611.000",
                        image: "//example.invalid/gameimages/landscape/bzgza4umpbwsh.png"
                    },
                    {
                        id: 282,
                        vendor_id: 2,
                        type_id: 2,
                        name: "A Night Out",
                        lottery_pool: "564564.000",
                        image: "//example.invalid/gameimages/landscape/69xaiyrbo4dae.png"
                    },
                    {
                        id: 283,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Azteca",
                        lottery_pool: "312684.000",
                        image: "//example.invalid/gameimages/landscape/oqt9p9876m39y.png"
                    },
                    {
                        id: 275,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Ocean Paradise",
                        lottery_pool: "165102.000",
                        image: "//example.invalid/gameimages/landscape/kf41ymtxfos1r.png"
                    },
                    {
                        id: 279,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Lucky God",
                        lottery_pool: "31522.000",
                        image: "//example.invalid/gameimages/landscape/dhdirsn3m3xia.png"
                    },
                    {
                        id: 300,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Lucky God Progressive",
                        lottery_pool: "1945.830",
                        image: "//example.invalid/gameimages/landscape/ef1uyxt98o6ur.png"
                    },
                    {
                        id: 314,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Captains Treasure Progressive",
                        lottery_pool: "1004.100",
                        image: "//example.invalid/gameimages/landscape/awn5jciusna5c.png"
                    },
                    {
                        id: 313,
                        vendor_id: 2,
                        type_id: 2,
                        name: "SilverBullet Progressive",
                        lottery_pool: "1004.100",
                        image: "//example.invalid/gameimages/landscape/ruufkzk1kpefn.png"
                    },
                    {
                        id: 304,
                        vendor_id: 2,
                        type_id: 2,
                        name: "HighwayKings Progressive",
                        lottery_pool: "1004.100",
                        image: "//example.invalid/gameimages/landscape/nh9swadbc3use.png"
                    },
                    {
                        id: 303,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Robin Hood",
                        lottery_pool: "1004.100",
                        image: "//example.invalid/gameimages/landscape/i4rc816e388c6.png"
                    },
                    {
                        id: 277,
                        vendor_id: 2,
                        type_id: 2,
                        name: "African",
                        lottery_pool: "1004.100",
                        image: "//example.invalid/gameimages/landscape/xtpy4bx49xhx1.png"
                    }
                ];
                commit(types.GET_LOTTERY, fake_lottery);
            } else {
                var fake_lottery = [{
                        id: 280,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Dolphin Reef",
                        lottery_pool: "99999999.999",
                        image: "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/rh8iwwntk3mie.png"
                    },
                    {
                        id: 340,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Sizzling Hot",
                        lottery_pool: "4658613.000",
                        image: "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/ww3a8wsu4de7c.png"
                    },
                    {
                        id: 284,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Sparta",
                        lottery_pool: "1486473.000",
                        image: "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/jbzd1cjsgh4dk.png"
                    },
                    {
                        id: 324,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Third Prince's Journey",
                        lottery_pool: "684611.000",
                        image: "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/bzgza4umpbwsh.png"
                    },
                    {
                        id: 345,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Dolphin Treasure",
                        lottery_pool: "654896.000",
                        image: "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/ateqfxp1sqamn.png"
                    },
                    {
                        id: 282,
                        vendor_id: 2,
                        type_id: 2,
                        name: "A Night Out",
                        lottery_pool: "564564.000",
                        image: "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/69xaiyrbo4dae.png"
                    },
                    {
                        id: 283,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Azteca",
                        lottery_pool: "312684.000",
                        image: "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/oqt9p9876m39y.png"
                    },
                    {
                        id: 278,
                        vendor_id: 2,
                        type_id: 2,
                        name: "Genie",
                        lottery_pool: "165413.000",
                        image: "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/1q36p58phmt6y.png"
                    }
                ];
                commit(types.GET_LOTTERY, fake_lottery);
            }
        }
    },
    //QR CODE進入重新取得token
    actionRenewToken({
        commit
    }, token_old) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED];
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);

        if (token) {
            axios
                .post(
                    Global.$API_URL + "api/renew/token",

                    {
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
                        // PC版 QR CODE 進mobile 重新取得token 存入session
                        sessionStorage.setItem("token", res.data.data);
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
    },
    /// member
    actionMember({
        commit
    }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        if (data != undefined) {
            var vendorName = data.vendor;
            var vendorID = data.id;
        } else {}
        // 如果有登入
        if (token) {
            axios
                .post(
                    Global.$API_URL + "api/member",

                    {
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
                        // 成功後記 session
                        // 會員帳號
                        sessionStorage.setItem(
                            "thirdpartyId_tem",
                            res.data.data.username
                        );
                        sessionStorage.setItem("vendorName", vendorName);
                        sessionStorage.setItem("vendorQRID", vendorID);

                        let member = res.data.data;
                        // 會員餘額
                        sessionStorage.setItem("balance", member.balance);
                        // 成功 commit 會員資料
                        commit(types.GET_MEMBER, member);
                        let vendorQRName = sessionStorage.getItem("vendorName");
                        let vendorQRID = sessionStorage.getItem("vendorQRID");
                        let locationHref = location.href;
                        // 一般打開不從QRCODE 打TEMPLATE API
                        if (locationHref.indexOf("QRcodeOpen") != -1) {
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
                                        // 前端是否開放註冊
                                        sessionStorage.setItem(
                                            "frontend_register",
                                            res.data.data.frontend_register
                                        );
                                        // 是否開放聊天室
                                        sessionStorage.setItem(
                                            "has_chat",
                                            res.data.data.has_chat
                                        );
                                        // 是否有PC版
                                        sessionStorage.setItem(
                                            "has_pc",
                                            res.data.data.has_pc
                                        );
                                        // 是否有手機版
                                        sessionStorage.setItem(
                                            "has_mobile",
                                            res.data.data.has_mobile
                                        );
                                        sessionStorage.setItem(
                                            "has_agent_layout",
                                            res.data.data.has_agent_layout
                                        );
                                        // 是否有線上儲值
                                        sessionStorage.setItem(
                                            "has_online_payment",
                                            res.data.data.has_online_payment
                                        );
                                        // 進入遊戲時是否將所有錢轉入(單一錢包)
                                        sessionStorage.setItem(
                                            "transaction_all",
                                            res.data.data.transaction_all
                                        );
                                        // 是否有APP下載
                                        sessionStorage.setItem(
                                            "has_app",
                                            res.data.data.has_app
                                        );
                                        sessionStorage.setItem(
                                            "loginSession",
                                            "SessionOK"
                                        );
                                        // 聊天室用
                                        var chatMember = JSON.stringify({
                                            // res.data.data.username 會員帳號
                                            thirdpartyId: sessionStorage.getItem(
                                                "thirdpartyId_tem"
                                            ),
                                            // 連接客服系統API USER
                                            agentuser: res.data.data.CS_API_USER,
                                            // 連接客服系統API KEY
                                            agentkey: res.data.data.CS_API_KEY
                                        });
                                        // 連接客服系統API USER
                                        sessionStorage.setItem(
                                            "CS_API_USER",
                                            res.data.data.CS_API_USER
                                        );
                                        // 連接客服系統API KEY
                                        sessionStorage.setItem(
                                            "CS_API_KEY",
                                            res.data.data.CS_API_KEY
                                        );
                                        sessionStorage.setItem(
                                            "chatMember",
                                            chatMember
                                        );
                                    } else {
                                        alert(res.data.message);
                                    }
                                })
                                .catch(error => {
                                    console.log(error);
                                });
                            // PC QRCODE開啟MOBILE APP頁面
                            setTimeout(() => {
                                window.location =
                                    "/GameApp?vendor=" +
                                    vendorQRName +
                                    "&vendorID=" +
                                    vendorQRID;
                            }, 1000);
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
        }
    },

    // banner廣告列表  advertisements
    actionBanner({
        commit
    }, path_id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            path_id: path_id //類型編號
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        if (token) {
            axios
                .post(
                    Global.$API_URL + "api/advertisements", {
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
                        // path_id = 4 全版廣告
                        if (path_id == 4) {
                            let bannerAry4 = res.data.data;
                            // 成功 commit 全版廣告資料
                            commit(types.GET_BANNER4, bannerAry4);
                        } else {
                            let bannerAry = res.data.data;
                            // 成功 commit BANNER資料
                            commit(types.GET_BANNER, bannerAry);
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
        } else {
            // PC版未登入 假資料
            var fake_Banner = [{
                    image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/15753698425.jpeg",
                    image_pc: "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157546182142.jpeg",
                    url: null,
                    game_id: 0,
                    game_type_id: 0,
                    vendor_id: 13
                },
                {
                    image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157537024232.jpeg",
                    image_pc: null,
                    url: null,
                    game_id: 0,
                    game_type_id: 0,
                    vendor_id: 11
                },
                {
                    image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157537462215.jpeg",
                    image_pc: null,
                    url: null,
                    game_id: 0,
                    game_type_id: 0,
                    vendor_id: 12
                }
            ];
            commit(types.GET_BANNER, fake_Banner);
        }
    },

    //國家列 countries
    actionCountries({
        commit
    }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/countries", {
                    params: params,
                    key: key
                }, {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer "
                    }
                }
            )
            .then(res => {
                if (res.data.code == 1000) {
                    // 成功 commit 國家列表
                    commit(types.GET_COUNTRIES, res.data.data);
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

    // 時區列 timezones
    actionTimezones({
        commit
    }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/timezones", {
                    params: params,
                    key: key
                }, {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer "
                    }
                }
            )
            .then(res => {
                if (res.data.code == 1000) {
                    // 成功 commit 時區列表
                    commit(types.GET_TIMEZONES, res.data.data);
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

    // 語言列 languages
    actionLanguages({
        commit
    }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        let token = [REDACTED]"token");
        if (token) {
            axios
                .post(
                    Global.$API_URL + "api/languages", {
                        params: params,
                        key: key
                    }, {
                        headers: {
                            "Accept-Language": lang,
                            Authorization: "Bearer "
                        }
                    }
                )
                .then(res => {
                    if (res.data.code == 1000) {
                        // 成功 commit 語系列表
                        commit(types.GET_LANGUAGES, res.data.data);
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
        } else {
            // pc版未登入 假資料
            var fake_languages = [{
                    id: 1,
                    code: "en",
                    name: "English"
                },
                {
                    id: 2,
                    code: "zh-tw",
                    name: "\u7e41\u9ad4\u4e2d\u6587"
                },
                {
                    id: 3,
                    code: "zh-cn",
                    name: "\u7b80\u4f53\u4e2d\u6587"
                },
                {
                    id: 4,
                    code: "ms-my",
                    name: "Melayu"
                },
                {
                    id: 5,
                    code: "id-id",
                    name: "Indonesia"
                }
            ];
            commit(types.GET_LANGUAGES, fake_languages);
        }
    }
};

const mutations = {
    [types.GET_LOTTERY](state, lottery) {
        state.lottery = lottery;
    },
    [types.GET_MEMBER](state, member) {
        store.state.Points.newVpoints = member.balance;

        state.acctInfo.balance = member.balance;
        sessionStorage.setItem("balance", member.balance);
        state.acctInfo.mobile = member.mobile;
        state.acctInfo.email = member.email;
        sessionStorage.setItem("id", member.id);
        sessionStorage.setItem("username", member.username);
        sessionStorage.setItem("nickname", member.nickname);
        sessionStorage.setItem("balance", member.balance);
        sessionStorage.setItem("avatar", member.image);
        sessionStorage.setItem("email", member.email);
        sessionStorage.setItem("mobile", member.mobile);
    },
    [types.GET_BANNER](state, bannerAry) {
        state.bannerAry = bannerAry;
    },
    [types.GET_BANNER4](state, bannerAry4) {
        state.bannerAry4 = bannerAry4;
    },
    [types.GET_COUNTRIES](state, countries) {
        state.countries = countries;
    },
    [types.GET_TIMEZONES](state, timezones) {
        state.timezones = timezones;
    },
    [types.GET_LANGUAGES](state, languages) {
        state.languages = languages;
    }
};

const getters = {
    getLottery: state => state.lottery,
    getBanner: state => state.bannerAry,
    getBanner4: state => state.bannerAry4,
    getCountries: state => state.countries,
    getAcctInfo: state => state.acctInfo,
    getLanguages: state => state.languages
};
export default {
    state,
    getters,
    actions,
    mutations
};
