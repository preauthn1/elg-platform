<template>
	<div class="PCLobby">
		<div class="bgi">
			<LobbyHeader></LobbyHeader>
			<LobbyBanner></LobbyBanner>

			<div class="lobbyCon clearfix">
				<div class="leftMenu float-left" id="leftMenu">
					<div class="Menu clearfix">
						<div class="menuLeft float-left">
							<img src="../../../static/img/C711_PC/vendorType_left_top.gif" />
						</div>
						<div class="menuCenter float-left">
							<img src="../../../static/img/C711_PC/vendorType_top.gif" />
						</div>
						<div class="menuRight float-right">
							<img src="../../../static/img/C711_PC/vendorType_right_top.gif" />
						</div>
					</div>
					<div class="MenuCenter clearfix">
						<div class="MenuCenterCenter float-left">
							<!-- 內容 -->
							<div class="MenuSelect">
								<div class="menuTitle flip" @click="menuOpen()">{{ $t("myCategory") }}</div>
								<div class="gamer">{{ $t("gamepublisher") }}</div>
								<div class="gamerList clearfix panel">
									<div class="gamerOne">
										<input type="checkbox" v-model="selectAll" @click="choseAllVendor()" />
										<label>
											<span>{{ $t("selectAll") }}</span>
										</label>
									</div>
									<div v-for="(vendor, i) in getLobby_Vendors" :key="i">
										<div class="gamerOne" v-if="vendor.status == 1 &&vendor.need_login != 1">
											<input
												type="checkbox"
												:id="'category_' + vendor.id"
												v-model="vendors_type"
												:value="vendor"
												@click="choseVendor()"
											/>
											<label :for="vendor.id">
												<span class="vendor-name" :title="vendor.name">{{ vendor.name }}</span>
											</label>
											<img class="vendorLogo" :src="vendor.image" />
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="Menu clearfix">
						<div class="menuLeft float-left">
							<img src="../../../static/img/C711_PC/vendorType_left_bottom.gif" />
						</div>
						<div class="menuCenter float-left">
							<img src="../../../static/img/C711_PC/vendorType_bottom.gif" />
						</div>
						<div class="menuRight float-right">
							<img src="../../../static/img/C711_PC/vendorType_right_bottom.gif" />
						</div>
					</div>
				</div>

				<div class="rightGame float-left">
					<div id="lobbyAppGame">
						<div v-if="isAppTitle_show" class="gameTypeTitle_lobby">
							<div class="title_lobby">{{$t('appgamepublisher')}}</div>
						</div>
						<swiper :options="swiperOptionHotNew">
							<swiper-slide
								class="swiperPic_lobby"
								v-for="(vendor, i) in getLobby_Vendors"
								:key="i"
								v-if=" vendor.status == 1 &&vendor.need_login == 1"
							>
								<div class="gamePicAll_lobby" @click="gamePop(vendor)">
									<img src="../../../static/img/C711_PC/user@example.invalid" class="GameItemB_lobby" />
									<div class="gameListConL_lobby">
										<img :src="vendor.image" class="gamePic_lobby" />
									</div>
								</div>
								<div class="gameName_lobby">{{ vendor.name }}</div>
							</swiper-slide>
							<div class="swiper-button-prev" slot="button-prev"></div>
							<div class="swiper-button-next" slot="button-next"></div>
						</swiper>
						<div>&nbsp;</div>
					</div>
					<router-view :vendors_type="vendors_type" :vendorName="vendorName"></router-view>
				</div>
			</div>
		</div>

		<!-- QR CODE -->
		<div class="popUp_blockQR" v-if="gameAppQR">
			<div class="wrapQR">
				<div class="titleQR">{{titleQR}}</div>
				<div class="closeBtnQR" @click="closeCode()" style="cursor: pointer;">
					<img src="../../../static/img/C711_PC/Group user@example.invalid" />
				</div>
				<div class="contentQR">
					<!-- <div>QR</div> -->
					<div id="qrcode" ref="qrcode"></div>
				</div>
				<div class="footerQR">
					<div class>*{{$t('scanQRcodeDownload')}}</div>
					<div>*{{$t('afterSigningInWithPhone')}}</div>
				</div>
			</div>
		</div>
		<PopUp></PopUp>
	</div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import LobbyHeader from "@/components/C711_PC/Lobby_header";
import LobbyBanner from "@/components/C711_PC/Lobby_banner";
import PopUp from "./Pop_up";
import QRCode from "qrcodejs2";
import "swiper/dist/css/swiper.css";
import { swiper, swiperSlide } from "vue-awesome-swiper";
export default {
	components: {
		LobbyHeader,
		LobbyBanner,
		PopUp,
		swiper,
		swiperSlide
	},
	computed: {
		...mapGetters(["getLobby_Vendors", "getHotList"])
	},
	data() {
		return {
			swiperOptionHotNew: {
				slidesPerView: 6, //一行顯示3個
				slidesPerColumn: 1, //顯示3行
				spaceBetween: 0, //在slide之間設置距離(px)
				slidesPerColumnFill: "row", //多行佈局中以什麼形式填充列（column）和行（row）。類型：string，默認：column。
				grabCursor: true, // 游標為手掌
				slidesOffsetBefore: 10, //設定slide與左邊框的預設偏移量（單位px）
				slidesOffsetAfter: 30, //設定slide與右邊框的預設偏移量（單位px）

				pagination: {
					el: ".swiper-pagination", //分頁點
					clickable: true
				},
				navigation: {
					nextEl: ".swiper-button-next",
					prevEl: ".swiper-button-prev"
				}
			},
			gameAppQR: false,
			vendors_type: [],
			vendorStr: "", // vendorId 字串上路由
			vendorName: [], //

			vendors_type_Lock: false,
			selectAll: null,
			titleQR: "",
			isAppTitle_show: false
		};
	},
	watch: {
		$route() {
			// 路由變更做動作 for checkbox打勾
			// 回大廳遊戲全選
			if (this.$route.fullPath == "/Lobby") {
				this.selectAll = true;
				this.vendors_type = [];
				let idAry = [];
				var vendorAry = this.getLobby_Vendors.filter(function(ary) {
					return ary.need_login == 0 && ary.status == 1;
				});

				vendorAry.forEach(element => {
					this.vendors_type.push(element);
					idAry.push(element.id);
				});
				sessionStorage.setItem("vendors_type", JSON.stringify(vendorAry));
				let listData = {
					vendor_ids: idAry,
					page: 1,
					per_page: 150
				};
				this.$store.dispatch("action_myGames", listData);
				// 最常
				let oftenData = {
					personal: 2,
					page: 1,
					per_page: 150
				};
				this.$store.dispatch("action_oftenList", oftenData);
			} else {
				/// 判斷路由數量 與 getvendor相等全選
				var getvendorAry = this.getLobby_Vendors.filter(function(ary) {
					return ary.need_login == 0 && ary.status == 1;
				});

				// let str = this.$route.query.vds
				// let str = this.$route.params.vendorID
				if (this.$route.params.vendorID == undefined) {
					var str = "";
				} else {
					var str = this.$route.params.vendorID;
				}

				let idAry;
				let ary = [];
				if (str) {
					idAry = str.split(",");

					idAry.forEach(id => {
						let vendorAry = this.getLobby_Vendors.filter(function(ary) {
							return ary.id == id;
						});
						ary.push(vendorAry[0]);
					});
				}

				if (getvendorAry.length == ary.length) {
					this.selectAll = true;
				} else {
					this.selectAll = false;
				}
			}
		},
		getLobby_Vendors(vendors) {
			// 在進入大廳時 一取到vendor api 後遊戲商全選
			if (this.$route.fullPath == "/Lobby") {
				// 大庭必全選
				this.vendors_type = [];
				var vendorAry = this.getLobby_Vendors.filter(function(ary) {
					return ary.need_login == 0 && ary.status == 1;
				});

				vendorAry.forEach(element => {
					this.vendors_type.push(element);
				});
				sessionStorage.setItem("vendors_type", JSON.stringify(vendorAry));
				if (this.vendors_type.length == vendorAry.length) {
					this.selectAll = true;
				}
			} else {
				// let str = this.$route.query.vds
				let str = this.$route.params.vendorID;

				let idAry = str.split(",");
				var vendorAry = this.getLobby_Vendors.filter(function(ary) {
					return ary.need_login == 0 && ary.status == 1;
				});
				idAry.forEach(id => {
					let vendorAry = this.getLobby_Vendors.filter(function(ary) {
						return ary.id == id;
					});
					this.vendors_type.push(vendorAry[0]);
				});

				if (this.vendors_type.length == vendorAry.length) {
					this.selectAll = true;
				}
			}

			// 抓上方監聽VENDORS資料 跑回圈裡如果是上線跟需登入遊戲就是app遊戲 然後顯示TITLE
			vendors.forEach(e => {
				if (e.status == 1 && e.need_login == 1) {
					this.isAppTitle_show = true;
				}
			});
		}
	},
	methods: {
		choseVendor() {
			setTimeout(() => {
				let sessionVendors = []; // 季session
				let idAry = []; // 傳路由 空動作為了gamelist能watch $route()
				this.vendors_type.forEach(element => {
					sessionVendors.push(element);
					idAry.push(element.id);
				});
				sessionStorage.setItem(
					"vendors_type",
					JSON.stringify(sessionVendors)
				);
				let idStr = idAry.join(",");

				// this.$router.push('/gameList?vds=' + idStr)

				this.$router.push("/GameList/" + idStr);
			}, 100);
		},
		choseAllVendor() {
			if (this.selectAll) {
				// true要變全部選
				let sessionVendors = []; // 季session
				this.vendors_type = [];
				sessionStorage.setItem(
					"vendors_type",
					JSON.stringify(sessionVendors)
				);

				// this.$router.push('/gameList?vds=')
				this.$router.push("/GameList/" + "");
			} else {
				// this.vendors_type 退盡全部

				var vendorAry = this.getLobby_Vendors.filter(function(ary) {
					return ary.need_login == 0 && ary.status == 1;
				});

				let idAry = [];
				vendorAry.forEach(element => {
					this.vendors_type.push(element);
					idAry.push(element.id);
				});
				//存session
				sessionStorage.setItem("vendors_type", JSON.stringify(vendorAry));
				//上路由
				let idStr = idAry.join(",");
				// this.$router.push('/gameList?vds=' + idStr)

				this.$router.push("/GameList/" + idStr);
			}
		},
		menuOpen() {
			$(".panel").slideToggle("slow");
		},
		// QR CODE 彈窗
		gamePop(vendor) {
			let token = [REDACTED]"token");
			let locationURL = location.host;
			this.gameAppQR = true;
			this.titleQR = vendor.name;
			// 二维码内容,一般是由后台返回的跳转链接,这里是写死的一个链接
			this.qrcode =
				locationURL +
				"/QRcodeOpen" +
				"/" +
				vendor.id +
				"/" +
				vendor.name +
				"/" +
				token;

			// 使用$nextTick确保数据渲染
			this.$nextTick(() => {
				this.crateQrcode();
			});
		},
		// 动态生成二维码
		crateQrcode() {
			let qr = new QRCode("qrcode", {
				width: 200,
				height: 200, // 高度
				text: this.qrcode // 二维码内容或轉跳網址
				// render: 'canvas' // 设置渲染方式（有两种方式 table和canvas，默认是canvas）
				// background: '#f0f'
				// foreground: '#ff0'
			});
		},
		closeCode() {
			this.$refs.qrcode.innerHTML = "";
			this.gameAppQR = false;
		}
	},
	created() {
		//   類型(預設1)1:遊戲列表，2:轉點列表
		let data = {
			type: 1,
			isGetBalance: false
		};
		this.$store.dispatch("actionVendors", data);
	},
	mounted() {
		//   <!-- app 遊戲商 -->
		//20秒打一次api member token err 登出
		//用在QR code轉跳手機後 PC版登出
		const timer = setInterval(() => {
			// 某些定时器操作

			this.$store.dispatch("actionMember");
		}, 15000);
		// 通过$once来监听定时器，在beforeDestroy钩子可以被清除。
		this.$once("hook:beforeDestroy", () => {
			clearInterval(timer);
		});

		this.menuOpen();
	}
};
</script>
<style scoped>
.flip {
	cursor: pointer;
}
.panel {
	display: none;
}
</style>
