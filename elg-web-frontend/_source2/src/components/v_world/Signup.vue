<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="AllContent">
      <form @submit.prevent="create()">
        <div class="form-group blueInput info_text_box">
          <span class="blueInputFont">*</span>
          <span>{{ $t("account") }}</span>
          <input
            @keyup="blur()"
            v-model="newAccount"
            name="userId"
            v-validate="'required|min:4|max:10|alpha_num'"
            :data-vv-as="$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
            maxlength="10"
            type="text"
            id="account"
            :placeholder="$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
          />
          <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetnewAccount()" />
          <label
            v-show="errors.has('userId')"
            for="userId"
            class="validate"
          >{{ errors.first('userId') }}</label>
        </div>

        <div class="form-group blueInput info_text_box">
          <span class="blueInputFont">*</span>
          <span>{{ $t("setpassword") }}</span>
          <input
            @keyup="blur()"
            v-model="newPassword"
            name="password"
            v-validate="'required|min:6|max:12|alpha_num'"
            :data-vv-as="$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
            maxlength="12"
            type="password"
            :placeholder="$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
            ref="password"
          />
          <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetnewPassword()" />

          <!--  -->
          <label
            v-show="errors.has('password')"
            for="password"
            class="validate"
          >{{ errors.first('password') }}</label>
        </div>
        <div class="form-group blueInput info_text_box">
          <span class="blueInputFont">*</span>
          <span>{{ $t("confirmpassword") }}</span>
          <input
            @keyup="blur()"
            v-model="rePassword"
            name="comfirmPass"
            v-validate="'required|confirmed:password'"
            :data-vv-as="$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
            maxlength="12"
            type="password"
            :placeholder="$t('pleaseEnterYourAccountNumber',{min:4,max:10})"
          />
          <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetrePassword()" />
          <label
            v-show="errors.has('comfirmPass')"
            class="validate"
          >{{ $t("inconsistentPassword") }}</label>
        </div>
        <div class="form-group blueInput info_text_box">
          <span class="blueInputFont">*</span>
          <span>{{ $t("sharingCode") }}</span>
          <input
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
            v-else
            @keyup="blur()"
            v-model="code"
            name="code"
            v-validate="'required'"
            :data-vv-as="$t('pleaseEnterTheSharingCode')"
            type="text"
          />

          <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetcode()" />
          <label v-show="errors.has('code')" class="validate">{{ errors.first('code') }}</label>
        </div>
        <div class="imgfontAll">
          <button
            type="submit"
            :disabled="!create_visible"
            @click="playSound()"
            style="background:none; border:none;"
          >{{ $t("signUp") }}</button>
        </div>
      </form>
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
    </div>

    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import LoginHeader from '@/components/v_world/Login_header'
import { mapGetters, mapMutations } from 'vuex'
import maskModal from './Modal'
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
export default {
  components: {
    LoginHeader,
    maskModal
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
      create_visible: false
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
    //進頁面先reload一次
    // $(document).ready(function() {
    //   if (location.href.indexOf('#re') == -1) {
    //     location.href = location.href + '#re'
    //     location.reload()
    //   }
    // })
  },
  methods: {
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
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style scoped>
.invite-code-panel .editable-clear-x {
  right: 15px;
  display: none;
}

.invite-code-panel .invite-code-input:valid + .editable-clear-x {
  display: inline;
}
</style>
