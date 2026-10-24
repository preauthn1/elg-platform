<template>
	<div id="C711Mobile">
		<div class="LobbyBg CwidthHeight widthHeightvh fontFamily">
			<PointsHeader></PointsHeader>
			<div class>
				<div class="infoPanel">
					<!-- 大頭照 -->
					<div class="personalPic">
						<div class="circlePic">
							<img :src="avatar== null ||avatar== '' ?DefaultAvatar:avatar " />
						</div>
						<img src="../../assets/img/C711_Mobile/camera.png" class="camera" v-on:click="show = !show" />
					</div>

					<!-- <div class="yellow_borderInput marginTop20">
						<a
							href="/SettingName"
							onclick="ga('send', 'event', 'SettingPersonal', 'SettingName_Click', 'SettingName');"
						>
							<div class="black_InputAll editInputBG fontsizelogin clearfix">
								<div class="pwFont float-left setPic">
									<div class="SettingNameLeft">{{ $t("nickname") }}</div>
									<div class="SettingNameRight">{{Nickname!='undefined'?Nickname:''}}</div>
								</div>
								<div class="setPic float-right">
									<img src="../../assets/img/C711_Mobile/icon/user@example.invalid" class="editIcon" />
								</div>&nbsp;
							</div>
						</a>
					</div>-->

					<!-- 暱稱 -->
					<router-link to="/SettingName">
						<div class="inputvalidate noValidate">
							<div class="ListborderInput">
								<div class="ListInputAllL Listinputbg setPic editInputBG">
									<div class="SettingInputLeft">{{ $t("nickname") }}</div>
									<div class="SettingInputRight">{{Nickname!='undefined'?Nickname:''}}</div>&nbsp;
									<div class="setPic float-right">
										<img src="../../assets/img/C711_Mobile/icon/user@example.invalid" class="editIcon" />
									</div>&nbsp;
								</div>
							</div>
						</div>
					</router-link>

					<!-- 帳號 -->
					<div class="inputvalidate noValidate">
						<div class="ListborderInput">
							<div class="ListInputAllL Listinputbg setPic editInputBG">
								<div class="SettingInputLeft">{{ $t("account") }}</div>
								<div class="SettingInputRight">{{Username}}</div>&nbsp;
							</div>
						</div>
					</div>

					<!-- 手機號碼 -->
					<div class="inputvalidate noValidate">
						<div class="ListborderInput">
							<div class="ListInputAllL Listinputbg setPic editInputBG">
								<div class="SettingInputLeft">{{ $t("cellphoneNumber") }}</div>
								<div class="SettingInputRight">{{Mobile =='undefined' || Mobile ==null ?'':Mobile}}</div>&nbsp;
							</div>
						</div>
					</div>
				</div>

				<div class="setting-link-panel">
					<!-- 改密碼 -->
					<div class="yellow_borderInput">
						<router-link
							to="/ChangePassword"
							onclick="ga('send', 'event', 'SettingPersonal', 'ChangePassword_Click', 'ChangePassword');"
						>
							<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
								<div class="pwFont float-left setPic">
									<img src="../../assets/img/C711_Mobile/icon/user@example.invalid" class="logoPic" />
									{{ $t("changePassword") }}
								</div>
								<div class="setPic float-right">
									<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
								</div>&nbsp;
							</div>
						</router-link>
					</div>

					<!-- 語言 -->
					<div class="yellow_borderInput">
						<router-link to="/Language">
							<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
								<div class="pwFont float-left setPic">
									<img src="../../assets/img/C711_Mobile/icon/user@example.invalid" class="logoPic" />
									{{ $t("language") }}
								</div>
								<div class="setPic float-right">
									<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
								</div>&nbsp;
							</div>
						</router-link>
					</div>

					<!-- 關於 -->
					<div class="yellow_borderInput">
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

					<div class="text-c">
						<div class="btn-orangeSetting" style="margin-top: 10px;">
							<button
								type="submit"
								@click="playSound();logOut()"
								style="padding: 5px 20px;"
							>{{ $t("signOut") }}</button>
						</div>
					</div>
				</div>
			</div>
			<!-- 相機BTN 滑出 -->
			<div class="transition">
				<transition name="fade">
					<div v-if="show">
						<div class="fadeMenu">
							<div class="fadeFont fadeBottomBorder">
								<label>
									{{ $t('camera') }}
									<input type="file" capture="camera" style="display:none" />
								</label>
							</div>
							<div class="fadeFont">
								<label>
									{{ $t("chooseFromAlbums") }}
									<input
										@change="uploadImg"
										type="file"
										accept="image/*"
										style="display:none"
									/>
								</label>
							</div>
						</div>
						<div class="fadeMenu" v-on:click="show = !show">
							<div class="fadeFont">{{ $t('cancel1') }}</div>
						</div>
					</div>
				</transition>
			</div>
			<!-- 彈窗 -->
			<maskModal></maskModal>
		</div>
	</div>
</template>
<script>
import PointsHeader from "@/components/C711_Mobile/Points_header";
import maskModal from "./Modal";
export default {
	components: {
		PointsHeader,
		maskModal
	},
	data() {
		return {
			vPoints: window.sessionStorage.getItem("balance"),
			Avatar: window.sessionStorage.getItem("avatar"),
			Email: window.sessionStorage.getItem("email"),
			Mobile: window.sessionStorage.getItem("mobile"),
			Nickname: window.sessionStorage.getItem("nickname"),
			Username: window.sessionStorage.getItem("username"),
			DefaultAvatar: require("../../assets/img/C711_Mobile/user.jpg"),
			avatar: sessionStorage.getItem("avatar"),
			show: false
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
	},
	methods: {
		//   按鈕音效
		playSound() {
			var sound = document.getElementById("audio");
			sound.play();
		},
		logOut() {
			this.$store.dispatch("actionLogout");
		},
		// 上傳照片
		uploadImg(e) {
			let file = e.target.files[0];
			if (!/image\/\w/.test(file.type)) {
				alert("請確保檔案為影象型別");
				return false;
			}
			let reader = new FileReader();
			let vm = this;
			reader.readAsDataURL(file);
			//   reader.onload = function() {
			//     vm.avatar = this.result
			//   }
			reader.onload = function(e) {
				vm.dealImage(this.result, { width: 1920 }, function(base) {
					vm.avatar = base;
					vm.$store.dispatch("actionAvatar", vm.avatar);
				});
			};
		},

		dealImage(path, obj, callback) {
			var img = new Image();
			img.src = path;
			img.onload = function() {
				var that = this;
				// 預設按比例壓縮
				var w = that.width,
					h = that.height,
					scale = w / h;
				w = obj.width || w;
				h = obj.height || w / scale;
				var quality = 0.7; // 預設圖片質量為0.7
				//生成canvas
				var canvas = document.createElement("canvas");
				var ctx = canvas.getContext("2d");
				// 建立屬性節點
				var anw = document.createAttribute("width");
				anw.nodeValue = w;
				var anh = document.createAttribute("height");
				anh.nodeValue = h;
				canvas.setAttributeNode(anw);
				canvas.setAttributeNode(anh);
				ctx.drawImage(that, 0, 0, w, h);
				// 影象質量
				if (obj.quality && obj.quality <= 1 && obj.quality > 0) {
					quality = obj.quality;
				}
				// quality值越小，所繪製出的影象越模糊
				var base64 = canvas.toDataURL("image/jpeg", quality);
				// 回撥函式返回base64的值
				callback(base64);
			};
		},
		// 更新資料
		update() {
			this.$store.dispatch("actionAvatar", this.avatar);
		}
	}
};
</script>
<style scoped>
.fade-enter-active,
.fade-leave-active {
	transition: all 0.4s ease;
}

.fade-enter {
	transform: translateY(50px);
	opacity: 0;
}

.fade-leave-to {
	transform: translateY(50px);
	opacity: 0;
}

.infoPanel {
	margin-top: 58px !important;
}

.transition {
	bottom: 60px !important;
}
</style>