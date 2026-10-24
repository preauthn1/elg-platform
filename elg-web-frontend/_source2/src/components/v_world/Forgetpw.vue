<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="AllContent">
      <span class="TitleFont">{{ $t("pleaseConfirmYourCountry") }}</span>
      <div class="ForgetpwForm">
        <router-link to="/LocationSelect">
          <div class="locationBtn">
            <div class="float-left locationTitle">{{ $t("area") }}</div>
            <div class="float-left locationSel">{{ Country!==null?Country:Country}}</div>
            <div class="float-right locationPic">
              <img src="../../assets/img/v_world/triangle_right.png" />
            </div>
          </div>
        </router-link>
        <div class="blueInput info_text_box">
          <span class="forgetnum">+{{ phoneCode!==null?phoneCode:phoneCode}}</span>
          <input
            @keyup="send_disable()"
            v-model="phone"
            name="phone"
            v-validate="{ required:true,regex:phoneRule }"
            :data-vv-as="$t('cellphoneNumber')"
            type="tel"
            :placeholder="$t('cellphoneNumber')"
          />
          <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetphone()" />
          <label
            v-show="errors.has('phone')"
            for="phone"
            class="validate"
          >{{ errors.first('phone') }}</label>
        </div>
        <div class="fontsize">{{ $t("malayThaiUsers") }}</div>
        <button
          v-if="!isGetCode"
          :disabled="!create_visiable"
          @click="checkPhone();playSound()"
          class="imgfontAll submitBtn"
        >
          <span>{{ $t("sendANewsLetter") }}</span>
        </button>
        <div v-if="isGetCode" :disabled="!reSendCode" @click="sendCode(1)" class="TimeBtn">
          <p>{{ $t("regainVerificationCode") }}</p>
          <p>00:{{getTime}}</p>
        </div>

        <div class="blueInput info_text_box">
          <span>{{ $t("verifycode") }}</span>
          <input
            @keyup="finish_disable()"
            v-model="code"
            name="code"
            type="number"
            v-validate="{ required:true }"
            :data-vv-as="$t('verifycode')"
            :placeholder="$t('pleaseEnterVerificationCode')"
          />
          <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetcode()" />
          <label v-show="errors.has('code')" for="code" class="validate">{{ errors.first('code') }}</label>
        </div>
      </div>
    </div>
    <div v-if="isCheckPhone" class="mask">
      <div class="modalBlueAll">
        <div class="content">
          <div>
            <h5>{{ $t("confirmphonenumber") }}</h5>
          </div>
          <div>
            <p>
              {{ $t("sendverifycodetophonenumber") }}:
              <br />
              +{{phoneCode}} {{phone}}
            </p>
          </div>
          <div class="comfirmBtnAll clearfix">
            <div @click="sendCode(1)" class="float-left greenBtn modalBtn">{{ $t("confirm") }}</div>
            <div @click="closeCheck()" class="float-right redBtn modalBtn">{{ $t("cancel") }}</div>
            <!-- <button type="button" @click="sendCode(1)" class="btn btn-primary">{{ $t("confirm") }}</button>
            <button type="button" @click="closeCheck()" class="btn btn-secondary">{{ $t("cancel") }}</button>-->
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
import maskModal from './Modal'
import LoginHeader from '@/components/v_world/Login_header'
import { truncate } from 'fs'
import { setTimeout } from 'timers'
export default {
  components: {
    LoginHeader
  },
  computed: {
    ...mapGetters(['isGetCode', 'getCountries', 'getTime', 'reSendCode'])
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
      code: ''
    }
  },
  mounted() {
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
      } else if (params == '/ForgetPassword') {
        this.verify_type = 2
      } else if (params == '/RetrievePassword') {
        this.verify_type = 3
      }
    },

    // 簡訊紐解鎖
    send_disable() {
      if (this.phone != '') {
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
  }
}
</script>

<style scoped>
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
