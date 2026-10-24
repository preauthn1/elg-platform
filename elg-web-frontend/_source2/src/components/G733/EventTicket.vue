<template>
  <div id="G733">
    <EventsHeader></EventsHeader>
    <div class="single_block">
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainNoB zindex2">
          <div class="mainEvent">
            <div class="goldBorder pointbtnBorder_all marginBottom20" style="width:95%;">
              <div class="eventlottyDiv eventDiv">
                <div class="ticketTitle">{{$t('lotteryTicketNumber')}}</div>
                <div class="ticketNumAll">
                  <div class="ticketNum" v-for="(num, i) in get_MyLotteryNumbers" :key="i">
                    <span>
                      {{num.lottery_number}}
                      <span v-if="num.status ==1">({{$t('win')}})</span>
                      <span v-else>({{$t('missed')}})</span>
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- 彈窗 -->
          <maskModal></maskModal>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import EventsHeader from '@/components/G733/Events_header'
import maskModal from './Modal'
import { mapGetters } from 'vuex'
export default {
  components: {
    EventsHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['get_EventDESC', 'get_MyLotteryNumbers'])
  },
  data() {
    return {
      remaining_number: sessionStorage.getItem('remaining_number'),
      NowDate: '',
      eventOpenGame: 1
    }
  },
  mounted() {
    //   取今日日期
    var Today = new Date()
    this.NowDate =
      Today.getFullYear() +
      '-' +
      (Today.getMonth() + 1) +
      '-' +
      Today.getDate() +
      ' ' +
      Today.getHours() +
      ':' +
      Today.getMinutes()

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
  },
  methods: {
    //   取摸彩號碼
    getMyLotteryNumber() {
      let id = this.$route.query.id
      this.$store.dispatch('action_MyLotteryNumbers', id)
    }
  },
  created() {
    this.getMyLotteryNumber()
  }
}
</script>

