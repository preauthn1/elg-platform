<template>
  <div id="C711Mobile">
    <div class="pageBg widthHeightvh CwidthHeight">
      <LoginHeader></LoginHeader>
      <div class="pageCon">
        <form @submit.prevent="create()">
          <!-- 帳號 -->
          <div class="inputvalidate">
            <div class="ListborderInput">
              <input
                @keyup="blur()"
                v-model="newAccount"
                class="form-control ListInputAllL Listinputbg"
                name="userId"
                v-validate="'required|min:4|max:10|alpha_num'"
                :data-vv-as="$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
                maxlength="10"
                type="text"
                id="account"
                :placeholder="$t('account')+'：'+$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
              />
              <img
                src="../../assets/img/C711_Mobile/grayClose.png"
                @click="resetnewAccount()"
                class="inputClose"
              />
            </div>
            <label
              v-show="errors.has('userId')"
              for="userId"
              class="validateDiv"
            >{{ errors.first('userId') }}</label>
          </div>
          <!-- 密碼 -->
          <div class="inputvalidate">
            <div class="ListborderInput">
              <input
                class="ListInputAllL Listinputbg"
                @keyup="blur()"
                v-model="newPassword"
                name="password"
                v-validate="'required|min:6|max:12|alpha_num'"
                :data-vv-as="$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
                maxlength="12"
                type="password"
                :placeholder=" $t('setpassword')+'：'+$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
                ref="password"
              />
              <img
                src="../../assets/img/C711_Mobile/grayClose.png"
                @click="resetnewPassword()"
                class="inputClose"
              />
            </div>
            <label
              v-show="errors.has('password')"
              for="password"
              class="validateDiv"
            >{{ errors.first('password') }}</label>
          </div>
          <!-- 確認密碼 -->
          <div class="inputvalidate">
            <div class="ListborderInput">
              <input
                class="ListInputAllL Listinputbg"
                @keyup="blur()"
                v-model="rePassword"
                name="comfirmPass"
                v-validate="'required|confirmed:password'"
                :data-vv-as="$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
                maxlength="12"
                type="password"
                :placeholder="$t('confirmpassword')+'：'+$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
              />
              <img
                src="../../assets/img/C711_Mobile/grayClose.png"
                @click="resetrePassword()"
                class="inputClose"
              />
            </div>
            <label
              v-show="errors.has('comfirmPass')"
              class="validateDiv"
            >{{ $t("inconsistentPassword") }}</label>
          </div>
          <!-- 邀請碼 -->
          <div class="inputvalidate">
            <div class="ListborderInput">
              <input
                class="ListInputAllL Listinputbg"
                v-if="$route.params.shareCode==undefined"
                @keyup="blur()"
                v-model="code"
                name="code"
                v-validate="'required'"
                :data-vv-as="$t('pleaseEnterTheSharingCode')"
                type="text"
                :placeholder="$t('pleaseEnterTheSharingCode')"
              />
              <input
                class="form-control ListInputAllL Listinputbg"
                v-else
                @keyup="blur()"
                v-model="code"
                name="code"
                v-validate="'required'"
                :data-vv-as="$t('pleaseEnterTheSharingCode')"
                type="text"
              />
              <img
                src="../../assets/img/C711_Mobile/grayClose.png"
                @click="resetcode()"
                class="inputClose"
              />
            </div>
            <label v-show="errors.has('code')" class="validateDiv">{{ errors.first('code') }}</label>
          </div>
          <!-- 按鈕 -->
          <div class="btn-blue">
            <button
              type="submit"
              :disabled="!create_visible"
              @click="playSound()"
              style="padding:9px auto;"
            >{{ $t("signUp") }}</button>
          </div>
        </form>
      </div>
      <!-- APP 客服 按鈕 -->
      <div class="bottomIcon">
        <div class="whatapp">
          <a href="https://example.invalid/601119006244" target="_blank">
            <div class="whatappPic">
              <img src="../../assets/img/C711_Mobile/whatapp.png" />
            </div>
            <div class="whatappFont">{{ $t("customerService") }}</div>
          </a>
        </div>
        <div class="whatapp" @click="downloadAPP()" v-if="has_app===1||has_app==='1'">
          <div class="whatappPic">
            <img src="../../assets/img/C711_Mobile/vworld_icon.png" />
          </div>
          <div class="whatappFont">{{ $t("downLoadAPP") }}</div>
        </div>
      </div>
      <!-- 彈窗 -->
      <maskModal></maskModal>
      <WeChatMask></WeChatMask>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/C711_Mobile/Login_header'
import { mapGetters, mapMutations } from 'vuex'
import maskModal from './Modal'
import WeChatMask from './WeChatMask'
$(function() {
  var u = navigator.userAgent,
    app = navigator.appVersion
  var isAndroid = u.indexOf('Android') > -1 || u.indexOf('Linux') > -1 //g
  var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/) //ios终端
  if (isAndroid) {
    //ANDROID鍵盤喚起時,FOOTER隱藏
    var oHeight = $(document).height() //螢幕當前高度
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
    LoginHeader,
    maskModal,
    WeChatMask
  },
  computed: {
    ...mapGetters([''])
  },
  data() {
    return {
      newAccount: '',
      newPassword: '',
      rePassword: '',
      code: this.$route.params.shareCode,
      create_visible: false,
      has_app: sessionStorage.getItem('has_app')
    }
  },
  mounted() {
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    //橫向禁止
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }
  },
  methods: {
    //   按鈕音效
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
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
    // 驗證
    blur() {
      if (
        this.newAccount != '' &&
        this.newPassword != '' &&
        this.rePassword != '' &&
        this.code != ''
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
  }
}
</script>