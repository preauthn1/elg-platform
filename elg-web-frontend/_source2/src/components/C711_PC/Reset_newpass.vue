<template>
  <div class="single_block">
    <div class="case_wrap" style="margin-top:300px;">
      <div>
        <div class="title">
          {{$t('setnewpassword')}}
          <img
            @click="close()"
            class="close_icon"
            src="/static/img/C711_PC/Group5301.png"
          />
        </div>
        <div class="form">
          <div class="validateDiv">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">{{$t('account')}}</span>

                <input
                  v-model="account"
                  class="inputFontRight dark-brown"
                  type="text"
                  disabled="disabled"
                />
              </div>
            </div>
          </div>
          <div class="validateDiv">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">{{$t('newPassword')}}</span>
                <input
                  @keyup="checkStore()"
                  v-model="newPassword"
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
                  @click="resetnewPassword()"
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
                  @keyup="checkStore()"
                  v-model="checkPassword"
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
                  @click="resetcheckPassword()"
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
          <div class="btn-blue">
            <button :disabled="!canStore" @click="resetPassword()">{{$t('finish')}}</button>
          </div>
        </div>
      </div>
    </div>
    <!-- 改密碼成功彈窗 -->
    <div v-if="isResetNewpass" class="popUp_block" style="max-width:100%;">
      <div class="wrap">
        <div class="title">{{ $t("prompt") }}</div>
        <div class="content">
          <div>{{ $t("passwordupdated") }}</div>
        </div>
        <div class="footer">
          <a href="/">
            <div class="btn-blue">
              <button>{{ $t("confirm") }}</button>
            </div>
          </a>
        </div>
      </div>
    </div>
    <!-- <PopUp></PopUp> -->
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import PopUp from './Pop_up'
export default {
  components: {
    PopUp
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
  methods: {
    close() {
      this.$router.push('/Login')
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