<template>
  <div id="C711Mobile" style="background-color: #2d0d02;height:100%;">
    <div class="pageBg widthHeightvh fontFamily">
      <LoginHeader></LoginHeader>
      <div class="pageFull pageBg">
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
          <!--客服紀錄-->
          <table class="table table-striped" v-if="$route.meta.customerServiceTransferRecord">
            <thead>
              <tr>
                <th class="number">{{ $t("orderid") }}</th>
                <th class="date">{{ $t("date") }}</th>
                <th class="point">
                  <div class="clearfix">
                    <div>
                      <span class="colorG">{{ $t("transferIn")}}</span>
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
        </div>
      </div>
      <!-- 彈窗 -->
      <maskModal></maskModal>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/C711_Mobile/Login_header'
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
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
        //new Date()補齊兩位數，以符合api參數規則
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
        // 進入紀錄頁時 isSearchRecord_lock=0 才打預設紀錄列表，
        // =1時代表透過時間查詢紀錄，故不打actionRecord_point
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