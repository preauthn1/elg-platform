<template>
  <div id="page-forms">
    <v-container grid-list-xl fluid>
      <v-layout row wrap>
        <v-flex lg6>
          <v-card>
            <v-card-text>
              <v-form v-model="valid" ref="form" lazy-validation>
                <v-layout row wrap>
                  <v-flex lg12 sm12>
                    <v-text-field
                      :label="$t('form.username')"
                      name="title"
                      v-model="search_form.username"
                    >
                    </v-text-field>
                  </v-flex>
                  <v-flex lg12 sm12>
                    <v-select
                      :items="search_form.types"
                      v-model="search_form.type"
                      :label="$t('list.type')"
                      item-text="name"
                      item-value="type"
                    ></v-select>
                  </v-flex>
                  <v-flex sm6 lg6>
                    <v-menu
                      class="pr-2"
                      ref="statDate"
                      lazy
                      :close-on-content-click="false"
                      v-model="search_form.startDateMenu"
                      transition="scale-transition"
                      offset-y
                      full-width
                      :nudge-bottom="-22"
                      max-width="290px"
                      :return-value.sync="search_form.startDate"
                    >
                      <v-text-field
                        slot="activator"
                        :label="$t('form.startDate')"
                        v-model="search_form.startDate"
                        append-icon="event"
                        readonly
                      ></v-text-field>
                      <v-date-picker
                        v-model="search_form.startDate"
                        no-title
                        scrollable
                      >
                        <v-spacer></v-spacer>
                        <v-btn
                          flat
                          color="primary"
                          @click="search_form.startDateMenu = false"
                          >{{ $t('form.cancel') }}</v-btn
                        >
                        <v-btn
                          flat
                          color="primary"
                          @click="$refs.statDate.save(search_form.startDate)"
                          >{{ $t('form.confirm') }}</v-btn
                        >
                      </v-date-picker>
                    </v-menu>
                  </v-flex>
                  <v-flex sm6 lg6>
                    <v-menu
                      ref="startTime"
                      lazy
                      :close-on-content-click="false"
                      v-model="search_form.startTimeMenu"
                      transition="scale-transition"
                      offset-y
                      full-width
                      :nudge-bottom="-24"
                      max-width="290px"
                      :return-value.sync="search_form.startTime"
                    >
                      <v-text-field
                        slot="activator"
                        :label="$t('form.startTime')"
                        v-model="search_form.startTime"
                        append-icon="access_time"
                        readonly
                      ></v-text-field>
                      <v-time-picker v-model="search_form.startTime">
                        <v-spacer></v-spacer>
                        <v-btn
                          flat
                          color="primary"
                          @click="search_form.startTimeMenu = false"
                          >{{ $t('form.cancel') }}</v-btn
                        >
                        <v-btn
                          flat
                          color="primary"
                          @click="$refs.startTime.save(search_form.startTime)"
                          >{{ $t('form.confirm') }}</v-btn
                        >
                      </v-time-picker>
                    </v-menu>
                  </v-flex>
                  <v-flex sm6 lg6>
                    <v-menu
                      ref="endDate"
                      lazy
                      class="pr-2"
                      :close-on-content-click="false"
                      v-model="search_form.endDateMenu"
                      transition="scale-transition"
                      offset-y
                      full-width
                      :nudge-bottom="-22"
                      min-width="290px"
                      :return-value.sync="search_form.endDate"
                    >
                      <v-text-field
                        slot="activator"
                        :label="$t('form.endDate')"
                        v-model="search_form.endDate"
                        append-icon="event"
                        readonly
                      ></v-text-field>
                      <v-date-picker
                        v-model="search_form.endDate"
                        no-title
                        scrollable
                      >
                        <v-spacer></v-spacer>
                        <v-btn
                          flat
                          color="primary"
                          @click="search_form.endDateMenu = false"
                          >{{ $t('form.cancel') }}</v-btn
                        >
                        <v-btn
                          flat
                          color="primary"
                          @click="$refs.endDate.save(search_form.endDate)"
                          >{{ $t('form.confirm') }}</v-btn
                        >
                      </v-date-picker>
                    </v-menu>
                  </v-flex>
                  <v-flex sm6 lg6>
                    <v-menu
                      ref="endTime"
                      lazy
                      :close-on-content-click="false"
                      v-model="search_form.endTimeMenu"
                      transition="scale-transition"
                      offset-y
                      full-width
                      :nudge-bottom="-24"
                      max-width="290px"
                      :return-value.sync="search_form.endTime"
                    >
                      <v-text-field
                        slot="activator"
                        :label="$t('form.endTime')"
                        v-model="search_form.endTime"
                        append-icon="access_time"
                        readonly
                      ></v-text-field>
                      <v-time-picker v-model="search_form.endTime">
                        <v-spacer></v-spacer>
                        <v-btn
                          flat
                          color="primary"
                          @click="search_form.endTimeMenu = false"
                          >{{ $t('form.cancel') }}</v-btn
                        >
                        <v-btn
                          flat
                          color="primary"
                          @click="$refs.endTime.save(search_form.endTime)"
                          >{{ $t('form.confirm') }}</v-btn
                        >
                      </v-time-picker>
                    </v-menu>
                  </v-flex>
                  <v-spacer></v-spacer>
                </v-layout>
              </v-form>
            </v-card-text>
            <v-card-actions class="pb-3">
              <v-spacer></v-spacer>
              <v-btn color="primary" @click="searchHistory()">{{
                $t('form.search')
              }}</v-btn>
            </v-card-actions>
          </v-card>
        </v-flex>
      </v-layout>
      <!-- 開洗分紀錄 -->
      <v-layout v-if="list_type" row wrap>
        <v-flex sm12>
          <h3>{{ $t('list.transaction_list') }}</h3>
        </v-flex>
        <v-flex lg12>
          <v-card>
            <v-toolbar card color="white">
              <v-text-field
                flat
                solo
                prepend-icon="search"
                placeholder="Type something"
                v-model="search"
                hide-details
                class="hidden-sm-and-down"
              ></v-text-field>
              <v-btn icon>
                <v-icon>filter_list</v-icon>
              </v-btn>
            </v-toolbar>
            <v-divider></v-divider>
            <v-card-text class="pa-0">
              <v-data-table
                :headers="complex.headers"
                :search="search"
                :items="get_transaction_List"
                :rows-per-page-items="[10, 25, 50, {text: 'All', value: -1}]"
                class="elevation-1"
                item-key="name"
                v-model="complex.selected"
              >
                <template slot="items" slot-scope="props">
                  <td>{{ props.item.created_at }}</td>
                  <td>{{ props.item.id }}</td>
                  <td>
                    <span v-if="props.item.type == 1">{{
                      $t('list.transfer_in')
                    }}</span>
                    <span v-if="props.item.type == 2">{{
                      $t('list.transfer_out')
                    }}</span>
                    <span v-if="props.item.type == 3" style="color:#f44336">{{
                      $t('list.transfer_gift')
                    }}</span>
                  </td>
                  <td>{{ props.item.member_username }}</td>
                  <td>{{ props.item.member_nickname }}</td>
                  <td>{{ props.item.amount }}</td>
                  <td>
                    <span v-if="props.item.status == 0">{{
                      $t('list.failed')
                    }}</span>
                    <span v-if="props.item.status == 1">{{
                      $t('list.success')
                    }}</span>
                    <span v-if="props.item.status == 2">{{
                      $t('list.rejected')
                    }}</span>
                    <span v-if="props.item.status == 3">{{
                      $t('list.expired')
                    }}</span>
                    <span v-if="props.item.status == 4">{{
                      $t('list.pending')
                    }}</span>
                  </td>
                  <td>{{ props.item.user_username }}</td>
                  <td>{{ props.item.remark }}</td>
                </template>
              </v-data-table>
            </v-card-text>
          </v-card>
        </v-flex>
      </v-layout>
      <!-- 紅包紀錄 -->
      <v-layout v-if="!list_type" row wrap>
        <v-flex sm12>
          <h3>{{ this.$t('list.redEnvelope_list') }}</h3>
        </v-flex>
        <v-flex lg12>
          <v-card>
            <v-toolbar card color="white">
              <v-text-field
                flat
                solo
                prepend-icon="search"
                placeholder="Type something"
                v-model="search1"
                hide-details
                class="hidden-sm-and-down"
              ></v-text-field>
              <v-btn icon>
                <v-icon>filter_list</v-icon>
              </v-btn>
            </v-toolbar>
            <v-divider></v-divider>
            <v-card-text class="pa-0">
              <v-data-table
                :headers="complex1.headers"
                :search="search1"
                :items="get_redEnvelope_List"
                :rows-per-page-items="[10, 25, 50, {text: 'All', value: -1}]"
                class="elevation-1"
                item-key="name"
                v-model="complex1.selected"
              >
                <template slot="items" slot-scope="props">
                  <td>{{ props.item.created_at }}</td>
                  <td>
                    <span v-if="props.item.type == 1">{{
                      $t('list.send_redEnvelope')
                    }}</span>
                    <span v-if="props.item.type == 2">{{
                      $t('list.receive_redEnvelope')
                    }}</span>
                  </td>
                  <td>{{ props.item.self_username }}</td>
                  <td>{{ props.item.other_username }}</td>
                  <td>{{ props.item.amount }}</td>
                  <!-- <td>
                      <span v-if="props.item.status == 1">{{ $t('list.received')}}</span>
                      <span v-if="props.item.status == 2">{{ $t('list.not_received')}}</span>
                  </td> -->
                </template>
              </v-data-table>
            </v-card-text>
          </v-card>
        </v-flex>
      </v-layout>
    </v-container>
  </div>
</template>

<script>
import {page} from 'vue-analytics'
import {mapGetters} from 'vuex'
import {Items as Users} from '@/api/user'
import VWidget from '@/components/VWidget'
import HistorySearchForm from '@/components/widgets/form/HistorySearchForm'
export default {
  components: {
    VWidget,
    HistorySearchForm,
  },
  computed: {
    ...mapGetters(['get_transaction_List', 'get_redEnvelope_List']),
  },
  data() {
    return {
      list_type: true, // 列表類別顯示true為開洗分 false為紅包
      search: '',
      complex: {
        // 開洗分列表TITLE
        selected: [],
        headers: [
          {
            text: this.$t('list.dateTime'),
            value: 'created_at',
          },
          {
            text: this.$t('list.transfer_id'),
            value: 'id',
          },
          {
            text: this.$t('list.type'),
            value: 'type',
          },
          {
            text: this.$t('list.username'),
            value: 'member_username',
          },
          {
            text: this.$t('list.nickname'),
            value: 'member_nickname',
          },
          {
            text: this.$t('list.amount/point'),
            value: 'amount',
          },
          {
            text: this.$t('list.status'),
            value: 'status',
          },
          {
            text: this.$t('list.operator'),
            value: 'user_username',
          },
          {
            text: this.$t('list.remark'),
            value: 'remark',
          },
          // {
          //   text: 'Action',
          //   value: ''
          // },
        ],
        // items: Users
      },
      search1: '',
      complex1: {
        //   紅包列表TITLE
        selected: [],
        headers: [
          {
            text: this.$t('list.dateTime'),
            value: 'created_at',
          },
          {
            text: this.$t('list.type'),
            value: 'type',
          },
          {
            text: this.$t('list.username'),
            value: 'self_username',
          },
          {
            text: this.$t('list.other_username'),
            value: 'other_username',
          },
          {
            text: this.$t('list.amount/point'),
            value: 'amount',
          },
          // {
          //     text: this.$t('list.status'),
          //     value: 'status'
          // },
        ],
      },
      search_form: {
        username: '',
        startDateMenu: false,
        startDate: null,
        startTimeMenu: false,
        startTime: null,
        endDateMenu: false,
        endDate: null,
        endTimeMenu: false,
        endTime: null,
        types: [
          // { name: 'All', abbr: 'FL', id: 1 },
          {name: this.$t('list.transaction'), type: 1},
          {name: this.$t('list.redEnvelope'), type: 2},
        ],
        type: 1,
      },
      valid: true,
    }
  },
  methods: {
    searchHistory() {
      let start =
        this.search_form.startDate + ' ' + this.search_form.startTime + ':00'
      let end =
        this.search_form.endDate + ' ' + this.search_form.endTime + ':00'
      let data = {
        username: this.search_form.username,
        start_time: start,
        end_time: end,
      }
      if (this.search_form.type == 1) {
        this.$store.dispatch('action_TransactionList', data)
        this.list_type = true
      } else if (this.search_form.type == 2) {
        this.$store.dispatch('action_RedEnvelopeList', data)
        this.list_type = false
      }
    },
    // 查詢初始時間
    day_of_record() {
      // D-DAY
      let dt = new Date()
      let D_year = dt.getFullYear()
      let D_month = dt.getMonth() + 1
      D_month = D_month < 10 ? '0' + D_month : D_month
      let D_date = dt.getDate()
      D_date = D_date < 10 ? '0' + D_date : D_date
      let D_hour = dt.getHours()
      D_hour = D_hour < 10 ? '0' + D_hour : D_hour
      let D_minutes = dt.getMinutes()
      D_minutes = D_minutes < 10 ? '0' + D_minutes : D_minutes
      this.search_form.endDate = D_year + '-' + D_month + '-' + D_date //endDate
      this.search_form.endTime = D_hour + ':' + D_minutes //endTime
      // D-1
      let st = new Date(dt.getTime() - 1 * 24 * 60 * 60 * 1000)
      let S_year = st.getFullYear()
      let S_month = st.getMonth() + 1
      S_month = S_month < 10 ? '0' + S_month : S_month
      let S_date = st.getDate()
      S_date = S_date < 10 ? '0' + S_date : S_date
      this.search_form.startDate = S_year + '-' + S_month + '-' + S_date //startDate
      this.search_form.startTime = D_hour + ':' + D_minutes //startTime (時分秒一樣)

      this.search_form.username = this.$route.params.username
      this.searchHistory()
    },
  },
  created() {
    this.day_of_record()
  },
}
</script>
