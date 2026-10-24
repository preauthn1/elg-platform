<template>
    <div class="acct">
        <div class="acct__wrap">
            <el-form  label-width="100px">
                <div class="acct__title"><h2>聯繫資料</h2></div>
                <div class="form__wrap">
                    <div class="form__width">
                        <el-form-item label="連絡電話" >
                            <el-input v-model="mobile_update" size="mini"></el-input>
                        </el-form-item>
                        <el-form-item label="電子郵件" >
                            <el-input v-model="email_update" minlength="6" size="mini"></el-input>
                        </el-form-item>        
                    </div>                                     
                </div>
                <div class="acct__title"><h2>居住地址</h2></div>
                <div class="form__wrap">
                    <div class="form__width">
                        <el-form-item label="城市" >
                            <el-input v-model="city_update" size="mini"></el-input>
                        </el-form-item>
                        <el-form-item label="區" >
                            <el-input v-model="district_update" size="mini"></el-input>
                        </el-form-item>
                        <el-form-item label="地址" >
                            <el-input v-model="address_update" size="mini"></el-input>
                        </el-form-item>
                        <el-form-item label="郵政編號" >
                            <el-input v-model="zipCode_update" size="mini"></el-input>
                        </el-form-item>      
                    </div>
                </div>
                <div class="acct__title"><h2>身分驗證</h2></div>
                <div class="form__wrap">
                    <div class="form__width">                        
                        <el-form-item label="驗證狀態" >
                            <span v-if="$store.state.acctInfo.status == 0">未驗證完成<i class="el-icon-circle-close"></i></span>
                            <el-button @click="validate()" type="primary">開始驗證</el-button>
                        </el-form-item>
                    </div>
                </div>
                
                <div class="acct__title"><h2>其他</h2></div>
                <div class="form__wrap">
                    <div class="form__width">
                        <el-form-item label="時區">
                            <el-select v-model="timezone_update" size="mini">
                                <el-option
                                v-for="item in getTimezones"
                                :key="item.id"
                                :label="item.time_offset"
                                :value="item.id">
                                </el-option>
                            </el-select>
                        </el-form-item>
        
                        <el-form-item label="語言">
                            <el-select v-model="value" size="mini">
                                <el-option v-for="(lang,i) in getLanguages" :key="`Lang${i}`" :label="lang.name" :value="lang.code">
                                </el-option>
                            </el-select>
                        </el-form-item>

                        <el-form-item label="最常非活動狀態時間">
                            <el-select v-model="limitTime" size="mini">
                                <el-option value="A">年</el-option>
                                
                            </el-select>
                        </el-form-item>
                    </div>
                </div>
                
                <div class="acct__subTitle"><h3>選擇如何收到優惠資訊</h3></div>
                <div class="form__wrap">
                    <div class="form__center">
                        <el-form-item label="推送通知">
                            <el-radio-group v-model="push_notify">
                                <el-radio :label='1'>是</el-radio>
                                <el-radio :label='0'>否</el-radio>
                            </el-radio-group>
                        </el-form-item>
                        <el-form-item label="短信通知">
                            <el-radio-group v-model="sms_notify">
                                <el-radio :label='1'>是</el-radio>
                                <el-radio :label='0'>否</el-radio>
                            </el-radio-group>
                        </el-form-item>
                        <el-form-item label="電子郵件">
                            <el-radio-group v-model="email_notify">
                                <el-radio :label='1'>是</el-radio>
                                <el-radio :label='0'>否</el-radio>
                            </el-radio-group>
                        </el-form-item>
                        <el-form-item label="信息收件箱">
                            <el-radio-group v-model="box_notify">
                                <el-radio :label='1'>是</el-radio>
                                <el-radio :label='0'>否</el-radio>
                            </el-radio-group>
                        </el-form-item>
                        <el-form-item label="彈出信息">
                            <el-radio-group v-model="popup_notify">
                                <el-radio :label='1'>是</el-radio>
                                <el-radio :label='0'>否</el-radio>
                            </el-radio-group>
                        </el-form-item>
                    </div>
                </div>
                <div class="acctbutton">
                    <el-button @click="updateMember"  size="small">更新</el-button>
                    <el-button @click="$router.push('/')" size="small">取消</el-button>                    
                </div>
            </el-form>
        </div>
        
    </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
export default {
    data(){
        return {
            value:""
        }
    },
    computed: {
        ...mapGetters({
            getTimezones: 'getTimezones',
            getLanguages: 'getLanguages',
            getStatus: 'getStatus',
        }),
        mobile_update: {
            get () { return this.$store.state.acctInfo.mobile },
            set (val) { this.$store.commit('updateMobile',val) }
        },
        email_update: {
            get () { return this.$store.state.acctInfo.email },
            set (val) { this.$store.commit('updateEmail',val) }
        },
        city_update: {
            get () { return this.$store.state.acctInfo.city },
            set (val) { this.$store.commit('updateCity',val) }
        },
        district_update: {
            get () { return this.$store.state.acctInfo.district },
            set (val) { this.$store.commit('updateDistrict',val) }
        },
        address_update: {
            get () { return this.$store.state.acctInfo.address },
            set (val) { this.$store.commit('updateAddress',val) }
        },
        zipCode_update: {
            get () { return this.$store.state.acctInfo.zip_code },
            set (val) { this.$store.commit('updateZipcode',val) }
        },
        timezone_update: {
            get () { return this.$store.state.acctInfo.timezone_id },
            set (val) { this.$store.commit('updateTimezone',val) }
        },
        limitTime: {
            get () { return this.$store.state.acctInfo.limitTime },
            set (val) { this.$store.commit('updateLimitTime',val) }
        },
        email_notify: {
            get () { return this.$store.state.acctInfo.email_notify },
            set (val) { this.$store.commit('update_Email_notify',val) }
        },
        box_notify: {
            get () { return this.$store.state.acctInfo.box_notify },
            set (val) { this.$store.commit('update_Box_notify',val) }
        },
        popup_notify: {
            get () { return this.$store.state.acctInfo.popup_notify },
            set (val) { this.$store.commit('update_Popup_notify',val) }
        },
        push_notify: {
            get () { return this.$store.state.acctInfo.push_notify },
            set (val) { this.$store.commit('update_Push_notify',val) }
        },
        sms_notify: {
            get () { return this.$store.state.acctInfo.sms_notify },
            set (val) { this.$store.commit('update_Sms_notify',val) }
        },
    },
    methods: {
        ...mapActions([
            'updateMember',
        ]),
        validate() {
            this.$router.push('/validate')
        },
    },
    created() {
        this.$store.dispatch('actionTimezones')
        this.$store.dispatch('getMember', {isValidate:true} )
    },
    
}
</script>

<style lang="scss" scoped>
.acct {
   padding-top:140px;
    .acct__wrap {
        width: 80%;
        margin: auto;
        margin-bottom:20px;
        background-color: #fff;
        font-size: 12px;
        .acct__title {
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
        .acct__subTitle {
            color: #fff;
            text-align: center;
            background-color: #4d7992;
            margin: 0 30px;
            
        }
        .form__wrap{
            margin: 30px;
            background-color: #eee;
            text-align:center;
        }
         @media only screen and(max-width:375px){
		   .form__wrap{padding:10px 10px 0 10px;}
        } 
        .form__width {
            width: 60%;
            color:#666;
        }
        h4{
            font-size:15px;
        }
        @media only screen and(max-width:768px){
		.form__width {
            width: 90%;
        }   
 }
        @media only screen and(max-width:375px){
		   .form__width {
            width: 100%;
            } 
        } 
        .form__center {
            width: 250px;
            margin: auto;
        }
    }
    .acctbutton{
        padding-bottom:30px;
        text-align:center;     
    }
    .grid-content {
        color:#666;
        border-radius: 4px;
        min-height: 100px;
        min-width: 70px;
    }
    .contentdata{
        text-align:left;
    }
    .el-row-two {
        margin-top: 20px;  
    } 
//.acct {
//    width: 100%;
// //   .acct__wrap {
 //       width: 800px;
 //       margin: auto;
 //       background-color: #fff;
 //       font-size: 12px;
 //   }
 //   .el-icon-circle-close {
 //     color: red;
 //   }
}
</style>

