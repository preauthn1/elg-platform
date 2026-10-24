
<template>
<div class="all">
    <div class="signup" >
        <div class="signup__wrap">
            <el-form :model="ruleForm" :rules="rules" ref="ruleForm" label-width="100px">
                <div class="signup__title"><h2>登入資訊</h2></div>
                <div class="form__wrap">
                    
                    <div class="form__width ">
                        <el-form-item label="推薦碼" class="inputB">
                            <el-input v-model="ruleForm.code" size="mini" ></el-input>                            
                         </el-form-item>
                         <div class="inputS">
                            <el-input v-model="ruleForm.code" size="mini"   placeholder="推薦碼"></el-input>
                         </div>
                        <el-form-item label="用戶名稱" prop="name" class="inputB">
                            <el-input v-model="ruleForm.name" size="mini"></el-input>
                        </el-form-item>
                        <div class="inputS"><el-input v-model="ruleForm.name" size="mini" placeholder="用戶名稱"></el-input></div>
                        <el-form-item label="密碼" prop="pass" class="inputB">
                            <el-input type="password" minlength="6" v-model="ruleForm.pass" size="mini"></el-input>
                        </el-form-item>
                        <div class="inputS">
                             <el-input type="password" minlength="6" v-model="ruleForm.pass" size="mini" placeholder="密碼"></el-input>
                        </div>
                        <el-form-item label="確認密碼" prop="checkPass" class="inputB">
                            <el-input type="password" v-model="ruleForm.checkPass" size="mini"></el-input>
                        </el-form-item>
                        <div class="inputS">
                            <el-input type="password" v-model="ruleForm.checkPass" size="mini" placeholder="確認密碼"></el-input>
                        </div>
                        <el-form-item label="電子郵件"  class="inputB">
                            <el-input type="email" v-model="ruleForm.email" size="mini"></el-input>
                        </el-form-item>
                        <div class="inputS">
                            <el-input type="email" v-model="ruleForm.email" size="mini" placeholder="電子郵件"></el-input>
                        </div>
                        <el-form-item label="連絡電話"  class="inputB">
                            <el-input v-model="ruleForm.phone" size="mini" ></el-input>
                        </el-form-item>
                        <div class="inputS">
                            <el-input v-model="ruleForm.phone" size="mini" placeholder="聯絡電話"></el-input>
                        </div>
                    </div>
                   
                </div>
                
                <div class="signup__title"><h2>基本資料</h2></div>
                <div class="form__wrap">
                    <div class="form__width">
                        <el-form-item label="姓氏拼音" class="inputB">
                            <el-input v-model="ruleForm.lastName" size="mini"></el-input>
                        </el-form-item>
                        <div class="inputS">
                            <el-input v-model="ruleForm.lastName" size="mini" placeholder="姓氏拼音"></el-input>
                        </div>
                        <el-form-item label="名字拼音" class="inputB">
                            <el-input v-model="ruleForm.firstName" size="mini" ></el-input>
                        </el-form-item>
                        <div class="inputS">
                            <el-input v-model="ruleForm.firstName" size="mini" placeholder="名字拼音"></el-input>
                        </div>
                        <el-form-item label="中文全名" class="inputB">
                            <el-input v-model="ruleForm.chineseName" size="mini"></el-input>
                        </el-form-item>
                        <div class="inputS">
                            <el-input v-model="ruleForm.chineseName" size="mini" placeholder="中文全名"></el-input>
                        </div>
                        <el-form-item label="出生年月日" class="inputB">
                            <el-col :span="7">
                                <el-form-item >
                                    <el-select v-model="ruleForm.month" @change="adult()" placeholder="月" size="mini">
                                        <el-option value="">月</el-option>
                                        <el-option
                                        v-for="item in months"
                                        :key="item"
                                        :label="item"
                                        :value="item">
                                        </el-option>
                                    </el-select>
                                </el-form-item>
                            </el-col>                            
                            <el-col :span="7" :offset="1">
                                <el-form-item >
                                    <el-select v-model="ruleForm.day" @change="adult()" placeholder="日" size="mini">
                                        <el-option value="">日</el-option>
                                        <el-option
                                        v-for="item in days[ruleForm.month]"
                                        :key="item"
                                        :label="item"
                                        :value="item">
                                        </el-option>
                                    </el-select>
                                </el-form-item>
                            </el-col>
                            <el-col :span="7" :offset="1">
                                <el-form-item >
                                    <el-select v-model="ruleForm.year" @change="adult()" placeholder="年" size="mini">
                                        <el-option value="">年</el-option>
                                        <el-option
                                        v-for="item in years"
                                        :key="item"
                                        :label="item"
                                        :value="item">
                                        </el-option>
                                    </el-select>
                                </el-form-item>
                            </el-col>
                        </el-form-item>
                        <div class="inputS">
                            <el-row >
                                <el-col :span="8">                                    
                                        <el-select v-model="ruleForm.month" @change="adult()" placeholder="月" size="mini">
                                            <el-option value="">月</el-option>
                                            <el-option
                                            v-for="item in months"
                                            :key="item"
                                            :label="item"
                                            :value="item">
                                            </el-option>
                                        </el-select>                                    
                                </el-col>                            
                                <el-col :span="8">                                    
                                        <el-select v-model="ruleForm.day" @change="adult()" placeholder="日" size="mini">
                                            <el-option value="">日</el-option>
                                            <el-option
                                            v-for="item in days[ruleForm.month]"
                                            :key="item"
                                            :label="item"
                                            :value="item">
                                            </el-option>
                                        </el-select>                                    
                                </el-col>
                                <el-col :span="8">                                   
                                        <el-select v-model="ruleForm.year" @change="adult()" placeholder="年" size="mini">
                                            <el-option value="">年</el-option>
                                            <el-option
                                            v-for="item in years"
                                            :key="item"
                                            :label="item"
                                            :value="item">
                                            </el-option>
                                        </el-select>                                   
                                </el-col>
                            </el-row>
                        </div>
                         <el-form-item label="國家"  class="inputB" >
                            <el-select v-model="ruleForm.country" placeholder="請選擇" size="mini">
                                <el-option
                                v-for="item in getCountries"
                                :key="item.id"
                                :label="item.name"
                                :value="item.id">
                                </el-option>
                            </el-select>
                        </el-form-item>
                        <div class="inputS">
                            <el-select v-model="ruleForm.country" placeholder="國家" size="mini">
                                <el-option
                                v-for="item in getCountries"
                                :key="item.id"
                                :label="item.name"
                                :value="item.id">
                                </el-option>
                            </el-select>
                        </div>
                        <el-form-item label="城市"  class="inputB">
                            <el-input v-model="ruleForm.city" size="mini"></el-input>
                        </el-form-item>
                        <div class="inputS">
                            <el-input v-model="ruleForm.city" size="mini" placeholder="城市"></el-input>
                        </div>
                        <el-form-item label="區"  class="inputB">
                            <el-input v-model="ruleForm.district" size="mini"></el-input>
                        </el-form-item>
                        <div class="inputS">
                            <el-input v-model="ruleForm.district" size="mini" placeholder="區"></el-input>
                        </div>
                        <el-form-item label="地址"  class="inputB">
                            <el-input v-model="ruleForm.address" size="mini"></el-input>
                        </el-form-item>
                        <div class="inputS">
                            <el-input v-model="ruleForm.address" size="mini" placeholder="地址"></el-input>
                        </div>
                        <el-form-item label="郵政編號"  class="inputB">
                            <el-input v-model="ruleForm.postal" size="mini"></el-input>
                        </el-form-item>
                        <div class="inputS">
                            <el-input v-model="ruleForm.postal" size="mini" placeholder="郵政編號"></el-input>
                        </div>       
                    </div>
                </div>
                <div class="signup__title"><h2>其他</h2></div>
                <div class="form__wrap">
                    <div class="form__width">
                        <el-form-item label="時區" class="inputB">
                            <el-select v-model="ruleForm.GMT" placeholder="請選擇" size="mini">
                                <el-option
                                v-for="item in getTimezones"
                                :key="item.id"
                                :label="item.time_offset"
                                :value="item.id">
                                </el-option>
                            </el-select>
                        </el-form-item>
                        <div class="inputS">                            
                            <el-select v-model="ruleForm.GMT" placeholder="時區" size="mini">
                                <el-option
                                v-for="item in getTimezones"
                                :key="item.id"
                                :label="item.time_offset"
                                :value="item.id">
                                </el-option>
                            </el-select>                        
                        </div>
                    </div>
                </div>
                
                <div class="signup__subTitle"><h3>選擇如何收到優惠資訊</h3></div>
                <div class="form__wrap">
                    <div class="form__center">
                        <el-form-item label="推送通知" prop="push">
                            <el-radio-group v-model="ruleForm.push">
                                <el-radio :label=true>是</el-radio>
                                <el-radio :label=false>否</el-radio>
                            </el-radio-group>
                        </el-form-item>
                        <el-form-item label="短信通知" prop="SMS">
                            <el-radio-group v-model="ruleForm.SMS">
                                <el-radio :label=true>是</el-radio>
                                <el-radio :label=false>否</el-radio>
                            </el-radio-group>
                        </el-form-item>
                        <el-form-item label="電子郵件" prop="mail">
                            <el-radio-group v-model="ruleForm.mail">
                                <el-radio :label=true>是</el-radio>
                                <el-radio :label=false>否</el-radio>
                            </el-radio-group>
                        </el-form-item>
                        <el-form-item label="信息收件箱" prop="msgBox">
                            <el-radio-group v-model="ruleForm.msgBox">
                                <el-radio :label=true>是</el-radio>
                                <el-radio :label=false>否</el-radio>
                            </el-radio-group>
                        </el-form-item>
                        <el-form-item label="彈出信息" prop="msgPopup">
                            <el-radio-group v-model="ruleForm.msgPopup">
                                <el-radio :label=true>是</el-radio>
                                <el-radio :label=false>否</el-radio>
                            </el-radio-group>
                        </el-form-item>
                    </div>
                  </div>
                    <div class="signup__agree">
                        <el-form-item prop="agree">
                            <el-checkbox-group v-model="ruleForm.agree">
                                <el-checkbox label="我已年滿18歲，且已閱讀、並接受同意" name="type"></el-checkbox>
                            </el-checkbox-group>
                        </el-form-item>
                    </div>                
                    <div  class="button">
                        <el-button size="small" @click="submitForm('ruleForm')">提交</el-button>
                    </div>                
            </el-form>
        </div>
    </div>
</div>
</template>

<script>
const axios = require('axios');
const md5 = require('js-md5');
import secret from '../utils/secret'
import { mapGetters, mapActions } from 'vuex'
export default {
    data() {
        var validateName = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请输入帳號'))
            } else if (value.length < 4 || value.length > 10) {
                callback(new Error('請輸入4-10位字元'))
            } else {
                callback()
            }
        }
        var validatePass = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请输入密码'))
            } else if (value.length < 6 || value.length > 12) {
                callback(new Error('請輸入6-12位字元'))
            } else {
                if (this.ruleForm.checkPass !== '') {
                    this.$refs.ruleForm.validateField('checkPass')
                }
                callback()
            }
        }
        var validatePass2 = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请再次输入密码'))
            } else if (value !== this.ruleForm.pass) {
                callback(new Error('两次输入密码不一致!'))
            } else {
                callback()
            }
        }
        var validateLastname = (rule, value, callback) => {
            let re = /[a-zA-Z]/
            if (value === '') {
                callback(new Error('请输入姓氏拼音'))
            } else if (!re.test(value)) {
                callback(new Error('請輸入英文'))
            } else {
                callback()
            }
        }
        var validateFirstname = (rule, value, callback) => {
            let re = /[a-zA-Z]/
            if (value === '') {
                callback(new Error('请输入名子拼音'))
            } else if (!re.test(value)) {
                callback(new Error('請輸入英文'))
            } else {
                callback()
            }
        }
        var validateChineseName = (rule, value, callback) => {
            let re = /[\u4E00-\u9FA5]/
            if (value === '') {
                callback(new Error('请输入中文全名'))
            } else if (!re.test(value)) {
                callback(new Error('請輸入中文'))
            } else {
                callback()
            }
        }
        var validateMonth = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请输入月份'))
            } else {
                callback()
            }
        }
        var validateDay = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请输入日期'))
            } else {
                callback()
            }
        }
        var validateYear = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请输入年份'))
            } else {
                callback()
            }
        }
        var validateEmail = (rule, value, callback) => {
            let re = /^([A-Za-z0-9_\-\.])+\@([A-Za-z0-9_\-\.])+\.([A-Za-z]{2,4})$/
            if (value === '') {
                callback(new Error('请输入電子郵件'))
            } else if (!re.test(value)) {
                callback(new Error('EX: user@example.invalid'))
            } else {
                callback()
            }
        }
        var validatePhone = (rule, value, callback) => {
            let re = /[0-9\-]/
            if (value === '') {
                callback(new Error('请输入聯絡電話'))
            } else if (!re.test(value)) {
                callback(new Error('请输入0988-'))
            } else {
                callback()
            }
        }
        var validateCountry = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请输入國家'))
            } else {
                callback()
            }
        }
        var validateCity = (rule, value, callback) => {
            let re = /[a-zA-Z\u4E00-\u9FA5]/
            if (value === '') {
                callback(new Error('请输入鄉鎮/城市'))
            } else if (!re.test(value)) {
                callback(new Error('格式錯'))
            } else {
                callback()
            }
        }
        var validateDistrict = (rule, value, callback) => {
            let re = /[a-zA-Z\u4E00-\u9FA5]/
            if (value === '') {
                callback(new Error('请输入省/地區/郡'))
            } else if (!re.test(value)) {
                callback(new Error('格式錯'))
            } else {
                callback()
            }
        }
        var validatePostal = (rule, value, callback) => {
            let re = /[0-9]/
            if (value === '') {
                callback(new Error('请输入郵政編號'))
            } else if (!re.test(value)) {
                callback(new Error('格式錯'))
            } else {
                callback()
            }
        }
        var validateGMT = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请输入時區'))
            } else {
                callback()
            }
        }
        var validatePush = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请選取是否接收推播通知'))
            } else {
                callback()
            }
        }
        var validateSMS = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请選取是否接收短信通知'))
            } else {
                callback()
            }
        }
        var validateMail = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请選取是否接收電子郵件'))
            } else {
                callback()
            }
        }
        var validateAgree = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请閱讀、並同意'))
            } else {
                callback()
            }
        }
        return {
            years: [],
            months: [
                '01','02','03','04','05','06','07','08','09','10','11','12'
            ],
            days: {
                '01': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31'
                ],
                '02': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29'
                ],
                '03': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31'
                ],
                '04': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30'
                ],
                '05': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31'
                ],
                '06': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30'
                ],
                '07': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31'
                ],
                '08': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31'
                ],
                '09': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30'
                ],
                '10': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31'
                ],
                '11': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30'
                ],
                '12': [
                    '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31'
                ]
            },
            ruleForm: {
                code: '',
                name: '',
                pass: '',
                checkPass: '',
                lastName: '',
                firstName: '',
                chineseName: '',
                year: '',
                month: '',
                day: '',
                birthday: '',
                email: '',
                phone: '',
                country: '',
                city: '',
                district: '',
                address: '',
                postal: '',
                GMT: '',
                limitTime: '0',
                push: true,
                SMS: true,
                mail: true,
                msgBox: true,
                msgPopup: true,
                agree: ''
            },
            rules: {
                name: [
                    { required: true, validator: validateName, trigger: 'blur' },
                    { min: 4, max: 10, trigger: 'blur' }
                ],
                pass: [
                    { required: true, validator: validatePass, trigger: 'blur' },
                    { min: 6, max:12, trigger: 'blur' }
                ],
                checkPass: [
                    { required: true, validator: validatePass2, trigger: 'blur' }
                ],
                // lastName: [
                //     { validator: validateLastname, trigger: 'blur' }
                // ],
                // firstName: [
                //     { validator: validateFirstname, trigger: 'blur' }
                // ],
                // chineseName: [
                //     { validator: validateChineseName, trigger: 'blur' }
                // ],
                // year: [
                //     { validator: validateYear, trigger: 'change' }
                // ],
                // month: [
                //     { validator: validateMonth, trigger: 'change' }
                // ],
                // day: [
                //     { validator: validateDay, trigger: 'change' }
                // ],
                
                // email: [
                //     { validator: validateEmail, trigger: 'blur' },
                //     { max:60, trigger: 'blur' }
                // ],
                // phone: [
                //     { validator: validatePhone, trigger: 'blur' },
                //     { max:20, trigger: 'blur' }
                // ],
                // country: [
                //     { validator: validateCountry, trigger: 'change' }
                // ],
                // city: [
                //     { validator: validateCity, trigger: 'blur' }
                // ],
                // district: [
                //     { validator: validateDistrict, trigger: 'blur' }
                // ],
                // postal: [
                //     { validator: validatePostal, trigger: 'blur' },
                //     { max: 10, trigger: 'blur' }
                // ],
                // GMT: [
                //     { validator: validateGMT, trigger: 'change' }
                // ],
                push: [
                    { validator: validatePush, trigger: 'change' }
                ],
                SMS: [
                    { validator: validateSMS, trigger: 'change' }
                ],
                mail: [
                    { validator: validateMail, trigger: 'change' }
                ],
                agree: [
                    { validator: validateAgree, trigger: 'change' }
                ],
            },
            formJson: '',
            encryptMsg: '',
            md5_key: '&zSMvVTK4gbDvCcU',
            key: '',
            
        }
    },
    computed: {
        ...mapGetters({
            getTimezones: 'getTimezones',
            getCountries: 'getCountries',
        }),
    },
    methods: {
        ...mapActions([
            
        ]),
        submitForm(formName) {
            this.$refs[formName].validate((valid) => {
                if (valid) {
                    if( this.ruleForm.year == '' || this.ruleForm.month == '' || this.ruleForm.day == ''){
                        this.ruleForm.birthday = ''
                    } else {
                        this.ruleForm.birthday = this.ruleForm.year +'-'+ this.ruleForm.month +'-'+ this.ruleForm.day
                    }
                    console.log('birthday',this.ruleForm.birthday)
                    this.formJson = JSON.stringify({
                        "code": this.ruleForm.code, "username": this.ruleForm.name, "password": this.ruleForm.pass, "password_confirmation": this.ruleForm.checkPass, "first_name": this.ruleForm.firstName, "last_name": this.ruleForm.lastName, "email": this.ruleForm.email, "birthday": this.ruleForm.birthday, "mobile": this.ruleForm.phone, "timezone_id": this.ruleForm.GMT, "country_id": this.ruleForm.country, "zip_code": this.ruleForm.postal, "district": this.ruleForm.district, "city": this.ruleForm.city, "address": this.ruleForm.address
                    })
                    this.encryptMsg = secret.Encrypt(this.formJson)
                    this.key = md5(this.encryptMsg + this.md5_key)
                    let vm = this
                    axios.post('https://example.invalid/api/create',{
                        params: vm.encryptMsg,
                        key: vm.key
                    },{
                        headers: { 'Accept-Language': vm.$i18n.locale ,'Authorization': 'Bearer' }
                    })
                    .then(function (response) {
                        sessionStorage.setItem('mobile',vm.ruleForm.mobile)
                        sessionStorage.setItem('email',vm.ruleForm.email)
                        let loginJson =  JSON.stringify({ "username": vm.ruleForm.name, "password": vm.ruleForm.pass })
                        let loginEncrypt = secret.Encrypt(loginJson)
                        let loginKey = md5(loginEncrypt + vm.md5_key)
                        axios.post('https://example.invalid/api/login',{
                            params: loginEncrypt,
                            key: loginKey
                        },{
                            headers: {}
                        })
                        .then(function (response) {
                            sessionStorage.setItem('username',response.data.data.username)
                            sessionStorage.setItem('first_name',response.data.data.first_name)
                            sessionStorage.setItem('last_name',response.data.data.last_name)
                            sessionStorage.setItem('balance',response.data.data.balance)
                            sessionStorage.setItem('last_login',response.data.data.last_login)
                            sessionStorage.setItem('token',response.data.data.token)
                            let token = [REDACTED]
                            let settingJson = JSON.stringify({
                                "activity_limit_time": vm.ruleForm.limitTime, "push_notify": vm.ruleForm.push, "sms_notify": vm.ruleForm.SMS, "email_notify": vm.ruleForm.mail, "message_box_notify": vm.ruleForm.msgBox, "message_popup_notify": vm.ruleForm.msgPopup,
                            })
                            let settingEncrypt = secret.Encrypt( settingJson )
                            let settingKey = md5( settingEncrypt + vm.md5_key ) 
                            console.log('token',token)
                            axios.post('https://example.invalid/api/createSetting',{
                                params: settingEncrypt,
                                key: settingKey
                            },{
                                headers: { 'Accept-Language': vm.$i18n.locale ,'Authorization': 'Bearer ' + token}
                            })
                            .then(function (res) {
                                console.log('createsetting', res)
                                window.location.assign('http://localhost:8080/')
                                })
                            .catch(function (error) {
                                console.log('error',error);
                                alert('通知設定失敗，請稍後再設定')
                                window.location.assign('http://localhost:8080/')
                            })
                        })
                        .catch(function (error) {
                            console.log('login',error);
                        })
                    })
                    .catch(function (error) {
                        console.log(error);
                    })

                } else {
                    console.log('error submit!!');
                    return false;
                }
            })
        },
        yearsAry() {
            let today = new Date()
            let nowYear = today.getFullYear()
            for(let i = nowYear; i >= nowYear - 80; i--){
                this.years.push(i)
            }
        },
        adult() {
            if( this.ruleForm.year !== '' && this.ruleForm.month !== '' && this.ruleForm.day ){
                let today = new Date()
                let nowYear = today.getFullYear()
                let nowMonth = today.getMonth() + 1
                let nowDay = today.getDate()
                if( nowYear - this.ruleForm.year > 18 ){
                    console.log('成年')
                } else if( nowYear - this.ruleForm.year == 18 && nowMonth - this.ruleForm.month <= 0 && nowDay - this.ruleForm.day >= 0){
                    console.log('成年')
                } else if( nowYear - this.ruleForm.year == 18 && nowMonth - this.ruleForm.month > 0){
                    console.log('成年')
                } else {
                    console.log('未滿18歲')
                }
            }
        },
        paramsCode() {
            this.ruleForm.code = this.$route.params.code
            console.log('code',this.ruleForm.code)
        }
    },
    created() {
        this.yearsAry()
        this.paramsCode()
    },
    mounted() {
        this.$store.dispatch('actionTimezones')
        this.$store.dispatch('actionCountries')
    }
}
</script>
<style lang="scss" scoped>
.all{
    width: 100%;
    height:auto;
    padding-top:140px;

}
@media only screen and(max-width:768px){
		.all{padding-top:78px;    
}
    }
@media only screen and(max-width:650px){
		.all{padding-top:130px;    
}
	}
.signup {
    margin: auto;
    margin-bottom:20px;
    .signup__wrap {
        width: 80%;
        margin: auto;
        background-color: #fff;
        font-size: 12px;
        .signup__title {
            color: #fff;
            padding: 5px 0;
            h2 {
                background-color: #4d7992;
                padding: 8px 30px;
                font-size: 18px;
                transform: translateX(30px)
            }
        }
        
        .signup__subTitle {
            color: #fff;
            text-align: center;
            background-color: #4d7992;
            margin: 0 30px;
            h3 {
                padding: 5px 0;
            }
        }
        .form__wrap{
            
            margin: 0 30px;
            padding:0 80px;
            background-color: #eee;
            text-align:center;
        }
         @media only screen and(max-width:650px){
		   .form__wrap{padding:0 10px;}
        } 
        .form__width {
            width: 60%;
        }

        @media only screen and(max-width:768px){
		.form__width {
            width: 90%;
        }   
        }
        @media only screen and(max-width:650px){
		   .form__width {
            width: 100%;
            text-align:left;
            
        } 
        } 
        .inputB{
            display:block;
            text-align:left;
        }
        @media only screen and(max-width:650px){
		   .inputB {
            display:none;
        } 
        }
        .inputS{
            display:none;
        }
        @media only screen and(max-width:650px){
		   .inputS {
            display:block;
            padding:10px 0px;
        } 
        }
        .form__center {
            width: 230px;
            margin: auto;
            
        }
        .signup__agree {
            text-align: center;
            color:#666;
            /deep/ .el-form-item__content { 
                margin: 0 !important; 
            }
            /deep/ .el-form-item__error {
                position: inherit;
            }
        }
        .button{
            border:none;
            text-align:center;
            width:100%;
            margin-bottom:50px;
          }
    }
}

</style>
