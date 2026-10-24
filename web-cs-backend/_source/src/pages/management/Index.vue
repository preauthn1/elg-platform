<template>
	<div id="pageTable">
		<v-container grid-list-xl fluid>
			<v-layout row wrap>
				<v-flex lg12>
					<!-- 建立用戶 -->
					<v-dialog id="AddUser" v-model="basic.dialog" persistent max-width="500px">
						<v-btn v-if="show_addUser" color="primary" dark slot="activator">
							{{
							addTitle
							}}
						</v-btn>
						<v-card>
							<v-card-title style="justify-content:space-between">
								<span class="headline">{{ addTitle }}</span>
								<small>{{ $t('form.Asterisk_Required') }}</small>
							</v-card-title>
							<v-divider></v-divider>
							<v-card-text>
								<!-- <v-container grid-list-md> -->
								<v-layout wrap>
									<v-flex xs12>
										<v-text-field
											:label="$t('form.username') + ' *'"
											v-model="basic.username"
											v-validate="'required|min:4|max:10|'"
											:data-vv-name="$t('form.username')"
											:error-messages="errors.collect($t('form.username'))"
										></v-text-field>
									</v-flex>
									<v-flex xs12>
										<v-text-field :label="$t('form.nickname')" v-model="basic.nickname"></v-text-field>
									</v-flex>
									<v-flex xs12>
										<v-text-field
											:label="$t('form.password') + ' *'"
											v-model="basic.password"
											v-validate="'required|min:4|max:15|alpha_num'"
											:data-vv-name="$t('form.password')"
											:error-messages="errors.collect($t('form.password'))"
											:append-icon="p1 ? 'visibility' : 'visibility_off'"
											:append-icon-cb="() => (p1 = !p1)"
											:type="p1 ? 'password' : 'text'"
											counter="15"
										></v-text-field>
									</v-flex>
									<v-flex xs12>
										<v-text-field
											:label="$t('form.passConfirm') + ' *'"
											v-model="basic.passConfirm"
											v-validate="'required|min:4|max:15|alpha_num'"
											:data-vv-name="$t('form.passConfirm')"
											:error-messages="errors.collect($t('form.passConfirm'))"
											:append-icon="p2 ? 'visibility' : 'visibility_off'"
											:append-icon-cb="() => (p2 = !p2)"
											:type="p2 ? 'password' : 'text'"
											counter="15"
										></v-text-field>
									</v-flex>
									<!-- 餘額 -->
									<!-- <v-flex xs12>
                    <v-text-field
                      :label="$t('form.limit_of_credit')"
                      v-model="basic.limit_of_credit"
                      v-validate="'required|numeric'"
                      :data-vv-name="$t('form.amount')"
                      :error-messages="errors.collect( $t('form.amount') )"
                    ></v-text-field>
									</v-flex>-->
									<v-flex xs12>
										<h5>{{ $t('form.avatar') }}</h5>
										<img style="width:30%;vertical-align:top" :src="basic.thumbnail" />
										<input style="width:65%;vertical-align:top" type="file" @change="addUser_Img" />
									</v-flex>
								</v-layout>
								<!-- </v-container> -->
							</v-card-text>
							<v-card-actions>
								<v-spacer></v-spacer>
								<v-btn color="blue darken-1" flat @click.native="clear()">
									{{
									$t('form.cancel')
									}}
								</v-btn>
								<v-btn color="blue darken-1" flat @click="addUser()">
									{{
									$t('form.create')
									}}
								</v-btn>
							</v-card-actions>
						</v-card>
					</v-dialog>
					<!-- 編輯用戶 -->
					<v-dialog id="EditUser" v-model="Edit.dialog" persistent max-width="500px">
						<v-card>
							<v-card-title style="justify-content:space-between">
								<span class="headline">{{ editTitle }} {{ Edit.username }}</span>
								<small>{{ $t('form.Asterisk_Required') }}</small>
							</v-card-title>
							<v-divider></v-divider>
							<v-card-text>
								<!-- <v-container grid-list-sd> -->
								<v-layout wrap>
									<v-flex xs12>
										<v-text-field :label="$t('form.nickname')" v-model="Edit.nickname"></v-text-field>
									</v-flex>
									<!-- <v-flex xs12>
                    <v-text-field
                      :label="$t('form.password')"
                      v-model="Edit.password"
                      v-validate="'min:6|max:12|alpha_num'"
                      data-vv-name="Password"
                      :error-messages="errors.collect('Password')"
                      :append-icon="p3 ? 'visibility' : 'visibility_off'"
                      :append-icon-cb="() => (p3 = !p3)"
                      :type="p3 ? 'password' : 'text'"
                      counter="15"
                    ></v-text-field>
                  </v-flex>
                  <v-flex xs12>
                    <v-text-field
                      :label="$t('form.passConfirm')"
                      v-model="Edit.passConfirm"
                      v-validate="'min:6|max:12|alpha_num'"
                      data-vv-name="PassConfirm"
                      :error-messages="errors.collect('PassConfirm')"
                      :append-icon="p4 ? 'visibility' : 'visibility_off'"
                      :append-icon-cb="() => (p4 = !p4)"
                      :type="p4 ? 'password' : 'text'"
                      counter="15"
                    ></v-text-field>
									</v-flex>-->
									<!-- 狀態 -->
									<v-flex xs12>
										<v-select
											:items="Status"
											v-model="Edit.status"
											:label="$t('list.status')"
											item-text="text"
											item-value="value"
										></v-select>
									</v-flex>
									<!-- 會員同意 -->
									<v-flex xs12>
										<!-- {{Edit.withdraw_confirm}} -->
										<v-select
											:items="WithdrawConfirm"
											v-model="Edit.withdraw_confirm"
											:label="$t('list.withdraw_confirm')"
											item-text="text"
											item-value="val"
										></v-select>
									</v-flex>
									<!-- 餘額 -->
									<!-- <v-flex xs12>
                    <v-text-field
                      :label="$t('form.limit_of_credit')+ ' *'"
                      v-model="Edit.limit_of_credit"
                      v-validate="'required|numeric'"
                      :data-vv-name="$t('form.editAmount')"
                      :error-messages="errors.collect($t('form.editAmount'))"
                    ></v-text-field>
									</v-flex>-->
									<v-flex xs12>
										<h5>{{ $t('form.avatar') }}</h5>
										<img
											style="width:30%;vertical-align:top"
											:src="Edit.thumbnail ? Edit.thumbnail : defaultAvatar"
										/>
										<!-- <img style="width:100px;vertical-align:bottom" :src="defaultAvatar"> -->
										<input style="width:65%;vertical-align:top" type="file" @change="editUser_Img" />
									</v-flex>
								</v-layout>
								<!-- </v-container> -->
							</v-card-text>
							<v-card-actions>
								<v-spacer></v-spacer>
								<v-btn color="blue darken-1" flat @click.native="clear()">
									{{
									$t('form.cancel')
									}}
								</v-btn>
								<v-btn color="blue darken-1" flat @click="edit()">
									{{
									$t('form.save')
									}}
								</v-btn>
							</v-card-actions>
						</v-card>
					</v-dialog>
					<!-- 開洗分 -->
					<v-dialog id="EditLimitUser" v-model="LimitEdit.dialog" persistent max-width="500px">
						<v-card>
							<v-card-title style="justify-content:space-between">
								<span class="headline">{{ $t('list.transaction') }}</span>
								<small>{{ $t('form.Asterisk_Required') }}</small>
							</v-card-title>
							<v-divider></v-divider>
							<v-card-text>
								<!-- <v-container grid-list-sd> -->
								<v-layout wrap>
									<v-flex xs12>
										<p>{{ $t('form.limit_of_credit') }}: {{ LimitEdit.balance }}</p>
									</v-flex>
									<!-- 餘額 -->
									<v-flex xs12>
										<v-text-field
											:label="
                        $t('form.amount') + ' ' + $t('form.minus_is_out') + ' *'
                      "
											v-model="LimitEdit.amount"
										></v-text-field>
									</v-flex>
									<!-- 備註 -->
									<v-flex xs12>
										<v-textarea textarea :label="$t('form.remarks') + ' *'" v-model="LimitEdit.remarks"></v-textarea>
									</v-flex>
								</v-layout>
								<!-- </v-container> -->
							</v-card-text>
							<v-card-actions>
								<v-spacer></v-spacer>
								<v-btn color="blue darken-1" flat @click.native="clear()">
									{{
									$t('form.cancel')
									}}
								</v-btn>
								<v-btn
									color="blue darken-1"
									flat
									@click="
                    User_transferIn()
                    GA_Agent_transfer()
                  "
								>{{ $t('form.save') }}</v-btn>
							</v-card-actions>
						</v-card>
					</v-dialog>
					<!-- 凍結用戶 -->
					<v-dialog id="deleteUser" v-model="Del.dialog" persistent max-width="400px">
						<v-card>
							<v-card-title>
								<p class="headline" style="margin:auto">{{ delTitle }} {{ Del.username }}</p>
							</v-card-title>
							<v-card-actions>
								<v-spacer></v-spacer>
								<v-btn color="blue darken-1" flat @click.native="clear()">
									{{
									$t('form.cancel')
									}}
								</v-btn>
								<v-btn color="blue darken-1" flat @click="freeze()">
									{{
									$t('form.confirm')
									}}
								</v-btn>
							</v-card-actions>
						</v-card>
					</v-dialog>
				</v-flex>
				<!-- 營運商克服搜尋 -->
				<!-- <v-flex lg6>
          <v-card>
            <v-card-text>
                <v-form ref="form" lazy-validation>
                  <v-layout row wrap>
                    <v-flex lg12 sm12>
                        <v-text-field :label="$t('form.username')" name="title" v-model="User.username">
                        </v-text-field>
                    </v-flex>
                    <v-flex lg12 sm12>
                        <v-text-field :label="$t('form.nickname')" name="title" v-model="User.nickname">
                        </v-text-field>
                    </v-flex> 
                    <v-spacer></v-spacer>
                  </v-layout>        
                </v-form>
            </v-card-text>
            <v-card-actions class="pb-3">
                <v-spacer></v-spacer>
                <v-btn color="primary" @click="searchUser()">{{ $t('form.search') }}</v-btn>
            </v-card-actions>
          </v-card>
				</v-flex>-->
				<v-flex sm12>
					<h3>{{ listTitle }}</h3>
				</v-flex>
				<!-- <v-flex sm12>
            <v-card>
                <table>
                    <thead>
                        <tr>
                            <th>{{$t('list.username')}}</th>
                            <th>{{$t('list.nickname')}}</th>
                            <th>{{$t('list.status')}}</th>
                            <th>{{$t('list.limit_of_credit')}}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(user, i) in get_userList" :key="i">
                            <td>{{user.username}}</td>
                            <td>{{user.nickname}}</td>
                            <td>{{user.status}}</td>
                            <td>{{user.limit_of_credit}}</td>
                            <td>
                                <v-btn depressed outline icon fab dark color="primary" small @click="editDialog(user)">
                                    <v-icon>edit</v-icon>
                                </v-btn>
                                <v-btn depressed outline icon fab dark color="pink" small @click="freezeDialog(user)">
                                    <v-icon>delete</v-icon>
                                </v-btn>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <footer>
                    <a @click="turnPage(get_userPages.previous)">{{get_userPages.previous}}</a>
                    <a @click="turnPage(get_userPages.current)">{{get_userPages.current}}</a>
                    <a @click="turnPage(get_userPages.next)">{{get_userPages.next}}</a>
                    <a>all {{get_userPages.pages}}</a>
                </footer>
            </v-card>
				</v-flex>-->
				<v-flex lg12>
					<v-card>
						<v-toolbar card color="white">
							<v-text-field
								flat
								solo
								prepend-icon="search"
								placeholder="Type something"
								v-model="search"
								hide-details
								class="hidden-sm-and-down"
							></v-text-field>
							<v-btn icon>
								<v-icon>filter_list</v-icon>
							</v-btn>
						</v-toolbar>
						<v-divider></v-divider>
						<v-card-text class="pa-0">
							<v-data-table
								:headers="complex.headers"
								:search="search"
								:items="get_userList"
								:rows-per-page-items="[10, 25, 50, {text: 'All', value: -1}]"
								class="elevation-1"
								item-key="name"
								v-model="complex.selected"
							>
								<template slot="items" slot-scope="props">
									<td>{{ props.item.username }}</td>
									<td>{{ props.item.nickname }}</td>
									<td>
										<!-- {{props.item.status}} -->
										{{
										props.item.status
										? $t('form.status_1')
										: $t('form.status_0')
										}}
									</td>
									<td>
										<!-- {{ props.item.withdraw_confirm}} -->
										{{
										props.item.withdraw_confirm == 1
										? $t('form.agree_1')
										: $t('form.agree_0')
										}}
									</td>
									<td>{{ (props.item.limit_of_credit - props.item.used_credit).toFixed(2) }}</td>
									<td>
										<v-btn
											v-if="can_Transaction"
											depressed
											outline
											icon
											fab
											dark
											color="orange"
											small
											@click="limitDialog(props.item)"
										>
											<v-icon>attach_money</v-icon>
										</v-btn>
										<v-btn
											v-if="can_Edit"
											depressed
											outline
											icon
											fab
											dark
											color="primary"
											small
											@click="editDialog(props.item)"
										>
											<v-icon>edit</v-icon>
										</v-btn>
										<v-btn
											v-if="can_Delete"
											depressed
											outline
											icon
											fab
											dark
											color="pink"
											small
											@click="freezeDialog(props.item)"
										>
											<v-icon>delete</v-icon>
										</v-btn>
									</td>
								</template>
							</v-data-table>
						</v-card-text>
					</v-card>
				</v-flex>
			</v-layout>
		</v-container>
	</div>
</template>

<script>
import VWidget from "@/components/VWidget";
import { mapGetters } from "vuex";
export default {
	components: {
		VWidget
	},
	computed: {
		...mapGetters(["get_userList", "get_userPages", "getMenu"]),
		addTitle() {
			//共用模組:建立名稱依router.meta切換是營運商還是客服人員
			const name = this.$route.meta.addTitle;
			const title = this.$t("router." + name);
			return title;
		},
		editTitle() {
			//編輯名稱依meta切換
			const name = this.$route.meta.editTitle;
			const title = this.$t("router." + name);
			return title;
		},
		delTitle() {
			//刪除名稱依meta切換
			const name = this.$route.meta.delTitle;
			const title = this.$t("router." + name);
			return title;
		},
		listTitle() {
			//列表名稱依meta切換
			const name = this.$route.meta.listTitle;
			const title = this.$t("router." + name);
			return title;
		}
	},
	watch: {
		getMenu() {
			//監聽menu判斷功能開關
			this.btn();
		},
		$route: function(id) {
			console.log("change");
			this.getTableData();
		}
	},
	data() {
		return {
			// 開關
			can_Transaction: false,
			can_Edit: false,
			can_Delete: false,
			//
			defaultAvatar: require("@/../static/avatar/user.png"),
			p1: true,
			p2: true,
			p3: true,
			p4: true,
			show_addUser: false,
			basic: {
				// AddUSER
				dialog: false,
				username: "",
				nickname: "",
				password: "",
				passConfirm: "",
				thumbnail: ""
			},
			Del: {
				// deleteUser
				dialog: false,
				userID: "",
				username: ""
			},
			Edit: {
				// EditUser
				dialog: false,
				id: "",
				username: "",
				nickname: "",
				password: "",
				passConfirm: "",
				thumbnail: "",
				status: 1,
				withdraw_confirm: 1
			},
			LimitEdit: {
				dialog: false,
				balance: 0,
				id: "",
				username: "",
				amount: 0,
				remarks: ""
			},
			Status: [
				{ text: this.$t("form.status_1"), value: 1 },
				{ text: this.$t("form.status_0"), value: 0 }
			],
			WithdrawConfirm: [
				{ text: this.$t("form.agree_1"), val: "1" },
				{ text: this.$t("form.agree_0"), val: "0" }
			],
			search: "",
			// dom :headers="complex.headers"
			complex: {
				selected: [],
				headers: [
					{
						text: this.$t("list.username"),
						value: "username"
					},
					{
						text: this.$t("list.nickname"),
						value: "nickname"
					},
					{
						text: this.$t("list.status"),
						value: "status"
					},
					{
						text: this.$t("list.withdraw_confirm"),
						value: "withdraw_confirm"
					},
					{
						text: this.$t("list.balance"),
						value: "limit_of_credit"
					},
					{
						text: "",
						value: ""
					}
				],
				items: this.get_userList
			},
			User: {
				username: "",
				nickname: ""
			}
		};
	},
	//   mounted() {
	//     setTimeout(() => {
	//       console.log('this.get_userList', this.get_userList)
	//     }, 5000)
	//   },
	methods: {
		// 建立
		addUser_Img(e) {
			let file = e.target.files[0];
			let reader = new FileReader();
			let vm = this;
			reader.readAsDataURL(file);
			reader.onload = function() {
				vm.basic.thumbnail = this.result;
			};
		},
		addUser() {
			this.$validator.validateAll().then(vaild => {
				// console.log(vaild)
				if (vaild) {
					let user = {
						username: this.basic.username,
						nickname: this.basic.nickname,
						password: [REDACTED],
						passConfirm: this.basic.passConfirm,
						thumbnail: this.basic.thumbnail
					};
					this.$store.dispatch("action_AddUser", user);
					//   this.$store.dispatch('action_AddUser', user).then(code => {
					//     if (code == 1000) {
					//       this.basic.username = ''
					//       this.basic.nickname = ''
					//       this.basic.password = ''
					//       this.basic.passConfirm = ''
					//       this.basic.thumbnail = ''
					//     }
					//   })
					this.basic.username = "";
					this.basic.nickname = "";
					this.basic.password = "";
					this.basic.passConfirm = "";
					this.basic.thumbnail = "";
					this.basic.dialog = false;
				}
			});
		},
		clear() {
			this.$validator.reset();
			this.basic.dialog = false;
			this.Edit.dialog = false;
			this.Del.dialog = false;

			this.LimitEdit.dialog = false;
			this.LimitEdit.amount = null;
		},
		// 凍結
		freezeDialog(user) {
			this.Del.dialog = true;
			this.Del.userID = user.id;
			this.Del.username = user.username;
		},
		freeze() {
			this.Del.dialog = false;
			this.$store.dispatch("action_DeleteUser", this.Del.userID);
		},
		// 編輯
		editUser_Img(e) {
			let file = e.target.files[0];
			let reader = new FileReader();
			let vm = this;
			reader.readAsDataURL(file);
			reader.onload = function() {
				vm.Edit.thumbnail = this.result;
			};
		},
		editDialog(user) {
			//   console.log('editDialoguser:', user)
			this.Edit.dialog = true;
			this.Edit.id = user.id;
			this.Edit.username = user.username;
			this.Edit.nickname = user.nickname;
			this.Edit.status = user.status;
			this.Edit.withdraw_confirm = user.withdraw_confirm.toString();
			this.Edit.thumbnail = user.thumbnail;
		},
		edit() {
			this.Edit.dialog = false;
			this.LimitEdit.dialog = false;
			let user = {
				id: this.Edit.id,
				nickname: this.Edit.nickname,
				// password: [REDACTED],
				// passConfirm: this.Edit.passConfirm,
				status: this.Edit.status,
				withdraw_confirm: this.Edit.withdraw_confirm
			};
			//   console.log('edituser:', user)
			if (this.Edit.thumbnail) {
				let https = this.Edit.thumbnail.split(":");
				if (https[0] !== "https") {
					user["thumbnail"] = this.Edit.thumbnail;
				}
			}
			this.$store.dispatch("action_EditUser", user);
		},
		/// 開洗分
		limitDialog(user) {
			//   console.log('user', user)
			this.LimitEdit.dialog = true;
			this.LimitEdit.id = user.id;
			this.LimitEdit.username = user.username;
			this.LimitEdit.balance = user.limit_of_credit - user.used_credit;
		},
		User_transferIn() {
			let data = {
				id: this.LimitEdit.id,
				amount: this.LimitEdit.amount,
				remark: this.LimitEdit.remarks
			};

			this.$store.dispatch("action_UserTransaction", data);
			this.LimitEdit.dialog = false;
			this.LimitEdit.amount = null;
		},
		GA_Agent_transfer() {
			// console.log('ga',this.$ga)
			ga("send", "event", {
				eventCategory: "agent_Transfer",
				eventAction: "agent_Transfer_Click",
				eventLabel: this.LimitEdit.username,
				eventValue: this.LimitEdit.amount
			});
		},
		//分頁
		turnPage(page) {
			let data = {
				username: this.User.username,
				nickname: this.User.nickname,
				page: page
			};
			this.$store.dispatch("action_UserList", data);
		},
		searchUser() {
			let data = {
				username: this.User.username,
				nickname: this.User.nickname,
				page: 1
			};
			this.$store.dispatch("action_UserList", data);
		},
		btn() {
			// 功能開關
			if (this.getMenu.length > 0) {
				// 判斷有無取道資料，如頁面重整api資料延遲，watch getMenu會再執行一次btn()
				let obj = this.getMenu.filter(e => {
					if (this.$route.fullPath == "/agent") {
						return e.id == 2; // 營運商選單/agent id=2
					} else if (this.$route.fullPath == "/customer_service") {
						return e.id == 3; // 客服選單/customer_service id=3
					}
				});
				let string = obj[0].action;
				let actionAry = string.split(",");
				// 判斷getMenu(menu api)內進入的頁面有沒有功能開關action，action內有的才開放
				// console.log('actionAry', actionAry)
				if (actionAry.indexOf("transaction") !== -1) {
					//開洗分功能
					this.can_Transaction = true;
					console.log("transaction");
				}
				if (actionAry.indexOf("edit") !== -1) {
					//編輯功能
					this.can_Edit = true;
					console.log("edit");
				}
				if (actionAry.indexOf("delete") !== -1) {
					//封鎖功能
					this.can_Delete = true;
					console.log("delete");
				}
			}
		},
		getTableData() {
			let user_list_role = this.$route.fullPath == "/agent" ? 2 : 3; //判斷角色編號顯示營運商/客服列表
			this.$store.dispatch("action_UserList", { role_id: user_list_role });
		}
	},
	created() {
		this.getTableData();
		if (this.$route.name == "customer_service") {
			//客服人員列表才顯示建立新用戶按鈕
			this.show_addUser = true;
		} else {
			this.show_addUser = false;
		}
		this.btn();
	}
};
</script>
