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
                        <v-text-field :label=" $t('report.agent_name')" name="title" v-model="search_form.username">
                        </v-text-field>
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
                            <v-date-picker v-model="search_form.startDate" no-title scrollable>
                                <v-spacer></v-spacer>
                                <v-btn flat color="primary" @click="search_form.startDateMenu = false">{{ $t('form.cancel') }}</v-btn>
                                <v-btn flat color="primary" @click="$refs.statDate.save(search_form.startDate)">{{ $t('form.confirm') }}</v-btn>
                            </v-date-picker>
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
                            <v-date-picker v-model="search_form.endDate" no-title scrollable>
                                <v-spacer></v-spacer>
                                <v-btn flat color="primary" @click="search_form.endDateMenu = false">{{ $t('form.cancel') }}</v-btn>
                                <v-btn flat color="primary" @click="$refs.endDate.save(search_form.endDate)">{{ $t('form.confirm') }}</v-btn>
                            </v-date-picker>
                        </v-menu>
                    </v-flex>           
                    <v-spacer></v-spacer>
                  </v-layout>        
                </v-form>
            </v-card-text>
            <v-card-actions class="pb-3">
                <v-spacer></v-spacer>
                <v-btn color="primary" @click="searchHistory()">{{ $t('form.search') }}</v-btn>
            </v-card-actions>
          </v-card>
        </v-flex>
      </v-layout>
      <v-layout row wrap >
        <v-flex sm12>
          <h3 >{{ $t('report.agent_total_amount') }}</h3>
        </v-flex>        
        <v-flex lg12>
          <v-card>
            <v-card-text class="pa-0">
              <table class="total"> 
                  <thead>
                      <tr>
                          <th>{{ $t('report.total_in') }}</th>
                          <th>{{ $t('report.total_out') }}</th>
                          <th>{{ $t('report.total_amount') }}</th>
                      </tr>
                  </thead>
                  <tbody>
                      <tr v-for="(item,i) in get_sr02Report.total" :key="i">
                          <td>{{item.total_in? item.total_in : 0}}</td>
                          <td>{{item.total_out? item.total_out : 0}}</td>
                          <td>{{item.total_in - item.total_out? item.total_in - item.total_out : 0}}</td>
                      </tr>
                  </tbody>
              </table>
            </v-card-text>
          </v-card>
        </v-flex>      
      </v-layout>
<!-- sr02報表紀錄 -->
      <v-layout row wrap >
        <v-flex sm12>
          <h3 >{{ $t('report.transaction_list') }}</h3>
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
                :items="get_sr02Report.data"
                :rows-per-page-items="[10,25,50,{text:'All','value':-1}]"
                class="elevation-1"
                item-key="name"
                v-model="complex.selected"
                >
                <template slot="items" slot-scope="props">
                  <td>{{ props.item.username }}</td>
                  <td>{{ props.item.total_in }}</td>
                  <td>{{ props.item.total_out }}</td>
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
import { page } from 'vue-analytics'
import { mapGetters } from 'vuex'
import { Items as Users } from '@/api/user';
import VWidget from '@/components/VWidget';
import HistorySearchForm from '@/components/widgets/form/HistorySearchForm';
export default {
    components: {
        VWidget,
        HistorySearchForm
    },
    computed: {
        ...mapGetters(['get_sr02Report']),
    }, 
    data () {
        return {
            search: '',
            complex: {
                selected: [],
                headers: [
                {
                    text: this.$t('report.agent_name'),
                    value: 'username'
                },
                {
                    text: this.$t('report.total_in'),
                    value: 'total_in'
                },     
                {
                    text: this.$t('report.total_out'),
                    value: 'total_out'
                },     
                
                // {
                //   text: 'Action',
                //   value: ''
                // },
                ],
                // items: Users
            },
            search_form: {
                username: '',
                startDateMenu: false,
                startDate: null,
                endDateMenu: false,
                endDate: null,
                types: [
                    // { name: 'All', abbr: 'FL', id: 1 },
                    { name: this.$t('list.transaction'), type: 1 },
                    { name: this.$t('list.redEnvelope'), type: 2 },
                ],
            },
            valid: true,
        };
    }, 
    methods: {
        searchHistory() { //搜尋紀錄
            let start = this.search_form.startDate
            let end = this.search_form.endDate
            let data = {
                "username": this.search_form.username,
                "start_time": start,
                "end_time": end,
            }
            console.log('data',data)
            this.$store.dispatch('action_report_SR02',data)
        },
        day_of_record() { //預設時段
            // D-DAY
            let dt = new Date()
            let D_year = dt.getFullYear()
            let D_month = dt.getMonth() + 1
            D_month = D_month < 10 ? '0'+ D_month : D_month
            let D_date = dt.getDate()
            D_date = D_date < 10 ? '0'+ D_date : D_date
            // let D_hour = dt.getHours()
            // D_hour = D_hour < 10 ? '0'+ D_hour : D_hour
            // let D_minutes = dt.getMinutes()
            // D_minutes = D_minutes < 10 ? '0'+ D_minutes : D_minutes
            this.search_form.endDate = D_year +'-'+ D_month +'-'+ D_date  //endDate
            // this.search_form.endTime = D_hour +':'+ D_minutes  //endTime
            // D-1
            let st = new Date(dt.getTime() - 1 * 24 * 60 * 60 * 1000)
            let S_year = st.getFullYear() 
            let S_month = st.getMonth() + 1
            S_month = S_month < 10 ? '0'+ S_month : S_month
            let S_date = st.getDate()
            S_date = S_date < 10 ? '0'+ S_date : S_date
            this.search_form.startDate = S_year +'-'+ S_month +'-'+ S_date  //startDate
            // this.search_form.startTime = D_hour +':'+ D_minutes  //startTime (時分秒一樣)

            this.searchHistory()
        }
    },
    created() {
        this.day_of_record()
    }
};
</script>
<style lang="css">
.total {
    padding: 20px;
    width: 100%;
    text-align: center;
}
.total th, .total td{
    width: 33%;
}
.total th {
    padding-bottom: 5px;
}
</style>