<template>
	<div class="header_all" >
		<div class="header ">
			<BlockUI v-if="blockUI">	
				<img src="../assets/Rolling.gif" >
			</BlockUI>			
			<el-row class="header__color fixed-width " >
				<el-col :span="4" class="logoimg"><router-link to="/"><img src="../assets/logo1.png"></router-link></el-col>
				<el-col :span="19" >					
					<div class="grid-content">
						<el-row  type="flex" justify="end"  style="padding-top:14px;">
							<el-col :span="24" class="flexEnd " >
								<div  class="headerBtn" >
									<div v-if="ifSignIn === false" class="signed">
										<el-col class="singButton" >
											<el-button  @click="signinBtn()" type="primary" size="mini" round class="signIn" >{{ $t("signIn") }}</el-button>												
												<el-dialog  :visible.sync="signinFormVisible" :show-close="false" :center="true"  class="singinDialogB"  :modal-append-to-body='false' >
													<el-form :model="signinForm"  :rules="rules" ref="ruleForm" :label-position="labelPosition" label-width="135px" >
														<!--PC登入-->
														<div class="singinB">
															<el-form-item :label="$t('AccountName')" prop="username" >
															<el-input v-model="signinForm.username" autosize ></el-input>
															</el-form-item>
															<el-form-item :label="$t('AccountPassword')" prop="password">
																<el-input type="password" v-model="signinForm.password" autosize></el-input>
															</el-form-item>
															<el-form-item :label="$t('identify')" prop="identify">
																<el-input v-model="signinForm.identify" placeholder="請輸入您的驗證碼" ></el-input>
															</el-form-item>
														</div>
														<!--手機登入-->
														<div class="singinS">
															<el-form-item :label="$t('AccountName')" prop="username"  ></el-form-item>
															<el-input v-model="signinForm.username" autosize ></el-input>
															<el-form-item :label="$t('AccountPassword')" prop="password"></el-form-item>
															<el-input type="password" v-model="signinForm.password" autosize></el-input>
															<el-form-item :label="$t('identify')" prop="identify" ></el-form-item>
															<el-input v-model="signinForm.identify" placeholder="請輸入您的驗證碼" ></el-input>
														</div>
														<div @click="refreshCode()"  >
															<Sidentify :identifyCode="identifyCode"></Sidentify>														
														</div>
													</el-form>
													<div slot="footer" class="dialog-footer" >
														<el-button type="info"   @click="login('ruleForm')">{{ $t("signIn") }}</el-button>
														<el-button  type="info"  >忘記密碼</el-button>
													</div>
												</el-dialog>												
											<router-link to="/signup"   >
											<el-button   @click="signup()" type="primary" size="mini" round class="signI=Up" >{{ $t("signUp") }}</el-button>
											</router-link>
										</el-col>
										</div>
									<div class="signed"  v-if="ifSignIn === true">										
											<el-col class="signedL">
												
												<span class="name">hi {{ username }}</span>											
												<dropdown>
													<i @click="acctBtn()" class="el-icon-setting"></i>													
													<div slot="content">
														<menus>
															<menu-item ><router-link to="/acct">會員資料</router-link></menu-item>
															<menu-item ><router-link to="/record">交易紀錄</router-link></menu-item>
															<menu-item ><router-link to="/points">轉點</router-link></menu-item>
															<menu-item ><a @click="addPointBtn()">儲值</a></menu-item>
															<div class="divider"></div>
															<menu-item ><a @click="logout()">登出</a></menu-item>
														</menus>
													</div>
												</dropdown>																						
												<el-dialog :visible.sync="addPointVisible" class="addacc" :show-close="false" :center="true" :modal-append-to-body='false'>
													<el-form class="payacc">
														儲值
														<el-form-item label="支付方式" >
															<el-select v-model="orderType_id" placeholder="請選擇" size="mini">
																<el-option
																v-for="item in getOrdertypes"
																:key="item.id"
																:label="item.name"
																:value="item.id">
																</el-option>
															</el-select>
														</el-form-item>
														<el-form-item label="金額(TWD)">
															<el-input v-model="addPoint" size="mini"></el-input>
														</el-form-item>
														<el-form style="text-align:center;">
															<el-button @click="actionAddPoint" size="small">儲值</el-button>
															<el-button @click="addPointVisible = false" size="small">取消</el-button>
														</el-form>
													</el-form>
												</el-dialog>
											</el-col>											
											<el-col  class="signedR" >
													<div class="balance" ><p>目前餘額<span>{{ $store.state.balance }}</span></p></div>
													<div  class="balanceS" ><p>目前餘額<span>{{ $store.state.balance }}</span></p></div>	
											</el-col>																														
									</div>
									<el-col class="langBtn" >
											 <el-col class="langL">
													<el-select v-model="lang" class="language" popper-class="select__bgc" size="mini" style="text-align:left;;margin:2px;">
														<el-option v-for="(lang,i) in getLanguages" :key="`Lang${i}`" :label="lang.name" :value="lang.code"></el-option>
													</el-select>
												</el-col>
												<el-col class="langR">													
													<el-input v-model="input" class="search" size="mini" suffix-icon="el-icon-search" style=""></el-input>	
													<div class="menuBtnS" >											
														<span class="icon">
															<i class="el-icon-menu" @click="menuBtnS()" style="margin:6px;"></i>
														</span>
														<el-dialog :visible.sync="menuBtn" width="60%"  :show-close="false" :modal-append-to-body='false'>
																<div class="columns" style=" text-align:left; background-color: #f0f0f0;">
																<div class="column is-3">
																	<div class="menu menuBtn" >
																		<menus v-for="(item) in menuType" :key="item.id">
																			<menu-item v-if="item.submenu" >
																				<span>{{ $t(item.name) }}</span>
																				<menus slot="sub">
																					<menu-item icon="money" v-for="(itemSub) in item.submenu" :key="itemSub.id">
																						<p @click="actionTypeidS(item.id)">{{ $t(itemSub.name) }} A</p>
																					</menu-item>
																				</menus>
																			</menu-item>
																			<menu-item v-else >
																				<p @click="actionTypeidS(item.id)">{{ $t(item.name) }}</p>
																			</menu-item>
																			<div class="divider"></div>
																		</menus>
																	</div>
																</div>
																</div>													
															</el-dialog>					
													</div>
												</el-col>									
									</el-col>						
								</div>	
							</el-col>
						</el-row>
						<div class="menublock" >
							<el-row type="flex" justify="" class="navbar_menu"  >
								<el-col :span="24">
									<el-row type="flex" justify="end">
										<div v-for="(item) in menuType" :key="item.id" >
											<el-dropdown size="mini" v-if="item.submenu" placement="bottom" popper-class="select__bgc" @command="actionTypeid">
												<el-button type="primary" size="medium"  class="elbuttonS">
													<span class="el-dropdown-link">{{ $t(item.name) }}</span><i class="el-icon-arrow-down el-icon--right"></i>
												</el-button>
												<el-dropdown-menu slot="dropdown">
													<el-dropdown-item v-for="(itemSub) in item.submenu" :key="itemSub.id" :command="itemSub.id">{{ $t(itemSub.name) }} A</el-dropdown-item>
												</el-dropdown-menu>
											</el-dropdown>
											<div v-else class="navMenu">
												<el-button type="primary" size="medium" class="el-dropdown-link elbuttonS" @click="actionTypeid(item.id)">{{ $t(item.name) }}</el-button>
											</div>
										</div>
									</el-row>
								</el-col>
							</el-row>							
						</div>						
					</div>					
				</el-col>				
			</el-row>
		</div>	
	</div>		
</template>  

<script>

import { mapGetters, mapActions } from 'vuex'
const axios = require('axios');
const md5 = require('js-md5');
import secret from '../utils/secret'
import Sidentify from '@/components/identify'

export default {
	name: "app",
	computed: {
        ...mapGetters({
		menuType: 'getMenu',
		getLanguages: 'getLanguages',
		getOrdertypes: 'getOrdertypes',
		
		}),
		blockUI: {
			get () {
                return this.$store.state.blockUI
			},
			set (val) {
				this.$store.commit('blockUI',val)
			}
		},
		lang: {
            get () {
                return this.$store.state.lang
            },
            set (val) {
                this.$store.commit('Lang',val)
            }
        },
		orderType_id: {
            get () {
                return this.$store.state.orderType_id
            },
            set (val) {
                this.$store.commit('orderType_id', val)
            }
		},
		addPoint: {
			get () {
                return this.$store.state.addPoint
            },
            set (val) {
                this.$store.commit('addPoint', val)
            }
		}
	},
	
	data() {
		var username = (rule, value, callback) => {
			if (value === '') {
            	callback(new Error('请输入帳號'));
			} else {
            	callback();
        }
		};
		var password = [REDACTED], value, callback) => {
			if (value === '') {
				callback(new Error('请输入密码'));
			} else {
				callback();
			}
		};
		var identify = (rule, value, callback) => {
			let val = value.toUpperCase()
			if (val === '') {
				callback(new Error('请输入驗證碼'));
			} else if (val !== this.identifyCode) {
				callback(new Error('驗證碼錯誤!'));
			} else {
				callback();
			}
		};

		return {
			activeName: 'first',
///////效果//////
			Loading: 'Loading...',
			labelPosition: 'right',
			signinFormVisible: false,
			forgetVisible: false,
			AcctVisible: false,
			ifSignIn: false,
			addPointVisible: false,
			menuBtn:false,
			input: '',
		////////
			menuData: [],
            options: [{
                    value: '1',
                    label: 'default1'
                },{
                    Value: '2',
                    label: 'default2'}
                ],
            defaultValue: 'Default',
            signinForm: {
                username: '',
				password: '',
				identify: ''
			},
			forgetForm: {
				email: '',
				year: '',
				month: '',
				date :'',
			},
			rules: {
				username: [
                	{ validator: username, trigger: 'blur' }
				],
				password: [
					{ validator: password, trigger: 'blur' }
				],
				identify: [
					{ validator: identify, trigger: 'blur' }
				],
			},
//////驗證碼
			identifyCode: '',
			identifyCodes: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
/////sessiondata
			username: '',
			balance: '',
//////解密erypto
			formJson: '',
            encryptMsg: '',
			decryptMsg: '',	
			key: '',
			md5_key: '&zSMvVTK4gbDvCcU',
			token: ''
		};
	},
	components:{
		Sidentify
	},
    methods: {
		handleClick(tab, event) {
        console.log(tab, event);
      },
		...mapActions([
			'actionTypeid',
			'actionAddPoint',
        ]),
////////驗證碼////////////
		randomNum(min, max) {
			return Math.floor(Math.random() * (max - min) + min)
		},
		refreshCode() {
			this.identifyCode = ''
			this.makeCode(this.identifyCodes, 4)
			console.log('驗證碼',this.identifyCode)
		},
		makeCode(o,l) {
			for (let i = 0; i < l; i++) {
				this.identifyCode += this.identifyCodes[
					this.randomNum(0, this.identifyCodes.length)
				]
			}
		},

		loading() {
			let vm = this
			axios.interceptors.request.use(
				function(config) {
					vm.blockUI = true;
					return config;
				},
				function(error) {
					vm.blockUI = false;
					return Promise.reject(error);
				}
			)
			axios.interceptors.response.use(
				function(response) {
					setTimeout(function() {
						vm.blockUI = false;
					}, 500);
					return response;
				},
				function(error) {
					vm.blockUI = false;
					return Promise.reject(error);
				}
			)
		},
		signinBtn() {
			this.signinFormVisible = true
			this.identifyCode = ''
			this.makeCode(this.identifyCodes, 4)
		},
		acctBtn() {
			this.AcctVisible = true
		},
		acct() {
			this.$router.push('/acct')
			this.AcctVisible = false
			console.log('acct')
		},
		record() {
			this.$router.push('/record')
			this.AcctVisible = false
		},
		events() {
			this.$router.push('/events')
			this.AcctVisible = false
		},
		points(){
			this.$router.push('/points')
			this.AcctVisible = false
		},
		forget() {
			this.signinFormVisible = false
			this.forgetVisible = true
		},
		logout() {
			sessionStorage.clear()
			this.AcctVisible = false
			this.$store.dispatch('actionLogout')
		},
		addPointBtn() {
			this.AcctVisible = false
			this.addPointVisible = true
		},
		menuBtnS() {
			this.AcctVisible = false
			this.menuBtn = true
		},
		actionTypeidS(id) {
			this.menuBtn = false
			this.$store.dispatch('actionTypeid', id)
		},
////////登入///////////////////
        login(formName) {
			
			this.formJson = JSON.stringify({ "username": this.signinForm.username, "password": this.signinForm.password })
            this.encryptMsg = secret.Encrypt(this.formJson)
			this.key = md5(this.encryptMsg + this.md5_key)
			let vm = this
			this.$refs[formName].validate((valid) => {
				if (valid) {
					this.blockUI = true
					// alert('submit!');
					axios.post('https://example.invalid/api/login', {
						params: vm.encryptMsg,
						key: vm.key
					},{
						headers: { 'Authorization': "Bearer " }
					})
					.then(function (response) {
						
						sessionStorage.setItem('username',response.data.data.username)
						sessionStorage.setItem('first_name',response.data.data.first_name)
						sessionStorage.setItem('last_name',response.data.data.last_name)
						sessionStorage.setItem('balance',response.data.data.balance)
						sessionStorage.setItem('last_login',response.data.data.last_login)
						sessionStorage.setItem('token',response.data.data.token)
					
						vm.signinFormVisible = false
						console.log(sessionStorage.getItem('username'));
						console.log(sessionStorage.getItem('last_login'));
						console.log(sessionStorage.getItem('token'));
						window.location.assign('/')
						// window.location.assign('http://localhost:8080/')
						vm.blockUI = false
						
					})
					.catch(function (error) {
						console.log(error);
					})
				} else {
						console.log('error submit!!');
						return false;
				}
			});
		},
		getPassword() {
			function append_zero(obj){
				if( obj<10 && obj.length<2) return '0' +''+ obj
				else return obj
			}
			let birthday = this.forgetForm.year +'-'+ append_zero(this.forgetForm.month) +'-'+ append_zero(this.forgetForm.date)
			console.log('0.0', append_zero(this.forgetForm.month))
			console.log('0.0', append_zero(this.forgetForm.date))
			let formJson = JSON.stringify({ 
				"email": this.forgetForm.email, "birthday": birthday 
			})
            let params = secret.Encrypt(formJson)
			let key = md5( params + this.md5_key)
			axios.post('https://example.invalid/api/forgetPassword',{
				params: params,
				key: key
			})
			.then(res => {
				this.forgetVisible = false
				console.log('forget',res)
				alert('請至電子信箱開啟密碼重設連結')
			})
			.catch(error => {
				console.log('forget',error)
			})
		},
////////////換語系////////////////////
		// changeLang() {
		// 	console.log("$18n",this.$i18n.locale)
		// 	localStorage.setItem('lang', this.$i18n.locale)
		// 	console.log("local",localStorage.getItem("lang"))
		// 	let vm = this
		// 	axios.post('https://example.invalid/api/gameTypes',{
		// 		params: secret.Encrypt(''),
        //         key: md5( secret.Encrypt('') + this.md5_key)
		// 	},{
		// 		headers: { 'Accept-Language': vm.$i18n.locale }
        //     })
		// 	.then(function (response) {
		// 		vm.menuData = response.data
		// 	})
		// 	.catch(function (error) {
		// 		console.log(error);
		// 	})
		// },
		// lang() {
		// 	let sys = (navigator.language || navigator.browserLanguage).toLowerCase()
		// 	console.log("browser",sys)
		// 	var lang
		// 	if ( sys == "en" || sys == "en-us" || sys == "en-gb" || sys == "en-au" || sys == "nz"){
		// 		lang = "en"
		// 	} else if (sys == "zh"|| sys == "zh-tw"|| sys == "zh-TW"|| sys == "zh-sg"|| sys == "zh-cn"|| sys == "zh-hk" ){
		// 		lang = "zh-tw"
		// 	}
		// 	if (localStorage.getItem("lang") === ""){
		// 		localStorage.setItem("lang",lang)
		// 		this.$i18n.locale = localStorage.getItem("lang")
		// 		console.log("if no lang")
		// 	} else {
		// 		this.$i18n.locale = localStorage.getItem("lang")
		// 	}
			
		// 	console.log("getlocal",localStorage.getItem("lang"))
		// 	console.log("$18n",this.$i18n.locale)
		// },
		// showMenu() {
		// 	let vm = this
		// 	axios.post('https://example.invalid/api/gameTypes',{
		// 		params: secret.Encrypt(''),
        //         key: md5( secret.Encrypt('') + this.md5_key)
		// 	},{
		// 		headers: { 'Accept-Language': vm.$i18n.locale }
        //     })
		// 	.then(function (response) {
		// 		vm.menuData = response.data
		// 	})
		// 	.catch(function (error) {
		// 		console.log(error)
		// 	})
		// },
		// getGames(command) {
		// 	console.log("id",command)
		// },
		loging() {
			this.token = [REDACTED]'token')
			if(this.token) {
				this.ifSignIn = true
				this.username = sessionStorage.getItem('username')
				this.balance = sessionStorage.getItem('balance')
			} else {
				this.ifSignIn = false
			}
		}
	},
	
	created() {
		this.loging()
		this.loading()
	},
	mounted() {
		this.$store.dispatch('browserLang')
		this.$store.dispatch('actionLanguages')
		this.$store.dispatch('actionMenu')
		this.$store.dispatch('actionOrderTypes')
		
	}
	
};

</script>



<style lang="scss" scoped>

.header_all{
	width:100%;	
}

.header {
	background-color: #2c495a ;
	color: #fff;
	width: 80%;
	text-align: center;
	margin:0 auto;
	position: fixed;
	left:10%;
	z-index: 999;
.logoimg{
	height:108px;
	padding-top:28px;
	
}
.headerBtn{
	width:70%;
}
@media screen and (max-width: 650px) {
	.headerBtn{width:55%;}	
	
}
@media screen and (max-width: 650px) {
	.logoimg{
		padding-left:25px;
	}
}
.menuBtnS{
	display:none;
	/deep/ .el-input__inner {
		background-color: #304658;
		border:0;
	}
}
@media screen and (max-width: 650px){
	.menuBtnS{
		display:block;
	.diglogMenu{
		font-size:15px;
	}
		}	
}
.menuBtn{
	padding-left:20px;
}
.flexEnd {
		display: flex;
		justify-content: flex-end;
		margin-top: 10px;
		
		
	}
	/deep/ .el-dialog {
		z-index: 8;
		background-color:#304658;
		border-radius:5%;
		box-shadow:#888 3px 3px 3px;
		.el-dialog__body {
			/deep/ .el-form-item__label {
				color: #fff;
				font-size:15px;
			}
			canvas {
				display: block;
				background-size: cover;
				margin: auto;
			}
		}
		
		.el-dialog__footer {
			.el-button {
				margin: 2 auto;
				border: 1px solid #fff;
				color: #fff;
			}
		}
	}
	.singinDialogB{
		width:70%;
		margin:auto;
		text-align: left;		
	}
@media only screen and (max-width: 650px) {
    .singinDialogB{		
		width:140%;
		border:none;
		font-size:0px;	
	}
	/deep/ .el-dialog {
		z-index: 8;
		background-color:#304658;
		border-radius:5%;
		box-shadow:#888 3px 3px 3px;
		margin-left:60px;
		}
}
.singinB{
	display:block;
}
@media screen and (max-width: 650px) {
	.singinB{
		display:none;
	}	
}
.singinS{
	display:none;
}
@media screen and (max-width: 650px) {
	.singinS{
		display:block;
		margin-bottom:15px;		
	.el-form-item{
		height:10px;			
		}
	/deep/ .el-form-item__label {
		float:none;
	}
	}
}
.singButton{	
	text-align:right;	
}
@media screen and (max-width: 650px) {
	.singButton{
		width:100%;
	}	
}
.langBtn{
	text-align:center; 
	width:60%;
	
}
@media screen and (max-width: 650px) {
	.langBtn{
		margin:2px 0;	
		width:100%;		
	}	
}
.langL{
 	 width:10%;
	 text-align:right;
	 padding-left:15px;	  
}
.langR{
	width:90%;
	text-align:right;
}
.dialogS{
	width:26%;
}
@media only screen and (max-width: 650px) {
    .dialogS{
		width:400px;
	}
}

.payacc{
	padding:10px; 
	font-size:18px; 
	font-weight:bold;
}
.elbuttonS{
	background-color:#2c495a;
	border:none;
}

.el-tabs h2{
	font-size:20px;
	text-align:center;
	border-bottom:2px solid #666;
}
.accountItem{
	color:#000; 
	width:80%; 
	margin:0 auto;
}
.accountButton{
	text-align:center;
}

	.signed {
		font-size: 14px;
		text-align:left;		
		width:40%;
		
		& > div {
			display: inline-block;
		}
		.name {
			line-height: 28px;
			padding: 7px;
		}
		a {
			cursor: pointer;
		}
		.balance {
			position: relative;
			
			p {
				height: 28px;
				line-height: 28px;
				background-color: #304658;
				margin: 0;
				padding: 0 0 0 10px;
				font-size: 12px;
				color: rgba(255, 255, 255, .5);
				span {
					margin: 15px;
					color: rgb(253, 255, 111);
				}
			}
		}
		@media screen and(max-width: 650px) {
		.balance{
			display:none;
		}
	}
	@media screen and (max-width: 650px) {
		width:100%;
	}

	.signedL{
		width:40%;
	}
	@media screen and (max-width: 650px) {
		.signedL{
		width:100%;
		margin-left:20px;
	}		
	}
	.signedR{
		width:50%;
		padding-left:2px;
	}
	@media screen and (max-width: 650px) {
		.signedR{
		width:100%;
	}		
	}
	.balanceS {
		display:none;
	}

	@media screen and(max-width: 650px) {
			.balanceS {
			font-size:12px; 
			text-align:left; 
			display:block;
			margin-left:13px;
			p {
				height: 28px;
				line-height: 28px;
				background-color: #304658;
				margin: 0;
				padding: 0 0 0 10px;
				font-size: 12px;
				color: rgba(255, 255, 255, .5);
				span {
					margin: 15px;
					color: rgb(253, 255, 111);
				}
			}
		}			
		}
	.cascaderS{
		display:none;
		/deep/ .el-input__inner {
			background-color: #304658;
			border:0;
		}
	}
	@media screen and (max-width: 650px){
	.cascaderS{
		display:block;
		margin:2px;}	
	}
		.el-scrollbar {
			background-color: #304658;
		}
	}
	.language {
		/deep/ .el-input__inner {
		background-color: #355669;
		color: #fff;
		border: none;
		border-radius: 0;
		width: 95px;
		margin: 0 10px;
		
		}
		/deep/ .el-input__suffix {
		right: 10px;
		.el-icon-arrow-up::before {
			content: "\e78f";
		}
		}
	}
	.search {
		max-width: 80%;
		padding-top:3px;		
		/deep/ .el-input__inner {
		background-color: #151c21;
		border: none;
		border-radius: 0;
		display:block;
		color:#fff;
		}	
	}
	@media screen and(max-width: 650px) {
		.search{
			display:none;
		}		
	}
	.addacc{
		margin:auto;
		width:50%;
	}
	@media screen and (max-width: 650px) {
		.addacc{
			width:140%;
		}
		
	}
	.el-meun{
		border:0px;
	}

	.el-dropdown {
		color: #fff;
		margin: 15px 60px 15px 0;
		vertical-align: top;
		&:hover {
		color: #091114;
		}
		 .el-dropdown + .el-dropdown {
    margin-left: 15px;
  }
  .el-icon-arrow-down {
    font-size: 12px;
  }
		.el-dropdown-link {
		cursor: pointer;
		}
	}
	.navbar_menu{
		background-color: #2c495a;
	}
	.menublock
	{
		display:block;
	}

	@media only screen and(max-width:1024px){
		
		.menublock{width:100%; left:0px; }
	}
	@media only screen and(max-width:768px){
		.menublock{display:none;}
	}
	.navMenu {
		margin: 15px 40px 0 0;
		font-size: 14px;
		
		span {
			cursor: pointer;
			&:hover {
				color: #45c2fd;
			}
		}
	}
}
@media screen and (min-width: 1024px) {
	el-row{
		flex-wrap:wrap;
	}

}
@media screen and (max-width: 650px) {
	.header{
		width:100%;
		left:0px;
	}

}
@media screen and (max-width:1280px){
	.header{
		width:85%;
		left:96px;
	}
	
}
@media screen and (max-width:1024px){
	.header{
		width:100%;		
		left:0px;
	}
	
}
.el-dropdown-menu {
	background-color: #355669;
	border: 0;
	border-radius: 0;
	padding: 0;
	.el-dropdown-menu__item {
		color: #fff;
		box-shadow: 0 -1px 2px #888 inset;
		line-height: 40px;
		&:focus {
		color: #45c2fd;
		background-color: #355669;
		}
		&:not(.is-disabled):hover {
		color: #45c2fd;
		background-color: #355669;
		}
	}
}
.el-select-dropdown__list {
	color: #fff;
	background-color: #355669;
	padding: 0;
	.el-select-dropdown__item {
		color: #ccc;
		&.hover {
			color: #45c2fd;
			background-color: #355669;
		}
		&:hover {
			color: #45c2fd;
			background-color: #355669;
		}
	}
}
</style>
<style lang="scss">
	.el-popper[x-placement^="bottom"] .popper__arrow {
		border-bottom-color: #355669;
	}
	.el-popper[x-placement^="bottom"] .popper__arrow:after {
		border-bottom-color: #355669;
	}
	.select__bgc {
		background: #355669;
		border: 0;
		border-radius: 0;
	}
	.el-dialog__body {
		color: #fff;
		font-size:15px;
	}
	.loading-container {
		color: #aaa;
		.loading {
			background-color: transparent !important;
			box-shadow: none !important;
		}
	}
	
</style>