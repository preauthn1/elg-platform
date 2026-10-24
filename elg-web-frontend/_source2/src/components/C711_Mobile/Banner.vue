<template>
	<div id="C711Mobile">
		<!-- 上下顯示的banner -->
		<div v-for="(banner , i) in getBanner" :key="i">
			<img :src="banner.image" class="eventPageBanner" @click="goBanner(banner)" />
		</div>

		<!-- 左右滑動顯示的banner -->
		<!-- <div class="C711_Mcarousel">	
			<div class="carouselPic">
				<div class="bannerImg">
			
				<b-carousel
						id="carousel-fade"
						style="text-shadow: 0px 0px 2px #000"
						fade
						img-width="100%"
						img-height="100%"
					>
						<b-carousel-slide
							class="re"
							v-for="(banner,i) in getBanner"
							:key="i"
							:img-src="banner.image"
							width="100%;"
							height="100%;"
							max-height="100%;"
						>
							<div class="ab" @click="goBanner(banner)"></div>
						</b-carousel-slide>
					</b-carousel>
				</div>
			</div>      
		</div>
		-->

		<!-- 彈窗 -->
		<maskModal></maskModal>
	</div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import maskModal from "./Modal";
export default {
	computed: {
		...mapGetters([
			"getBanner",
			"getAcctInfo",
			"getVendors",
			"getLobby_Vendors"
		])
	},
	components: {
		maskModal
	},

	mounted() {
		//   沒有BANNER的時候 display BANNER DIV
		setTimeout(() => {
			if (this.getBanner.length <= 0) {
				$(".bannerLobby").css("display", "none");
				$(".CLobbyContent").css("height", 65 + "%");
			} else {
				$(".bannerLobby").css("display", "block");
			}
		}, 1000);
		// 橫向遮罩
		$(".directionMask").css("display", "none");
		if (window.orientation == 0 || window.orientation == 180) {
			$(".directionMask").css("display", "none");
		}
		//手機橫向禁止
		(window.onorientationchange = function() {
			if (window.orientation == 90 || window.orientation == -90) {
				$(".directionMask").css("display", "block");
			} else {
				$(".directionMask").css("display", "none");
			}
		}),
			//banner
			$(".carouselPic").css("display", "none");
		setTimeout(() => {
			// 1.8秒 等待api回來 getBanner有值
			var banner = this.getBanner;
			let vm = this;
			//   BANNER 大小
			if (banner[0]) {
				vm.fullWidth = window.innerWidth;
				vm.fullHeight = window.innerHeight;
				$(".bannerImg").css("width", vm.fullWidth);
				$(".bannerImg").css("margin-left", -vm.fullWidth / 2);
				window.onresize = () => {
					vm.fullWidth = window.innerWidth;
					vm.fullHeight = window.innerHeight;
				};
				$(".carouselPic").css("display", "block");
				$(".bannerImg").css("display", "block");
			} else {
				$(".carouselPic").css("display", "none");
				$(".bannerImg").css("display", "none");
			}
		}, 1800);

		this.getVendorsByApi();
	},

	methods: {
		getVendorsByApi() {
			// 類型(預設1)1:遊戲列表，2:轉點列表
			let data = {
				type: 1,
				isGetBalance: false
			};

			this.$store.dispatch("actionVendors", data);
		},

		//   Banner進入列表遊戲 四種方法 1.進遊戲 2.進列表 3.跟著遊戲商 4.進URL(DB後台設定)
		goBanner(banner) {
			// 進遊戲
			if (banner.game_id > 0) {
				if (banner.vendor_id == null) {
					var bannerVendorId = 0;
				}
				this.$router.push("/Game?id=" + banner.game_id);
				ga("send", "event", {
					eventCategory: "LobbyHeader",
					eventAction: "Carousel_Click",
					eventLabel: banner.image,
					eventValue: banner.game_id
				});
				// 進列表
			} else if (banner.game_type_id > 0) {
				sessionStorage.setItem("vendor_id", "");
				sessionStorage.setItem("type_id", banner.game_type_id);
				this.$router.push("/GameList/" + banner.game_type_id);
				window.location.reload();
				ga("send", "event", {
					eventCategory: "LobbyHeader",
					eventAction: "Carousel_Click",
					eventLabel: banner.image,
					eventValue: banner.game_type_id
				});
				//進遊戲商
			} else if (banner.vendor_id > 0) {
				var Lobby_Vendors = this.getLobby_Vendors;
				var vendor = this.getVendors;
				var store = this.$store;
				var router = this.$router;

				sessionStorage.setItem("vendor_id", banner.vendor_id);

				$.each(vendor, function(key, value) {
					if (banner.vendor_id == value.id) {
						if (value.need_login == 0) {
							// need_login 是否需開app
							if (value.only_one_game == 1) {
								store
									.dispatch("actionOne_balance", value.id)
									.then(balance => {
										let vendor_balance = parseInt(
											balance.toString().replace(",", ""),
											10
										);
										if (vendor_balance < 0) {
											this.vendorName = value.name;
											this.depositVal = Math.round(
												parseInt(
													this.vWorld_balance
														.toString()
														.replace(",", ""),
													10
												) / 2
											);
											this.depositVisible = true;
										} else {
											// 目前只有豪龍 SBO
											router.push("/Game?id=" + banner.vendor_id);
											store.dispatch(
												"action_vendorInfo",
												banner.vendor_id
											);
										}
									});
								ga("send", "event", {
									eventCategory: "LobbyHeader",
									eventAction: "Carousel_Click",
									eventLabel: banner.image,
									eventValue: banner.vendor_id
								});
							}
							//   遊戲列表BANNER進不同的遊戲列表
							else {
								let vendorAry = Lobby_Vendors.filter(function(res) {
									return res.id == banner.vendor_id;
								});
								let session_vendor_name = vendorAry[0].name;
								sessionStorage.setItem("vendor_id", banner.vendor_id);
								sessionStorage.setItem(
									"vendor_name",
									session_vendor_name
								);

								let listData = {
									vendor_id: banner.vendor_id,
									page: 1,
									per_page: 1000
								};
								store.dispatch("action_myGames", listData);

								router.push("/GameList/" + banner.vendor_id);
								// window.location.reload()
								ga("send", "event", {
									eventCategory: "LobbyHeader",
									eventAction: "Carousel_Click",
									eventLabel: banner.image,
									eventValue: banner.vendor_id
								});
							}
						} else if (value.need_login == 1) {
							router.push(
								"/GameApp?vendor=" +
									value.name +
									"&vendorID=" +
									value.id
							); // ?vendor= for gameApp title //
							ga("send", "event", {
								eventCategory: "LobbyHeader",
								eventAction: "Carousel_Click",
								eventLabel: banner.image,
								eventValue: banner.vendor_id
							});
						}
					}
				});
				ga("send", "event", {
					eventCategory: "LobbyHeader",
					eventAction: "Carousel_Click",
					eventLabel: banner.image,
					eventValue: banner.vendor_id
				});
			}
			// banner 直接URL
			else if (
				banner.vendor_id == 0 &&
				banner.game_type_id == 0 &&
				banner.game_id == 0
			) {
				window.location = banner.url;
			} else if (banner.event_id > 0) {
				this.$router.push("/EventDescription?id=" + banner.event_id);
				ga("send", "event", {
					eventCategory: "LobbyHeader",
					eventAction: "Carousel_Click",
					eventLabel: banner.image,
					eventValue: banner.event_id
				});
			}
		}
	},
	created() {
		this.$store.dispatch("actionBanner", 1);
		var width = window.screen.width;
		$(".bannerImg").css("width", width);
		$(".bannerImg").css("margin-left", width / 2);
	}
};
</script>
<style scoped>
.re >>> .carousel-caption {
	top: 0 !important;
	position: absolute;
	right: 0;
	bottom: 0;
	left: 0;
}
.re {
	position: relative;
}
.ab {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
}
</style>