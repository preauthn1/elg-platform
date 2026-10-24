<template>
  <div id="C711Mobile">
    <div class="pageBg CwidthHeight widthHeightvh fontFamily">
      <LoginHeader></LoginHeader>
      <div class="pageCon">
        <form @submit.prevent="setNickname()">
          <div class="inputvalidate">
            <div class="ListborderInput">
              <span class="forgetphoneCode setNewPw">{{ $t("nickname") }}</span>
              <input
                class="ListInputAllR Listinputbg"
                @keyup="blur()"
                v-model="Nickname"
                name="nickname"
                :data-vv-as="$t('pleaseEnterANickname')"
                v-validate="'required'"
                id="InputUser"
                :placeholder="$t('pleaseEnterANickname')"
                maxlength="10"
              />
              <img
                src="../../assets/img/C711_Mobile/grayClose.png"
                @click="resetNickname()"
                class="inputClose"
              />
            </div>
            <label
              v-show="errors.has('nickname')"
              class="validateDiv"
            >{{ errors.first('nickname') }}</label>
          </div>

          <div class="btn-blue">
            <button type="submit" @click="playSound()" style="padding:9px auto;">{{ $t("store") }}</button>
          </div>
        </form>
      </div>
      <!-- 彈窗 -->
      <maskModal></maskModal>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/C711_Mobile/Login_header'
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
    // 驗證
    blur() {
      if (this.Nickname != '') {
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