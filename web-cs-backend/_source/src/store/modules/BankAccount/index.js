import Global from '@/Global'
import * as mu_types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'
import {
  resolve
} from 'path'

const state = {
  bankAccount: {
    account_name: '',
    account_no: '',
    bank_id: '',
    exist_data_id: 0
  },
  orderTypes: []
}

const actions = {


  action_get_bank_account_info({
    commit
  }, username) {

    return new Promise((resolve, reject) => {

      let token = [REDACTED]'user_token')

      if (token) {
        let md5_key = Global.$API_MD5_KEY
        let jsonMsg = JSON.stringify({
          username: username,
        })

        let params = secret.Encrypt(jsonMsg)
        let key = md5(params + md5_key)

        axios
          .post(
            Global.$API_URL + 'api/members/bank-account', {
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
              if (res.data.data != null) {
                commit(mu_types.GET_USER_BANK_ACCOUNT, res.data.data);
              }
            } else {
              alert(res.data.message)
            }

            //vue那邊處理
            resolve(res);

          })
          .catch(error => {
            console.log(error)
          })


      }
    });

  },



  action_update_bank_account_info({
    commit
  }, data) {

    return new Promise((resolve, reject) => {

      let token = [REDACTED]'user_token');

      if (token) {
        let md5_key = Global.$API_MD5_KEY
        let jsonMsg = JSON.stringify(data);
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);


        console.log(data);

        axios
          .post(
            Global.$API_URL + 'api/members/bank-account/create', {
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
              alert('success!');
            } else {
              alert(res.data.message)
            }

            //vue那邊處理
            resolve(res);

          })
          .catch(error => {
            console.log(error)
          })
      }

    });
  },



  action_get_orderTypes({
    commit
  }) {

    return new Promise((resolve, reject) => {

      let token = [REDACTED]'user_token')

      if (token) {
        let md5_key = Global.$API_MD5_KEY
        let jsonMsg = JSON.stringify('')
        let params = secret.Encrypt(jsonMsg)
        let key = md5(params + md5_key)

        axios
          .post(
            Global.$API_URL + 'api/orderType/list', {
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
              commit(mu_types.GET_ORDER_TYPES, res.data.data);
            } else {
              alert(res.data.message)
            }

            //vue那邊處理
            resolve(res);

          })
          .catch(error => {
            console.log(error)
          })


      }
    });

  },




}

const mutations = {
  [mu_types.GET_USER_BANK_ACCOUNT](state, query) {
    state.bankAccount.account_name = query.account_name;
    state.bankAccount.account_no = query.account_no;
    state.bankAccount.bank_id = query.bank_id;
    state.bankAccount.exist_data_id = query.id;
  },

  [mu_types.GET_ORDER_TYPES](state, query) {
    state.orderTypes = query;
  }
}

const getters = {
  get_bankAccount: state => state.bankAccount,
  get_orderTypes: state => state.orderTypes,
}

export default {
  state,
  actions,
  mutations,
  getters,
}