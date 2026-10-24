<template>
	<div class="single_block">
		<div>
			<LobbyHeader></LobbyHeader>
			<div class="personal_wrap">
				<div id="agentPage" class="personal_content">
					<div class="page">
						<div class="clearfix"></div>

						<div class="record">
							<div class="select_block">
								<div class="ele-border bankPic">
									<select
										class="ele_border_none gradual-black custom-select"
										id="currentTab"
										@change="changePosition"
										v-model="tabPosition"
									>
										<option class="gray_dropdownBg" value="1">{{$t("Agent_Label_MyPlayers")}}</option>
										<option class="gray_dropdownBg" value="2">{{$t("Agent_Label_MyWinLoss")}}</option>
									</select>
									<div class="PicD" style="cursor: pointer;">
										<img src="/static/img/C711_PC/Path63.png" />
									</div>
								</div>

								<router-link to="/AgentMyWithdrawal">
									<button class="blue">{{$t("Agent_Label_MyWithdrawal")}}</button>
								</router-link>
							</div>
						</div>

						<table class="agent-dataTable" v-show="tabPosition == 1">
							<tr>
								<th>{{$t("Common_Account")}}</th>
								<th class="text-r">{{$t("Agent_TodayTeam_TotalScore")}}</th>
								<th class="text-r">{{$t("Agent_TodayPlayer_TotalScore")}}</th>
								<th class="text-r">{{$t("Agent_TeamMemberCount")}}</th>
								<th class="text-r">{{$t("Agent_DirectPlayerCount")}}</th>
								<th>{{$t("Common_Type")}}</th>
							</tr>

							<tr v-for="(item, index) in getAgentMyPlayerList" :key="index">
								<td>{{item.account}}</td>
								<td class="text-r">{{item.todayTeamScore| ToMoneyDisplayFormat}}</td>
								<td class="text-r">{{item.todayPlayerScore| ToMoneyDisplayFormat}}</td>
								<td class="text-r">{{item.teamCount}}</td>
								<td class="text-r">{{item.directPlayerCount}}</td>
								<td>{{item.type}}</td>
							</tr>
						</table>

						<table class="agent-dataTable" v-show="tabPosition == 2">
							<tr>
								<th>{{$t("Common_SendTime")}}</th>
								<th class="text-r">{{$t("Agent_Daily_TotalWinLoss")}}</th>
								<th class="text-r">{{$t("Agent_Daily_TeamWinLoss")}}</th>
								<th class="text-r">{{$t("Agent_Daily_DirectWinLoss")}}</th>
								<th class="text-r" m>{{$t("Common_Bonus")}}</th>
								<th>{{$t("Common_Status")}}</th>
							</tr>

							<tr v-for="(item, index) in getAgentMyRewardList" :key="index">
								<td>{{item.date}}</td>
								<td class="text-r">{{item.totalContribution| ToMoneyDisplayFormat}}</td>
								<td class="text-r">{{item.teamContribution| ToMoneyDisplayFormat}}</td>
								<td class="text-r">{{item.memberContribution| ToMoneyDisplayFormat}}</td>
								<td class="text-r">{{item.reward| ToMoneyDisplayFormatd}}</td>
								<td>{{item.type}}</td>
							</tr>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>
<script>
import LobbyHeader from "@/components/C711_PC/Lobby_header";
import { mapGetters, mapActions } from "vuex";
import helper from "@/utils/format_utils.js";
import LoginHeader from "@/components/C711_Mobile/Login_header";

export default {
	components: {
		LobbyHeader
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
	},
	created() {},
	methods: {
		changePosition() {
			if (this.tabPosition == 1) {
				this.$store.dispatch("actionGetMyPlayers");
			} else if (this.tabPosition == 2) {
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


