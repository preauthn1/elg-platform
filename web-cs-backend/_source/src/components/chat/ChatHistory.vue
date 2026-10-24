<template>
	<div class="chat-history">
		<!-- <v-toolbar dense class="chat-history-toolbar">
      <v-text-field flat solo full-width clearable prepend-icon="search" label="Search"></v-text-field>
		</v-toolbar>-->
		<vue-perfect-scrollbar class="chat-history--scrollbar">
			<v-divider></v-divider>
			<v-list two-line class="chat-history--list" style="padding-top:0">
				<v-subheader>History</v-subheader>
				<v-divider></v-divider>
				<div class="check_block">
					<button @click="roomHide()">{{ $t('room_hide') }}</button>
					<div>
						<label for="selectAll">{{ $t('selectAll') }}</label>
						<input
							class="hide_input"
							id="selectAll"
							type="checkbox"
							:checked="get_roomList_show.length === checked.length"
							@change="all()"
						/>
					</div>
				</div>
				<template v-for="(item, index) in get_roomList_show">
					<div class="roomBlock" :key="index">
						<v-divider></v-divider>
						<v-list-tile
							class="chat-list"
							:class="{active: chatStatusColor(item)}"
							avatar
							:to="chatRoute(item)"
							@click="roomEnter(item)"
						>
							<v-list-tile-avatar>
								<!-- <v-list-tile-avatar :color="randomAvatarColor(item)"> -->
								<img
									v-if="item.thumbnail !== 'null'"
									:src="item.thumbnail ? item.thumbnail : defaultAvatar"
								/>
								<img v-else :src="defaultAvatar" />
								<!-- <img :src="item.user.avatar" v-if="item.users.length === 1">
								<span v-else class="white--text headline">{{ firstLetter(item.title)}}</span>-->
							</v-list-tile-avatar>
							<v-list-tile-content>
								<v-list-tile-title>
									{{ item.name }}
									<span style="color:#AAA;margin-left:5px;">({{item.username}})</span>
								</v-list-tile-title>
								<v-list-tile-sub-title>
									{{
									item.message_content
									}}
								</v-list-tile-sub-title>
							</v-list-tile-content>
							<v-list-tile-action>
								<v-list-tile-action-text>{{ formatChatTime(item.message_time) }}</v-list-tile-action-text>
								<!-- 豆豆隱藏 -->
								<!-- <v-circle dot small :color="chatStatusColor(item)"></v-circle> -->
								<v-circle dot small></v-circle>
							</v-list-tile-action>
						</v-list-tile>
						<!-- <a class="hide_btn" @click="roomHide(item)">{{$t('room_hide')}}</a> -->
						<input class="hide_input" type="checkbox" name :value="item.code" v-model="checked" />
					</div>
				</template>
			</v-list>
		</vue-perfect-scrollbar>
	</div>
</template>

<script>
require("howler");
import Global from "@/Global";
import md5 from "js-md5";
import { mapGetters } from "vuex";
import { Groups } from "@/api/chat";
import { getUserById } from "@/api/user";
import VCircle from "@/components/circle/VCircle";
import Util from "@/util";
import VuePerfectScrollbar from "vue-perfect-scrollbar";
import { watch } from "fs";
export default {
	components: {
		VuePerfectScrollbar,
		VCircle,
	},
	computed: {
		...mapGetters(["get_roomList_show", "get_newMark"]),
	},
	data() {
		return {
			defaultAvatar: require("@/../static/avatar/user.png"),
			chats: Groups,
			sound_count: 0,
			checked: [],
			hide_count: 0,
			EventMode: sessionStorage.getItem("EventMode"),
		};
	},
	methods: {
		all() {
			//聊天室列表全部選取
			if (this.get_roomList_show.length === this.checked.length) {
				this.checked = [];
			} else {
				this.checked = [];
				this.get_roomList_show.forEach((room) => {
					//狀態顯示的聊天室加到checked
					this.checked.push(room.code);
				});
			}
		},
		roomHide() {
			//聊天室隱藏
			//   this.checked 要隱藏的聊天室ROOM CODE
			this.checked.forEach((code) => {
				this.hide_count++;
				// console.log('hide '+code+'  '+this.hide_count)
				this.$store.dispatch("action_RoomHide", code).then((res) => {
					// 非同步，等hide跑完回傳true，count--
					if (res) {
						this.hide_count--;
					}
					// 當所有hide跑完即count減至0，執行列表更新
					if (this.hide_count == 0) {
						this.$store.dispatch("action_RoomList_Status", 1);
					}
					// console.log('finish',this.hide_count)
				});
			});
		},
		roomEnter(room) {
			//進入房間
			this.$store.commit("clearMark", room.code);
			let data = {
				code: room.code ? room.code : "",
				friend_id: room.friend_id ? room.friend_id : "",
			};
			this.$store.dispatch("action_RoomEnter", data);
		},
		//////////////////
		chatRoute(item) {
			//房間資訊推上路由
			return (
				"/chat/messaging/" + item.id + "/" + item.members + "/" + item.code
			);
		},
		// firstLetter (title) {
		//     return title.charAt(0);
		// },
		formatChatTime(s) {
			//聊天室訊息時間轉換
			let newDate;
			if (s) {
				newDate = s.replace(/-/g, "/");
			}
			return new Date(newDate).toLocaleDateString();
		},
		// computeTitle (item) {
		//     let username = (item.users.length === 1) ? getUserById(item.users[0]).username : '';
		//     return item.users.length === 1 ? username : item.title;
		// },
		// randomAvatarColor (item) {
		//     return item.users.length === 1
		//     ? ''
		//     : Util.randomElement(['blue', 'indigo', 'success', 'error', 'pink']);
		// },
		//新訊息橘橘黃黃方方的亮亮框
		chatStatusColor(item) {
			//   console.log('item', item)
			// return Util.randomElement(['blue', 'indigo', 'success', 'error', 'pink']);
			if (this.get_newMark.includes(item.code)) {
				// return Util.randomElement(['pink'])
				return true;
			} else {
				// return Util.randomElement(['blue'])
				return false;
			}
		},
	},
	created() {
		// 狀態1 是沒有隱藏 狀態2在HIDE
		this.$store.dispatch("action_RoomList_Status", 1);
		// if(this.$route.params.friend_id || this.$route.params.room_code) {
		//     console.log('friend',this.$route.params.friend_id)
		//     console.log('code',this.$route.params.room_code)
		//     let params = {
		//         "friend_id": this.$route.params.friend_id?this.$route.params.friend_id:'',
		//         "code": this.$route.params.room_code?this.$route.params.room_code:'',
		//     }
		//     this.roomEnter(params)
		// } else {
		//     console.log('NO params cant open chat')
		// }
		this.$store.dispatch("action_listen_list"); //監聽聊天室
	},
};
</script>
<style lang="css" scoped>
.check_block {
	padding: 10px 20px;
	display: flex;
	justify-content: space-around;
}
.check_block > div {
	padding-top: 2px;
}
.check_block label,
.check_block input {
	vertical-align: middle;
}
.check_block button {
	/* border: 1px solid rgba(0,0,0,.3); */
	background-color: rgba(0, 0, 0, 0.2);
	padding: 2px 8px;
	box-shadow: 0px 0px 2px 0px rgba(0, 0, 0, 0.6);
}
.active {
	background-color: rgba(255, 230, 190, 1);
}
@media screen and (max-width: 959px) {
	.chat-history--scrollbar {
		height: calc(100vh - 56px);
	}
	.v-subheader {
		height: 56px;
	}
}
.roomBlock {
	position: relative;
}
.roomBlock input {
	position: absolute;
	bottom: 12px;
	right: 20px;
}
.hide_input {
	width: 20px;
	height: 20px;
	border: 1px solid rgba(0, 0, 0, 0.3);
}
</style>
