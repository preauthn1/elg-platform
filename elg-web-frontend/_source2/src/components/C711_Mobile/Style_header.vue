<template>
	<div id="C711Mobile">
		<div class="PointHeader">
			<div class="PointImgBg">
				<div class="styleFontHeader">{{ $t($route.meta.title)}}</div>

				<div class="rightBtn">
					<a href="/Record" onclick="ga('send', 'event', 'Wallet', 'RecordBtn_Click', 'Record');">
						<img src="../../assets/img/C711_Mobile/record.png" v-if="$route.meta.record" />
					</a>
				</div>
				<div class="backBtn">
					<img src="../../assets/img/C711_Mobile/back.png" @click="back()" v-if="$route.meta.back" />
				</div>
				<div class="backBtn">
					<img
						src="../../assets/img/C711_Mobile/back.png"
						@click="gameListBack()"
						v-if="$route.meta.gameListBack"
					/>
				</div>
			</div>

			<!-- 解鎖彈窗 -->
			<div class="popUp_block" v-if="get_isUnlockEvent">
				<div class="wrap" style="top:20%;">
					<div class="single_block">
						<div class="case_wrapIframe">
							<div>
								<div class="title">
									{{ $t("eventModeSignOff") }}
									<img
										@click="modeModal()"
										class="close_icon"
										src="../../../static/img/C711_PC/Group5301.png"
									/>
								</div>
								<div class="form">
									<div class="orangeTitle" style="margin:20px auto;">
										<div
											class="forceFont"
										>{{ $t("youCurrentlyHave") }}{{get_unlockEvent.balance}}{{ $t("point") }}，{{ $t("areYousureThat") }}{{get_unlockEvent.point*get_unlockEvent.times}}{{ $t("point") }}{{ $t("ifYouSignOffNow") }}</div>
									</div>
								</div>
								<div class="footer clearfix" style="width:90%;margin:auto;">
									<div class="btn-blue float-left" style="margin:5px; width:120px;" @click="forcibly(1)">
										<button>{{ $t("determine") }}</button>
									</div>
									<div class="btn-orange float-right" style="margin:5px; width:120px;" @click="modeModal()">
										<button>{{$t('cancel')}}</button>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<!-- 自動帶點模式 點數重整提示 -->
			<div class="mask" v-if="oneWallet">
				<div class="Popups_Modal">
					<div class="PopupsTitle">{{$t('prompt')}}</div>
					<div class="PopupsConAll">
						<div class="PopupsCon popupsConFont">
							<div style="width:80%;margin:auto;line-height:25px;">{{$t('checkingTheBalance')}}</div>
						</div>
						<div class="PopupsBtn clearfix">
							<div class="float-left">
								<div class="btn-blueTwo">
									<button
										type="submit"
										@click="reloadAllBalance()"
										style="padding:9px auto;"
									>{{ $t("confirm") }}</button>
								</div>
							</div>
							<div class="float-right">
								<div class="btn-orangeTwo">
									<button
										type="submit"
										@click="oneWallet  = false"
										style="padding:9px auto;"
									>{{ $t("cancel") }}</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<!-- 彈窗 -->
			<maskModal></maskModal>
		</div>
	</div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import "../../assets/scss/C711_Mobile/css.css";
import Marquee from "@/components/C711_Mobile/Marquee";
import maskModal from "./Modal";
export default {
	components: {
		maskModal,
		Marquee
	},
	computed: {
		...mapGetters([
			"getAcctInfo",
			"getVendors",
			"getNewvpoints",
			"getNew_VendorBalance",
			"getVendor_balance",
			//   鎖分活動
			"get_checkEvent",
			"get_isUnlockEvent",
			"get_unlockEvent",
			"getRecordPoints",
			//   單一錢包
			"get_singleBalance"
		]),
		getPoint() {
			let vpoints = this.getNewvpoints
				? this.getNewvpoints
				: sessionStorage.getItem("balance");
			return vpoints;
		},
		getBalance() {
			let vendorPoints = this.getNew_VendorBalance
				? this.getNew_VendorBalance
				: this.getVendor_balance;

			return vendorPoints;
		}
	},
	data() {
		return {
			vPoints: window.sessionStorage.getItem("balance"),
			balance: window.sessionStorage.getItem("balance"),
			depositVisible: false,
			template_name: sessionStorage.getItem("TemplateTitle"),
			vWorld_balance: sessionStorage.getItem("balance"),
			vendor_name: sessionStorage.getItem("vendor_name"),
			depositPoint: 0,
			has_online_payment: sessionStorage.getItem("has_online_payment"),
			//   鎖分
			EventMode: window.sessionStorage.getItem("eventMode"),
			transaction_all: sessionStorage.getItem("transaction_all"),

			//   自動帶點,最後一筆轉點紀錄 點數
			oneWallet: false
		};
	},
	mounted() {
		$(".directionMask").css("display", "none");
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
		back() {
			let vendor_id = sessionStorage.getItem("vendor_id");
			let need_login = sessionStorage.getItem("need_login");
			let only_one_game = sessionStorage.getItem("only_one_game");
			if (location.pathname == "/ButtonClick") {
				this.$router.push("/");
			} else if (only_one_game == 1 && location.pathname == "/Game") {
				this.$router.push("/");
			} else if (need_login == 0 && location.pathname == "/Game") {
				this.$router.push("/GameList/" + vendor_id);
			} else if (location.pathname == "/GameList/" + vendor_id) {
				this.$router.push("/");
			} else if (location.pathname == "/GameApp") {
				this.$router.push("/");
			} else {
				this.$router.go(-1);
			}
		},
		gameListBack() {
			this.$router.push("/");
		},
		goLobby() {
			window.location = "/";
		},
		/// game遊戲中 轉點
		pointTransfer() {
			this.depositVisible = true;
			this.vendor_name = sessionStorage.getItem("vendor_name");
		},
		inputVal() {
			this.depositPoint = parseInt(this.depositPoint, 10);
			let vPoints = parseInt(
				this.vWorld_balance.toString().replace(",", ""),
				10
			);
			if (this.depositPoint >= vPoints) {
				this.depositPoint = vPoints;
			} else if (!this.depositPoint) {
				this.depositPoint = 0;
			}
		},
		// 一般轉點
		normal_deposit() {
			let data = {};
			data["vendor_id"] = sessionStorage.getItem("vendor_id");
			data["point"] = this.depositPoint;
			this.$store.dispatch("balanceToVendor", data);
			this.depositVisible = false;
		},
		// 重新取平台點數
		reloadBalance() {
			this.$store.dispatch("actionMember");
		},
		// 活動解鎖
		UnLockEventMode() {
			this.$store.dispatch("actionUnLoginEvent");
		},
		// 關閉強制解鎖彈窗
		modeModal() {
			this.$store.commit("HIDE_UNLOCK_EVENT");
		},
		// 強制解鎖
		forcibly(data) {
			this.$store.dispatch("actionUnLoginEvent", data);
			this.$store.commit("HIDE_UNLOCK_EVENT");
		},
		// 單一錢包彈窗
		reloadAllModal() {
			this.oneWallet = true;
		},
		// 自動轉點 RE LODAD取全部餘額按鈕
		reloadAllBalance() {
			let data = "";
			this.$store.dispatch("singleWalletBalance", data);
			this.oneWallet = false;
			//   $('.zero').css('display', 'none')
		}
	},
	created() {
		this.$store.dispatch("actionMember");
	}
};
</script>
