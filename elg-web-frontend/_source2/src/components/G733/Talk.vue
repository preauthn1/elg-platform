<template>
  <div id="G733">
    <div class="chatIframe">
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
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import maskModal from './Modal'
import Global from '@/Global'
import { isNull } from 'util'
const CryptoJS = require('crypto-js')
import secret from '@/utils/secret'

//判斷IOS ANDROID
$(function() {
  var u = navigator.userAgent,
    app = navigator.appVersion
  var isAndroid = u.indexOf('Android') > -1 || u.indexOf('Linux') > -1 //g
  var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/) //ios终端
  if (isAndroid) {
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
    LoginHeader,
    maskModal
  },
  data() {
    return {
      iframeSrc: Global.$API_CHATPATH,
      inputSrc: ''
    }
  },
  mounted() {
    var agUser = sessionStorage.getItem('CS_API_USER')
    var agKey = sessionStorage.getItem('CS_API_KEY')
    var templateMain = sessionStorage.getItem('Template')

    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }

    const vm = this
    vm.fullHeight = window.innerHeight
    vm.fullWidth = window.innerWidth
    window.onresize = () => {
      vm.fullHeight = window.innerHeight
      vm.fullWidth = window.innerWidth
    }
    $('.chatIframe').css('height', vm.fullHeight - 53 + 'px')
    $('.chatIframe').css('width', vm.fullWidth)

    var stest = {
      agentuser: sessionStorage.getItem('CS_API_USER'),
      agentkey: sessionStorage.getItem('CS_API_KEY')
    }

    var signData = JSON.stringify({
      thirdpartyId: window.sessionStorage.getItem('username'),
      agentuser: sessionStorage.getItem('CS_API_USER'),
      agentkey: sessionStorage.getItem('CS_API_KEY'),
      lang: localStorage.getItem('LOCALE'),
      templateMain: sessionStorage.getItem('Template'),
      EventMode: sessionStorage.getItem('eventMode')
    })

    let signDataSecret = [REDACTED]

    this.iframeSrc = this.iframeSrc + signDataSecret.replace(/\//g, '5s1g7er0A')
  },
  methods: {}
}
</script>

