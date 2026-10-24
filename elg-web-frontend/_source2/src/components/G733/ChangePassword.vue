<template>
  <div id="G733">
    <div class="mobileHeader">
      <LoginHeader></LoginHeader>
    </div>
    <!-- LoginMain -->
    <div class="single_block">
      <div class="LoginMain footerBottom">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainAll">
          <div class="pcmainBg">
            <div class="mainLogin"></div>
            <div class="loginInfo">
              <form @submit.prevent="setNewPass()">
                <div class="info">
                  <input
                    type="text"
                    name="password"
                    :placeholder="$t('account')"
                    class="grayInput input_all"
                    disabled="disabled"
                  />
                  <span class="RightFont">{{ Username }}</span>
                </div>
                <div class="inputvalidate">
                  <div class="info">
                    <input
                      @keyup="blur()"
                      v-model="oldPass"
                      name="oldpass"
                      v-validate="'required|min:6|max:12|alpha_num'"
                      :data-vv-as="$t('oldPassword')"
                      maxlength="12"
                      type="password"
                      :placeholder="$t('oldPassword')"
                      class="yellowInput input_all"
                    />
                    <span class="RightFont">{{$t('oldPassword')}}</span>
                    <img
                      src="../../assets/img/G733/xx.png"
                      alt
                      class="clearBtn"
                      @click="resetoldPass()"
                    />
                  </div>
                  <label
                    v-show="errors.has('oldpass')"
                    for="oldpass"
                    class="validateFont"
                  >{{ errors.first("oldpass") }}</label>
                </div>
                <div class="inputvalidate">
                  <div class="info">
                    <input
                      @keyup="blur()"
                      v-model="newPass"
                      name="newpass"
                      ref="newpass"
                      v-validate="'required|min:6|max:12|alpha_num'"
                      :data-vv-as="$t('newPassword')"
                      maxlength="12"
                      type="password"
                      :placeholder="$t('newPassword')"
                      class="yellowInput input_all"
                    />
                    <span class="RightFont">{{$t('newPassword')}}</span>
                    <img
                      src="../../assets/img/G733/xx.png"
                      alt
                      class="clearBtn"
                      @click="resetnewPass()"
                    />
                  </div>
                  <label
                    v-show="errors.has('newpass')"
                    for="newpass"
                    class="validateFont"
                  >{{ errors.first("newpass") }}</label>
                </div>
                <div class="inputvalidate">
                  <div class="info">
                    <input
                      @keyup="blur()"
                      v-model="checkNew"
                      name="checknew"
                      v-validate="'required|confirmed:newpass'"
                      :data-vv-as="$t('confirmpassword')"
                      type="password"
                      :placeholder="$t('confirmpassword')"
                      class="yellowInput input_all"
                    />
                    <span class="RightFont">{{$t('confirmpassword')}}</span>
                    <img
                      src="../../assets/img/G733/xx.png"
                      alt
                      class="clearBtn"
                      @click="resetcheckNew()"
                    />
                  </div>
                  <label
                    v-show="errors.has('checknew')"
                    class="validateFont"
                  >{{ $t("inconsistentPassword") }}</label>
                </div>

                <div class="silverBorder btnBorder_all modalBtn">
                  <button
                    type="submit"
                    :disabled="!create_visible"
                    @click="playSound()"
                    class="redBtn btn_all"
                  >{{ $t("finish") }}</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <!--密碼更新 重新登入彈窗-->
        <div class="mask" v-if="isSuccess">
          <div class="Popups_Modal">
            <div class="PopupsTitle">{{ $t("prompt") }}</div>
            <div class="PopupsConAll">
              <div
                class="PopupsCon popupsConFont"
              >{{ $t("thePasswordHasBeenUpdatedPleaseReEnter") }}</div>
              <div class="PopupsBtn" @click="logout()">
                <div class="silverBorder btnBorder_all modalBtn">
                  <button
                    type="submit"
                    @click="playSound()"
                    class="redBtn btn_all"
                  >{{ $t("confirm") }}</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- 彈窗 -->
        <maskModal></maskModal>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import { mapGetters, mapMutations } from 'vuex'
import maskModal from './Modal'
import { truncate } from 'fs'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['isSuccess'])
  },
  data() {
    return {
      Username: window.sessionStorage.getItem('username'),
      create_visible: false,
      oldPass: '',
      newPass: '',
      checkNew: ''
    }
  },
  mounted() {
    //手機方向
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
    resetoldPass() {
      this.oldPass = ''
    },
    resetnewPass() {
      this.newPass = ''
    },
    resetcheckNew() {
      this.checkNew = ''
    },

    blur() {
      // input輸入 資料基礎驗證
      if (this.oldPass != '' && this.newPass != '' && this.checkNew != '') {
        //   this.$validator.validateAll() 來檢查每個欄位，只有在全部通過的時候才會將資料送出
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
    //更新密碼
    setNewPass() {
      let passwords = {
        password: [REDACTED],
        new_password: [REDACTED],
        new_password_confirmation: this.checkNew
      }
      this.$store.dispatch('actionChangePassword', passwords)
    },
    logout() {
      this.$store.dispatch('actionLogout')
    }
  }
}
</script>