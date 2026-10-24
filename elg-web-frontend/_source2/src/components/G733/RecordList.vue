<template>
  <div id="G733">
    <div class="mobileHeader">
      <LoginHeader></LoginHeader>
    </div>
    <!-- LoginMain -->
    <div class="single_block">
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainNoB zindex2">
          <div class="pcmainBg pctop">
            <div class="mainLogin"></div>

            <div class="loginInfo">
              <div class="clearfix">
                <div class="recordType float-left">
                  <b-form-select
                    v-model="selected"
                    class="yellowInput input_all"
                    style="height:auto;color:#fff;cursor: pointer; "
                    :options="recordtype"
                    @change="search(selected)"
                  ></b-form-select>
                  <div class="recordPic float-right">
                    <img src="../../assets/img/G733/D.png" alt class="recordD" />
                  </div>
                </div>
                <div class="date_block" v-if="haveDate">
                  <vue-datepicker-local v-model="startDate" />
                  <p class="DateFont">~</p>

                  <vue-datepicker-local v-model="endDate" />
                  <div @click="search(selected)" class="margin" style="cursor: pointer;">
                    <img src="/static/img/C711_PC/user@example.invalid" />
                  </div>
                </div>
              </div>
              <div class="RecordListCon">
                <!-- 平台轉入轉出 -->
                <table class="table table-striped" v-if="selected == 1 ||  dataSelection == 1">
                  <thead>
                    <tr>
                      <th class="number">{{ $t("num") }}</th>
                      <th class="date">{{ $t("date") }}</th>
                      <th class="gamer">{{ $t("gamepublisher") }}</th>
                      <th class="point">
                        <span class="colorR">{{ $t("transferIn") }}</span>/
                        <span class="colorB">{{ $t("transferOut") }}</span>
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
                      <td v-if="record.type == 1" class="colorR">{{ record.point }}</td>
                      <td v-if="record.type == 2" class="colorB">{{ record.point }}</td>
                    </tr>
                  </tbody>
                </table>
                <!--客服紀錄-->
                <table class="table table-striped" v-if="selected == 2">
                  <thead>
                    <tr>
                      <th class="number">{{ $t("orderid") }}</th>
                      <th class="date">{{ $t("date") }}</th>

                      <th class="point">
                        <div class="clearfix">
                          <span class="colorR">{{ $t("transferIn")}}</span>/
                          <span class="colorB">{{ $t("transferOut") }}</span>
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
                      <td v-if="record.type == 1" class="colorR">{{ record.point }}</td>
                      <td v-if="record.type == 2" class="colorB">{{ record.point }}</td>
                    </tr>
                  </tbody>
                </table>
                <!--刮刮卡轉入紀錄-->
                <table
                  class="table table-striped"
                  v-if="selected == 3 ||$route.meta.scratchCardRecord"
                >
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
                <table
                  class="table table-striped"
                  v-if="selected== 4||selected == 5||selected == 6"
                >
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
              </div>
            </div>
          </div>
        </div>
        <!-- 彈窗 -->
        <maskModal></maskModal>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import { mapGetters, mapActions } from 'vuex'
import VueDatepickerLocal from 'vue-datepicker-local'
import maskModal from './Modal'
export default {
  components: {
    LoginHeader,
    VueDatepickerLocal,
    maskModal
  },
  computed: {
    ...mapGetters(['getRecordPoints', 'get_Prize_Record'])
  },
  data() {
    return {
      startDate: '',
      endDate: new Date(),
      haveDate: false,
      recordtype: [
        { text: this.$t('type'), value: null },
        { text: this.$t('platformTransfer'), value: 1 },
        { text: this.$t('customerServiceTransferRecord'), value: 2 },
        { text: this.$t('scratchCardTransferRecord'), value: 3 },
        {
          text:
            '(' + this.$t('redEnvelopeRecord') + ')' + this.$t('redEnvelope'),
          value: 4
        },
        {
          text:
            '(' +
            this.$t('redEnvelopeRecord') +
            ')' +
            this.$t('receivedRedEnvelope'),
          value: 5
        },
        {
          text:
            '(' +
            this.$t('redEnvelopeRecord') +
            ')' +
            this.$t('redEnvelopeRefund'),
          value: 6
        }
      ],
      selected: null,
      isEnvelope: false,
      listType: null,

      dataSelection: window.sessionStorage.getItem('isSearchRecord_lock')
    }
  },
  watch: {
    selected() {
      if (this.selected == 1) {
        this.haveDate = true
        $('.searchBtn').css('display', 'block')
      } else {
        this.haveDate = false
        $('.searchBtn').css('display', 'none')
      }
    },
    redEnvelope_select() {}
  },
  mounted() {
    $('.searchBtn').css('display', 'none')
  },
  methods: {
    getTransactions() {
      window.sessionStorage.setItem('isSearchRecord_lock', '0')
    },
    defaultStart() {
      let dt = this.endDate
      this.startDate = new Date(dt.getTime() - 30 * 24 * 60 * 60 * 1000)
    },

    search(value) {
      function append_zero(obj) {
        if (obj < 10 && obj.length < 2) return '0' + obj
        else return obj
      }
      /// 結束 //
      let dt = new Date(this.endDate)
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
      let start = new Date(this.startDate)
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
      this.selected = value

      if (this.selected == 1) {
        // 平台轉入轉出
        this.$store.dispatch('actionRecord_point', range)
      } else if (this.selected == 2) {
        // 客服轉點
        this.$store.dispatch('actionRecord_Customer')
      } else if (this.selected == 3) {
        // 活動
        this.$store.dispatch('action_PrizeRecords')
      } else if (this.selected == 4) {
        // 紅包發送
        this.$store.dispatch('actionRecord_RedEnvelope', 1)
      } else if (this.selected == 5) {
        // 紅包領取
        this.$store.dispatch('actionRecord_RedEnvelope', 2)
      } else if (this.selected == 6) {
        // 紅包退還
        this.$store.dispatch('actionRecord_RedEnvelope', 3)
      }
    }
  },
  created() {
    this.defaultStart()

    this.getTransactions()
    if (window.location.pathname == '/ScratchCardRecord') {
      //刮刮卡
      this.$store.dispatch('action_PrizeRecords')
    }
  }
}
</script>