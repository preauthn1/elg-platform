<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="qrcodeCreame">
      <qrcode-stream @decode="onDecode" @init="onInit" />
    </div>
    <div class="qrcodesearBottom qrcodeButtonBg clearfix">
      <div class="buttonLR float-left blueBtnPic buttonAll ContentFont">從相簿中選擇</div>
      <a href="/MyQRcode">
        <div class="buttonLR float-right blueBtnPic buttonAll ContentFont">顯示行動條碼</div>
      </a>
    </div>
  </div>
</template>
<script>
import listTitle from '@/components/ListTitle'
import { QrcodeStream } from 'vue-qrcode-reader'
export default {
  components: {
    listTitle,
    QrcodeStream
  },
  data() {
    return {}
  },
  methods: {
    onDecode(result) {
      this.result = result
    }
  }
}
</script>
<style scoped>
</style>

