import Global from '@/Global'
import * as mu_types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import value_utils from '@/util/value_utils'
import md5 from 'js-md5'
import {
  resolve
} from 'path'

const state = {
  listFlowAmount: [],
}

const actions = {
  // 監聽聊天列
  action_get_flowAmount_list({
    commit
  }, payload) {

    let token = [REDACTED]'user_token');
    let md5_key = Global.$API_MD5_KEY

    //member_id 、user_id 、page、per_page 
    let jsonMsg = JSON.stringify(payload)
    let params = secret.Encrypt(jsonMsg)
    let key = md5(params + md5_key)
    let headers = value_utils.GetGlobalRequestHeader();


    // var fakeData = [{
    //     type: '遊戲流水',
    //     bonus: 100,
    //     flow_limit: 200,
    //     valid_bet: 200,
    //     status: 'success',
    //     created_at: '2020-05-14 03:07:05',
    //     updated_at: '2020-05-10 14:55:00',
    //   },
    //   {
    //     type: '人工建立',
    //     bonus: 7500,
    //     flow_limit: 1200,
    //     valid_bet: 500,
    //     status: 'fail',
    //     created_at: '2020-05-10 11:13:45',
    //     updated_at: '2020-05-12 22:05:00',
    //   },
    //   {
    //     type: '人工建立',
    //     bonus: 925,
    //     flow_limit: 925,
    //     valid_bet: 925,
    //     status: 'success',
    //     created_at: '2020-05-10 11:13:45',
    //     updated_at: '2020-05-12 22:05:00',
    //   },
    //   {
    //     type: '遊戲流水',
    //     bonus: 2500,
    //     flow_limit: 1200,
    //     valid_bet: 0,
    //     status: 'fail',
    //     created_at: '2020-05-10 11:13:45',
    //     updated_at: '2020-05-12 22:05:00',
    //   },
    //   {
    //     type: '人工建立',
    //     bonus: 3350,
    //     flow_limit: 3350,
    //     valid_bet: 100,
    //     status: 'success',
    //     created_at: '2020-05-10 11:13:45',
    //     updated_at: '2020-05-12 22:05:00',
    //   },
    //   {
    //     type: '人工建立',
    //     bonus: 2500,
    //     flow_limit: 1200,
    //     valid_bet: 0,
    //     status: 'success',
    //     created_at: '2020-05-10 11:13:45',
    //     updated_at: '2020-05-12 22:05:00',
    //   }
    // ];

    //commit(mu_types.GET_FLOW_AMOUNT_LIST, fakeData);


    axios
      .post(
        Global.$API_URL + 'api/member/flowLimit', {
          params: params,
          key: key,
        }, {
          headers: headers
        }
      )
      .then(res => {

        console.log(res);


        if (res.data.code == 1000) {

          let roomList = res.data.data
          commit(mu_types.GET_FLOW_AMOUNT_LIST, roomList);

        } else {
          alert(res.data.message)
        }

      })
      .catch(error => {
        console.log(error)
      })

  }

}

const mutations = {
  [mu_types.GET_FLOW_AMOUNT_LIST](state, res) {

    let allPageData = [];

    res.forEach(element => {

      let newInstance = {
        type: element.type,
        depositAmount: element.bonus,
        limit_flowAmount: element.flow_limit,
        valid_flowAmount: element.valid_bet,
        status: element.status,
        date: element.created_at,
        operation_time: element.updated_at,
      }

      allPageData.push(newInstance);
    });

    state.listFlowAmount = allPageData


  }
}

const getters = {
  get_flowAmount_list: state => state.listFlowAmount,
}
export default {
  state,
  actions,
  mutations,
  getters,
}