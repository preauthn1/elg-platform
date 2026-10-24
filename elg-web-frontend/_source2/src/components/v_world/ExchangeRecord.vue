<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <!--content-->
    <div class="AllContent">
      <div v-for="(record,i) in get_Prize_Record" :key="i" class="ExchangeRecordList clearfix">
        <div class="ExchangeRecordWrap float-left">
          <div class="ExchangeRecordTitle">{{ record.event_name }}</div>
          <div class="ExchangeRecordTime">兌換時間: {{ record.updated_at.substring(0,16) }}</div>
        </div>
        <div class="ExchangeRecordBtn float-left">
          <p style="margin:0">獎項名稱:</p>
          <div style="position:relative">
            <span>{{ record.name }}</span>
            <img src="../../assets/img/v_world/GrBtn.png" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import { mapGetters } from 'vuex'

// eslint-disable-next-line no-unused-vars
import LoginHeader from '@/components/v_world/Login_header'
export default {
  components: {
    LoginHeader
  },
  computed: {
    ...mapGetters(['get_Prize_Record'])
  },
  created() {
    this.$store.dispatch('action_PrizeRecords')
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
