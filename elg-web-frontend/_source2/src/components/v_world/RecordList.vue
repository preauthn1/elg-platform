<template>
  <!--用於全部的紀錄頁面-->
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="RecordListAll">
      <div class="RecordListCon">
        <!--平台紀錄-->
        <table class="table table-striped" v-if="$route.meta.recordTable">
          <thead>
            <tr>
              <th class="number">{{ $t("num") }}</th>
              <th class="date">{{ $t("date") }}</th>
              <th class="gamer">{{ $t("gamepublisher") }}</th>
              <th class="point">
                <div class="clearfix">
                  <div>
                    <span class="colorG">{{ $t("transferIn") }}</span>
                  </div>
                  <div>
                    <span class="colorR">{{ $t("transferOut") }}</span>
                  </div>
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(record,i) in getRecordPoints" :key="i">
              <th scope="row">{{ record.id }}</th>
              <td>
                <div>{{ record.updated_at }}</div>
              </td>
              <td>{{ record.vendor_name }}</td>
              <td v-if="record.type == 1" class="colorG">{{ record.point }}</td>
              <td v-if="record.type == 2" class="colorR">{{ record.point }}</td>
            </tr>
          </tbody>
        </table>
        <!--紅包紀錄-->
        <table class="table table-striped" v-if="$route.meta.redEnvelopeTable">
          <thead>
            <tr>
              <th class="date">{{ $t("date") }}</th>
              <th class="gamer">{{ $t("friend") }}</th>
              <th class="point">
                <div class="clearfix">
                  <div>
                    <span>{{ $t("amount")}}</span>
                  </div>
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(record,i) in getRecordPoints" :key="i">
              <td>
                <div>{{ record.created_at }}</div>
              </td>
              <td>{{ record.nickname }}</td>
              <td>{{ record.point }}</td>
            </tr>
          </tbody>
        </table>
        <!--刮刮卡轉入紀錄-->
        <table class="table table-striped" v-if="$route.meta.scratchCardRecord">
          <thead>
            <tr>
              <th class="cardnumber">{{ $t("eventname") }}</th>
              <th class="date">{{ $t("date") }}</th>
              <th class="cardgamer">{{ $t("itemname") }}</th>
              <th class="cardpoint">{{ $t("points") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(record,i) in get_Prize_Record" :key="i">
              <th scope="row">{{ record.event_name }}</th>
              <td>
                <div>{{ record.updated_at }}</div>
              </td>
              <td>{{ record.name }}</td>
              <td>{{ record.point }}</td>
            </tr>
          </tbody>
        </table>
        <!--客服紀錄-->
        <table class="table table-striped" v-if="$route.meta.customerServiceTransferRecord">
          <thead>
            <tr>
              <th class="number">{{ $t("orderid") }}</th>
              <th class="date">{{ $t("date") }}</th>
              <!-- <th class="gamer">{{ $t("source") }}</th> -->
              <th class="point">
                <div class="clearfix">
                  <div>
                    <span class="colorG">{{ $t("transferIn")}}</span>/
                  </div>
                  <div>
                    <span class="colorR">{{ $t("transferOut") }}</span>
                  </div>
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(record,i) in getRecordPoints" :key="i">
              <th scope="row">{{ record.id }}</th>
              <td>
                <div>{{ record.created_at }}</div>
              </td>
              <td v-if="record.type == 1" class="colorG">{{ record.point }}</td>
              <td v-if="record.type == 2" class="colorR">{{ record.point }}</td>
            </tr>
          </tbody>
        </table>
        <div class="REnvelopeListWrap"></div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
import LoginHeader from '@/components/v_world/Login_header'
export default {
  components: {
    LoginHeader,
    maskModal
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
      //   console.log('lock', window.sessionStorage.getItem('isSearchRecord_lock'))
      if (window.sessionStorage.getItem('isSearchRecord_lock') !== '1') {
        this.$store.dispatch('actionRecord_point', range)
      }
      window.sessionStorage.setItem('isSearchRecord_lock', '0')
    }
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
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
