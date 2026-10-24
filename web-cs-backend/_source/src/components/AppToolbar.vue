<template>
	<v-toolbar color="primary" fixed dark app>
		<v-toolbar-title class="ml-0 pl-3">
			<v-toolbar-side-icon @click.stop="handleDrawerToggle"></v-toolbar-side-icon>
		</v-toolbar-title>
		<!-- <v-text-field
        flat
        solo-inverted
        prepend-icon="search"
        label="Search"
        class="hidden-sm-and-down"
        >
		</v-text-field>-->
		<v-spacer></v-spacer>
		<!-- <v-btn href="mailto:user@example.invalid">
        Hire Me
      </v-btn>      
      <v-btn icon href="https://example.invalid/tookit/vue-material-admin">
        <v-icon>fa fa-github</v-icon>
		</v-btn>-->
		<h5
			style="border:1px solid rgba(255,255,255,.4);padding:2px 12px;margin-left:3px;min-width:100px;text-align:center;border-radius: 10px;"
		>{{ $t('share_code') }}: {{ sharecode }}</h5>
		<v-btn icon @click="copyShare()" class="coby" :data-clipboard-text="copyLink">
			<v-icon>file_copy</v-icon>
		</v-btn>
		<!-- <v-menu offset-y origin="center center" class="elelvation-1" :nudge-bottom="14" transition="scale-transition">
        <v-btn icon flat slot="activator">
        <v-badge color="red" overlap>
          <span slot="badge">3</span>
          <v-icon medium>notifications</v-icon>
        </v-badge>
        </v-btn>
        <notification-list></notification-list>
		</v-menu>-->
		<v-menu offset-y origin="center center" :nudge-bottom="10" transition="scale-transition">
			<v-btn icon large flat slot="activator">
				<v-avatar size="30px">
					<img :src="langauge" />
				</v-avatar>
			</v-btn>
			<v-list class="pa-0">
				<v-list-tile
					v-for="(item, index) in langs"
					:to="item.href"
					ripple="ripple"
					@click="item.click"
					rel="noopener"
					:key="index"
				>
					<v-avatar size="30px" style="margin-right: 25px">
						<img :src="item.icon" />
					</v-avatar>
					<v-list-tile-content>
						<v-list-tile-title>{{ item.title }}</v-list-tile-title>
					</v-list-tile-content>
				</v-list-tile>
			</v-list>
		</v-menu>
		<v-menu offset-y origin="center center" :nudge-bottom="10" transition="scale-transition">
			<v-btn icon large flat slot="activator">
				<v-avatar size="30px" v-bind:title="userName">
					<img v-if="Avatar !== 'null'" :src="Avatar ? Avatar : defaultAvatar" />
					<img v-else :src="defaultAvatar" />
				</v-avatar>
			</v-btn>
			<v-list class="pa-0">
				<v-list-tile
					v-for="(item, index) in items"
					:to="item.href"
					ripple="ripple"
					@click="item.click"
					rel="noopener"
					:key="index"
				>
					<v-list-tile-action v-if="item.icon">
						<v-icon>{{ item.icon }}</v-icon>
					</v-list-tile-action>
					<v-list-tile-content>
						<v-list-tile-title>{{ item.title }}</v-list-tile-title>
					</v-list-tile-content>
				</v-list-tile>
			</v-list>
		</v-menu>
		<div></div>
	</v-toolbar>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import NotificationList from "@/components/widgets/list/NotificationList";
import Util from "@/util";
// 複製外掛 clipboard
import clipboard from "clipboard";
export default {
	name: "app-toolbar",
	components: {
		NotificationList
	},
	data() {
		return {
			langs: [
				{
					icon: require("@/../static/language/taiwan_icon.png"),
					title: "繁中",
					click: e => {
						//語系切換點擊click
						localStorage.setItem("LOCALE", "zh-tw");
						window.getApp.$router.go(0);
					}
				},
				{
					icon: require("@/../static/language/china_icon.png"),
					title: "简中",
					click: e => {
						localStorage.setItem("LOCALE", "zh-cn");
						window.getApp.$router.go(0);
					}
				},
				{
					icon: require("@/../static/language/usa_icon.png"),
					title: "English",
					click: e => {
						localStorage.setItem("LOCALE", "en");
						window.getApp.$router.go(0);
					}
				},
				{
					icon: require("@/../static/language/indonesia_icon.jpg"),
					title: "Indonesia",
					click: e => {
						localStorage.setItem("LOCALE", "id");
						window.getApp.$router.go(0);
					}
				},
				{
					icon: require("@/../static/language/malaysia_icon.jpg"),
					title: "Malaysia",
					click: e => {
						localStorage.setItem("LOCALE", "ms-my");
						window.getApp.$router.go(0);
					}
				}
			],
			userName: sessionStorage.getItem("user_name"),
			langauge: "",
			defaultAvatar: require("@/../static/avatar/user.png"),
			Avatar: "",
			items: [
				// {
				//     icon: 'account_circle',
				//     href: '#',
				//     title: 'Profile',
				//     click: (e) => {
				//         console.log(e);
				//     }
				// },
				{
					icon: "settings",
					href: "/setup/self",
					title: this.$t("setting"),
					click: e => {}
				},
				{
					icon: "fullscreen_exit",
					href: "#",
					title: this.$t("logout"),
					click: e => {
						//   window.getApp.$emit('APP_LOGOUT');
						window.getApp.$store.dispatch("actionLogout");
					}
				}
			],
			sharecode: "",
			copyLink: ""
		};
	},
	computed: {
		toolbarColor() {
			return this.$vuetify.options.extra.mainNav;
		}
	},
	mounted() {
		setTimeout(() => {
			let shareCodeValue = sessionStorage.getItem("share_code");

			if (shareCodeValue != null && shareCodeValue != "null") {
				this.sharecode = shareCodeValue;
			} else {
				this.sharecode = "none";
			}
			this.Avatar = sessionStorage.getItem("user_avatar");
		}, 2000);
	},
	methods: {
		...mapActions(["actionLogout"]),
		handleDrawerToggle() {
			window.getApp.$emit("APP_DRAWER_TOGGLED");
		},
		handleFullScreen() {
			Util.toggleFullScreen();
		},
		// 分享碼拷貝
		copyShare() {
			var share_domain = sessionStorage.getItem("share_domain").trim();
			if(!share_domain || share_domain === "null") {
				share_domain = 'example.invalid';
			}
			this.copyLink = "https://" + share_domain + "/Signup/" + this.sharecode;
			new clipboard(".coby");
			alert("Copied Success: " + this.copyLink);
		}
	},
	created() {
		if (localStorage.getItem("LOCALE") == "zh-tw") {
			//語系更換圖片
			this.langauge = require("@/../static/language/taiwan_icon.png");
		} else if (localStorage.getItem("LOCALE") == "en") {
			this.langauge = require("@/../static/language/usa_icon.png");
		} else if (localStorage.getItem("LOCALE") == "zh-cn") {
			this.langauge = require("@/../static/language/china_icon.png");
		} else if (localStorage.getItem("LOCALE") == "id") {
			this.langauge = require("@/../static/language/indonesia_icon.jpg");
		} else if (localStorage.getItem("LOCALE") == "ms-my") {
			this.langauge = require("@/../static/language/malaysia_icon.jpg");
		}
	}
};
</script>
