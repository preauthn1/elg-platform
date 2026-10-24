<template>
  <div id="C711Mobile">
    <div class="CwidthHeight widthHeightvh fontFamily scrollBar">
      <div class="pointHeight">
        <PointsHeader></PointsHeader>
      </div>
      <div class="LobbyBg LobbySetting">
        <div class="pageCon">
          &nbsp;
          <!-- 領取紅包 -->
          <div class="form-group yellow_borderInput marginTop10">
            <a
              href="/ReceivedRedEnvelope"
              onClick="ga('send', 'event', 'RedEnvelopeRecord', 'RedEnvelopeReceive_Click', 'RedEnvelopeRecord');"
            >
              <div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
                <div class="pwFont float-left setPic">
                  <img
                    src="../../assets/img/C711_Mobile/ReceivedRedEnvelope.png"
                    class="recordLogoPic"
                  />
                  {{ $t("receivedRedEnvelope") }}
                </div>
                <div class="setPic float-right">
                  <img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
                </div>&nbsp;
              </div>
            </a>
          </div>
          <!-- 發送紅包 -->
          <div class="form-group yellow_borderInput marginTop10">
            <a
              href="/RedEnvelope"
              onClick="ga('send', 'event', 'RedEnvelopeRecord', 'RedEnvelopeSend_Click', 'RedEnvelopeRecord');"
            >
              <div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
                <div class="pwFont float-left setPic">
                  <img src="../../assets/img/C711_Mobile/RedEnvelope.png" class="recordLogoPic" />
                  {{ $t("redEnvelope") }}
                </div>
                <div class="setPic float-right">
                  <img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
                </div>&nbsp;
              </div>
            </a>
          </div>
          <!-- 紅包退還 -->
          <div class="form-group yellow_borderInput marginTop10">
            <a
              href="/RedEnvelopeRefund"
              onClick="ga('send', 'event', 'RedEnvelopeRecord', 'RedEnvelopeCancel_Click', 'RedEnvelopeRecord');"
            >
              <div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
                <div class="pwFont float-left setPic">
                  <img
                    src="../../assets/img/C711_Mobile/RedEnvelopeRefund.png"
                    class="recordLogoPic"
                  />
                  {{ $t("redEnvelopeRefund") }}
                </div>
                <div class="setPic float-right">
                  <img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
                </div>&nbsp;
              </div>
            </a>
          </div>
        </div>
      </div>
      <!-- 彈窗 -->
      <maskModal></maskModal>
    </div>
  </div>
</template>
<script>
import PointsHeader from '@/components/C711_Mobile/Points_header'
import maskModal from './Modal'
export default {
  components: {
    PointsHeader,
    maskModal
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