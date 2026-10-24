<template>
	<v-navigation-drawer
		id="appDrawer"
		:mini-variant.sync="mini"
		fixed
		:dark="$vuetify.dark"
		app
		v-model="drawer"
		width="260"
	>
		<v-toolbar color="primary darken-1" dark>
			<!-- <img v-bind:src="computeLogo" height="36" alt="Vue Material Admin Template"> -->
			<v-toolbar-title class="ml-0 pl-3" style="text-align:center;width:100%;padding-right:16px">
				<!-- <span class="hidden-sm-and-down">Vue Material</span> -->
				<h5>{{ $t('list.balance') }}: {{ balance }}</h5>
			</v-toolbar-title>
		</v-toolbar>
		<vue-perfect-scrollbar class="drawer-menu--scroll" :settings="scrollSettings">
			<v-list dense expand>
				<template v-for="(item, i) in getMenu">
					<!--group with subitems-->
					<v-list-group
						v-if="item.submenu"
						:key="item.name"
						:group="item.group"
						:prepend-icon="item.symbol"
						no-action="no-action"
					>
						<v-list-tile slot="activator" ripple="ripple">
							<v-list-tile-content>
								<v-list-tile-title>
									{{
									$t('menu.' + item.code)
									}}
								</v-list-tile-title>
							</v-list-tile-content>
						</v-list-tile>
						<template v-for="(subItem, i) in item.submenu">
							<!--sub group-->
							<!-- <v-list-group v-if="subItem.items" :key="subItem.name" :group="subItem.group" sub-group="sub-group">
                        <v-list-tile slot="activator" ripple="ripple">
                            <v-list-tile-content>
                                <v-list-tile-title>{{ subItem.title }}</v-list-tile-title>
                            </v-list-tile-content>
                        </v-list-tile>
                        <v-list-tile v-for="(grand, i) in subItem.children" :key="i" :to="genChildTarget(item, grand)" :href="grand.href" ripple="ripple">
                            <v-list-tile-content>
                                <v-list-tile-title>{{ grand.title }}</v-list-tile-title>
                            </v-list-tile-content>
                        </v-list-tile>
							</v-list-group>-->
							<!--child item-->
							<v-list-tile :key="i" :to="subItem.url" ripple="ripple">
								<!-- <v-list-tile :key="i" :to="genChildTarget(item, subItem)" :href="subItem.href" :disabled="subItem.disabled" :target="subItem.target" ripple="ripple"> -->
								<v-list-tile-content>
									<v-list-tile-title>
										<span>
											{{
											$t('menu.' + subItem.code)
											}}
										</span>
									</v-list-tile-title>
								</v-list-tile-content>
								<!-- <v-circle class="white--text pa-0 circle-pill" v-if="subItem.badge" color="red" disabled="disabled">{{ subItem.badge }}</v-circle> -->
								<v-list-tile-action v-if="subItem.symbol">
									<v-icon :class="[subItem.actionClass || 'success--text']">
										{{
										subItem.symbol
										}}
									</v-icon>
								</v-list-tile-action>
							</v-list-tile>
						</template>
					</v-list-group>
					<v-subheader v-else-if="item.header" :key="i">
						{{
						item.header
						}}
					</v-subheader>
					<v-divider v-else-if="item.divider" :key="i"></v-divider>
					<!--top-level link-->
					<v-list-tile
						v-else
						:to="item.url"
						ripple="ripple"
						rel="noopener"
						:key="item.name"
						:target="item.target"
					>
						<!-- <v-list-tile v-else :to="!item.href ? { name: item.name } : null" :href="item.href" ripple="ripple" :disabled="item.disabled" :target="item.target" rel="noopener" :key="item.name"> -->
						<v-list-tile-action v-if="item.symbol">
							<v-icon>{{ item.symbol }}</v-icon>
						</v-list-tile-action>
						<v-list-tile-content>
							<v-list-tile-title>
								{{
								$t('menu.' + item.code)
								}}
							</v-list-tile-title>
						</v-list-tile-content>
						<!-- <v-circle class="white--text pa-0 chip--x-small" v-if="item.badge" :color="item.color || 'primary'" disabled="disabled">{{ item.badge }}</v-circle> -->
						<v-list-tile-action v-if="item.subAction">
							<v-icon class="success--text">{{ item.subAction }}</v-icon>
						</v-list-tile-action>
						<!-- <v-circle class="caption blue lighten-2 white--text mx-0" v-else-if="item.chip" label="label" small="small">{{ item.chip }}</v-circle> -->
					</v-list-tile>
				</template>
			</v-list>
		</vue-perfect-scrollbar>
	</v-navigation-drawer>
</template>
<script>
import { mapGetters } from "vuex";
import menu from "@/api/menu";
import VuePerfectScrollbar from "vue-perfect-scrollbar";
import { setTimeout } from "timers";
export default {
	name: "app-drawer",
	components: {
		VuePerfectScrollbar
	},
	props: {
		expanded: {
			type: Boolean,
			default: true
		}
	},
	data: () => ({
		mini: false,
		drawer: true,
		menus: menu,
		scrollSettings: {
			maxScrollbarLength: 160
		},
		LOCALE: localStorage.getItem("LOCALE")
	}),
	computed: {
		...mapGetters(["getMenu", "get_userView"]),
		balance() {
			//上方使用者開洗分餘額
			if (this.get_userView.limit_of_credit == "-1") {
				// -1 為無上限
				return this.$t("list.unlimited");
			} else {
				if (this.get_userView == null || this.get_userView.length == 0) {
					return "0";
				}
				let myValue =
					this.get_userView.limit_of_credit -
					this.get_userView.used_credit;

				return myValue.toFixed(2);
			}
		}
		// computeGroupActive () {
		//     return true;
		// },
		// computeLogo () {
		//     return '/static/m.png';
		// },
		// sideToolbarColor () {
		//     return this.$vuetify.options.extra.sideNav;
		// }
	},
	watch: {
		/// 權限驗證防呆
		getMenu() {
			let path = window.location.pathname; //取路由path ex:'/setup/system'
			let ary = [];
			this.getMenu.forEach(element => {
				// 取menu item.url 加進 ary[]
				if (element.submenu) {
					element.submenu.forEach(e => {
						let url = e.url;
						ary.push(url);
					});
				} else {
					let url = element.url;
					ary.push(url);
				}
			});
			// console.log('ary',ary)
			let pathitem = path.split("/")[1]; //取path 大項目 ex:'/chat/messaging'=>'chat'
			if (pathitem !== "chat" && pathitem !== "login") {
				//非聊天權限(主頁)
				let auth = ary.indexOf(path);
				//console.log("auth:", auth, "path:", path, "pathitem:", pathitem);
				let transaction =
					"/" + path.split("/")[1] + "/" + path.split("/")[2];
				// console.log('tra',transaction)

				//特定的頁面不檢查權限
				if (path != "/RD_Debug") {
					if (auth == -1 && transaction !== "/transaction/list") {
						// alert(this.$t('noAuthority'))
						this.$router.push("/index");
						// console.log('no chat')
					}
				}
			} else {
				//chat聊天權限(開新分頁)
				let chaturl = "/" + pathitem;
				this.$store.dispatch("action_Authority", chaturl);
			}
		}
	},
	methods: {
		// 防止登入的人用改網址的方式導入沒權限觀看的頁面
		authority() {
			// setTimeout(()=>{
			let path = window.location.pathname; //取路由path ex:'/setup/system'
			let ary = [];
			this.getMenu.forEach(element => {
				// 取menu item.url 加進 ary[]
				if (element.submenu) {
					element.submenu.forEach(e => {
						let url = e.url;
						ary.push(url);
					});
				} else {
					let url = element.url;
					ary.push(url);
				}
			});
			console.log("ary", ary);
			let pathitem = path.split("/")[1]; //取path 大項目 ex:'/chat/messaging'=>'chat'
			if (pathitem !== "chat" && pathitem !== "login") {
				//step1.非聊天頁的權限(主頁沒另開視窗)
				let auth = ary.indexOf(path);
				console.log("auth:", auth, "path:", path, "pathitem:", pathitem);
				let transaction =
					"/" + path.split("/")[1] + "/" + path.split("/")[2];
				console.log("tra", transaction);
				if (auth == -1 && transaction !== "/transaction/list") {
					// alert(this.$t('noAuthority'))
					this.$router.push("/index");
					console.log("no chat");
				}
			} else {
				//stap2.chat聊天頁的權限(另開的視窗)
				let chaturl = "/" + pathitem;
				this.$store.dispatch("action_Authority", chaturl);
			}
			// },5000)
			// // setTimeout(()=>{
			//     // let path = this.$route.path
			//     let path = window.location.pathname
			//     let pathitem = path.split('/')[1]
			//     console.log('path:',path,'pathitem:',pathitem)
			//     this.$store.dispatch('action_Authority', '/'+ pathitem)
			// // },1)
		},
		// 給上方藍藍抓使用者開洗分上限的餘額 -1為無上限
		getUser() {
			setTimeout(() => {
				let id = sessionStorage.getItem("user_id");
				this.$store.dispatch("action_UserView", id);
			}, 1500);
		}
	},
	created() {
		//   原本框架套件有的
		window.getApp.$on("APP_DRAWER_TOGGLED", () => {
			this.drawer = !this.drawer;
		});
		this.$store.dispatch("actionMenu");
		// this.authority()
		this.getUser();
	}
};
</script>

<style lang="stylus">
// @import '../../node_modules/vuetify/src/stylus/settings/_elevations.styl';
#appDrawer {
	overflow: hidden;

	.drawer-menu--scroll {
		height: calc(100vh - 48px);
		overflow: auto;
	}
}
</style>
