<template>
  <div class="single_block">
    <!-- <div class="login_wrap">
      <img src="/static/img/C711_PC/vworld_s.png" />
      <div class="login_form">
        <div class="ele-border">
          <input
            v-model="account"
            class="ele_border_in gradual-black"
            type="text"
            :placeholder="$t('loginphone')"
          />
        </div>
        <div class="ele-border">
          <input
            v-model="password"
            class="ele_border_in gradual-black"
            type="password"
            :placeholder="$t('password')"
          />
        </div>
        <div class="ele-border">
          <button class="ele_border_in gradual-red" @click="actionLogin()">{{$t('signIn')}}</button>
        </div>
      </div>
      <div class="login_footer">
        <div class="ele-border">
          <button class="ele_border_in gradual-gold" @click="SignUp()">{{$t('signUp')}}</button>
        </div>
        <div class="ele-border">
          <button class="ele_border_in gradual-grey" @click="ForgetPass()">{{$t('forget')}}</button>
        </div>
      </div>
    </div>
    <PopUp></PopUp>-->
  </div>
</template>
<script>
// import { mapGetters, mapActions } from 'vuex'
// import PopUp from './Pop_up'
export default {
  //   components: {
  //     PopUp
  //   },
  //   computed: {
  //     ...mapGetters([''])
  //   },
  //   data() {
  //     return {
  //       language: this.$i18n.locale,
  //       account: localStorage.getItem('user_account') || '',
  //       password: [REDACTED]'user_password') || '',
  //       lang: localStorage.getItem('LOCALE')
  //     }
  //   },
  //   mounted() {},
  //   methods: {
  //     //語系變更
  //     // GetLanguage(event) {
  //     //     var lang = event.target.value
  //     //     localStorage.setItem('LOCALE', lang)
  //     //     this.$i18n.locale = lang
  //     // },
  //     actionLogin() {
  //       var device_id = sessionStorage.getItem('device')
  //
  //       let data = {
  //         account: this.account,
  //         password: [REDACTED],
  //         device_id: device_id
  //       }
  //
  //       this.$store.dispatch('actionLogin', data)
  //     },
  //     SignUp() {
  //       this.$router.push('/Signup')
  //     },
  //     ForgetPass() {
  //       this.$router.push('/ForgetPassword')
  //     }
  //   },
  created() {
    // this.$store.commit('HIDE_API_RES')

    //// 未登入 跳轉到登入頁後清除sessionStorage 導假大廳
    // sessionStorage.clear()
    this.$router.push('/Lobby')
  }
}
</script>