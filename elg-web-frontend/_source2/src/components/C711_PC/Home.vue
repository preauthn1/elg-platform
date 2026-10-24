<template>
	<div id="C711PC" @click="iframeRE()">
		<div>
			<router-view />

			<div class="leftFixedBtn" v-if="$route.meta.fixedBtn && loging">
				<div v-if="has_agent_layout == 1">
					<div class="fixedBtnPic">
						<a href="/Agent">
							<img src="../../assets/img/agent/agent_fix_right_PC.svg" />
						</a>
					</div>
					<div class="space"></div>
				</div>

				<div
					class="fixedBtnPic marginTop10"
					data-toggle="modal"
					data-target="#exampleModalCenter"
					@click="eventOpen()"
				>
					<img src="../../../static/img/C711_PC/fixedBtn1.png" />
				</div>
				<div v-if="has_chat == 1 ">
					<div class="space"></div>
					<div
						v-if="has_chat == 1"
						class="fixedBtnPic"
						data-toggle="modal"
						data-target="#exampleModalCenter1"
						@click="talkIframe()"
					>
						<img src="../../../static/img/C711_PC/fixedBtn2.png" />
					</div>
				</div>
			</div>

			<!-- FOOTER -->
			<div class="footer_block">
				<div class="footer_container">
					<div class="footer_top">
						<router-link to="/QA">
							{{
							$t("commonProblem")
							}}
						</router-link>
						<router-link to="/Rule">
							{{
							$t("termsAndConditions")
							}}
						</router-link>
						<router-link to="/Privacy">
							{{
							$t("privacyPolicy")
							}}
						</router-link>
						<router-link to="/Cookies">
							{{
							$t("cookiesPolicy")
							}}
						</router-link>
					</div>
				</div>
			</div>

			<!-- 聊天 -->
			<div
				v-if="loging"
				class="modal right fade"
				id="exampleModalCenter1"
				tabindex="-1"
				role="dialog"
				aria-labelledby="exampleModalCenterTitle"
				aria-hidden="true"
			>
				<div class="modal-dialog modal-dialog-centered" role="document" v-if="isLoadTalk">
					<div class="modal-content">
						<div class="modal-body">
							<iframe
								name="chat"
								width="100%"
								height="100%"
								frameborder="0"
								scrolling="auto"
								id="chat"
								:src="iframeSrc"
								class="iframeChat"
								ref="chat"
							></iframe>
						</div>
					</div>
				</div>
			</div>
			<!-- EVENTS -->
			<div
				v-if="loging"
				class="modal right fade"
				id="exampleModalCenter"
				tabindex="-1"
				role="dialog"
				aria-labelledby="exampleModalCenterTitle"
				aria-hidden="true"
			>
				<div class="modal-dialog modal-dialog-centered" role="document">
					<div class="modal-content">
						<div class="modal-body" v-if="isLoadEvent">
							<iframe
								id="iframe"
								name="frame"
								src="/Events_i"
								width="102%"
								frameborder="0"
								scrolling="auto"
								class="iframeHeight"
								style="background:#2d0d02;"
							></iframe>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>
<script>
import "@/assets/scss/C711_PC/style.css";
import "@/assets/scss/C711_PC/css.css";
import child from "./Events";
import Global from "@/Global";
import { isNull } from "util";
const CryptoJS = require("crypto-js");
import secret from "@/utils/secret";

export default {
	components: {
		child
	},
	data() {
		return {
			iframeSrc: Global.$API_CHATPATH,
			inputSrc: "",
			loging: sessionStorage.getItem("token"),
			template_has_chat: 0,
			has_agent_layout: sessionStorage.getItem("has_agent_layout"),
			has_chat: sessionStorage.getItem("has_chat"),
			has_pc: sessionStorage.getItem("has_pc"),
			PC_MOBILE: sessionStorage.getItem("PC_MOBILE"),
			templateMain_test: sessionStorage.getItem("Template"),
			name: "",
			eventModal: "",
			isLoadEvent: false,
			isLoadTalk: false
		};
	},
	mounted() {
		$("#has_mobilieMask").css("display", "none");
		$("#has_pcMask").css("display", "none");
		if (this.PC_MOBILE == "PC") {
			if (this.has_pc == 0 || this.has_pc == "0") {
				$("#has_pcMask").css("display", "block");
			}
		}
	},

	methods: {
		eventOpen() {
			this.eventModal = 1;

			if (!this.isLoadEvent) {
				this.isLoadEvent = true;
			}
		},
		iframeRE() {
			if (this.loging && this.isLoadEvent) {
				var a = document.getElementById("iframe").src;
				frame.location.replace(a);
				// 取EVENTMODE 點參加活動 關閉IFRAME RELOAD
				if (sessionStorage.getItem("eventIframe") == 1) {
					setTimeout(() => {
						window.location.reload();
					}, 100);
					sessionStorage.setItem("eventIframe", 0);
				}
			}
		},
		talkIframe() {
			if (!this.isLoadTalk) {
				this.isLoadTalk = true;
			}

			var agUser = sessionStorage.getItem("CS_API_USER");
			var agKey = sessionStorage.getItem("CS_API_KEY");

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

			const vm = this;
			vm.fullHeight = window.innerHeight;
			vm.fullWidth = window.innerWidth;
			window.onresize = () => {
				vm.fullHeight = window.innerHeight;
				vm.fullWidth = window.innerWidth;
			};
			$(".chatIframe").css("height", vm.fullHeight - 53 + "px");
			$(".chatIframe").css("width", vm.fullWidth);

			var stest = {
				agentuser: sessionStorage.getItem("CS_API_USER"),
				agentkey: sessionStorage.getItem("CS_API_KEY")
			};

			var signData = JSON.stringify({
				thirdpartyId: window.sessionStorage.getItem("username"),
				agentuser: sessionStorage.getItem("CS_API_USER"),
				agentkey: sessionStorage.getItem("CS_API_KEY"),
				lang: localStorage.getItem("LOCALE"),
				templateMain: sessionStorage.getItem("Template"),
				EventMode: sessionStorage.getItem("eventMode")
			});

			let signDataSecret = [REDACTED];

			this.iframeSrc =
				this.iframeSrc + signDataSecret.replace(/\//g, "5s1g7er0A");
		}
	}
};
</script>
