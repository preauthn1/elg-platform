<template>
	<div id="C711Mobile">
		<div class="CwidthHeight widthHeightvh fontFamily scrollBar">
			<div class="pointHeight">
				<PointHeader ref="PointHeader"></PointHeader>
			</div>
			<div class="LobbyBg LobbySetting">
				<div class="infoPanel">
					<div class="walletInfo">
						<div class="pointRow">
							{{$t("point")}}
							<div class="refreshBtn">
								<img
									src="../../assets/img/C711_Mobile/icon/user@example.invalid"
									@click="reloadBalance"
								/>
							</div>
						</div>

						<div class="amountRow">{{getAcctInfo.balance | ToMoneyDisplayFormat}}</div>

						<div class="walletBtnRow">
							<div class="pointAddBtn">
								<router-link
									to="/StoredValue"
									onclick="ga('send', 'event', 'StoredBtn', 'StoredBtn_Click', 'Stored');"
								>
									<img src="../../assets/img/C711_Mobile/icon/user@example.invalid" />

									<router-link to="Points">
										<img
											v-if="!isSingleWalletMode"
											src="../../assets/img/C711_Mobile/icon/user@example.invalid"
										/>
									</router-link>
								</router-link>
							</div>
						</div>
					</div>
				</div>
				<div class="pageCon">
					<!-- 紅包紀錄 -->
					<div class="form-group yellow_borderInput marginTop10">
						<a
							href="/RedEnvelopeRecord"
							onclick="ga('send', 'event', 'Record', 'redEnvelopeRecord_Click', 'Record');"
						>
							<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
								<div class="pwFont float-left setPic">
									<img src="../../assets/img/C711_Mobile/RedEnvelopeRecord.png" class="redEnvelLogo" />
									{{ $t("redEnvelopeRecord") }}
								</div>
								<div class="setPic float-right">
									<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
								</div>&nbsp;
							</div>
						</a>
					</div>
					<!-- 活動轉入紀錄 -->
					<div class="form-group yellow_borderInput marginTop10">
						<a
							href="/ScratchCardRecord"
							onclick="ga('send', 'event', 'Record', 'ScratchCardRecord_Click', 'Record');"
						>
							<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
								<div class="pwFont float-left setPic">
									<img src="../../assets/img/C711_Mobile/ScratchCardRecord.png" class="recordLogoPic" />
									{{ $t("scratchCardTransferRecord") }}
								</div>
								<div class="setPic float-right">
									<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
								</div>&nbsp;
							</div>
						</a>
					</div>
					<!-- 平台轉入轉出紀錄 -->
					<div class="form-group yellow_borderInput marginTop10">
						<a
							href="/RecordList"
							onclick="ga('send', 'event', 'Record', 'PointsRecordList_Click', 'Record');"
						>
							<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
								<div class="pwFont float-left setPic">
									<img src="../../assets/img/C711_Mobile/RecordList.png" class="recordLogoPic" />
									{{ $t("platformTransferRecord") }}
								</div>
								<div class="setPic float-right">
									<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
								</div>&nbsp;
							</div>
						</a>
					</div>
					<!-- 客服轉入轉出紀錄 -->
					<div class="form-group yellow_borderInput marginTop10">
						<a
							href="/CustomerServiceRecord/Success"
							onclick="ga('send', 'event', 'Record', 'CustomerServiceRecord_Click', 'Record');"
						>
							<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
								<div class="pwFont float-left setPic">
									<img src="../../assets/img/C711_Mobile/CustomerServiceRecord.png" class="recordLogoPic" />
									{{ $t("customerServiceTransferRecord") }}
								</div>
								<div class="setPic float-right">
									<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
								</div>&nbsp;
							</div>
						</a>
					</div>

					<div class="form-group yellow_borderInput marginTop10" v-if="has_agent_layout">
						<a
							href="/AgentMyWithdrawal?tab=2"
							onclick="ga('send', 'event', 'Record', 'CustomerServiceRecord_Click', 'Record');"
						>
							<div class="form-control black_InputAll black_inputbg fontsizelogin clearfix">
								<div class="pwFont float-left setPic">
									<img src="../../assets/img/agent/agent_logo_man_avator.svg" class="recordLogoPic" />
									{{ $t("Agent_AgentMenu") }}
								</div>
								<div class="setPic float-right">
									<img src="../../assets/img/C711_Mobile/L.png" class="Lpic" />
								</div>&nbsp;
							</div>
						</a>
					</div>
				</div>
			</div>
			<!-- 彈窗 -->
			<maskModal></maskModal>
		</div>
	</div>
</template>
<script>
import PointHeader from "@/components/C711_Mobile/Points_header";
import { mapGetters, mapActions } from "vuex";
import maskModal from "./Modal";
export default {
	components: {
		PointHeader,
		maskModal
	},

	computed: {
		...mapGetters(["getAcctInfo"]),

		isSingleWalletMode() {
			return sessionStorage.getItem("transaction_all") == 1;
		}
	},

	data() {
		return {
			has_agent_layout: sessionStorage.getItem("has_agent_layout")
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
		reloadBalance() {
			if (this.isSingleWalletMode) {
				this.$refs.PointHeader.reloadAllModal();
			} else {
				this.$refs.PointHeader.reloadBalance();
			}
		}
	}
};
</script>