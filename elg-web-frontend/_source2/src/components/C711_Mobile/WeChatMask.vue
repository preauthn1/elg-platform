<!-- 微信引導 -->
<template>
<div class="wechatMask" @click="wechatClose">
<div class="wechatPic">
    <img :src="lang == 'zh-tw' ? zh_twPic : enPic" />
</div>
</div>
</template>
<script>
export default {
  name: 'App',
  data() {
    return {
      enPic: require('../../../static/img/wechat.png'),
      zh_twPic: require('../../../static/img/wechatC.png'),
      lang: localStorage.getItem('LOCALE'),
    }
  },
  mounted() {
      //偵測WECHAT
    var ua = navigator.userAgent.toLowerCase()
    if (ua.match(/MicroMessenger/i) == 'micromessenger') {
      $('.wechatMask').css('display', 'block')
    } else {
      $('.wechatMask').css('display', 'none')
    }
  },
  methods: {
    // 微信引導關閉
    wechatClose() {
      $('.wechatMask').css('display', 'none')
    }
  },
  created() {}
};
</script>