<template>
  <div id="C711Mobile  CwidthHeight" style="background:#2d0d02;">
    <div
      class="pageBg fontFamily"
      :class="get_EventDESC.type != 4? 'widthHeightvh': 'CwidthHeight'"
    >
      <EventsHeader></EventsHeader>
      <div class="pageCon">
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
                <!-- {{ desc.start_time }} ~ {{ desc.end_time }} -->
                {{ desc.start_time.substring(0,16) }} ~ {{ desc.end_time.substring(0,16) }}
              </div>
              <div class="EventDescriptDate">
                {{ $t("exchangeDate") }}:
                <!-- {{ desc.prize_expire_date }} -->
                {{ desc.prize_expire_date.substring(0,16) }}
              </div>
              <!-- v-if="remaining_number!=0" -->
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
                      :href="/GameList/+detail.id+'/'+detail.vendor_id+'/'+eventOpenGame"
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
              class="dropdown yellow_borderInput marginTop20 about marginBottom20"
              v-for="(detail,i) in desc.detail"
              :key="i"
              v-if="detail.games!==undefined && detail.games.length > 0 "
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
                <div class="appFontLeft">
                  <img src="../../assets/img/C711_Mobile/appGift.png" />
                  {{detail.vendor_name}} ({{detail.action_name}})
                </div>
                <div class="appFontRight">
                  <img src="../../assets/img/C711_Mobile/L.png" />
                </div>
              </button>
              <div
                class="dropdown-menu yellow_borderDropdown termsAndConditions eventDesGame"
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
      <!-- 彈窗 -->
      <maskModal></maskModal>
    </div>
  </div>
</template>
<script>
import EventsHeader from '@/components/C711_Mobile/Events_header'
import maskModal from './Modal'
import { mapGetters } from 'vuex'
export default {
  components: {
    EventsHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['get_EventDESC', 'get_LotteryNumbers'])
  },
  data() {
    return {
      // 次數
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
    //   音效
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    //兌換獎勵
    exchange(event) {
      let data = {
        event_id: event.id,
        type: event.type
      }
      this.$store.dispatch('action_GetPrize', data)
    },
    // GA
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
      //兌換記錄
      this.$router.push('/ScratchCardRecord')
    },
    getEventDESC() {
      //活動詳細內容
      let id = this.$route.query.id
      this.$store.dispatch('action_EventDESC', id)
    },
    getLotteryNumbers() {
      //摸彩
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

