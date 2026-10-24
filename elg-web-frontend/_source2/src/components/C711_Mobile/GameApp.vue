<template>
	<div id="C711Mobile">
		<div class="pageBg CwidthHeight widthHeightvh fontFamily">
			<LoginHeader></LoginHeader>
			<div class="pageCon" style="padding-top:20px;">
				<!-- 遊戲商參與的活動 -->
				<!--<div class="appFontTitle">*{{ $t("clickTheButtonBelowToSeeAllGames") }}</div>-->

				<div
					class="dropdown yellow_borderInput marginTop20 about"
					v-for="(event, i) in getVendor_Games"
					:key="i"
				>
					<button
						class="black_InputAll orange_inputbg blackInput"
						style="text-align:center;"
						type="button"
						id="dropdownMenuButton"
						data-toggle="dropdown"
						aria-haspopup="true"
						aria-expanded="false"
					>
						<div class="appFontLeft">
							<img src="../../assets/img/C711_Mobile/appGift.png" />
							{{ event.title }}
						</div>
						<div class="appFontRight">
							<img src="../../assets/img/C711_Mobile/L.png" />
						</div>
					</button>
					<div class="dropdown-menu yellow_borderDropdown" aria-labelledby="dropdownMenuButton">
						<div class="gray_dropdownBg">
							<div class="aboutCon" v-for="(games, i) in event.games" :key="i">
								<span>{{ games.name }}</span>
							</div>
						</div>
					</div>
				</div>
				<div>&nbsp;</div>
				<!-- 大黑框裡 -->
				<div class="form-group yellow_borderInput">
					<div class="form-control black_DivAll black_Divbg fontsizelogin">
						<div class="OpenFont" v-if="getVendor_Info.ios_open == ''">{{ $t("downLoadAPP") }}</div>
						<div class="OpenFont" v-else>{{ $t("openTheGame") }}</div>
						<div class="OpenBtn">
							<div class="form-group yellow_borderInput abreastBtn">
								<button
									type="button"
									class="form-control black_InputAll yellow_Btnbg fontsizeBtn"
									@click="AndroidIos()"
								>
									<span v-if="getVendor_Info.ios_open == ''">{{ $t("downLoad") }}</span>
									<span v-else>{{ $t("open") }}</span>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- 大黑框裡 下載連結2 -->
				<div
					class="form-group yellow_borderInput"
					v-if="getVendor_Info.android_download2 != '' && getVendor_Info.android_download2 != null"
					style="margin-top:10px;"
				>
					<div class="form-control black_DivAll black_Divbg fontsizelogin">
						<div class="OpenFont" v-if="getVendor_Info.ios_open == ''">{{ $t("downLoadAPP") }} (2)</div>

						<div class="OpenBtn">
							<div class="form-group yellow_borderInput abreastBtn">
								<button
									type="button"
									class="form-control black_InputAll yellow_Btnbg fontsizeBtn"
									@click="AndroidIosDownloadUrl2()"
								>
									<span v-if="getVendor_Info.ios_open == ''">{{ $t("downLoad") }}</span>
								</button>
							</div>
						</div>
					</div>
				</div>

				<div class="iconPic clearfix" v-if="getVendor_Info.ios_open == ''">
					<div class="float-left picLeft">
						<img :src="getVendor_Info.app_icon" />
					</div>
					<div class="float-left fontLeft">
						{{ $t("ifDownLoadGame") }}
						<br />
						{{ $t("pleaseEnterTheGame") }}
					</div>
				</div>
				<!-- 登入帳號 -->
				<div v-if="getVendor_Info.ios_open == ''" class="form-group yellow_borderInput marginBottom20">
					<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
						<div class="pwFont float-left">
							{{ $t("loginAccount") }} :
							{{ getVendor_Info.username }}
						</div>
						<div
							class="ctrlCPic float-right"
							:data-clipboard-text="getVendor_Info.username"
							@click="copy('acct')"
						>
							<img src="../../assets/img/C711_Mobile/copy.png" />
						</div>&nbsp;
					</div>
				</div>
				<!-- 原始密碼 -->
				<div v-if="getVendor_Info.ios_open == ''" class="form-group yellow_borderInput marginBottom20">
					<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
						<div class="pwFont float-left">
							{{ $t("loginPassword") }} :
							{{ getVendor_Info.password }}
						</div>&nbsp;
					</div>
				</div>
				<!-- 提示文字 -->
				<div class="iosOpenFont" v-if="getVendor_Info.ios_open == ''">
					<div>{{ $t("youCanChangeYourLoginPassword") }}</div>
					<div>{{ $t("ifYouHaveModifiedIt") }}</div>
					<div class="pwrule">
						<div>{{ $t("yourNewPasswordMustContain") }}</div>
						<div>1.{{ $t("atLeast6characters") }}</div>
						<div>2.{{ $t("uppercaseAndLowercaseLetters") }}</div>
						<div>3.{{ $t("atLeastOneNumber") }}</div>
					</div>
				</div>
				<div v-else style="font-size:15px;">
					<p>{{ $t("afterDownloading") }}</p>
					<p>{{ $t("pleaseReturnTo") }}</p>
				</div>
				<!-- 設定新密碼 -->
				<div class="inputvalidate" v-if="getVendor_Info.ios_open == ''">
					<div class="form-group ListborderInput">
						<span class="forgetphoneCode setNewPw" style="width:100px;">{{ $t("setnewpassword") }}</span>
						<input
							class="ListInputAllR Listinputbg"
							@keyup="changeDisable()"
							v-model="new_password"
							name="newPass"
							v-validate="{min: 6,regex: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d]{6,}$/}"
							:data-vv-as="$t('newPassword')"
							maxlength="14"
							type="password"
							placeholder="Ex:Aa12345"
						/>
					</div>
					<label
						v-show="errors.has('newPass')"
						for="newPass"
						class="validateDiv"
					>{{ errors.first("newPass") }}</label>
				</div>
				<!-- 按鈕 -->

				<div class="btn-orange" v-if="getVendor_Info.ios_open == ''">
					<button
						type="submit"
						@click="playSound();resetVendorPass();"
						:disabled="!isDisabled"
						style="padding:9px auto;"
					>{{ $t("changePassword") }}</button>
				</div>
			</div>
			<!-- 複製 -->
			<div class="mask" v-if="isAcctCopy || isPassCopy">
				<div class="Popups_Modal">
					<div class="PopupsTitle">{{ $t("prompt") }}</div>
					<div class="PopupsConNoBtn">
						<div class="PopupsConNo popupsConFont">
							<p v-if="isAcctCopy">{{ $t("loginAccount") }} {{ $t("copied") }}</p>
							<p v-if="isPassCopy">{{ $t("loginPassword") }} {{ $t("copied") }}</p>
						</div>
					</div>
				</div>
			</div>
			<!-- 下載連結a  -->
			<div class="mask" v-show="AppDownload">
				<div class="Popups_Modal">
					<div class="PopupsTitle">{{ $t("prompt") }}</div>
					<div class="PopupsConAll">
						<div class="PopupsCon popupsConFont">Download App</div>
						<div class="PopupsBtn clearfix">
							<div class="float-left">
								<a id="downloadLink" target="_blank" style="color:#fff" @click="AppDownload = false">
									<div class="btn-blueTwo">
										<button style="padding:9px auto;">{{ $t("downLoad") }}</button>
									</div>
								</a>
							</div>
							<div class="float-right" @click="AppDownload = false">
								<div class="btn-orangeTwo">
									<button style="padding:9px auto;">{{ $t("cancel1") }}</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<!-- 餘額不足 轉點 -->
			<div class="mask" v-if="depositVisible">
				<div class="Popups_Modal">
					<div class="PopupsTitle">
						{{ $t("insufficientBalance") }}
						<img
							@click="depositVisible = false"
							src="../../assets/img/C711_Mobile/modalClose.png"
							class="modalClose"
						/>
					</div>
					<div class="PopupsConAll">
						<div class="PopupsCon popupsConFont">
							<div class="modalBalance">
								{{ template }}{{ $t("amount") }} :
								{{ vWorld_balance }}
							</div>
							<div class="modalBalance">{{ $t("gamepublisher") }} : {{ vendorName }}</div>
							<div class="inputvalidate" style="width:80%;">
								<div class="form-group ListborderInput">
									<input
										class="ListInputRightS Listinputbg"
										type="number"
										pattern="[0-9]*"
										min="1"
										v-model="depositVal"
										@keyup="inputVal()"
									/>
								</div>
							</div>
						</div>
						<div class="PopupsBtn">
							<div class="btn-blue">
								<button type="submit" @click="deposit()" style="padding:9px auto;">{{ $t("transferIn") }}</button>
							</div>
						</div>
					</div>
				</div>
			</div>
			<!-- 彈窗 -->
			<maskModal></maskModal>
			<!-- 鎖分活動 -->
			<div class="mask" v-if="eventMode">
				<div class="Popups_Modal">
					<div class="PopupsTitle">{{ $t("prompt") }}</div>
					<div class="PopupsConAll">
						<div class="PopupsCon popupsConFont">{{$t('loginEventBackLobby')}}</div>
						<div class="PopupsBtn">
							<div class="btn-blue">
								<a href="/">
									<button type="submit" style="padding:9px auto;">{{ $t("confirm") }}</button>
								</a>
							</div>
						</div>
					</div>
				</div>
			</div>
			<!-- 全轉點數成功彈窗 -->
			<div class="mask" v-if="isPointsToVendor_success && ToVendor_allPoints > 0">
				<div class="Popups_Modal">
					<div class="PopupsTitle">{{ $t("prompt") }}</div>
					<div class="PopupsConAll">
						<div class="PopupsCon popupsConFont">
							<div style="font-size:14px;">
								{{ $t("theSystemHasTransferred")
								}}{{ ToVendor_allPoints }}{{ $t("pointsTO")
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
		</div>
	</div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import LoginHeader from "@/components/C711_Mobile/Login_header";
import maskModal from "./Modal";
// 複製外掛 clipboard
import clipboard from "clipboard";
import { setTimeout } from "timers";

export default {
	components: {
		LoginHeader,
		maskModal
	},
	computed: {
		...mapGetters(["getVendor_Info", "getVendor_Games", "get_singleBalance"])
	},
	data() {
		return {
			new_password: "",
			isDisabled: false,
			isAcctCopy: false,
			isPassCopy: false,
			//deposit data
			template: sessionStorage.getItem("Template"),
			vendorName: sessionStorage.getItem("vendor_name"),
			vWorld_balance: sessionStorage.getItem("balance"),
			vendorId: sessionStorage.getItem("vendor_id"),
			transaction_all: sessionStorage.getItem("transaction_all"),
			isPointsToVendor_success: false, //全轉成功提示彈窗
			ToVendor_allPoints: null, //全轉點數成功彈窗提示 共轉入的總金額
			game_id: "",
			depositVal: 0,
			depositVisible: false,
			// AppDownload
			AppDownload: false,
			//   鎖分活動
			EventMode: window.sessionStorage.getItem("eventMode"),
			eventMode: false,
			isDownloadUrl2: false
		};
	},
	mounted() {
		$(".directionMask").css("display", "none");
		//遊戲title
		$(".loginbarTitle").html(this.$route.query.vendor);
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
		// 如果在鎖分模式中 彈窗
		if (this.EventMode == 1) {
			this.eventMode = true;
		}

		//若為單一錢包模式 一律進到此頁就轉點
		if (this.transaction_all == 1) {
			this.$store
				.dispatch("singleWalletBalance", this.vendorId)
				.then(success => {});
		}
	},
	methods: {
		GoAppSingleWallet(scheme_url, download_url, timeout = 3000) {
			this.isDownloadUrl2 = false; //重製flag值

			// 單一錢包
			if (this.transaction_all == 1) {
				this.$store
					.dispatch("singleWalletBalance", this.vendorId)
					.then(success => {
						this.GOApp(scheme_url, download_url, timeout);
					})
					.catch(error => {
						console.log("Try again later", error);
					});
			} else {
				this.GOApp(scheme_url, download_url, timeout);
			}
		},
		GOApp(scheme_url, download_url, timeout = 3000) {
			let startTime = Date.now();
			if (scheme_url !== "") {
				//導連開啟app網址
				window.location = scheme_url;
			}
			// $('#open_app').attr('src',scheme_url)
			let vm = this;
			let t = setTimeout(() => {
				//如未安裝app導連失敗 3秒後開啟下載連結網址
				let endTime = Date.now();
				if (!startTime || endTime - startTime < timeout + 100) {
					vm.AppDownload = true;
					$("#downloadLink").attr("href", download_url);
				}
			}, timeout);
			window.onblur = function() {
				clearTimeout(t);
			};
		},

		AndroidIosDownloadUrl2() {
			this.isDownloadUrl2 = true;
			this.AndroidIos();
		},

		AndroidIos() {
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
				if (isIOS) {
					vm.IOS();
				}
			});
		},
		Android() {
			let vm = this;
			this.$store
				.dispatch("actionOne_balance", this.vendorId)
				.then(balance => {
					let vendor_balance = parseInt(
						balance.toString().replace(",", ""),
						10
					);
					if (vendor_balance < 0) {
						// 判斷遊戲商點數 點數不足請轉點
						this.game_id = "Android";
						this.depositVisible = true;
					} else {
						//判斷用下載連結1或連結2
						let downaloadUrl = vm.isDownloadUrl2
							? this.getVendor_Info.android_download2
							: this.getVendor_Info.android_download;

						this.GoAppSingleWallet(
							this.getVendor_Info.android_open,
							downaloadUrl
						);
					}
				});
		},
		IOS() {
			let vm = this;

			this.$store
				.dispatch("actionOne_balance", this.vendorId)
				.then(balance => {
					let vendor_balance = parseInt(
						balance.toString().replace(",", ""),
						10
					);
					if (vendor_balance < 0) {
						this.game_id = "IOS";
						this.depositVisible = true;
					} else {
						//判斷用下載連結1或連結2
						let downaloadUrl = vm.isDownloadUrl2
							? this.getVendor_Info.ios_download2
							: this.getVendor_Info.ios_download;

						// 點數足夠開啟app
						this.GoAppSingleWallet(
							this.getVendor_Info.ios_open,
							downaloadUrl
						);
						//   window.open(this.getVendor_Info.ios_download)
					}
				});
		},
		/// 餘額不足轉點 //
		inputVal() {
			this.depositVal = parseInt(this.depositVal, 10);
			let vPoints = parseInt(
				this.vWorld_balance.toString().replace(",", ""),
				10
			);
			if (this.depositVal >= vPoints) {
				// 輸入金額不得大於平台點數
				this.depositVal = vPoints;
			} else if (!this.depositVal) {
				// 輸入金額最低為0
				this.depositVal = 0;
			}
		},
		// 遊戲餘額不足的轉點至遊戲商
		deposit() {
			let data = {};
			data["isDeposit"] = true;
			data["vendor_id"] = this.vendorId;
			data["point"] = this.depositVal;
			data["game_id"] = this.game_id;
			this.$store.dispatch("balanceToVendor", data);
			this.depositVisible = false;
		},
		changeDisable() {
			// 輸入驗證，有輸入BTN才能點
			if (this.new_password !== "") {
				this.$validator.validate("newPass").then(valid => {
					if (valid) {
						this.isDisabled = true;
					} else {
						this.isDisabled = false;
					}
				});
			}
		},
		resetVendorPass() {
			//重新設定遊戲商帳密
			let newPass = this.new_password;
			this.$store.dispatch("action_resetVendorPass", newPass);
			this.new_password = "";
		},
		copy(e) {
			//複製帳密
			new clipboard(".coby");
			if (e == "acct") {
				this.isAcctCopy = true;
			} else {
				this.isPassCopy = true;
			}
			setTimeout(() => {
				this.isAcctCopy = false;
				this.isPassCopy = false;
			}, 1500);
		},
		close() {
			this.isPointsToVendor_success = false;
		},
		playSound() {
			var sound = document.getElementById("audio");
			sound.play();
		}
	},
	created() {
		let vendorID = this.$route.query.vendorID;

		this.$store.dispatch("action_vendorInfo", vendorID);
		this.$store.dispatch("action_vendorGames", vendorID);
		// 類型(預設1)1:遊戲列表，2:轉點列表
		let data = {
			type: 1,
			isGetBalance: false
		};
		this.$store.dispatch("actionVendors", data);
		this.depositVal = Math.round(
			parseInt(this.vWorld_balance.toString().replace(",", ""), 10) / 2
		);
	}
};
</script>


<style>
.abreastBtn {
	display: inline-block;
}
</style>