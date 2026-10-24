<template>
	<div id="C711Mobile">
		<div
			class="pageBg fontFamily"
			:class="get_Events == '' ? 'widthHeightvh ' : 'widthHeight'"
			style="padding-bottom:20px;"
		>
			<StyleHeader></StyleHeader>

			<div class="pageCon eventContent" style="width:100%;">
				<Banner></Banner>
				<div class="eventCards">
					<div class="eventsConFont" v-if="get_Events == ''">* {{ $t("noEvents") }}</div>
					<div class="eventsConFont" v-else>
						<div class="eventPr">* {{ $t("updatedTenMinutes") }}</div>
						<div class="eventPrR">
							<img src="../../assets/img/C711_Mobile/ticket.png" />
							：{{ $t("cumulativeClaim") }}
						</div>
					</div>
					<!-- 平台活動 -->
					<div
						class="goldBorder pointbtnBorder_all marginBottom20"
						style="width:100%;"
						v-if="get_getLoginEvent!=''"
					>
						<div class="eventDiv eventDivbg eventlottyDiv fontsizelogin">
							<div class="eventsTitle clearfix">
								<div class="float-left eventsFont">{{ $t("pointRewardEvent") }}</div>
							</div>
							<div class="eventTime" v-if="get_getLoginEvent.start_time&&get_getLoginEvent.end_time">
								{{ $t("duringTheEvent") }}:
								{{get_getLoginEvent.start_time.substring(0,16)}} ~ {{get_getLoginEvent.end_time.substring(0,16)}}
							</div>
							<div
								class="eventTime"
							>{{ $t("freePoint") }}{{get_getLoginEvent.point}}{{ $t("point") }}，{{ $t("pointsGiven") }}</div>
							<div class="reddeemTime" style="margin:5px auto;">* {{ $t("thereWillBeCertain") }}</div>&nbsp;
							<div class="abreastBtnTwo clearfix">
								<div class="yellow_borderInput abreastBtn float-left">
									<button type="button" class="form-control black_InputAll graycolor" v-if="EventMode==1">
										<span>{{ $t("eventModeOn") }}</span>
									</button>
									<button
										type="button"
										class="form-control black_InputAll graycolor"
										v-else-if="get_getLoginEvent.is_full==1"
									>
										<span>{{ $t("full") }}</span>
									</button>
									<button
										type="button"
										class="form-control black_InputAll yellowcolor"
										@click="checkEventMode()"
										v-if="get_getLoginEvent.is_full==0&&EventMode!=1"
									>
										<span style="font-size:12px;line-height:10px;">{{ $t("checkQualification") }}</span>
									</button>
								</div>
								<div class="yellow_borderInput abreastBtn float-right">
									<a href="/EventMode">
										<button type="button" class="form-control black_InputAll fontsizeBtn blue_BtnBg">
											<span>{{ $t("eventDescription") }}</span>
										</button>
									</a>
								</div>
							</div>
						</div>
					</div>
					<!-- 摸彩 -->
					<div
						class="yellow_borderInput marginBottom20"
						v-for="(event, i) in get_Events"
						:key="'info-'+ i"
						v-if="event.type == 4"
					>
						<!--  v-if="event.type_id == 4" -->
						<div class="black_EventDiv purple_Divbg fontsizelogin">
							<div class="eventsTitle clearfix">
								<div class="float-left eventsFont">
									{{ event.title }}
									<span>{{ event.id }}</span>
								</div>
							</div>
							<div class="eventTime">
								{{ $t("duringTheEvent") }}:
								{{event.start_time.substring(0,16)}} ~ {{event.end_time.substring(0,16)}}
							</div>

							<div v-for="(detail, i) in event.detail" :key="i">
								<div class="eventsProgress">
									<b-progress
										:max="detail.point"
										show-progress
										:variant="detail.now_point < detail.point ? 'danger': 'success'"
									>
										<b-progress-bar
											:value="parseFloat(detail.now_point)"
											:label="detail.now_point / detail.point >= 1 ? $t('achieved'): `${(detail.now_point /detail.point) *100}%` "
											striped
											:animated="detail.now_point < detail.point"
										></b-progress-bar>
									</b-progress>
								</div>

								<div
									v-if="detail.order_type_name"
									class="goldFlow dateFont"
								>{{ $t("designatedGold") }}:{{detail.order_type_name}}</div>

								<div class="eventsPointFont">
									{{ $t("currentProgress") }}:
									{{ detail.now_point }} {{ $t("points") }} /
									{{ detail.point }} {{ $t("points") }}
								</div>
								<div class="dateFont">
									{{ $t("timeLimit") }}:
									{{ detail.start_time }} ~
									{{ detail.end_time }}
								</div>
							</div>
							<!-- 開獎日期 -->
							<div class="dateFont">
								<span>{{$t('drawDate')}}:{{ event.prize_draw_date.substring(0, 16) }}</span>
							</div>&nbsp;
							<div class="abreastBtnTwo clearfix">
								<div class="yellow_borderInput abreastBtn float-left">
									<a :href="'/EventTicket?id=' + event.id">
										<!-- 累積張數 -->
										<button type="button" class="form-control black_InputAll yellowcolor">
											<span>{{$t('tickets')}}({{ event.remaining_number }})</span>
										</button>
									</a>
								</div>

								<div class="yellow_borderInput abreastBtn float-right">
									<button
										type="button"
										class="form-control black_InputAll fontsizeBtn"
										:class="Date.parse(event.prize_draw_date.substring(0, 16)).valueOf()>Date.parse(NowDate).valueOf()? 'brown_BtnBg' : 'blue_BtnBg'"
										@click="playSound();eventDESC(event.id,event.remaining_number);"
									>
										<span
											v-if="Date.parse(event.prize_draw_date.substring(0, 16)).valueOf()>Date.parse(NowDate).valueOf()"
										>{{ $t("eventDescription") }}</span>
										<span v-else>{{$t('winnersList')}}</span>
									</button>
								</div>
							</div>
						</div>
					</div>

					<!-- 活動 -->
					<div
						class="yellow_borderInput marginBottom20"
						v-for="(event, i) in get_Events"
						:key="i"
						v-if="event.type!=4"
					>
						<div class="black_EventDiv black_Divbg fontsizelogin">
							<div v-if="event.type_id == 2" class="eventsTitle clearfix">
								<div class="eventsLogo float-left">
									<img src="../../assets/img/C711_Mobile/Group.png" />
								</div>
								<!-- vip title -->
								<div class="eventsFont float-left">
									{{ event.title }}
									<span>{{ event.id }}</span>
								</div>
								<div class="float-right eventTicket" v-if="event.prize_is_repeatedly === 1">
									<img src="../../assets/img/C711_Mobile/ticket.png" />
								</div>
							</div>
							<!-- BLUE TITLE -->
							<div v-if="event.type_id == 1" class="eventsTitle clearfix">
								<div class="eventsFont float-left">
									{{ event.title }}
									<span>{{ event.id }}</span>
								</div>
								<div class="float-right eventTicket" v-if="event.prize_is_repeatedly === 1">
									<img src="../../assets/img/C711_Mobile/ticket.png" />
								</div>
							</div>
							<div class="eventTime">
								{{ $t("duringTheEvent") }}:
								{{event.start_time.substring(0,16)}} ~ {{event.end_time.substring(0,16)}}
							</div>
							<div class="reddeemTime">
								{{ $t("redeemable") }}:
								{{ event.remaining_number }}
							</div>
							<div class="remarks">
								{{ $t("description") }}:
								<p>{{ $t("remarks") }}</p>
							</div>
							<div v-for="(detail, i) in event.detail" :key="i">
								<div class="eventsProgress">
									<b-progress
										:max="detail.point"
										show-progress
										:variant="detail.now_point < detail.point ? 'danger': 'success'"
									>
										<b-progress-bar
											:value="parseFloat(detail.now_point)"
											:label="detail.now_point / detail.point >= 1 ? $t('achieved'): `${(detail.now_point /detail.point) *100}%` "
											striped
											:animated="detail.now_point < detail.point"
										></b-progress-bar>
									</b-progress>
								</div>
								<div class="gamer_Btn">
									<div v-if="detail.vendor_name" class="gamer dateFont">
										{{ $t("designatedGamer") }}:
										<span>{{ detail.vendor_name }}&nbsp;</span>
									</div>
									<!-- 如果結束時間大於今日 -->
									<a
										:href="/GameList/ +detail.id +'/' +detail.vendor_id + '/' +eventOpenGame"
										target="_parent"
										style="margin-top:-1px;color:#6DD8FF;"
									>
										<div class="bar_Btn" v-if="isOnEventDuration(detail.start_time  , detail.end_time)">
											<span>{{$t("viewGames")}}</span>
										</div>
									</a>
								</div>
								<div class="slotGameFont">* {{ $t("slotGameOnly") }} *</div>
								<div
									v-if="detail.order_type_name"
									class="goldFlow dateFont"
								>{{ $t("designatedGold") }}:{{detail.order_type_name}}</div>
								<div class="eventsPointFont">
									{{ $t("currentProgress") }}:
									{{ detail.now_point }} {{ $t("points") }} /
									{{ detail.point }} {{ $t("points") }}
									<span>({{ detail.action_name }})</span>
								</div>
								<div class="dateFont">
									{{ $t("timeLimit") }}:
									{{ detail.start_time.substring(0, 16) }} ~
									{{ detail.end_time.substring(0, 16) }}
								</div>
							</div>&nbsp;
							<div class="abreastBtnTwo clearfix">
								<div class="yellow_borderInput abreastBtn float-left">
									<button
										type="button"
										class="form-control black_InputAll"
										:class="event.show_exchange == 0? 'graycolor ' : 'yellowcolor'"
										@click="playSound();exchange(event);gaData(event);"
										:disabled="event.show_exchange == 0"
									>
										<!-- 活動假資料 -->
										<!-- :disabled="event.show_exchange == 0" 這句拿掉-->
										<!-- :class="event.show_exchange == 0? 'yellowcolor' : 'graycolor'" -->
										<span
											v-if="event.prize_is_repeatedly == 1"
										>{{event.show_exchange == 0? $t("unaccomplished") : $t("redeemNow")}}</span>
										<span v-else>{{event.show_exchange == 0 ? $t("redeemed"): $t("redeemNow")}}</span>
									</button>
								</div>
								<div class="yellow_borderInput abreastBtn float-right">
									<button
										type="button"
										class="form-control black_InputAll brown_BtnBg fontsizeBtn"
										@click="playSound();eventDESC(event.id,event.remaining_number);"
									>{{ $t("eventDescription") }}</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<!-- 直接兌獎 -->
			<div class="mask" v-if="get_isPrize">
				<div class="Popups_Modal">
					<div class="PopupsTitle">{{ $t("itemname") }}</div>
					<div class="PopupsConAll">
						<div class="PopupsCon popupsConFont">
							<div>{{ get_Prize.prize.name }}</div>
						</div>
						<div class="PopupsBtn" @click="prizeConfirm()">
							<div class="btn-blue" style="margin:10px auto;">
								<button type="submit" style="padding:9px auto;">{{ $t("confirm") }}</button>
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
import StyleHeader from "@/components/C711_Mobile/Style_header";
import Banner from "@/components/C711_Mobile/Banner";
import { mapGetters, mapMutations } from "vuex";
import maskModal from "./Modal";
import Bus from "./Bus.js";
import formatHelper from "@/utils/format_utils";

export default {
	components: {
		Banner,
		StyleHeader,
		maskModal
	},
	computed: {
		...mapGetters([
			"getBanner",
			"get_Events",
			"get_isPrize",
			"get_Prize",
			"get_EventsTotal",
			"get_getLoginEvent"
		])
	},
	data() {
		return {
			NowDate: "",
			eventOpenGame: 1,
			//   鎖分
			EventMode: window.sessionStorage.getItem("eventMode")
		};
	},
	created() {
		this.$store.dispatch("action_EventList");
		// 等上方api資料回來
		setTimeout(() => {
			this.totalHome();
		}, 1000);
		this.$store.dispatch("actionGetLoginEvent");

		let targetBannerType = this.$enums.bannerType.fullBanner;
		this.$store.dispatch("actionBanner", targetBannerType);
	},
	mounted() {
		$("#fullevents").css("display", "none");

		this.getToday();

		// 遮罩關閉
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
		isOnEventDuration(startTime, endTime) {
			let nowValue = formatHelper.ConvertToDate(this.NowDate);
			let startTimeValue = formatHelper.ConvertToDate(startTime);
			let endTimeValue = formatHelper.ConvertToDate(endTime);

			return (
				endTimeValue.valueOf() > nowValue.valueOf() &&
				nowValue.valueOf() > startTimeValue.valueOf()
			);
		},

		getToday() {
			// 取今日日期
			var Today = new Date();
			this.NowDate =
				Today.getFullYear() +
				"-" +
				(Today.getMonth() + 1) +
				"-" +
				Today.getDate() +
				" " +
				Today.getHours() +
				":" +
				Today.getMinutes();
		},
		// 傳遞event活動總數到home.vue
		totalHome: function() {
			Bus.$emit("total", this.get_EventsTotal);
		},
		// 音效
		playSound() {
			var sound = document.getElementById("audio");
			sound.play();
		},
		//    兌換獎品
		exchange(event) {
			let data = {
				event_id: event.id,
				type: event.type
			};
			this.$store.dispatch("action_GetPrize", data);
		},
		// GA
		gaData(event) {
			if (event.type == 1) {
				var eventType = "刮刮卡";
			} else if (event.type == 2) {
				var eventType = "轉輪";
			} else {
				var eventType = "直接兌獎";
			}
			ga("send", "event", {
				eventCategory: "Event",
				eventAction: "EventExchange_Click",
				eventLabel: eventType,
				eventValue: event.id
			});
		},
		// 活動說明
		eventDESC(id, remaining_number) {
			this.$router.push("/EventDescription?id=" + id);
			sessionStorage.setItem("remaining_number", remaining_number);
			ga("send", "event", {
				eventCategory: "Event",
				eventAction: "EventDESC_Click",
				eventLabel: id
			});
		},
		// 直接兌獎 關閉
		prizeConfirm() {
			this.$store.commit("HIDE_IS_PRIZE");
			this.$store.dispatch("action_EventList");
		},
		// 參加鎖分活動
		checkEventMode() {
			// Events 頁面 檢查資格轉跳 帶參數2
			this.$store.dispatch("actionCheckLoginEvent", 2);
		}
	}
};
</script>
<style scoped>
.progress-bar {
	color: #300909;
}
</style>
