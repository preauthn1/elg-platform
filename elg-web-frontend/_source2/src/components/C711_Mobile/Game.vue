<template>
	<div id="C711Mobile">
		<div class="pageBg CwidthHeight widthHeightvh fontFamily">
			<LoginHeader></LoginHeader>
			<div class="GamePlay">
				<span style="color:white;"></span>
				<div class="eventBtnD"></div>

				<img id="loading" src="../../assets/img/C711_Mobile/Loading.gif" class="loadingiframeGame" />

				<iframe
					name="target"
					width="100%"
					height="100%"
					frameborder="0"
					scrolling="auto"
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
				<form method="post" target="target" id="onlyOneGame"></form>
			</div>
			<!-- 彈窗 -->
			<maskModal></maskModal>
		</div>
		<!-- 全轉點數成功彈窗 -->
		<div class="mask" v-if="isPointsToVendor_success && ToVendor_allPoints > 0">
			<div class="Popups_Modal">
				<div class="PopupsTitle">{{ $t("prompt") }}</div>
				<div class="PopupsConAll">
					<div class="PopupsCon popupsConFont">
						<div style="font-size:14px;">
							{{ $t("theSystemHasTransferred")
							}}{{ ToVendor_allPointsMsgDisplay }}{{ $t("pointsTO")
							}}{{ vendor_name }}{{ $t("successfully") }}
						</div>
					</div>
					<div class="PopupsBtn">
						<div class="btn-blue">
							<button type="submit" @click="close()" style="padding:9px auto;">{{ $t("confirm") }}</button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<!-- 活動進入遊戲按鈕詳情 -->
		<div class="mask" v-if="eventGameDetail">
			<div class="Popups_Modal">
				<div class="PopupsTitle">{{ $t("participatedActivities") }}</div>
				<div class="PopupsConAll">
					<div class="PopupsCon popupsConFont">
						<div
							style="margin:auto; width:80%;text-align:left;"
							v-for="(detail, i) in getEventGameD"
							:key="i"
						>ID:{{ detail.id }}&nbsp;TITLE:{{ detail.title }}</div>
					</div>
					<div class="PopupsBtn">
						<div class="btn-blue">
							<button
								type="submit"
								@click="eventGameDetail = false"
								style="padding:9px auto;"
							>{{ $t("confirm") }}</button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<!-- 豪龍賠率表彈窗 -->
		<div class="mask" v-if="get_isVendorID4">
			<div class="vendorID4">
				<div class="closeBtn" @click="ID4Hide()">
					<img src="../../assets/img/C711_Mobile/modalClose.png" />
				</div>
				<img :src="lang == 'zh-cn' || lang == 'zh-tw' ? ID4PicCH : ID4PicEN" />
			</div>
		</div>
	</div>
</template>

<script>
import LoginHeader from "@/components/C711_Mobile/Login_header";
import { mapGetters, mapActions } from "vuex";
import maskModal from "./Modal";
import Bus from "./Bus.js";

export default {
	data() {
		return {
			vendor_id: window.sessionStorage.getItem("vendor_id"),
			screenHeight: window.innerHeight, //iframe高度
			template_name: sessionStorage.getItem("TemplateTitle"),
			vWorld_balance: sessionStorage.getItem("balance"),
			vendor_name: sessionStorage.getItem("vendor_name"),

			visibleIn: false,
			balance: sessionStorage.getItem("balance"),
			template_name: sessionStorage.getItem("TemplateTitle"),
			vendor_name: sessionStorage.getItem("vendor_name"),
			transaction_all: sessionStorage.getItem("transaction_all"), //全轉開關
			points: 0,
			repeat: false, //918重複轉點鎖定
			visibleNote: false,

			isPointsToVendor_success: false, //全轉成功提示彈窗
			ToVendor_allPoints: null, //全轉點數成功彈窗提示 共轉入的總金額
			ToVendor_allPointsMsgDisplay: "",
			eventGameDetail: false,
			name: 0,

			ptUserdata: "",
			//   豪龍賠率表
			ID4PicEN: require("../../../static/img/C711_Mobile/s1.jpg"),
			ID4PicCH: require("../../../static/img/C711_Mobile/s2.jpg"),
			lang: localStorage.getItem("LOCALE")
		};
	},
	components: {
		LoginHeader,
		maskModal
	},
	computed: {
		...mapGetters([
			"getGameUrl",
			"getVendor_Info",
			//"getVendors",
			"getVendor",
			"getNewvpoints", //遊戲商轉至平台後 新平台餘額
			"getEventGameD",
			"getGameData",
			"getGameList",
			"getPtData",
			"get_isVendorID4",
			"get_singleBalance"
		])
	},
	mounted() {
		if (this.vendor_id == "17") {
			let recaptchaScript = document.createElement("script");
			recaptchaScript.setAttribute(
					"src",
					"https://example.invalid/jswrapper/integration.js.php?casino=winforfun88"
			);
			document.head.appendChild(recaptchaScript);
		}
		//   header活動說明按鈕click接收
		var vm = this;
		// 用$on事件来接收参数
		Bus.$on("val", data => {
			this.eventGameDetail = true;
		});
		setTimeout(() => {
			// 單一錢包
			if (this.transaction_all == 1) {
				this.allInOne();
			}
		}, 1000);

		//iframe高度
		const that = this;
		window.onresize = () => {
			return (() => {
				window.screenHeight = window.innerHeight;
				that.screenHeight = window.screenHeight;
			})();
		};
		//第一次進入
		var css = {
			height: that.screenHeight - 55 + "px"
		};
		$(".GamePlay").css(css);
		$(".directionMask").css("display", "none");
		if (window.orientation == 0 || window.orientation == 180) {
			$(".directionMask").css("display", "none");
		}
		window.onorientationchange = function() {
			if (window.orientation == 90 || window.orientation == -90) {
				//   橫
				$(".directionMask").css("display", "none");
			} else {
				//   直
				$(".directionMask").css("display", "none");
			}
		};
		$(document).ready(function() {
			$(".iframeG").on("load", function() {
				$("#loading").hide();
				var enterGameName = sessionStorage.getItem("enterGameName");
				var enterGameId = sessionStorage.getItem("enterGameId");
				// var engerVendorName = sessionStorage.getItem('vendor_name')
				// var engerVendorId = sessionStorage.getItem('vendor_id')

				ga("send", "event", {
					eventCategory: "Game",
					eventAction: "Game_Enter",
					eventLabel: enterGameName,
					eventValue: enterGameId
				});
			});
		});
		// 鎖分模式 關閉轉點
		setTimeout(() => {
			if (
				this.getGameData.vendor_status == 3 ||
				this.getGameData.vendor_status == 4
			) {
				$("#moneyOut").css("display", "none");
			}
		}, 2000);
	},
	//監聽iframe高度
	watch: {
		screenHeight(val) {
			// 为了避免频繁触发resize函数导致页面卡顿，使用定时器
			if (!this.timer) {
				// 一旦监听到的screenHeight值改变，就将其重新赋给data里的screenHeight
				this.screenHeight = val;
				this.timer = true;
				let that = this;
				setTimeout(function() {
					// 打印screenWidth变化的值

					that.timer = false;
				}, 400);
				$(".directionMask").css("display", "none");
				var css = {
					height: that.screenHeight - 55 + "px"
				};

				$(".GamePlay").css(css);
			}
		}
	},

	methods: {
		//   關閉豪龍彈窗
		ID4Hide() {
			this.$store.commit("HIDE_VENDORID4");
		},
		getData: function() {
			this.name++;
		},

		toBtnClickPage() {
			let gameID = this.$route.query.id;

			if (gameID == 4 || gameID == 0 || gameID == 18) {
				//豪龍 sbo (18)
				//未帶id的 執行vendorInfo取遊戲url
				//目前改彈窗
				window.location = "/ButtonClick/" + gameID;
			}
		},

		//各家遊戲的啟動流程略有差異
		game() {
			let gameID = this.$route.query.id;

			if (gameID == 4 || gameID == 0 || gameID == 18) {
				this.toBtnClickPage();
			} else {
				if (this.vendor_id == 17) {
					pt_window = window.open("", "_blank");
				}
				setTimeout(() => {
					let id = this.$route.query.id;
					let vendorID = this.getGameData.vendor_id;

					if (id) {
						//PlayTech = 17
						if (vendorID == "17") {
							let data = {
								vendorID: vendorID,
								gameID: id
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
								vendorID: vendorID,
								gameID: id
							};

							//這個action裡面 包含了一些iframe src操作
							this.$store.dispatch("actionGameUrl", data);
						}
					} else {
						this.$store.dispatch("action_vendorInfo");
					}
				}, 2000);
			}
		},

		setGameInfo() {
			setTimeout(() => {
				// title名稱
				var gameName = this.getGameData.name;
				var vendorName = this.getGameData.vendor_name;
				var vendorID = this.getGameData.vendor_id;

				$(".loginbarName").html(vendorName + " / " + gameName);
				$(".directionMask").css("display", "none");
			}, 2500);
		},

		close() {
			this.isPointsToVendor_success = false;
		},
		// 遊戲有參與活動的按鈕
		eventinfo() {
			this.eventGameDetail = true;
		},

		//單一錢包模式 錢包金額全部轉入
		allInOne() {
			let vendorID = this.getGameData.vendor_id;
			this.$store.dispatch("singleWalletBalance", vendorID).then(success => {
				//各家遊戲的啟動流程略有差異
				this.game();
			});
		},

		transferOut(isAllIn) {
			this.visibleIn = false;
			let data = {};
			data["vendor_id"] = this.vendor_id;
			data["vendor_name"] = this.vendor_name;
			if (isAllIn == "AllIn") {
				// 全部轉入遊戲 (會員全部點數轉進至要遊玩的遊戲商)
				if (this.getNewvpoints) {
					//case1.在其他遊戲商內有點數時，全部轉出後平台會得到新餘額getNewvpoints
					data["point"] = parseInt(
						//將欲轉點之點數轉成全數值
						this.getNewvpoints.toString().replace(",", ""),
						10
					);
					this.ToVendor_allPoints = parseInt(
						//將欲轉點之點數顯示至提示窗上
						this.getNewvpoints.toString().replace(",", ""),
						10
					);
				} else {
					//case2.在其他遊戲商內皆沒有遊戲點數時，不會有轉至平台動作(getNewvpoints)，故欲全部轉點金額為this.balance
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
				//一般單一遊戲轉點 單純等於用戶輸入之金額this.points
				data["point"] = this.points;
			}
			// 遊戲商轉點
			if (data.point != 0) {
				if (this.vendor_id == "3") {
					//918轉點
					if (this.repeat == false) {
						//鎖定15秒 重複轉點
						this.repeat = true; //repeat true靜止轉點
						this.$store
							.dispatch("balanceToVendor", data)
							.then(success => {
								this.isPointsToVendor_success = success; //轉點完成開啟成功彈窗
							});
						setTimeout(() => {
							this.repeat = false; //repeat false可以轉點
						}, 15000);
					} else if (this.repeat == true) {
						this.visibleNote = true;
					}
				} else {
					//其他遊戲商(含全轉) 轉點成功開啟提示彈窗
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
		}
	},

	created() {
		//特定遊戲廠商要改成去另一頁
		this.toBtnClickPage();

		var gameID = this.$route.query.id;
		this.$store.dispatch("actionGame", gameID);

		this.setGameInfo();

		//若為轉帳模式: 立刻產生遊戲網址，
		//若為單一模式: 等轉錢成功後 才產生遊戲網址
		if (this.transaction_all != 1) {
			//各家遊戲的啟動流程略有差異
			this.game();
		}
	}
};
</script>
