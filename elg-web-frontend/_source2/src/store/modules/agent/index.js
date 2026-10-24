import Global from "@/Global";
import * as mu_type from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import value_utils from "@/utils/value_utils";
import md5 from "js-md5";

const state = {
    testValue: 'before',

    agentRuleLevels: [],
    agentWithdrawalHistory: [],

    agentMyRewardList: [],
    agentMyPlayerList: [],

    agentHomePageSummary: {
        parentAgentName: '',
        agentName: '',
        totalTeamMemberCount: 0,
        directPlayerCount: 0,
        todayNewPlayer: 0,
        todayActivePlayer: 0,
        shareLink: '',
        shareCode: ''
    },

    applyWithdrawalSummary: {
        account: 'tttest',
        totalScore: 0,
        applyWithdrawalAmount: 0,
        availableWithdrawalAmount: 0,
    }
};



const actions = {



    actionShowPopMsgByText({}, msg) {
        let resData = {
            code: 1000,
            msg: msg
        };

        this.commit(mu_type.SHOW_API_RES, resData);
    },


    actionShowPopMsg({}, res) {
        let resData = {
            code: res.data.code,
            msg: res.data.message
        };

        this.commit(mu_type.SHOW_API_RES, resData);
    },

    actionCommitAgentSummaryInfo({}, originalResponse) {

        this.commit(mu_type.GET_AGENT_SUMMARY_INFO, originalResponse.data.data);
    },


    //代理首頁
    actionGetAgentSummary({}) {

        return new Promise((resolve, reject) => {

            var alwaysHeader = value_utils.GetGlobalRequestHeader();

            let domainUrl = location.hostname;
            console.log(domainUrl);

            let md5_key = Global.$API_MD5_KEY;
            let jsonMsg = JSON.stringify({
                domain: domainUrl
            });

            let params = secret.Encrypt(jsonMsg);
            let key = md5(params + md5_key);
            let url = Global.$API_URL + "api/unlimit";

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

                    //vue那邊處理
                    resolve(res);

                })
                .catch(error => {
                    console.log("error", error);
                });

        });

    },


    //提現總覽
    actionGetWithdrawalSummary({}) {

        var alwaysHeader = value_utils.GetGlobalRequestHeader();

        let md5_key = Global.$API_MD5_KEY;
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        let url = Global.$API_URL + "api/unlimit/withdrawRecode";

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

                if (res.data.code == 1000) {

                    this.commit(mu_type.GET_AGENT_MY_WITHDRAWAL_SUMMARY, res.data.data);

                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    this.commit(mu_type.SHOW_API_RES, resData);
                }
            })
            .catch(error => {
                console.log("error", error);
            });

    },

    //提現歷史紀錄
    actionGetWithdrawalHistory({}) {

        var alwaysHeader = value_utils.GetGlobalRequestHeader();

        let md5_key = Global.$API_MD5_KEY;
        let jsonMsg = JSON.stringify({
            page: 1,
            per_page: 9999
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        let url = Global.$API_URL + "api/unlimit/history";

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

                if (res.data.code == 1000) {

                    this.commit(mu_type.GET_AGENT_MY_WITHDRAWAL_HISTORY, res.data.data);

                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    this.commit(mu_type.SHOW_API_RES, resData);
                }
            })
            .catch(error => {
                console.log("error", error);
            });

    },


    actionApplyWithdrawal({}, postPara) {

        return new Promise((resolve, reject) => {

            var alwaysHeader = value_utils.GetGlobalRequestHeader();
            let md5_key = Global.$API_MD5_KEY;

            let jsonMsg = JSON.stringify(postPara);

            let params = secret.Encrypt(jsonMsg);
            let key = md5(params + md5_key);
            let url = Global.$API_URL + "api/unlimit/withdraw";

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

                    //vue那邊處理
                    resolve(res);

                })
                .catch(error => {
                    console.log("error", error);
                });



        });

    },



    actionGetAgentPolicy({}) {

        var alwaysHeader = value_utils.GetGlobalRequestHeader();
        let md5_key = Global.$API_MD5_KEY;
        let params = secret.Encrypt("");
        let key = md5(params + md5_key);
        let url = Global.$API_URL + "api/unlimit/list";

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

                if (res.data.code == 1000) {
                    this.commit(mu_type.GET_AGENT_UNLIMIT_RULE_LEVELS, res.data.data);
                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    this.commit(mu_type.SHOW_API_RES, resData);
                }
            })
            .catch(error => {
                console.log("error", error);
            });

    },


    //我的玩家
    actionGetMyPlayers({}) {

        var alwaysHeader = value_utils.GetGlobalRequestHeader();
        let md5_key = Global.$API_MD5_KEY;
        let jsonMsg = JSON.stringify({
            page: 1,
            per_page: 9999
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);

        axios
            .post(
                Global.$API_URL + "api/unlimit/directPlayers", {
                    params: params,
                    key: key
                }, {
                    headers: alwaysHeader
                }
            )
            .then(res => {

                if (res.data.code == 1000) {
                    this.commit(mu_type.GET_AGENT_MY_PLAYERS, res.data.data);
                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    this.commit(mu_type.SHOW_API_RES, resData);
                }
            })
            .catch(error => {
                console.log("error", error);
            });

    },

    actionGetMyScore({}) {

        var alwaysHeader = value_utils.GetGlobalRequestHeader();
        let md5_key = Global.$API_MD5_KEY;
        let jsonMsg = JSON.stringify({
            page: 1,
            per_page: 9999
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);

        axios
            .post(
                Global.$API_URL + "api/unlimit/reward", {
                    params: params,
                    key: key
                }, {
                    headers: alwaysHeader
                }
            )
            .then(res => {

                if (res.data.code == 1000) {
                    this.commit(mu_type.GET_AGENT_MY_REWARD, res.data.data);
                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    this.commit(mu_type.SHOW_API_RES, resData);
                }
            })
            .catch(error => {
                console.log("error", error);
            });

    }



};

const mutations = {
    [mu_type.GET_AGENT_SUMMARY_INFO](state, res) {

        state.agentHomePageSummary.parentAgentName = res.topUsername;
        state.agentHomePageSummary.agentName = res.username;;
        state.agentHomePageSummary.totalTeamMemberCount = res.teamCount;;
        state.agentHomePageSummary.directPlayerCount = res.directPlayerCount;;
        state.agentHomePageSummary.todayNewPlayer = res.addedToday;;
        state.agentHomePageSummary.todayActivePlayer = res.activeToday;
        state.agentHomePageSummary.shareLink = res.link;
        state.agentHomePageSummary.shareCode = res.code;
    },

    [mu_type.GET_AGENT_UNLIMIT_RULE_LEVELS](state, res) {

        let allLevels = [];

        res.forEach(element => {

            let newInstance = {
                level: element.level,
                min: element.minAmount,
                max: element.maxAmount,
                rebate: element.rebateRate,
                bounsAmount: element.bounsAmount,
            }
            allLevels.push(newInstance);
        });

        state.agentRuleLevels = allLevels
    },

    [mu_type.GET_AGENT_MY_PLAYERS](state, res) {

        let allPageData = [];

        res.forEach(element => {

            let newInstance = {
                account: element.member,
                todayTeamScore: element.teamContribution,
                todayPlayerScore: element.memberContribution,
                teamCount: element.teamCount,
                directPlayerCount: element.memberCount,
                type: element.type,
            }

            allPageData.push(newInstance);
        });

        state.agentMyPlayerList = allPageData
    },

    [mu_type.GET_AGENT_MY_REWARD](state, res) {

        let allPageData = [];

        res.forEach(element => {

            let newInstance = {
                date: element.date,
                totalContribution: element.totalContribution,
                teamContribution: element.teamContribution,
                memberContribution: element.memberContribution,
                reward: element.reward,
                type: element.type,
            }

            allPageData.push(newInstance);
        });

        state.agentMyRewardList = allPageData
    },

    [mu_type.GET_AGENT_MY_WITHDRAWAL_SUMMARY](state, res) {

        state.applyWithdrawalSummary.account = res.username
        state.applyWithdrawalSummary.totalScore = res.totalRewardPoints
        state.applyWithdrawalSummary.applyWithdrawalAmount = res.withdrawnPoints
        state.applyWithdrawalSummary.availableWithdrawalAmount = res.withdrawablePoints

    },

    [mu_type.GET_AGENT_MY_WITHDRAWAL_HISTORY](state, res) {

        let allPageData = [];

        res.forEach(element => {

            let newInstance = {
                date: element.date,
                orderNo: element.no,
                amount: element.amount,
                type: element.withdrawType,
                status: element.type,
            }

            allPageData.push(newInstance);
        });

        state.agentWithdrawalHistory = allPageData


    }
};

const getters = {

    getAgentMyPlayerList: state => state.agentMyPlayerList,
    getAgentMyRewardList: state => state.agentMyRewardList,
    getAgentHomePageSummary: state => state.agentHomePageSummary,
    getAgentRuleLevels: state => state.agentRuleLevels,
    getApplyWithdrawalSummary: state => state.applyWithdrawalSummary,
    getAgentWithdrawalHistory: state => state.agentWithdrawalHistory,
};




export default {
    state,
    actions,
    mutations,
    getters
};
