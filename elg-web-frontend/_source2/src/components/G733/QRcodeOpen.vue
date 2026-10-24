<template>
  <div id="G733">
    <LoginHeader></LoginHeader>
    <div class="LoginMain">
      <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
      <div class="mainAll">
        <div class="pageCon">
          <div class="qrfont">{{$t('screenJump')}}</div>
        </div>
        <!-- 彈窗 -->
        <maskModal></maskModal>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import maskModal from './Modal'
import { mapGetters, mapActions } from 'vuex'
import axios from 'axios'
import secret from '@/utils/secret'
import md5 from 'js-md5'
import Global from '@/Global'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  data() {
    return {
      vendorID: this.$route.params.id
    }
  },
  mounted() {
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'none')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }

    this.$store.dispatch('actionRenewToken', this.$route.params.token)
    sessionStorage.setItem('vendor_id', this.$route.params.id)
    let vendor = this.$route.params.vendor
    let vendorID = this.$route.params.id
    let vendordata = this.$route.params
    // 帶遊戲商名稱

    setTimeout(() => {
      this.$store.dispatch('actionMember', vendordata)
    }, 2000)
  },
  methods: {}
}
</script>
