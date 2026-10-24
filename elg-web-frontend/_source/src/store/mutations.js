import * as types from './mutations_type.js'
import Vue from 'vue'
import {app} from '../main'
import router from '../router/index.js';
import store from './index.js';

// state
export const state = {
	blockUI: false,

	md5_key: '&zSMvVTK4gbDvCcU',
	bannerAry: [],//
	menu: [],//
	menutype_id: '',
	page: 1,//
	per_page: 20,//
	gameList: [],////gamelist 遊戲
	gamesAry: [],////main 遊戲
	
	countries: [],//
	timezones: [],//
	languages: [],//
	lang: '',
	gameUrl: '',
	urlParams: '',
	vendors: [],
	balance: sessionStorage.getItem('balance'),
	balanceForm: {
		transferTo: '',
		transferVal: '',
		point: '0',
		vendorbalance: null
	},
	acctInfo: {
		mobile: '',
		email: '',
		city: '',
		district: '',
		address: '',
		zip_code: '',
		timezone_id: '',
		limitTime: '',
		email_notify: null,
		box_notify: null,
		popup_notify: null,
		push_notify: null,
		sms_notify: null,
		status: null,
		is_validate: null,
	},
	vendor_id: '',
	game_type: '',

	checkType: {
		gameType: [],
		vendorType: [],
		myType: [],
	},

	IDimg_F: '',
	IDimg_B: '',
	portImg: '',
	IDF_Status: '',
	IDB_Status: '',
	portStatus: '',
	orderTypes: [],
	orderType_id: '',
	recordRange: '',
	today: '',
	yesterday: '',
	DByesterday: '',
	start: '',
	end: '',
	Range: '',
	recordTable: [],
	addPoint: '',

	events: [],
	eventLength: 0,

}

// mutations
export const mutations = {
	
	[types.GET_MEMBER] (state, member) {
		state.balance = member.balance
		sessionStorage.setItem('balance',state.balance)
		console.log(sessionStorage.getItem('balance'))
		state.acctInfo.status = member.status
		state.acctInfo.is_validate = member.is_validate
		state.acctInfo.mobile = member.mobile
		state.acctInfo.email = member.email
		state.acctInfo.city = member.city
		state.acctInfo.district = member.district
		state.acctInfo.address = member.address
		state.acctInfo.zip_code = member.zip_code
		state.acctInfo.timezone_id = member.timezone_id
		state.acctInfo.limitTime = member.setting.activity_limit_time
		state.acctInfo.email_notify = member.setting.email_notify
		state.acctInfo.box_notify = member.setting.message_box_notify
		state.acctInfo.popup_notify = member.setting.message_popup_notify
		state.acctInfo.push_notify = member.setting.push_notify
		state.acctInfo.sms_notify = member.setting.sms_notify
	},
	[types.MENUTYPE] (state , res) {
		state.menu = res
	},
	[types.HOMEGAMES_ARRAY] (state, gamesObj) {
		let ary = state.gamesAry
		ary.push(gamesObj)
	},
	// [types.MENUTYPEID] (state, id) {
	// 	state.menutype_id = id
	// 	console.log('menutypeID',state.menutype_id)
	// },
	[types.GAME_LIST] (state, res) {
		state.gameList = res
	},
	
	[types.GAMETYPE_LIST] (state, res) {
		state.gameList = res
		router.push('/gamelist')
	},

	[types.GET_COUNTRIES] (state, countries) {
		state.countries = countries
	},
	[types.GET_TIMEZONES] (state, timezones) {
		state.timezones = timezones
	},
	[types.GET_LANGUAGES] (state, languages) {
		state.languages = languages
	},
	[types.BROWSER_LANG] (state, lang) {
		state.lang = lang
		if (localStorage.getItem("lang") === ""){
		localStorage.setItem("lang",lang)
		
		} else {
		
		}
	},
	[types.GET_BANNER] (state, bannerAry) {
		state.bannerAry = bannerAry
	},
	[types.GET_ORDERTYPES] (state, ordertypes) {
		state.orderTypes = ordertypes
	},


	/////////////////////////////////////////
	[types.GET_PERCENT] (state, vendorId) {
		state.balanceForm.point = sessionStorage.getItem('balance') * 0.5
		state.balanceForm.transferTo = state.vendors[vendorId - 1].name
		state.balanceForm.transferVal = vendorId
	},
	[types.GET_VENDOR] (state, vendor) {
		state.vendor_id = vendor.vendor_id
		state.game_type = vendor.game_type
	},
	[types.GET_VENDORBALANCE] (state, balance) {
		state.balanceForm.vendorbalance = balance
	},
	[types.GET_GAMEURL] (state, gameUrl) {
		state.gameUrl = gameUrl
		router.push('/game')
	},
	[types.GET_URL_PARAM] (state, params) {
		console.log('par',params)
		state.urlParams = params
	},
	[types.GET_VENDORS] (state, vendors) {
		state.vendors = vendors.map(function(obj){
			var rObj = {}
			rObj['id'] = obj.id
			rObj['name'] = obj.name
			rObj['rate'] = obj.rate
			rObj['balance'] = '0'
			rObj['withdrawal'] = '0'
			return rObj
		})
	},
	[types.GET_AG_BALANCE] (state, AGbalance) {
		state.vendors[0].balance = AGbalance
		console.log('ag',state.vendors[0].balance)
	},
	[types.GET_JOKER_BALANCE] (state, JOKERbalance) {
		state.vendors[1].balance = JOKERbalance
		console.log('joker',state.vendors[1].balance)
	},
	[types.GET_SCR_BALANCE] (state, SCRbalance) {
		state.vendors[2].balance = SCRbalance
		console.log('scr',state.vendors[2].balance)
	},
	[types.GET_GD_BALANCE] (state, GDbalance) {
		state.vendors[3].balance = GDbalance
		console.log('gd',state.vendors[3].balance)
	},
	///// deposit
	[types.GET_TRANSFER_TO_VENDOR] (state, data) {
		sessionStorage.setItem('balance', data.balance)
		state.balance = sessionStorage.getItem('balance')
		state.vendors[state.balanceForm.transferVal - 1].balance = data.vendor_balance
		console.log(state.vendors[0].balance)
		// router.push('/points')
	},
	///////
	[types.GET_TRANSFER_TO_VENDOR] (state, data) {
		sessionStorage.setItem('balance', data.balance)
		state.balance = sessionStorage.getItem('balance')
		state.vendors[state.balanceForm.transferVal - 1].balance = data.vendor_balance
		console.log(state.vendors[0].balance)
		// router.push('/points')
	},
	[types.GET_TRANSFER_TO_ACCT] (state, data) {
		sessionStorage.setItem('balance', data.balance)
		state.balance = sessionStorage.getItem('balance')
		state.vendors[state.balanceForm.transferVal - 1].balance = data.vendor_balance
		// router.push('/points')
	},
	[types.GET_ID_F] (state, data) {
		state.IDimg_F = data
		console.log(state.IDimg_F)
	},
	[types.GET_ID_B] (state, data) {
		state.IDimg_B = data
		console.log(state.IDimg_B)
	},
	[types.GET_PORTIMG] (state, data) {
		state.portImg = data
		console.log(state.portImg)
	},
	[types.GET_VALIDATE] (state, data) {
		state.IDF_Status = data[0].status
		state.IDB_Status = data[1].status
		state.portStatus = data[2].status
	},
	[types.GET_RECORD] (state, {start,end}) {
		console.log('start',start)
		console.log('end',end)
		state.start = start
		state.end = end
	},
	[types.GET_RECORD_TABLE] (state, recordTable) {
		state.recordTable = recordTable
	},
	[types.GET_EVENTS] (state, events) {
		state.events = events
		state.eventLength = events.length
		// sessionStorage.setItem('event_length',events.length)
		
	},
	////// 雙向 bind v-model 處理 //////
	gemeType (state, val) {
		let session_gametype = sessionStorage.getItem('game_type_id')
		let ary = state.checkType.gameType
		ary.push(session_gametype)
		state.checkType.gameType = val
		console.log(state.checkType.gameType)
	},
	vendorType (state, val) {
		state.checkType.vendorType = val
	},
	myType (state, val) {
		state.checkType.myType = val
	},

	updateTransferTo (state, val) {
		state.balanceForm.transferTo = val
		state.balanceForm.transferVal = val
	},
	updatePoint (state, val) {
		state.balanceForm.point = val
	},
	updateMobile (state, val) {
		state.acctInfo.mobile = val
	},
	updateEmail (state, val) {
		state.acctInfo.email = val
	},
	updateCity (state, val) {
		state.acctInfo.city = val
	},
	updateDistrict (state, val) {
		state.acctInfo.district = val
	},
	updateAddress (state, val) {
		state.acctInfo.address = val
	},
	updateZipcode (state, val) {
		state.acctInfo.zip_code = val
	},
	updateTimezone (state, val) {
		state.acctInfo.timezone_id = val
	},
	updateLimitTime (state, val) {
		state.acctInfo.limitTime = val
	},
	update_Email_notify (state, val) {
		state.acctInfo.email_notify = val
	},
	update_Box_notify (state, val) {
		state.acctInfo.box_notify = val
	},
	update_Popup_notify (state, val) {
		state.acctInfo.popup_notify = val
	},
	update_Push_notify (state, val) {
		state.acctInfo.push_notify = val
	},
	update_Sms_notify (state, val) {
		state.acctInfo.sms_notify = val
	},

	blockUI (state, val) {
		state.blockUI = val
	},
	orderType_id (state, val) {
		state.orderType_id = val
	},
	addPoint (state, val) {
		state.addPoint = val
	},
	recordRange (state, val) {
		state.recordRange = val
	},
	Range (state, val) {
		state.Range = val
		state.start = state.Range[0]
		state.end = state.Range[1]
	},
	Lang (state, val) {
		state.lang = val
		console.log(state.lang)
		app.$i18n.locale = val
		console.log('this',app.$i18n.locale)
	}
}
