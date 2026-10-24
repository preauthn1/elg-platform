<template>
  <div id="C711Mobile">
    <div class="pageBg CwidthHeight widthHeightvh fontFamily">
      <LoginHeader></LoginHeader>
      <div class="pageCon" style="margin-top:20px;">
        <div class="yellow_borderInput" style="height:42px;margin-bottom:25px;">
          <div class="black_InputAll black_inputbg blackInput">
            <span class="inputFontLeft">{{ $t("account") }}：</span>
            <span class="inputFontRight">{{ account }}</span>
          </div>
        </div>新密碼
        <div class="inputvalidate">
          <div class="form-group ListborderInput InputMain">
            <span class="InputLeft">{{ $t("newPassword") }}</span>
            <input
              @keyup="checkStore()"
              v-model="newPassword"
              name="newpass"
              ref="newpass"
              v-validate="'required|min:6|max:12|alpha_num'"
              :data-vv-as="$t('newPassword')"
              type="password"
              :placeholder="$t('newPassword')"
              class="form-control ListInputAllR Listinputbg"
            />
            <img
              src="../../assets/img/C711_Mobile/grayClose.png"
              @click="resetnewPassword()"
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
          <div class="form-group ListborderInput InputMain">
            <span class="InputLeft">{{ $t("confirmpassword") }}</span>
            <input
              @keyup="checkStore()"
              v-model="checkPassword"
              name="checknew"
              v-validate="'required|confirmed:newpass'"
              :data-vv-as="$t('confirmpassword')"
              type="password"
              :placeholder="$t('confirmpassword')"
              class="form-control ListInputAllR Listinputbg"
            />

            <img
              src="../../assets/img/C711_Mobile/grayClose.png"
              @click="resetcheckPassword()"
              class="inputClose"
            />
          </div>
          <label
            v-show="errors.has('checknew')"
            class="validateDiv"
          >{{ $t("inconsistentPassword") }}</label>
        </div>
        <!-- 按鈕 -->
        <div class="btn-blue">
          <button
            type="submit"
            :disabled="!canStore"
            @click="playSound();resetPassword()"
            style="padding:9px auto;"
          >{{ $t("store") }}</button>
        </div>
      </div>
      <!-- 密碼更新彈窗 -->
      <div class="mask" v-if="isResetNewpass">
        <div class="Popups_Modal">
          <div class="PopupsTitle">{{ $t("prompt") }}</div>
          <div class="PopupsConAll">
            <div class="PopupsCon popupsConFont">{{ $t("passwordupdated") }}</div>
            <div class="PopupsBtn">
              <a href="/">
                <div class="btn-blue">
                  <button type="submit" style="padding:9px auto;">{{ $t("confirm") }}</button>
                </div>
              </a>
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
    // 驗證
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
    // 確認送出
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