<template>
  <div class="pageBg fontFamily CwidthHeight pageBackground">
    <EventsHeader></EventsHeader>
    <div class="pageCon pageBg widthHeightvh">
      <div class="pageCon">
        <div class="yellow_borderInput marginTop20">
          <div class="black_EventDiv purple_Divbg fontsizelogin">
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
    </div>
  </div>
</template>
<script>
import EventsHeader from '@/components/C711_PC/Events_header'
import '../../assets/scss/C711_PC/css.css'
import '../../assets/scss/C711_PC/style.css'
import { mapGetters, mapMutations } from 'vuex'
export default {
  components: {
    EventsHeader
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
    $('.footer_block').css('display', 'none')
  },
  methods: {
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