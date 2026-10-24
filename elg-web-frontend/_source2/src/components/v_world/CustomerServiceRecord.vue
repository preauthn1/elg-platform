<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="buleBtnPadding">
      <div class="buleBtnItem">
        <div class="textbox buleBtndiv">
          {{ $t("points") }}:
          156842
        </div>
      </div>
      <div class="BtnAll">
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a href="/SuccessfulRecord">
              <div class="float-left blueBtnFont">{{ $t("successfulRecord") }}</div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a href="/ProcessingRecord">
              <div class="float-left blueBtnFont">{{ $t("processingRecord") }}</div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
        <div class="blueBtnLong">
          <div class="blueBtn_Setting clearfix">
            <a href="/FailureRecord">
              <div class="float-left blueBtnFont">{{ $t("failureRecord") }}</div>
              <div class="float-right blueBtnRight">
                <img src="../../assets/img/v_world/right.png" />
              </div>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/v_world/Login_header'
export default {
  components: {
    LoginHeader
  },
  mounted() {
    //手機方向
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
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
