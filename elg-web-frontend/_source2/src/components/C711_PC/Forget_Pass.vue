<template>
  <div class="single_block">
    <div class="case_wrap forget" style="margin-top:300px;">
      <div>
        <div class="title">{{passTitle}}</div>
        <div class="form">
          <div class="ele-border area_select">
            <button
              class="ele_border_in gradual-black btn btn-secondary dropdown-toggle black_InputAll black_inputbg blackInput"
              type="button"
              id="dropdownMenuButton"
              data-toggle="dropdown"
              aria-haspopup="true"
              aria-expanded="false"
            >
              <span class="inputFontLeft">{{ $t("area") }}</span>
              <span class="inputFontRight">{{ Country!=null?Country:Country}}</span>
            </button>
            <div class="dropdown-menu yellow_borderDropdown" aria-labelledby="dropdownMenuButton">
              <div class="gray_dropdownBg">
                <a
                  class="dropdown-item"
                  @click="select(country)"
                  v-for="(country, i) in getCountries"
                  :key="i"
                >{{ country.name }}</a>
              </div>
            </div>
          </div>
          <div class="validateDiv">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">+{{ phoneCode!==null?phoneCode:phoneCode}}</span>
                <input
                  v-model="phone"
                  name="phone"
                  @keyup="send_disable()"
                  v-validate="{ required:true,regex:phoneRule }"
                  :data-vv-as="$t('cellphoneNumber')"
                  class="inputFontRight dark-brown"
                  type="tel"
                  :placeholder="$t('cellphoneNumber')"
                />
                <img
                  src="../../../static/img/C711_PC/grayClose.png"
                  class="inputClear"
                  @click="resetphone()"
                />
              </div>
            </div>
            <label
              v-show="errors.has('phone')"
              for="phone"
              class="validate_content"
            >{{ errors.first('phone') }}</label>
          </div>

          <p>* {{$t('malayThaiUsers')}}</p>
          <div class="item_block">
            <!-- 輸入驗證框 -->
            <div class="validateDiv">
              <div class="ele-border">
                <input
                  @keyup="finish_disable()"
                  v-model="code"
                  name="code"
                  v-validate="{ required:true }"
                  :data-vv-as="$t('verifycode')"
                  class="ele_border_none dark-brown"
                  type="text"
                  :placeholder="$t('pleaseEnterVerificationCode')"
                />
              </div>
              <label
                v-show="errors.has('code')"
                for="code"
                class="validate_content"
              >{{ errors.first('code') }}</label>
            </div>
            <!-- 發送簡訊紐 -->
            <div class="ele-border" v-if="!isGetCode">
              <button
                :disabled="!create_visiable"
                @click="checkPhone()"
                class="ele_border_in gradual-gold"
              >{{$t('sendANewsLetter')}}</button>
            </div>
            <!-- 重新獲取驗證 -->
            <div v-if="isGetCode" class="ele-border">
              <button
                class="ele_border_in gradual-gold"
                style="padding:12px 0"
                :disabled="!reSendCode"
                @click="sendCode(1)"
              >{{$t('regainVerificationCode')}} 00：{{getTime}}</button>
            </div>
          </div>
        </div>
        <div class="footer">
          <div class="btn-blue" v-if="$route.meta.setNewpass">
            <button :disabled="!isFinish" @click="finish('newPass')">{{$t('finish')}}</button>
          </div>
          <div class="btn-blue" v-if="$route.meta.verifyFinish">
            <button :disabled="!isFinish" @click="finish('verify')">{{$t('finish')}}</button>
          </div>
        </div>
      </div>
    </div>
    <!-- 發送彈窗 -->
    <div v-if="isCheckPhone" class="popUp_block" style="max-width:100%;">
      <div class="wrap">
        <div class="title">{{ $t("confirmphonenumber") }}</div>
        <div class="content">
          <div>
            {{ $t("sendverifycodetophonenumber") }}:
            <br />
            +{{phoneCode}} {{phone}}
          </div>
        </div>
        <div class="footer">
          <div class="btn-blue" @click="sendCode(1)">
            <button>{{ $t("confirm") }}</button>
          </div>
          <div class="btn-grey" @click="closeCheck()">
            <button>{{ $t("cancel") }}</button>
          </div>
        </div>
      </div>
    </div>
    <!-- <PopUp></PopUp> -->
  </div>
</template>
<script>
import PopUp from './Pop_up'
import { mapGetters, mapActions } from 'vuex'
import { setTimeout } from 'timers'
import { truncate } from 'fs'
export default {
  components: {
    PopUp
  },
  computed: {
    ...mapGetters([
      'isGetCode',
      'getCountries',
      'getTime',
      'reSendCode',
      'getCountries',
      'isFinish'
    ])
  },
  data() {
    return {
      create_visiable: false,
      isCheckPhone: false,
      verify_type: '',
      id: window.sessionStorage.getItem('id'),
      Country:
        window.sessionStorage.getItem('verify_country') || this.$t('malaysia'),
      phoneCode: window.sessionStorage.getItem('phone_code') || '60',
      phoneRule:
        window.sessionStorage.getItem('verify_phoneRule') || '^[1-9]\\d{8,9}$',
      phone: '',
      code: '',
      sendCodeBtn: this.$t('sendANewsLetter'),
      regainVerificationCode: '',
      passTitle: ''
    }
  },
  methods: {
    close() {
      this.$router.push('/Login')
    },
    resetphone() {
      this.phone = ''
    },
    resetcode() {
      this.code = ''
    },
    verifyType() {
      let params = window.location.pathname
      if (params == '/PhoneVerification') {
        this.verify_type = 1
        this.passTitle = this.$t('retrievePassword')
      } else if (params == '/ForgetPassword') {
        this.verify_type = 2
        this.passTitle = this.$t('forget')
      } else if (params == '/RetrievePassword') {
        this.verify_type = 3
        this.passTitle = this.$t('phoneVerification')
      }
    },
    select(e) {
      window.sessionStorage.setItem('verify_country', e.name)
      window.sessionStorage.setItem('phone_code', e.country_code)
      window.sessionStorage.setItem('verify_phoneRule', e.phone_rule)
      this.phoneCode = e.country_code
      this.Country = e.name
    },
    // 簡訊紐解鎖
    send_disable() {
      if (this.phone !== '') {
        this.$validator.validate('phone').then(valid => {
          if (valid) {
            this.create_visiable = true
          } else {
            this.create_visiable = false
          }
        })
      } else {
        this.create_visiable = false
      }
    },
    // 提示手機號碼確認
    checkPhone() {
      this.isCheckPhone = true
    },
    closeCheck() {
      this.isCheckPhone = false
    },
    // 發送簡訊
    sendCode(verify_by) {
      let data = {
        type: this.verify_type,
        member_id: sessionStorage.getItem('id')
          ? sessionStorage.getItem('id')
          : '',
        verify_by: verify_by,
        mobile: this.phoneCode + this.phone
      }

      this.$store.dispatch('actionSendCode', data)
      this.isCheckPhone = false

      this.regainVerificationCode = this.$t('regainVerificationCode') + '00：'
      setTimeout(() => {
        this.$store.dispatch('actionSendCode', data)
      }, 45000)
    },
    finish(type) {
      let data = {
        type: type,
        verifyCode: window.sessionStorage.getItem('verify_Code')
      }
      this.$store.dispatch('actionCheckCode', data)
    },
    // Login_header 完成按鈕解鎖
    finish_disable() {
      window.sessionStorage.setItem('verify_Code', this.code)
      if (this.code.length == 6) {
        let isFinish = true
        this.$store.commit('completeDisable', isFinish)
      } else if (this.code.length !== 6) {
        let isFinish = false
        this.$store.commit('completeDisable', isFinish)
      }
    }
  },
  created() {
    this.verifyType()
    this.$store.dispatch('actionCountries')
  }
}
</script>