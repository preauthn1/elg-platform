<template>
	<div id="C711Mobile">
		<div class="pageBg CwidthHeight widthHeightvh fontFamily">
			<LoginHeader></LoginHeader>
			<div class="pageCon">
				<!-- 編輯個人資料 -->
				<div class="yellow_borderInput marginTop20 marginBottom20">
					<a
						href="/SettingPersonal"
						onclick="ga('send', 'event', 'Setting', 'SettingPersonal_Click', 'SettingPersonal');"
					>
						<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
							<div class="pwFont float-left setPic">
								<img src="../../assets/img/C711_Mobile/setting.png" class="logoPic" />
								{{ $t("editProfile") }}
							</div>
							<div class="setPic float-right">
								<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
							</div>&nbsp;
						</div>
					</a>
				</div>

				<div class="yellow_borderInput marginBottom20">
					<a href="/Record" onclick="ga('send', 'event', 'Setting', 'Record_Click', 'Record');">
						<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
							<div class="pwFont float-left setPic">
								<img src="../../assets/img/C711_Mobile/icon_clock.svg" class="logoPic" />
								{{ $t("title_wallet_record") }}
							</div>
							<div class="setPic float-right">
								<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
							</div>&nbsp;
						</div>
					</a>
				</div>

				<!-- 語言 -->
				<div class="yellow_borderInput marginBottom20">
					<router-link to="/Language">
						<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
							<div class="pwFont float-left setPic">
								<img src="../../assets/img/C711_Mobile/language.png" class="logoPic" />
								{{ $t("language") }}
							</div>
							<div class="setPic float-right">
								<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
							</div>&nbsp;
						</div>
					</router-link>
				</div>

				<!-- 最新活動 -->
				<div class="yellow_borderInput marginBottom20">
					<a href="/Events" onclick="ga('send', 'event', 'Setting', 'Events_Click', 'Events');">
						<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
							<div class="pwFont float-left setPic">
								<img src="../../assets/img/C711_Mobile/new.png" class="logoPic" />
								{{ $t("newEvents") }}
							</div>
							<div class="setPic float-right">
								<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
							</div>&nbsp;
						</div>
					</a>
				</div>
				<!-- 關於 -->
				<div class="yellow_borderInput marginBottom20">
					<router-link to="/About" onclick="ga('send', 'event', 'Setting', 'About_Click', 'About');">
						<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
							<div class="pwFont float-left setPic">
								<img src="../../assets/img/C711_Mobile/about.png" class="logoPic" />
								{{ $t("about") }}
							</div>
							<div class="setPic float-right">
								<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
							</div>&nbsp;
						</div>
					</router-link>
				</div>
				<!-- 分享碼 -->
				<div class="inputvalidate">
					<div class="ListborderInput">
						<div
							class="settingShare Listinputbg displayFlex"
							style="height:40px; justify-content: flex-start;"
						>
							<div style="width:10px;">
								<img src="../../assets/img/C711_Mobile/person.png" class="personPic" />
							</div>
							<div style=" ">
								<span class="ShareCodeFont">{{ $t("myShareCode") }}</span>
							</div>

							<div class="ShareCodeFont2" style=" ">
								<span style="display:none">{{localHost}}</span>
								<span>{{SHARE_CODE}}</span>
							</div>
						</div>

						<div class="btn-orangeSetting inputInBtn">
							<button
								id="shareCode"
								:data-clipboard-text="localHost+SHARE_CODE"
								@click="copy()"
								style="padding:9px auto;"
							>{{ $t("copy") }}</button>
						</div>
					</div>
				</div>
				<!-- 登出按鈕 -->
				<div class="btn-blue">
					<button
						type="submit"
						@click="playSound();logOut()"
						style="padding:9px auto;"
					>{{ $t("signOut") }}</button>
				</div>
			</div>
			<!-- 已複製提示框 -->
			<div class="mask" v-if="isShareCodeCopy">
				<div class="Popups_Modal">
					<div class="PopupsTitle">{{ $t("prompt") }}</div>
					<div class="PopupsConNoBtn">
						<div class="PopupsConNo popupsConFont">{{ $t("theShareCodeHasBeenCopied") }}</div>
					</div>
				</div>
			</div>
			<!-- 彈窗 -->
			<maskModal></maskModal>
		</div>
	</div>
</template>
<script>
import LoginHeader from "@/components/C711_Mobile/Login_header";
import { mapGetters } from "vuex";
import clipboard from "clipboard";
import maskModal from "./Modal";
export default {
	components: {
		LoginHeader,
		maskModal
	},
	computed: {
		...mapGetters(["getAcctInfo"])
	},
	data() {
		return {
			vPoints: window.sessionStorage.getItem("balance"),
			Nickname: window.sessionStorage.getItem("nickname"),
			Username: window.sessionStorage.getItem("username"),
			SHARE_CODE: window.sessionStorage.getItem("SHARE_CODE"),
			localHost: "http://" + location.host + "/Signup/",
			isShareCodeCopy: false
		};
	},
	methods: {
		//   按鈕音效
		playSound() {
			var sound = document.getElementById("audio");
			sound.play();
		},
		// 登出
		logOut() {
			this.$store.dispatch("actionLogout");
		},
		// 複製
		copy() {
			new clipboard("#shareCode");
			this.isShareCodeCopy = true;
			setTimeout(() => {
				this.isShareCodeCopy = false;
			}, 1500);
		}
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
	created() {
		this.$store.dispatch("actionMember");
	}
};
</script>