<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="buleBtnPadding">
      <div class="buleBtnItem">
        <div class="textbox buleBtndiv">
          {{ $t("points") }}:
          {{ vPoints }}
        </div>
      </div>
      <div class="BtnAll">
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a
              href="/ReceivedRedEnvelope"
              onClick="ga('send', 'event', 'RedEnvelopeRecord', 'RedEnvelopeReceive_Click', 'RedEnvelopeRecord');"
            >
              <div class="float-left blueBtnFont">{{ $t("receivedRedEnvelope") }}</div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a
              href="/RedEnvelope"
              onClick="ga('send', 'event', 'RedEnvelopeRecord', 'RedEnvelopeSend_Click', 'RedEnvelopeRecord');"
            >
              <div class="float-left blueBtnFont">{{ $t("redEnvelope") }}</div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a
              href="/RedEnvelopeRefund"
              onClick="ga('send', 'event', 'RedEnvelopeRecord', 'RedEnvelopeCancel_Click', 'RedEnvelopeRecord');"
            >
              <div class="float-left blueBtnFont">{{ $t("redEnvelopeRefund") }}</div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import LoginHeader from '@/components/v_world/Login_header'
import maskModal from './Modal'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  data() {
    return {
      vPoints: window.sessionStorage.getItem('balance')
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
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
