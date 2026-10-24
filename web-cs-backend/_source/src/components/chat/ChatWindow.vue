<template>
	<v-card class="chat-room" v-if="$route.meta.inRoom">
		<div class="blockChat" v-if="blockChat" @touchstart="displayBlock()" @click="displayBlock()"></div>
		<v-toolbar card dense flat class="white chat-room--toolbar" light>
			<!-- <v-btn icon>
            <v-icon color="text--secondary">keyboard_arrow_left</v-icon>
			</v-btn>-->
			<template>
				<v-avatar v-if="get_roomMsg !== ''" size="32" class="avatar-stack">
					<img
						:src="get_roomMsg.room.thumbnail==null || get_roomMsg.room.thumbnail=='null' || get_roomMsg.room.thumbnail==''?defaultAvatar:get_roomMsg.room.thumbnail"
					/>
				</v-avatar>
				<h5
					v-if="get_roomMsg.room.partner_username"
					style="opacity:.54"
				>({{get_roomMsg.room.partner_username}})</h5>

				<v-btn
					color="info"
					round
					class="white--text"
					@click="showFlowLimitRecord"
					v-show="isFlowAmountBtnVisible"
				>
					<i class="fa fa-clock-o" aria-hidden="true"></i>
					{{$t("list.limit_flowAmount")}}
				</v-btn>

				<v-btn
					color="deep-purple lighten-2"
					round
					class="white--text"
					@click="showEditBankAccount"
					v-show="isMemberBankAccountBtnVisible"
				>
					<i class="fa fa-clock-o" aria-hidden="true"></i>
					{{$t("list.bank_account")}}
				</v-btn>
			</template>

			<v-spacer></v-spacer>
			<v-toolbar-title>
				<h4>{{get_roomMsg.room.name==null?'Chat Room':get_roomMsg.room.name}}</h4>
			</v-toolbar-title>
			<v-spacer></v-spacer>
			<!-- <v-tooltip bottom>
            <v-btn icon slot="activator">
            <v-icon color="text--secondary">add</v-icon>
            </v-btn>
            <span>Add user</span>
			</v-tooltip>-->
		</v-toolbar>
		<vue-perfect-scrollbar id="roomScroll" class="chat-room--scrollbar grey lighten-5">
			<v-card-text class="chat-room--list pa-3">
				<div @click="chatHistory()" class="roomHisory">- - {{$t('message.older_message')}} - -</div>
				<template v-for="(item, index) in get_roomMsg.message">
					<!-- 訊息 -->
					<div
						v-bind:class="[ item.is_self == 0 ? 'reverse' : '']"
						class="messaging-item layout row my-4"
						:key="index"
					>
						<div style="text-align:center">
							<v-avatar class="mx-1" size="40">
								<img
									v-bind:src="item.thumbnail==null||item.thumbnail=='null'||item.thumbnail==''?defaultAvatar:item.thumbnail"
								/>
							</v-avatar>
							<div v-if="item.is_self == 0" style="opacity:.5">{{item.user_username}}</div>
							<div v-else style="opacity:.54">
								{{item.nickname}}
								<br />
								<span class="smallText" v-if="item.username">({{item.username}})</span>
							</div>
						</div>
						<!-- 一般文字訊息 -->
						<div v-if="item.type == 1" class="messaging--body layout column mx-2">
							<div
								v-if="item.type == 1"
								:value="true"
								v-bind:class="[ item.is_self == 0 ? 'primary white--text' : 'white']"
								class="pa-2"
							>
								<span
									v-if="item.transaction_id && item.transaction_status == 1"
								>{{$t('message.received')}}{{$t('message.transaction_id')}} {{item.transaction_id}}</span>
								<span
									v-else-if="item.transaction_id && item.transaction_status == 2"
								>{{$t('message.rejected')}}{{$t('message.transaction_id')}} {{item.transaction_id}}</span>
								<pre v-else>{{item.content}}</pre>
							</div>
							<!-- <div class="caption px-2 text--secondary">{{new Date(item.created_at).toLocaleString()}}</div> -->
							<div
								class="caption text--secondary"
								v-bind:class="[ item.is_self == 0 ? '' : 'timeRight']"
							>{{ formatChatTime(item.created_at) }}</div>
						</div>

						<!-- 用戶發紅包給客服 -->
						<div
							v-else-if="item.type == 3 && get_roomMsg.room.transaction_type == 1"
							class="messaging--body layout column mx-2"
						>
							<p
								:value="true"
								v-bind:class="[ item.is_self == 0 ? 'primary white--text' : 'white']"
								class="pa-2"
							>
								{{item.username}}
								<span style="color:#f44336;font-weight:600">{{$t('message.transfer_in')}}</span>
								<span v-show="item.content != null && item.content != ''">({{item.content}})</span>
							</p>
							<div
								class="caption text--secondary"
								v-bind:class="[ item.is_self == 0 ? '' : 'timeRight']"
							>{{ formatChatTime(item.created_at) }}</div>
						</div>

						<!-- 洗分 -->
						<div
							v-else-if="item.type == 6 && get_roomMsg.room.transaction_type == 1"
							class="messaging--body layout column mx-2"
						>
							<p
								:value="true"
								v-bind:class="[ item.is_self == 0 ? 'primary white--text' : 'white']"
								class="pa-2"
							>
								{{$t('message.for_user')}}
								<span
									style="color:#f44336;font-weight:600"
								>{{$t('message.transfer_out')}} {{item.content}} {{$t('message.point')}}</span>
								{{$t('message.transaction_id')}} {{item.transaction_id}}
							</p>
							<div
								class="caption text--secondary"
								v-bind:class="[ item.is_self == 0 ? '' : 'timeRight']"
							>{{ formatChatTime(item.created_at) }}</div>
						</div>

						<div
							v-else-if="item.type == 6 && get_roomMsg.room.transaction_type == 2"
							class="messaging--body layout column mx-2"
							style="align-items:flex-end"
						>
							<div class="envelope">
								<div>
									<img :src="Room.redEnvelope" />
									<h5>{{$t('redEnvelope.transfer_out')}} {{item.content}} {{$t('message.point')}}</h5>
									<span>{{item.transaction_id}}</span>
								</div>
							</div>
							<div
								class="caption text--secondary"
								v-bind:class="[ item.is_self == 0 ? '' : 'timeRight']"
							>{{ formatChatTime(item.created_at) }}</div>
						</div>
						<!-- 開分 -->
						<div
							v-else-if="item.type == 7 && get_roomMsg.room.transaction_type == 1"
							class="messaging--body layout column mx-2"
						>
							<p
								:value="true"
								v-bind:class="[ item.is_self == 0 ? 'primary white--text' : 'white']"
								class="pa-2"
							>
								{{$t('message.for_user')}}
								<span
									style="color:#f44336;font-weight:600"
								>{{$t('message.transfer_in')}} {{item.content}} {{$t('message.point')}}</span>
								{{$t('message.transaction_id')}} {{item.transaction_id}}
							</p>
							<div
								class="caption text--secondary"
								v-bind:class="[ item.is_self == 0 ? '' : 'timeRight']"
							>{{ formatChatTime(item.created_at) }}</div>
						</div>
						<div
							v-else-if="item.type == 7 && get_roomMsg.room.transaction_type == 2"
							class="messaging--body layout column mx-2"
							style="align-items:flex-end"
						>
							<div class="envelope">
								<div>
									<img :src="Room.redEnvelope" />
									<h5>{{$t('redEnvelope.transfer_in')}} {{item.content}} {{$t('message.point')}}</h5>
									<span>{{item.transaction_id}}</span>
								</div>
							</div>
							<div
								class="caption text--secondary"
								v-bind:class="[ item.is_self == 0 ? '' : 'timeRight']"
							>{{ formatChatTime(item.created_at) }}</div>
						</div>
						<!-- 開分贈點 -->
						<div
							v-else-if="item.type == 8 && get_roomMsg.room.transaction_type == 1"
							class="messaging--body layout column mx-2"
						>
							<p
								:value="true"
								v-bind:class="[ item.is_self == 0 ? 'primary white--text' : 'white']"
								class="pa-2"
							>
								{{$t('message.for_user')}}
								<span
									style="color:#f44336;font-weight:600"
								>{{$t('message.transfer_gift')}} {{item.content}} {{$t('message.point')}}</span>
								{{$t('message.transaction_id')}} {{item.transaction_id}}
							</p>
							<div
								class="caption text--secondary"
								v-bind:class="[ item.is_self == 0 ? '' : 'timeRight']"
							>{{ formatChatTime(item.created_at) }}</div>
						</div>
						<div
							v-else-if="item.type == 8 && get_roomMsg.room.transaction_type == 2"
							class="messaging--body layout column mx-2"
							style="align-items:flex-end"
						>
							<div class="envelope">
								<div>
									<img :src="Room.redEnvelope" />
									<h5>{{$t('redEnvelope.transfer_gift')}} {{item.content}} {{$t('message.point')}}</h5>
									<span>{{item.transaction_id}}</span>
								</div>
							</div>
							<div
								class="caption text--secondary"
								v-bind:class="[ item.is_self == 0 ? '' : 'timeRight']"
							>{{ formatChatTime(item.created_at) }}</div>
						</div>
						<!-- 照片圖片 -->
						<div
							v-else-if="item.type == 2 && item.is_self == 0"
							class="messaging--body layout column mx-2 haha"
							style="align-items:flex-end"
						>
							<div>
								<img @click="zoom(item.content)" :src="item.content" />
							</div>
							<div class="caption text--secondary">{{ formatChatTime(item.created_at) }}</div>
						</div>
						<div
							v-else-if="item.type == 2 && item.member_id !== isSelf"
							class="messaging--body layout column mx-2 haha"
						>
							<div>
								<img @click="zoom(item.content)" :src="item.content" style="float:left;" />
							</div>
							<div class="caption text--secondary">{{ formatChatTime(item.created_at) }}</div>
						</div>
						<v-spacer></v-spacer>
					</div>
				</template>
			</v-card-text>
		</vue-perfect-scrollbar>
		<v-card-actions>
			<textarea label="Type some message here" class="sendMessage" v-model="Room.sendContent"></textarea>
			<!-- <v-text-field
            @keyup.13="sendMessage(1)"
            v-model="Room.sendContent"
            full-width 
            flat
            clearable 
            solo
            label="Type some message here"
            class="sendMessage">
            
			</v-text-field>-->
			<!-- 記訊息 -->
			<v-icon @click="sendMessage(1)">send</v-icon>
			<!-- 傳圖片 -->
			<label for="file">
				<v-icon class="mx-2">photo</v-icon>
				<input @change="sendPhoto" id="file" style="display:none;" type="file" accept="image/*" />
			</label>
			<!-- 記事關閉 -->
			<!-- <v-icon @click="goNote_list()">note_add</v-icon> -->
			<!-- 開洗分 -->
			<v-icon @click="points_Dialog()">local_atm</v-icon>
		</v-card-actions>
		<!-- 新增開洗分 -->
		<v-dialog id="add" v-model="Points.dialog" persistent max-width="500px" scrollable>
			<v-card>
				<div class="eventLock" v-if="get_checkEvent">{{$t('redEnvelope.eventModeNoTransfer')}}</div>
				<v-card-title style="justify-content:space-between">
					<span class="headline">{{ $t('redEnvelope.redEnvelope') }}</span>
					<small>{{ $t('form.Asterisk_Required') }}</small>
				</v-card-title>
				<v-divider></v-divider>
				<v-card-text>
					<!-- <v-container grid-list-md> -->
					<v-layout wrap>
						<v-flex xs12>
							<v-text-field :label="$t('form.username')" :value="get_member.username" disabled></v-text-field>
						</v-flex>
						<v-flex xs12>
							<v-text-field :label="$t('form.nickname')" :value="get_member.nickname" disabled></v-text-field>
						</v-flex>
						<v-flex xs12>
							<v-text-field :label="$t('form.platform_balance')" :value="get_member.balance" disabled></v-text-field>
						</v-flex>
						<v-flex xs12>
							<!-- 總代理 -->
							<v-select
								v-if="Points.role_id == '1'"
								:items="Points.admin_Types"
								v-model="Points.type"
								:label="$t('list.type')"
								item-text="text"
								item-value="val"
								style="margin-top:8px"
								v-on:change="check_change()"
							></v-select>
							<!-- 一般代理客服 -->
							<v-select
								v-else
								:items="Points.Types"
								v-model="Points.type"
								:label="$t('list.type')"
								item-text="text"
								item-value="val"
								style="margin-top:8px"
								v-on:change="check_change()"
							></v-select>
						</v-flex>
						<!-- 單一錢包 :label="$t('list.type')" -->
						<v-flex xs12 v-if="tranIn">
							<v-select
								:items="Points.Wallet"
								v-model="Points.wallet"
								:label="$t('redEnvelope.transfer_inTo')"
								item-text="text"
								item-value="val"
								style="margin-top:8px"
							></v-select>
						</v-flex>
						<!-- <v-flex xs12 v-if="tranOut">
                            <span style="color:red; font-size:13px;">會員目前為自動帶點模式，收紅包前請確認會員已將點數轉回平台 (可請會員點擊平台上查詢餘額按鈕)</span>
						</v-flex>-->
						<v-flex xs12>
							<v-text-field
								:label="$t('redEnvelope.redEnvelope') +' '+ $t('list.balance')"
								v-model="balance"
								disabled
							></v-text-field>
						</v-flex>
						<v-flex xs12>
							<v-text-field
								:label="$t('form.amount') + ' *'"
								v-model="Points.amount"
								v-validate="'required|decimal:4'"
								:data-vv-name="$t('form.amount')"
								:error-messages="errors.collect($t('form.amount'))"
							></v-text-field>
						</v-flex>
						<v-flex lg12 sm12>
							<!-- <v-textarea 
                                style="margin:0"
                                textarea 
                                :label="$t('form.description') + ' *'"
                                v-model="Points.description"
                                v-validate="'required'" 
                                :data-vv-name="$t('form.description')" 
                                :error-messages="errors.collect($t('form.description'))"
							></v-textarea>-->
							<v-text-field :label="$t('form.description')" v-model="Points.description"></v-text-field>
						</v-flex>
					</v-layout>
					<!-- </v-container> -->
				</v-card-text>
				<v-card-actions>
					<router-link
						:to="'/transaction/list/' + get_member.username"
						target="_blank"
						style="margin:0 24px"
					>{{ $t('redEnvelope.24hr_transfer_record')}}</router-link>
					<v-spacer></v-spacer>
					<v-btn color="blue darken-1" flat @click="dialog_Close()">{{ $t('form.cancel') }}</v-btn>
					<v-btn
						color="blue darken-1"
						flat
						@click="GA_Transaction();add_Transaction()"
						:disabled="Points.disabled"
						v-if="eventSendMode"
					>{{ $t('form.send') }}</v-btn>
				</v-card-actions>
			</v-card>
		</v-dialog>

		<v-dialog id="showEditBankAccount" v-model="isShowEditBankAccount" persistent max-width="600px">
			<div class="popup-content">
				<div>
					<i class="fa fa-times medium f-r" aria-hidden="true" @click="closeEditBankAccountPopup"></i>
				</div>
				<div>
					<h3>{{$t('list.bank_account')}}</h3>
					<v-form ref="bank_account_form" lazy-validation>
						<v-container grid-list-md>
							<v-layout wrap>
								<v-flex xs12>
									<v-text-field
										:label="$t('form.username')"
										v-model="editBankAccount.username"
										:disabled="true"
									></v-text-field>
								</v-flex>

								<v-flex xs12>
									<v-select
										:items="get_orderTypes"
										v-model="editBankAccount.bank_id"
										:rules="[v => !!v || 'required*']"
										:label="$t('list.type')"
										item-text="name"
										item-value="id"
										required
									></v-select>
								</v-flex>

								<v-flex xs9>
									<v-text-field
										:label="$t('form.account_name')"
										v-model="editBankAccount.account_name"
										:rules="[v => !!v || 'required*']"
										required
										maxlength="50"
									></v-text-field>
								</v-flex>

								<v-flex xs3>
									<v-btn
										:color="editBankAccount.copy_btn_style_account_name"
										type="button"
										v-clipboard:copy="editBankAccount.account_name"
										v-clipboard:success="copy_account_name"
									>{{editBankAccount.copy_text_account_name}}</v-btn>
								</v-flex>

								<v-flex xs9>
									<v-text-field
										:label="$t('form.account_no')"
										v-model="editBankAccount.account_no"
										required
										maxlength="50"
										:rules="[v => !!v || 'required*']"
									></v-text-field>
								</v-flex>

								<v-flex xs3>
									<v-btn
										:color="editBankAccount.copy_btn_style_account_no"
										type="button"
										v-clipboard:copy="editBankAccount.account_no"
										v-clipboard:success="copy_account_no"
									>{{editBankAccount.copy_text_account_no}}</v-btn>
								</v-flex>
							</v-layout>

							<div>
								<v-btn color="info darken-1" @click="submit_bankAccount()">{{ $t('form.save') }}</v-btn>
							</div>
						</v-container>
					</v-form>
				</div>
			</div>
		</v-dialog>

		<v-dialog id="showFlowLimit" v-model="isShowFlowLimit" persistent max-width="1000px">
			<div class="popup-content">
				<div>
					<i class="fa fa-times medium f-r" aria-hidden="true" @click="closeFlowAmountPopup"></i>
				</div>

				<v-data-table
					:headers="dataTableFlowLimit.headers"
					:items="get_flowAmount_list"
					:disable-initial-sort="isDisableSort"
					:rows-per-page-items="[10]"
					class
					item-key="name"
					v-model="dataTableFlowLimit.selected"
				>
					<template slot="items" slot-scope="props">
						<td>{{ props.item.type }}</td>
						<td class="text-r">{{ props.item.depositAmount | ToMoneyDisplayFormat }}</td>
						<td class="text-r">{{ props.item.limit_flowAmount | ToMoneyDisplayFormat }}</td>
						<td class="text-r">{{ props.item.valid_flowAmount | ToMoneyDisplayFormat }}</td>
						<td class="text-c">{{ props.item.status }}</td>
						<td class="text-c">{{ props.item.date }}</td>
						<td class="text-c">{{ props.item.operation_time }}</td>
					</template>
				</v-data-table>
			</div>
		</v-dialog>

		<!-- 成功 -->
		<v-dialog
			id="addNote"
			v-model="$store.state.Transaction.is_addSuccess"
			persistent
			max-width="400px"
		>
			<v-card>
				<v-card-title>
					<span class="headline" style="margin:auto">{{ $t('redEnvelope.transfer_success') }}</span>
				</v-card-title>
				<v-card-actions>
					<v-spacer></v-spacer>
					<v-btn color="blue darken-1" flat @click="dialog_Close()">{{ $t('form.confirm') }}</v-btn>
				</v-card-actions>
			</v-card>
		</v-dialog>
		<!-- img放大 -->
		<!-- <v-dialog v-model="Room.img_dialog" max-width="100%" max-height="100%"> -->
		<div v-if="Room.img_dialog" class="zoom_block" @click="Room.img_dialog = false">
			<div>
				<img :src="Room.zoomImg" />
			</div>
		</div>
		<!-- </v-dialog> -->
	</v-card>
</template>
<script>
import { mapGetters } from "vuex";
import VuePerfectScrollbar from "vue-perfect-scrollbar";
import { setTimeout } from "timers";

export default {
	components: {
		VuePerfectScrollbar,
	},
	computed: {
		...mapGetters([
			"get_roomMsg",
			"get_member",
			"get_userView",
			"get_checkEvent",
			"get_flowAmount_list",
			"get_orderTypes",
			"get_bankAccount",
		]),
		balance() {
			// -1等於開洗分金額無上限
			if (this.get_userView.limit_of_credit == "-1") {
				return this.$t("list.unlimited");
			} else {
				return (
					this.get_userView.limit_of_credit - this.get_userView.used_credit
				).toFixed(2);
			}
		},
		msgStatus: {
			get() {
				return this.get_roomMsg.message;
			},
		},
		roterCode: {
			get() {
				return this.$route.params.room_code;
			},
		},
		isFlowAmountBtnVisible: {
			get() {
				let result =
					this.get_roomMsg != null && this.get_roomMsg.room.isThird == 1; //代表為玩家客服諮詢 (其他還有代理之間的聊天)
				return result;
			},
		},
		isMemberBankAccountBtnVisible: {
			get() {
				let result =
					this.get_roomMsg != null && this.get_roomMsg.room.isThird == 1; //代表為玩家客服諮詢 (其他還有代理之間的聊天)
				return result;
			},
		},
	},
	data() {
		return {
			defaultCopyBtnText: "COPY",
			defaultCopyBtnStyle: "amber lighten-1",
			isShowEditBankAccount: false,
			isShowFlowLimit: false,
			isSelf: sessionStorage.getItem("user_member_id"),
			defaultAvatar: require("@/../static/avatar/user.png"),
			tempCurrentPath: "",
			editBankAccount: {
				username: "",
				account_name: "",
				account_no: "",
				bank_id: "",
				copy_btn_style_account_name: "",
				copy_btn_style_account_no: "",
				copy_text_account_name: "",
				copy_text_account_no: "",
			},

			Points: {
				dialog: false,
				disabled: false, //按鈕鎖定防開洗分連擊
				member_id: "",
				amount: null,
				description: "",
				type: 1,
				wallet: 1,
				// 開洗分選單
				role_id: sessionStorage.getItem("role_id"),
				Types: [
					{ text: this.$t("redEnvelope.transfer_in"), val: 1 },
					{ text: this.$t("redEnvelope.transfer_out"), val: 2 },
				],
				admin_Types: [
					{ text: this.$t("redEnvelope.transfer_in"), val: 1 },
					{ text: this.$t("redEnvelope.transfer_out"), val: 2 },
					{ text: this.$t("redEnvelope.transfer_gift"), val: 3 },
				],
				Wallet: [
					{ text: this.$t("redEnvelope.platform"), val: 1 },
					//{ text: this.$t("redEnvelope.gamepublisher"), val: 2 } //先不允許轉到遊戲錢包 by Rory
				],
			},

			// tranOut:false,
			selected: null,
			// 聊天室
			Room: {
				sendContent: "",
				history_page: 2,
				sendImg: "",
				zoomImg: "",
				img_dialog: false,
				/// 聊天scroll高度
				first_load: true,
				Ary_length: 30,
				// 開洗分紅包
				redEnvelope: require("@/../static/chat/redEnvelopes.png"),
			},
			blockChat: true,
			//  鎖分模式
			eventLock: false,
			EventMode: sessionStorage.getItem("EventMode"),
			eventSendMode: true,
			// 單一錢包
			platformType: "",
			transaction_all: sessionStorage.getItem("transaction_all"),
			tranIn: false,

			isDisableSort: true,
			dataTableFlowLimit: {
				selected: [],
				headers: [
					{
						text: this.$t("list.type"),
						value: "type",
						sortable: false,
						align: "center",
						width: "15%",
					},
					{
						text: this.$t("list.depositAmount"),
						value: "depositAmount",
						sortable: false,
						align: "end",
						width: "15%",
					},
					{
						text: this.$t("list.limit_flowAmount"),
						value: "limit_flowAmount",
						sortable: false,
						align: "end",
					},
					{
						text: this.$t("list.valid_flowAmount"),
						value: "valid_flowAmount",
						sortable: false,
						align: "end",
					},
					{
						text: this.$t("list.status"),
						sortable: false,
						align: "center",
						value: "status",
					},
					{
						text: this.$t("list.date"),
						sortable: false,
						align: "center",
						value: "date",
					},
					{
						text: this.$t("list.operation_time"),
						sortable: false,
						align: "center",
						value: "operation_time",
					},
				],
				items: this.get_userList,
			},
		};
	},

	methods: {
		check_change() {
			this.change();
		},
		change() {
			if (sessionStorage.getItem("transaction_all") == 1) {
				if (this.Points.type == 1) {
					this.tranIn = true;
					//    this.tranOut= false
				} else if (this.Points.type == 2) {
					this.tranIn = false;
				} else if (this.Points.type == 3) {
					this.tranIn = true;
				}
			} else {
				this.tranIn = false;
			}
		},

		showEditBankAccount() {
			this.isShowEditBankAccount = true;
			this.resetEditBankAccountStatus();
			this.get_bankAccount_info();
		},

		resetEditBankAccountStatus() {
			//切換router path的時候  某些變數似乎還是會keep住上一個的資料
			if (this.tempCurrentPath != this.$route.path) {
				this.editBankAccount.copy_btn_style_account_name = this.defaultCopyBtnStyle;
				this.editBankAccount.copy_btn_style_account_no = this.defaultCopyBtnStyle;
				this.editBankAccount.copy_text_account_name = this.defaultCopyBtnText;
				this.editBankAccount.copy_text_account_no = this.defaultCopyBtnText;

				if (this.$refs.bank_account_form) {
					this.$refs.bank_account_form.reset();
				}

				this.tempCurrentPath = this.$route.path;
			}

			this.$nextTick(() => {
				this.editBankAccount.username = this.get_roomMsg.room.partner_username;
			});
		},

		showFlowLimitRecord() {
			this.isShowFlowLimit = true;
			this.getFlowAmountByApi();
		},

		getFlowAmountByApi() {
			var postBody = {
				member_id: this.$route.params.member_id,
				user_id: sessionStorage.getItem("user_id"),
				page: "1",
				per_page: 50000,
			};

			this.$store.dispatch("action_get_flowAmount_list", postBody);
		},

		closeFlowAmountPopup() {
			this.isShowFlowLimit = false;
		},

		closeEditBankAccountPopup() {
			this.isShowEditBankAccount = false;
		},

		get_bankAccount_info() {
			let vm = this;
			let username = this.get_roomMsg.room.partner_username;

			this.$store
				.dispatch("action_get_bank_account_info", username)
				.then((res) => {
					if (res.data.code == 1000 && res.data.data != null) {
						this.editBankAccount.account_name =
							vm.get_bankAccount.account_name;
						this.editBankAccount.account_no =
							vm.get_bankAccount.account_no;
						this.editBankAccount.bank_id = vm.get_bankAccount.bank_id;
					}
				})
				.catch((error) => {});
		},

		getOrderTypes() {
			let vm = this;
			this.$store.dispatch("action_get_orderTypes");
		},

		submit_bankAccount() {
			var isValid = this.$refs.bank_account_form.validate();

			if (!isValid) {
				return;
			}

			let data = {
				exist_data_id: this.get_bankAccount.exist_data_id,
				username: this.get_roomMsg.room.partner_username,
				bank_id: this.editBankAccount.bank_id,
				account_name: this.editBankAccount.account_name,
				account_no: this.editBankAccount.account_no,
				operator_cs: sessionStorage.getItem("user_id"),
			};
			let vm = this;
			this.$store
				.dispatch("action_update_bank_account_info", data)
				.then((res) => {
					console.log(res);

					if (res.data.code == 1000) {
						vm.closeEditBankAccountPopup();
					} else {
					}
				});
		},

		copy_account_name() {
			console.log(this.editBankAccount.account_name);
			this.editBankAccount.copy_text_account_name = "Success!";
			this.editBankAccount.copy_btn_style_account_name =
				"light-green lighten-1";
		},

		copy_account_no() {
			console.log(this.editBankAccount.account_no);
			this.editBankAccount.copy_text_account_no = "Success!";
			this.editBankAccount.copy_btn_style_account_no =
				"light-green lighten-1";
		},

		displayBlock() {
			this.blockChat = false;
		},
		formatChatTime(s) {
			let newDate;
			if (s) {
				newDate = s.replace(/-/g, "/");
			}
			return newDate;
		},
		/// 轉note list
		goNote_list() {
			if (this.$route.params.room_id && this.$route.params.member_id) {
				// this.$router.push('/noteTitle/' + this.$route.params.room_id + '/' + this.$route.params.member_id)
				window.open(
					"/chat/noteTitle/" +
						this.$route.params.room_id +
						"/" +
						this.$route.params.member_id,
					"_blank"
				);
			} else {
				console.log('no params."room_id" "member_id" cant go to note');
			}
		},
		/// 開洗分
		points_Dialog() {
			this.Points.member_id = this.$route.params.member_id;
			this.Points.dialog = true;
			this.$store.dispatch("action_memberView", this.Points.member_id);

			let id = sessionStorage.getItem("user_id");
			this.$store.dispatch("action_UserView", id);
			let event_Mode = sessionStorage.getItem("eventMode");
			// console.log('event_Mode',event_Mode)
			this.eventModeEvent();
			setTimeout(() => {
				if (sessionStorage.getItem("transaction_all") == 1) {
					if (this.Points.type == 1) {
						this.tranIn = true;
						//    this.tranOut= false
					} else if (this.Points.type == 2) {
						this.tranIn = false;
					} else if (this.Points.type == 3) {
						this.tranIn = true;
					}
				} else {
					this.tranIn = false;
				}
			}, 800);
		},
		eventModeEvent() {
			setTimeout(() => {
				// console.log('this.EventMode1',this.EventMode)
				console.log("Mode:", sessionStorage.getItem("EventMode"));
				if (sessionStorage.getItem("EventMode") == 1) {
					this.eventSendMode = false;
				} else {
					this.eventSendMode = true;
				}
			}, 1200);
		},

		transactionAll() {},
		add_Transaction() {
			this.$validator.validateAll().then((vaild) => {
				if (vaild) {
					// if(this.Points.type==1||this.Points.type==3){
					//     this.platformType = this.Points.wallet
					//     console.log('this.platformType',this.platformType)
					// }else{
					//    this.platformType=''
					// }
					let transaction_Data = {
						member_id: this.Points.member_id,
						type: this.Points.type,
						amount: this.Points.amount,
						remark: this.Points.description,
						platform:
							this.Points.type == 1 || this.Points.type == 3
								? this.Points.wallet
								: "",
						// "platform":this.platformType
					};
					this.$store.dispatch("action_Transaction_Add", transaction_Data);
					this.Points.disabled = true;
					this.Points.dialog = false;
					this.Points.amount = "";
					this.Points.description = "";
				}
			});
			//  this.$store.commit('HIDE_JOIN_EVENT')
		},
		GA_Transaction() {
			if (this.Points.type == 1) {
				ga("send", "event", {
					eventCategory: "member_Transfer",
					eventAction: "Transfer_In_Click",
					eventLabel: this.get_member.username,
					eventValue: this.Points.amount,
				});
			} else if (this.Points.type == 2) {
				ga("send", "event", {
					eventCategory: "member_Transfer",
					eventAction: "Transfer_Out_Click",
					eventLabel: this.get_member.username,
					eventValue: this.Points.amount,
				});
			} else if (this.Points.type == 3) {
				ga("send", "event", {
					eventCategory: "member_Transfer",
					eventAction: "Transfer_Gift_Click",
					eventLabel: this.get_member.username,
					eventValue: this.Points.amount,
				});
			}
		},
		dialog_Close() {
			this.$validator.reset();
			this.Points.dialog = false;
			this.Points.disabled = false;
			this.Points.amount = "";
			this.Points.description = "";
			this.$store.commit("Add_dialog_close");
			this.$store.commit("HIDE_JOIN_EVENT");
		},

		// room聊天室
		enterRoom() {
			if (this.$route.params.friend_id || this.$route.params.room_code) {
				// console.log('friend',this.$route.params.friend_id)
				// console.log('code',this.$route.params.room_code)
				let params = {
					friend_id: this.$route.params.friend_id
						? this.$route.params.friend_id
						: "",
					code: this.$route.params.room_code
						? this.$route.params.room_code
						: "",
				};
				this.$store.dispatch("action_RoomEnter", params);
			} else {
				console.log("NO params cant open chat");
			}
		},
		chatHistory() {
			//歷史訊息
			let data = {
				code: this.$route.params.room_code,
				page: this.Room.history_page,
				per_page: 30,
			};
			this.$store.dispatch("action_RoomHistory", data);
			// console.log(this.Room.history_page)
			this.Room.history_page++;
		},
		// 傳送訊息
		sendMessage(type) {
			let regex = /\s+/g; //空白
			if (this.Room.sendContent.replace(regex, "") == "") {
				this.Room.sendContent = "";
			} else if (this.Room.sendContent !== "") {
				let sendData = {
					code: this.get_roomMsg.room.code,
					type: type,
					content: this.Room.sendContent,
				};
				if (this.Room.sendContent) {
					this.$store.dispatch("action_sendMessage", sendData);
					this.Room.sendContent = "";
					// this.$store.dispatch('action_RoomList_Status',1)
				} else {
					console.log("nomessage", this.Room.sendContent);
				}
				setTimeout(() => {
					this.watchHight();
				}, 200);
			}
		},

		//上傳圖片
		sendPhoto(e) {
			let file = e.target.files[0];
			if (!/image\/\w/.test(file.type)) {
				alert("請確保檔案為影象型別");
				return false;
			}
			let reader = new FileReader();
			let vm = this;
			reader.readAsDataURL(file);
			reader.onload = function (event) {
				vm.dealImage(this.result, { width: 1200 }, function (base) {
					vm.Room.sendImg = base;
					let sendData = {
						code: vm.get_roomMsg.room.code,
						type: 2,
						content: vm.Room.sendImg,
					};
					// console.log("sendimg",sendData)
					vm.$store.dispatch("action_sendMessage", sendData);
				});
				// vm.Room.sendImg = this.result
				// console.log('img',vm.Room.sendImg)
				// let sendData = {
				//     "code": vm.get_roomMsg.room.code,
				//     "type": 2,
				//     "content": this.result
				// }
				// console.log("data",sendData)
				// vm.$store.dispatch('action_sendMessage',sendData)
			};
		},
		dealImage(path, obj, callback) {
			var img = new Image();
			img.src = path;
			img.onload = function () {
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
		zoom(img) {
			//圖片放大
			this.Room.zoomImg = img;
			this.Room.img_dialog = true;
		},
		watchHight() {
			//己方輸入訊息滑動視窗位置
			let roomScroll = document.getElementById("roomScroll");
			let element = document.getElementById("roomScroll").firstChild;
			let h = element.offsetHeight;
			// console.log('roomScroll:',roomScroll,' h:',h)
			roomScroll.scroll({
				top: h,
				behavior: "smooth",
			});
		},
	},
	watch: {
		msgStatus() {
			//監聽新訊息滑動視窗位置
			let l = this.get_roomMsg.message.length;
			// console.log('新訊息長度:',l)
			// console.log('原始訊息長度:',this.Room.Ary_length)
			if (this.Room.first_load || l - this.Room.Ary_length == 1) {
				setTimeout(() => {
					let roomScroll = document.getElementById("roomScroll");
					let element = document.getElementById("roomScroll").firstChild;
					let h = element.offsetHeight;
					roomScroll.scroll({
						top: h,
					});
				}, 200);
				this.Room.first_load = false;
				this.Room.Ary_length = l;
			} else {
				let vm = this;
				setTimeout(() => {
					if (l > vm.Room.Ary_length) {
						let roomScroll = document.getElementById("roomScroll");
						let h = (l - vm.Room.Ary_length) * 106;
						roomScroll.scroll({
							top: h - 150,
						});
						vm.Room.Ary_length = l;
					}
				}, 200);
			}
		},
		roterCode() {
			// 歷史紀錄 換房間後需要重置搜尋條件，預防搜錯頁
			this.Room.history_page = 2;
			this.Room.first_load = true;
		},
	},
	created() {
		this.enterRoom();
		this.getOrderTypes();
		this.resetEditBankAccountStatus();
	},
};
</script>
<style lang="css" scoped>
.eventLock {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	width: 100%;
	height: 570px;
	background-color: #7c7a7abf;
	z-index: 9999;
	margin: auto;
	text-align: center;
	padding-top: 200px;
	color: red;
}
@media (max-width: 500px) {
	.eventLock {
		height: 460px;
	}
}
.blockChat {
	width: 100%;
	height: 100vh;
	position: fixed;
	top: 0;
	right: 0;
	z-index: 9998;
	/* background-color: #9e9e9e61; */
	background-color: #ffffff00;
}
.timeRight {
	text-align: right;
}
.sendMessage >>> .v-text-field__details {
	bottom: 0;
	left: 0;
}
textarea.sendMessage {
	border: 1px solid rgba(0, 0, 0, 0.12);
	background-color: rgba(0, 0, 0, 0.03);
	overflow-y: auto; /* IE */
	resize: none;
	width: 100%;
}
.haha {
	align-items: flex-start;
}
.haha div {
	width: 40%;
	max-height: 200px;
}
.haha div img {
	max-height: 200px;
	max-width: 100%;
	display: inline;
	float: right;
}
.zoom_block {
	display: block;
	position: fixed;
	/* max-height: 100vh; */
	width: 100%;
	height: 100vh;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	margin: auto;
	z-index: 999;
	background-color: #202020;
}

.chat-room--scrollbar {
	height: calc(100vh - 48px - 60px);
}
@media screen and (max-width: 500px) {
	.v-card__actions {
		padding: 8px 4px;
	}
}
@media screen and (min-width: 750px) {
	.zoom_block div {
		max-width: 50%;
		max-height: 80vh;
		position: absolute;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		margin: auto;
	}
}
.zoom_block img {
	max-width: 100%;
	max-height: 80vh;
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	margin: auto;
}
.roomHisory {
	text-align: center;
	cursor: pointer;
	color: #3f51b5;
}
.roomHisory:hover {
	color: #f44336;
}
.envelope > div {
	position: relative;
	width: 75%;
	margin-left: 25%;
}
.envelope img {
	width: 100%;
	vertical-align: bottom;
}
.envelope span {
	position: absolute;
	top: 65%;
	right: 50%;
	transform: translateX(50%);
}
.envelope h5 {
	position: absolute;
	top: 20%;
	right: 50%;
	transform: translateX(50%);
	color: gold;
	width: 90%;
	text-align: center;
}
.smallText {
	font-size: 10px;
	color: #888;
}
</style>
