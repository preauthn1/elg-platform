<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="AllContent">
      <div class="setpwAcc clearfix">
        <div class="float-left">
          <span>{{ $t("account") }}:</span>
        </div>
        <div class="float-right">{{ account }}</div>
      </div>
      <div class="blueInput info_text_box">
        <span>{{ $t("newPassword") }}</span>
        <input
          @keyup="checkStore()"
          v-model="newPassword"
          name="newpass"
          ref="newpass"
          v-validate="'required|min:6|max:12|alpha_num'"
          :data-vv-as="$t('newPassword')"
          type="password"
          :placeholder="$t('newPassword')"
        />
        <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetnewPassword()" />
        <label
          v-show="errors.has('newpass')"
          for="newpass"
          class="validate"
        >{{ errors.first('newpass') }}</label>
      </div>
      <div class="blueInput info_text_box">
        <span>{{ $t("confirmpassword") }}</span>
        <input
          @keyup="checkStore()"
          v-model="checkPassword"
          name="checknew"
          v-validate="'required|confirmed:newpass'"
          :data-vv-as="$t('confirmpassword')"
          type="password"
          :placeholder="$t('confirmpassword')"
        />
        <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetcheckPassword()" />
        <label v-show="errors.has('checknew')" class="validate">{{ $t("inconsistentPassword") }}</label>
      </div>
      <div class="setpwfont"></div>
      <button
        :disabled="!canStore"
        @click="playSound();resetPassword()"
        class="imgfontAll submitBtn"
      >
        <span>{{ $t("store") }}</span>
      </button>
    </div>
    <!-- 密碼已更新提示窗 -->
    <div v-if="isResetNewpass" class="mask">
      <div class="modalBlueAll">
        <div class="content">
          <div>
            <p>{{ $t("passwordupdated") }}</p>
          </div>
          <div>
            <router-link to="/" class="btn btn-secondary" style="background:none; border:none;">
              <div class="grayBtn modalBtn">{{ $t("confirm") }}</div>
            </router-link>
          </div>
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import { truncate } from 'fs'
import maskModal from './Modal'
import LoginHeader from '@/components/v_world/Login_header'
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
      if (this.newPassword !== '' && this.checkPassword !== '') {
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
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
.submitBtn {
  background-color: transparent;
  border: 0;
}
.mask {
  width: 100%;
  height: 100vh;
  position: fixed;
  top: 0;
  right: 0;
  z-index: 999;
  background-color: rgba(0, 0, 0, 0.7);
}
.noteWrap {
  width: 90%;
  background-color: #fff;
  color: #000;
  padding: 20px 0;
  margin: auto;
  position: relative;
  top: 50%;
  transform: translateY(-50%);
}
</style>
