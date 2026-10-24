import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";
import value_utils from "@/utils/value_utils";

const state = {
    lobby_vendorList: [],
    vendors: [],
    vendorGameTypes: [],
    gamelist_balance: "",
    vendor: ""
};

const actions = {


    actionGetVendorGameTypes({}) {
        let md5_key = Global.$API_MD5_KEY;
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);

        var alwaysHeader = value_utils.GetGlobalRequestHeader();
        let url = Global.$API_URL + "/api/vendor/gameTypes";

        axios
            .post(
                url, {
                    params: params,
                    key: key
                }, {
                    headers: alwaysHeader
                }
            )
            .then(res => {

                console.log(res.data.data);
                this.commit(types.GET_VENDOR_GAMETYPES, res.data.data);

            })
            .catch(error => {

                console.log("error", error);
            });
    },


    /// 取遊戲商列表 ///
    actionVendors({
        dispatch,
        commit
    }, payload) {
        return new Promise(async (resolve, reject) => {
            let md5_key = Global.$API_MD5_KEY;
            let lang = localStorage.getItem("LOCALE");
            let token = [REDACTED]"token");

            let jsonMsg = JSON.stringify({
                type: payload.type
            });
            let params = secret.Encrypt(jsonMsg);
            let key = md5(params + md5_key);
            if (token) {
                axios
                    .post(
                        Global.$API_URL + "api/vendors", {
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
                            let vendors = res.data.data;
                            // 成功後 commit 遊戲商列表
                            commit(types.GET_VENDORS, vendors);
                            // pc版用
                            commit(types.GET_LOBBY_VENDORS_LIST, vendors);
                            // 是否抓取餘額
                            let isGetBalance = payload.isGetBalance || false;

                            if (isGetBalance) {
                                let count = 0;
                                for (var vendor of vendors) {
                                    let id = vendor.id;
                                    let slow = vendor.is_slow;

                                    if (vendor.status == 1 && slow != 1) {
                                        count++;

                                        // 已上線的遊戲商才取餘額
                                        dispatch(
                                            "actionVendor_balance",
                                            id
                                        ).then(getBal => {
                                            count--;

                                            if (count == 0) {
                                                resolve(true);
                                            }
                                        });
                                    }
                                }
                            }
                        } else {
                            //未登入
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
                // pc未登入 假資料
                var fake_lobbyList = [{
                        id: 8,
                        name: "Vpaly",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156816760153.png",
                        app_icon: null,
                        is_new: 1,
                        is_hot: 1,
                        need_login: 1,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 3,
                        name: "SCR(918KISS)",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156315696428.png",
                        app_icon: null,
                        is_new: 0,
                        is_hot: 0,
                        need_login: 1,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 4,
                        name: "4D",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156810221017.png",
                        app_icon: null,
                        is_new: 1,
                        is_hot: 0,
                        need_login: 0,
                        only_one_game: 1,
                        status: 1
                    },
                    {
                        id: 5,
                        name: "GreatWall99",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156922158472.png",
                        app_icon: null,
                        is_new: 0,
                        is_hot: 0,
                        need_login: 1,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 6,
                        name: "Ace333",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket/15638543196.png",
                        app_icon: null,
                        is_new: 0,
                        is_hot: 0,
                        need_login: 1,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 10,
                        name: "Mega888",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157010057310.png",
                        app_icon: null,
                        is_new: 1,
                        is_hot: 0,
                        need_login: 1,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 1,
                        name: "AG",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156895864747.png",
                        app_icon: null,
                        is_new: 0,
                        is_hot: 1,
                        need_login: 0,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 2,
                        name: "Joker",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156315692331.png",
                        app_icon: null,
                        is_new: 0,
                        is_hot: 0,
                        need_login: 0,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 11,
                        name: "Xe88",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157010045355.png",
                        app_icon: null,
                        is_new: 0,
                        is_hot: 0,
                        need_login: 0,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 13,
                        name: "eBET",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket/15748521611.png",
                        app_icon: null,
                        is_new: 0,
                        is_hot: 0,
                        need_login: 0,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 12,
                        name: "Lucky",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket/157174143953.png",
                        app_icon: null,
                        is_new: 1,
                        is_hot: 0,
                        need_login: 0,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 15,
                        name: "Ygg",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157708157088.png",
                        app_icon: null,
                        is_new: 1,
                        is_hot: 0,
                        need_login: 0,
                        only_one_game: 0,
                        status: 1
                    },
                    {
                        id: 14,
                        name: "iSB",
                        rate: "1.00000",
                        image: "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157906139564.png",
                        app_icon: null,
                        is_new: 1,
                        is_hot: 0,
                        need_login: 0,
                        only_one_game: 0,
                        status: 1
                    }
                ];
                commit(types.GET_LOBBY_VENDORS_LIST, fake_lobbyList);
            }
        });
    },
    /// 取單遊戲商資料 ///
    actionVendor({
        commit
    }, id) {
        return new Promise(async (resolve, reject) => {
            let lang = localStorage.getItem("LOCALE");
            let token = [REDACTED]"token");
            let md5_key = Global.$API_MD5_KEY;
            let jsonMsg = JSON.stringify({
                vendor_id: id
            });
            let params = secret.Encrypt(jsonMsg);
            let key = md5(params + md5_key);
            axios
                .post(
                    Global.$API_URL + "api/vendor", {
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
                        let vendorData = res.data.data;
                        // 成功 commit 遊戲商明細
                        commit(types.GET_VENDOR, vendorData);
                    } else {
                        //未登入
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
        });
    },
    /// 取各遊戲商餘額 ///
    actionVendor_balance({
        commit
    }, id) {
        return new Promise(async (resolve, reject) => {
            let lang = localStorage.getItem("LOCALE");
            let token = [REDACTED]"token");
            let md5_key = Global.$API_MD5_KEY;
            let jsonMsg = JSON.stringify({
                vendor_id: id
            });
            let params = secret.Encrypt(jsonMsg);
            let key = md5(params + md5_key);
            axios
                .post(
                    Global.$API_URL + "api/getVendorBalance", {
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
                        resolve(true);
                        let obj = {};
                        obj["id"] = id;
                        obj["balance"] = res.data.data;
                        // 成功 commit 各遊戲商平台餘額
                        commit(types.GET_VENDORS_BALANCE, obj);
                    } else {
                        //未登入
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
        });
    },
    /// 單遊戲商餘額
    actionOne_balance({
        commit
    }, id) {
        // 非同步Promise
        return new Promise(async (resolve, reject) => {
            let lang = localStorage.getItem("LOCALE");
            let token = [REDACTED]"token");
            let md5_key = Global.$API_MD5_KEY;
            let jsonMsg = JSON.stringify({
                vendor_id: id
            });
            let params = secret.Encrypt(jsonMsg);
            let key = md5(params + md5_key);
            axios
                .post(
                    Global.$API_URL + "api/getVendorBalance", {
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
                        let balance = res.data.data;
                        // 成功 commit 單一遊戲商餘額
                        commit(types.GET_ONE_BALANCE, balance);

                        // 回傳resolve給 gamelist.playGame() actioneOne_balance.then()
                        resolve(balance);
                    } else {
                        //未登入
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
        });
    },

    actionUpdateHeaderVendorPoint({
        commit
    }, balance) {
        commit(types.GET_ONE_BALANCE, balance);
    }
};

const mutations = {
    //  lobby vendor list(未照id排序)
    [types.GET_LOBBY_VENDORS_LIST](state, vendors) {
        state.lobby_vendorList = vendors;
    },
    // 轉點用遊戲商列表(照id排序)
    [types.GET_VENDORS](state, vendors) {
        var object = {};
        vendors.forEach(function (element) {
            // 918 點右邊按鈕取點數
            element.balance = "Click the right button";
            // element.balance = this.$t("pleaseClickTheRightButton");
            object[element.id] = element;
        });
        state.vendors = object;
    },
    // vendor 自己資料
    [types.GET_VENDOR](state, vendor) {
        state.vendor = vendor;
    },

    [types.GET_VENDOR_GAMETYPES](state, vendorGameTypes) {
        state.vendorGameTypes = vendorGameTypes;
    },
    [types.GET_VENDORS_BALANCE](state, balanceAry) {
        let id = balanceAry.id;
        state.vendors[id].balance = balanceAry.balance;
    },
    [types.GET_ONE_BALANCE](state, balance) {
        state.gamelist_balance = balance;
    }
};

const getters = {
    getLobby_Vendors: state => state.lobby_vendorList,
    getVendors: state => state.vendors,
    getVendor: state => state.vendor,
    getVendor_balance: state => state.gamelist_balance,
    getVendor_gameTypes: state => state.vendorGameTypes
};
export default {
    state,
    getters,
    actions,
    mutations
};
