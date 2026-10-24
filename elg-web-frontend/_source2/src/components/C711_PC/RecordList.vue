<template>
  <div
    class="pageBg CwidthHeight fontFamily"
    :class="this.$route.query.Banner==2?'bannerEvent':'noBannerEvent'"
  >
    <EventsHeader></EventsHeader>
    <div class="pageCon">
      <div class="pageFull">
        <div class="RecordListCon">
          <!--刮刮卡轉入紀錄-->
          <table class="table table-striped" v-if="$route.meta.scratchCardRecord">
            <thead>
              <tr>
                <th class="date">{{ $t("date") }}</th>
                <th class="cardnumber">{{ $t("eventname") }}</th>
                <th class="cardgamer">{{ $t("itemname") }}</th>
                <th class="cardpoint">{{ $t("points") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(record,i) in get_Prize_Record" :key="i">
                <th>
                  <div>{{ record.updated_at }}</div>
                </th>
                <td scope="row">{{ record.event_name }}</td>
                <td>{{ record.name }}</td>
                <td>{{ record.point }}</td>
              </tr>
            </tbody>
          </table>
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
    ...mapGetters(['getRecordPoints', 'get_Prize_Record'])
  },
  methods: {
    getTransactions() {
      function append_zero(obj) {
        if (obj < 10 && obj.length < 2) return '0' + obj
        else return obj
      }
      /// 結束 //
      let dt = new Date()
      let year = dt.getFullYear()
      let month = append_zero((dt.getMonth() + 1).toString())
      let date = append_zero(dt.getDate().toString())
      let hours = append_zero(dt.getHours().toString())
      let minute = append_zero(dt.getMinutes().toString())
      let second = append_zero(dt.getSeconds().toString())
      let endTime =
        year +
        '-' +
        month +
        '-' +
        date +
        ' ' +
        hours +
        ':' +
        minute +
        ':' +
        second
      /// 開始 //
      let start = new Date(dt.getTime() - 30 * 24 * 60 * 60 * 1000)
      let sYear = start.getFullYear()
      let sMonth = append_zero((start.getMonth() + 1).toString())
      let sDate = append_zero(start.getDate().toString())
      let sHours = append_zero(start.getHours().toString())
      let sMinute = append_zero(start.getMinutes().toString())
      let sSecond = append_zero(start.getSeconds().toString())
      let startTime =
        sYear +
        '-' +
        sMonth +
        '-' +
        sDate +
        ' ' +
        sHours +
        ':' +
        sMinute +
        ':' +
        sSecond
      let range = {}
      range['start_time'] = startTime
      range['end_time'] = endTime

      if (window.sessionStorage.getItem('isSearchRecord_lock') !== '1') {
        this.$store.dispatch('actionRecord_point', range)
      }
      window.sessionStorage.setItem('isSearchRecord_lock', '0')
    }
  },
  created() {
    if (window.location.pathname == '/RecordList') {
      //轉入轉出
      this.getTransactions()
    } else if (window.location.pathname == '/ScratchCardRecord') {
      //刮刮卡
      this.$store.dispatch('action_PrizeRecords')
    } else if (window.location.pathname == '/CustomerServiceRecord/Success') {
      //客服轉點
      this.$store.dispatch('actionRecord_Customer')
    } else if (window.location.pathname == '/ReceivedRedEnvelope') {
      // 收紅包
      this.$store.dispatch('actionRecord_RedEnvelope', 2)
    } else if (window.location.pathname == '/RedEnvelope') {
      // 發紅包
      this.$store.dispatch('actionRecord_RedEnvelope', 1)
    } else if (window.location.pathname == '/RedEnvelopeRefund') {
      // 退紅包
      this.$store.dispatch('actionRecord_RedEnvelope', 3)
    }
  }
}
</script>