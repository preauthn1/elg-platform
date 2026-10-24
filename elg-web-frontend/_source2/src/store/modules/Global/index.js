import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    blockUI: false,
    api_request: {
        isVisible: false,
        code: "",
        data: "",
        msg: "",
        type: ""
    }
};

const actions = {

    actionShowBlockUI({}) {

        this.commit("closeBlockUI", true);
    },

    actionCloseBlockUI({}) {

        this.commit("closeBlockUI", false);
    }

};

const mutations = {
    closeBlockUI(state, val) {
        state.blockUI = val;
    },
    //api request 共用彈窗資訊
    [types.SHOW_API_RES](state, data) {
        state.api_request.isVisible = true;
        state.api_request.code = data.code;
        state.api_request.msg = data.msg;
        state.api_request.type = data.type;
    },
    // 關閉彈窗
    [types.HIDE_API_RES](state) {
        state.api_request.isVisible = false;
    }
};

const getters = {
    getApi_request: state => state.api_request
};
export default {
    state,
    actions,
    mutations,
    getters
};
