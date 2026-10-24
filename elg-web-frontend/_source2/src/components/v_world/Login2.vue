<template>
  <div class="login2">
    <img src="../../assets/img/v_world/background.png" />
    <div class="signin">
      <form>
        <div class="form-group">
          <input
            v-model="account"
            class="form-control inputbg"
            id="InputUser"
            :placeholder="$t('username')"
            required
          />
        </div>
        <div class="form-group">
          <input
            v-model="password"
            type="password"
            class="form-control inputbg"
            id="InputPassword"
            :placeholder="$t('password')"
            required
          />
        </div>
        <!-- <div class="languageSelect">
          <select id="inputState" class="form-control inputbg" @change="GetLanguage($event)">
            <option
              v-for="(value,key) in this.$LANGUAGE"
              :key="key"
              :value="key"
              :selected="key === language"
            >{{ value }}</option>
          </select>
        </div>-->
        <div class="clearfix loginBtn">
          <div class="float-left imgfont blueBtn" @click="playSound();actionLogin()">
            <span>{{ $t("signIn") }}</span>
          </div>
          <div class="float-right imgfont blueBtn">
            <a href="/Signup" @click="playSound">
              <span>{{ $t("signUp") }}</span>
            </a>
          </div>
        </div>
        <!-- <div class="imgfont float-left" @click="actionLogin">
            <span>{{ $t("signIn") }}</span>
          </div>
          <div class="imgfont float-right">
            <a href="/Signup">
              <span>{{ $t("signUp") }}</span>
            </a>
        </div>-->
      </form>
      <div class="forgetpwBtn">
        <router-link to="/ForgetPassword">
          <span>{{ $t("forget") }}</span>
        </router-link>
      </div>
    </div>
    <div
      class="loginFooter"
    >Customer Service contact:wechat ID "abababc" Bi-weekly maintenance:Thursday 08:00AM~11:00AM</div>
    <div v-if="isVerifyError" class="mask">
      <div class="modalBlueAll">
        <div>
          <div>
            <p>{{ errorMsg }}</p>
          </div>
          <div>
            <router-link
              v-if="errorCode == 9006"
              to="/RetrievePassword"
              class="btn btn-secondary"
              style="background:none;border:none"
            >
              <div class="grayBtn modalBtn">{{ $t("confirm") }}</div>
            </router-link>
            <router-link
              v-if="errorCode == 9007"
              to="/PhoneVerification"
              class="btn btn-secondary"
              style="background:none;border:none"
            >
              <div class="grayBtn modalBtn">{{ $t("confirm") }}</div>
            </router-link>
            <router-link v-if="errorCode == 9008" to class="btn btn-secondary">
              <div class="grayBtn modalBtn">{{ $t("confirm") }}</div>
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
var winHeight = $(window).height()
$(window).resize(function() {
  var thisHeight = $(this).height()
  var keyboardHeight = thisHeight - winHeight
  $('.loginFooter').css({ bottom: keyboardHeight + 'px' })
})

// console.log(window.orientation)
// window.addEventListener('orientationchange', function() {
//   console.log(window.orientation)
//   alert(window.orientation)
// })
export default {
  computed: {
    ...mapGetters(['isVerifyError', 'errorMsg', 'errorCode'])
  },
  data() {
    return {
      language: this.$i18n.locale,
      account: '',
      password: ''
    }
  },
  methods: {
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    //語系變更
    GetLanguage(event) {
      var lang = event.target.value
      localStorage.setItem('LOCALE', lang)
      this.$i18n.locale = lang
    },
    actionLogin() {
      let data = {
        account: this.account,
        password: [REDACTED]
      }
      this.$store.dispatch('actionLogin', data)
    }
  },
  created() {
    this.$store.commit('LOGINERROR_RESET')
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
.submitBtn {
  background-color: transparent;
  border: 0;
}
</style>
