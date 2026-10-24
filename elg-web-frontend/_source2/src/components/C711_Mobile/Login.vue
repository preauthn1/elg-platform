<template>
	<div id="C711Mobile">
		<div class="login">
			<!-- 捷徑引導 -->
			<div class="shortcutMask" v-if="false">
				<div class="shortcutPic" style="color:#fff;" v-if="AndroidIos == 'Android'">
					<img :src="lang == 'zh-tw' ? androidAddcn : androidAdden" class="androidAdd" />
				</div>
				<div class="shortcutPic" style="color:#fff;" v-if="AndroidIos == 'Ios'">
					<img :src="lang == 'zh-tw' ? iosAddcn : iosAdden" class="iosAdd" />
				</div>
			</div>
			<!-- 螢幕鎖定 -->
			<div class="directionMask">
				<div class="directionPic">
					<img :src="lang == 'zh-tw' ? directionCh : directionEng" />
				</div>
			</div>
			<!-- 微信引導 -->
			<div class="wechatMask">
				<div class="wechatPic">
					<img :src="lang == 'zh-tw' ? zh_twPic : enPic" />
				</div>
			</div>
			<img src="../../assets/img/C711_Mobile/loginBg.png" class="loginBg" />

			<div class="selectWapper">
				<img src="../../assets/img/C711_Mobile/icon/global_earth_icon.svg" class="selectIcon" />
				<select id="selectLang" v-model="selectedLang" @change="changeLang">
					<option class="gray_dropdownBg" value="en">English</option>
					<option class="gray_dropdownBg" value="zh-tw">繁體中文</option>
					<option class="gray_dropdownBg" value="zh-cn">简体中文</option>
					<option class="gray_dropdownBg" value="ms-my">Melayu</option>
					<option class="gray_dropdownBg" value="id-id">Indonesia</option>
				</select>
			</div>

			<div class="C711_Msignin">
				<div class="loginLogo">
					<img :src="logoMobile" />
				</div>
				<div class="signData">
					<form>
						<div class="form-group yellow_borderInput marginBottom20">
							<input
								v-model="account"
								class="black_InputAll black_inputbg fontsizelogin"
								id="InputUser"
								:placeholder="$t('loginphone')"
								required
								style="color:#fff font-size:15px"
							/>
						</div>
						<div class="form-group yellow_borderInput marginBottom20">
							<input
								v-model="password"
								type="password"
								class="black_InputAll black_inputbg fontsizelogin"
								id="InputPassword"
								:placeholder="$t('password')"
								required
								style="color:#fff font-size:15px"
							/>
						</div>
						<div class="form-group yellow_borderInput marginBottom20">
							<input
								v-model="loginBtn"
								type="button"
								class="black_InputAll red_Btnbg fontsizeBtn"
								@click="playSound();actionLogin();"
								style="color:#fff font-size:15px"
							/>
						</div>
						<div class="abreastBtnTwo marginBottom20 displayFlex">
							<!-- v-if="frontend_register == 1 " -->
							<div class="form-group yellow_borderInput abreastBtn">
								<a href="/Signup" @click="playSound">
									<input
										v-model="signupBtn"
										type="button"
										class="black_InputAll yellow_Btnbg fontsizeBtn"
										@click="playSound()"
										style="color:#fff font-size:15px"
									/>
								</a>
							</div>
							<div class="form-group yellow_borderInput abreastBtn">
								<a href="/ForgetPassword" @click="playSound">
									<input
										v-model="forgetBtn"
										type="button"
										class="black_InputAll gray_BtnBg fontsizeBtn"
										style="color:#fff font-size:15px"
									/>
								</a>
							</div>
						</div>
					</form>
				</div>
				<div class="mask" v-if="get_eventOver">
					<div class="Popups_Modal">
						<div class="PopupsTitle">{{ $t("prompt") }}</div>
						<div class="PopupsConAll">
							<div class="PopupsCon popupsConFont">
								<div>{{$t('eventIsOver')}}</div>
							</div>
						</div>
					</div>
				</div>
				<!-- 彈窗 -->
				<maskModal></maskModal>
			</div>

			<div class="bottomIcon">
				<div class="whatapp">
					<a href="https://example.invalid/601119006244" target="_blank">
						<div class="whatappPic">
							<img src="../../assets/img/G733/whatapp.png" />
						</div>
						<div class="whatappFont">{{ $t("customerService") }}</div>
					</a>
				</div>
				<div class="whatapp" @click="downloadAPP()" v-if="has_app == 1 ">
					<div class="whatappPic">
						<img src="../../assets/img/G733/vworld_icon.png" />
					</div>
					<div class="whatappFont">{{ $t("downLoadAPP") }}</div>
				</div>
			</div>
		</div>
	</div>
</template>
<script>
import "../../assets/scss/C711_Mobile/css.css";
import { mapGetters, mapActions } from "vuex";
import maskModal from "./Modal";
import Global from "@/Global";
import { isNull } from "util";
const CryptoJS = require("crypto-js");
import secret from "@/utils/secret";
import md5 from "js-md5";
import axios from "axios";
import Bus from "./Bus.js";

var winHeight = $(window).height();
$(window).resize(function() {
	var thisHeight = $(this).height();
	var keyboardHeight = thisHeight - winHeight;
	$(".loginFooter").css({ bottom: keyboardHeight + "px" });
});
$(function() {
	var u = navigator.userAgent,
		app = navigator.appVersion;
	var isAndroid = u.indexOf("Android") > -1 || u.indexOf("Linux") > -1; //g
	var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/); //ios终端
	if (isAndroid) {
		//ANDROID鍵盤喚起時,FOOTER隱藏
		var oHeight = $(document).height(); //屏幕当前的高度
		$(window).resize(function() {
			if ($(document).height() < oHeight) {
				$(".whatapp").css("display", "none");
			} else {
				$(".whatapp").css("display", "block");
			}
		});
	}
	if (isIOS) {
	}
});
export default {
	components: {
		maskModal
	},
	computed: {
		...mapGetters(["get_eventOver"])
	},
	data() {
		return {
			selectedLang: localStorage.getItem("LOCALE"),
			language: this.$i18n.locale,
			account: "",
			password: "",
			loginBtn: this.$t("signIn"),
			signupBtn: this.$t("signUp"),
			forgetBtn: this.$t("forget"),
			directionCh: require("../../../static/img/directionCh.png"),
			directionEng: require("../../../static/img/directionEng.png"),
			iosAddcn: require("../../../static/img/iosAddcn.png"),
			iosAdden: require("../../../static/img/iosAdden.png"),
			androidAddcn: require("../../../static/img/androidAddcn.png"),
			androidAdden: require("../../../static/img/androidAdden.png"),
			enPic: require("../../../static/img/wechat.png"),
			zh_twPic: require("../../../static/img/wechatC.png"),
			lang: localStorage.getItem("LOCALE"),
			direction: window.orientation,
			logoMobile: sessionStorage.getItem("TemplateLogo"),
			frontend_register: sessionStorage.getItem("frontend_register"),
			has_app: sessionStorage.getItem("has_app"),
			has_mobile: sessionStorage.getItem("has_mobile"),
			PC_MOBILE: sessionStorage.getItem("PC_MOBILE"),
			AndroidIos: sessionStorage.getItem("AndroidIos"),
			device: sessionStorage.getItem("device")
			//   鎖分活動
			//   eventModeModal: false
		};
	},
	mounted() {
		var shortcut = $.cookie("shortcut");
		// ios android 引導
		if (!shortcut) {
			if (this.AndroidIos == "Ios") {
				$(".shortcutMask").css("display", "block");
				$(".shortcutPic").css("top", 50 + "%");
				$(".shortcutPic").css("transform", "translateY(-50%)");
			} else {
				$(".shortcutMask").css("display", "block");
			}
			setTimeout(() => {
				$(".shortcutMask").css("display", "none");
			}, 3000);
			$.cookie("shortcut", "has_shortcut");
		} else {
			$(".shortcutMask").css("display", "none");
		}
		// 紀錄密碼解密
		if (localStorage.getItem("user_account")) {
			this.account = localStorage.getItem("user_account");
		} else {
			this.account = "";
		}
		// this.account = localStorage.getItem('user_account') || '',
		let secretPW = localStorage.getItem("user_password");
		if (secretPW == null || secretPW == undefined || secretPW == "") {
			this.password = "";
		} else {
			let decryptPW = secret.Decrypt(secretPW);
			this.password = [REDACTED];
		}

		$("#has_mobilieMask").css("display", "none");
		$("#has_pcMask").css("display", "none");
		if (this.PC_MOBILE == "MOBILE") {
			if (this.has_mobile == 0) {
				$("#has_mobilieMask").css("display", "block");
				$("#has_pcMask").css("display", "none");
			} else {
			}
		}

		//偵測WECHAT
		var ua = navigator.userAgent.toLowerCase();
		if (ua.match(/MicroMessenger/i) == "micromessenger") {
			$(".wechatMask").css("display", "block");
		} else {
			$(".wechatMask").css("display", "none");
		}

		$(".directionMask").css("display", "none");
		if (window.orientation == 0 || window.orientation == 180) {
			$(".directionMask").css("display", "none");
		}
		window.onorientationchange = function() {
			if (window.orientation == 90 || window.orientation == -90) {
				$(".directionMask").css("display", "block");
			} else {
				$(".directionMask").css("display", "none");
			}
		};

		// 鎖分活動彈窗
	},
	methods: {
		changeLang() {
			let targetLang = this.selectedLang;
			let currentLang = localStorage.getItem("LOCALE");

			if (targetLang != currentLang) {
				localStorage.setItem("LOCALE", targetLang);
				location.href = "/login";
			}
		},

		playSound() {
			var sound = document.getElementById("audio");
			sound.play();
		},
		// 微信引導關閉
		wechatClose() {
			$(".wechatMask").css("display", "none");
		},
		//語系變更
		GetLanguage(event) {
			var lang = event.target.value;
			localStorage.setItem("LOCALE", lang);
			this.$i18n.locale = lang;
		},
		actionLogin() {
			var device_id = sessionStorage.getItem("device");

			let data = {
				account: this.account,
				password: [REDACTED],
				device_id: device_id
			};

			this.$store.dispatch("actionLogin", data);
		},

		downloadAPP() {
			//判斷IOS ANDROID
			$(function() {
				var u = navigator.userAgent,
					app = navigator.appVersion;
				var isAndroid =
					u.indexOf("Android") > -1 || u.indexOf("Linux") > -1; //g
				var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/); //ios终端
				if (isAndroid) {
					window.location =
						" https://example.invalid/APPDL/Android/Browser/VWorld.apk";
				}
				if (isIOS) {
					window.location =
						" itms-services://?action=download-manifest&url=https://example.invalid/APPDL/IOS/Browser/VWorld.plist";
				}
			});
		}
		// closeEventOver() {
		//   this.$store.commit('HIDE_EVENT_OVER')
		// }
	},
	created() {
		this.$store.commit("HIDE_API_RES");
	},
	watch: {
		// selectedLang: function(val) {
		// 	console.log("ttttest");
		// 	this.changeLang();
		// },

		direction: function(val) {}
	}
};
</script>


<style>
select:focus {
	outline: 0;
}
</style>