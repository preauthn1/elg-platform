<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <form @submit.prevent="setNewPass()" class="AllContent">
      <div class="changeAcc clearfix">
        <div class="float-left">
          <span>{{ $t("account") }}:</span>
        </div>
        <div class="float-right">{{ Username }}</div>
      </div>
      <div class="blueInput info_text_box">
        <span>{{ $t("oldPassword") }}</span>
        <input
          @keyup="blur()"
          v-model="oldPass"
          name="oldpass"
          v-validate="'required|min:6|max:12|alpha_num'"
          :data-vv-as="$t('oldPassword')"
          maxlength="12"
          type="password"
          :placeholder="$t('oldPassword')"
          class="changepwinput"
        />
        <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetoldPass()" />
        <label
          v-show="errors.has('oldpass')"
          for="oldpass"
          class="validate"
        >{{ errors.first('oldpass') }}</label>
      </div>
      <div class="blueInput info_text_box">
        <span>{{ $t("newPassword") }}</span>
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
          class="changepwinput"
        />
        <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetnewPass()" />
        <label
          v-show="errors.has('newpass')"
          for="newpass"
          class="validate"
        >{{ errors.first('newpass') }}</label>
      </div>
      <div class="blueInput info_text_box">
        <span>{{ $t("confirmpassword") }}</span>
        <input
          @keyup="blur()"
          v-model="checkNew"
          name="checknew"
          v-validate="'required|confirmed:newpass'"
          :data-vv-as="$t('confirmpassword')"
          type="password"
          :placeholder="$t('confirmpassword')"
          class="changepwinput"
        />
        <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetcheckNew()" />
        <label v-show="errors.has('checknew')" class="validate">{{ $t("inconsistentPassword") }}</label>
      </div>

      <button
        type="submit"
        :disabled="!create_visible"
        @click="playSound();"
        class="imgfontAll submitBtn"
      >
        <span>{{ $t("finish") }}</span>
      </button>
      <!-- 送出提示窗 -->
      <div v-if="isSuccess" class="mask">
        <div class="modalBlueAll">
          <div class="content">
            <div>
              <p>{{ $t("thePasswordHasBeenUpdatedPleaseReEnter") }}</p>
            </div>
            <div class="grayBtn modalBtn" style="margin:auto;">
              <button
                type="button"
                @click="logout()"
                style="background:none; border:none;"
              >{{ $t("confirm") }}</button>
            </div>
          </div>
        </div>
      </div>
    </form>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
import LoginHeader from '@/components/v_world/Login_header'
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
      if (this.oldPass !== '' && this.newPass !== '' && this.checkNew !== '') {
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
  z-index: 5;
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
