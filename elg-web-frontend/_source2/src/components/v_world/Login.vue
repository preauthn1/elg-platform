<template>
  <div class="login">
    <div class="directionMask">
      <div class="directionPic">
        <img :src="lang=='en'?directionEng:directionCh" />
      </div>
    </div>
    <div class="wechatMask">
      <div class="wechatPic">
        <img :src="lang=='en'?enPic:zh_twPic" />
      </div>
    </div>
    <img src="../../assets/img/v_world/bgNew.png" />
    <img src="../../assets/img/v_world/bg_snow.png" class="specialT" />
    <div class="signin">
      <!-- <span style="color:red">123</span> -->
      <form>
        <div class>
          <input
            v-model="account"
            class="form-control inputbg fontsizelogin"
            id="InputUser"
            :placeholder="$t('loginphone')"
            required
          />
        </div>
        <div class>
          <input
            v-model="password"
            type="password"
            class="form-control inputbg fontsizelogin"
            id="InputPassword"
            :placeholder="$t('password')"
            required
          />
        </div>

        <div class="clearfix loginBtn">
          <div class="float-left imgfont blueBtn" @click="playSound();actionLogin()">
            <span>{{ $t("signIn") }}</span>
          </div>
          <div class="float-right imgfont blueBtn">
            <router-link to="/Signup" @click="playSound">
              <span>{{ $t("signUp") }}</span>
            </router-link>
          </div>
        </div>
      </form>
      <div class="forgetpwBtn">
        <router-link to="/ForgetPassword">
          <span>{{ $t("forget") }}</span>
        </router-link>
      </div>
    </div>
    <img src="../../assets/img/v_world/bg_Man.png" class="specialB" />

    <div class="bottomIcon">
      <div class="whatapp">
        <a href="https://example.invalid/601119006244" target="_blank">
          <div class="whatappPic">
            <img src="../../assets/img/v_world/whatapp.png" />
          </div>
          <div class="whatappFont">{{ $t("customerService") }}</div>
        </a>
      </div>

      <div class="whatapp" @click="downloadAPP()">
        <div class="whatappPic">
          <img src="../../assets/img/v_world/custom_icon1.png" />
        </div>
        <div class="whatappFont">{{ $t("downLoadAPP") }}</div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
    <!-- <div class="loginFooter">
      Customer Service contact:wechat ID "vworld_cs" Bi-weekly
      <br />maintenance:Thursday 08:00AM~11:00AM
    </div>-->
  </div>
</template>

<script>
import '../../assets/scss/v_world/style.css'
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
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
    // console.log('安卓')
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
    // console.log('蘋果')
  }
})
// console.log(window.orientation)
// window.addEventListener('orientationchange', function() {
//   console.log(window.orientation)
//   alert(window.orientation)
// })
export default {
  components: {
    maskModal
  },
  computed: {
    ...mapGetters([''])
  },
  data() {
    return {
      language: this.$i18n.locale,
      account: localStorage.getItem('user_account') || '',
      password: '',
      zh_twPic: require('../../../static/img/v_world/wechatC.png'),
      directionCh: require('../../../static/img/v_world/directionCh.png'),
      directionEng: require('../../../static/img/v_world/directionEng.png'),
      enPic: require('../../../static/img/v_world/wechat.png'),
      lang: localStorage.getItem('LOCALE'),
      direction: window.orientation
    }
  },
  mounted() {
    // console.log(this.direction, '橫豎')

    //偵測WECHAT
    var ua = navigator.userAgent.toLowerCase()
    var isWeixin = ua.indexOf('micromessenger') != -1
    if (isWeixin) {
      $('.wechatMask').css('display', 'block')
      //   console.log('wechat')
    } else {
      //   console.log('no wechat')
      $('.wechatMask').css('display', 'none')
    }
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      //   console.log('直向')
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
        // console.log('橫向')
      } else {
        // console.log('直向')
        $('.directionMask').css('display', 'none')
      }
    }
  },
  methods: {
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
      //   console.log(sound)
    },
    //語系變更
    GetLanguage(event) {
      var lang = event.target.value
      localStorage.setItem('LOCALE', lang)
      this.$i18n.locale = lang
    },
    actionLogin() {
      var device_id = sessionStorage.getItem('device')
      //   console.log(device_id, '直直')
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
          //   window.open(
          //     ' https://example.invalid/APPDL/Android/Browser/VWorld.apk'
          //   )
          window.location =
            ' https://example.invalid/APPDL/Android/Browser/VWorld.apk'
        }
        if (isIOS) {
          //   window.open(
          //     ' itms-services://?action=download-manifest&url=https://example.invalid/APPDL/IOS/Browser/VWorld.plist'
          //   )
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
    direction: function(val) {
      //   console.log('橫向')
    }
  }

  //     direction: {
  //       handler: function(val) {
  //         console.log(val, '551313')
  //       }
  //     }

  //     //        //橫向禁止
  //     //     if (window.orientation == 90 || window.orientation == -90) {
  //     //       $('.directionMask').css('display', 'block')
  //     //       console.log('橫向')
  //     //     } else {
  //     //       $('.directionMask').css('display', 'none')
  //     //     }
  //   }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->

<style scoped >
.submitBtn {
  background-color: transparent;
  border: 0;
}
</style>
