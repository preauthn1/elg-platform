<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="buleBtnPadding">
      <div class="buleBtnItem">
        <div class="textbox buleBtndiv">
          {{ $t("points") }}:
          <span>{{ getAcctInfo.balance }}</span>
          <a
            href="/StoredValue"
            onClick="ga('send', 'event', 'Wallet', 'StoredBtn_Click', 'Stored');"
          >
            <img src="../../assets/img/v_world/Gadd.png" class="float-right walletAddBtn" />
          </a>
        </div>
      </div>
      <div class="BtnAll">
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a
              href="/Points"
              onClick="ga('send', 'event', 'Wallet', 'TransferBtn_Click', 'TransferInOut');"
            >
              <div class="float-left blueBtnFont">
                <span>{{ $t("platformTransfer") }}</span>
              </div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a href="/Record" onClick="ga('send', 'event', 'Wallet', 'RecordBtn_Click', 'Record');">
              <div class="float-left blueBtnFont">
                <span>{{ $t("recordInquiry") }}</span>
              </div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
      </div>
    </div>
    <!-- <div
      class="ys-float-btn"
      :style="{'width':itemWidth+'px','height':itemHeight+'px','left':left+'px','top':top+'px'}"
      ref="div"
      @click="onBtnClicked"
    >
      <slot name="icon"></slot>
      <a @click="EventBtn()">
        <img src="../../assets/img/v_world/eventBtn.png" />
      </a>
    </div>
    <div v-if="eventVisible" class="eventModal">
      <Events></Events>
    </div>-->
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters } from 'vuex'
import maskModal from './Modal'
import LoginHeader from '@/components/v_world/Login_header'
import Events from '@/components/v_world/Events'
export default {
  name: 'FloatImgBtn',

  components: {
    LoginHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['getAcctInfo'])
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
  },

  created() {
    this.$store.dispatch('actionMember')
  }
}
</script>

