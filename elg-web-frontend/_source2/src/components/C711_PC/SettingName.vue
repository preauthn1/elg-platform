<template>
  <div class="single_block">
    <LobbyHeader></LobbyHeader>
    <div>
      <div class="changePass_wrap">
        <h3>{{$t('setNickname')}}</h3>
        <div class="form">
          <div class="validateDiv fontsizelogin" style="margin:auto; width:70%;">
            <div class="ele-border input-X">
              <div class="dark-brown">
                <span class="inputFontLeft">{{$t('nickname')}}</span>
                <input
                  @keyup="blur()"
                  v-model="Nickname"
                  name="nickname"
                  v-validate="'required'"
                  :data-vv-as="$t('nickname')"
                  class="inputFontRight dark-brown"
                  type="text"
                  :placeholder="$t('pleaseEnterANickname')"
                />
                <img
                  src="../../../static/img/C711_PC/grayClose.png"
                  class="inputClear"
                  @click="resetNickname()"
                />
              </div>
            </div>
            <label
              v-show="errors.has('nickname')"
              for="nickname"
              class="validate_content"
            >{{ errors.first('nickname') }}</label>
          </div>
        </div>
        <div class="footer">
          <div class="btn-blue" style="margin:auto 10px;">
            <button
              :disabled="!create_visiable"
              @click="setNickname()"
              style="padding:9px auto;"
            >{{$t('finish')}}</button>
          </div>
          <div class="btn-orange" style="margin:auto 10px;">
            <button @click="cancel()" style="padding:9px auto;">{{$t('cancel1')}}</button>
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
  data() {
    return {
      Nickname: '',
      create_visiable: false
    }
  },
  methods: {
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
    },
    cancel() {
      window.history.go(-1)
    }
  },
  created() {
    this.getNickname()
  }
}
</script>