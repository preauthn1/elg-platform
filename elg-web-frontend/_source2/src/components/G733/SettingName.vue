<template>
  <div id="G733">
    <div class="mobileHeader">
      <LoginHeader></LoginHeader>
    </div>
    <!-- LoginMain -->
    <div class="single_block">
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainAll">
          <div class="pcmainBg">
            <div class="mainLogin"></div>
            <form @submit.prevent="setNickname()">
              <div class="loginInfo">
                <div class="inputvalidate">
                  <div class="info">
                    <input
                      @keyup="blur()"
                      v-model="Nickname"
                      name="nickname"
                      :data-vv-as="$t('pleaseEnterANickname')"
                      v-validate="'required'"
                      id="InputUser"
                      :placeholder="$t('pleaseEnterANickname')"
                      maxlength="10"
                      class="yellowInput input_all"
                    />
                    <img
                      src="../../assets/img/G733/xx.png"
                      alt
                      class="clearBtn"
                      @click="resetNickname()"
                    />
                  </div>
                  <label
                    v-show="errors.has('nickname')"
                    class="validateFont"
                  >{{ errors.first('nickname') }}</label>
                </div>
                <div class="nickBtn clearfix">
                  <div class="silverBorder btnBorder_all fixedBtn float-left">
                    <button
                      type="submit"
                      @click="playSound()"
                      class="redBtn btn_all"
                    >{{ $t("store") }}</button>
                  </div>
                  <div class="silverBorder btnBorder_all fixedBtn float-right">
                    <button @click="cancel()" class="grayBtn btn_all">{{$t('cancel1')}}</button>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>
        <!-- 彈窗 -->
        <maskModal></maskModal>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import { truncate } from 'fs' // submit鎖定
import maskModal from './Modal'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  data() {
    return {
      Nickname: '',
      create_visiable: false
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
    resetNickname() {
      this.Nickname = ''
    },
    blur() {
      if (this.Nickname !== '') {
        this.$validator.validateAll().then(valid => {
          if (valid) {
            this.create_visiable = true
          }
        })
      } else {
        this.create_visiable = false
      }
    },
    setNickname() {
      let nickname = this.Nickname

      this.$store.dispatch('actionNickname', nickname)
    },
    getNickname() {
      let nickname = window.sessionStorage.getItem('nickname')
      if (nickname) {
        this.Nickname = nickname
      } else {
        this.Nickname = ''
      }
    }
  },
  created() {
    this.getNickname()
  }
}
</script>