<template>
  <div class="NewEvent">
    <LoginHeader></LoginHeader>
    <div class="NewEventItem">
      <div class="EventList">
        <a href="/BannerEvents">
          <div class="EventTitle">萬眾期待刮刮卡活動</div>
          <div class="EventTime">活動期間:2018/05*30~2019/12/31</div>
        </a>
      </div>
      <div class="EventList">
        <div class="EventTitle">萬眾期待刮刮卡活動</div>
        <div class="EventTime">活動期間:2018/05*30~2019/12/31</div>
      </div>
      <div class="EventList">
        <div class="EventTitle">萬眾期待刮刮卡活動</div>
        <div class="EventTime">活動期間:2018/05*30~2019/12/31</div>
      </div>
      <div class="EventList">
        <div class="EventTitle">萬眾期待刮刮卡活動</div>
        <div class="EventTime">活動期間:2018/05*30~2019/12/31</div>
      </div>
      <div class="EventList">
        <div class="EventTitle">萬眾期待刮刮卡活動</div>
        <div class="EventTime">活動期間:2018/05*30~2019/12/31</div>
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
