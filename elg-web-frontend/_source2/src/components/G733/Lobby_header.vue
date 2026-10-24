<template>
  <div id="G733">
    <div class="LoginHeader gary_header">
      <!-- LoginHeader -->
      <div class="headerLeft">
        <img src="../../assets/img/G733/logo.png" alt="V-World" />
      </div>
      <div class="headerRight"></div>
    </div>
  </div>
</template>
<script>
import '../../assets/scss/G733/style.css'
import '../../assets/scss/G733/css.css'
import maskModal from './Modal'
import { mapGetters, mapActions } from 'vuex'
import Bus from './Bus.js'
// import Marquee from '@/components/C711_PC/Marquee'
import Global from '@/Global'
import secret from '@/utils/secret'
const CryptoJS = require('crypto-js')
import md5 from 'js-md5'
import axios from 'axios'
export default {
  components: {
    // DEPOSIT
    maskModal
  },
  computed: {
    ...mapGetters([
      'getMenu',
      'getOrderTypes',
      'getLanguages',
      'getNewvpoints',
      // 忘記密碼
      'isGetCode',
      'getCountries',
      'getTime',
      'reSendCode',
      'getCountries',
      'isFinish',
      'getOrderId',
      //   鎖分活動
      'get_checkLoginEvent',
      'get_checkEvent',
      'getAcctInfo',
      'get_unlockEvent',
      'get_isUnlockEvent',
      'get_eventOver'
    ]),
    getPoint() {
      let vpoints = this.getNewvpoints
        ? this.getNewvpoints
        : sessionStorage.getItem('balance')
      //  更新平台餘額
      return vpoints
    }
  },
  data() {
    return {
      loging: sessionStorage.getItem('token'),
      avatar: sessionStorage.getItem('avatar'),
      DefaultAvatar: require('../../../static/img/C711_PC/lion.jpg'),
      username: sessionStorage.getItem('username'),
      balance: sessionStorage.getItem('balance'),
      template_name: sessionStorage.getItem('TemplateTitle'),
      template_has_online_payment: sessionStorage.getItem('has_online_payment'),
      template_frontend_register: sessionStorage.getItem('frontend_register'),

      //儲值
      deposit_is_open: false,
      visiable: false,
      valueVisible: false,
      selected: null,
      addBalance: '',
      name: '',
      ordertypeId: '',
      orderId: '',

      // 列表
      // gameType: ''
      firstEmit: true,

      //登入
      login_Modal: false,
      language: this.$i18n.locale,
      account: localStorage.getItem('user_account') || '',
      password: '',
      lang: localStorage.getItem('LOCALE'),
      //註冊
      signUp_Modal: false,
      newAccount: '',
      newPassword: '',
      rePassword: '',
      code: this.$route.params.shareCode,
      create_visible: false,
      //忘記密碼
      forgetPW_Modal: false,
      create_visiable: false,
      isCheckPhone: false,
      verify_type: '',
      id: window.sessionStorage.getItem('id'),
      Country:
        window.sessionStorage.getItem('verify_country') || this.$t('malaysia'),
      phoneCode: window.sessionStorage.getItem('phone_code') || '60',

      phoneRule:
        window.sessionStorage.getItem('verify_phoneRule') || '^[1-9]\\d{8,9}$',
      phone: '',
      code: '',
      sendCodeBtn: this.$t('sendANewsLetter'),
      regainVerificationCode: '',
      has_pcCH: require('../../../static/img/UsePcCN.png'),
      has_pcEN: require('.../../../static/img/UsePcEN.png'),
      has_mobileCH: require('../../../static/img/UseAppCN.png'),
      has_mobileEN: require('../../../static/img/UseAppEN.png'),
      transaction_all: sessionStorage.getItem('transaction_all'),
      secretPW: '',
      //   鎖分
      EventMode: sessionStorage.getItem('eventMode'),
      eventModeModal: false,
      //   eventModeModal: false
      // 大獎蓋台
      fullAD: false,
      //   單一錢包彈窗
      oneWallet: false,
      ad_iframe: false
    }
  },
  mounted() {
    if (!sessionStorage.getItem('Template')) {
      // if (sessionStorage.getItem("Template")) {
      let domainUrl = location.hostname
      let md5_key = Global.$API_MD5_KEY
      let lang = localStorage.getItem('LOCALE')
      let token = [REDACTED]'token')
      let jsonMsg = JSON.stringify({
        domain: domainUrl
      })
      let params = secret.Encrypt(jsonMsg)
      let key = md5(params + md5_key)
      axios
        .post(
          Global.$API_URL + 'api/template',
          {
            params: params,
            key: key
          },
          {
            headers: {
              'Accept-Language': lang,
              Authorization: 'Bearer ' + token
            }
          }
        )
        .then(res => {
          if (res.data.code == 1000) {
            // 開關
            sessionStorage.setItem(
              'frontend_register',
              res.data.data.frontend_register
            )
            sessionStorage.setItem('has_chat', res.data.data.has_chat)
            sessionStorage.setItem('has_pc', res.data.data.has_pc)
            sessionStorage.setItem('has_mobile', res.data.data.has_mobile)
            sessionStorage.setItem(
              'has_online_payment',
              res.data.data.has_online_payment
            )
            sessionStorage.setItem('has_app', res.data.data.has_app)
            sessionStorage.setItem(
              'transaction_all',
              res.data.data.transaction_all
            )
          } else {
            alert(res.data.message)
          }
        })
        .catch(error => {
          console.log(error)
        })
    }
    // 紀錄密碼解密
    if (localStorage.getItem('user_account')) {
      this.account = localStorage.getItem('user_account')
    } else {
      this.account = ''
    }
    // this.account = localStorage.getItem('user_account') || '',
    let secretPW = localStorage.getItem('user_password')
    if (secretPW == null || secretPW == undefined || secretPW == '') {
      this.password = ''
    } else {
      let decryptPW = secret.Decrypt(secretPW)
      this.password = [REDACTED]
    }
    if (this.transaction_all == 1 && window.location.pathname != '/Points') {
      //   $('#acct_balance').css('display', 'none')
    }
  },
  methods: {
    reloadBalance() {
      this.$store.dispatch('actionMember')
    },
    // 單一錢包
    reloadAllModal() {
      this.oneWallet = true
    },
    // 自動轉點 RE LODAD取全部餘額按鈕
    reloadAllBalance() {
      let data = ''
      this.$store.dispatch('singleWalletBalance', data)
      this.oneWallet = false
      //   $('.zero').css('display', 'none')
    },
    goHome() {
      this.$router.push('/Lobby')
    },
    listByGametype(gameType) {
      let str = this.$route.params.vendorID

      if (!str) {
        // this.$router.push('/gameList?vds=')
        let sessionVendorAry = JSON.parse(
          sessionStorage.getItem('vendors_type')
        )

        let vendor_ids = []
        sessionVendorAry.forEach(element => {
          vendor_ids.push(element.id)
        })

        let vds = vendor_ids.join(',')

        // this.$router.push('/gameList?vds=' + vds)
        this.$router.push('/GameList/' + vds)
      }
      if (this.firstEmit) {
        setTimeout(() => {
          // 第一次$emit監聽不到 gamelist $on取不到值，需延遲
          /// 非父子組件傳遞 $emit傳

          Bus.$emit('gametype', gameType)
          this.firstEmit = false
        }, 1000)
      } else {
        Bus.$emit('gametype', gameType)
      }
    },

    //////
    Logout() {
      this.$store.dispatch('actionLogout')
    },
    deposit_open() {
      this.deposit_is_open = true
      this.$store.dispatch('actionOrderTypes')
    },
    deposit_close() {
      this.deposit_is_open = false
    },

    ////
    resetaddBalance() {
      this.addBalance = ''
    },
    //儲值確認談窗||
    storedConfirm() {
      if (this.addBalance == 0 || this.selected == null) {
        this.valueVisible = true
      } else {
        this.visiable = true
        // $('#submitModal').modal('show')
        this.name = this.selected.name
        this.ordertypeId = this.selected.id
      }
    },
    close() {
      this.visiable = false
    },
    errorClose() {
      this.valueVisible = false
    },
    //儲值送出
    stored() {
      let obj = {}
      obj['ordertypeId'] = this.ordertypeId
      obj['amount'] = this.addBalance
      this.$store.dispatch('actionAddPoint', obj)
      this.visiable = false
    },
    gaData() {
      setTimeout(() => {
        ga('require', 'ecommerce', 'ecommerce.js')
        // 收集結帳商品資料用

        let getOrderID = this.getOrderId.replace(/_/g, '')

        ga('ecommerce:addItem', {
          id: getOrderID, // 商品ID，必填
          name: this.name, // 商品名稱，必填
          sku: this.ordertypeId, // SKU/code
          category: 'StoredValue', // 分類
          price: this.addBalance, // 商品單價，必填
          quantity: 1 // 數量，必填
        })
        //   收集最終結帳交易資料用
        ga('ecommerce:addTransaction', {
          id: this.ordertypeId, // 交易ID，必填
          affiliation: this.name, // 聯盟或分店
          revenue: this.addBalance // 結帳金額
          // shipping: ,// 運費
          // tax: ,// 稅金
        })
        ga('ecommerce:send')
      }, 1000)
    },
    changeLang(e) {
      var lang = event.currentTarget.getAttribute('data-lang-key')
      localStorage.setItem('LOCALE', lang)
      this.$i18n.locale = lang
      window.location.reload()
    },
    loginBtn() {
      this.login_Modal = true
      $('.blackMask').css('background-color', '#49494a69')
    },
    login_Modalclose() {
      this.login_Modal = false
      $('.blackMask').css('background-color', '#fbfbfb00')
    },
    signUpBtn() {
      this.signUp_Modal = true
    },
    signUp_Modalclose() {
      this.signUp_Modal = false
    },
    forgetPWBtn() {
      this.forgetPW_Modal = true
    },
    forgetPW_Modalclose() {
      this.forgetPW_Modal = false
    },
    //註冊
    resetnewAccount() {
      this.newAccount = ''
    },
    resetnewPassword() {
      this.newPassword = ''
    },
    resetrePassword() {
      this.rePassword = ''
    },
    resetcode() {
      this.code = ''
    },
    create() {
      var device_id = sessionStorage.getItem('device')
      let formMsg = {
        username: this.newAccount,
        password: [REDACTED],
        password_confirmation: this.rePassword,
        code: this.code,
        device_id: device_id
      }
      this.$store.dispatch('actionCreate', formMsg)
    },
    blur() {
      if (
        this.newAccount !== '' &&
        this.newPassword !== '' &&
        this.rePassword !== '' &&
        this.code !== ''
      ) {
        this.$validator.validateAll().then(valid => {
          if (valid) {
            this.create_visible = true
          } else {
            this.create_visible = false
          }
        })
      } else {
        this.create_visible = false
      }
    },
    //忘記密碼
    resetphone() {
      this.phone = ''
    },
    resetcode() {
      this.code = ''
    },
    verifyType() {
      let params = window.location.pathname
      if (params == '/PhoneVerification') {
        this.verify_type = 1
      } else if (params == '/ForgetPassword') {
        this.verify_type = 2
      } else if (params == '/RetrievePassword') {
        this.verify_type = 3
      }
    },
    select(e) {
      window.sessionStorage.setItem('verify_country', e.name)
      window.sessionStorage.setItem('phone_code', e.country_code)
      window.sessionStorage.setItem('verify_phoneRule', e.phone_rule)
      this.phoneCode = e.country_code
      this.Country = e.name
      //   window.location.reload()
      //   this.$router.push('/PhoneVerification')
      //   window.history.go(-1)
    },
    // 簡訊紐解鎖
    send_disable() {
      if (this.phone !== '') {
        this.$validator.validate('phone').then(valid => {
          if (valid) {
            this.create_visiable = true
          } else {
            this.create_visiable = false
          }
        })
      } else {
        this.create_visiable = false
      }
    },
    // 提示手機號碼確認
    checkPhone() {
      this.isCheckPhone = true
    },
    closeCheck() {
      this.isCheckPhone = false
    },
    // 發送簡訊
    sendCode(verify_by) {
      let data = {
        type: 2, //忘記密碼
        member_id: sessionStorage.getItem('id')
          ? sessionStorage.getItem('id')
          : '',
        verify_by: verify_by,
        mobile: this.phoneCode + this.phone
      }
      //   if (this.verify_type == 2 || this.verify_type == 3) {
      //     data.member_id = ''
      //   }

      this.$store.dispatch('actionSendCode', data)
      this.isCheckPhone = false

      this.regainVerificationCode = this.$t('regainVerificationCode') + '00：'
    },
    finish(type) {
      let data = {
        type: type,
        verifyCode: window.sessionStorage.getItem('verify_Code')
      }
      this.$store.dispatch('actionCheckCode', data)
    },
    // Login_header 完成按鈕解鎖
    finish_disable() {
      window.sessionStorage.setItem('verify_Code', this.code)
      if (this.code.length == 6) {
        let isFinish = true
        this.$store.commit('completeDisable', isFinish)
      } else if (this.code.length !== 6) {
        let isFinish = false
        this.$store.commit('completeDisable', isFinish)
      }
    },
    //登入
    actionLogin() {
      var device_id = sessionStorage.getItem('device')

      let data = {
        account: this.account,
        password: [REDACTED],
        device_id: device_id
      }

      this.$store.dispatch('actionLogin', data)
    },
    SignUp() {
      this.$router.push('/Signup')
    },
    ForgetPass() {
      this.$router.push('/ForgetPassword')
    },
    // // 參加鎖分活動
    joinEventMode() {
      this.$store.dispatch('actionJoinLoginEvent')
      this.$store.commit('HIDE_JOIN_EVENT')
    },
    //  鎖分不參加按鈕
    modeModal() {
      this.$store.commit('HIDE_JOIN_EVENT')
      this.$store.commit('HIDE_UNLOCK_EVENT')
    },
    // 強制解鎖
    forcibly(data) {
      //   強制解鎖帶參數1
      this.$store.dispatch('actionUnLoginEvent', data)
      this.$store.commit('HIDE_UNLOCK_EVENT')
    },
    // 鎖分模式解鎖
    UnLockEventMode() {
      this.$store.dispatch('actionUnLoginEvent')
    },

    closeAD() {
      //   $('#fullAD').css('display', 'none')
      this.fullAD = false
    }
  },
  created() {
    this.$store.dispatch('actionMenu')
    this.$store.dispatch('actionMember')
    this.$store.dispatch('actionLanguages')

    // // 忘記密碼
    // this.verifyType()
    this.$store.dispatch('actionCountries')
    // 登入
    this.$store.commit('HIDE_API_RES')
    // 確認是否可以參加登入活動
    if (this.loging) {
      if (
        this.EventMode != 1 &&
        localStorage.getItem('checkLoginEvent' + this.username) != 1
      ) {
        this.$store.dispatch('actionCheckLoginEvent', 1)

        localStorage.setItem('checkLoginEvent' + this.username, 1)
      }
    }
  }
}
</script>