import Vue from 'vue'
import mutations, { state } from './mutations'
import * as types from './mutations_type.js'
import axios from 'axios'
const md5 = require('js-md5');
import secret from '../utils/secret'
import store from '.'
import router from '../router'
import $ from 'jquery'
// import { getMenu, getGamelist } from './getters';

//// logout ////
export const actionLogout = ({ commit }) => {
	let token = [REDACTED]'token')
	let params = secret.Encrypt( '' )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/logout', {
		params: params,
		key: key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('已登出',res)
		window.location.assign('/')
	})
}


///// MENU 選單 抓type_id 生成 遊戲首頁列表 GAME LIST////
export const actionTypeid = ({ commit }, id) => {
	console.log(id)
	if(id){
		window.sessionStorage.setItem('game_type_id',id)
	}
	let type_id = window.sessionStorage.getItem('game_type_id')
	let md5_key = state.md5_key
	let jsonMsg = JSON.stringify({
		"vendor_id": '', "type_id": type_id , "key": '', "page": state.page, "per_page": state.per_page
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + md5_key )
	let token = [REDACTED]'token')
	axios.post('https://example.invalid/api/games', {
		params: params,
		key: key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		// console.log( 'gamelist',res.data.data)
		
		commit(types.GAMETYPE_LIST, res.data.data)
	})
	.catch(error => {
		console.log(error)
	})
}

///// 產生 MENU 選單 ////
export const actionMenu = ({ commit }) => {
	let md5_key = state.md5_key
	axios.post('https://example.invalid/api/gameTypes',{
		params: secret.Encrypt(''),
		key: md5( secret.Encrypt('') + md5_key )
	},{
		headers: { 'Accept-Language': store.state.lang }
	})
	.then(res => {
		commit(types.MENUTYPE, res.data.data)
	})
	.catch(error => {
		console.log(error)
		
	})
}

//// 首頁遊戲列表 games////

export const actionHomegames = async ({ dispatch, commit }) => {
	let md5_key = state.md5_key
	await axios.post('https://example.invalid/api/gameTypes',{
		params: secret.Encrypt(''),
		key: md5( secret.Encrypt('') + md5_key )
	},{
		headers: { 'Accept-Language': store.state.lang }
	})
	.then(res => {
		//console.log('menu_id:', res.data)
		dispatch('getGames',res)
	})
	.catch(error => {
		console.log(error)
		
	})	
}
export const getGames = async ({commit},res) => {
	let md5_key = state.md5_key
	let menuAry = res.data.data
	// console.log('menuARY',menuAry)
	for(var element of menuAry)
	{
		let id = element.id
		let name = element.name
		let jsonMsg = JSON.stringify({
			"vendor_id": '', "type_id": id , "key": '', "page": state.page, "per_page": state.per_page
		})
		let params = secret.Encrypt( jsonMsg )
		let key = md5( params + md5_key )
		let token = [REDACTED]'token')
		await axios.post('https://example.invalid/api/games', {
			params: params,
			key: key
		},{
			headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
		})
		.then(res => {
			let ary = res.data.data
			// console.log('id:',id ,name)
			// console.log('ary:', ary)
			let obj = {}
			obj['id'] = id
			obj['name'] = name
			obj['games'] = ary
			// console.log('obj',obj)
			commit(types.HOMEGAMES_ARRAY, obj)		

		})
		.catch(error => {
			console.log(error)
		})
	}	
}


//// 專屬優惠 events ////
export const actionEvents = ({ commit }) => {
	let md5_key = state.md5_key
	let token = [REDACTED]'token')
	if(token){
		let jsonMsg = JSON.stringify({
			"page": 1, "per_page": 30
		})
		let params = secret.Encrypt( jsonMsg )
		let key = md5( params + md5_key)
		axios.post('https://example.invalid/api/events',{
			params: params,
			key: key
		},{
			headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token}
		})
		.then(res => {
			commit(types.GET_EVENTS, res.data.data)
		})
		.catch(error => {
			console.log('error',error)
		})
	}
}


//// 國家列 countries ////
export const actionCountries = ({ commit }) => {
	let md5_key = state.md5_key
	let params = secret.Encrypt('')
	let key = md5( params + md5_key)
	axios.post('https://example.invalid/api/countries',{
		params: params,
		key: key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " }
	})
	.then(res => {
		console.log('countries',res.data.data)
		commit(types.GET_COUNTRIES, res.data.data)
	})
	.catch(error => {
		console.log(error)
	})
}

//// 時區列 timezones ////
export const actionTimezones = ({ commit }) => {
	let md5_key = state.md5_key
	let params = secret.Encrypt('')
	let key = md5( params + md5_key)
	axios.post('https://example.invalid/api/timezones',{
		params: params,
		key: key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " }
	})
	.then(res => {
		console.log('timezone',res.data.data)
		commit(types.GET_TIMEZONES, res.data.data)
	})
	.catch(error => {
		console.log(error)
	})
}
/// 語言列 languages///
export const actionLanguages = ({ commit }) => {
	let md5_key = state.md5_key
	let params = secret.Encrypt('')
	let key = md5( params + md5_key)
	axios.post('https://example.invalid/api/languages',{
		params: params,
		key: key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " }
	})
	.then(res => {
		// console.log('languages',res.data.data)
		commit(types.GET_LANGUAGES, res.data.data)
	})
	.catch(error => {
		console.log(error)
	})
}

//// 抓瀏覽器語系 ////
export const browserLang = ({ commit }) => {
	let sys = (navigator.language || navigator.browserLanguage).toLowerCase()
	console.log("browser",sys)
	let lang
	if ( sys == "en" || sys == "en-us" || sys == "en-gb" || sys == "en-au" || sys == "nz"){
		lang = "en"
	} else if (sys == "zh"|| sys == "zh-tw"|| sys == "zh-sg"|| sys == "zh-cn"|| sys == "zh-hk" ){
		lang = "zh-tw"
	}
	console.log("lang",lang)
	commit(types.BROWSER_LANG, lang)
}

//// banner廣告列表  advertisements////
export const actionBanner = ({ commit }, path_id) => {
	let jsonMsg = JSON.stringify({
		"path_id": path_id
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/advertisements',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer "}
	})
	.then(res => {
		let bannerAry = res.data.data
		commit(types.GET_BANNER, bannerAry)
	})
	.catch(error => {
		console.log('error',error)
	})
}

////get member /////
export const getMember = ({ commit },payload) => {
	console.log(payload)
	let token = [REDACTED]'token')
	let params = secret.Encrypt( '' )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/member',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('member',res.data.data)
		commit(types.GET_MEMBER, res.data.data)
		let isValidate = payload.isValidate || false
		if(isValidate) {
			let status = res.data.data.status
			console.log('status',status)
			let jsonMsg = JSON.stringify({
				"status": status
			})
			let params = secret.Encrypt( jsonMsg )
			let key = md5( params + state.md5_key )
			axios.post('https://example.invalid/api/validations',{
				params : params,
				key : key
			},{
				headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
			})
			.then(res => {
				console.log('validates', res)
				console.log('validates', status)
				commit(types.GET_VALIDATE, res.data.data)
			})
			.catch(error => {
				console.log('error', error)
			})
		}
	})
	.catch(error => {
		console.log(error)
	})
}

/// mygames 我的最愛 最常玩.... /////
export const actionMygames = ({ commit }) => {
	let token = [REDACTED]'token')
	let jsonMsg = JSON.stringify({
		"vendor_id": '', "type_id": "", "diamond": "", "personal": "", "page":1, "per_page":30
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/myGames',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('mygames', res)
	})
	.catch(error => {
		console.log('error',error)
	})
}

//// 加到我的最愛 ////
export const addFavorite = ({ commit }, item) => {
	let token = [REDACTED]'token')
	let game_id = item.id
	let isFavorite = !item.is_favourite
	console.log('item',item)
	console.log('favourite',isFavorite)
	let jsonMsg = JSON.stringify({
		"game_id": game_id,"status": isFavorite
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/favouriteGame',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('AddFavourit', res)
	})
	.catch(error => {
		console.log('error',error)
	})
}

///update /////
export const updateMember = ({ commit }) => {
	let token = [REDACTED]'token')
	let jsonMsg = JSON.stringify({
		"email": state.acctInfo.email ,"mobile": state.acctInfo.mobile, "city":state.acctInfo.city, "district":state.acctInfo.district, "address":state.acctInfo.address, "zip_code":state.acctInfo.zip_code, "timezone_id":state.acctInfo.timezone_id
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/update',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('update', res)
	})
	.catch(error => {
		console.log('error',error)
	})
	let settingJsonMsg = JSON.stringify({
		"activity_limit_time": state.acctInfo.limitTime, "push_notify": state.acctInfo.push_notify, "sms_notify":state.acctInfo.sms_notify, "email_notify": state.acctInfo.email_notify, "message_box_notify": state.acctInfo.box_notify, "message_popup_notify":state.acctInfo.popup_notify
	})
	let settingParams = secret.Encrypt( settingJsonMsg )
	let settingKey = md5( settingParams + state.md5_key )
	axios.post('https://example.invalid/api/updateSetting',{
		params : settingParams,
		key : settingKey
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('updateSetting', res)
	})
	.catch(error => {
		console.log('error',error)
	})
}

//// get balance 進遊戲 判斷遊戲商餘額///
export const getBalance = ({ commit }, vendor) => {
	let token = [REDACTED]'token')
	console.log('vendor',vendor)
	console.log('gametype',vendor.game_type)
	console.log('vendorid',vendor.vendor_id)
	let jsonMsg = JSON.stringify({
		"vendor_id": vendor.vendor_id
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/getVendorBalance',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('gamebalance',res.data.data)
		commit(types.GET_VENDOR, vendor)
		commit(types.GET_VENDORBALANCE, res.data.data)
		/// 遊戲未點數 平台餘額轉遊戲儲值 ///
		if (res.data.data == 0 ) {
			console.log('res.data',res.data)
			commit(types.GET_PERCENT, vendor.vendor_id)
			router.push('/deposit')			
		}
		/// 遊戲有點數 進入遊戲 ///
		else if (res.data.data !== 0 & res.data.data !== '') {
			console.log('res.data',res.data)
			let jsonMsg = JSON.stringify({
				"vendor_id": vendor.vendor_id ,"game_type": vendor.game_type
			})
			let params = secret.Encrypt( jsonMsg )
			let key = md5( params + state.md5_key )
			axios.post('https://example.invalid/api/getGameUrl',{
				params : params,
				key : key
			},{
				headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
			})
			.then(res => {
				console.log('url',res.data.data)
				
				let url = res.data.data
				let split = url.split("?")[1].split('&')
				let account = split[0].split('=')[1]
				let password = [REDACTED]'=')[1]
				let params = "帳號:" + account + " 密碼:" + password
				if(split) {
					commit(types.GET_URL_PARAM, params)
				}
				console.log("split",account)
				console.log("split",password)
				commit(types.GET_GAMEURL, res.data.data)
			})
			.catch(error => {
				console.log('url_error',error)
			})
		}
		else {
			console.log('error')
		}
	})
	.catch(error => {
		console.log(error)
	})
}

/// vendor_id = 2 進遊戲 ////
export const actionJokerGame = ({ commit }) => {
	if(state.vendor_id == 2){
		let token = [REDACTED]'token')
		let jsonMsg = JSON.stringify({
			"vendor_id": state.vendor_id ,"game_type": state.game_type
		})
		let params = secret.Encrypt( jsonMsg )
		let key = md5( params + state.md5_key )
		axios.post('https://example.invalid/api/getGameUrl',{
			params : params,
			key : key
		},{
			headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
		})
		.then(res => {
			console.log('2222url',res.data.data)
			$("iframe").attr("src",res.data.data);
         	$("iframe").submit();
		})
		.catch(error => {
			console.log('url_error',error)
		})
	}
}

/// 轉點頁 轉帳進遊戲商 並跳遊戲 ///
export const actionDeposit = ({ commit }) => {
	let token = [REDACTED]'token')
	let jsonMsg = JSON.stringify({
		"vendor_id": state.balanceForm.transferVal ,"point": state.balanceForm.point
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/balanceToVendor',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('res', res.data)
		commit(types.GET_TRANSFER_TO_VENDOR, res.data.data)
			let jsonMsg = JSON.stringify({
				"vendor_id":  state.balanceForm.transferVal ,"game_type": state.game_type
			})
			let params = secret.Encrypt( jsonMsg )
			let key = md5( params + state.md5_key )
			axios.post('https://example.invalid/api/getGameUrl',{
				params : params,
				key : key
			},{
				headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
			})
			.then(res => {
				console.log('url',res.data.data)
				commit(types.GET_GAMEURL, res.data.data)
			
			})
			.catch(error => {
				console.log('url_error',error)
			})
	})
	.catch(error => {
		console.log('error',error)
	})
}

//// points 轉帳進遊戲商 ////
export const balanceToVendor = ({ commit }) => {
	let token = [REDACTED]'token')
	let jsonMsg = JSON.stringify({
		"vendor_id": state.balanceForm.transferVal ,"point": state.balanceForm.point
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/balanceToVendor',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('res', res.data)
		commit(types.GET_TRANSFER_TO_VENDOR, res.data.data)
	})
	.catch(error => {
		console.log('error',error)
	})
}

//// points 轉帳出平台 ////
export const vendorToBalance = ({ commit }) => {
	let token = [REDACTED]'token')
	let jsonMsg = JSON.stringify({
		"vendor_id": state.balanceForm.transferVal ,"point": state.balanceForm.point
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/vendorToBalance',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('res', res.data)
		commit(types.GET_TRANSFER_TO_ACCT, res.data.data)
	})
	.catch(error => {
		console.log('error',error)
	})
}

//// vendors /////
export const getVendors = ({ commit }) => {
	let token = [REDACTED]'token')
	if(token) {
		let params = secret.Encrypt( '' )
		let key = md5( params + state.md5_key )
		axios.post('https://example.invalid/api/vendors',{
			params : params,
			key : key
		},{
			headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
		})
		.then(res => {
			commit(types.GET_VENDORS, res.data.data)
		})
		.catch(error => {
			console.log('error', error)
		})
	}
}

/// 抓 AG balance ////
export const getAGbalance = ({ commit }) => {
	// if(state.vendors.length !== 0){
		// let AG = state.vendors[0].id
		// console.log('AGbalance',AG)
		let token = [REDACTED]'token')
		let jsonMsg = JSON.stringify({
			"vendor_id": 1
		})
		let params = secret.Encrypt( jsonMsg )
		let key = md5( params + state.md5_key )
		
		axios.post('https://example.invalid/api/getVendorBalance',{
			params : params,
			key : key
		},{
			headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
		})
		.then(res => {
			console.log('getag',res.data)
			commit(types.GET_AG_BALANCE,res.data.data)
		})
		.catch(error => {
			console.log('error', error)
		})
	// }
}
/// joker balance /////
export const getJokerbalance = ({ commit }) => {
	// if(state.vendors.length !== 0){
		// let joker = state.vendors[1].id
		// console.log('Jokerbalance',joker)
		let token = [REDACTED]'token')
		let jsonMsg = JSON.stringify({
			"vendor_id": 2
		})
		let params = secret.Encrypt( jsonMsg )
		let key = md5( params + state.md5_key )
		
		axios.post('https://example.invalid/api/getVendorBalance',{
			params : params,
			key : key
		},{
			headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
		})
		.then(res => {
			console.log('getjoker',res.data)
			commit(types.GET_JOKER_BALANCE,res.data.data)
		})
		.catch(error => {
			console.log('error', error)
		})
	// }
}
/// SCR balance /////
export const getSCRbalance = ({ commit }) => {
	// if(state.vendors.length !== 0){
		// let SCR = state.vendors[2].id
		// console.log('Jokerbalance',SCR)
		let token = [REDACTED]'token')
		let jsonMsg = JSON.stringify({
			"vendor_id": 3
		})
		let params = secret.Encrypt( jsonMsg )
		let key = md5( params + state.md5_key )
		
		axios.post('https://example.invalid/api/getVendorBalance',{
			params : params,
			key : key
		},{
			headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
		})
		.then(res => {
			console.log('getscr',res.data)
			commit(types.GET_SCR_BALANCE,res.data.data)
		})
		.catch(error => {
			console.log('error', error)
		})
	// }
}
/// GD balance /////
export const getGDbalance = ({ commit }) => {
	// if(state.vendors.length !== 0){
		// let GD = state.vendors[3].id
		// console.log('GDbalance',GD)
		let token = [REDACTED]'token')
		let jsonMsg = JSON.stringify({
			"vendor_id": 4
		})
		let params = secret.Encrypt( jsonMsg )
		let key = md5( params + state.md5_key )
		
		axios.post('https://example.invalid/api/getVendorBalance',{
			params : params,
			key : key
		},{
			headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
		})
		.then(res => {
			console.log('getag',res.data)
			commit(types.GET_GD_BALANCE,res.data.data)
		})
		.catch(error => {
			console.log('error', error)
		})
	// }
}

//// 64base ////
export const actionID_F = ({ commit }, e) => {
	var files = e.target.files[0];
		if (!e || !window.FileReader) return; // 看支持不支持FileReader
		let reader = new FileReader();
		reader.readAsDataURL(files); // 这里是最关键的一步，转换就在这里
		reader.onloadend = function() {
			//  这个时候_self.avatar中存的就是编码过后的图片了
			commit(types.GET_ID_F,this.result)
		};
}
export const actionID_B = ({ commit }, e) => {
	var files = e.target.files[0];
		if (!e || !window.FileReader) return; // 看支持不支持FileReader
		let reader = new FileReader();
		reader.readAsDataURL(files); // 这里是最关键的一步，转换就在这里
		reader.onloadend = function() {
			//  这个时候_self.avatar中存的就是编码过后的图片了
			commit(types.GET_ID_B,this.result)
		};
}
export const actionPort = ({ commit }, e) => {
	var files = e.target.files[0];
		if (!e || !window.FileReader) return; // 看支持不支持FileReader
		let reader = new FileReader();
		reader.readAsDataURL(files); // 这里是最关键的一步，转换就在这里
		reader.onloadend = function() {
			//  这个时候_self.avatar中存的就是编码过后的图片了
			commit(types.GET_PORTIMG,this.result)
		};
}

//// 驗證 create ///
export const createID_F = ({ commit }, type_id) => {
	let token = [REDACTED]'token')
	let jsonMsg = JSON.stringify({
		"type_id": type_id, "image": state.IDimg_F
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/validation/create',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('idf', res.data)
	})
	.catch(error => {
		console.log('error', error)
	})
}
export const createID_B = ({ commit }, type_id) => {
	let token = [REDACTED]'token')
	let jsonMsg = JSON.stringify({
		"type_id": type_id, "image": state.IDimg_B
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/validation/create',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('idB', res.data)
	})
	.catch(error => {
		console.log('error', error)
	})
}
export const createPort = ({ commit }, type_id) => {
	let token = [REDACTED]'token')
	let jsonMsg = JSON.stringify({
		"type_id": type_id, "image": state.portImg
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/validation/create',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('port', res.data)
	})
	.catch(error => {
		console.log('error', error)
	})
}

//// 轉帳類型 /////
export const actionOrderTypes = ({ commit }) => {
	let token = [REDACTED]'token')
	let params = secret.Encrypt( '' )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/orderTypes',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		// console.log('OderTypes', res.data.data)
		commit(types.GET_ORDERTYPES, res.data.data)
	})
	.catch(error => {
		console.log('error', error)
	})
}
//// 交易紀錄 /////
export const actionRecordData = ({ commit }) => {
	let dt = new Date()
	let year = dt.getFullYear()
	let month = append_zero( dt.getMonth() + 1 )
	let date = append_zero( dt.getDate() )
	let hours = append_zero( dt.getHours() )
	let minute = append_zero( dt.getMinutes() )
	let second = append_zero( dt.getSeconds() )
	function append_zero(obj){
		if( obj<10 ) return '0' +''+ obj
		else return obj
	}
	let today = year +'-'+ month +'-'+ date +' '+ hours +':'+ minute +':'+ second
	
	if(state.recordRange == 24){
		let ydt = new Date(dt.getTime() - 24*60*60*1000)
		let y_year = ydt.getFullYear()
		let y_month = append_zero( ydt.getMonth() + 1 )
		let y_date = append_zero( ydt.getDate() )
		let y_hours = append_zero( ydt.getHours() )
		let y_minute = append_zero( ydt.getMinutes() )
		let y_second = append_zero( ydt.getSeconds() )
		let yesterday = y_year +'-'+ y_month +'-'+ y_date +' '+ y_hours +':'+ y_minute +':'+ y_second
		commit(types.GET_RECORD, {'start':yesterday,'end':today})
	} else if(state.recordRange == 48) {
		let dby = new Date(dt.getTime() - 48*60*60*1000)
		let dby_year = dby.getFullYear()
		let dby_month = dby.getMonth() + 1
		let dby_date = dby.getDate()
		let dby_hours = dby.getHours()
		let dby_minute = dby.getMinutes()
		let dby_second = dby.getSeconds()
		let DByesterday = dby_year +'-'+ append_zero(dby_month) +'-'+ append_zero(dby_date) +' '+ append_zero(dby_hours) +':'+ append_zero(dby_minute) +':'+ append_zero(dby_second)
		commit(types.GET_RECORD, {'start':DByesterday,'end':today})
	} else if(state.recordRange == 0){
		commit(types.GET_RECORD, {'start':'','end':''})
	}
}

export const Record = ({ commit }) => {
	let token = [REDACTED]'token')
	let jsonMsg = JSON.stringify({
		"order_type_id": state.orderType_id, "start_time": state.start, "end_time":state.end, "status": '1', "page":1, "per_page":30
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	console.log(state.start)
	axios.post('https://example.invalid/api/orders',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('orders',res.data.data)
		commit(types.GET_RECORD_TABLE, res.data.data)
	})
	.catch(error => {
		console.log(error)
	})
}

/// 儲值 //////
export const actionAddPoint = ({ commit }) => {
	let token = [REDACTED]'token')
	console.log(window.location);
	let jsonMsg = JSON.stringify({
		"order_type_id": state.orderType_id, "amount": state.addPoint, "callback": window.location.origin+'/point_success'
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/order/create',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log(res.data.data)
		let url = res.data.data
		window.location.assign(url)
	})
	.catch(error => {
		console.log(error)
	})
}

export const pointSuccess = ({ dispatch, commit }) => {
	return dispatch('getMember').then(() => {
		setTimeout(() => {
			window.location.assign('/')
		},5000)
	})
}




















///// template ////////////
export const template = ({ commit }) => {
	let token = [REDACTED]'token')
	let jsonMsg = JSON.stringify({
		"domain":"localhost"
	})
	let params = secret.Encrypt( jsonMsg )
	let key = md5( params + state.md5_key )
	axios.post('https://example.invalid/api/template',{
		params : params,
		key : key
	},{
		headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " + token }
	})
	.then(res => {
		console.log('000000',res.data.data)
	})
	.catch(error => {
		console.log(error)
	})
}
