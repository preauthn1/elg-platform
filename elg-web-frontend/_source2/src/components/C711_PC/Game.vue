<template>
	<div class="bgi" style="padding-bottom:0px;">
		<LobbyHeader></LobbyHeader>
		<div class="gameBG">
			<div class="gameListBar">
				<img
					src="../../../static/img/C711_PC/iii.png"
					class="inform"
					@click="eventinfo()"
					v-if="getEventGameD!==undefined&&getEventGameD!==''"
				/>
				<img
					src="../../../static/img/C711_PC/iframeout2.png"
					class="iframeOutBtn"
					:class="EventMode==1?'iframeBottom_eventMode':'iframeBottom'"
					@click="visit()"
					v-if="transaction_all != 1"
					id="moneyOut"
				/>
			</div>

			<div class="gameBLACK"></div>
			<div class="game">
				<!-- 一般遊戲 -->
				<iframe
					name="target"
					frameborder="0"
					scrolling="auto"
					width="100%"
					class="iframeG"
					id="iframeG"
				></iframe>

				<!-- 一般遊戲 需要帳號密碼submit開法 -->
				<form method="post" target="target" id="formOpenGame">
					<input :value="getVendor_Info.user" name="user" type="hidden" />
					<input :value="getVendor_Info.key" name="key" type="hidden" />
					<input :value="getVendor_Info.extension1" name="extension1" type="hidden" />
				</form>
				<!-- vendor單一遊戲 lobby直進 -->
			</div>
		</div>

		<!-- 點數轉入遊戲-->
		<div class="half_popUp mask" v-if="visibleIn">
			<div class="wrap">
				<img class="bg" src="/static/img/C711_PC/user@example.invalid" />
				<div class="block">
					<div class="head">
						<p>{{ $t("transferOut") }}</p>
						<img @click="close()" src="/static/img/C711_PC/Group5301.png" style="cursor: pointer;" />
					</div>
					<div class="body">
						<div class="content">
							<div class="pointModalInput">
								<div class="pointTO clearfix">
									<div class="float-left pointToFont">{{ template_name }}</div>
									<div class="float-left pointToImg">
										<img src="../../../static/img/C711_PC/pointTO.png" />
										<!-- <img src="../../../static/img/C711_PC/TOpoint.png" /> -->
									</div>
									<div class="float-left pointToFont">{{ vendor_name }}</div>
								</div>
								<div class="pointInput">
									<div class="yellow_borderInput" style="width:75%; margin:auto;">
										<div class="ListborderInput">
											<span class="forgetphoneCode setNewPw">{{ $t("points") }}</span>
											<input
												class="black_InputAll black_inputbg fontsizelogin"
												style="text-align:right; color:#F3D84D; height:35px;"
												@keyup="inputvalue('in')"
												v-model="points"
												type="number"
												pattern="[0-9]*"
											/>
										</div>
									</div>
								</div>
							</div>
						</div>
						<div>
							<div class="btn-blue" @click="transferOut()">
								<button>{{ $t("submit") }}</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<!--全轉成功彈窗-->
		<div class="popUp_block" v-if="isPointsToVendor_success && ToVendor_allPoints > 0">
			<div class="wrap">
				<div class="title">{{ $t("prompt") }}</div>
				<div class="content">
					<div>{{$t('theSystemHasTransferred')}}{{ToVendor_allPointsMsgDisplay}}{{$t('pointsTO')}}{{vendor_name}}{{$t('successfully')}}</div>
				</div>
				<div class="footer">
					<div class="btn-blue">
						<button @click="isPointsToVendor_success=false">{{ $t("confirm") }}</button>
					</div>
				</div>
			</div>
		</div>
		<!-- 遊戲參與活動詳情彈窗 -->
		<div class="popUp_block" v-if="eventGameDetail">
			<div class="wrap">
				<div class="title">{{$t('participatedActivities')}}</div>
				<div class="content" style="text-align:left;">
					<div v-for="(detail, i) in getEventGameD" :key="i">ID:{{detail.id}}&nbsp;TITLE:{{detail.title}}</div>
				</div>
				<div class="footer">
					<div class="btn-blue">
						<button @click="eventGameDetail=false">{{ $t("confirm") }}</button>
					</div>
				</div>
			</div>
		</div>
		<!-- 豪龍賠率表彈窗 -->
		<div class="popUp_block" v-if="get_isVendorID4PC">
			<div class="vendorID4">
				<div class="closeBtn" @click="ID4Hide()">
					<img src="../../../static/img/C711_PC/Group5301.png" />
				</div>
				<img :src="lang=='zh-cn'||lang=='zh-tw'?ID4PicCH:ID4PicEN" />
			</div>
		</div>
		<!-- 彈窗 -->
		<!-- <PopUp></PopUp> -->
	</div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import LobbyHeader from "@/components/C711_PC/Lobby_header";
import PopUp from "./Pop_up";
export default {
	components: {
		LobbyHeader,
		PopUp
	},
	computed: {
		...mapGetters([
			"getGameUrl",
			"getVendor_Info",
			"getVendors",
			"getVendor",
			"getNewvpoints",
			"getEventGameD",
			"getGameData",
			"getGameList",
			"getPtData",
			"get_isVendorID4PC",
			"get_singleBalance"
		])
	},
	data() {
		return {
			vendor_id: window.sessionStorage.getItem("vendor_id"),
			screenHeight: window.innerHeight, //iframe高度

			visibleIn: false,
			balance: sessionStorage.getItem("balance"),
			template_name: sessionStorage.getItem("TemplateTitle"),
			vendor_name: sessionStorage.getItem("vendor_name"),
			transaction_all: sessionStorage.getItem("transaction_all"),
			points: 0,
			repeat: false,
			visibleNote: false,
			isPointsToVendor_success: false,
			ToVendor_allPoints: null,
			ToVendor_allPointsMsgDisplay: "",
			eventGameDetail: false,
			ID4PicEN: require("../../../static/img/C711_PC/s1.jpg"),
			ID4PicCH: require("../../../static/img/C711_PC/s2.jpg"),
			lang: localStorage.getItem("LOCALE"),
			// 鎖分活動模式
			EventMode: sessionStorage.getItem("eventMode")
		};
	},
	//監聽iframe高度
	watch: {},
	mounted() {
		let recaptchaScript = document.createElement("script");
		recaptchaScript.setAttribute(
			"src",
			"https://example.invalid/jswrapper/integration.js.php?casino=winforfun88"
		);
		document.head.appendChild(recaptchaScript);

		if (this.transaction_all == 1 || this.transaction_all == "1") {
			//   全轉
			this.allInOne();
		}

		//iframe高度
		const that = this;
		window.screenHeight = window.innerHeight;
		that.screenHeight = window.screenHeight;

		$(".gameBG").css("height", that.screenHeight - 153 + "px");
		$(".game").css("height", that.screenHeight - 153 + "px");
		$(".gameBLACK").css("height", that.screenHeight - 153 + "px");

		$(document).ready(function() {
			$(".iframeG").on("load", function() {
				$("#loading").hide();
				var enterGameName = sessionStorage.getItem("enterGameName");
				var enterGameId = sessionStorage.getItem("enterGameId");

				ga("send", "event", {
					eventCategory: "Game",
					eventAction: "Game_Enter",
					eventLabel: enterGameName,
					eventValue: enterGameId
				});
				var idoc = document.getElementById("iframeG");
				var w = idoc.contentWindow.document;
			});
		});
		// 鎖分模式 關閉轉點
		setTimeout(() => {
			if (
				this.getGameData.vendor_status == 3 ||
				this.getGameData.vendor_status == 4
			) {
				$("#moneyOut").css("display", "none");
			} else if (this.getGameData.vendor_status == 1) {
				$("#moneyOut").css("bottom", 35 + "px");
			}
		}, 1000);
	},
	methods: {
		ID4Hide() {
			this.$store.commit("HIDE_VENDORID4_PC");
		},

		game() {
			setTimeout(() => {
				let query = window.location.search.split("&");
				let qID = query[0];
				let idsplit = qID.split("=");

				let id = idsplit[1];
				if (id) {
					if (this.getGameData.vendor_id == "17") {
						let data = {
							vendorID: this.getGameData.vendor_id,
							gameID: idsplit[1]
						};
						this.$store.dispatch("actionGameUrl", data);

						setTimeout(() => {
							let ptUserdata = {
								username: this.getPtData.username,
								password: [REDACTED],
								gametype: this.getPtData.game_type
							};

							iapiSetCallout("Login", calloutLogin);

							login(ptUserdata);
						}, 1500);
					} else {
						let data = {
							vendorID: this.getGameData.vendor_id,
							gameID: idsplit[1]
						};
						this.$store.dispatch("actionGameUrl", data);
					}
				} else {
					this.$store.dispatch("action_vendorInfo");
				}
			}, 2000);
		},
		checkBlock: function() {
			window.screenHeight = window.innerHeight;
			this.screenHeight = window.screenHeight;
			this.isShow = !this.isShow;
			if (this.isShow) {
				$(".gameBG").css("height", this.screenHeight - 153 + "px");
				$(".game").css("height", this.screenHeight - 153 + "px");
				$(".game").css("width", this.screenHeight / 2);
			} else {
				$(".gameBLACK").css("display", "block");

				$(".gameBG").css("height", this.screenHeight - 153 + "px");
				$(".game").css("height", this.screenHeight - 153 + "px");

				$(".game").css("width", 100 + "%");
				setTimeout(() => {
					$(".gameBLACK").css("display", "none");
				}, 800);
			}
		},

		// 轉點
		visit() {
			this.visibleIn = true;
		},
		// 遊戲有參與活動的按鈕
		eventinfo() {
			this.eventGameDetail = true;
		},
		inputvalue(val) {
			if (val === "in") {
				// 平台點數去除千分位  ex: 1,000.00 > 1000
				let vPoints = parseInt(
					sessionStorage
						.getItem("balance")
						.toString()
						.replace(",", ""),
					10
				);
				if (this.points == "") {
					// input 空值'' = 0
					this.points = 0;
				} else if (this.points !== "") {
					// ex: 0500 > 500 開頭去0
					this.points = parseInt(this.points);
				}
				if (this.points >= vPoints) {
					// input 轉入最多 = 平台點數
					this.points = vPoints;
				}
			}
		},
		//平台轉出
		transferOut(isAllIn) {
			this.visibleIn = false;
			let data = {};
			data["vendor_id"] = this.vendor_id;
			data["vendor_name"] = this.vendor_name;
			if (isAllIn == "AllIn") {
				if (this.getNewvpoints) {
					data["point"] = parseInt(
						this.getNewvpoints.toString().replace(",", ""),
						10
					);
					this.ToVendor_allPoints = parseInt(
						this.getNewvpoints.toString().replace(",", ""),
						10
					);
				} else {
					data["point"] = parseInt(
						this.balance.toString().replace(",", ""),
						10
					);
					this.ToVendor_allPoints = parseInt(
						this.balance.toString().replace(",", ""),
						10
					);
				}
			} else {
				data["point"] = this.points;
			}
			if (data.point != 0) {
				if (this.vendor_id == "3") {
					if (this.repeat == false) {
						this.repeat = true; // 防15秒
						this.$store
							.dispatch("balanceToVendor", data)
							.then(success => {
								this.isPointsToVendor_success = success;
							});
						setTimeout(() => {
							this.repeat = false;
						}, 15000);
					} else if (this.repeat == true) {
						this.visibleNote = true;
					}
				} else {
					this.$store.dispatch("balanceToVendor", data).then(success => {
						this.isPointsToVendor_success = success;
					});
				}

				ga("send", "event", {
					eventCategory: "Points",
					eventAction: "TransferOut_Click",
					eventLabel: this.vendor_name,
					eventValue: this.points
				});
			}
		},
		close() {
			this.visibleIn = false;
		},
		//! 開關 全轉入遊戲 1.allInOne  2.transferAll_In
		//! 全轉遊戲改打單一錢包API
		allInOne() {
			setTimeout(() => {
				let vendorID = this.getGameData.vendor_id;

				this.$store
					.dispatch("singleWalletBalance", vendorID)
					.then(success => {
						// this.isPointsToVendor_success = success; //轉點完成開啟成功彈窗
						// this.ToVendor_allPoints = parseInt(
						// 	//     //將欲轉點之點數顯示至提示窗上
						// 	this.get_singleBalance.toString().replace(",", ""),
						// 	10
						// );
						// this.ToVendor_allPointsMsgDisplay = this.get_singleBalance
						// 	.toString()
						//   .replace(",", "");
					});
			}, 1500);
		}
	},

	created() {
		//   類型(預設1)1:遊戲列表，2:轉點列表
		let data = {
			type: 2,
			isGetBalance: true
		};
		this.$store.dispatch("actionVendors", data);
		var gameID = this.$route.query.id;
		this.$store.dispatch("actionGame", gameID);
		this.game();
	}
};
</script>
