<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="AllContent">
      <button
        type="button"
        class="btn btn-primary LanguageBtn"
        :class="key===language ? 'active' : ''"
        v-for="(value,key) in getLanguages"
        :key="key"
        :data-lang-key="value.code"
        @click="GetLanguage(value.name)"
      >{{ value.name }}</button>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import LoginHeader from '@/components/v_world/Login_header'
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
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
    // console.log(1)
    // console.log(this.getLanguages, '語系')
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
    //語系變更
    // GetLanguage(event) {
    //   var lang = event.currentTarget.getAttribute('data-lang-key')
    //   localStorage.setItem('LOCALE', lang)
    //   this.$i18n.locale = lang
    // }
    GetLanguage() {
      var lang = event.currentTarget.getAttribute('data-lang-key')
      //   console.log(lang, '語系123')
      localStorage.setItem('LOCALE', lang)
      this.$i18n.locale = lang
    }
  },

  created() {
    this.$store.dispatch('actionLanguages')
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>



