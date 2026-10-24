<template>
  <div id="G733">
    <LoginHeader></LoginHeader>
    <div class="single_block">
      <!-- LoginMain -->
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainAll">
          <div class="pcmainBg">
            <div class="mainLogin"></div>
            <div class="loginInfo">
              <div class="inputvalidate" style="height:100%;">
                <!-- input方格 -->
                <div class="info">
                  <div type="text" class="grayInput input_all" disabled="disabled">
                    <div class="SettingInputLeft">{{ $t("account") }}</div>&nbsp;
                    <div class="SettingInputRight">{{ account }}</div>
                  </div>
                </div>
                <div class="inputvalidate">
                  <div class="info">
                    <input
                      @keyup="checkStore()"
                      v-model="newPassword"
                      name="newpass"
                      ref="newpass"
                      v-validate="'required|min:6|max:12|alpha_num'"
                      :data-vv-as="$t('newPassword')"
                      type="password"
                      :placeholder="$t('newPassword')"
                      class="yellowInput input_all"
                    />
                    <img
                      src="../../assets/img/G733/xx.png"
                      alt
                      class="clearBtn"
                      @click="resetnewPassword()"
                    />
                  </div>
                  <label
                    v-show="errors.has('newpass')"
                    for="newpass"
                    class="validateFont"
                  >{{ errors.first('newpass') }}</label>
                </div>
                <div class="inputvalidate">
                  <div class="info">
                    <input
                      @keyup="checkStore()"
                      v-model="checkPassword"
                      name="checknew"
                      v-validate="'required|confirmed:newpass'"
                      :data-vv-as="$t('confirmpassword')"
                      type="password"
                      :placeholder="$t('confirmpassword')"
                      class="yellowInput input_all"
                    />
                    <img
                      src="../../assets/img/G733/xx.png"
                      alt
                      class="clearBtn"
                      @click="resetcheckPassword()"
                    />
                  </div>
                  <label
                    v-show="errors.has('checknew')"
                    class="validateFont"
                  >{{ $t("inconsistentPassword") }}</label>
                </div>
              </div>
            </div>
          </div>
          <div class="silverBorder btnBorder_all">
            <button
              type="submit"
              :disabled="!canStore"
              @click="playSound();resetPassword()"
              class="redBtn btn_all"
            >{{ $t("store") }}</button>
          </div>
        </div>
      </div>
    </div>
    <!-- -->
    <div class="mask" v-if="isResetNewpass">
      <div class="Popups_Modal">
        <div class="PopupsTitle">{{ $t("prompt") }}</div>
        <div class="PopupsConAll">
          <div class="PopupsCon popupsConFont">{{ $t("passwordupdated") }}</div>
          <div class="PopupsBtn">
            <a href="/">
              <div class="silverBorder btnBorder_all">
                <button
                  type="submit"
                  style="padding:9px auto;"
                  class="redBtn btn_all"
                >{{ $t("confirm") }}</button>
              </div>
            </a>
          </div>
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>     
<script>
import LoginHeader from '@/components/G733/Login_header'
import maskModal from './Modal'
import { mapGetters, mapActions } from 'vuex'
import { truncate } from 'fs'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['isResetNewpass'])
  },
  data() {
    return {
      canStore: false,
      account: sessionStorage.getItem('username'),
      newPassword: '',
      checkPassword: ''
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
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    resetnewPassword() {
      this.newPassword = ''
    },
    resetcheckPassword() {
      this.checkPassword = ''
    },

    checkStore() {
      if (this.newPassword != '' && this.checkPassword != '') {
        this.$validator.validateAll().then(valid => {
          if (valid) {
            this.canStore = true
          } else {
            this.canStore = false
          }
        })
      } else {
        this.canStore = false
      }
    },
    resetPassword() {
      let data = {
        verify_Code: sessionStorage.getItem('verify_Code'),
        newPassword: [REDACTED],
        checkPassword: [REDACTED]
      }
      this.$store.dispatch('actionResetPassword', data)
    }
  }
}
</script>       