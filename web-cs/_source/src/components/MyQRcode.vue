<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="qrcodeAll">
      <div class="qrcodePic">
        <div id="qrcode" ref="qrcode"></div>
        <!-- <img src="../assets/img/qrcodeB.png" /> -->
      </div>
      <div class="qrcodeFont ContentFont">掃描上面的行動條碼,即可將你加入好友</div>
    </div>
    <div class="mask talkModal" v-if="noQRcodeShow">
      <div class="talkModalAll">
        <div class="talkModalHeader modalHeaderBg mheaderBorder mheaderFont clearfix">
          {{$t("prompt")}}
          <img
            src="../assets/img/closeBtn.png"
            class="float-right"
            @click="closeModal()"
          />
        </div>
        <div class="talkModalCon">
          <div class="mbodyFont talkModalFont">{{$t("qrCannotBeRead")}}</div>
          <div class="talkModalfotter clearfix">
            <div class="modalBtn modalBtnGreen modalBtnFont">{{$t("confirm")}}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import listTitle from '@/components/ListTitle'
import QRCode from 'qrcodejs2'
export default {
  components: {
    listTitle
  },
  data() {
    return {
      noQRcodeShow: false
    }
  },
  methods: {
    qrcode() {
      let qrcode = new QRCode('qrcode', {
        width: 200,
        height: 200, // 高度
        text: '455634' // 二维码内容或轉跳網址
        // render: 'canvas' // 设置渲染方式（有两种方式 table和canvas，默认是canvas）
        // background: '#f0f'
        // foreground: '#ff0'
      })
    }
  },
  //   mounted() {
  //     this.qrcode()
  //   },
  created() {
    this.$nextTick(() => {
      this.qrcode()
    })
  }
}
</script>
<style scoped>
</style>

