<template>
  <div class="EventDescription">
    <EventsHeader></EventsHeader>

    <div v-for="(desc,i) in get_EventDESC" :key="i" class="EventDescriptionAll">
      <!-- <div class="EventDescriptionPic">
        <img src="../../assets/img/v_world/eventPic.png" />
      </div>-->
      <div class="EventDescriptionCon">
        <div class="EventDescriptionList">
          <div class="EventDescriptTitle clearfix">
            <div class="float-left eventPr">{{ desc.title }}</div>
            <div class="float-right eventPrR">
              <img src="../../assets/img/v_world/ticket.png" />
            </div>
            <!-- v-if="desc.prize_is_repeatedly===1" -->

            <!-- {{desc.prize_is_repeatedly}} -->
          </div>
          <div class="EventDescriptTime">
            {{ $t("duringTheEvent") }}:
            {{ desc.start_time.substring(0,16) }} ~ {{ desc.end_time.substring(0,16) }}
          </div>
          <div class="EventDescriptDate">
            {{ $t("exchangeDate") }}:
            {{ desc.expire_date.substring(0,16) }}
          </div>
          <div class="reddeemTime" v-if="remaining_number!==0">
            {{ $t("redeemable") }}:
            {{remaining_number}}
          </div>
          <div class="remarks">
            {{$t('description')}}:
            <p>{{$t('remarks')}}</p>
          </div>
          <div class="EventDescriptFont">{{ desc.content}}</div>
        </div>
        <div class="EventDescriptionList">
          <div class="EventDescriptTitle_Gray">{{ $t("achieveConditions") }}</div>
          <div v-for="(detail,i) in desc.detail" :key="i">
            <div class="EventDescriptProgress">
              <b-progress
                :max="detail.point"
                show-progress
                :variant="detail.now_point < detail.point?'danger':'success'"
              >
                <b-progress-bar
                  :value="parseFloat(detail.now_point)"
                  :label="(detail.now_point / detail.point >=1)? $t('achieved'):`${(detail.now_point / detail.point) * 100}%`"
                  striped
                  :animated="detail.now_point < detail.point"
                ></b-progress-bar>
              </b-progress>
            </div>
            <div
              v-if="detail.vendor_name"
              class="dateFont"
            >{{ $t("designatedGamer") }}:{{detail.vendor_name}}</div>
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
        <div class="EventDescriptionList">
          <div class="EventDescriptTitle_Gray">{{ $t("award") }}</div>
          <div class="EventDescriptFont">
            <div class="eventItem" v-for="(prize,i) in desc.prize" :key="i">{{ prize.name }}</div>
            <!-- <ol>
              <li v-for="(prize,i) in desc.prize" :key="i">{{ prize.name }}</li>
            </ol>-->
          </div>
        </div>
      </div>
      <div class="EventDescriptBtn clearfix">
        <div class="imgfont float-left" :class="desc.show_exchange == 0?'grayBtn':'blueBtn'">
          <button
            :disabled="desc.show_exchange == 0"
            @click="playSound();exchange(desc);gaData(desc)"
            style="border: none; background: none;"
          >
            <span>{{ desc.show_exchange_text }}</span>
          </button>
        </div>
        <div
          class="imgfont greenBtn float-right"
          onclick="ga('send', 'event', 'EventDescription', 'ExchangeRecord_Click', 'ExchangeRecord');"
        >
          <div @click="playSound();exchangeRecord()">
            <span>{{ $t("exchangeRecord") }}</span>
          </div>
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters } from 'vuex'
import maskModal from './Modal'
// eslint-disable-next-line no-unused-vars
import EventsHeader from '@/components/v_world/Events_header'
export default {
  components: {
    EventsHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['get_EventDESC'])
  },
  data() {
    return {
      remaining_number: sessionStorage.getItem('remaining_number')
    }
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
  },
  methods: {
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
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
    exchangeRecord() {
      this.$router.push('/ScratchCardRecord')
    },
    getEventDESC() {
      let id = this.$route.query.id
      this.$store.dispatch('action_EventDESC', id)
    }
  },
  created() {
    this.getEventDESC()
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
.dateFont {
  height: inherit !important;
}
</style>
