<template>
	<div id="app ">
		<div class="has_pc_mobileMask" style="display:none z-index:9;" id="has_pcMask">
			<div class="has_pc_mobilePic">
				<!-- 手機圖 pc用-->
				<img :src="lang == 'zh-tw' ? has_mobileCH : has_mobileEN" />
			</div>
		</div>
		<div class="has_pc_mobileMask" style="display:none z-index:9;" id="has_mobilieMask">
			<div class="has_pc_mobilePic">
				<!-- pc圖 手機用-->
				<img :src="lang == 'zh-tw' ? has_pcCH : has_pcEN" />
			</div>
		</div>
		<!-- 螢幕鎖定 -->
		<div class="directionMask" style="z-index:8;">
			<div class="directionPic">
				<img :src="lang == 'zh-tw' ? directionCh : directionEng" />
			</div>
		</div>

		<BlockUI v-if="blockUI" :url="url" style="z-index:99999"></BlockUI>

		<router-view />
	</div>
</template>
<script>
import Global from "@/Global";
import { mapGetters, mapMutations } from "vuex";
import axios from "axios";
import loadingImage from "../src/assets/img/C711_Mobile/loading7.gif";

export default {
	name: "App",
	data() {
		return {
			loadingCount: 0,
			directionCh: require("../static/img/directionCh.png"),
			directionEng: require("../static/img/directionEng.png"),
			has_pcCH: require("../static/img/UsePcCN.png"),
			has_pcEN: require("../static/img/UsePcEN.png"),
			has_mobileCH: require("../static/img/UseAppCN.png"),
			has_mobileEN: require("../static/img/UseAppEN.png"),
			enPic: require("../static/img/wechat.png"),
			zh_twPic: require("../static/img/wechatC.png"),
			lang: localStorage.getItem("LOCALE"),
			has_pc: sessionStorage.getItem("has_pc"),
			has_mobile: sessionStorage.getItem("has_mobile"),
			msg: "",
			url: loadingImage //It is important to import the loading image then use here
		};
	},
	computed: {
		...mapGetters(["getApi_request"]),
		blockUI: {
			get() {
				return this.$store.state.Global.blockUI;
			},
			set(val) {
				this.$store.commit("closeBlockUI", val);
			}
		}
	},
	mounted() {
		$("#has_mobilieMask").css("display", "none");
		$("#has_pcMask").css("display", "none");
		$(".directionMask").css("display", "none");

		// 判斷手機板本
		$(function() {
			var u = navigator.userAgent,
				app = navigator.appVersion;
			var isAndroid = u.indexOf("Android") > -1 || u.indexOf("Linux") > -1; //g
			var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/); //ios终端
			if (isAndroid) {
				sessionStorage.setItem("AndroidIos", "Android");
			}
			if (isIOS) {
				sessionStorage.setItem("AndroidIos", "Ios");
			}
		});
	},
	methods: {
		/// BLOCKUI //
		addLoading() {
			this.loadingCount++;
		},
		isCloseLoading() {
			this.loadingCount--;

			if (this.loadingCount == 0) {
				this.blockUI = false;
			}
		},
		/// 彈窗關
		resHide(code) {
			if (code == "9006") {
				this.$router.push("/RetrievePassword");
			} else if (code == "9007") {
				this.$router.push("/PhoneVerification");
			} else if (code == "9008") {
			} else if (code == "9998") {
				this.$router.push("/Login");
			}

			this.$store.commit("HIDE_API_RES");
		},
		/// 註冊成功
		signupSuccess() {
			window.location = "/login";
		}
	},
	created() {
		let vm = this;
		axios.interceptors.request.use(
			function(config) {
				vm.addLoading();

				if (
					config.url !== Global.$API_URL + "api/getPrize" &&
					config.url !== Global.$API_URL + "api/setPrize" &&
					config.url !== Global.$API_URL + "api/member" &&
					config.url !== Global.$API_URL + "api/singleWalletBalance"
					//特定api不顯示遮罩
				) {
					vm.blockUI = true;
				}
				return config;
			},
			function(error) {
				vm.blockUI = false;

				return Promise.reject(error);
			}
		);
		axios.interceptors.response.use(
			function(response) {
				vm.isCloseLoading();
				return response;
			},
			function(error) {
				// vm.blockUI = false
				vm.isCloseLoading();
				return Promise.reject(error);
			}
		);
	}
};
</script>

<style scoped>
#app {
	/* font-family: 'Avenir', Helvetica, Arial, sans-serif; */
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
	text-align: center;
	color: #2c3e50;
	margin: auto;
}
.btnborder {
	background: none;
	border: none;
	outline: none;
	display: inline-block;
}
.btn:hover,
.btn:focus {
	outline: none;
}

.loading-container .loading-backdrop {
	/* //the backdrop falls in here, you can override the opacity etc. */
}

.loading-container .loading .loading-icon {
	/* //the image or inserted html will be put here */
}
.loading-container .loading .loading-label {
	/* //the label showing on loading screen */
}
/* direction鎖定方向 */
.directionMask {
	width: 100%;
	height: 100vh;
	position: fixed;
	top: 0;
	right: 0;
	z-index: 9998;
	background-color: #000000f5;
	/* display: none; */
}
.directionPic {
	position: relative;
	top: 50%;
	transform: translateY(-50%);
	margin: auto;
	z-index: 9999;
	width: 100%;
	/* height: 80%; */
}
img {
	width: 100%;
	height: 100%;
}
/* 有無手機/PC版 */
.has_pc_mobileMask {
	width: 100%;
	height: 100vh;
	position: fixed;
	top: 0;
	right: 0;
	z-index: 9998;
	background-color: #000000f5;
	/* display: none; */
}
.has_pc_mobilePic {
	position: relative;
	top: 50%;
	transform: translateY(-50%);
	margin: auto;
	z-index: 9999;
	width: 100%;
	/* height: 80%; */
}
img {
	width: 100%;
	height: 100%;
}
</style>
