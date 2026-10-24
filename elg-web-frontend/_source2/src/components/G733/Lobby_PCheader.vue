<template>
  <div class="Lobby_header">
    <a href="/Login">
      <div
        class="blackMask"
        v-if="!loging&&pathname!='/login'&&pathname!='/Login'&&pathname!='/Signup'&&pathname!='/ForgetPassword'"
      ></div>
    </a>
    <div class="nav">
      <div class="logo">
        <a href="/Lobby">
          <img src="../../assets/img/G733/vworld_s.png" />
        </a>
      </div>
      <div class="menu">
        <a href="/Lobby">{{$t('home')}}</a>
        <a v-for="(menu,i) in getMenu" :key="i" @click="listByGametype(menu)">{{$t(menu.name)}}</a>
      </div>
      <!-- 未登入 -->
      <div class="sub_nav">
        <div v-if="!loging" class="logingNO">
          <a href="/Login">
            <div class="login_button signUpBTN">
              <img src="/static/img/C711_PC/ic_person_24px.png" />
              <span class="signInBtn">{{$t('signIn')}}</span>
            </div>
          </a>
          <a href="/Signup">
            <div
              v-if="template_frontend_register == 1||template_frontend_register == '1'"
              class="login_button signUpBTN"
            >
              <span>{{$t('signUp')}}{{$t('account')}}</span>
            </div>
          </a>
          <a href="/ForgetPassword">
            <div class="login_button signUpBTN">
              <span>{{$t('forget')}}</span>
            </div>
          </a>
        </div>
      </div>
      <!-- 已登入 -->
      <div class="userData" v-if="loging">
        <div class="bankBtn">
          <a href="/Points" v-if="transaction_all!=1">
            <img src="../../assets/img/G733/bank.png" />
          </a>
        </div>
        <div class="pointLockBtn" v-if="EventMode ==1">
          <div class="yellow_borderInput abreastBtnTwo">
            <button
              type="button"
              class="form-control lockHeight fontsizeLock blue_BtnBg"
              @click="UnLockEventMode()"
            >
              <span>Unlock</span>
            </button>
          </div>
        </div>
        <div class="acct_balance" id="acct_balance">
          <img
            src="../../assets/img/G733/reBtnBlue.png"
            @click="reloadBalance()"
            class="deposit_btn"
            style="width:25px; height:25px;"
            v-if="transaction_all==0"
          />
          <img
            src="../../assets/img/G733/reBtnBlue.png"
            @click="reloadAllModal()"
            class="deposit_btn"
            style="width:25px; height:25px;"
            v-if="transaction_all==1"
          />

          <p>
            {{$t('points')}}:
            <span>{{getPoint}}</span>
          </p>

          <a href="/StoredValue">
            <img
              v-if="template_has_online_payment==1  && EventMode == 0"
              class="deposit_btn"
              src="../../assets/img/G733/add.png"
              id="storedBtn"
            />
          </a>
        </div>

        <!-- 個人資料 -->
        <div class="acct_profile" style="z-index:999;" v-if="loging">
          <div class="avatarAll">
            <div class="avatar">
              <img
                :src="avatar =='null'?DefaultAvatar:avatar?avatar:DefaultAvatar"
                id="headerSmallPic"
              />
            </div>
            <div class="path">
              <img src="../../assets/img/G733/Path.png" alt />
            </div>
          </div>

          <div class="sub_menu--border">
            <div class="sub_menu">
              <div class="item" @click="MenuTo(1)">{{$t('settingPersonal')}}</div>

              <div
                v-if="transaction_all!=1"
                class="item"
                @click="MenuTo(2)"
              >{{$t('platformTransfer')}}</div>

              <div class="item" @click="MenuTo(3)">{{$t('recordInquiry')}}</div>

              <div class="item" @click="Logout()">{{$t('signOut')}}</div>
            </div>
          </div>
        </div>
        <!-- 語系 -->

        <div class="lang_button" v-if="loging">
          <img class="arrow_down" src="../../assets/img/G733/lang.png" />
          <div class="sub_menu--border">
            <div class="sub_menu">
              <div
                class="item"
                v-for="(lang, i) in getLanguages"
                :data-lang-key="lang.code"
                :key="i"
                @click="changeLang(lang)"
              >{{lang.name}}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 儲值 -->
    <div class="half_popUp" v-if="deposit_is_open">
      <div class="wrap">
        <img class="bg" src="/static/img/C711_PC/user@example.invalid" />
        <div class="block">
          <div class="head">
            <p>{{$t('storedValuPoints')}}</p>
            <img @click="deposit_close()" src="/static/img/C711_PC/Group5301.png" />
          </div>
          <div class="body">
            <div class="content">
              <p>
                {{template_name}}{{$t('amount')}}:
                <span>{{balance}}</span>
              </p>
              <div class="ele-border bankPic">
                <b-form-select
                  v-model="selected"
                  class="ele_border_in gradual-black"
                  style="height:auto;color:#fff; font-size:14px;"
                >
                  <option :value="null" class="gray_dropdownBg">{{ $t('pleaseSelectABank') }}</option>
                  <option
                    v-for="(item,i) in getOrderTypes"
                    :key="i"
                    :value="item"
                    class="gray_dropdownBg"
                  >{{ item.name }}</option>
                </b-form-select>
                <div class="PicD">
                  <img src="../../assets/img/C711_Mobile/D.png" />
                </div>
              </div>
              <div class="validateDiv">
                <div class="ele-border input-X">
                  <div class="dark-brown">
                    <input
                      v-model="addBalance"
                      class="ele_border_none dark-brown"
                      type="number"
                      :placeholder="$t('pleaseEnterTheNumberOfPoints')"
                      style="font-size:14px;"
                      onkeyup="value=value.replace(/[^\d]/g,'') "
                    />
                    <img
                      src="../../../static/img/C711_PC/grayClose.png"
                      class="inputClear"
                      @click="resetaddBalance()"
                    />
                  </div>
                </div>
              </div>
            </div>
            <div>
              <div class="btn-blue" @click="storedConfirm();">
                <button>{{ $t("submit") }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 未輸入彈窗 -->
    <div class="popUp_block" v-if="valueVisible">
      <div class="wrap">
        <div class="title">{{ $t("prompt") }}</div>
        <div class="content">
          <div>
            <p v-if="this.selected == null">{{$t("pleaseSelectABank")}}</p>
            <p v-if="this.addBalance == 0">{{$t("pleaseEnterTheNumberOfPoints")}}</p>
          </div>
        </div>
        <div class="footer">
          <div @click="errorClose()" class="btn-blue">
            <button>{{ $t("confirm") }}</button>
          </div>
        </div>
      </div>
    </div>
    <!-- 確認彈窗 -->
    <div class="popUp_block" v-if="visiable">
      <div class="wrap">
        <div class="title">{{ $t("confirmDepositInformation") }}</div>
        <div class="content">
          <div>{{ $t("bankName") }}: {{ name }}</div>
          <div>{{ $t("storedValuPoints") }}: {{ addBalance }}</div>
        </div>
        <div class="footer">
          <div @click="stored(); gaData();" class="btn-blue">
            <button>{{ $t("confirm") }}</button>
          </div>
          <div @click="close()" class="btn-grey">
            <button>{{$t('cancel1')}}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- 鎖分活動彈窗 -->

    <div class="popUp_block" style="overflow-y:scroll;" v-if="get_checkLoginEvent">
      <div class="wrap" style="top:5%;">
        <div class="single_block" style="padding-bottom:0px;">
          <div class="case_wrapIframe">
            <div>
              <div class="title">
                {{ $t("eventsContent") }}
                <img
                  @click="modeModal()"
                  class="close_icon"
                  src="../../../static/img/C711_PC/Group5301.png"
                />
              </div>
              <div class="form">
                <div class="orangeTitle" v-if="get_checkEvent.start_time&&get_checkEvent.end_time">
                  <div class="formTitle">{{$t('duringTheEvent')}}:</div>
                  {{get_checkEvent.start_time.substring(0, 16)}} ~ {{get_checkEvent.end_time.substring(0, 16)}}
                </div>
                <div class="orangeTitle">
                  <div class="formTitle">{{$t('participateQualification')}}:</div>
                  <div>1.{{$t('newMemberCreated')}}</div>
                  <div>2.{{$t('existingMembers')}}</div>
                  <div class="formTitle">{{$t('eventRules')}}:</div>
                  {{$t('youWillReceive')}}{{get_checkEvent.point}}{{$t('point')}}，{{$t('afterYouAgree')}}
                  <br />
                  <span>{{$t('theseAreTheCertain')}}</span>
                  <br />
                  1.{{$t('certainGamesAre')}}
                  <br />
                  2.{{$t('cannotOpenWithdrawal')}}
                  <br />
                  3.{{$t('connotJoinOrDelete')}}
                </div>
                <div class="orangeTitle">
                  <div class="formTitle">{{$t('youCanUnlock')}}</div>
                  <div>1.{{$t('balanceIsLesser')}}</div>
                  <div>2.{{$t('balanceIsMoreThen')}}{{get_checkEvent.point*get_checkEvent.times}}{{$t('point')}} {{$t('canKeepAll')}}</div>
                </div>
                <div class="orangeTitle">{{$t('youCanClickTheUnlock')}}</div>
                <div class="redFont">* {{$t('attentionIfYou')}}</div>
                <div class="orangeTitle">
                  {{$t('unlockButtonSign')}}&nbsp;
                  <img
                    src="../../../static/img/C711_PC/LOCKPIC.png"
                  />
                </div>
              </div>
              <div
                class="footer clearfix"
                style="width:90%;margin:auto; margin-top:15px;"
                v-if="EventMode==0"
              >
                <div class="btn-blue float-left" style=" width:120px;">
                  <button @click="joinEventMode()">{{$t('join')}}</button>
                </div>
                <div class="btn-orange float-right" style=" width:120px;">
                  <button @click="modeModal()">{{$t('notJoin')}}</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 解鎖彈窗  -->
    <div class="popUp_block" v-if="get_isUnlockEvent">
      <div class="wrap" style="top:20%;">
        <div class="single_block" style="padding-bottom:0px">
          <div class="case_wrapIframe">
            <div>
              <div class="title" style="background:none;">{{ $t("eventModeSignOff") }}</div>
              <div class="form">
                <div class="orangeTitle">
                  <div
                    class="forceFont"
                  >{{ $t("youCurrentlyHave") }}{{get_unlockEvent.balance}}{{ $t("point") }}，{{ $t("areYousureThat") }}{{get_unlockEvent.point*get_unlockEvent.times}}{{ $t("point") }}{{ $t("ifYouSignOffNow") }}</div>
                </div>
              </div>
              <div class="clearfix" style="width:80%;margin:10px auto;">
                <div
                  class="btn-blue float-left"
                  style="margin:5px; width:120px;"
                  @click="forcibly(1)"
                >
                  <button>{{ $t("determine") }}</button>
                </div>
                <div
                  class="btn-orange float-right"
                  style="margin:5px; width:120px;"
                  @click="modeModal()"
                >
                  <button>{{$t('cancel')}}</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 自動帶點模式 點數重整提示 -->
    <div class="mask" v-if="oneWallet">
      <div class="Popups_Modal">
        <div class="PopupsTitle">{{$t('prompt')}}</div>
        <div class="PopupsConAll">
          <div class="PopupsCon popupsConFont">
            <div style="width:80%;margin:auto;line-height:25px;">{{$t('checkingTheBalance')}}</div>
          </div>
          <div class="PopupsBtn clearfix">
            <div class="silverBorder btnBorder_all float-left">
              <button
                type="submit"
                @click="reloadAllBalance()"
                class="btn_all yellowBtn"
              >{{ $t("confirm") }}</button>
            </div>

            <div class="silverBorder btnBorder_all float-right" @click="modeModal()">
              <button
                type="submit"
                @click="oneWallet  = false"
                class="blueBtn btn_all"
              >{{ $t("cancel") }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import Bus from './Bus.js'
import '../../assets/scss/G733/style.css'
import '../../assets/scss/G733/css.css'
// import Marquee from '@/components/C711_PC/Marquee'
import maskModal from './Modal'
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
      'get_isUnlockEvent'
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
      ad_iframe: false,
      pathname: window.location.pathname
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
      let listData = {
        vendor_id: '',
        type_id: gameType.id,
        key: '',
        page: 1,
        per_page: 100
      }

      this.$store.dispatch('actionGamelist', listData)
      this.$router.push('/GameList')
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
      // var pointMondy = document.getElementById('addBalance').value ///爆錯行

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
      //   setTimeout(()=>{
      //
      //       this.$store.dispatch('actionMenu')
      //       this.$store.dispatch('actionBanner', 1)
      //   },200)
    },
    // HEADER個人選單
    MenuTo(number) {
      if (number == 1) {
        location.href = '/SettingPersonal'
      } else if (number == 2) {
        location.href = '/Points'
      } else if (number == 3) {
        location.href = '/RecordList'
      }
    },
    loginBtn() {
      window.location = '/Login'
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

    
<style  scoped>
</style>