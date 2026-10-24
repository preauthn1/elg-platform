<template>
	<div id="C711Mobile">
		<div class="contentAll">
			<div
				v-if="$route.meta.event"
				class="ys-float-btn"
				:style="{width: itemWidth + 'px',height: itemHeight + 'px',left: left + 'px',top: top + 'px'}"
				ref="myEvents"
				id="myEvents"
				@click="onBtnClicked()"
			>
				<slot name="icon"></slot>
				<div class="eventbtn" data-toggle="modal" data-target="#eventVisible" @click="eventPopup">
					<img
						src="../../assets/img/C711_Mobile/mark.gif"
						class="eventBtnT"
						v-if="get_EventsReddeem == 1"
					/>

					<span class="eventFont">{{ getEventTotal }}</span>
					<img src="../../assets/img/C711_Mobile/eventBtn.png" />
				</div>
			</div>
			<!-- EVENTS 彈窗 -->

			<div class="modal fade" id="eventVisible" tabindex="-1" role="dialog" v-if="$route.meta.event">
				<div class="modal-dialog modal-dialog-scrollable" role="document">
					<div class="modal-content">
						<div class="colse closeBtn" data-dismiss="modal" aria-label="Close" @click="iframeRE()">
							<img src="../../../static/img/C711_Mobile/modalClose.png" />
						</div>
						<div class="modal-body" v-if="isLoadEventIframe">
							<iframe
								id="iframe"
								name="frame"
								src="/Events_i"
								width="102%"
								frameborder="0"
								scrolling="auto"
								class="iframeHeight"
							></iframe>
						</div>
					</div>
				</div>
			</div>

			<router-view />

			<footer v-if="$route.meta.footer">
				<div class="C711_MlobbyMenu">
					<div class="menuBtn">
						<a href="/">
							<div class="btnPosition">
								<div class="MenuBottomBtn">
									<img src="../../assets/img/C711_Mobile/lobby4.png" v-if="!$route.meta.lobbyBtnLight" />
									<img src="../../assets/img/C711_Mobile/lobby5.png" v-if="$route.meta.lobbyBtnLight" />
								</div>
								<div class="MenuBottomFont">
									<span
										:class="$route.meta.lobbyBtnLight? 'fontColorWhite' : 'fontColorGray'"
									>{{ $t("lobby") }}</span>
								</div>
							</div>
						</a>
					</div>

					<div class="menuBtn">
						<a href="/Events">
							<div class="btnPosition">
								<div class="MenuBottomBtn">
									<img
										src="../../assets/img/C711_Mobile/menu/footer_icon_Activity.png"
										v-if="!$route.meta.eventBtnLight"
									/>

									<img
										src="../../assets/img/C711_Mobile/menu/footer_icon_Activity_light.png"
										v-if="$route.meta.eventBtnLight"
									/>
								</div>
								<div class="MenuBottomFont">
									<span
										:class="$route.meta.eventBtnLight ? 'fontColorWhite': 'fontColorGray' "
									>{{ $t("events") }}</span>
								</div>
							</div>
						</a>
					</div>

					<div class="menuBtn" v-if="has_agent_layout == 1">
						<a href="/Agent">
							<div class="btnPosition">
								<div class="MenuBottomBtn">
									<img
										src="../../assets/img/C711_Mobile/menu/menu_agent_grey.png"
										v-if="!$route.meta.agentBtnLight"
									/>

									<img
										src="../../assets/img/C711_Mobile/menu/menu_agent_light.png"
										v-if="$route.meta.agentBtnLight"
									/>
								</div>
								<div class="MenuBottomFont">
									<span
										:class="$route.meta.agentBtnLight ? 'fontColorWhite': 'fontColorGray' "
									>{{ $t("Agent_AgentMenu") }}</span>
								</div>
							</div>
						</a>
					</div>

					<div class="menuBtn" v-if="has_chat == 1">
						<a href="/Talk">
							<div class="btnPosition">
								<div class="MenuBottomBtn">
									<img src="../../assets/img/C711_Mobile/talk4.png" v-if="!$route.meta.talkBtnLight" />
									<img src="../../assets/img/C711_Mobile/talk5.png" v-if="$route.meta.talkBtnLight" />
								</div>
								<div class="MenuBottomFont">
									<span
										:class="$route.meta.talkBtnLight ? 'fontColorWhite': 'fontColorGray' "
									>{{ $t("talk") }}</span>
								</div>
							</div>
						</a>
					</div>

					<!-- 轉帳模式錢包 -->
					<div class="menuBtn">
						<a href="/PointAndRecord">
							<div class="btnPosition">
								<div class="MenuBottomBtn">
									<img src="../../assets/img/C711_Mobile/wallet4.png" v-if="!$route.meta.walletBtnLight" />
									<img src="../../assets/img/C711_Mobile/wallet5.png" v-if="$route.meta.walletBtnLight" />
								</div>
								<div class="MenuBottomFont">
									<span
										:class="!$route.meta.walletBtnLight? 'fontColorGray': 'fontColorWhite' "
									>{{ $t("wallet") }}</span>
								</div>
							</div>
						</a>
					</div>

					<!-- <div class="menuBtn">
						<a href="/Setting">
							<div class="btnPosition">
								<div class="MenuBottomBtn">
									<img src="../../assets/img/C711_Mobile/set4.png" v-if="!$route.meta.settingBtnLight" />
									<img src="../../assets/img/C711_Mobile/set5.png" v-if="$route.meta.settingBtnLight" />
								</div>
								<div class="MenuBottomFont">
									<span
										:class="$route.meta.settingBtnLight ? 'fontColorWhite': 'fontColorGray'"
									>{{ $t("set") }}</span>
								</div>
							</div>
						</a>
					</div>-->
				</div>
			</footer>
		</div>
		<component :is="getDynamicComponentName"></component>
	</div>
</template>
<script>
import Events from "@/components/C711_Mobile/Events";
import { mapGetters, mapActions } from "vuex";
import { truncate } from "fs";
import { EDEADLK } from "constants";
import { runInDebugContext } from "vm";
import { setTimeout } from "timers";
import Bus from "./Bus.js";
import "../../assets/scss/C711_Mobile/css.css";
import maskModal from "./Modal";
import value_utils from "@/utils/value_utils";
import LangCustomize_ms from "./LangCustomize_ms-my";
//ios back reload
window.addEventListener("pageshow", function(e) {
	var isIOS = navigator.userAgent.indexOf("iPhone") > -1;
	if (isIOS && localStorage.getItem("isNeedRefresh")) {
		localStorage.removeItem("isNeedRefresh");
		window.location.reload();
	}
});

export default {
	name: "FloatImgBtn",
	components: {
		//Events,
		maskModal,
		LangCustomize_ms
	},
	computed: {
		...mapGetters([
			"get_EventsTotal",
			"get_Events",
			"get_EventsReddeem",
			"get_getLoginEvent"
		]),
		// 有登入活動時 TOTAL+1
		getEventTotal() {
			let eventTotal = this.get_getLoginEvent
				? this.get_EventsTotal + 1
				: this.get_EventsTotal;
			return eventTotal;
		},
		getDynamicComponentName() {
			return value_utils.IsMalayLang() ? "LangCustomize_ms" : "";
		}
	},
	data() {
		return {
			isLoadEventIframe: false,
			timer: null,
			currentTop: 0,
			clientWidth: 0,
			clientHeight: 0,
			left: 0,
			top: 0,
			eventVisible: false,
			f: false,
			has_agent_layout: sessionStorage.getItem("has_agent_layout"),
			has_chat: sessionStorage.getItem("has_chat"),
			has_mobile: sessionStorage.getItem("has_mobile"),
			PC_MOBILE: sessionStorage.getItem("PC_MOBILE"),
			transaction_all: sessionStorage.getItem("transaction_all"),
			event_Total: "",
			fullevents: false, //這欄位已棄用了 2020-06-22
			//   鎖分
			EventMode: window.sessionStorage.getItem("eventMode")
		};
	},
	props: {
		text: {
			type: String,
			default: "預設文字"
		},
		itemWidth: {
			type: Number,
			default: 60
		},
		itemHeight: {
			type: Number,
			default: 60
		},
		gapWidth: {
			type: Number,
			default: 10
		},
		coefficientHeight: {
			type: Number,
			default: 0.8
		}
	},
	created() {
		this.clientWidth = document.documentElement.clientWidth;
		this.clientHeight = document.documentElement.clientHeight;
		this.left = this.clientWidth - this.itemWidth - this.gapWidth;
		example.invalid = this.clientHeight * this.coefficientHeight - 100;
		setTimeout(() => {
			let isEventMustShow = this.$route.meta.event;

			if (sessionStorage.getItem("token") && isEventMustShow) {
				this.$store.dispatch("action_EventList");
				this.$store.dispatch("actionGetLoginEvent");
			}
		}, 1000);
	},
	mounted() {
		// 取今日日期
		var Today = new Date();
		this.NowDate =
			Today.getFullYear() +
			"-" +
			(Today.getMonth() + 1) +
			"-" +
			Today.getDate() +
			" " +
			Today.getHours() +
			":" +
			Today.getMinutes() +
			":" +
			Today.getSeconds();

		//接收events.vue 活動總數
		var vm = this;

		Bus.$on("total", data => {
			vm.event_Total = data;
			sessionStorage.setItem("EventTotal", vm.event_Total);
		});
		setTimeout(() => {
			let locationHref = location.href;
			if (
				locationHref.indexOf("Signup") != -1 ||
				locationHref.indexOf("ForgetPassword") != -1 ||
				locationHref.indexOf("RetrievePassword") != -1 ||
				locationHref.indexOf("PhoneVerification") != -1 ||
				locationHref.indexOf("SetPassword") != -1
			) {
				// 活動彈窗不出現
				this.fullevents = false;
			}
		}, 1000);
		setTimeout(() => {
			let eventTotal = sessionStorage.getItem("EventTotal");
			this.event_Total = eventTotal;
			let total = $.cookie("EventsTotal");
			let locationHref = location.href;

			if (
				locationHref.indexOf("Signup") != -1 ||
				locationHref.indexOf("ForgetPassword") != -1 ||
				locationHref.indexOf("RetrievePassword") != -1 ||
				locationHref.indexOf("PhoneVerification") != -1 ||
				locationHref.indexOf("SetPassword") != -1
			) {
				this.fullevents = false;
			} else {
				if (total == undefined || total == null || eventTotal > total) {
					this.fullevents = true;
					// 紀錄活動總數
					$.cookie("EventsTotal", eventTotal);
				}
				// console
			}
		}, 4500);

		$("#has_mobilieMask").css("display", "none");
		$("#has_pcMask").css("display", "none");
		if (this.PC_MOBILE == "MOBILE") {
			if (this.has_mobile == 0 || this.has_mobile == "0") {
				$("#has_pcMask").css("display", "block");
			}
		}
		//手機方向
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
		// 浮動按鈕
		window.addEventListener("scroll", this.handleScrollStart);
		this.$nextTick(() => {
			const div = this.$refs.myEvents;

			if (div) {
				div.addEventListener("touchstart", () => {
					div.style.transition = "none";
				});
				div.addEventListener("touchmove", e => {
					if (e.targetTouches.length === 1) {
						let touch = event.targetTouches[0];
						this.left = touch.clientX - this.itemWidth / 2;
						example.invalid = touch.clientY - this.itemHeight / 2;
					}

					// $('#app').addClass('noscroll')
				});
				div.addEventListener("touchend", () => {
					div.style.transition = "all 0.3s";
					if (this.left > this.clientWidth / 2) {
						this.left = this.clientWidth - this.itemWidth - this.gapWidth;
					} else {
						this.left = this.gapWidth;
					}
				});
				div.addEventListener(
					"touchmove",
					function cantscroll(e) {
						e.preventDefault();
					},
					{ passive: false }
				);
				div.addEventListener("touchend", function cantscroll(e) {
					div.removeEventListener("touchend", cantscroll);
				});
			}
		});
	},
	beforeDestroy() {
		window.removeEventListener("scroll", this.handleScrollStart);
	},
	methods: {
		back() {
			window.history.go(-1);
		},

		onBtnClicked() {
			$("#fullevents").css("display", "none");
		},
		// IFRAME關閉
		iframeRE() {
			var a = document.getElementById("iframe").src;
			frame.location.replace(a);
			// 取EVENTMODE 點參加活動 關閉IFRAME RELOAD
			if (sessionStorage.getItem("eventIframe") == 1) {
				setTimeout(() => {
					window.location.reload();
				}, 100);
				sessionStorage.setItem("eventIframe", 0);
			}
		},
		handleScrollStart() {
			this.timer && clearTimeout(this.timer);
			this.timer = setTimeout(() => {
				this.handleScrollEnd();
			}, 300);
			this.currentTop =
				document.documentElement.scrollTop || document.body.scrollTop;
			if (this.left > this.clientWidth / 2) {
				this.left = this.clientWidth - this.itemWidth / 2;
			} else {
				this.left = -this.itemWidth / 2;
			}
		},
		handleScrollEnd() {
			let scrollTop =
				document.documentElement.scrollTop || document.body.scrollTop;
			if (scrollTop === this.currentTop) {
				if (this.left > this.clientWidth / 2) {
					this.left = this.clientWidth - this.itemWidth - this.gapWidth;
				} else {
					this.left = this.gapWidth;
				}
				clearTimeout(this.timer);
			}
		},

		eventPopup() {
			if (!this.isLoadEventIframe) {
				this.isLoadEventIframe = true;
			}

			ga("send", "event", "Lobby", "EventBtn_Click", "Event");
		},

		eventClose() {
			this.eventVisible = false;
		},
		homeFullClose() {
			//   this.fullevents = false
			$("#fullevents").css("display", "none");
		}
	}
};
</script>

<style scoped>
.all {
	width: 100%;
	height: 100%;
	background-color: #fff;
}
.noscroll {
	overflow: hidden !important;
	color: #fff;
}
</style>
