<template>
  <div
    class="pageBg fontFamily pageBackground"
    :class="get_EventDESC.type != 4? 'widthHeightvh': 'CwidthHeight'"
  >
    <div :class="this.$route.query.Banner==2?'bannerEvent':'noBannerEvent'">
      <EventsHeader></EventsHeader>
      <div class="pageCon pageBg">
        <!-- 摸彩 -->

        <div
          v-for="(desc,i) in get_EventDESC"
          :key="'info-'+ i"
          v-if="desc.type == 4 && Date.parse(desc.prize_draw_date.substring(0, 16)).valueOf()<Date.parse(NowDate).valueOf()"
        >
          <div class="yellow_borderInput marginTop20">
            <div class="black_EventDiv purple_Divbg fontsizelogin">
              <div class="ticketTitle">{{$t('winnersList')}}</div>

              <div class="ticketNumAll" v-for="(number,i) in  get_LotteryNumbers" :key="i">
                <div class="ticketNum">
                  <div>
                    <img src="../../assets/img/C711_Mobile/appGift.png" />
                    {{number.name}} ( X {{number.lottery_numbers.length}})
                  </div>
                  <div class="ticketUser">
                    <span v-for="(num,i) in number.lottery_numbers" :key="i">
                      {{num.lottery_number}}
                      <span v-if="number.lottery_numbers.length !=i+1">,</span>
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- 摸彩結束 -->
        <div v-for="(desc,i) in get_EventDESC" :key="i">
          <div class="yellow_borderInput marginBottom20" style="margin-top:10px;">
            <div class="black_EventDiv black_Divbg fontsizelogin">
              <div class="EventDescriptTitle clearfix">
                <div class="float-left eventPr">{{ desc.title }}</div>
                <div class="float-right eventPrR">
                  <img src="../../assets/img/C711_Mobile/ticket.png" />
                </div>
              </div>
              <div class="EventDescriptTime">
                {{ $t("duringTheEvent") }}:
                {{ desc.start_time.substring(0,16) }} ~ {{ desc.end_time.substring(0,16) }}
              </div>
              <div class="EventDescriptDate">
                {{ $t("exchangeDate") }}:
                {{ desc.prize_expire_date.substring(0,16) }}
              </div>
              <div class="EventDescriptFont">{{ desc.content}}</div>
              <div class="reddeemTime" v-if="remaining_number!=0">
                {{ $t("redeemable") }}:
                {{remaining_number}}
              </div>
              <div class="remarks">
                {{$t('description')}}:
                <p>{{$t('remarks')}}</p>
              </div>
              <div class="EventDescriptFont">{{ desc.content}}</div>
            </div>
          </div>
          <div v-if="desc.type!=4">
            <div class="yellow_borderInput marginBottom20" style="margin-top:10px;">
              <div class="black_EventDiv black_Divbg fontsizelogin">
                <div class="EventDescriptTitle">{{ $t("achieveConditions") }}</div>
                <div v-for="(detail,i) in desc.detail" :key="i">
                  <div class="EventDescriptProgress">
                    <b-progress
                      :max="detail.point"
                      show-progress
                      :variant="detail.now_point < detail.point?'danger':'success'"
                    >
                      <b-progress-bar
                        :value="parseFloat(detail.now_point)"
                        :label="(detail.now_point / detail.point >=1)?$t('achieved'):`${(detail.now_point / detail.point) * 100}%`"
                        striped
                        :animated="detail.now_point < detail.point"
                      ></b-progress-bar>
                    </b-progress>
                  </div>

                  <div class="gamer_Btn">
                    <div v-if="detail.vendor_name" class="dateFont">
                      {{ $t("designatedGamer") }}:
                      <span>{{detail.vendor_name}}&nbsp;</span>
                    </div>
                    <!-- 如果結束時間大於今日 -->
                    <a
                      :href="/gameList/+detail.id+'/'+detail.vendor_id+'/'+detail.vendor_name+'/'+eventOpenGame"
                      target="_parent"
                      style="margin-top:-1px;color:#6DD8FF;"
                    >
                      <div
                        class="bar_Btn"
                        v-if="Date.parse(detail.end_time.substring(0,16)).valueOf() > Date.parse(NowDate).valueOf()&&Date.parse(NowDate).valueOf()>Date.parse(detail.start_time.substring(0,16)).valueOf()"
                      >
                        <span>({{$t('viewGames')}})</span>
                      </div>
                    </a>
                  </div>
                  <div
                    v-if="Date.parse(detail.end_time.substring(0,16)).valueOf() > Date.parse(NowDate).valueOf()&&Date.parse(NowDate).valueOf()>Date.parse(detail.start_time.substring(0,16)).valueOf()"
                    class="slotGameFont"
                  >* {{$t('slotGameOnly')}} *</div>
                  <div
                    v-if="detail.order_type_name"
                    class="dateFont"
                  >{{ $t("designatedGold") }}:{{detail.order_type_name}}</div>
                  <div class="EventDescriptDate">
                    {{ $t("currentProgress") }}: {{ detail.now_point}} / {{ detail.point }}{{ $t("points") }}
                    <span>({{detail.action_name}})</span>
                  </div>
                  <div class="dateFont">
                    {{ $t("timeLimit") }}:
                    {{ detail.start_time.substring(0,16) }} ~ {{ detail.end_time.substring(0,16) }}
                  </div>
                </div>
              </div>
            </div>

            <div
              class="dropdown yellow_borderInput marginTop20 aboutEvent marginBottom20"
              v-for="(detail,i) in desc.detail"
              :key="i"
              v-if="detail.games!==undefined && detail.games.length > 0"
            >
              <button
                class="black_InputAll orange_inputbg blackInput"
                style="text-align:center;"
                type="button"
                id="dropdownMenuButton"
                data-toggle="dropdown"
                aria-haspopup="true"
                aria-expanded="false"
              >
                <!-- {{detail.vendor_name}} ({{detail.action_name}}) -->
                <div class="appFontLeft">
                  <img src="../../../static/img/C711_PC/appGift.png" />
                  {{detail.vendor_name}} ({{detail.action_name}})
                </div>
                <div class="appFontRight">
                  <img src="../../../static/img/C711_PC/L.png" />
                </div>
              </button>
              <div
                class="dropdown-menu yellow_borderDropdown eventDesGame"
                aria-labelledby="dropdownMenuButton"
              >
                <div class="gray_dropdownBg">
                  <div class="aboutCon" v-for="(games,i) in detail.games" :key="i">
                    <span>{{games.name}}</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="abreastBtnTwo clearfix" style="padding-bottom:20px;">
              <div class="yellow_borderInput abreastBtn float-left">
                <button
                  type="button"
                  class="form-control black_InputAll"
                  :class="desc.show_exchange == 0?'graycolor':'yellowcolor'"
                  :disabled="desc.show_exchange == 0"
                  @click="playSound();exchange(desc);gaData(desc)"
                >{{ desc.show_exchange_text }}</button>
                <!-- 活動假資料 -->
                <!-- :disabled="desc.show_exchange == 0" -->
                <!-- :class="desc.show_exchange == 0?'yellowcolor':'graycolor'" -->
              </div>
              <div class="yellow_borderInput abreastBtn float-right">
                <button
                  type="button"
                  class="form-control black_InputAll brown_BtnBg fontsizeBtn"
                  @click="playSound();exchangeRecord()"
                  onclick="ga('send', 'event', 'EventDescription', 'ExchangeRecord_Click', 'ExchangeRecord');"
                >{{ $t("exchangeRecord") }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 輪盤 -->
    <div class="iframeLottery lottery_block" v-if="get_isBanner">
      <div class="lotteryClose" @click="lotteryClose()">
        <img src="../../assets/img/C711_Mobile/close.png" />
      </div>

      <iframe
        id="iframe"
        name="frame"
        :src="LotteryURL"
        width="102%"
        frameborder="0"
        scrolling="auto"
        class="iframeHeight"
        style="background:#2d0d02;"
      ></iframe>
    </div>
    <!-- 刮刮卡 -->
    <div class="iframeLottery lottery_block" v-if="get_isCard">
      <div class="lotteryClose" @click="lotteryClose()">
        <img src="../../assets/img/C711_Mobile/close.png" />
      </div>

      <iframe
        id="iframe1"
        name="frame"
        :src="ScratchCardURL"
        width="102%"
        frameborder="0"
        scrolling="auto"
        class="iframeHeight"
        style="background:#2d0d02;"
      ></iframe>
    </div>
  </div>
</template>
<script>
import EventsHeader from '@/components/C711_PC/Events_header'
import '../../assets/scss/C711_PC/css.css'
import '../../assets/scss/C711_PC/style.css'
import { mapGetters, mapMutations } from 'vuex'
import Bus from './Bus.js'
export default {
  components: {
    EventsHeader
  },
  computed: {
    ...mapGetters([
      'get_EventDESC',
      'get_LotteryNumbers',
      'get_isBanner',
      'get_isCard',
      'get_Prize',
      'get_Prizes'
    ])
  },
  data() {
    return {
      remaining_number: sessionStorage.getItem('remaining_number'),
      eventOpenGame: 1,
      bannerEventType: sessionStorage.getItem('eventTypeBanner'),
      LotteryURL: '/Lottery?id=' + this.$route.query.id,
      ScratchCardURL: '/ScratchCard?id=' + this.$route.query.id,
      bannerEventURL: ''
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
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    exchange(event) {
      if (this.$route.query.Banner == 2) {
        sessionStorage.setItem('bannerOpen', 1)
      }
      let data = {
        event_id: event.id,
        type: event.type
      }
      sessionStorage.setItem('eventTypeBanner', event.type)
      this.$store.dispatch('action_GetPrize', data)
    },
    lotteryClose() {
      sessionStorage.removeItem('bannerOpen')
      this.$store.commit('HIDE_BANNER_OPEN')
      this.$store.commit('HIDE_CARD_OPEN')
    },
    gaData(event) {
      if (event.type == 1) {
        var eventType = '刮刮卡'
      } else if (event.type == 2) {
        var eventType = '轉輪'
      } else {
        var eventType = '直接兌獎'
      }
      ga('send', 'event', {
        eventCategory: 'Event',
        eventAction: 'EventExchange_Click',
        eventLabel: eventType,
        eventValue: event.id
      })
    },
    exchangeRecord() {
      this.$router.push('/ScratchCardRecord?Banner=' + 2)
    },
    getEventDESC() {
      let id = this.$route.query.id
      this.$store.dispatch('action_EventDESC', id)
    },
    getLotteryNumbers() {
      let id = this.$route.query.id
      this.$store.dispatch('action_LotteryNumbers', id)
    }
  },
  created() {
    this.getEventDESC()
    this.getLotteryNumbers()
  }
}
</script>