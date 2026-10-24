<template>
  <div>
    <div class="bgi">
      <LobbyHeader></LobbyHeader>
      <div class="record">
        <div class="tool">
          <div class="select_block">
            <div class="ele-border bankPic">
              <b-form-select
                v-model="selected"
                class="ele_border_none gradual-black"
                style="height:auto;color:#fff;cursor: pointer; "
              >
                <option :value="null" class="gray_dropdownBg">{{ $t('type') }}</option>
                <option
                  v-for="(item,i) in recordtype"
                  :key="i"
                  :value="item"
                  class="gray_dropdownBg"
                >
                  <p>{{ item.name }}</p>
                </option>
              </b-form-select>
              <div class="PicD" style="cursor: pointer;">
                <img src="/static/img/C711_PC/Path63.png" />
              </div>
            </div>
            <div class="ele-border bankPic" v-if="isEnvelope">
              <b-form-select
                v-model="redEnvelope_select"
                class="ele_border_none gradual-black"
                style="height:auto;color:#fff"
              >
                <option :value="null" class="gray_dropdownBg">{{ $t('type') }}</option>
                <option
                  v-for="(item,i) in redEnvelope_type"
                  :key="i"
                  :value="item"
                  class="gray_dropdownBg"
                >
                  <p>{{ item.name }}</p>
                </option>
              </b-form-select>
              <div class="PicD">
                <img src="/static/img/C711_PC/Path63.png" style="cursor: pointer;" />
              </div>
            </div>
            <div class="date_block">
              <vue-datepicker-local v-model="startDate" v-if="haveDate" />
              <p class="DateFont" v-if="haveDate">~</p>

              <vue-datepicker-local v-model="endDate" v-if="haveDate" />
              <div @click="search()" class="margin" style="cursor: pointer;">
                <img src="/static/img/C711_PC/user@example.invalid" />
              </div>
            </div>
          </div>
        </div>
        <div v-if="getRecordPoints.length!==0 || get_Prize_Record.length!==0" class="list">
          <!-- 平台轉入轉出 -->
          <table class="table table-striped" v-if="listType == 1">
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
          <table class="table table-striped" v-if="listType == 2">
            <thead>
              <tr>
                <th class="number">{{ $t("orderid") }}</th>
                <th class="date">{{ $t("date") }}</th>
                <!-- <th class="gamer">{{ $t("source") }}</th> -->
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
          <table class="table table-striped" v-if="listType == 3">
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
          <table class="table table-striped" v-if="listType == 4">
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

      <div v-if="selected != null && selected.type == 5">

        <div id="agentPage">
           <table class="agent-dataTable" >
							<tr>
								<th>{{$t("Common_Time")}}</th>
								<th>{{$t("Common_OrderNo")}}</th>
								<th class="text-r">{{$t("Common_Amount")}}</th>
								<th>{{$t("Common_WithdrawalType")}}</th>
								<th>{{$t("Common_Status")}}</th>
							</tr>

							<tr v-for="(item, index) in getAgentWithdrawalHistory" :key="index">
								<td>{{item.date}}</td>
								<td>{{item.orderNo}}</td>
								<td class="text-r">{{item.amount| ToMoneyDisplayFormat}}</td>
								<td>{{item.type}}</td>
								<td>{{item.status}}</td>
							</tr>
						</table>
        </div>
        
      </div>

      </div>
      <PopUp></PopUp>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import LobbyHeader from '@/components/C711_PC/Lobby_header'
import VueDatepickerLocal from 'vue-datepicker-local'
import PopUp from './Pop_up'
export default {
  components: {
    LobbyHeader,
    VueDatepickerLocal,
    PopUp
  },
  computed: {
    ...mapGetters(['getRecordPoints', 'get_Prize_Record' , 	"getAgentWithdrawalHistory"])
  },
  data() {
    return {
      startDate: '',
      endDate: new Date(),
      haveDate: false,
      recordtype: [
        { name: this.$t('platformTransfer'), type: 1 },
        { name: this.$t('customerServiceTransferRecord'), type: 2 },
        { name: this.$t('scratchCardTransferRecord'), type: 3 },
        { name: this.$t('redEnvelopeRecord'), type: 4 },
        { name: this.$t('Agent_AgentMenu'), type: 5 }
      ],
      selected: null,
      redEnvelope_type: [
        { name: this.$t('redEnvelope'), type: 1 },
        { name: this.$t('receivedRedEnvelope'), type: 2 },
        { name: this.$t('redEnvelopeRefund'), type: 3 }
      ],
      redEnvelope_select: null,
      isEnvelope: false,
      listType: null
    }
  },
  watch: {
    selected() {
      if (this.selected.type == 4) {
        this.isEnvelope = true
      } else {
        this.isEnvelope = false
      }
      if (this.selected.type == 1) {
        this.haveDate = true
      } else {
        this.haveDate = false
      }

      if(this.selected.type == 5)
      {
        this.loadAgentHistory();
      }
    },
    redEnvelope_select() {}
  },
  mounted() {},
  methods: {

    loadAgentHistory()
    {
      this.$store.dispatch('actionGetWithdrawalHistory');
    },

    defaultStart() {
      let dt = this.endDate
      this.startDate = new Date(dt.getTime() - 30 * 24 * 60 * 60 * 1000)
    },
    search() {
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

      if (this.selected.type == 1) {
        // 平台轉入轉出
        this.$store.dispatch('actionRecord_point', range)
      } else if (this.selected.type == 2) {
        // 客服轉點
        this.$store.dispatch('actionRecord_Customer')
      } else if (this.selected.type == 3) {
        // 活動
        this.$store.dispatch('action_PrizeRecords')
      } 
      else if(this.selected.type == 5)
      {
        this.loadAgentHistory();
      }      
      else {
        // 紅包
        if (this.redEnvelope_select.type == 1) {
          this.$store.dispatch('actionRecord_RedEnvelope', 1)
        } else if (this.redEnvelope_select.type == 2) {
          this.$store.dispatch('actionRecord_RedEnvelope', 2)
        } else if (this.redEnvelope_select.type == 3) {
          this.$store.dispatch('actionRecord_RedEnvelope', 3)
        }
      }
      this.listType = this.selected.type
    }
  },
  created() {
    this.defaultStart()
  }
}
</script>