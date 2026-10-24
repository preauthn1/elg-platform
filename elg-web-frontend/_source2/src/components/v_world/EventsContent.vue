<template>
  <div class="EventsContent">
    <EventsHeader></EventsHeader>
    <div class="EventsContentAll">
      <div class="EventsContenCon">
        <div class="EventsContentTitle">兌換紀錄 - 會員首儲</div>
        <div class="EventsContentList clearfix">
          <div class="EventsContentPic float-left">
            <img src="../../assets/img/v_world/game3.png" />
          </div>
          <div class="EventsContentFont float-left">
            <div class="FontTitle">紅利點數100點</div>
            <div class="FontTime">活動期間:2019/01/01~2019/07/31</div>
          </div>
        </div>
        <div class="EventsContentList clearfix">
          <div class="EventsContentPic float-left">
            <img src="../../assets/img/v_world/game3.png" />
          </div>
          <div class="EventsContentFont float-left">
            <div class="FontTitle">紅利點數100點</div>
            <div class="FontTime">活動期間:2019/01/01~2019/07/31</div>
          </div>
        </div>
        <div class="EventsContentList clearfix">
          <div class="EventsContentPic float-left">
            <img src="../../assets/img/v_world/game3.png" />
          </div>
          <div class="EventsContentFont float-left">
            <div class="FontTitle">紅利點數100點</div>
            <div class="FontTime">活動期間:2019/01/01~2019/07/31</div>
          </div>
        </div>
        <div class="EventsContentList clearfix">
          <div class="EventsContentPic float-left">
            <img src="../../assets/img/v_world/game3.png" />
          </div>
          <div class="EventsContentFont float-left">
            <div class="FontTitle">紅利點數100點</div>
            <div class="FontTime">活動期間:2019/01/01~2019/07/31</div>
          </div>
        </div>
        <div class="EventsContentList clearfix">
          <div class="EventsContentPic float-left">
            <img src="../../assets/img/v_world/game3.png" />
          </div>
          <div class="EventsContentFont float-left">
            <div class="FontTitle">紅利點數100點</div>
            <div class="FontTime">活動期間:2019/01/01~2019/07/31</div>
          </div>
        </div>
        <div class="EventsContentList clearfix">
          <div class="EventsContentPic float-left">
            <img src="../../assets/img/v_world/game3.png" />
          </div>
          <div class="EventsContentFont float-left">
            <div class="FontTitle">紅利點數100點</div>
            <div class="FontTime">活動期間:2019/01/01~2019/07/31</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
// eslint-disable-next-line no-unused-vars
import EventsHeader from '@/components/v_world/Events_header'
export default {
  components: {
    EventsHeader
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
