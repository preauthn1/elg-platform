<template>
  <div id="G733">
    <LoginHeader></LoginHeader>
    <!-- LoginMain -->
    <div class="LoginMain">
      <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
      <div class="mainAll">
        <div class="mainLogin"></div>
        <div class="loginInfo">
          <div
            class="goldBorder btnBorder_all"
            v-for="(value,key) in getLanguages"
            :key="key"
            :data-lang-key="value.code"
            @click="GetLanguage(value.code)"
            id="remove"
          >
            <div
              class="settingBtn removeClass"
              style="text-align:center;"
              :class="value.code==language ? 'yellowBtn' : 'blackBtn'"
              :id="'lanColor'+value.code"
            >{{ value.name }}</div>
          </div>
        </div>
      </div>
      <!-- 彈窗 -->
      <maskModal></maskModal>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import maskModal from './Modal'
import { mapGetters, mapActions } from 'vuex'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['getLanguages'])
  },
  data() {
    return {
      language: this.$i18n.locale
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
    GetLanguage(value) {
      var lang = event.currentTarget.getAttribute('data-lang-key')

      localStorage.setItem('LOCALE', lang)

      this.$i18n.locale = lang

      $('.removeClass').removeClass('yellowBtn')
      $('.removeClass').addClass('blackBtn')
      $('#lanColor' + value).addClass('yellowBtn')
    }
  },
  created() {
    this.$store.dispatch('actionLanguages')
  }
}
</script>