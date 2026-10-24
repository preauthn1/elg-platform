<template>
  <div id="C711Mobile">
    <div class="pageBg CwidthHeight widthHeightvh fontFamily">
      <LoginHeader></LoginHeader>
      <div class="pageCon">
        <div class="forgetTitleFont">{{ $t("pleaseConfirmYourCountry") }}</div>
        <div class="dropdown yellow_borderInput">
          <button
            class="btn btn-secondary dropdown-toggle black_InputAll black_inputbg blackInput"
            type="button"
            id="dropdownMenuButton"
            data-toggle="dropdown"
            aria-haspopup="true"
            aria-expanded="false"
          >
            <span class="inputFontLeft">{{ $t("area") }}</span>
            <span>{{ Country!=null?Country:Country}}</span>
          </button>
          <div class="dropdown-menu yellow_borderDropdown" aria-labelledby="dropdownMenuButton">
            <div class="gray_dropdownBg">
              <a
                class="dropdown-item"
                href="#"
                @click="select(country)"
                v-for="(country, i) in getCountries"
                :key="i"
              >{{ country.name }}</a>
            </div>
          </div>
        </div>
        <div class="inputvalidate">
          <div class="form-group ListborderInput">
            <span class="forgetphoneCode">+{{ phoneCode!=null?phoneCode:phoneCode}}</span>

            <input
              class="ListInputAllR Listinputbg"
              @keyup="send_disable()"
              v-model="phone"
              name="phone"
              v-validate="{ required:true,regex:phoneRule }"
              :data-vv-as="$t('cellphoneNumber')"
              type="tel"
              :placeholder="$t('cellphoneNumber')"
            />

            <img
              src="../../assets/img/C711_Mobile/grayClose.png"
              @click="resetphone()"
              class="inputClose"
            />
          </div>
          <label
            v-show="errors.has('phone')"
            for="phone"
            class="validateDiv"
          >{{ errors.first('phone') }}</label>
        </div>
        <div class="forgetFont">*{{ $t("malayThaiUsers") }}</div>
        <div class="form-group yellow_borderInput" v-if="!isGetCode">
          <input
            v-model="sendCodeBtn"
            type="button"
            class="form-control black_InputAll yellow_Btnbg fontsizeBtn"
            :disabled="!create_visiable"
            @click="checkPhone();playSound()"
          />
        </div>

        <div
          v-if="isGetCode"
          class="form-group yellow_borderInput"
          :disabled="!reSendCode"
          @click="sendCode(1)"
        >
          <div
            class="form-control black_InputAll gray_BtnBg fontsizeBtn"
          >{{$t('regainVerificationCode')}} 00：{{getTime}}</div>
        </div>
        <div class="inputvalidate">
          <div class="form-group ListborderInput">
            <span class="forgetphoneCode">{{ $t("verifycode") }}</span>
            <input
              class="ListInputAllR Listinputbg"
              @keyup="finish_disable()"
              v-model="code"
              name="code"
              type="number"
              v-validate="{ required:true }"
              :data-vv-as="$t('verifycode')"
              :placeholder="$t('pleaseEnterVerificationCode')"
            />

            <img
              src="../../assets/img/C711_Mobile/grayClose.png"
              @click="resetcode()"
              class="inputClose"
            />
          </div>
          <label
            v-show="errors.has('code')"
            for="code"
            class="validateDiv"
          >{{ errors.first('code') }}</label>
        </div>
        <!-- 發送彈窗 -->
        <div class="mask" v-if="isCheckPhone">
          <div class="Popups_Modal">
            <div class="PopupsTitle">{{ $t("confirmphonenumber") }}</div>
            <div class="PopupsConAll">
              <div class="PopupsCon popupsConFont">
                {{ $t("sendverifycodetophonenumber") }}:
                <br />
                +{{phoneCode}} {{phone}}
              </div>
              <div class="PopupsBtn clearfix">
                <div class="float-left">
                  <div class="btn-blueTwo">
                    <button
                      type="submit"
                      @click="sendCode(1)"
                      style="padding:9px auto;"
                    >{{ $t("confirm") }}</button>
                  </div>
                </div>
                <div class="float-right">
                  <div class="btn-orangeTwo">
                    <button
                      type="submit"
                      @click="closeCheck()"
                      style="padding:9px auto;"
                    >{{ $t("cancel") }}</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="btn-blue" v-if="$route.meta.setNewpass">
          <button
            type="submit"
            id="pass"
            @click="finish('newPass')"
            :disabled="!isFinish"
            style="padding:9px auto;"
          >{{ $t("finish") }}</button>
        </div>
        <div class="btn-blue" v-if="$route.meta.verifyFinish">
          <button
            type="submit"
            @click="finish('verify')"
            :disabled="!isFinish"
            style="padding:9px auto;"
          >{{ $t("finish") }}</button>
        </div>
      </div>
      <!-- 彈窗 -->
      <maskModal></maskModal>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import LoginHeader from '@/components/C711_Mobile/Login_header'
import { truncate } from 'fs'
import { setTimeout } from 'timers'
import maskModal from './Modal'
export default {
  components: {
    LoginHeader,
    maskModal
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
      create_visiable: false, //輸入電話後為true，啟用發送驗證碼簡訊btn
      isCheckPhone: false, //true顯示確認發送簡訊彈窗
      verify_type: '', // 驗證類型1:資料驗證2:忘記密碼3:找回密碼
      id: window.sessionStorage.getItem('id'),
      Country:
        window.sessionStorage.getItem('verify_country') || this.$t('malaysia'), //國家名
      phoneCode: window.sessionStorage.getItem('phone_code') || '60', //國碼
      phoneRule:
        window.sessionStorage.getItem('verify_phoneRule') || '^[1-9]\\d{8,9}$', //國家電話格式
      phone: '',
      code: '',
      sendCodeBtn: this.$t('sendANewsLetter'),
      regainVerificationCode: '',
      enPic: require('../../../static/img/wechat.png'),
      zh_twPic: require('../../../static/img/wechatC.png'),
      lang: localStorage.getItem('LOCALE')
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
    //   音效
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    // 重設電話
    resetphone() {
      this.phone = ''
    },
    resetcode() {
      this.code = ''
    },
    verifyType() {
      // 驗證類型判斷
      let params = window.location.pathname
      if (params == '/PhoneVerification') {
        this.verify_type = 1
      } else if (params == '/ForgetPassword') {
        this.verify_type = 2
      } else if (params == '/RetrievePassword') {
        this.verify_type = 3
      }
    },
    select(e) {
      window.sessionStorage.setItem('verify_country', e.name)
      window.sessionStorage.setItem('phone_code', e.country_code)
      window.sessionStorage.setItem('verify_phoneRule', e.phone_rule)

      this.phoneCode = e.country_code
      this.Country = e.name
      this.phoneRule = e.phone_rule
    },
    // 簡訊紐解鎖
    send_disable() {
      if (this.phone !== '') {
        //   this.$validator.validateAll() 來檢查每個欄位，只有在全部通過的時候才會將資料送出
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
      //   if (this.verify_type == 2 || this.verify_type == 3) {
      //     data.member_id = ''
      //   }

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