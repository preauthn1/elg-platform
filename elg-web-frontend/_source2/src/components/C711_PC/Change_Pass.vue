<template>
  <div class="single_block">
    <LobbyHeader></LobbyHeader>
    <div>
      <div class="changePass_wrap">
        <h3>{{$t('changePassword')}}</h3>
        <div class="form fontsizelogin" style="margin:auto; width:70%;">
          <div class="ele-border input-X">
            <div>
              <span class="inputFontLeft">{{$t('account')}}</span>
              <p class="inputFontRight ele_border_in gradual-black">{{Username}}</p>
            </div>
          </div>
          <div class="validateDiv">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">{{$t('oldPassword')}}</span>
                <input
                  @keyup="blur()"
                  v-model="oldPass"
                  name="oldpass"
                  v-validate="'required|min:6|max:12|alpha_num'"
                  :data-vv-as="$t('oldPassword')"
                  class="inputFontRight dark-brown"
                  type="password"
                  :placeholder="$t('pleaseEnterTheEnglishCombinationOf6~10YardsForThePassword')"
                />
                <img
                  src="../../../static/img/C711_PC/grayClose.png"
                  class="inputClear"
                  @click="resetoldPass()"
                />
              </div>
            </div>
            <label
              v-show="errors.has('oldpass')"
              for="oldpass"
              class="validate_content"
            >{{ errors.first('oldpass') }}</label>
          </div>
          <div class="validateDiv">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">{{$t('newPassword')}}</span>
                <input
                  @keyup="blur()"
                  v-model="newPass"
                  name="newpass"
                  ref="newpass"
                  v-validate="'required|min:6|max:12|alpha_num'"
                  :data-vv-as="$t('newPassword')"
                  class="inputFontRight dark-brown"
                  type="password"
                  :placeholder="$t('pleaseEnterTheEnglishCombinationOf6~10YardsForThePassword')"
                />
                <img
                  src="../../../static/img/C711_PC/grayClose.png"
                  class="inputClear"
                  @click="resetnewPass()"
                />
              </div>
            </div>
            <label
              v-show="errors.has('newpass')"
              for="newpass"
              class="validate_content"
            >{{ errors.first('newpass') }}</label>
          </div>
          <div class="validateDiv">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">{{$t('confirmpassword')}}</span>
                <input
                  @keyup="blur()"
                  v-model="checkNew"
                  name="checknew"
                  v-validate="'required|confirmed:newpass'"
                  :data-vv-as="$t('confirmpassword')"
                  class="inputFontRight dark-brown"
                  type="password"
                  :placeholder="$t('pleaseEnterTheEnglishCombinationOf6~10YardsForThePassword')"
                />
                <img
                  src="../../../static/img/C711_PC/grayClose.png"
                  class="inputClear"
                  @click="resetcheckNew()"
                />
              </div>
            </div>
            <label
              v-show="errors.has('checknew')"
              for="checknew"
              class="validate_content"
            >{{ errors.first('checknew') }}</label>
          </div>
        </div>
        <div class="footer">
          <div class="btn-blue" style="margin:auto 10px;">
            <button :disabled="!create_visible" @click="setNewPass()">{{$t('finish')}}</button>
          </div>
          <div class="btn-orange" style="margin:auto 10px;">
            <button @click="cancel()" style="padding:9px auto;">{{$t('cancel1')}}</button>
          </div>
        </div>
      </div>
    </div>
    <!-- 改密碼成功彈窗 -->
    <div v-if="isSuccess" class="popUp_block" style="max-width:100%;">
      <div class="wrap">
        <div class="title">{{ $t("prompt") }}</div>
        <div class="content">
          <div>{{ $t("thePasswordHasBeenUpdatedPleaseReEnter") }}</div>
        </div>
        <div class="footer">
          <div class="btn-blue" @click="logout()">
            <button>{{ $t("confirm") }}</button>
          </div>
        </div>
      </div>
    </div>
    <!-- <PopUp></PopUp> -->
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import LobbyHeader from '@/components/C711_PC/Lobby_header'
import PopUp from './Pop_up'
export default {
  components: {
    LobbyHeader,
    PopUp
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
  methods: {
    resetoldPass() {
      this.oldPass = ''
    },
    resetnewPass() {
      this.newPass = ''
    },
    resetcheckNew() {
      this.checkNew = ''
    },
    cancel() {
      window.history.go(-1)
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