<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="SettingItem">
      <div class="SettingData clearfix">
        <div class="float-left settingLeft">
          <div
            class="SettingUser"
          >{{ $t("nickname") }}：{{ Nickname!== undefined?Nickname:Username }}</div>
          <div class="SettingUser">{{ $t("points") }}：{{ getAcctInfo.balance }}</div>
        </div>
        <div
          class="float-right settingRight"
          onClick="ga('send', 'event', 'Setting', 'SettingPersonal_Click', 'SettingPersonal');"
        >
          <a href="/SettingPersonal">
            <img src="../../assets/img/v_world/right.png" />
          </a>
        </div>
      </div>
      <div class="BtnAll">
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a
              href="/Language"
              onClick="ga('send', 'event', 'Setting', 'Language_Click', 'Language');"
            >
              <div class="float-left bluePic">
                <img src="../../assets/img/v_world/language.png" />
              </div>
              <div class="float-left settingFont">
                <span>{{ $t("language") }}</span>
              </div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a href="/">
              <div class="float-left bluePic">
                <img src="../../assets/img/v_world/qrcode.png" />
              </div>
              <div class="float-left settingFont">
                <span>{{ $t("qrcode") }}</span>
              </div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a href="/Events" onClick="ga('send', 'event', 'Setting', 'Events_Click', 'Events');">
              <div class="float-left bluePic">
                <img src="../../assets/img/v_world/new.png" />
              </div>
              <div class="float-left settingFont">
                <span>{{ $t("newEvents") }}</span>
              </div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a href="/About" onClick="ga('send', 'event', 'Setting', 'About_Click', 'About');">
              <div class="float-left bluePic">
                <img src="../../assets/img/v_world/about.png" />
              </div>
              <div class="float-left settingFont">
                <span>{{ $t("about") }}</span>
              </div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
      </div>
      <div class="SettingNum clearfix">
        <div class="shareCode float-left">{{ $t("myShareCode") }}</div>
        <div class="shareCode float-left">
          <span style="display:none">{{localHost}}</span>
          <span>{{SHARE_CODE}}</span>
        </div>
        <div
          class="shareCodePic float-right"
          id="shareCode"
          :data-clipboard-text="localHost+SHARE_CODE"
          @click="copy()"
        >
          <img src="../../assets/img/v_world/sharecode.png" class="sharePic" />
          <span class="shareFont">{{ $t("copy") }}</span>
        </div>
      </div>
      <div class="imgfontAll settingBtn" @click="playSound();logOut()">
        <span>{{ $t("signOut") }}</span>
      </div>
    </div>
    <div v-if="isShareCodeCopy" class="mask">
      <div class="modalBlueAll">
        <p>{{ $t("theShareCodeHasBeenCopied") }}</p>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters } from 'vuex'
import maskModal from './Modal'
import clipboard from 'clipboard'
import LoginHeader from '@/components/v_world/Login_header'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['getAcctInfo'])
  },
  data() {
    return {
      vPoints: window.sessionStorage.getItem('balance'),
      Nickname: window.sessionStorage.getItem('nickname'),
      Username: window.sessionStorage.getItem('username'),
      SHARE_CODE: window.sessionStorage.getItem('SHARE_CODE'),
      localHost: 'http://' + location.host + '/Signup/',
      isShareCodeCopy: false
    }
  },
  methods: {
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    logOut() {
      this.$store.dispatch('actionLogout')
    },
    copy() {
      new clipboard('#shareCode')
      this.isShareCodeCopy = true
      setTimeout(() => {
        this.isShareCodeCopy = false
      }, 1500)
      //   console.log('http://' + location.host + '/', '網址')
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
  created() {
    this.$store.dispatch('actionMember')
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
