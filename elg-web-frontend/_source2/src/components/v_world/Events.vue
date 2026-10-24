<template>
  <div class="Events">
    <EventsHeader></EventsHeader>
    <!--content-->
    <div class="eventsAll">
      <div class="eventsCon">
        <div class="eventsConFont" v-if="get_Events==''">* {{$t('noEvents')}}</div>
        <div class="eventsConFont" v-else>
          <div class="eventPr">* {{$t('updatedTenMinutes')}}</div>
          <div class="eventPrR">
            <img src="../../assets/img/v_world/ticket.png" />
            ：{{$t('cumulativeClaim')}}
          </div>
        </div>
        <div v-for="(event,i) in get_Events" :key="i" class="eventsList clearfix">
          <div v-if="event.type_id == 2" class="eventsTitle clearfix">
            <div class="eventsLogo float-left">
              <img src="../../assets/img/v_world/Group.png" />
            </div>
            <!-- vip TITLE -->
            <div class="eventsFont float-left">
              {{event.title}}
              <span>{{event.id}}</span>

              <!-- {{event.prize_is_repeatedly}} -->
            </div>
            <div class="float-right eventTicket" v-if="event.prize_is_repeatedly===1">
              <img src="../../assets/img/v_world/ticket.png" />
            </div>
          </div>
          <!-- BLUE TITLE -->
          <div v-if="event.type_id == 1" class="eventsTitle clearfix">
            <div class="eventsFontBlue float-left">
              {{event.title}}
              <span>{{event.id}}</span>

              <!-- {{event.prize_is_repeatedly}} -->
            </div>
            <div class="float-right eventTicket" v-if="event.prize_is_repeatedly===1">
              <img src="../../assets/img/v_world/ticket.png" />
            </div>
          </div>
          <div class="eventTime" v-if="event.start_time!==''||event.end_time!==''">
            {{ $t("duringTheEvent") }}:
            {{event.start_time.substring(0,16)}} ~ {{event.end_time.substring(0,16)}}
          </div>
          <div class="reddeemTime" v-if="event.remaining_number!==0">
            {{ $t("redeemable") }}:
            {{event.remaining_number}}
          </div>
          <div class="remarks">
            {{$t('description')}}:
            <p>{{$t('remarks')}}</p>
          </div>
          <div v-for="(detail,i) in event.detail" :key="i">
            <div class="eventsProgress">
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
            <div
              v-if="detail.vendor_name"
              class="gamer dateFont"
            >{{ $t("designatedGamer") }}:{{detail.vendor_name}}</div>
            <div
              v-if="detail.order_type_name"
              class="goldFlow dateFont"
            >{{ $t("designatedGold") }}:{{detail.order_type_name}}</div>

            <div class="eventsPointFont">
              {{ $t("currentProgress") }}: {{detail.now_point}} {{ $t("points") }} / {{detail.point}} {{ $t("points") }}
              <span>({{detail.action_name}})</span>
            </div>
            <div class="dateFont">
              {{ $t("timeLimit") }}:
              {{detail.start_time.substring(0,16)}} ~ {{detail.end_time.substring(0,16)}}
            </div>
          </div>
          <div class="eventsBtn clearfix">
            <div class="imgfont float-left" :class="event.show_exchange == 0?'grayBtn':'blueBtn'">
              <button
                :disabled="event.show_exchange == 0"
                @click="playSound();exchange(event);gaData(event)"
                style="border: none; background: none;"
              >
                <span v-if="event.prize_is_repeatedly===1">
                  <!-- {{ event.show_exchange_text }} -->
                  {{event.show_exchange == 0? $t('unaccomplished') :$t('redeemNow')}}
                </span>
                <span v-else>
                  <!-- {{ event.show_exchange_text }} -->
                  {{event.show_exchange == 0? $t('redeemed') :$t('redeemNow')}}
                </span>
              </button>
            </div>
            <div class="imgfont greenBtn float-right">
              <div @click="playSound();eventDESC(event.id,event.remaining_number)">
                <span>{{ $t("eventDescription") }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- 直接開獎 -->
      <!-- <div v-if="get_isPrize" class="mask">
        <div class="modalBlueAll">
          <span class="eventFontTitle">{{ $t("itemname") }}:</span>
          <p>{{ get_Prize.prize.name }}</p>
          <div
            class="greenBtn modalBtn"
            @click="HIDE_IS_PRIZE"
            style="margin:auto;"
          >{{ $t("confirm") }}</div>
        </div>
      </div>-->

      <div v-if="get_isPrize" class="mask depositvisible">
        <div class="modalTitleAll">
          <div class="modalTitle">
            <img src="../../assets/img/v_world/modalTitle.png" class="TitlePic" />
            <div class="modalTitleAll">
              <div class="contentTitle">{{ $t("itemname") }}</div>
            </div>
          </div>
          <div class="modalContentAll">
            <div class="nomoneyFont" style="padding-top:20px;">{{ get_Prize.prize.name }}</div>
            <div class="imgfontGreen im" @click="prizeConfirm()">
              <span>{{ $t("confirm") }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters, mapMutations } from 'vuex'
import maskModal from './Modal'
// eslint-disable-next-line no-unused-vars
import EventsHeader from '@/components/v_world/Events_header'

export default {
  components: {
    EventsHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['get_Events', 'get_isPrize', 'get_Prize'])
  },
  data() {
    return {}
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
  },
  methods: {
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    // ...mapMutations(['HIDE_IS_PRIZE']),
    exchange(event) {
      let data = {
        event_id: event.id,
        type: event.type
      }
      this.$store.dispatch('action_GetPrize', data)
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
    eventDESC(id, remaining_number) {
      this.$router.push('/EventDescription?id=' + id)
      sessionStorage.setItem('remaining_number', remaining_number)
      ga('send', 'event', {
        eventCategory: 'Event',
        eventAction: 'EventDESC_Click',
        eventLabel: id
      })
    },
    prizeConfirm() {
      this.$store.commit('HIDE_IS_PRIZE')
      this.$store.dispatch('action_EventList')
    }
  },
  created() {
    this.$store.dispatch('action_EventList')
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
.eventTime {
  line-height: inherit !important;
}
</style>
