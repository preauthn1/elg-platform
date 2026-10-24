import Global from '@/Global'
import * as types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'

const state = {}

const actions = {
  actionLogin({
    commit
  }, data) {
    let md5_key = Global.$API_MD5_KEY
    let jsonMsg = JSON.stringify({
      username: data.username,
      password: [REDACTED],
    })
    let params = secret.Encrypt(jsonMsg)
    let key = md5(params + md5_key)

    let lang = localStorage.getItem('LOCALE');

    if (lang == null || lang == '') {
      let defaultLang = "en";
      lang = defaultLang;
      localStorage.setItem("LOCALE", defaultLang);
    }

    axios
      .post(
        Global.$API_URL + 'api/login', {
          params: params,
          key: key,
        }, {
          headers: {
            'Accept-Language': lang
          },
        }
      )
      .then(res => {
        if (res.data.code == 1000) {
          let user = res.data.data
          sessionStorage.setItem('user_id', user.id)
          sessionStorage.setItem('role_id', user.role_id)
          sessionStorage.setItem('user_name', user.username)
          sessionStorage.setItem('member_username', user.member_username)
          sessionStorage.setItem('user_nickname', user.nickname)
          sessionStorage.setItem('user_avatar', user.thumbnail)
          sessionStorage.setItem('user_token', user.token)
          sessionStorage.setItem('user_member_id', user.member_id)
          sessionStorage.setItem('share_code', user.share_code)
          sessionStorage.setItem('share_domain', user.domain)
          router.push('/')
        } else {
          alert(res.data.message)
          //   window.location.reload()
        }
      })
      .catch(error => {
        console.log(error)
      })
  },
  actionLogout({
    commit
  }) {
    let md5_key = Global.$API_MD5_KEY
    let token = [REDACTED]'user_token')
    let params = secret.Encrypt('')
    let key = md5(params + md5_key)
    axios
      .post(
        Global.$API_URL + 'api/logout', {
          params: params,
          key: key,
        }, {
          headers: {
            'Accept-Language': Global.$LOCALE_LANG,
            Authorization: 'Bearer ' + token,
          },
        }
      )
      .then(res => {
        if (res.data.code == 1000) {
          router.push('/login')
          sessionStorage.clear()
        } else {
          alert(res.data.message)
        }
      })
      .catch(error => {
        console.log(error)
      })
  },
}

const mutations = {}

const getters = {}
export default {
  state,
  actions,
  mutations,
  getters,
}