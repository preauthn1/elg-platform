<template>
	<div class="single_block">
		<div>
			<LobbyHeader></LobbyHeader>
			<div class="personal_wrap">
				<div id="agentPage" class="personal_content">
					<table class="topTable" style="font-size:10px;">
						<thead>
							<tr>
								<th class="border-top-left" style="width: 18%;">{{$t('Agent_AgentParentName')}}</th>
								<th style="width: 18%;">{{$t('Agent_AgentName')}}</th>
								<th>{{$t('Agent_TeamMemberCount')}}</th>
								<th>{{$t('Agent_DirectPlayerCount')}}</th>
								<th>{{$t('Agent_TodayNewPlayerCount')}}</th>
								<th class="border-top-right">{{$t('Agent_TodayOnlinePlayerCount')}}</th>
							</tr>
						</thead>
						<tr>
							<td class="border-btm-left">{{getAgentHomePageSummary.parentAgentName}}</td>
							<td>{{getAgentHomePageSummary.agentName}}</td>
							<td>{{getAgentHomePageSummary.totalTeamMemberCount}}</td>
							<td>{{getAgentHomePageSummary.directPlayerCount}}</td>
							<td>{{getAgentHomePageSummary.todayNewPlayer}}</td>
							<td class="border-btm-right">{{getAgentHomePageSummary.todayActivePlayer}}</td>
						</tr>
					</table>

					<div class="flexDiv">
						<div class="middle-box">
							<div class="flexDiv">
								<div class="qrWrapper">
									<div class="text-c" style="padding:0px 5px 5px 5px;">{{$t('Agent_MyQRCode')}}</div>
									<div>
										<div id="qrcode" ref="qrcode" @click="showBigQRCode"></div>
									</div>
									<div class="text-c" style="padding:5px;">{{$t('Agent_ClickZoomIn')}}</div>
								</div>

								<div class="f75" style="padding-left:15px;">
									<div style="margin-bottom:10px;">
										<div class="ele-border input-X" style="margin-bottom: 30px;">
											<div>
												<span class="inputFontLeft" :title="$t('Agent_PromoteLink')">{{$t('Agent_PromoteLink')}}</span>
												<p
													class="inputFontRight ele_border_in gradual-black agentInfo"
												>{{getAgentHomePageSummary.shareLink}}</p>
											</div>

											<button class="orange fixRight">{{$t("copy")}}</button>
										</div>
									</div>

									<div style="margin-bottom:10px;">
										<div class="ele-border input-X" style="margin-bottom: 30px;">
											<div>
												<span class="inputFontLeft" :title="$t('Agent_PromoteCode')">{{$t('Agent_PromoteCode')}}</span>
												<p
													class="inputFontRight ele_border_in gradual-black agentInfo"
												>{{getAgentHomePageSummary.shareCode}}</p>
											</div>

											<button class="orange fixRight">{{$t("copy")}}</button>
										</div>
									</div>

									<div class="imageRow">
										<div style="margin-top:10px;">
											<router-link to="/agentRules">
												<img src="../../assets/img/agent/agent_tips_rules.svg" class="q-img-button" />
											</router-link>

											<img
												src="../../assets/img/agent/agent_ad_small_btn_PC.jpg"
												class="float-r ad-small-img"
												@click="showAdMask"
											/>
										</div>
									</div>
								</div>
							</div>

							<div class="bottomRow">
								<router-link to="/AgentMyPlayers">
									<button
										class="yellow"
										:value="$t('Agent_Label_Bind')"
										style="margin-top:20px;"
									>{{$t('Agent_Label_MyPromote')}}</button>
								</router-link>

								<router-link to="/AgentMyWithdrawal">
									<button
										:value="$t('Agent_Label_Bind')"
										class="blue"
										style="margin-top:20px;"
									>{{$t('Agent_Label_MyWithdrawal')}}</button>
								</router-link>
							</div>

							<router-link to="/agentRules">
								<div class="bottom-img" @click="showAdMask">
									<img src="../../assets/img/agent/agent_summary_bottom_banner_3x.png" />
								</div>
							</router-link>
						</div>
					</div>

					<div class="popUp_block" v-show="isBigQRCodeVisible">
						<div class="wrap">
							<div class="title">
								{{$t('Agent_MyQRCode')}}
								<img
									class="closeBtn-pop"
									src="../../assets/img/modalClose.png"
									v-on:click="closeBigQRCode"
								/>
							</div>
							<div id="qrcodeBig" ref="qrcode" @click="showBigQRCode"></div>
						</div>
					</div>

					<div class="popUp_block" v-show="isShowFirstMask">
						<div class="wrap">
							<div class="imgWrapper">
								<img
									class="closeBtn"
									style="top:10px;right:5px;"
									src="../../assets/img/close_btn_white.svg"
									v-on:click="closeAdMask"
								/>
							</div>

							<div class="adImgBox">
								<img
									style="width:100%;width:360px;"
									class="img-border10"
									v-bind:src="GenerateImageUrlByLang('agent_ad_pop_1x.jpg')"
								/>

								<div class="info-btn">
									<router-link to="/AgentRules">
										<img src="../../assets/img/agent/agent_tips_rules.svg" class="float-r" />
									</router-link>
								</div>
							</div>

							<div style="clear:both;"></div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>



<script>
const KEY_IS_FIRST_WATCH = "KEY_IS_FIRST_WATCH";

import clipboard from "clipboard";
import QRCode from "qrcodejs2";
import { mapGetters, mapMutations } from "vuex";
import LoginHeader from "@/components/C711_Mobile/Login_header";
import PointsHeader from "@/components/C711_Mobile/Points_header";
import AgentRulesPopup from "@/components/C711_Mobile/AgentRules";
import value_utils from "@/utils/value_utils";
import LobbyHeader from "@/components/C711_PC/Lobby_header";
import PopUp from "./Pop_up";

export default {
	components: {
		AgentRulesPopup,
		LobbyHeader
	},

	computed: {
		...mapGetters(["getAgentHomePageSummary"])
	},

	data: function() {
		return {
			testPrice: 163217.63,
			testStatus: 1,
			isBigQRCodeVisible: false,
			isShowFirstMask: false,
			isBindAlready: true,
			isToEnd: false
		};
	},
	mounted() {
		this.checkIsFirstTimeWatch();
		this.getInitialData();
	},

	created() {},
	methods: {
		regenerateQRCode(myUrl) {
			let qr = new QRCode("qrcode", {
				text: myUrl
			});

			let qr2 = new QRCode("qrcodeBig", {
				text: myUrl
			});
		},

		checkIsFirstTimeWatch() {
			let firstValue = localStorage.getItem(KEY_IS_FIRST_WATCH);
			//首次進入 顯示說明
			if (firstValue == null || firstValue.length == 0) {
				localStorage.setItem(KEY_IS_FIRST_WATCH, "false");
				this.isShowFirstMask = true;
			}
		},

		showAdMask() {
			this.isShowFirstMask = true;
		},

		closeAdMask() {
			this.isShowFirstMask = false;
		},

		showBigQRCode() {
			this.isBigQRCodeVisible = true;
		},

		closeBigQRCode() {
			this.isBigQRCodeVisible = false;
		},

		showRules() {
			this.$refs.AgentRulesPopup.setVisible();
			this.$refs.AgentRulesPopup.getRuleSetting();
		},

		crateQrcode() {
			let myUrl = "not initial";
		},

		copyText(elementId) {
			new clipboard(elementId);
		},

		GenerateImageUrlByLang(fileName) {
			let dynamicPath = value_utils.GenerateImageUrlByLang(fileName);
			return dynamicPath;
		},

		getInitialData() {
			this.$store.dispatch("actionGetAgentSummary").then(res => {
				if (res.data.code == 1000) {
					this.$store.dispatch("actionCommitAgentSummaryInfo", res);

					//刷新QRCode
					this.regenerateQRCode(res.data.data.link);
				} else {
					this.$store.dispatch("actionShowPopMsg", res);
				}
			});
		}
	}
};
</script>
