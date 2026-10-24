<template>
	<div id="C711Mobile">
		<div class="PointHeader">
			<div class="PointImgBg">
				<!-- <img src="../../assets/img/C711_Mobile/Points_header.png" v-if="!$route.meta.isShowTileText" /> -->

				<div class="styleFontHeader">
					<div class v-if="$route.meta.isShowTileText">{{ titleText}}</div>
					<div v-if="$route.meta.isShowLogo">
						<img class="logoImg" src="../../assets/img/vworld_logo_400.png" />
					</div>
				</div>

				<div class="searchIcon" v-if="$route.meta.isSearchIcon">
					<router-link to="/gameListSearch">
						<img class="searchIcon" src="../../assets/img/C711_Mobile/icon/game_list_search.png" />
					</router-link>
				</div>
				<!-- 點數框 -->
				<div v-if="$route.meta.settingPoints">
					<div class="blackPointBg">
						<img src="../../assets/img/C711_Mobile/blackPoint.png" />
					</div>

					<!-- 一般重整按鈕 取平台餘額 -->
					<div class="pointRe" v-if="transaction_all==0">
						<img
							src="../../assets/img/C711_Mobile/reBtnBlue.png"
							@click="reloadBalance()"
							class="deposit_btn"
						/>
					</div>
					<!-- 點數自動轉入按鈕(單一錢包) 取全部餘額  -->
					<div class="pointRe" v-if="transaction_all==1">
						<img
							src="../../assets/img/C711_Mobile/reBtnBlue.png"
							@click="reloadAllModal()"
							class="deposit_btn"
						/>
					</div>
					<!-- 單一 一般共用 -->
					<div class="pointFont">{{ $t("points") }}：{{ getAcctInfo.balance }}</div>
					<div class="pointAddBtn" v-if="has_online_payment == 1 && EventMode == 0">
						<a
							href="/StoredValue"
							onclick="ga('send', 'event', 'StoredBtn', 'StoredBtn_Click', 'Stored');"
						>
							<img src="../../assets/img/C711_Mobile/add.png" />
						</a>
					</div>
					<!-- 鎖分活動 解鎖按鈕 -->
					<div class="pointLockBtn" v-if="EventMode ==1">
						<div class="yellow_borderInput abreastBtnTwo">
							<button
								type="button"
								class="form-control lockHeight fontsizeLock blue_BtnBg"
								@click="UnLockEventMode()"
							>
								<span>Unlock</span>
							</button>
						</div>
					</div>
				</div>
				<!-- 鎖分跑馬燈 -->
				<div class="marquee_Pos">
					<Marquee></Marquee>
				</div>
				<!-- 黑底無重整 -->
				<div v-if="$route.meta.getPoints">
					<div class="blackPointBg">
						<img src="../../assets/img/C711_Mobile/blackPoint.png" />
					</div>
					<div class="pointRe">
						<img
							src="../../assets/img/C711_Mobile/reBtnBlue.png"
							@click="reloadBalance()"
							class="deposit_btn"
						/>
					</div>
					<div class="pointFont">{{ $t("points") }}：{{ getPoint }}</div>
					<div class="pointAddBtn" v-if="has_online_payment == 1 && EventMode == 0">
						<a
							href="/StoredValue"
							onclick="ga('send', 'event', 'StoredBtn', 'StoredBtn_Click', 'Stored');"
						>
							<img src="../../assets/img/C711_Mobile/add.png" />
						</a>
					</div>
					<div class="pointLockBtn" v-if="EventMode ==1">
						<div class="yellow_borderInput abreastBtnTwo">
							<button
								type="button"
								class="form-control lockHeight fontsizeLock blue_BtnBg"
								@click="UnLockEventMode()"
							>
								<span>Unlock</span>
							</button>
						</div>
					</div>
				</div>
				<!-- 紅底 gamelist-->
				<div v-if="$route.meta.getBalance">
					<div class="blackPointBg">
						<img src="../../assets/img/C711_Mobile/blackPointGame.png" />
					</div>
					<div class="pointFont" style="color: #f9f;">{{ $t("Common_GamePoint") }}：{{ getBalance }}</div>
					<div class="pointAddBtn" style="top:1px;">
						<div class="pointLockBtn" v-if="EventMode ==1">
							<div class="yellow_borderInput abreastBtnTwo">
								<button
									type="button"
									class="form-control lockHeight fontsizeLock blue_BtnBg"
									@click="UnLockEventMode()"
								>
									<span>Unlock</span>
								</button>
							</div>
						</div>
					</div>
				</div>

				<div class="fixed-settingBtn">
					<router-link to="/Setting">
						<img
							src="../../assets/img/C711_Mobile/icon/home_icon_Settings.png"
							v-if="$route.meta.isSettingBtn"
						/>
					</router-link>
				</div>

				<div class="rightBtn">
					<img
						src="../../assets/img/C711_Mobile/home.png"
						class="homeBtn"
						@click="goLobby()"
						v-if="$route.meta.home"
					/>
				</div>
				<div class="rightBtn">
					<img
						src="../../assets/img/C711_Mobile/moneyOut1.png"
						@click="pointTransfer()"
						v-if="$route.meta.gamePointBtn && transaction_all != 1"
						style="width:22px;"
						id="moneyOutBtn"
					/>
				</div>
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
			<!-- game遊戲中 儲值 -->
			<div class="mask" v-if="depositVisible">
				<div class="Popups_Modal">
					<div class="PopupsTitle">
						{{ $t("gameTurnPoint") }}
						<img
							@click="depositVisible = false"
							src="../../assets/img/C711_Mobile/modalClose.png"
							class="modalClose"
						/>
					</div>
					<div class="PopupsConAll">
						<div class="PopupsCon popupsConFont">
							<div class="modalBalance">
								{{ template_name }}{{ $t("amount") }} :
								{{ vWorld_balance }}
							</div>
							<div class="modalBalance">{{ $t("gamepublisher") }} : {{ vendor_name }}</div>
							<div class="inputvalidate" style="width:80%;">
								<div class="form-group ListborderInput">
									<input
										class="ListInputRightS Listinputbg"
										type="number"
										pattern="[0-9]*"
										min="1"
										v-model="depositPoint"
										@keyup="inputVal()"
									/>
								</div>
							</div>
						</div>
						<div class="PopupsBtn" @click="normal_deposit()">
							<div class="btn-blue">
								<button type="submit" style="padding:9px auto;">{{ $t("transferIn") }}</button>
							</div>
						</div>
					</div>
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
			titleText: "",
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

		this.titleText = this.$i18n.t(this.$route.meta.title);
	},
	methods: {
		changeTitleText(text) {
			this.titleText = text;
		},

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
			this.$store.dispatch("actionShowBlockUI");
			this.$store.dispatch("actionMember");

			setTimeout(() => {
				this.$store.dispatch("actionCloseBlockUI");
			}, 750);
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

			//只有從這邊觸發的單一錢包轉帳 需要顯示遮罩
			this.$store.dispatch("actionShowBlockUI");

			this.$store
				.dispatch("singleWalletBalance", data)
				.then(isOK => {
					this.$store.dispatch("actionCloseBlockUI");
					if (isOK) {
						let msg = this.$i18n.t("turnPointCompleted");
						alert(msg);
						this.oneWallet = false;
					}
				})
				.catch(error => {
					this.$store.dispatch("actionCloseBlockUI");
				});
		}
	},
	created() {
		this.$store.dispatch("actionMember");
	}
};
</script>
