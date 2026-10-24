import Global from '@/Global'
import * as types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'
import {type} from 'os'
import {resolve} from 'url'
import {reject} from 'q'

const state = {
  userList: [],
  userPages: [],
  userView: [],
  // selfInfo: {},
}

const actions = {
  // 營運商/客服列表
  action_UserList({commit}, data) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      // let Page
      // if(data == undefined){
      //     Page = 1
      // } else {
      //     Page = data.page
      // }
      // console.log('role_id',data.role_id)
      let jsonMsg = JSON.stringify({
        role_id: data.role_id,
        page: 1,
        per_page: 50000,
        // "username": data.username ,"nickname": data.nickname,"page": Page, "per_page": 100
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/user/list',
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
            commit(types.GET_USER_LIST, res.data.data)
            // commit(types.GET_USER_LIST, res.data)
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
  },
  // 建立營運商/客服
  action_AddUser({commit}, user) {
    let token = [REDACTED]'user_token')
    if (token) {
      // return new Promise (async (resolve, reject)=> {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        username: user.username,
        nickname: user.nickname,
        password: [REDACTED],
        password_confirmation: user.passConfirm,
        thumbnail: user.thumbnail,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/user/add',
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
            alert('建立成功')
            // 角色編號role_id 判斷是代理還是客服來撈取UserList
            let user_list_role = window.location.pathname == '/agent' ? 2 : 3
            store.dispatch('action_UserList', {role_id: user_list_role})
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
      // })
    }
  },
  // 凍結營運商/客服
  action_DeleteUser({commit}, userID) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        id: userID,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/user/delete',
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
            // 角色編號role_id 判斷是代理還是客服來撈取UserList
            let user_list_role = window.location.pathname == '/agent' ? 2 : 3
            store.dispatch('action_UserList', {role_id: user_list_role})
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
  },
  // 編輯營運商/客服
  action_EditUser({commit}, edit) {
    let token = [REDACTED]'user_token')
    if (token) {
      //   console.log('edit.withdraw_confirm:', edit.withdraw_confirm)
      //   console.log('edit.status:', edit.status)
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        id: edit.id,
        nickname: edit.nickname,
        password: [REDACTED],
        password_confirmation: edit.passConfirm,
        thumbnail: edit.thumbnail,
        status: edit.status.toString(),
        withdraw_confirm: edit.withdraw_confirm.toString(),
      })
      //   console.log('jsonMsg:', jsonMsg)
      let params = secret.Encrypt(jsonMsg)
      //   console.log('params', params)
      //   console.log('secret.Decrypt', secret.Decrypt(params))
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/user/edit',
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
            alert('編輯成功')
            // 角色編號role_id 判斷是代理還是客服來撈取UserList
            let user_list_role = window.location.pathname == '/agent' ? 2 : 3
            store.dispatch('action_UserList', {role_id: user_list_role})
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log('EditUser', error)
        })
    }
  },
  // 管理員開洗分
  action_UserTransaction({commit}, data) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        id: data.id,
        amount: data.amount,
        remark: data.remark,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/user/transaction',
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
            // 角色編號role_id 判斷是代理還是客服來撈取UserList
            let user_list_role = window.location.pathname == '/agent' ? 2 : 3
            store.dispatch('action_UserList', {role_id: user_list_role})
            // console.log('router',window.location.pathname)
            store.dispatch('action_UserView', sessionStorage.getItem('user_id'))
            alert('Transaction Success')
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log('EditUser', error)
        })
    }
  },
  // user view
  action_UserView({commit}, id) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        id: id,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/user/view',
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
            // console.log('view:', res.data.data)
            let userInfo = res.data.data
            // sessionStorage.setItem('user_id', userInfo.id)
            sessionStorage.setItem('role_id', userInfo.role_id)
            sessionStorage.setItem('user_name', userInfo.username)
            sessionStorage.setItem('member_username', userInfo.member_username)
            sessionStorage.setItem('user_nickname', userInfo.nickname)
            sessionStorage.setItem('user_avatar', userInfo.thumbnail)
            // sessionStorage.setItem('user_token', userInfo.token)
            sessionStorage.setItem('user_member_id', userInfo.member_id)
            // console.log('member_id:', sessionStorage.setItem('user_member_id'))
            sessionStorage.setItem('share_code', userInfo.share_code)
            // console.log('記住分享碼', sessionStorage.getItem('share_code'))
            sessionStorage.setItem('share_domain', userInfo.domain)
            commit(types.GET_USER_VIEW, userInfo)
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log('UserView', error)
        })
    }
  },
  //個人設定
  action_EditSelf({commit}, editSelf) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      if (!editSelf.thumbnail) {
        editSelf.thumbnail = ''
      }
      let jsonMsg = JSON.stringify({
        id: editSelf.id,
        nickname: editSelf.nickname,
        password: [REDACTED],
        password_confirmation: editSelf.passConfirm,
        thumbnail: editSelf.thumbnail,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/user/edit',
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
            sessionStorage.setItem('user_nickname', editSelf.nickname)
            sessionStorage.setItem('user_avatar', editSelf.thumbnail)
            alert('儲存成功')
            window.location.reload()
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log('EditSelf', error)
        })
    }
  },
  // Self Info
  action_SelfInfo({commit}, id) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        id: id,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/user/view',
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
            let self = res.data.data
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log('SelfInfo', error)
        })
    }
  },
  // 權限驗證
  action_Authority({commit}, url) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        url: url,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/userMenu/auth',
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
            // console.log(res.data)
          } else {
            // alert(res.data.message)
            router.push('/index') //驗證未過跳首頁
          }
        })
        .catch(error => {
          console.log('Authority', error)
        })
    }
  },
}

const mutations = {
  [types.GET_USER_LIST](state, userList) {
    state.userList = userList
    // state.userList = userList.data
    // state.userPages = userList.pagination
  },
  [types.GET_USER_VIEW](state, userView) {
    state.userView = userView
  },
  // [types.GET_SET_SELF](state, selfInfo) {
  //     state.selfInfo = selfInfo
  //     console.log(state.selfInfo)
  // }
}

const getters = {
  get_userList: state => state.userList,
  get_userPages: state => state.userPages,
  get_userView: state => state.userView,
  // get_selfInfo: state => state.selfInfo,
}
export default {
  state,
  actions,
  mutations,
  getters,
}
