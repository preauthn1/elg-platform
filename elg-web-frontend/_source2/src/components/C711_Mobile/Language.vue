<template>
  <div id="C711Mobile">
    <div class="pageBg CwidthHeight widthHeightvh fontFamily">
      <LoginHeader></LoginHeader>
      <div class="pageCon" id="pageCon">
        <div
          class="form-group yellow_borderInput margin20"
          v-for="(value,key) in getLanguages"
          :key="key"
          :data-lang-key="value.code"
          @click="GetLanguage(value.code)"
          id="remove"
        >
          <div
            class="form-control black_InputAll fontsizelogin removeClass"
            :class="value.code===language ? 'red_Btnbg' : 'black_inputbg'"
            :id="'lanColor'+value.code"
          >{{ value.name }}</div>
        </div>
      </div>
      <!-- 彈窗 -->
      <maskModal></maskModal>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/C711_Mobile/Login_header'
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
      // 語系變更按鈕顏色更改
      $('.removeClass').removeClass('red_Btnbg')
      $('.removeClass').addClass('black_inputbg')
      $('#lanColor' + value).addClass('red_Btnbg')
    }
  },
  created() {
    this.$store.dispatch('actionLanguages')
  }
}
</script>