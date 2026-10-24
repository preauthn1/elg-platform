<template>
  <div class="single_block" style="min-height:500px;">
    <div class="case_wrapIframe">
      <div>
        <div class="title">
          {{$t('setAccPw')}}
          <img
            @click="close()"
            class="close_icon"
            src="../../../static/img/C711_PC/Group5301.png"
          />
        </div>
        <div class="form">
          <div class="validateDiv">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">{{$t('account')}}</span>
                <input
                  @keyup="blur()"
                  v-model="newAccount"
                  name="userId"
                  v-validate="'required|min:6|max:12|alpha_num'"
                  :data-vv-as="$t('account')"
                  class="inputFontRight dark-brown"
                  type="text"
                  :placeholder="$t('pleaseEnterTheEnglishCombinationOf6~10YardsForThePassword')"
                />
                <img
                  src="../../../static/img/C711_PC/grayClose.png"
                  class="inputClear"
                  @click="resetnewAccount()"
                />
              </div>
            </div>
            <label
              v-show="errors.has('userId')"
              for="userId"
              class="validate_content"
            >{{ errors.first('userId') }}</label>
          </div>
          <div class="validateDiv">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">{{$t('setpassword')}}</span>
                <input
                  @keyup="blur()"
                  v-model="newPassword"
                  name="password"
                  ref="password"
                  v-validate="'required|min:6|max:12|alpha_num'"
                  :data-vv-as="$t('newPassword')"
                  class="inputFontRight dark-brown"
                  type="password"
                  :placeholder="$t('pleaseEnterTheEnglishCombinationOf6~10YardsForThePassword')"
                />
                <img
                  src="../../../static/img/C711_PC/grayClose.png"
                  class="inputClear"
                  @click="resetnewPassword()"
                />
              </div>
            </div>
            <label
              v-show="errors.has('password')"
              for="password"
              class="validate_content"
            >{{ errors.first('password') }}</label>
          </div>
          <div class="validateDiv">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">{{$t('confirmpassword')}}</span>
                <input
                  @keyup="blur()"
                  v-model="rePassword"
                  name="comfirmPass"
                  v-validate="'required|confirmed:password'"
                  :data-vv-as="$t('confirmpassword')"
                  class="inputFontRight dark-brown"
                  type="password"
                  :placeholder="$t('pleaseEnterTheEnglishCombinationOf6~10YardsForThePassword')"
                />
                <img
                  src="../../../static/img/C711_PC/grayClose.png"
                  class="inputClear"
                  @click="resetrePassword()"
                />
              </div>
            </div>
            <label
              v-show="errors.has('comfirmPass')"
              class="validate_content"
            >{{ $t("inconsistentPassword") }}</label>
          </div>
          <div class="validateDiv">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">{{$t('sharingCode')}}</span>
                <input
                  v-if="$route.params.shareCode==undefined"
                  @keyup="blur()"
                  v-model="code"
                  name="code"
                  v-validate="'required'"
                  :data-vv-as="$t('sharingCode')"
                  class="inputFontRight dark-brown"
                  type="password"
                  :placeholder="$t('pleaseEnterTheSharingCode')"
                />
                <img
                  src="../../../static/img/C711_PC/grayClose.png"
                  class="inputClear"
                  @click="resetcode()"
                />
              </div>
            </div>
            <label
              v-show="errors.has('code')"
              for="code"
              class="validate_content"
            >{{ errors.first('code') }}</label>
          </div>
        </div>
        <div class="footer">
          <div class="btn-blue">
            <button :disabled="!create_visible" @click="create()">{{$t('signUp')}}</button>
          </div>
        </div>
      </div>
    </div>
    <PopUp></PopUp>
  </div>
</template>
<script>
import PopUp from './Pop_up'
export default {
  components: {
    PopUp
  },
  data() {
    return {
      newAccount: '',
      newPassword: '',
      rePassword: '',
      code: this.$route.params.shareCode,
      create_visible: false
    }
  },
  methods: {
    close() {
      this.$router.push('/Login')
    },
    resetnewAccount() {
      this.newAccount = ''
    },
    resetnewPassword() {
      this.newPassword = ''
    },
    resetrePassword() {
      this.rePassword = ''
    },
    resetcode() {
      this.code = ''
    },
    create() {
      var device_id = sessionStorage.getItem('device')
      let formMsg = {
        username: this.newAccount,
        password: [REDACTED],
        password_confirmation: this.rePassword,
        code: this.code,
        device_id: device_id
      }
      this.$store.dispatch('actionCreate', formMsg)
    },
    blur() {
      if (
        this.newAccount !== '' &&
        this.newPassword !== '' &&
        this.rePassword !== '' &&
        this.code !== ''
      ) {
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
    }
  },
  mounted() {
    $('.footer_block').css('display', 'none')
  }
}
</script>