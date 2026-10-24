<template>
  <div id="G733">
    <!-- 捷徑引導 -->
    <div v-if="PC_MOBILE=='Mobile'">
      <div class="shortcutMask">
        <div class="shortcutPic" style="color:#fff;" v-if="AndroidIos == 'Android'">
          <img :src="lang == 'zh-tw' ? androidAddcn : androidAdden" class="androidAdd" />
        </div>
        <div class="shortcutPic" style="color:#fff;" v-if="AndroidIos == 'Ios'">
          <img :src="lang == 'zh-tw' ? iosAddcn : iosAdden" class="iosAdd" />
        </div>
      </div>
      <!-- 螢幕鎖定 -->
      <div class="directionMask">
        <div class="directionPic">
          <img :src="lang == 'zh-tw' ? directionCh : directionEng" />
        </div>
      </div>
      <!-- 微信引導 -->
      <div class="wechatMask">
        <div class="wechatPic">
          <img :src="lang == 'zh-tw' ? zh_twPic : enPic" />
        </div>
      </div>
    </div>
    <div class="mobileHeader">
      <LobbyHeader></LobbyHeader>
    </div>
    <div class="pcHeader">
      <LobbyPCHeader></LobbyPCHeader>
    </div>

    <!-- LoginMain -->
    <div class="single_block">
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainAll">
          <div class="pcmainBg">
            <div class="mainLogin">{{$t('signIn')}}</div>
            <div class="loginInfo">
              <div class="info">
                <input
                  type="text"
                  v-model="account"
                  id="InputUser"
                  name="username"
                  :placeholder="$t('loginphone')"
                  class="yellowInput input_all"
                  required
                />
              </div>
              <div class="info">
                <input
                  v-model="password"
                  type="password"
                  name="password"
                  id="InputPassword"
                  :placeholder="$t('password')"
                  class="yellowInput input_all"
                  required
                />
              </div>
            </div>
            <div class="silverBorder btnBorder_all">
              <input
                type="button"
                v-model="loginBtn"
                @click="playSound();actionLogin();"
                name="loginBtn"
                class="redBtn btn_all"
              />
            </div>
            <div class="forget">
              <a
                href="/ForgetPassword"
                @click="playSound()"
                style="color:#edba37;"
              >{{$t('forget')}}?</a>
            </div>
            <div class="line"></div>
            <div class="notMember">
              <a
                href="/Signup"
                @click="playSound()"
                style="color:white;"
              >{{$t('noAccountRegistered')}}</a>
            </div>
            <div class="silverBorder btnBorder_all">
              <a href="/Signup" @click="playSound()">
                <input type="button" v-model="signupBtn" name="signupBtn" class="blueBtn btn_all" />
              </a>
            </div>
          </div>
          <div class="bottomIcon">
            <div class="whatapp">
              <a href="https://example.invalid/601119006244" target="_blank">
                <div class="whatappPic">
                  <img src="../../assets/img/C711_Mobile/whatapp.png" />
                </div>
                <div class="whatappFont">{{ $t("customerService") }}</div>
              </a>
            </div>
            <div class="whatapp" @click="downloadAPP()" v-if="has_app == 1 ">
              <div class="whatappPic">
                <img src="../../assets/img/C711_Mobile/vworld_icon.png" />
              </div>
              <div class="whatappFont">{{ $t("downLoadAPP") }}</div>
            </div>
          </div>
          <!-- 彈窗 -->
          <maskModal></maskModal>
          <!-- v-if="oneWallet" -->
          <div class="mask" v-if="get_eventOver">
            <div class="Popups_Modal">
              <div class="PopupsTitle">{{$t('prompt')}}</div>
              <div class="PopupsConAll">
                <div class="PopupsCon popupsConFont">
                  <div style="width:80%;margin:auto;line-height:25px;">{{$t('eventIsOver')}}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import LobbyHeader from '@/components/G733/Lobby_header'
import LobbyPCHeader from '@/components/G733/Lobby_PCheader'
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
import Global from '@/Global'
import { isNull } from 'util'
const CryptoJS = require('crypto-js')
import secret from '@/utils/secret'
import md5 from 'js-md5'
import axios from 'axios'
import Bus from './Bus.js'

var winHeight = $(window).height()
$(window).resize(function() {
  var thisHeight = $(this).height()
  var keyboardHeight = thisHeight - winHeight
  $('.loginFooter').css({ bottom: keyboardHeight + 'px' })
})
$(function() {
  var u = navigator.userAgent,
    app = navigator.appVersion
  var isAndroid = u.indexOf('Android') > -1 || u.indexOf('Linux') > -1 //g
  var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/) //ios终端
  if (isAndroid) {
    //ANDROID鍵盤喚起時,FOOTER隱藏
    var oHeight = $(document).height() //屏幕当前的高度
    $(window).resize(function() {
      if ($(document).height() < oHeight) {
        $('.whatapp').css('display', 'none')
      } else {
        $('.whatapp').css('display', 'block')
      }
    })
  }
  if (isIOS) {
  }
})
export default {
  components: {
    LobbyHeader,
    LobbyPCHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['get_eventOver'])
  },
  data() {
    return {
      language: this.$i18n.locale,
      account: '',
      password: '',
      loginBtn: this.$t('signIn'),
      signupBtn: this.$t('signUp'),
      forgetBtn: this.$t('forget'),
      directionCh: require('../../../static/img/directionCh.png'),
      directionEng: require('../../../static/img/directionEng.png'),
      iosAddcn: require('../../../static/img/iosAddcn.png'),
      iosAdden: require('../../../static/img/iosAdden.png'),
      androidAddcn: require('../../../static/img/androidAddcn.png'),
      androidAdden: require('../../../static/img/androidAdden.png'),
      enPic: require('../../../static/img/wechat.png'),
      zh_twPic: require('../../../static/img/wechatC.png'),
      lang: localStorage.getItem('LOCALE'),
      direction: window.orientation,
      logoMobile: sessionStorage.getItem('TemplateLogo'),
      frontend_register: sessionStorage.getItem('frontend_register'),
      has_app: sessionStorage.getItem('has_app'),
      has_mobile: sessionStorage.getItem('has_mobile'),
      PC_MOBILE: sessionStorage.getItem('PC_MOBILE'),
      AndroidIos: sessionStorage.getItem('AndroidIos'),
      loging: sessionStorage.getItem('token'),
      //   視窗高度
      //   screenWidth: window.innerWidth
      screenWidth: document.body.clientWidth
    }
  },
  mounted() {
    var shortcut = $.cookie('shortcut')

    if (!shortcut) {
      if (this.AndroidIos == 'Ios') {
        $('.shortcutMask').css('display', 'block')
        $('.shortcutPic').css('top', 50 + '%')
        $('.shortcutPic').css('transform', 'translateY(-50%)')
      } else {
        $('.shortcutMask').css('display', 'block')
      }
      setTimeout(() => {
        $('.shortcutMask').css('display', 'none')
      }, 3000)
      $.cookie('shortcut', 'has_shortcut')
    } else {
      $('.shortcutMask').css('display', 'none')
    }
    // 紀錄密碼解密
    if (localStorage.getItem('user_account')) {
      this.account = localStorage.getItem('user_account')
    } else {
      this.account = ''
    }

    let secretPW = localStorage.getItem('user_password')
    if (secretPW == null || secretPW == undefined || secretPW == '') {
      this.password = ''
    } else {
      let decryptPW = secret.Decrypt(secretPW)
      this.password = [REDACTED]
    }

    $('#has_mobilieMask').css('display', 'none')
    $('#has_pcMask').css('display', 'none')
    if (this.PC_MOBILE == 'MOBILE') {
      if (this.has_mobile == 0) {
        $('#has_mobilieMask').css('display', 'block')
        $('#has_pcMask').css('display', 'none')
      } else {
      }
    }

    //偵測WECHAT
    var ua = navigator.userAgent.toLowerCase()
    var isWeixin = ua.indexOf('micromessenger') != -1
    if (isWeixin) {
      $('.wechatMask').css('display', 'block')
    } else {
      $('.wechatMask').css('display', 'none')
    }
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }
  },

  methods: {
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    //語系變更
    GetLanguage(event) {
      var lang = event.target.value
      localStorage.setItem('LOCALE', lang)
      this.$i18n.locale = lang
    },
    actionLogin() {
      var device_id = sessionStorage.getItem('device')

      let data = {
        account: this.account,
        password: [REDACTED],
        device_id: device_id
      }

      this.$store.dispatch('actionLogin', data)
    },

    downloadAPP() {
      //判斷IOS ANDROID
      $(function() {
        var u = navigator.userAgent,
          app = navigator.appVersion
        var isAndroid = u.indexOf('Android') > -1 || u.indexOf('Linux') > -1 //g
        var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/) //ios终端
        if (isAndroid) {
          window.location =
            ' https://example.invalid/APPDL/Android/Browser/VWorld.apk'
        }
        if (isIOS) {
          window.location =
            ' itms-services://?action=download-manifest&url=https://example.invalid/APPDL/IOS/Browser/VWorld.plist'
        }
      })
    }
  },
  created() {
    this.$store.commit('HIDE_API_RES')
  },
  watch: {
    direction: function(val) {}
  }
}
</script>