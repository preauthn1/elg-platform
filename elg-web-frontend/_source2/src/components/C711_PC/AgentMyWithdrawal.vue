<style>
.popUp_block {
	z-index: 9999999 !important;
}
</style>

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
										<option class="gray_dropdownBg" value="1">{{$t("Agent_Label_WithdrawalSummary")}}</option>
										<option class="gray_dropdownBg" value="2">{{$t("Agent_Label_WithdrawalHistory")}}</option>
									</select>
									<div class="PicD" style="cursor: pointer;">
										<img src="/static/img/C711_PC/Path63.png" />
									</div>
								</div>

								<router-link to="/AgentMyPlayers">
									<button class="yellow">{{$t("Agent_Label_MyPromote")}}</button>
								</router-link>
							</div>
						</div>

						<div v-show="tabPosition == 1">
							<table class="agent-dataTable">
								<tr>
									<th>{{$t("Common_Account")}}</th>
									<th class="text-r">{{$t("Agent_TotalBonus_Socre")}}</th>
									<th class="text-r">{{$t("Agent_AlreadyApply_Socre")}}</th>
									<th class="text-r">{{$t("Agent_AvailableApply_Socre")}}</th>
								</tr>

								<tr>
									<td>{{getApplyWithdrawalSummary.account}}</td>
									<td class="text-r">{{getApplyWithdrawalSummary.totalScore| ToMoneyDisplayFormat}}</td>
									<td
										class="text-r"
									>{{getApplyWithdrawalSummary.applyWithdrawalAmount| ToMoneyDisplayFormat}}</td>
									<td
										class="text-r"
									>{{getApplyWithdrawalSummary.availableWithdrawalAmount| ToMoneyDisplayFormat}}</td>
								</tr>
							</table>

							<div style="margin-top:35px;" class="text-c">
								<button class="orange popup" @click="showApplyPopup">{{$t("Agent_Label_ApplyWithdrawal")}}</button>
							</div>
						</div>

						<table class="agent-dataTable" v-show="tabPosition == 2">
							<tr>
								<th>{{$t("Common_Time")}}</th>
								<th>{{$t("Common_OrderNo")}}</th>
								<th class="text-r">{{$t("Common_Amount")}}</th>
								<th>{{$t("Common_WithdrawalType")}}</th>
								<th>{{$t("Common_Status")}}</th>
							</tr>

							<tr v-for="(item, index) in getAgentWithdrawalHistory" :key="index">
								<td>{{item.date}}</td>
								<td>{{item.orderNo}}</td>
								<td class="text-r">{{item.amount| ToMoneyDisplayFormat}}</td>
								<td>{{item.type}}</td>
								<td>{{item.status}}</td>
							</tr>
						</table>
					</div>

					<div class="half_popUp" v-show="isShowApplyPopup">
						<div class="wrap">
							<img src="/static/img/C711_PC/user@example.invalid" class="bg" />
							<div class="block">
								<div class="head">
									<p>{{$t("Agent_Title_ApplyWithdrawl")}}</p>
									<img class="closeImg" src="/static/img/C711_PC/Group5301.png" @click="closeApplyPopup" />
								</div>
								<div class="body">
									<div class="content" style="padding-bottom:30px;">
										<p class="hintMemo">
											<!-- 											
											{{$t("Agent_ApplyWithdrawal_Memo")}}-->
										</p>

										<div class="flexDiv" style="margin-top:30px;">
											<div class="f40">
												<div class="box">
													<div class="v-middle">{{$t("Agent_RemainingApplyAmount_Socre")}}</div>
												</div>
											</div>

											<div class="f60">
												<div
													class="fakeInput noRightItem"
												>{{getApplyWithdrawalSummary.availableWithdrawalAmount}}</div>
											</div>
										</div>
										<div class="flexDiv" style="margin-top:15px;">
											<div class="f40">
												<div class="box">
													<div class="v-middle">{{$t("Agent_FormApplyAmount_Socre")}}</div>
												</div>
											</div>

											<div class="f60">
												<div class="fakeInput brown">
													<input
														name="applyAmount"
														ref="applyAmount"
														class="hideInput"
														type="text"
														v-model="applyAmount"
														v-validate="'required|max:12|min_value:0.01|decimal:4'"
													/>
												</div>

												<div>
													<label
														v-show="errors.has('applyAmount')"
														class="validateDiv"
													>{{ $t("Validate_NumericOnly") }}</label>
												</div>
											</div>
										</div>
									</div>
									<div>
										<div class="btn-blue">
											<button @click="submitApply">{{$t("submit")}}</button>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					<!--end of popup-->
				</div>
			</div>
		</div>
	</div>
</template>
<script>
import helper from "@/utils/format_utils.js";
import { mapGetters, mapActions } from "vuex";
import LobbyHeader from "@/components/C711_PC/Lobby_header";
export default {
	components: {
		LobbyHeader
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
		this.loadSummaryData();
	},

	methods: {
		loadSummaryData() {
			this.$store.dispatch("actionGetWithdrawalSummary");
		},

		changePosition() {
			if (this.tabPosition == 2) {
				this.$store.dispatch("actionGetWithdrawalHistory");
			} else if (this.tabPosition == 1) {
				this.loadSummaryData();
			}
		},

		showApplyPopup() {
			this.isShowApplyPopup = true;
			this.$nextTick(() => this.$refs.applyAmount.focus());
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

