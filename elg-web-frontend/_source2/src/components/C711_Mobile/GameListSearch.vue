<template>
	<div id="C711Mobile">
		<div class="CwidthHeight widthHeightvh fontFamily scrollBar">
			<div class="pointHeight">
				<PointsHeader></PointsHeader>
			</div>
			<div class="LobbyBg LobbySetting">
				<div class>
					<div class>
						<div class="golden-search">
							<div class="golden-input">
								<div class="left">
									<input type="text" class="hideInput" v-model="searchKey" />
								</div>
								<div class="right">
									<img class src="../../assets/img/C711_Mobile/icon/game_list_search.png" />
								</div>
							</div>
						</div>

						<div class="filter-lable">{{$t("Common_Label_QuickFilter")}}</div>

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

							<div class="filterCard" v-for="(gameType, i) in gameTypes" :key="i">
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
						<div class="gameCard searchPage" v-for="(gameItem,i) in getSortGames" :key="i">
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
								<!-- 
								<img
									src="../../assets/img/C711_Mobile/Gift.png"
									class="Gift"
									v-if="gameItem.event_bet||gameItem.event_win"
								/>-->

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
			"getVendor"
		]),
		getBalance() {
			let vendorPoints = this.getNew_VendorBalance
				? this.getNew_VendorBalance
				: this.getVendor_balance;
			return vendorPoints;
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
			let myKeyword = this.searchKey.toLowerCase();

			let isFilterType = this.currentSelectedGameType != 0;
			let isKeyword =
				myKeyword != null && myKeyword != "" && myKeyword != " ";

			//過濾篩選gameType
			let result = items
				.filter(item =>
					isFilterType ? item.type_id == filterGameType : true
				)
				.filter(item =>
					isKeyword ? item.name.toLowerCase().includes(myKeyword) : true
				)
				.sort(compare);

			return result;
		}
	},
	watch: {
		// 即時搜尋 監聽searchKey輸入停止後 0.6秒才執行"searchGame()"，實現input連續輸入，只發一次請求
		// searchKey() {
		// 	clearTimeout(this.timeout);
		// 	this.timeout = setTimeout(() => {
		// 		this.$forceUpdate();
		// 		console.log("forceUpdate");
		// 		this.gameList = this.getGameList;
		// 		//this.changeGameTypeFilter(this.currentSelectedGameType);
		// 	}, 600);
		// }
	},
	data() {
		return {
			template: window.sessionStorage.getItem("Template"),
			transaction_all: sessionStorage.getItem("transaction_all"),
			comfirmVisible: false,
			hide: true,

			gameId: "",
			gameName: "",
			searchKey: "",
			gameList: this.getGameList,
			timeout: null,
			currentSelectedGameType: 0,
			eventBarID: this.$route.params.id,
			routerVendorID: this.$route.params.vendorID,
			eventOpenGame: this.$route.params.eventOpenGame
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
	},
	created() {
		let vendorID = this.$route.params.vendorID;

		this.getGamelist();
	},
	methods: {
		changeGameTypeFilter(targetGameType) {
			this.currentSelectedGameType = targetGameType;

			if (targetGameType == 0) {
				targetGameType = null;
			}
		},

		reloadGames() {
			let items = this.getGameList.slice().sort(compare);
			let filterGameType = this.currentSelectedGameType;
			let myKeyword = this.searchKey;

			if (this.currentSelectedGameType == 0) return items;

			let isKeyword =
				myKeyword != null && myKeyword != "" && myKeyword != " ";

			//過濾篩選gameType
			let result = items
				.filter(item => item.type_id == filterGameType)
				.filter(item =>
					isKeyword ? item.name.indexOf(myKeyword) > 0 : true
				)
				.sort(compare);

			return result;
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
					this.$router.push("/Game?id=" + this.gameId);
					ga("send", "event", {
						eventCategory: "GameList",
						eventAction: "Game_Click",
						eventLabel: this.gameName,
						eventValue: this.gameId
					});

					var sound = document.getElementById("audio");
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
				vendor_id: "",
				type_id: sessionStorage.getItem("type_id"),
				key: "",
				page: 1,
				per_page: 2500
			};
			if (data) {
				// have type
				sessionStorage.setItem("type_id", data);
				listData.type_id = data;
			}

			this.$store.dispatch("actionGamelist", listData);
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
.LobbySetting {
	overflow-y: auto;
	padding-bottom: 60px !important;
	margin-top: -5px !important;
}

.gameCategoryWrapper {
	padding-top: 8px !important;
}
</style>