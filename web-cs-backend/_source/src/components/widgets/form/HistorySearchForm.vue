<template>
  <v-card>
    <v-card-text>
      <v-form v-model="valid" ref="form" lazy-validation>
        <v-layout row wrap>
          <v-flex lg12 sm12>
            <v-text-field :label="$t('form.username')" name="title" v-model="search.username">
            </v-text-field>
          </v-flex>
          <v-flex lg12 sm12>
            <v-select
                :items="search.types"          
                v-model="search.type"
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
                v-model="search.startDateMenu"
                transition="scale-transition"
                offset-y
                full-width
                :nudge-bottom="-22"
                max-width="290px"
                :return-value.sync="search.startDate"
            >
                <v-text-field
                    slot="activator"
                    :label="$t('form.startDate')"
                    v-model="search.startDate"
                    append-icon="event"
                    readonly
                ></v-text-field>
                <v-date-picker v-model="search.startDate" no-title scrollable>
                    <v-spacer></v-spacer>
                    <v-btn flat color="primary" @click="search.startDateMenu = false">{{ $t('form.cancel') }}</v-btn>
                    <v-btn flat color="primary" @click="$refs.statDate.save(search.startDate)">{{ $t('form.confirm') }}</v-btn>
                </v-date-picker>
            </v-menu>
          </v-flex>
          <v-flex sm6 lg6>
            <v-menu
                ref="startTime"
                lazy
                :close-on-content-click="false"
                v-model="search.startTimeMenu"
                transition="scale-transition"
                offset-y
                full-width
                :nudge-bottom="-24"
                max-width="290px"
                :return-value.sync="search.startTime"
            >
                <v-text-field
                    slot="activator"
                    :label="$t('form.startTime')"
                    v-model="search.startTime"
                    append-icon="access_time"
                    readonly
                ></v-text-field>
                <v-time-picker v-model="search.startTime">
                    <v-spacer></v-spacer>
                    <v-btn flat color="primary" @click="search.startTimeMenu = false">{{ $t('form.cancel') }}</v-btn>
                    <v-btn flat color="primary" @click="$refs.startTime.save(search.startTime)">{{ $t('form.confirm') }}</v-btn>                
                </v-time-picker>
            </v-menu>
          </v-flex>   
          <v-flex sm6 lg6>
            <v-menu
                ref="endDate"
                lazy
                class="pr-2"
                :close-on-content-click="false"
                v-model="search.endDateMenu"
                transition="scale-transition"
                offset-y
                full-width
                :nudge-bottom="-22"
                min-width="290px"
                :return-value.sync="search.endDate"
            >
                <v-text-field
                    slot="activator"
                    :label="$t('form.endDate')"
                    v-model="search.endDate"
                    append-icon="event"
                    readonly
                ></v-text-field>
                <v-date-picker v-model="search.endDate" no-title scrollable>
                    <v-spacer></v-spacer>
                    <v-btn flat color="primary" @click="search.endDateMenu = false">{{ $t('form.cancel') }}</v-btn>
                    <v-btn flat color="primary" @click="$refs.endDate.save(search.endDate)">{{ $t('form.confirm') }}</v-btn>
                </v-date-picker>
            </v-menu>
          </v-flex>
          <v-flex sm6 lg6>
            <v-menu
                ref="endTime"
                lazy
                :close-on-content-click="false"
                v-model="search.endTimeMenu"
                transition="scale-transition"
                offset-y
                full-width
                :nudge-bottom="-24"
                max-width="290px"
                :return-value.sync="search.endTime"
            >
                <v-text-field
                    slot="activator"
                    :label="$t('form.endTime')"
                    v-model="search.endTime"
                    append-icon="access_time"
                    readonly
                ></v-text-field>
                <v-time-picker v-model="search.endTime" >
                    <v-spacer></v-spacer>
                    <v-btn flat color="primary" @click="search.endTimeMenu = false">{{ $t('form.cancel') }}</v-btn>
                    <v-btn flat color="primary" @click="$refs.endTime.save(search.endTime)">{{ $t('form.confirm') }}</v-btn>                
                </v-time-picker>
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
</template>

<script>
export default {
    data() {
        return {
            search: {
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
                    { name: this.$t('list.transaction'), type: 1 },
                    { name: this.$t('list.redEnvelope'), type: 2 },
                ],
                type: 1,
            },
            valid: true,
            // date: null,
            // modal: false,
        }
    },  
    methods: {
        searchHistory() {
            let start = this.search.startDate + ' ' + this.search.startTime + ':00'
            let end = this.search.endDate + ' ' + this.search.endTime + ':00'
            let data = {
                "username": this.search.username,
                "start_time": start,
                "end_time": end,
            }
            if(this.search.type == 1) {
                this.$store.dispatch('action_TransactionList',data)
            } else if (this.search.type == 2) {
                this.$store.dispatch('action_RedEnvelopeList',data)
            }
            
        },
        day_of_record() {
            // D-DAY
            let dt = new Date()
            let D_year = dt.getFullYear()
            let D_month = dt.getMonth() + 1
            D_month = D_month < 10 ? '0'+ D_month : D_month
            let D_date = dt.getDate()
            D_date = D_date < 10 ? '0'+ D_date : D_date
            let D_hour = dt.getHours()
            D_hour = D_hour < 10 ? '0'+ D_hour : D_hour
            let D_minutes = dt.getMinutes()
            D_minutes = D_minutes < 10 ? '0'+ D_minutes : D_minutes
            this.search.endDate = D_year +'-'+ D_month +'-'+ D_date  //endDate
            this.search.endTime = D_hour +':'+ D_minutes  //endTime
            // D-1
            let st = new Date(dt.getTime() - 7 * 24 * 60 * 60 * 1000)
            let S_year = st.getFullYear() 
            let S_month = st.getMonth() + 1
            S_month = S_month < 10 ? '0'+ S_month : S_month
            let S_date = st.getDate()
            S_date = S_date < 10 ? '0'+ S_date : S_date
            this.search.startDate = S_year +'-'+ S_month +'-'+ S_date  //startDate
            this.search.startTime = D_hour +':'+ D_minutes  //startTime (時分秒一樣)

            
            this.search.username = this.$route.params.username
            this.searchHistory()
        }
    },
    created() {
        this.day_of_record()
    }
};
</script>
