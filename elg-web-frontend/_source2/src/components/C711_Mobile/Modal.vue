<template>
	<!--  -->
	<div id="C711Mobile">
		<div class="mask" v-if="getApi_request.isVisible">
			<div class="Popups_Modal">
				<div class="PopupsTitle">{{ $t("prompt") }}</div>
				<div class="PopupsConAll">
					<div class="PopupsCon popupsConFont">
						<p v-if="getApi_request.code != 2002&&getApi_request.code != 99961">
							<span>
								{{ getApi_request.msg }}
								<br />
							</span>
						</p>
						<p v-if="getApi_request.code == 2002">
							<span v-for="(item, i) in getApi_request.msg" :key="i">
								{{ item }}
								<br />
							</span>
						</p>
						<p v-if="getApi_request.code == 99961">{{$t('systemMaintenanceUpgrade')}}</p>
					</div>
					<!-- 帳號鎖定 9006 -->
					<div class="PopupsBtn" v-if="getApi_request.code == 9006" @click="resHide(9006)">
						<div class="BtnShare blueBtnShare">{{ $t("confirm") }}</div>
					</div>

					<!-- 手機未驗證 9007 -->
					<div class="PopupsBtn" v-else-if="getApi_request.code == 9007" @click="resHide(9007)">
						<div class="BtnShare blueBtnShare">{{ $t("confirm") }}</div>
					</div>

					<!-- 信箱未驗證 9008 -->

					<div class="PopupsBtn" v-else-if="getApi_request.code == 9008" @click="resHide(9008)">
						<div class="BtnShare blueBtnShare">{{ $t("confirm") }}</div>
					</div>

					<!-- 註冊成功 1000 -->

					<div
						class="PopupsBtn"
						v-else-if="getApi_request.code == 1000 && getApi_request.type == 'signup'"
						@click="signupSuccess()"
					>
						<div class="BtnShare blueBtnShare">{{ $t("confirm") }}</div>
					</div>
					<!-- 前台客服登入成功 -->
					<div class="PopupsBtn" v-else-if="getApi_request.code == 10001 " @click="resHide('10001')">
						<div class="BtnShare blueBtnShare">{{ $t("confirm") }}</div>
					</div>
					<!-- gameapp 換密碼成功 1000 -->
					<div
						class="PopupsBtn"
						v-else-if="getApi_request.code == 1000 && getApi_request.type == 'vendorPass'"
						@click="resHide('vendorPass')"
					>
						<div class="BtnShare blueBtnShare">{{ $t("confirm") }}</div>
					</div>

					<!-- 9998 TOKEN ERROR 轉跳 LOGIN -->
					<div class="PopupsBtn" v-else-if="getApi_request.code == 9998" @click="resHide('9998')">
						<div class="BtnShare blueBtnShare">{{ $t("confirm") }}</div>
					</div>
					<!-- 一般錯誤 -->
					<div class="PopupsBtn" v-else @click="resHide()">
						<div class="BtnShare blueBtnShare">{{ $t("confirm") }}</div>
					</div>
				</div>
			</div>
		</div>

		<div v-show="isRightBottomPopupVisible">
			<div :class="'rightBottomPopup ' + rightBottomCssStyle">
				<svg
					style="margin-right:10px;margin-top: -2px;"
					class="bi bi-exclamation-circle"
					width="1em"
					height="1em"
					viewBox="0 0 16 16"
					fill="currentColor"
					xmlns="http://example.invalid/2000/svg"
				>
					<path
						fill-rule="evenodd"
						d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"
					/>
					<path
						d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0zM7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 4.995z"
					/>
				</svg>
				{{rightBottomMsg}}
			</div>
		</div>
	</div>
</template>
<script>
import Global from "@/Global";
import { mapGetters, mapMutations } from "vuex";
import axios from "axios";

export default {
	name: "App",
	data() {
		return {
			rightBottomMsg: "",
			isRightBottomPopupVisible: true,
			loadingCount: 0,
			lang: localStorage.getItem("LOCALE"),
			rightBottomCssStyle: "hidden-effect"
		};
	},
	computed: {
		...mapGetters(["getApi_request"])
	},
	mounted() {
		if (window.orientation == 0 || window.orientation == 180) {
			$(".directionMask").css("display", "none");
		}
		//橫向禁止
		window.onorientationchange = function() {
			if (window.orientation == 90 || window.orientation == -90) {
				$(".directionMask").css("display", "block");
			} else {
				$(".directionMask").css("display", "none");
			}
		};
	},
	methods: {
		//顯示右下方的小彈窗
		showRightBottomMaskAndDisappear(text) {
			this.isRightBottomPopupVisible = true;
			this.rightBottomCssStyle = "visible";
			this.rightBottomMsg = text;

			//n秒後自動淡出
			setTimeout(() => {
				this.rightBottomCssStyle = "hidden-effect";
				setTimeout(() => {
					this.isRightBottomPopupVisible = false;
				}, 1000);
			}, 2000);
		},

		/// 彈窗關
		resHide(code) {
			if (code == "9006") {
				this.$router.push("/RetrievePassword");
			} else if (code == "9007") {
				this.$router.push("/PhoneVerification");
			} else if (code == "9008") {
			} else if (code == "9998") {
				// sessionStorage.removeItem('token')
				window.sessionStorage.clear();
				localStorage.removeItem("user_account");
				window.location.assign("/login");
			}
			this.$store.commit("HIDE_API_RES");
		},
		/// 註冊成功
		signupSuccess() {
			window.location = "/login";
		}
	},
	created() {}
};
</script>