<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="AllContent">
      <form @submit.prevent="setNickname()">
        <div class="blueInput info_text_box">
          <span>{{ $t("nickname") }}</span>
          <input
            @keyup="blur()"
            v-model="Nickname"
            name="nickname"
            :data-vv-as="$t('pleaseEnterANickname')"
            v-validate="'required'"
            id="InputUser"
            :placeholder="$t('pleaseEnterANickname')"
            maxlength="10"
          />
          <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetNickname()" />
          <label v-show="errors.has('nickname')" class="validate">{{ errors.first('nickname') }}</label>
        </div>
        <button
          class="imgfontAll"
          type="submit"
          :disabled="!create_visiable"
          @click="playSound()"
        >{{ $t("store") }}</button>
      </form>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import LoginHeader from '@/components/v_world/Login_header'
import maskModal from './Modal'
import { truncate } from 'fs' // submit鎖定
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
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
button {
  background-color: transparent;
  border: 0;
}
</style>
