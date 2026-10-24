<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="AllContent">
      <a @click="select(country)" v-for="(country, i) in getCountries" :key="i">
        <div class="LocationBtn info_text_box">
          <div class="float-left">{{ country.name }}</div>
          <div class="float-right">+{{ country.country_code }}</div>
        </div>
      </a>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
import LoginHeader from '@/components/v_world/Login_header'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['getCountries'])
  },
  methods: {
    select(e) {
      window.sessionStorage.setItem('verify_country', e.name)
      window.sessionStorage.setItem('phone_code', e.country_code)
      window.sessionStorage.setItem('verify_phoneRule', e.phone_rule)
      //   this.$router.push('/PhoneVerification')
      window.history.go(-1)
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
  created() {
    this.$store.dispatch('actionCountries')
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
