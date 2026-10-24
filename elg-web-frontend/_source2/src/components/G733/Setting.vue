<template>
  <div id="G733">
    <div class="mobileHeader">
      <LoginHeader></LoginHeader>
    </div>
    <!-- LoginMain -->
    <div class="single_block">
      <div class="LoginMain" style="height:90vh;">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainAll">
          <div class="pcmainBg">
            <div class="mainLogin"></div>
            <div class="loginInfo">
              <!-- 編輯個人資料 -->
              <div class="goldBorder btnBorder_all">
                <a
                  href="/SettingPersonal"
                  onclick="ga('send', 'event', 'Setting', 'SettingPersonal_Click', 'SettingPersonal');"
                >
                  <div class="blackBtn settingBtn">
                    <img src="../../assets/img/G733/setting.png" alt class="blackBtnPic" />
                    <span class="blackBtnFont">{{ $t("editProfile") }}</span>
                    <img src="../../assets/img/G733/R.png" alt class="RightBtnPic" />
                  </div>
                </a>
              </div>
              <!-- 語言 -->
              <div class="goldBorder btnBorder_all">
                <a
                  href="/Language"
                  onclick="ga('send', 'event', 'Setting', 'Language_Click', 'Language');"
                >
                  <div class="blackBtn settingBtn">
                    <img src="../../assets/img/G733/language.png" alt class="blackBtnPic" />
                    <span class="blackBtnFont">{{ $t("language") }}</span>
                    <img src="../../assets/img/G733/R.png" alt class="RightBtnPic" />
                  </div>
                </a>
              </div>
              <!-- 行動條碼 -->
              <div class="goldBorder btnBorder_all">
                <a href="/">
                  <div class="blackBtn settingBtn">
                    <img src="../../assets/img/G733/qrcode.png" alt class="blackBtnPic" />
                    <span class="blackBtnFont">{{ $t("qrcode") }}</span>
                    <img src="../../assets/img/G733/R.png" alt class="RightBtnPic" />
                  </div>
                </a>
              </div>
              <!-- 最新活動 -->
              <div class="goldBorder btnBorder_all">
                <a
                  href="/Events"
                  onclick="ga('send', 'event', 'Setting', 'Events_Click', 'Events');"
                >
                  <div class="blackBtn settingBtn">
                    <img src="../../assets/img/G733/new.png" alt class="blackBtnPic" />
                    <span class="blackBtnFont">{{ $t("newEvents") }}</span>
                    <img src="../../assets/img/G733/R.png" alt class="RightBtnPic" />
                  </div>
                </a>
              </div>
              <!-- 關於 -->
              <div class="goldBorder btnBorder_all">
                <a href="/About" onclick="ga('send', 'event', 'Setting', 'About_Click', 'About');">
                  <div class="blackBtn settingBtn">
                    <img src="../../assets/img/G733/about.png" alt class="blackBtnPic" />
                    <span class="blackBtnFont">{{ $t("about") }}</span>
                    <img src="../../assets/img/G733/R.png" alt class="RightBtnPic" />
                  </div>
                </a>
              </div>
              <div class="grayInput input_all">
                <img src="../../assets/img/G733/person.png" alt class="blackBtnPic" />

                <span class="myShareCode">{{ $t("myShareCode") }}：</span>
                <div class="ShareCodeFont">
                  <span style="display:none">{{localHost}}</span>
                  <span>{{SHARE_CODE}}</span>
                </div>
                <div class="silverBorder btnBorder_all copy" style="width:75px;">
                  <button
                    id="shareCode"
                    :data-clipboard-text="localHost+SHARE_CODE"
                    @click="copy()"
                    class="yellowBtn btn_all"
                  >{{ $t("copy") }}</button>
                </div>
              </div>
              <div class="silverBorder btnBorder_all">
                <button
                  type="submit"
                  @click="playSound();logOut()"
                  class="grayBtn btn_all"
                >{{ $t("signOut") }}</button>
              </div>
            </div>
            <div class="mask" v-if="isShareCodeCopy">
              <div class="Popups_Modal">
                <div class="PopupsTitle">{{ $t("prompt") }}</div>
                <div class="PopupsConNoBtn">
                  <div class="PopupsConNo popupsConFont">{{ $t("theShareCodeHasBeenCopied") }}</div>
                </div>
              </div>
            </div>
            <!-- 彈窗 -->
            <maskModal></maskModal>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import { mapGetters } from 'vuex'
import clipboard from 'clipboard'
import maskModal from './Modal'
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