<template>
  <div id="C711Mobile">
    <div class="pageBg CwidthHeight widthHeightvh fontFamily">
      <LoginHeader></LoginHeader>
      <div class="pageCon">
        <div class="epidemicPic" style>
          <div class="epid_pic" @click="iframe_ad(2)">
            <span>Covid-19 Malaysia news</span>
            <img src="../../assets/img/2.jpg" alt />
          </div>
          <div class="epid_pic" @click="iframe_ad(1)">
            <span>Covid-19 International news</span>
            <img src="../../assets/img/1.jpg" alt />
          </div>
          <div class="epid_pic" @click="iframe_ad(3)">
            <span>國際疫情</span>
            <img src="../../assets/img/3.jpg" alt />
          </div>
        </div>
      </div>
      <!-- 彈窗 -->
    </div>
    <!--疫情彈窗iframe-->
    <div class="popUp_block" v-if="ad_iframe">
      <div class="wrap" style="width:98%; top:4%; transform: translateX(-50%) ;">
        <div class="single_block">
          <div class="case_wrapIframe" style>
            <div>
              <div class="title">
                <img
                  @click="ad_iframe=false"
                  class="close_icon"
                  src="../../../static/img/C711_PC/Group5301.png"
                />
              </div>
              <div>
                <div class="ad_full" style="margin-bottom:10px; height:85vh;">
                  <iframe name="target" width="98%" height="100%" frameborder="0" id="adiframe"></iframe>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/C711_Mobile/Login_header'
export default {
  components: {
    LoginHeader
  },
  data() {
    return {
      ad_iframe: false
    }
  },
  methods: {
    // 疫情蓋台
    iframe_ad(type) {
      if (type == 1) {
        this.ad_iframe = true
        setTimeout(() => {
          $('#adiframe').attr(
            'src',
            'https://example.invalid/coronavirus/'
          )
        }, 1500)
      } else if (type == 2) {
        this.ad_iframe = true
        setTimeout(() => {
          $('#adiframe').attr('src', 'https://www.outbreak.my/')
        }, 1500)
      } else if (type == 3) {
        this.ad_iframe = true
        setTimeout(() => {
          $('#adiframe').attr(
            'src',
            'https://example.invalid/zt2020/page/feiyan.htm#/global'
          )
        }, 1500)
      }
    }
  }
}
</script>