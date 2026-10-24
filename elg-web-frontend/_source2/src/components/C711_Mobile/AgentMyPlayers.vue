
<style>
</style>

<template>
	<div id="C711Mobile">
		<div id="agentPage" class="pageBg CwidthHeight widthHeightvh fontFamily">
			<LoginHeader></LoginHeader>

			<div class="scrollDiv" ref="scrollDiv" v-bind:style="dynamicHeight">
				<div>
					<div class="tabRow">
						<div
							v-bind:class="'gameListBgAll removebg ' + getTabStyleClass(1)"
							@click="changePosition(1)"
						>{{$t("Agent_Label_MyPlayers")}}</div>
						<div
							v-bind:class="'gameListBgAll removebg ' + getTabStyleClass(2)"
							@click="changePosition(2)"
						>{{$t("Agent_Label_MyWinLoss")}}</div>
					</div>
				</div>

				<div class="table-bg scrollmenu">
					<table class="agent_data" v-show="tabPosition == 1">
						<tr>
							<td class="dark-bg">{{$t("Common_Account")}}</td>
							<td v-for="(item, index) in getAgentMyPlayerList" :key="index">{{item.account}}</td>
						</tr>

						<tr>
							<td class="dark-bg">{{$t("Agent_TodayTeam_TotalScore")}}</td>
							<td
								v-for="(item, index) in getAgentMyPlayerList"
								:key="index"
							>{{item.todayTeamScore | ToMoneyDisplayFormat}}</td>
						</tr>

						<tr>
							<td class="dark-bg">{{$t("Agent_TodayPlayer_TotalScore")}}</td>
							<td
								v-for="(item, index) in getAgentMyPlayerList"
								:key="index"
							>{{item.todayPlayerScore| ToMoneyDisplayFormat}}</td>
						</tr>

						<tr>
							<td class="dark-bg">{{$t("Agent_TeamMemberCount")}}</td>
							<td
								v-for="(item, index) in getAgentMyPlayerList"
								:key="index"
							>{{item.teamCount| ToMoneyDisplayFormat}}</td>
						</tr>

						<tr>
							<td class="dark-bg">{{$t("Agent_DirectPlayerCount")}}</td>
							<td
								v-for="(item, index) in getAgentMyPlayerList"
								:key="index"
							>{{item.directPlayerCount| ToMoneyDisplayFormat}}</td>
						</tr>

						<tr>
							<td class="dark-bg">{{$t("Common_Type")}}</td>
							<td v-for="(item, index) in getAgentMyPlayerList" :key="index">{{item.type}}</td>
						</tr>
					</table>

					<table class="agent_data" v-show="tabPosition == 2">
						<tr>
							<td class="dark-bg">{{$t("Common_SendTime")}}</td>
							<td v-for="(item, index) in getAgentMyRewardList" :key="index">{{item.date}}</td>
						</tr>

						<tr>
							<td class="dark-bg">{{$t("Agent_Daily_TotalWinLoss")}}</td>
							<td
								v-for="(item, index) in getAgentMyRewardList"
								:key="index"
							>{{item.totalContribution| ToMoneyDisplayFormat}}</td>
						</tr>

						<tr>
							<td class="dark-bg">{{$t("Agent_Daily_TeamWinLoss")}}</td>
							<td
								v-for="(item, index) in getAgentMyRewardList"
								:key="index"
							>{{item.teamContribution| ToMoneyDisplayFormat}}</td>
						</tr>

						<tr>
							<td class="dark-bg">{{$t("Agent_Daily_DirectWinLoss")}}</td>
							<td
								v-for="(item, index) in getAgentMyRewardList"
								:key="index"
							>{{item.memberContribution| ToMoneyDisplayFormat}}</td>
						</tr>

						<tr>
							<td class="dark-bg">{{$t("Common_Bonus")}}</td>
							<td
								v-for="(item, index) in getAgentMyRewardList"
								:key="index"
							>{{item.reward| ToMoneyDisplayFormat}}</td>
						</tr>

						<tr>
							<td class="dark-bg">{{$t("Common_Status")}}</td>
							<td v-for="(item, index) in getAgentMyRewardList" :key="index">{{item.type}}</td>
						</tr>
					</table>
				</div>

				<div class="text-c lastTime">
					*{{$t("Agent_Page_LastUpdateTime")}}:
					{{lastTime}}
				</div>
				<div style="margin-top: 15px;">
					<router-link to="/AgentMyWithdrawal">
						<button
							:value="$t('Agent_Label_Bind')"
							class="blue m-size"
							style="margin: auto;"
						>{{$t('Agent_Label_MyWithdrawal')}}</button>
					</router-link>
				</div>
			</div>

			<!-- 彈窗 -->
			<maskModal></maskModal>
		</div>
	</div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import helper from "@/utils/format_utils.js";
import LoginHeader from "@/components/C711_Mobile/Login_header";
import maskModal from "./Modal";
export default {
	components: {
		LoginHeader,
		maskModal
	},

	computed: {
		...mapGetters(["getAgentMyPlayerList", "getAgentMyRewardList"])
	},

	data: function() {
		return {
			tabPosition: 1,
			dynamicHeight: "",
			lastTime: "",
			isShowFirstMask: false
		};
	},
	mounted() {
		this.$store.dispatch("actionGetMyPlayers");
		this.updateLastTime();
		this.dynamicChangeHeight();
	},
	created() {},
	methods: {
		dynamicChangeHeight() {
			let height = this.$refs.scrollDiv.clientHeight;
			this.dynamicHeight = "height:" + (height - 20) + "px";
		},

		changePosition(targetPosition) {
			if (targetPosition == this.tabPosition) {
				return;
			}

			this.tabPosition = targetPosition;

			if (targetPosition == 1) {
				this.$store.dispatch("actionGetMyPlayers");
			} else if (targetPosition == 2) {
				this.$store.dispatch("actionGetMyScore");
			}

			this.updateLastTime();
		},

		updateLastTime() {
			var date = new Date();
			this.lastTime = helper.To_YYYYMMDD_HHMMSS(date);
		},

		getTabStyleClass(comparePosition) {
			if (this.tabPosition == comparePosition) {
				return "gameListBg_s";
			} else {
				return "gameListBg";
			}
		}
	}
};
</script>
