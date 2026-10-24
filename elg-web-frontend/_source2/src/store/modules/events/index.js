import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import router from "@/router";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    events_total: "", // 活動總筆數
    events: [], // 活動列表
    eventDESC: [], // 活動內容
    prize: [], // 獎項
    prizes: [], // 獎項列表 (轉輪)
    prize_record: [], //兌獎紀錄
    isPrize: false, // 直接兌獎彈窗 events.type=3,
    isBanner: false, //banner lottery彈窗
    isCard: false, //banner 刮刮卡彈窗
    events_reddeem: "", //event按鈕驚嘆號,
    event_LotteryNumber: "", //樂透號碼
    event_MyLotteryNumber: "" //自己的樂透號碼
};

const actions = {
    // 活動列表
    action_EventList({ commit }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            page: 1,
            per_page: 30
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        if (token) {
            axios
                .post(
                    Global.$API_URL + "api/events",
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
                        // 1000=成功
                        let events = res.data.data;
                        let total = res.data.pagination.total;
                        let reddeem = res.data.is_reddeem;
                        // 成功 COMMIT 總筆數
                        commit(types.GET_EVENTS_TOTAL, total);
                        // 成功 COMMIT 是否可兌換獎勵
                        commit(types.GET_EVENTS_REDDEEM, reddeem);
                        // 成功 COMMIT 活動列表
                        commit(types.GET_EVENTS_LIST, events);
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
    },

    // 活動內容說明
    action_EventDESC({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            id: id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/event",
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
                    // 1000 = 成功
                    let event = res.data.data;
                    // 成功 commit 活動詳情
                    commit(types.GET_EVENTS_DESC, event);
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
    // 摸彩中獎名單
    action_LotteryNumbers({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            event_id: id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/lotteryNumbers",
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
                    // 1000=成功
                    let lotteryNumbers = res.data.data;
                    // 成功 commit 抽獎活動的中獎編號
                    commit(types.GET_LOTTERY_NUMBERS, lotteryNumbers);
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
    // 自己的摸彩券
    action_MyLotteryNumbers({ commit }, id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            event_id: id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/myLotteryNumbers",
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
                    // 1000 = 成功
                    let MylotteryNumbers = res.data.data;
                    // 成功 commit 自己摸彩券資料及中獎狀態
                    commit(types.GET_MY_LOTTERY_NUMBERS, MylotteryNumbers);
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
    // 取中獎項目
    action_GetPrize({ commit }, eventData) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            event_id: eventData.event_id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        // 真資料註解
        axios
            .post(
                Global.$API_URL + "api/getPrize",
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
                    // 1000 = 成功
                    let prizeInfo = res.data.data;
                    // 成功 commit 中獎項目
                    commit(types.GET_PRIZE, prizeInfo);
                    // type:1 刮刮卡
                    if (eventData.type == 1) {
                        setTimeout(() => {
                            var bannerOpen = sessionStorage.getItem(
                                "bannerOpen"
                            );
                            // PC版 彈窗
                            if (bannerOpen == 1) {
                                commit(types.SHOW_CARD_OPEN);
                            } else {
                                // MOBILE
                                // 傳跳刮刮卡
                                router.push("/ScratchCard");
                            }
                        }, 100);
                    }
                    // type:2 輪盤
                    else if (eventData.type == 2) {
                        let prizes = prizeInfo.prizes;
                        commit(types.GET_PRIZES, prizes);

                        setTimeout(() => {
                            var bannerOpen = sessionStorage.getItem(
                                "bannerOpen"
                            );
                            // PC版 彈窗
                            if (bannerOpen == 1) {
                                commit(types.SHOW_BANNER_OPEN);
                            } else {
                                // MOBILE
                                router.push("/Lottery");
                            }
                        }, 100);
                    }
                    // type:3 直接兌獎
                    else if (eventData.type == 3) {
                        commit(types.SHOW_IS_PRIZE);
                        let prize_member = prizeInfo.prize.prize_member;
                        store.dispatch("action_SetPrize", prize_member);
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
        // 活動假資料開始;
        // var fake_getPrize = [
        //     {
        //         id: 30,
        //         event_id: 31,
        //         type_id: 2,
        //         name: "\u6e2c\u8a66",
        //         background:
        //             "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156765173322.png",
        //         number: 1,
        //         expire_date: "2020-12-31 23:59:59",
        //         is_repeatedly: 0
        //     }
        // ];
        // commit(types.GET_PRIZE, fake_getPrize);
        // var fake_prizes = [
        //     {
        //         id: 392,
        //         name: "v-point 300 \u9ede",
        //         point: 1,
        //         percentage: 80,
        //         prize_member: 1
        //     },
        //     { id: 393, name: "v-point 100\u9ede", point: 1, percentage: 5 },
        //     {
        //         id: 394,
        //         name: "\u9298\u8b1d\u60e0\u9867",
        //         point: 0,
        //         percentage: 15
        //     },
        //     {
        //         id: 394,
        //         name: "\u9298\u8b1d\u60e0\u9867",
        //         point: 0,
        //         percentage: 15
        //     },
        //     {
        //         id: 394,
        //         name: "\u9298\u8b1d\u60e0\u9867",
        //         point: 0,
        //         percentage: 15
        //     },
        //     { id: 395, name: "iPhone XR", point: 1, percentage: 1 }
        // ];
        // var fake_prize_member = 1;

        // if (eventData.type == 3) {
        //     setTimeout(() => {
        //         var bannerOpen = sessionStorage.getItem("bannerOpen");
        //         if (bannerOpen == 1) {
        //             commit(types.SHOW_CARD_OPEN);
        //             // router.push("/Lottery");
        //         } else {
        //             router.push("/ScratchCard");
        //         }
        //     }, 100);
        // } else if (eventData.type == 2) {
        //     // let prizes = prizeInfo.prizes;
        //     commit(types.GET_PRIZES, fake_prizes);

        //     setTimeout(() => {
        //         var bannerOpen = sessionStorage.getItem("bannerOpen");
        //         if (bannerOpen == 1) {
        //             commit(types.SHOW_BANNER_OPEN);
        //             // router.push("/Lottery");
        //         } else {
        //             router.push("/Lottery");
        //         }
        //     }, 100);
        // } else if (eventData.type == 1) {
        //     commit(types.SHOW_IS_PRIZE);
        //     // let prize_member = prizeInfo.prize.prize_member;
        //     store.dispatch("action_SetPrize", fake_prize_member);
        // }
        // 活動假資料結束;
    },
    // 兌獎回傳
    action_SetPrize({ commit }, prize_member_id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            prize_member_id: prize_member_id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/setPrize",
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
                    // 設定中獎項目為已領取
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
    // 活動紀錄
    action_PrizeRecords({ commit }) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            page: 1,
            per_page: 20
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/prizes",
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
                    // 成功 commit 取得中獎列表
                    commit(types.GET_PRIZE_RECORD, res.data.data);
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
    [types.GET_EVENTS_TOTAL](state, total) {
        state.events_total = total;
    },
    [types.GET_EVENTS_REDDEEM](state, reddeem) {
        state.events_reddeem = reddeem;
    },
    [types.GET_EVENTS_LIST](state, events) {
        state.events = events;
    },

    [types.GET_EVENTS_DESC](state, event) {
        state.eventDESC = event;
    },
    [types.GET_LOTTERY_NUMBERS](state, lotteryNumbers) {
        state.event_LotteryNumber = lotteryNumbers;
    },
    [types.GET_MY_LOTTERY_NUMBERS](state, MylotteryNumbers) {
        state.event_MyLotteryNumber = MylotteryNumbers;
    },
    [types.GET_PRIZE](state, prize) {
        state.prize = prize;
    },
    [types.GET_PRIZES](state, prizes) {
        let newPrizes = [];
        let i = 1;
        prizes.forEach(element => {
            // let color = "#FDB451";
            // if (i % 2 == 0) {
            //     color = "#D83B3D";
            // }
            // let item = {};
            // item["id"] = element.id;
            // item["title"] = element.name;
            // item["color"] = color;
            // newPrizes.push(item);
            // i++;
            // 漸層用

            let color1 = "#E33E3E";
            let color2 = "#A01517";
            // let color2 = "green";
            if (i % 2 == 0) {
                color1 = "#FDCF51";
                color2 = "#ED8949";
                // color2 = "green";
            }
            let item = {};
            item["id"] = element.id;
            item["title"] = element.name;
            item["color1"] = color1;
            item["color2"] = color2;
            // item["color3"] = color3;

            newPrizes.push(item);
            i++;
        });
        state.prizes = newPrizes;
    },
    // eventsDESCRIPTION BANNER LOTTERY彈窗控制
    [types.SHOW_BANNER_OPEN](state, id) {
        state.isBanner = true;
    },
    [types.HIDE_BANNER_OPEN](state) {
        state.isBanner = false;
    },
    // eventsDESCRIPTION BANNER 刮刮卡彈窗控制
    [types.SHOW_CARD_OPEN](state, id) {
        state.isCard = true;
    },
    [types.HIDE_CARD_OPEN](state) {
        state.isCard = false;
    },
    // events.vue 直接兌獎彈窗控制
    [types.SHOW_IS_PRIZE](state, id) {
        state.isPrize = true;
    },
    [types.HIDE_IS_PRIZE](state) {
        state.isPrize = false;
    },
    // 中獎紀錄
    [types.GET_PRIZE_RECORD](state, record) {
        state.prize_record = record;
    }
};

const getters = {
    get_EventsTotal: state => state.events_total,
    get_EventsReddeem: state => state.events_reddeem,
    get_Events: state => state.events,
    get_EventDESC: state => state.eventDESC,
    get_LotteryNumbers: state => state.event_LotteryNumber,
    get_MyLotteryNumbers: state => state.event_MyLotteryNumber,
    get_Prize: state => state.prize,

    get_Prizes: state => state.prizes,
    get_Prize_Record: state => state.prize_record,
    get_isPrize: state => state.isPrize,
    get_isBanner: state => state.isBanner,
    get_isCard: state => state.isCard
};
export default {
    state,
    actions,
    mutations,
    getters
};
