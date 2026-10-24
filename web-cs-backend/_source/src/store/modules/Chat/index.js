import Global from '@/Global'
import * as types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'
import {resolve} from 'path'

const state = {
  friendList: [],
  friendPages: {},
  friendView: [],
  // roomList: [],
  roomList_show: [],
  roomList_hide: [],
  newMark: [], //newMark 放未讀的 ROOM CODE
  roomMsg: {
    message: [],
    room: {thumbnail: null},
  },
  listen_room: [], // 已監聽的room code
}

const actions = {
  // 監聽聊天列
  action_listen_list({commit}) {
    let md5Msg = md5(
      sessionStorage.getItem('user_member_id') +
        Global.$API_MD5_KEY +
        sessionStorage.getItem('member_username')
    )
    var channelAll = window.Echo.private('newMessage.' + md5Msg)
    channelAll.listen('NewMessage', function(data) {
      //   console.log('監聽', data)
      // 新訊息進來後，聊天室列表更新
      store.dispatch('action_RoomList_Status', 1)
      // 新訊息未讀，加上未讀標記
      commit(types.GET_ROOM_NEW_MARK, data)
    })
  },
  // 1.顯示/2.隱藏列表
  action_RoomList_Status({commit}, status) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        status: status,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/room/list',
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
            let roomList = res.data.data
            if (status == 1) {
              //狀態顯示的聊天室列表
              commit(types.GET_ROOM_LIST_SHOW, roomList)
            } else if (status == 2) {
              //狀態隱藏的聊天室列表
              commit(types.GET_ROOM_LIST_HIDE, roomList)
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
  // 進聊天
  action_RoomEnter({commit}, data) {
    let token = [REDACTED]'user_token')
    // console.log('token', token)
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        code: data.code,
        friend_id: data.friend_id,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/room/enter',
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
            // console.log('訊息', res.data.data)
            commit(types.GET_ROOM_MSG, res.data.data)

            // 好友表進聊天時 路由不含room_code，故跳轉加上room_code
            let current_room_id = router.currentRoute.params.room_id
            let current_member_id = router.currentRoute.params.member_id
            let current_room_code = router.currentRoute.params.room_code
            if (!current_room_code) {
              router.push(
                '/chat/messaging/' +
                  current_room_id +
                  '/' +
                  current_member_id +
                  '/' +
                  res.data.data.room.code
              )
            }
            // 監聽房間內訊息
            setTimeout(() => {
              let path = window.location.pathname.split('/')
              let roomId = path[3]
              let roomCode = path[5]
              if (state.listen_room.indexOf(roomCode) == -1) {
                //監聽的房間state.listen_room如未含現在的roomcode，則執行監聽房間
                commit('listenPush', roomCode)
                var channel = window.Echo.private('room.' + roomCode)
                channel.listen('MessageSent', function(data) {
                  //   console.log('roomCode:', roomCode)
                  //   console.log('roomCode11:', data.message.code)
                  if (
                    roomId == data.message.room_id &&
                    roomCode == data.message.code
                  ) {
                    //新進訊息的roomcode跟id必須為現在正開啟的房間，才能將新訊息推進聊天室
                    if (
                      data.message.member_id ==
                      sessionStorage.getItem('user_member_id')
                    ) {
                      data.message.is_self = 0
                    } else {
                      data.message.is_self = 1
                    }
                    // console.log(data.message, 'data.message')
                    commit(types.GET_NEW_MSG, data.message)
                  }
                })
              }
            }, 200)
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
  },
  // 聊天室隱藏
  action_RoomHide({commit}, code) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        code: code,
        status: 2,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      return new Promise(async (resolve, reject) => {
        axios
          .post(
            Global.$API_URL + 'api/room/changeStatus',
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
              resolve(true) // 非同步
              if (location.pathname !== '/chat/messaging') {
                //導回/chat/messaging等於關閉房間
                router.push('/chat/messaging')
              }
            } else {
              alert(res.data.message)
            }
          })
          .catch(error => {
            console.log(error)
          })
      })
    }
  },
  // 查歷史訊息
  action_RoomHistory({commit}, data) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        code: data.code,
        page: data.page,
        per_page: data.per_page,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/room/history',
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
            commit(types.GET_ROOM_HISTORY, res.data.data)
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
  },
  // 好友列表
  action_FriendList({commit}, data) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        status: data.status,
        username: data.username,
        page: data.page,
        per_page: 10,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/friend/list',
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
            commit(types.GET_FRIEND_LIST, res.data.data)
            commit(types.GET_FRIEND_PAGES, res.data.pagination)
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
  },
  // friend view
  action_FriendView({commit}, id) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        member_id: id,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/friend/view',
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
            let friendInfo = res.data.data
            commit(types.GET_FRIEND_VIEW, friendInfo)
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
  },
  // 編輯好友
  action_EditFriend({commit}, friend) {
    let token = [REDACTED]'user_token')
    if (token) {
      let md5_key = Global.$API_MD5_KEY
      let jsonMsg = JSON.stringify({
        nickname: friend.nickname,
        status: friend.status,
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/friend/edit',
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
            // console.log('EditFriend',res.data)
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log('EditFriend', error)
        })
    }
  },
}

const mutations = {
  [types.GET_FRIEND_LIST](state, friendList) {
    state.friendList = friendList
    // console.log('friendList',state.friendList)
  },
  [types.GET_FRIEND_PAGES](state, pages) {
    state.friendPages = pages
    // console.log('pages',state.friendPages)
  },
  [types.GET_FRIEND_VIEW](state, friendView) {
    state.friendView = friendView
    // console.log('friendView',state.friendView)
  },
  [types.GET_ROOM_LIST](state, roomList) {
    state.roomList = roomList
    // console.log('ROOMList',state.roomList)
  },
  [types.GET_ROOM_LIST_SHOW](state, roomList) {
    state.roomList_show = roomList
    // console.log('ROOMList',state.roomList)
  },
  [types.GET_ROOM_LIST_HIDE](state, roomList) {
    state.roomList_hide = roomList
    // console.log('ROOMList',state.roomList)
  },
  [types.GET_ROOM_MSG](state, roomMsg) {
    state.roomMsg = roomMsg
    // console.log('ROOMMsg',state.roomMsg)
  },
  /// 聊天歷史訊息
  [types.GET_ROOM_HISTORY](state, history) {
    // console.log('history',history)
    history.forEach(element => {
      let ary = state.roomMsg.message
      ary.unshift(element)
    })
  },
  //// 未讀訊息聊天mark標記
  [types.GET_ROOM_NEW_MARK](state, data) {
    // console.log('smae',window.location.pathname)
    // split 抓 ROOM CODE
    let room = window.location.pathname.split('/')[5]
    if (room !== data.message.code) {
      state.newMark.push(data.message.code)
      // 新訊息音訊
      console.log('sound')
      var sound = document.getElementById('audio')
      sound.play()
      //   提示音
    }
    // console.log('newcode', state.newMark)
  },
  /// 聊天室新進訊息
  [types.GET_NEW_MSG](state, newMsg) {
    let path = window.location.pathname.split('/')
    let roomCode = path[5]
    // console.log('a', roomCode)
    // console.log('b', newMsg.code)
    // roomID 相同時送出新訊息
    if (roomCode == newMsg.code) {
      //   console.log('newMsg', newMsg)
      state.roomMsg.message.push(newMsg)
    }
  },
  listenPush(state, code) {
    state.listen_room.push(code)
  },
  clearMark(state, code) {
    let res = state.newMark.filter(ele => {
      return ele !== code
    })
    state.newMark = res
  },
}

const getters = {
  get_friendList: state => state.friendList,
  get_friendPages: state => state.friendPages,
  get_friendView: state => state.friendView,
  get_roomList: state => state.roomList,
  get_roomList_show: state => state.roomList_show,
  get_roomList_hide: state => state.roomList_hide,
  get_newMark: state => state.newMark,
  get_roomMsg: state => state.roomMsg,
}
export default {
  state,
  actions,
  mutations,
  getters,
}
