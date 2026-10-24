
<style>
</style>

<template>
	<div id="C711Mobile">
		<div id="agentPage" class="pageBg CwidthHeight widthHeightvh fontFamily">
			<LoginHeader></LoginHeader>

			<div class="summaryWrapper">
				<div style>
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

					<div style="margin-top:30px;">
						<div class="flexDiv">
							<div style="width:110px">
								<div class="text-c" style="padding:0px 5px 5px 5px;">{{$t('Agent_MyQRCode')}}</div>
								<div>
									<div id="qrcode" ref="qrcode" @click="showBigQRCode"></div>
								</div>
								<div class="text-c" style="padding:5px;">{{$t('Agent_ClickZoomIn')}}</div>
							</div>

							<div class="f70" style="padding-left:15px;">
								<div style="margin-bottom:5px;">{{$t('Agent_PromoteLink')}}</div>

								<div style="margin-bottom:10px;">
									<div class="fakeInput" attr-value>
										<div class="innerText">{{getAgentHomePageSummary.shareLink}}</div>

										<img
											id="copy_agent_shareLink"
											src="../../assets/img/agent/agent_copy_btn.svg"
											class="copyBtn"
											:data-clipboard-text="getAgentHomePageSummary.shareLink"
											@click="copyText('#copy_agent_shareLink')"
										/>
									</div>
								</div>
								<div style="margin-bottom:5px;">{{$t('Agent_PromoteCode')}}</div>

								<div>
									<div class="fakeInput" attr-value>
										{{getAgentHomePageSummary.shareCode}}
										<img
											id="copy_agent_shareCode"
											src="../../assets/img/agent/agent_copy_btn.svg"
											class="copyBtn"
											:data-clipboard-text="getAgentHomePageSummary.shareCode"
											@click="copyText('#copy_agent_shareCode')"
										/>
									</div>
								</div>
								<!--                                 

								<div v-if="!getAgentIsBindAlready">
									<div>
										<input type="search" value v-bind:placeholder="$t('Agent_Please_TypeParentCode')" />
									</div>

									<button
										class="orange"
										:value="$t('Agent_Label_Bind')"
										style="margin-top:10px;"
									>{{$t('Agent_Label_Bind')}}</button>
								</div>-->

								<div style="margin-top:10px;">
									<img
										src="../../assets/img/agent/agent_tips_rules.svg"
										class="q-img-button"
										@click="showRules"
									/>
									<img
										src="../../assets/img/agent/agent_ad_small_btn.jpg"
										class="float-r ad-small-img"
										@click="showMask"
									/>
								</div>
							</div>
						</div>
					</div>

					<div style="margin-top:15px;">
						<router-link to="/AgentMyPlayers">
							<button class="yellow" :value="$t('Agent_Label_Bind')">{{$t('Agent_Label_MyPromote')}}</button>
						</router-link>

						<router-link to="/AgentMyWithdrawal">
							<button
								:value="$t('Agent_Label_Bind')"
								class="blue"
								style="margin-top:10px;"
							>{{$t('Agent_Label_MyWithdrawal')}}</button>
						</router-link>
					</div>

					<div class="bottom-fixed-img" @click="showRules">
						<img src="../../assets/img/agent/agent_summary_bottom_banner.png" />
					</div>
				</div>
			</div>

			<!-- 彈窗 -->
			<maskModal></maskModal>

			<div class="mask mask-dark-grey" v-show="isBigQRCodeVisible">
				<div class="page">
					<div class="pageTitle clearfix">
						<img src="../../assets/img/C711_Mobile/pageTitle.png" class="pagePic" />

						<div class="pageTitleAll">
							<div class="loginbarBack float-left"></div>

							<div class="gamePointTransfer float-left"></div>
							<div class="loginbarTitle float-left">
								<!---->
								<span class="loginbarName">{{$t("Agent_MyQRCode")}}</span>
							</div>

							<div class="float-right" style="margin-right:15px;" @click="closeBigQRCode">
								<img src="../../assets/img/C711_Mobile/close_button_yellow.svg" />
							</div>
						</div>
					</div>

					<div>
						<div class="qr-share-title">{{$t("Agent_PromoteCode")}}: {{getAgentHomePageSummary.shareCode}}</div>

						<div style="text-align: center;margin-top:5%;">
							<div id="qrcodeBig" ref="qrcodeBig"></div>
						</div>

						<div class="text-c" style="margin-top:30px;">
							<img srcset="../../assets/img/vworld_logo_400.png" class="logo-img" />
						</div>
					</div>
				</div>
			</div>

			<div class="mask" v-show="isShowFirstMask">
				<div class="ad_popup">
					<div class="imgWrapper">
						<img class="closeBtn" src="../../assets/img/close_btn_white.svg" v-on:click="closeMask" />
					</div>

					<div class="adImgBox">
						<img style="width:100%;" v-bind:src="GenerateImageUrlByLang('agent_ad_pop_1x.jpg')" />

						<div class="info-btn">
							<img src="../../assets/img/agent/agent_tips_rules.svg" class="float-r" @click="showRules" />
						</div>
					</div>

					<div style="clear:both;"></div>
				</div>
			</div>
		</div>

		<maskModal ref="myMaskModal"></maskModal>
		<AgentRulesPopup ref="AgentRulesPopup"></AgentRulesPopup>
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
import maskModal from "./Modal";
import value_utils from "@/utils/value_utils";

export default {
	components: {
		AgentRulesPopup,
		LoginHeader,
		maskModal
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

		showMask() {
			this.isShowFirstMask = true;
		},

		closeMask() {
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

			let text = this.$i18n.t("successfulCopy");
			this.$refs.myMaskModal.showRightBottomMaskAndDisappear(text);
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
