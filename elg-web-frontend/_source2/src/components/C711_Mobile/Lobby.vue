<template>
	<div id="C711Mobile">
		<div class="widthHeightvh CwidthHeight fontFamily scrollBar">
			<div class="pointHeight">
				<PointsHeader></PointsHeader>
			</div>
			<div class="LobbyBg LobbySetting">
				<div class="CLobbyContent clearfix">
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

					<div class="swiperPadding">
						<!-- 往下排 -->
						<div class="swiper clearfix">
							<div v-for="(vendors, i) in getAvailableVendors" :key="i">
								<div style="clear:both" v-if="i % 3 == 0"></div>
								<div id="noSwiper" class="float-left">
									<div class="GameIconAll">
										<img
											class="GameIconBg"
											v-if="isDisabledStatus(vendors)"
											src="../../assets/img/C711_Mobile/lobbyGameBgG.png"
										/>
										<img
											class="GameIconBg"
											v-if="!isDisabledStatus(vendors)"
											src="../../assets/img/C711_Mobile/bg/home_vendor_icon-bg.png"
										/>

										<div class="GameIcon" v-if="vendors.status == 1">
											<a @click="gameList(vendors);gameEnter(vendors);">
												<img
													v-if="vendors.is_new == 1"
													src="../../assets/img/C711_Mobile/NewBtn.png"
													class="newHotPic newPic"
												/>
												<img
													v-if="vendors.is_hot == 1"
													src="../../assets/img/C711_Mobile/HotBtn.png"
													class="newHotPic hotPic"
												/>
												<img :src="vendors.image" class="gameImage" />
											</a>
										</div>
										<!-- COMING SOON -->
										<div class="GameIcon" v-if="vendors.status == 2">
											<img src="../../assets/img/C711_Mobile/comingsoon2.png" class="newHotPic maintain" />

											<img :src="vendors.image" class="gameImage disPic disGray" />
										</div>
										<!-- 維護中 -->
										<div class="GameIcon" v-if="vendors.status == 3 && vendors.maintenance_end_time !== null">
											<img src="../../assets/img/C711_Mobile/maintain25.png" class="newHotPic maintain" />
											<div class="maintainfont">
												<span>{{vendors.maintenance_end_time.substring(5,16)}}+08</span>
											</div>

											<img :src="vendors.image" class="gameImage disPic disGray" />
										</div>
										<!-- 鎖分活動 -->
										<div class="GameIcon" v-if="vendors.status == 4" @click="tips()">
											<img src="../../assets/img/C711_Mobile/lock.png" class="lockPic" />
											<img :src="vendors.image" class="gameImage disPic disGray" />
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<!-- 彈窗 -->
			<maskModal></maskModal>
		</div>

		<!-- 全版廣告 v-if="fullAD"-->
		<div class="mask" id="fullAD" v-show="fullAD">
			<div class="Popups_full" style="width:95%;">
				<!--<span style>{{ $t("clickToEnterTheEvent") }}</span>-->
				<img
					src="../../assets/img/C711_Mobile/modalClose.png"
					class="closead"
					style="top:0px;"
					@click="closeAD()"
				/>

				<div v-for="(ad, i) in getBanner4" :key="i">
					<!--<a :href="ad.url">-->
					<img :src="ad.image" class="fullad" />
					<!--</a>-->
				</div>
			</div>
		</div>

		<!-- 鎖分活動彈窗 -->

		<div class="popUp_block" v-if="get_checkLoginEvent" style="overflow-y:scroll;">
			<div class="wrap">
				<div class="single_block">
					<div class="case_wrapIframe">
						<div>
							<div class="title">
								{{ $t("eventsContent") }}
								<img
									@click="modeModal()"
									class="close_icon"
									src="../../../static/img/C711_PC/Group5301.png"
								/>
							</div>
							<div class="form">
								<div class="orangeTitle" v-if="get_checkEvent.start_time&&get_checkEvent.end_time">
									<div class="formTitle">{{$t('duringTheEvent')}}:</div>
									{{get_checkEvent.start_time.substring(0, 16)}} ~ {{get_checkEvent.end_time.substring(0, 16)}}
								</div>
								<div class="orangeTitle">
									<div class="formTitle">{{$t('participateQualification')}}:</div>
									<div>1.{{$t('newMemberCreated')}}</div>
									<div>2.{{$t('existingMembers')}}</div>
									<div class="formTitle">{{$t('eventRules')}}:</div>
									{{$t('youWillReceive')}}{{get_checkEvent.point}}{{$t('point')}}，{{$t('afterYouAgree')}}
									<br />
									<span>{{$t('theseAreTheCertain')}}</span>
									<br />
									1.{{$t('certainGamesAre')}}
									<br />
									2.{{$t('cannotOpenWithdrawal')}}
									<br />
									3.{{$t('connotJoinOrDelete')}}
								</div>
								<div class="orangeTitle">
									<div class="formTitle">{{$t('youCanUnlock')}}</div>
									<div>1.{{$t('balanceIsLesser')}}</div>
									<div>2.{{$t('balanceIsMoreThen')}}{{get_checkEvent.point*get_checkEvent.times}}{{$t('point')}} {{$t('canKeepAll')}}</div>
								</div>
								<div class="orangeTitle">{{$t('youCanClickTheUnlock')}}</div>
								<div class="redFont">* {{$t('attentionIfYou')}}</div>
								<div class="orangeTitle">
									{{$t('unlockButtonSign')}}&nbsp;
									<img src="../../assets/img/C711_Mobile/lockBTN.png" />
								</div>
							</div>
							<div class="footer clearfix" style="width:90%;margin:auto;" v-if="EventMode==0">
								<div class="btn-blue float-left" style="margin:5px; width:120px;">
									<button @click="joinEventMode()">{{$t('join')}}</button>
								</div>
								<div class="btn-orange float-right" style="margin:5px; width:120px;">
									<button @click="modeModal()">{{$t('notJoin')}}</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<!-- 模式中 反灰遊戲商提醒彈窗 "-->
		<div class="popUp_block" v-if="tipsModal">
			<div class="wrap" style="top:20%;">
				<div class="single_block">
					<div class="case_wrapIframe">
						<div>
							<div class="title">
								{{$t('eventModePrompt')}}
								<img
									@click="tipsClose()"
									class="close_icon"
									src="../../../static/img/C711_PC/Group5301.png"
								/>
							</div>
							<div class="form">
								<div class="orangeTitle" style="margin:20px auto;">
									<!-- <div class="redFont">強制解鎖訊息：</div> -->
									<div class="forceFont">
										{{$t('youAreCurrentlyIn')}}
										{{$t('pleaseClickOnUnlock')}}
									</div>
									<div style="margin:5px auto; ">
										<div style="margin:5px auto;" class="orcolor">{{$t('eventDescriptionButton')}}</div>
										<div class="yellow_borderInput abreastBtn">
											<a href="/EventMode">
												<button type="button" class="form-control black_InputAll fontsizeBtn blue_BtnBg">
													<span style="color:#fff;">{{ $t("eventDescription") }}</span>
												</button>
											</a>
										</div>
									</div>
									<div style="margin:5px auto; text-align:center;">
										<div style="margin:5px auto; text-align:left;" class="orcolor">{{$t('unlockButton')}}</div>
										<img src="../../assets/img/C711_Mobile/lockBTN.png" />
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import AddOrUpdate from "../C711_Mobile/Events";
import "swiper/dist/css/swiper.css";
// eslint-disable-next-line no-unused-vars
import PointsHeader from "@/components/C711_Mobile/Points_header";
import Banner from "@/components/C711_Mobile/Banner";
import Marquee from "@/components/C711_Mobile/Marquee";
import { swiper, swiperSlide } from "vue-awesome-swiper";
import maskModal from "./Modal";
import value_utils from "@/utils/value_utils";

export default {
	computed: {
		...mapGetters([
			"getVendors",
			"getLobby_Vendors",
			"getBanner",
			"getBanner4",
			"get_checkLoginEvent",
			"get_checkEvent",
			"getVendor_gameTypes"
		]),

		gameTypes() {
			return this.$enums.gameTypeList;
		},

		getAvailableVendors() {
			if (this.currentSelectedGameType == 0) return this.getLobby_Vendors;

			if (this.filterVendorIDs == null || this.filterVendorIDs.length == 0)
				return [];

			let ids = this.filterVendorIDs;
			let result = this.getLobby_Vendors.filter(item =>
				ids.includes(item.id)
			);

			return result;
		}
	},
	components: {
		PointsHeader,
		swiper,
		swiperSlide,
		maskModal,
		Banner,
		Marquee
	},
	data() {
		return {
			template: sessionStorage.getItem("Template"),
			vWorld_balance: sessionStorage.getItem("balance"),
			vendorName: "",
			depositVisible: false,
			depositVal: 0,
			Username: window.sessionStorage.getItem("username"),
			Nickname: window.sessionStorage.getItem("nickname"),
			balance: window.sessionStorage.getItem("balance"),
			Avatar: window.sessionStorage.getItem("avatar"),
			EventMode: window.sessionStorage.getItem("eventMode"),
			PC_MOBILE: sessionStorage.getItem("PC_MOBILE"),
			has_app: sessionStorage.getItem("has_app"),
			has_mobile: sessionStorage.getItem("has_mobile"),
			DefaultAvatar: require("../../assets/img/C711_Mobile/user.jpg"),
			currentSelectedGameType: "0",
			filterVendorIDs: [],
			//   鎖分活動
			eventModeModal: false,
			//   翻灰遊戲商點擊提醒
			tipsModal: false,
			//   全版廣告
			fullAD: false,
			fullWidth: 0,
			fullHeight: 0,
			swiperOption: {
				notNextTick: true,
				// loop: true,
				// direction: 'horizontal',
				initialSlide: 0, // 设定初始化时slide的索引。
				// effect: 'coverflow',
				// slidesPerView: 1, //一行顯示3個
				// slidesPerColumn: 1, //顯示幾行
				slidesPerView: 3, //一行顯示3個
				slidesPerColumn: 4, //顯示幾行
				spaceBetween: 5, //在slide之間設置距離(px)
				slidesPerGroup: 3, //默认：1
				slidesPerColumnFill: "row", //多行佈局中以什麼形式填充列（column）和行（row）。類型：string，默認：column。
				grabCursor: true, // 游標為手掌
				slidesOffsetBefore: 0, //設定slide與左邊框的預設偏移量（單位px）
				slidesOffsetAfter: 0, //設定slide與右邊框的預設偏移量（單位px）,
				//滑动之后回调函数
				on: {
					slideChangeTransitionEnd: function() {}
				},

				pagination: {
					el: ".swiper-pagination", //分頁點
					clickable: true
				}
			},

			ad_iframe: false
		};
	},
	mounted() {
		this.getVendorGameTypes();

		$(".bannerLobby").css("display", "none");

		$("#has_mobilieMask").css("display", "none");
		$("#has_pcMask").css("display", "none");
		if (this.PC_MOBILE == "MOBILE") {
			if (this.has_mobile == 0) {
				$("#has_mobilieMask").css("display", "block");
				$("#has_pcMask").css("display", "none");
			} else {
			}
		}

		$(".LobbyContent").css("display", "none");
		setTimeout(() => {
			$(".LobbyContent").css("display", "block");
		}, 1750);
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
		// 進入 打聊天室 has_chat 有無聊天室
		if (sessionStorage.getItem("has_chat") != 1) {
			if (
				sessionStorage.getItem("Chatapi_code") == undefined ||
				sessionStorage.getItem("Chatapi_code") == ""
			) {
				let chatMember = sessionStorage.getItem("chatMember");

				var sign_data = JSON.parse(chatMember);

				var post = {
					info: {},
					url: "api/member/self"
				};

				this.$store.dispatch("post", post).then(res => {
					if (res.data.code == 1000) {
						sessionStorage.setItem("Chatapi_code", res.data.code);
					} else {
						// alert(res.data.message);
					}
				});
			} else {
			}
		}

		setTimeout(() => {
			if (this.getBanner4.length != 0) {
				//console.log(this.getBanner4);
				var FullAD = $.cookie("FullAD");

				if (FullAD) {
					this.fullAD = false;
				} else {
					this.fullAD = true;
					$.cookie("FullAD", "has_FullAD", { expires: 0.2 });
				}
			}
		}, 1600);
	},

	methods: {
		getVendorGameTypes() {
			this.$store.dispatch("actionGetVendorGameTypes");
		},

		changeGameTypeFilter(targetGameType) {
			this.currentSelectedGameType = targetGameType;

			if (targetGameType == 0) {
				return;
			}

			let myGameTypes = this.getVendor_gameTypes.queryEachVendorGameGroup;

			let vendorIDs = [];

			$.each(myGameTypes, function(key, value) {
				if (value.root_type == targetGameType) {
					vendorIDs.push(value.vendor_id);
				}
			});

			this.filterVendorIDs = vendorIDs;
		},

		getImageFilePath(gameTypeKey, myKey) {
			let highLightSuffix =
				myKey == this.currentSelectedGameType ? "_highLight" : "";

			let filePath =
				"../../../static/img/C711_Mobile/icon/home_icon_filter_" +
				gameTypeKey.toLowerCase() +
				highLightSuffix +
				".png";

			return filePath;
		},

		geti18nTextByKey(key) {
			return this.$i18n.t(key);
		},

		isDisabledStatus(vendors) {
			return (
				vendors.status == 2 || vendors.status == 3 || vendors.status == 4
			);
		},

		gameList(vendor) {
			// let name = vendor.name //for app餘額不足彈窗遊戲商名稱,gamelist餘額遊戲商點遊戲playgame(list)帶出來
			sessionStorage.setItem("type_id", "");
			sessionStorage.setItem("vendor_id", vendor.id);
			sessionStorage.setItem("vendor_name", vendor.name);
			sessionStorage.setItem("vendor_balance", vendor.balance);
			sessionStorage.setItem("need_login", vendor.need_login);
			sessionStorage.setItem("only_one_game", vendor.only_one_game);
			ga("send", "event", {
				eventCategory: "Lobby",
				eventAction: "GameList_Click",
				eventLabel: vendor.name,
				eventValue: vendor.id
			});
			if (vendor.need_login == 0) {
				// need_login 是否需開app
				if (vendor.only_one_game == 1) {
					this.$store
						.dispatch("actionOne_balance", vendor.id)
						.then(balance => {
							let vendor_balance = parseInt(
								balance.toString().replace(",", ""),
								10
							);
							if (vendor_balance < 0) {
								this.vendorName = vendor.name;
								this.depositVal = Math.round(
									parseInt(
										this.vWorld_balance.toString().replace(",", ""),
										10
									) / 2
								);
								this.depositVisible = true;
							} else {
								// 目前只有豪龍 sbo

								this.$router.push("/Game?id=" + vendor.id);
								//   this.$store.dispatch('action_vendorInfo', vendor.id) // 取app ios android資訊
							}
						});
				} else {
					// 進列表
					this.$router.push("/GameList/" + vendor.id);
				}
			} else if (vendor.need_login == 1) {
				this.$router.push(
					"/GameApp?vendor=" + vendor.name + "&vendorID=" + vendor.id
				);
				// ?vendor= for gameApp title //
			}
		},
		//GA判斷用
		gameEnter(vendor) {
			if (vendor.only_one_game == 1) {
				var enterGameName = vendor.name;
				var enterGameId = vendor.id;
				sessionStorage.setItem("enterGameName", enterGameName);
				sessionStorage.setItem("enterGameId", enterGameId);
			}
		},
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
		deposit() {
			let data = {};
			data["isDeposit"] = true;
			data["vendor_id"] = sessionStorage.getItem("vendor_id");
			data["point"] = this.depositVal;
			//   data['game_id'] = this.game_id
			this.$store.dispatch("balanceToVendor", data);
			this.depositVisible = false;
		},
		closeAD() {
			//   $('#fullAD').css('display', 'none')
			this.fullAD = false;
		},
		// 參加鎖分活動
		joinEventMode() {
			this.$store.dispatch("actionJoinLoginEvent");
			this.$store.commit("HIDE_JOIN_EVENT");
		},
		modeModal() {
			this.$store.commit("HIDE_JOIN_EVENT");
		},
		// 鎖分活動 反灰遊戲商提示
		tips() {
			this.tipsModal = true;
		},
		tipsClose() {
			this.tipsModal = false;
		}
	},
	created() {
		this.$store.dispatch("actionMember");
		// 類型(預設1)1:遊戲列表，2:轉點列表
		let data = {
			type: 1,
			isGetBalance: false
		};

		this.$store.dispatch("actionVendors", data);

		// 彈窗滿版廣告
		this.$store.dispatch("actionBanner", 4);

		// 確認是否可以參加登入活動
		if (
			this.EventMode != 1 &&
			localStorage.getItem("checkLoginEvent" + this.Username) != 1
		) {
			this.$store.dispatch("actionCheckLoginEvent", 1);

			localStorage.setItem("checkLoginEvent" + this.Username, 1);
		}
	}
};
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->

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
	width: 60%;
}

.swiper-slide:nth-child(2n) {
	width: 40%;
}
.swiper-slide:nth-child(3n) {
	width: 20%;
}
.swiper-pagination-bullet-active {
	background: #ffe992 !important;
}
.swiper-pagination-bullet {
	background: #ffe992 !important;
}
</style>
