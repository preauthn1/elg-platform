<template>
  <div id="C711Mobile">
    <div class="pageBg CwidthHeight widthHeightvh fontFamily">
      <LoginHeader></LoginHeader>
      <div class="pageCon">
        <form @submit.prevent="setNewPass()">
          <!-- 帳號 -->
          <div class="yellow_borderInput marginTop20" style="margin-bottom:25px;">
            <div class="black_InputAll black_inputbg fontsizelogin clearfix">
              <div class="pwFont float-left setPic">
                <div class="SettingNameLeft">{{ $t("account") }}</div>
                <div class="SettingNameRight">{{ Username }}</div>
              </div>&nbsp;
            </div>
          </div>
          <!-- 舊密碼 -->
          <div class="inputvalidate">
            <div class="ListborderInput">
              <span class="forgetphoneCode setNewPw" style="width:130px;">{{ $t("oldPassword") }}</span>
              <input
                class="ListInputAllR Listinputbg"
                @keyup="blur()"
                v-model="oldPass"
                name="oldpass"
                v-validate="'required|min:6|max:12|alpha_num'"
                :data-vv-as="$t('oldPassword')"
                maxlength="12"
                type="password"
                :placeholder="$t('oldPassword')"
              />
              <img
                src="../../assets/img/C711_Mobile/grayClose.png"
                @click="resetoldPass()"
                class="inputClose"
              />
            </div>
            <label
              v-show="errors.has('oldpass')"
              for="oldpass"
              class="validateDiv"
            >{{ errors.first('oldpass') }}</label>
          </div>
          <!-- 新密碼 -->
          <div class="inputvalidate">
            <div class="ListborderInput">
              <span class="forgetphoneCode setNewPw" style="width:130px; ">{{ $t("newPassword") }}</span>
              <input
                class="ListInputAllR Listinputbg"
                @keyup="blur()"
                v-model="newPass"
                name="newpass"
                ref="newpass"
                v-validate="'required|min:6|max:12|alpha_num'"
                :data-vv-as="$t('newPassword')"
                maxlength="12"
                type="password"
                :placeholder="$t('newPassword')"
              />
              <img
                src="../../assets/img/C711_Mobile/grayClose.png"
                @click="resetnewPass()"
                class="inputClose"
              />
            </div>
            <label
              v-show="errors.has('newpass')"
              for="newpass"
              class="validateDiv"
            >{{ errors.first('newpass') }}</label>
          </div>
          <!-- 確認密碼 -->
          <div class="inputvalidate">
            <div class="ListborderInput">
              <span
                class="forgetphoneCode setNewPw"
                style="width:140px; "
              >{{ $t("confirmpassword") }}</span>
              <input
                class="ListInputAllR Listinputbg"
                @keyup="blur()"
                v-model="checkNew"
                name="checknew"
                v-validate="'required|confirmed:newpass'"
                :data-vv-as="$t('confirmpassword')"
                type="password"
                :placeholder="$t('confirmpassword')"
              />
              <img
                src="../../assets/img/C711_Mobile/grayClose.png"
                @click="resetcheckNew()"
                class="inputClose"
              />
            </div>
            <label
              v-show="errors.has('checknew')"
              class="validateDiv"
            >{{ $t("inconsistentPassword") }}</label>
          </div>
          <!-- 送出按鈕 -->

          <div class="btn-blue">
            <button
              type="submit"
              :disabled="!create_visible"
              @click="playSound();"
              style="padding:9px auto;"
            >{{ $t("finish") }}</button>
          </div>
        </form>
      </div>
      <!--更換密碼確認後登出-->
      <div class="mask" v-if="isSuccess">
        <div class="Popups_Modal">
          <div class="PopupsTitle">{{ $t("prompt") }}</div>
          <div class="PopupsConAll">
            <div class="PopupsCon popupsConFont">{{ $t("thePasswordHasBeenUpdatedPleaseReEnter") }}</div>
            <div class="PopupsBtn" @click="logout()">
              <div class="btn-blue">
                <button
                  type="submit"
                  @click="playSound();"
                  style="padding:9px auto;"
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
</template>
<script>
import LoginHeader from '@/components/C711_Mobile/Login_header'
import maskModal from './Modal'
import { truncate } from 'fs'
import { mapGetters, mapActions } from 'vuex'
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
      create_visible: false, //true才能點submit btn
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
    //   點擊音效
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    resetoldPass() {
      //清除舊密碼
      this.oldPass = ''
    },
    resetnewPass() {
      //清除新密碼
      this.newPass = ''
    },
    resetcheckNew() {
      //清除新密碼確認
      this.checkNew = ''
    },

    blur() {
      // input輸入 資料基礎驗證
      if (this.oldPass !== '' && this.newPass !== '' && this.checkNew !== '') {
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