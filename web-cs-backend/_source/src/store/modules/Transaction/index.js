import Global from '@/Global'
import * as types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'

const state = {
  transaction_List: [],
  redEnvelope_List: [],
  is_addSuccess: false,
}

const actions = {
  // 紅包紀錄
  action_RedEnvelopeList({commit}, data) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        username: data.username,
        start_time: data.start_time,
        end_time: data.end_time,
        page: 1,
        per_page: 1000,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/redEnvelope/record',
          {
            params: params,
            key: key,
          },
          {
            headers: {
              'Accept-Language': Global.$LOCALE_LANG,
              Authorization: 'Bearer ' + token,
            },
          }
        )
        .then(res => {
          if (res.data.code == 1000) {
            commit(types.GET_REDENVELOPE_LIST, res.data.data)
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
  },
  // 紀錄列表
  action_TransactionList({commit}, data) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        username: data.username,
        // "status": data.status,
        start_time: data.start_time,
        end_time: data.end_time,
        page: 1,
        per_page: 1000,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/transaction/list',
          {
            params: params,
            key: key,
          },
          {
            headers: {
              'Accept-Language': Global.$LOCALE_LANG,
              Authorization: 'Bearer ' + token,
            },
          }
        )
        .then(res => {
          if (res.data.code == 1000) {
            commit(types.GET_TRANSACTION_LIST, res.data.data)
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
  },
  // 新增開洗分
  action_Transaction_Add({commit}, data) {
    console.log('data', data)
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        member_id: data.member_id,
        type: data.type,
        amount: data.amount,
        remark: data.remark,
        platform: data.platform,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/transaction/add',
          {
            params: params,
            key: key,
          },
          {
            headers: {
              'Accept-Language': Global.$LOCALE_LANG,
              Authorization: 'Bearer ' + token,
            },
          }
        )
        .then(res => {
          if (res.data.code == 1000) {
            commit('Add_dialog_Success') // 開洗分成功彈窗
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
  },
  // 編輯
  action_Transaction_Edit({commit}, data) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        transaction_id: data.transaction_id,
        remark: data.remark,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/transaction/edit',
          {
            params: params,
            key: key,
          },
          {
            headers: {
              'Accept-Language': Global.$LOCALE_LANG,
              Authorization: 'Bearer ' + token,
            },
          }
        )
        .then(res => {
          if (res.data.code == 1000) {
            console.log(res.data)
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log('Transaction_Edit', error)
        })
    }
  },
}

const mutations = {
  [types.GET_TRANSACTION_LIST](state, historyList) {
    state.transaction_List = historyList
  },
  [types.GET_REDENVELOPE_LIST](state, historyList) {
    state.redEnvelope_List = historyList
  },

  /// 加開洗分成功窗
  Add_dialog_Success(state) {
    state.is_addSuccess = true
  },
  Add_dialog_close(state) {
    state.is_addSuccess = false
  },
}

const getters = {
  get_transaction_List: state => state.transaction_List,
  get_redEnvelope_List: state => state.redEnvelope_List,
}
export default {
  state,
  actions,
  mutations,
  getters,
}
