<template>
  <div class="chatIframe">
    <!-- {{Nickname}} -->
    <!-- <form action method="POST" target="chat" id="chatSend">
      <input name="sign" type="hidden" v-model="inputSrc" />
    </form>-->
    <iframe
      name="chat"
      width="100%"
      height="100%"
      frameborder="0"
      scrolling="auto"
      id="chat"
      :src="iframeSrc"
      class="iframeChat"
      ref="chat"
    ></iframe>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
import LoginHeader from '@/components/v_world/Login_header'
import Global from '@/Global'
import { isNull } from 'util'
const CryptoJS = require('crypto-js')
import secret from '@/utils/secret'

// function changeFrameHeight() {
//   var iframe = document.getElementById('Gameiframe')
//   iframe.height = iframe.contentDocument.body.clientHeight - 60
// }
// window.onresize = function() {
//   changeFrameHeight()
// }
//判斷IOS ANDROID
$(function() {
  var u = navigator.userAgent,
    app = navigator.appVersion
  var isAndroid = u.indexOf('Android') > -1 || u.indexOf('Linux') > -1 //g
  var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/) //ios终端
  if (isAndroid) {
    // console.log('安卓')
    //ANDROID鍵盤喚起時,FOOTER隱藏
    var oHeight = $(document).height() //屏幕当前的高度
    $(window).resize(function() {
      if ($(document).height() < oHeight) {
        $('footer').css('display', 'none')
      } else {
        $('footer').css('display', 'block')
      }
    })
  }
  if (isIOS) {
    // console.log('蘋果')
  }
})

//ANDROID鍵盤喚起時,FOOTER隱藏
var oHeight = $(document).height() //屏幕当前的高度
$(window).resize(function() {
  if ($(document).height() < oHeight) {
    $('footer').css('display', 'none')
  } else {
    $('footer').css('display', 'block')
  }
})

export default {
  components: {
    maskModal
  },
  data() {
    return {
      iframeSrc: Global.$API_CHATPATH,
      inputSrc: ''
    }
  },
  computed: {
    //   ...mapGetters(['getGameUrl'])
  },
  //   components: {
  //     LoginHeader
  //   },
  created() {},

  mounted() {
    var agUser = sessionStorage.getItem('CS_API_USER')
    var agKey = sessionStorage.getItem('CS_API_KEY')
    var templateMain = sessionStorage.getItem('Template')

    // console.log(templateMain, 'templateMain')
    // if (agUser && agKey === '') {
    //   alert('沒有使用聊天室權限')
    //   this.$router.push('/')
    // }
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      //   console.log('直向')
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
        // console.log('橫向')
      } else {
        // console.log('直向')
        $('.directionMask').css('display', 'none')
      }
    }
    //GA
    // this.$ga.event({
    //   eventCategory: 'Talk',
    //   eventAction: 'Enter',
    //   eventLabel: 'Talk'
    //   //   eventValue: 123
    // })
    // console.log(this.$refs.chat)
    // console.log(this.$refs.chat.contentWindow)
    const vm = this
    vm.fullHeight = window.innerHeight
    vm.fullWidth = window.innerWidth
    window.onresize = () => {
      vm.fullHeight = window.innerHeight
      vm.fullWidth = window.innerWidth
    }
    $('.chatIframe').css('height', vm.fullHeight - 60 + 'px')
    $('.chatIframe').css('width', vm.fullWidth)
    // console.log('hi' + vm.fullHeight)
    var stest = {
      agentuser: sessionStorage.getItem('CS_API_USER'),
      agentkey: sessionStorage.getItem('CS_API_KEY')
    }

    var signData = JSON.stringify({
      thirdpartyId: window.sessionStorage.getItem('username'),
      agentuser: sessionStorage.getItem('CS_API_USER'),
      agentkey: sessionStorage.getItem('CS_API_KEY'),
      lang: localStorage.getItem('LOCALE'),
      templateMain: sessionStorage.getItem('Template')
    })

    let signDataSecret = [REDACTED]
    // console.log(signDataSecret, '加密後')
    // console.log(secret.DecryptChat(signDataSecret), '解密')

    // console.log(signDataSecret.replace(/\//g, '5s1g7er0A'), '替代')
    this.iframeSrc = this.iframeSrc + signDataSecret.replace(/\//g, '5s1g7er0A')
    // console.log(this.iframeSrc, '帶過去iframe')
    // this.inputSrc = signDataSecret
    // $('#chatSend').attr('action', this.iframeSrc)
    // $('#chatSend').submit()
  },
  methods: {}
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
