<template>
	<div class="single_block">
		<div>
			<LobbyHeader></LobbyHeader>
			<div class="personal_wrap">
				<div id="agentPage" class="personal_content">
					<div class="page">
						<div class="clearfix">
							<!-- <img src="../../assets/img/C711_Mobile/pageTitle.png" class="pagePic" /> -->

							<div class="white-title">{{$t("Agent_Title_Rule")}}</div>
						</div>

						<div id="agentRules" style="color:#FFF;">
							<div class="wrapper-d">
								<div class="bigTitleRow">
									<img src="../../assets/img/C711_Mobile/Group.png" style="height:20px;" />

									<div class="bigTitle">
										<div>{{$t("Agent_Rules_BigTitle")}}</div>
										<!-- <div>{{$t("Agent_Rules_BigTitle2")}}</div> -->
									</div>

									<div class="r-img">
										<img src="../../assets/img/C711_Mobile/ticket.png" style="height:15px;float:right" />
									</div>
								</div>

								<div style="margin-top:15px;">
									<div class="eachQ">
										<div class="Question">{{$t("Agent_Rules_Q110")}}</div>
										<div class="Answer">{{$t("Agent_Rules_A110")}}</div>
									</div>

									<div class="eachQ">
										<div class="Question">{{$t("Agent_Rules_Q120")}}</div>
										<div class="Answer">
											{{$t("Agent_Rules_A120")}}
											<img
												class="coin-icon"
												src="../../assets/img/agent/agent_coin_icon.png"
											/>
										</div>
									</div>

									<div class="eachQ">
										<div class="Question">{{$t("Agent_Rules_Q130")}}</div>
										<div class="Answer">{{$t("Agent_Rules_A130")}}</div>
									</div>

									<div class="eachQ">
										<div class="Question">{{$t("Agent_Rules_Q140")}}</div>
										<div class="Answer">{{$t("Agent_Rules_A140")}}</div>
									</div>

									<div class="eachQ">
										<div class="Question">{{$t("Agent_Rules_Q150")}}</div>
										<div class="Answer">{{$t("Agent_Rules_A150")}}</div>

										<div class="image-sample">
											<img v-bind:src="GenerateImageUrlByLang('agent_rule_tree_PC.png')" />
										</div>
									</div>

									<div class="eachQ">
										<div class="Question">{{$t("Agent_Rules_Q160")}}</div>
										<div class="Answer">{{$t("Agent_Rules_A160")}}</div>

										<table class="ruleLevels">
											<tr>
												<th>{{$t("Agent_Table_Column_Level")}}</th>
												<th>{{$t("Agent_Table_Column_Min")}}</th>
												<th>{{$t("Agent_Table_Column_Max")}}</th>
												<th>{{$t("Agent_Table_Column_Rabate")}}</th>
												<th>{{$t("Agent_Table_Column_Memo")}}</th>
											</tr>

											<tr v-for="(item, index) in getAgentRuleLevels" :key="index">
												<td>{{item.level}}</td>
												<td>{{item.min | ToMoneyDisplayFormat}}</td>
												<td>{{item.max | toValueOrNoneText(emptyText)}}</td>
												<td>
													{{$t("Agent_Table_Hint_MaxHint")}}
													{{item.rebate}}%
												</td>
												<td>
													{{$t("Agent_Table_Hint_Per10000")}}
													{{item.bounsAmount | ToMoneyDisplayFormat}}
												</td>
											</tr>
										</table>
									</div>

									<div class="eachQ">
										<div class="Question">{{$t("Agent_Rules_Q170")}}</div>
										<div class="Answer">{{$t("Agent_Rules_A170")}}</div>

										<div class="image-sample">
											<img
												v-bind:src="GenerateImageUrlByLang('agent_rule_sample_PC.png')"
												style="border-radius: 15px;"
											/>
										</div>
									</div>

									<div class="eachQ">
										<div class="Question">{{$t("Agent_Rules_Q180")}}</div>
										<div class="Answer">{{$t("Agent_Rules_A180")}}</div>
									</div>

									<div class="eachQ">
										<div class="Question">{{$t("Agent_Rules_Q190")}}</div>
										<div class="Answer">{{$t("Agent_Rules_A190")}}</div>

										<div class="Answer" style="margin-top:30px;">
											<span></span>
											{{$t("Agent_Rules_A191")}}
										</div>

										<div class="Answer">
											<ol>
												<li>{{$t("Agent_Rules_A291")}}</li>
												<li>{{$t("Agent_Rules_A292")}}</li>
												<li>{{$t("Agent_Rules_A293")}}</li>
												<li>{{$t("Agent_Rules_A294")}}</li>
												<li>{{$t("Agent_Rules_A295")}}</li>
												<li>{{$t("Agent_Rules_A296")}}</li>
											</ol>
										</div>
									</div>

									<div class="eachQ">
										<div class="Question">{{$t("Agent_Rules_Q200")}}</div>
										<div class="Answer">{{$t("Agent_Rules_A200")}}</div>

										<table class="ruleLevels">
																				<tr>
										<th>{{$t("Common_GameType_Label")}}</th>
										<th>{{$t("Agent_Rules_ConvertRate")}}</th>
										<th>{{$t("Agent_Rules_Note")}}</th>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_AG")}}</td>
										<td>0.8X</td>
										<td>{{$t("Agent_Rules_Note_AG")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_Joker")}}</td>
										<td>0.5X</td>
										<td>{{$t("Agent_Rules_Note_Joker")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_918Kiss")}}</td>
										<td>0.3X</td>
										<td>{{$t("Agent_Rules_Note_918Kiss")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_GreatWall99")}}</td>
										<td>0.5X</td>
										<td>{{$t("Agent_Rules_Note_GreatWall99")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_Ace333")}}</td>
										<td>0.5X</td>
										<td>{{$t("Agent_Rules_Note_Ace333")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_Vplay")}}</td>
										<td>1.0X</td>
										<td>{{$t("Agent_Rules_Note_Vplay")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_Mega888")}}</td>
										<td>0.3X</td>
										<td>{{$t("Agent_Rules_Note_Mega888")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_Xe88")}}</td>
										<td>0.5X</td>
										<td>{{$t("Agent_Rules_Note_Xe88")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_Lucky")}}</td>
										<td>1.0X</td>
										<td>{{$t("Agent_Rules_Note_Lucky")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_eBET")}}</td>
										<td>0.8X</td>
										<td>{{$t("Agent_Rules_Note_eBET")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_iSB")}}</td>
										<td>1.0X</td>
										<td>{{$t("Agent_Rules_Note_iSB")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_Ygg")}}</td>
										<td>1.0X</td>
										<td>{{$t("Agent_Rules_Note_Ygg")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_WorldMatch")}}</td>
										<td>1.0X</td>
										<td>{{$t("Agent_Rules_Note_WorldMatch")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_PlayTech")}}</td>
										<td>0.5X</td>
										<td>{{$t("Agent_Rules_Note_PlayTech")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_SBO")}}</td>
										<td>0.5X</td>
										<td>{{$t("Agent_Rules_Note_SBO")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_MT")}}</td>
										<td>0.8X</td>
										<td>{{$t("Agent_Rules_Note_MT")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_Play'n GO")}}</td>
										<td>1X</td>
										<td>{{$t("Agent_Rules_Note_Play'n GO")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_IA")}}</td>
										<td>0.5X</td>
										<td>{{$t("Agent_Rules_Note_IA")}}</td>
									</tr>
									<tr>
										<td>{{$t("Common_GameType_Vpower")}}</td>
										<td>1.0X</td>
										<td>{{$t("Agent_Rules_Note_Vpower")}}</td>
									</tr>
										</table>
									</div>
								</div>
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
import enums from "@/utils/enums";
import formatHelper from "@/utils/format_utils";
import LobbyHeader from "@/components/C711_PC/Lobby_header";
import value_utils from "@/utils/value_utils";

export default {
	components: { LobbyHeader },

	data() {
		return {
			Title: "default",
			isVisible: false,
			depositVisible: false,
			specialPic: "",
			emptyText: this.$i18n.t("Common_None")
		};
	},
	computed: {
		...mapGetters(["getAgentRuleLevels"])
	},

	mounted() {
		console.log(process.env.NODE_ENV);
		this.getRuleSetting();
	},

	filters: {
		toValueOrNoneText(value, emptyText) {
			if (value <= 0) return emptyText;
			return "" + formatHelper.To_Money_Format(value);
		}
	},

	methods: {
		getRuleSetting() {
			this.$store.dispatch("actionGetAgentPolicy");
		},

		setInvisible() {
			this.isVisible = false;
		},

		setVisible() {
			this.isVisible = true;
		},
		GenerateImageUrlByLang(fileName) {
			let dynamicPath = value_utils.GenerateImageUrlByLang(fileName);
			return dynamicPath;
		}
	}
};
</script>
