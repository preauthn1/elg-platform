
<style>
</style>

<template>
	<div id="C711Mobile">
		<div id="agentPage" class="pageBg CwidthHeight widthHeightvh fontFamily">
			<LoginHeader></LoginHeader>

			<div class>
				<div class="tabRow">
					<div
						v-bind:class="'gameListBgAll removebg ' + getTabStyleClass(1)"
						@click="changePosition(1)"
					>{{$t("Agent_Label_WithdrawalSummary")}}</div>
					<div
						v-bind:class="'gameListBgAll removebg ' + getTabStyleClass(2)"
						@click="changePosition(2)"
					>{{$t("Agent_Label_WithdrawalHistory")}}</div>
				</div>
			</div>

			<div class="table-bg scrollmenu">
				<table class="agent_data layout5050" v-show="tabPosition == 1">
					<tr>
						<td class="dark-bg">{{$t("Common_Account")}}</td>
						<td class style="width:50%">{{getApplyWithdrawalSummary.account}}</td>
					</tr>

					<tr>
						<td class="dark-bg">{{$t("Agent_TotalBonus_Socre")}}</td>
						<td class>{{getApplyWithdrawalSummary.totalScore| ToMoneyDisplayFormat}}</td>
					</tr>

					<tr>
						<td class="dark-bg">
							<div>{{$t("Agent_AlreadyApply_Socre")}}</div>
						</td>
						<td class>{{getApplyWithdrawalSummary.applyWithdrawalAmount| ToMoneyDisplayFormat}}</td>
					</tr>

					<tr>
						<td class="dark-bg">{{$t("Agent_AvailableApply_Socre")}}</td>
						<td class>{{getApplyWithdrawalSummary.availableWithdrawalAmount| ToMoneyDisplayFormat}}</td>
					</tr>
				</table>

				<table class="agent_data" v-show="tabPosition == 2">
					<tr>
						<td class="dark-bg">{{$t("Common_Time")}}</td>
						<td v-for="(item, index) in getAgentWithdrawalHistory" :key="index">{{item.date}}</td>
					</tr>

					<tr>
						<td class="dark-bg">{{$t("Common_OrderNo")}}</td>
						<td v-for="(item, index) in getAgentWithdrawalHistory" :key="index">{{item.orderNo}}</td>
					</tr>

					<tr>
						<td class="dark-bg">{{$t("Common_Amount")}}</td>
						<td
							v-for="(item, index) in getAgentWithdrawalHistory"
							:key="index"
						>{{item.amount| ToMoneyDisplayFormat}}</td>
					</tr>

					<tr>
						<td class="dark-bg">{{$t("Common_WithdrawalType")}}</td>
						<td v-for="(item, index) in getAgentWithdrawalHistory" :key="index">{{item.type}}</td>
					</tr>

					<tr>
						<td class="dark-bg">{{$t("Common_Status")}}</td>
						<td v-for="(item, index) in getAgentWithdrawalHistory" :key="index">{{item.status}}</td>
					</tr>
				</table>
			</div>

			<div v-show="tabPosition == 1" style="margin-top: 25px;margin-bottom:50px;">
				<button
					@click="showApplyPopup"
					class="orange m-size"
					style="margin: auto;"
				>{{$t('Agent_Label_ApplyWithdrawal')}}</button>
			</div>

			<div style="margin-top: 50px;">
				<router-link to="/AgentMyPlayers">
					<button
						:value="$t('Agent_Label_Bind')"
						class="yellow m-size"
						style="margin: auto;"
					>{{$t('Agent_Label_MyPromote')}}</button>
				</router-link>
			</div>

			<!-- 轉入平台彈窗 右邊 v-if="visibleOut"-->
			<div class="mask" v-show="isShowApplyPopup">
				<div class="popup-red medium">
					<div class="pointModalBtn">
						<div class="closeBtn-yellow" @click="closeApplyPopup"></div>
						<div class="title_row">{{$t('Agent_Title_ApplyWithdrawl')}}</div>

						<div class="panel-purple">
							<div class="panel-black">
								<div class="flex-layout" style="margin-top:20px;">
									<div class="f50 labelText box">
										<div class="v-middle">{{$t("Agent_RemainingApplyAmount_Socre")}}</div>
									</div>
									<div class="f50">
										<div class="ListborderInput">
											<input
												type="text"
												class="ListInputAllR Listinputbg"
												v-model="getApplyWithdrawalSummary.availableWithdrawalAmount"
												disabled="disabled"
												value="getApplyWithdrawalSummary.availableWithdrawalAmount"
											/>
										</div>
									</div>
								</div>

								<div class="flex-layout" style="margin-top:15px;">
									<div class="f50 labelText box">
										<div class="v-middle">{{$t("Agent_FormApplyAmount_Socre")}}</div>
									</div>
									<div class="f50">
										<div class="ListborderInput">
											<input
												name="applyAmount"
												type="text"
												maxlength="12"
												class="ListInputAllR Listinputbg"
												aria-required="true"
												aria-invalid="false"
												v-validate="'required|max:12|min_value:0.01|decimal:4'"
												:data-vv-as="$t('confirmpassword')"
												v-model="applyAmount"
											/>
										</div>
										<label
											v-show="errors.has('applyAmount')"
											class="validateDiv"
										>{{ $t("Validate_NumericOnly") }}</label>
									</div>
								</div>

								<div></div>
							</div>
							<div class="text-c">
								<div class="btn-blue" style="margin:30px auto;">
									<button type="submit" @click="submitApply()" style="padding:9px auto;">{{ $t("submit") }}</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- 彈窗 -->
			<maskModal></maskModal>
		</div>
	</div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import LoginHeader from "@/components/C711_Mobile/Login_header";
import maskModal from "./Modal";
export default {
	components: {
		LoginHeader,
		maskModal
	},
	data: function() {
		return {
			tabPosition: 1,
			isShowApplyPopup: false,
			applyAmount: ""
		};
	},
	computed: {
		...mapGetters(["getApplyWithdrawalSummary", "getAgentWithdrawalHistory"])
	},
	mounted() {
		//根據queryString決定預設頁
		var targetTab = this.$route.query.tab;

		if (targetTab == 2) {
			this.changePosition(2);
		} else {
			this.loadSummaryData();
		}
	},

	methods: {
		loadSummaryData() {
			this.$store.dispatch("actionGetWithdrawalSummary");
		},

		changePosition(targetPosition) {
			if (targetPosition == this.tabPosition) {
				return;
			}

			this.tabPosition = targetPosition;

			if (targetPosition == 2) {
				this.$store.dispatch("actionGetWithdrawalHistory");
			} else if (targetPosition == 1) {
				this.loadSummaryData();
			}
		},

		showApplyPopup() {
			this.isShowApplyPopup = true;
		},

		closeApplyPopup() {
			this.isShowApplyPopup = false;
			this.applyAmount = "";
		},

		getTabStyleClass(comparePosition) {
			if (this.tabPosition == comparePosition) {
				return "gameListBg_s";
			} else {
				return "gameListBg";
			}
		},

		submitApply() {
			this.$validator.validateAll().then(valid => {
				if (valid) {
					var post = {
						amount: this.applyAmount
					};

					this.$store.dispatch("actionApplyWithdrawal", post).then(res => {
						if (res.data.code == 1000) {
							let msg = this.$i18n.t("Msg_ApplySuccess");
							this.$store.dispatch("actionShowPopMsgByText", msg);

							this.closeApplyPopup();

							//刷新資料
							this.loadSummaryData();
						} else {
							this.$store.dispatch("actionShowPopMsg", res);
						}
					});
				}
			});
		}
	}
};
</script>
