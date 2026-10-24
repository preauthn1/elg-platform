import Global from '@/Global'
import * as types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'

const state = {
  member: [],
  isJoinEvent: false,
}

const actions = {
  action_memberView({commit}, member_id) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        member_id: member_id,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/member/view',
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
            commit(types.GET_MEMBER_VIEW, res.data.data)
            let memberView = res.data.data
            // console.log('memberView', memberView)
            sessionStorage.setItem('EventMode', memberView.event_mode)
            // console.log('記TRANSACTION ALL ==1')
            sessionStorage.setItem(
              'transaction_all',
              memberView.transaction_all
            )
            if (memberView.transaction_all != 1) {
              //   console.log('記TRANSACTION ALL ==0')
              sessionStorage.setItem('transaction_all', 0)
            }
            // console.log('session', sessionStorage.getItem('EventMode'))
            if (memberView.event_mode == 1) {
              commit(types.SHOW_JOIN_EVENT)
              sessionStorage.setItem('EventMode', memberView.event_mode)
            }
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
  },
}

const mutations = {
  [types.GET_MEMBER_VIEW](state, member) {
    state.member = member
    // console.log('member',state.member)
  },
  // 鎖分模式 禁止開洗分
  [types.SHOW_JOIN_EVENT](state) {
    state.isJoinEvent = true
  },
  [types.HIDE_JOIN_EVENT](state) {
    state.isJoinEvent = false
  },
}

const getters = {
  get_member: state => state.member,
  get_checkEvent: state => state.isJoinEvent,
}
export default {
  state,
  actions,
  mutations,
  getters,
}
