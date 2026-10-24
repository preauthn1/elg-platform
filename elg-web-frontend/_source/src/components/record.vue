<template>
    <div class="record">
        <div class="record__wrap">
            <el-form  label-width="100px">
                
                <div class="record__title"><h2>交易紀錄</h2></div>
                <div class="form__wrap">
                    <div class="form__width">
                        <el-form-item label="歷史紀錄類型" >
                            <el-select v-model="orderType_id" placeholder="請選擇" size="mini">
                                <el-option
                                v-for="item in getOrdertypes"
                                :key="item.id"
                                :label="item.name"
                                :value="item.id">
                                </el-option>
                            </el-select>
                        </el-form-item>
                        <el-form-item>
                            <el-radio @change="actionRecordData" v-model="recordRange" label="24">最近24小時</el-radio>
                            <el-radio @change="actionRecordData" v-model="recordRange" label="48">最近48小時</el-radio>
                        </el-form-item>
                        <el-form-item >
                            <el-radio @change="actionRecordData" v-model="recordRange" label="0">範圍</el-radio>
                            </el-form-item>
                         <div>
                                <el-date-picker
                                 size="mini"
                                v-model="Range"
                                value-format="yyyy-MM-dd HH:mm:ss"
                                type="datetimerange"
                                :picker-options="pickerOptions"
                                range-separator="~"
                                start-placeholder="開始日期"
                                end-placeholder="結束日期"
                                align="right"
                                class="input-class">
                                </el-date-picker>
                                <el-button @click="Record">查詢</el-button>
                             </div>
                        <el-form>
                            
                        </el-form>
                        <div class="tableall">
                        <el-table :data="getRecordTable" border >
                            <el-table-column prop="created_at" label="時間" width="200"></el-table-column>
                            <el-table-column prop="order_type_id" label="類型" width="200"></el-table-column>
                            <el-table-column prop="amount" label="金額" width="200"></el-table-column>
                            <el-table-column prop="balance" label="餘額" width="199"></el-table-column>
                        </el-table>
                        </div>
                    </div>
                </div>
            </el-form>
        </div>
        <div class="bottom"></div>
    </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
export default {
     data() {
      return {
        pickerOptions: {
          shortcuts: [{
            text: '最近一周',
            onClick(picker) {
              const end = new Date();
              const start = new Date();
              start.setTime(start.getTime() - 3600 * 1000 * 24 * 7);
              picker.$emit('pick', [start, end]);
            }
          }, {
            text: '最近一个月',
            onClick(picker) {
              const end = new Date();
              const start = new Date();
              start.setTime(start.getTime() - 3600 * 1000 * 24 * 30);
              picker.$emit('pick', [start, end]);
            }
          }, {
            text: '最近三个月',
            onClick(picker) {
              const end = new Date();
              const start = new Date();
              start.setTime(start.getTime() - 3600 * 1000 * 24 * 90);
              picker.$emit('pick', [start, end]);
            }
          }]
        },
        value2: '',
      };
    },
    computed: {
        ...mapGetters({
            getOrdertypes: 'getOrdertypes',
            getRecordTable: 'getRecordTable',
        }),
        orderType_id: {
            get () {
                return this.$store.state.orderType_id
            },
            set (val) {
                this.$store.commit('orderType_id', val)
            }
        },
        recordRange: {
            get () {
                return this.$store.state.recordRange
            },
            set (val) {
                this.$store.commit('recordRange', val)
            }
        },
        Range: {
            get () {
                return this.$store.state.Range
            },
            set (val) {
                this.$store.commit('Range', val)
            }
        }
    },
    methods: {
        ...mapActions([
            'actionRecordData',
            'Record'
        ]),
    },
    mounted() {
    },
}
</script>

<style lang="scss" scoped>
.record {
    padding-top:140px;
    .record__wrap {
        width: 80%;
        margin: auto;
        background-color: #fff;
        font-size: 12px;
        padding-bottom:20px;
        .record__title {
            color: #fff;
            padding: 5px 0;
            h2 {
                background-color: #4d7992;
                padding: 8px 30px;
                font-size: 18px;
                transform: translateX(30px)
            }
            h3{
                
                
                font-size: 18px;
                color:#666;
                transform: translateX(30px)
            }
        }
        
        .record__subTitle {
            color: #fff;
            text-align: center;
            background-color: #4d7992;
            margin: 0 30px;
            
        }
        .form__wrap{
            
            margin: 0 30px;
           padding:20px;
            background-color: #eee;
            
        }
}
}
.tableall{
    width: 100%;
    margin-top:20px;
}
.block{
    color:#666;
    margin:8px;
 
}
@media only screen and(max-width:768px){
		.record {padding-top:150px;    
}
    }
@media only screen and(max-width:650px){
		.record {padding-top:140px;    
}
    }
.bottom{
    padding-bottom:121px;
}
.input-class{
  width: 20%;
 }
@media only screen and(max-width:650px){
		.input-class {width:100%;    
}
    }
</style>