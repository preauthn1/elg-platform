import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import router from "@/router";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    newVpoints: null,
    newVendorBalance: null,
    singleBalance: ""
};

const actions = {
    // points 轉帳出遊戲商
    balanceToVendor({
        commit
    }, data) {
        return new Promise(async (resolve, reject) => {
            let md5_key = Global.$API_MD5_KEY;
            let lang = localStorage.getItem("LOCALE");
            let token = [REDACTED]"token");
            let jsonMsg = JSON.stringify({
                vendor_id: data.vendor_id,
                point: data.point
            });

            let params = secret.Encrypt(jsonMsg);
            let key = md5(params + md5_key);
            axios
                .post(
                    Global.$API_URL + "api/balanceToVendor", {
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
                    if (data.isDeposit) {
                        // 平台餘額轉帳至遊戲商餘額
                        // 進遊戲餘額不足
                        if (res.data.code == 1000) {
                            // 1000 = 成功
                            // 平台餘額
                            window.sessionStorage.setItem(
                                "balance",
                                res.data.data.balance
                            );
                            // 遊戲商餘額
                            window.sessionStorage.setItem(
                                "vendor_balance",
                                res.data.data.vendor_balance
                            );
                            let id = data.game_id;
                            if (!id) {
                                router.push("/Game");
                            } else if (id != "Android" && id != "IOS") {
                                router.push("/Game?id=" + id);
                            } else if (id == "Android" || id == "IOS") {
                                if (id == "Android") {
                                    window.open(
                                        store.state.GameApp.vendor_info
                                        .android_download
                                    );
                                } else if (id == "IOS") {
                                    window.open(
                                        store.state.GameApp.vendor_info
                                        .ios_download
                                    );
                                }
                            }
                        } else {
                            let resData = {
                                code: res.data.code,
                                msg: res.data.message
                            };
                            commit(types.SHOW_API_RES, resData);
                        }
                    } else {
                        // 一般points轉點
                        if (res.data.code == 1000) {
                            resolve(true);
                            let obj = res.data.data;
                            obj["vendor_id"] = data.vendor_id;
                            obj["vWorld_balance"] = data.vendor_id;
                            window.sessionStorage.setItem(
                                "balance",
                                res.data.data.balance
                            );
                            window.sessionStorage.setItem(
                                "vendor_balance",
                                res.data.data.vendor_balance
                            );
                            commit(types.GET_TRANSFER_TO_VENDOR, obj);

                            resolve(true); // 點數全轉入單一遊戲開關,成功回傳true
                        } else {
                            let resData = {
                                code: res.data.code,
                                msg: res.data.message
                            };
                            commit(types.SHOW_API_RES, resData);
                        }
                    }
                })
                .catch(error => {
                    console.log("error", error);
                });
        });
    },

    // points 轉帳入平台
    vendorToBalance({
        commit
    }, data) {
        return new Promise(async (resolve, reject) => {
            let md5_key = Global.$API_MD5_KEY;
            let lang = localStorage.getItem("LOCALE");
            let token = [REDACTED]"token");
            let jsonMsg = JSON.stringify({
                vendor_id: data.vendor_id,
                point: data.point
            });
            let params = secret.Encrypt(jsonMsg);
            let key = md5(params + md5_key);
            axios
                .post(
                    Global.$API_URL + "api/vendorToBalance", {
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
                    // 遊戲商餘額轉帳至平台餘額
                    if (res.data.code == 1000) {
                        resolve(true);
                        let obj = res.data.data;
                        obj["vendor_id"] = data.vendor_id;
                        window.sessionStorage.setItem(
                            "balance",
                            res.data.data.balance
                        );
                        window.sessionStorage.setItem(
                            "vendor_balance",
                            res.data.data.vendor_balance
                        );
                        commit(types.GET_TRANSFER_TO_ACCT, obj);
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
        });
    },
    // 單一錢包
    singleWalletBalance({
        commit
    }, data) {
        return new Promise(async (resolve, reject) => {
            let md5_key = Global.$API_MD5_KEY;
            let lang = localStorage.getItem("LOCALE");
            let token = [REDACTED]"token");
            let jsonMsg = JSON.stringify({
                vendor_id: data
            });
            let params = secret.Encrypt(jsonMsg);
            let key = md5(params + md5_key);

            axios
                .post(
                    Global.$API_URL + "api/singleWalletBalance", {
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
                        let singleBalance = res.data.data;

                        commit(types.GET_SINGLE_WALLET_BALANCE, singleBalance);

                        resolve(true); // 點數全轉入單一遊戲開關,成功回傳true

                        //順手刷新畫面上的數字
                        store.dispatch("actionUpdateHeaderVendorPoint", singleBalance);
                        store.dispatch("actionMember");

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
        });
    }
};

const mutations = {
    [types.GET_TRANSFER_TO_VENDOR](state, val) {
        let id = val.vendor_id;
        if (store.state.Vendors.vendors) {
            store.state.Vendors.vendors[id].balance = val.vendor_balance;
        }
        state.newVpoints = val.balance;
        state.newVendorBalance = val.vendor_balance;
        sessionStorage.setItem("balance", val.balance);
        sessionStorage.setItem("POINT_OK", "OK");
    },
    [types.GET_TRANSFER_TO_ACCT](state, val) {
        let id = val.vendor_id;
        if (store.state.Vendors.vendors) {
            store.state.Vendors.vendors[id].balance = val.vendor_balance;
        }
        // 遊戲商目前點數
        state.newVpoints = val.balance;
        // 剩餘的總POINT
        state.newVendorBalance = val.vendor_balance;

        sessionStorage.setItem("balance", val.balance);

        sessionStorage.setItem("POINT_OK", "OK");
    },
    [types.GET_SINGLE_WALLET_BALANCE](state, singleBalance) {
        state.singleBalance = singleBalance;
    }
};

const getters = {
    getNewvpoints: state => state.newVpoints,
    getNew_VendorBalance: state => state.newVendorBalance,
    get_singleBalance: state => state.singleBalance
};
export default {
    state,
    actions,
    mutations,
    getters
};
