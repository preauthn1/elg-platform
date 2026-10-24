<template>
	<div id="C711Mobile">
		<div class="CwidthHeight widthHeightvh fontFamily scrollBar">
			<div class="pointHeight">
				<PointsHeader ref="PointsHeader"></PointsHeader>
			</div>
			<div class="LobbyBg LobbySetting">
				<div class>
					<div class>
						<!-- <div class="fixBg">
							<img src="../../assets/img/C711_Mobile/gamelistTitleBG1.png" />
						</div>-->

						<div class="gameCategoryWrapper">
							<div class="filterCard">
								<img />

								<img
									class="filterIcon"
									:src="getImageFilePath('all' , '0')"
									@click="changeGameTypeFilter(0)"
								/>
								<div>All</div>
							</div>

							<div class="filterCard" v-for="(gameType, i) in getAvailableGameTypes" :key="i">
								<img
									class="filterIcon"
									:src="getImageFilePath(gameType.keyName , gameType.id)"
									@click="changeGameTypeFilter(gameType.id)"
								/>
								<div>{{geti18nTextByKey(gameType.i18nKey)}}</div>
							</div>
						</div>
					</div>
				</div>

				<div class="GameItemAll">
					<div class="gameCardWrapper">
						<div class="gameCard" v-for="(gameItem,i) in getSortGames" :key="i">
							<div
								:class="'imgBG' +  (gameItem.disable==1?'disGray':'')"
								@click="gameItem.disable==1?'':playGame(gameItem)"
							>
								<img
									src="../../assets/img/C711_Mobile/NewBtn.png"
									class="newTag"
									v-if="gameItem.is_new == 1"
								/>
								<img
									src="../../assets/img/C711_Mobile/HotBtn.png"
									class="hotTag"
									v-if="gameItem.is_hot == 1"
								/>

								<img
									src="../../assets/img/C711_Mobile/Gift.png"
									class="Gift"
									v-if="gameItem.event_bet||gameItem.event_win"
								/>

								<img :src="gameItem.image" class="gameImg" />
							</div>
							<div class="gameNameText">{{gameItem.name}}</div>
						</div>
					</div>
				</div>
			</div>

			<!-- 彈窗 -->
			<div class="mask" v-if="comfirmVisible">
				<div class="Popups_Modal">
					<div class="PopupsTitle">{{ $t("prompt") }}</div>
					<div class="PopupsConAll">
						<div class="PopupsCon popupsConFont">{{ $t("areYouSureToEnterTheGame") }}</div>
						<div class="PopupsBtn clearfix">
							<div class="float-left">
								<div class="btn-blueTwo">
									<button type="submit" @click="comfirm" style="padding:9px auto;">{{ $t("confirm") }}</button>
								</div>
							</div>
							<div class="float-right">
								<div class="btn-orangeTwo">
									<button type="submit" @click="cancel" style="padding:9px auto;">{{ $t("cancel") }}</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<maskModal></maskModal>
		</div>
	</div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import PointsHeader from "@/components/C711_Mobile/Points_header";
import Banner from "@/components/C711_Mobile/Banner";
import maskModal from "./Modal";
import { setTimeout, clearTimeout } from "timers";
import { async, Promise } from "q";
import value_utils from "@/utils/value_utils";

function playSound() {
	var sound = document.getElementById("audio");
	sound.play();
}
export default {
	components: {
		PointsHeader,
		maskModal
	},
	computed: {
		...mapGetters([
			"getGameList",
			"getVendor_balance",
			"getNew_VendorBalance",
			"getVendor_Info",
			"get_EventsGames",
			"getVendor"
		]),
		getBalance() {
			let vendorPoints = this.getNew_VendorBalance
				? this.getNew_VendorBalance
				: this.getVendor_balance;
			return vendorPoints;
		},

		getAvailableGameTypes() {
			let items = this.getGameList.slice();

			var typeValues = value_utils.SelectUniqueValues(items, "type_id");

			if (typeValues == null || typeValues.length == 0) return [];

			let myResult = this.gameTypes.filter(item =>
				typeValues.includes(item.id)
			);

			return myResult;
		},

		isSingleWalletMode() {
			return this.transaction_all == 1;
		},

		gameTypes() {
			return this.$enums.gameTypeList;
		},

		getSortGames() {
			function compare(itemA, itemB) {
				let scoreA = (itemA.is_hot ? -4 : 0) + (itemA.is_new ? -2 : 0);
				let scoreB = (itemB.is_hot ? -4 : 0) + (itemB.is_new ? -2 : 0);
				return scoreA - scoreB;
			}

			let items = this.getGameList.slice().sort(compare);
			let filterGameType = this.currentSelectedGameType;

			if (this.currentSelectedGameType == 0) return items;

			//過濾篩選gameType
			let result = items
				.filter(item => item.type_id == filterGameType)
				.sort(compare);

			return result;
		}
	},
	watch: {
		// 即時搜尋 監聽searchKey輸入停止後 0.6秒才執行"searchGame()"，實現input連續輸入，只發一次請求
		searchKey() {
			clearTimeout(this.timeout);
			this.timeout = setTimeout(() => {
				this.searchGame();
			}, 600);
		}
	},
	data() {
		return {
			template: window.sessionStorage.getItem("Template"),
			vWorld_balance: window.sessionStorage.getItem("balance"),
			transaction_all: sessionStorage.getItem("transaction_all"),
			comfirmVisible: false,
			hide: true,
			// gamelist data
			gameId: "",
			// 搜尋遊戲
			searchKey: "",
			timeout: null,
			currentSelectedGameType: 0,
			//deposit data
			vendor_name: "遊戲商",
			vendorId: "",
			depositVal: 0,
			transferPoint: 0,
			depositVisible: false,
			isNormal_deposit: true,
			transferInOut: "",
			vendorNumber: this.$route.params.vendorID,
			vendorName: sessionStorage.getItem("vendor_name"),
			vendorId: this.$route.params.vendorID,

			eventBarID: this.$route.params.id,
			routerVendorID: this.$route.params.vendorID,
			eventOpenGame: this.$route.params.eventOpenGame,

			swiperOption: {
				slidesPerView: 3, //一行顯示3個
				slidesPerColumn: 1, //顯示3行
				spaceBetween: 5, //在slide之間設置距離(px)
				slidesPerColumnFill: "row", //多行佈局中以什麼形式填充列（column）和行（row）。類型：string，默認：column。
				grabCursor: true, // 游標為手掌
				slidesOffsetBefore: 0, //設定slide與左邊框的預設偏移量（單位px）
				slidesOffsetAfter: 100, //設定slide與右邊框的預設偏移量（單位px）

				pagination: {
					el: ".swiper-pagination", //分頁點
					clickable: true
				}
			},

			swiperOptionHotNew: {
				slidesPerView: 2.2, //一行顯示3個
				slidesPerColumn: 1, //顯示3行
				spaceBetween: 0, //在slide之間設置距離(px)
				slidesPerColumnFill: "row", //多行佈局中以什麼形式填充列（column）和行（row）。類型：string，默認：column。
				grabCursor: true, // 游標為手掌
				slidesOffsetBefore: 10, //設定slide與左邊框的預設偏移量（單位px）
				slidesOffsetAfter: 30, //設定slide與右邊框的預設偏移量（單位px）

				pagination: {
					el: ".swiper-pagination", //分頁點
					clickable: true
				}
			},
			swiperOptionItem: {
				slidesPerView: 3.8, //一行顯示3個
				slidesPerColumn: 2, //顯示3行
				spaceBetween: 2, //在slide之間設置距離(px)
				slidesPerColumnFill: "column", //多行佈局中以什麼形式填充列（column）和行（row）。類型：string，默認：column。
				grabCursor: true, // 游標為手掌
				slidesOffsetBefore: 10, //設定slide與左邊框的預設偏移量（單位px）
				slidesOffsetAfter: 30, //設定slide與右邊框的預設偏移量（單位px）

				pagination: {
					el: ".swiper-pagination", //分頁點
					clickable: true
				}
			}
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
		// 畫面進入全選
		$("#type0").removeClass("gameListBg");
		$("#type0").addClass("gameListBg_s");

		//需求方提出希望在此頁即自動錢包轉帳
		this.singleWalletAllInOne();
	},
	created() {
		this.depositVal = Math.round(
			parseInt(this.vWorld_balance.toString().replace(",", ""), 10) / 2
		);

		let vendorID = this.$route.params.vendorID;

		//PC版切換至手機時 router 帶逗號 轉跳LOBBY
		if (vendorID != null) {
			if (vendorID.indexOf(",") == 1) {
				this.$router.push("/");
			}
		}

		if (this.eventOpenGame == 1) {
			let listData = {
				detail_id: this.eventBarID,
				page: 1,
				per_page: 30
			};
			sessionStorage.setItem("vendor_id", this.routerVendorID);
			this.$store.dispatch("action_EventGames", listData);
		} else {
			this.getGamelist();
		}

		this.getVendorData();
		this.getVendorBalance();
		this.$store.dispatch(
			"action_favorite_Length",
			this.$route.params.vendorID
		);
	},
	methods: {
		changeGameTypeFilter(targetGameType) {
			this.currentSelectedGameType = targetGameType;

			if (targetGameType == 0) {
				targetGameType = null;
			}

			//this.getGamelist(targetGameType);
		},

		getImageFilePath(gameTypeKey, myKey) {
			let highLightSuffix =
				myKey == this.currentSelectedGameType ? "_highLight" : "";

			let filePath =
				"../../../static/img/C711_Mobile/icon/game_icon_filter_" +
				gameTypeKey.toLowerCase() +
				highLightSuffix +
				".png";

			return filePath;
		},
		geti18nTextByKey(key) {
			return this.$i18n.t(key);
		},

		// 進遊戲
		playGame(list) {
			this.gameId = list.id;
			this.gameName = list.name;
			this.vendorId = list.vendor_id;

			this.$store
				.dispatch("actionOne_balance", this.vendorId)
				.then(balance => {
					//查詢欲遊玩的遊戲商餘額，成功then回傳遊戲商餘額balance
					let vendor_balance = parseInt(
						balance.toString().replace(",", ""),
						10
					);
					if (vendor_balance < 0) {
						//判斷遊戲商餘額沒錢，開啟轉點彈窗轉點(此功能現正關閉中，故vendor_balance < 0)
						this.depositVisible = true;
						this.isNormal_deposit = false;
						this.vendor_name = list.vendor_name;
					} else {
						//判斷遊戲商餘額有足夠點數，及進入/Game遊玩遊戲
						//   this.comfirmVisible = true
						this.$router.push("/Game?id=" + this.gameId);
						ga("send", "event", {
							eventCategory: "GameList",
							eventAction: "Game_Click",
							eventLabel: this.gameName,
							eventValue: this.gameId
						});

						var sound = document.getElementById("audio");
					}
				});
		},

		singleWalletAllInOne() {
			if (!this.isSingleWalletMode) {
				return;
			}

			let vendorID = this.routerVendorID;

			//單一錢包自動轉帳
			this.$store
				.dispatch("singleWalletBalance", vendorID)
				.then(success => {});
		},

		// 取單支遊戲商餘額
		getVendorBalance() {
			let vendor_id = this.$route.params.vendorID;

			if (vendor_id !== "") {
				this.$store
					.dispatch("actionOne_balance", vendor_id)
					.then(balance => {
						return balance;
					});
			} else {
				this.hide = false;
				setTimeout(() => {
					$(".GameListMenu").css("height", 50 + "px");
					$(".GameListItem").css("padding-top", 50 + "px");
				}, 100);
			}
		},
		pointTransfer(transferInOut) {
			this.vWorld_balance = window.sessionStorage.getItem("balance");

			this.transferInOut = transferInOut;
			this.vendor_name = sessionStorage.getItem("vendor_name");
			this.depositVisible = true;
			this.isNormal_deposit = true;
			if (this.transferInOut == 2) {
				var transferType = "轉出按鈕";
			} else {
				var transferType = "轉入按鈕";
			}
			ga("send", "event", {
				eventCategory: "GameList",
				eventAction: "transferBtn_Click",
				eventLabel: transferType,
				eventValue: this.transferInOut
			});
		},
		comfirm() {
			this.comfirmVisible = false;
			this.$router.push("/Game?id=" + this.gameId);
			ga("send", "event", {
				eventCategory: "GameList",
				eventAction: "Game_Click",
				eventLabel: this.gameName,
				eventValue: this.gameId
			});

			var sound = document.getElementById("audio");
			sound.play();
		},
		cancel() {
			this.comfirmVisible = false;
			var sound = document.getElementById("audio");
			sound.play();
		},
		close() {
			this.depositVisible = false;
		},

		// 遊戲列表 //
		getGamelist(data) {
			$(".removebg").removeClass("gameListBg_s");
			$(".removebg").addClass("gameListBg");
			$("#type" + data).addClass("gameListBg_s");

			this.searchKey = "";
			if (data == undefined || data == 0) {
				sessionStorage.setItem("type_id", "");
			}
			sessionStorage.setItem("personal", "");

			let listData = {
				vendor_id: this.$route.params.vendorID,
				type_id: sessionStorage.getItem("type_id"),
				key: "",
				page: 1,
				per_page: 500
			};

			if (data) {
				// have type
				sessionStorage.setItem("type_id", data);
				listData.type_id = data;
			}

			this.$store.dispatch("actionGamelist", listData);
		},
		// 取遊戲商自己的資料
		getVendorData() {
			var vendorID = this.$route.params.vendorID;

			this.$store.dispatch("actionVendor", vendorID);
			//   狀態3 狀態4 隱藏上方轉點按鈕
			if (this.getVendor.status == 3 || this.getVendor.status == 4) {
				$("#moneyOutBtn").css("display", "none");
			}

			setTimeout(() => {
				let name = this.getVendor.name;
				this.$refs.PointsHeader.changeTitleText(name);
			}, 1500);
		},

		/// 餘額不足轉點 //
		inputVal() {
			this.depositVal = parseInt(this.depositVal, 10);
			let vPoints = parseInt(
				this.vWorld_balance.toString().replace(",", ""),
				10
			);
			if (this.depositVal >= vPoints) {
				this.depositVal = vPoints;
			} else if (!this.depositVal) {
				this.depositVal = 0;
			}
		},
		//轉出input
		inputtransferVal() {
			this.transferPoint = parseInt(this.transferPoint, 10);
			let getPoints = parseInt(
				this.getBalance.toString().replace(",", ""),
				10
			);
			if (this.transferPoint >= getPoints) {
				this.transferPoint = getPoints;
			} else if (!this.transferPoint) {
				this.transferPoint = 0;
			}
		},
		// 遊戲餘額不足，點數轉入
		deposit() {
			let data = {};
			data["isDeposit"] = true;
			data["vendor_id"] = this.vendorId;
			data["point"] = this.depositVal;
			data["game_id"] = this.gameId;
			this.$store.dispatch("balanceToVendor", data);
			this.depositVisible = false;
		},
		// 一般轉點
		normal_deposit() {
			let data = {};
			data["isDeposit"] = false;
			//   data['vendor_id'] = sessionStorage.getItem('vendor_id')
			data["vendor_id"] = this.$route.params.vendorID;
			data["point"] = this.depositVal;
			this.$store.dispatch("balanceToVendor", data);
			this.depositVisible = false;
		},
		//點數轉出
		transferOut() {
			let data = {};
			//   data['vendor_id'] = sessionStorage.getItem('vendor_id')
			data["vendor_id"] = this.$route.params.vendorID;
			data["point"] = this.transferPoint;
			this.$store.dispatch("vendorToBalance", data);
			this.depositVisible = false;
			this.vWorld_balance = window.sessionStorage.getItem("balance");
		},

		openapp() {
			// //判斷IOS ANDROID
			let vm = this;
			$(function() {
				var u = navigator.userAgent,
					app = navigator.appVersion;
				var isAndroid =
					u.indexOf("Android") > -1 || u.indexOf("Linux") > -1; //g
				var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/);
				if (isAndroid) {
					vm.Android();
				}
			});
		},
		Android() {
			this.GOApp(this.getVendor_Info.android_open);
		},
		GOApp(scheme_url, timeout = 3000) {
			let startTime = Date.now();
		}
	}
};
</script>
<style scoped>
.swiper {
	height: 100%;
	/* position: relative; */
	/* border: 2px solid red; */
}

.swiper-wrapper {
	margin: 0 !important;
}
.swiper-container {
	height: auto !important;
	margin-left: auto;
	margin-right: auto;
	overflow: inherit;
}
.swiper-container-horizontal > .swiper-pagination-bullets {
	bottom: -25px;
}
.swiperPic {
	width: 95%;
	max-height: 100%;
}
.swiper-slide {
	height: 100%;
	width: auto;
}

.swiper-slide:nth-child(2n) {
	width: 40%;
}
.swiper-slide:nth-child(3n) {
	width: 20%;
}

.LobbySetting {
	overflow-y: auto;
	padding-bottom: 60px !important;
}
</style>