<template>
	<v-app id="login" class="primary">
		<v-content>
			<v-container fluid fill-height>
				<v-layout align-center justify-center>
					<v-flex xs12 sm8 md4 lg4>
						<v-card class="elevation-1 pa-3">
							<v-card-text>
								<div class="layout column align-center">
									<!--<img src="/static/m.png" alt="Vue Material Admin" width="120" height="120">-->
									<h1 class="flex my-4 primary--text">Customer Service</h1>
								</div>
								<v-form>
									<v-text-field
										append-icon="person"
										name="login"
										label="Login"
										type="text"
										v-model="model.username"
									></v-text-field>
									<v-text-field
										append-icon="lock"
										name="password"
										label="Password"
										id="password"
										type="password"
										v-model="model.password"
									></v-text-field>
								</v-form>
							</v-card-text>
							<v-card-actions>
								<!--<v-btn icon>
                  <v-icon color="blue">fa fa-facebook-square fa-lg</v-icon>
                </v-btn>
                <v-btn icon>
                  <v-icon color="red">fa fa-google fa-lg</v-icon>
                </v-btn>
                <v-btn icon>
                  <v-icon color="light-blue">fa fa-twitter fa-lg</v-icon>
								</v-btn>-->
								<v-spacer></v-spacer>
								<v-btn block color="primary" @click="login" :loading="loading" id="loginBtn">Login</v-btn>
							</v-card-actions>
						</v-card>
					</v-flex>
				</v-layout>
			</v-container>
		</v-content>
	</v-app>
</template>

<script>
export default {
	data: () => ({
		loading: false,
		model: {
			username: "",
			password: ""
		}
	}),

	methods: {
		login() {
			this.loading = true;
			let data = {
				username: this.model.username,
				password: [REDACTED]
			};
			this.$store.dispatch("actionLogin", data);
			setTimeout(() => {
				this.loading = false;
			}, 1000);
		}
	},
	created() {
		let lang = localStorage.getItem("LOCALE");

		//如無語系 刷新一次畫面
		if (lang == null || lang == "") {
			let defaultLang = "en";
			localStorage.setItem("LOCALE", defaultLang);
			location.href = location.href + "#";
			location.reload();
		}

		// 重刷一次，防止cors不過
		if (location.href.indexOf("#") == -1) {
			location.href = location.href + "#";
			location.reload();
		} else {
			console.log("login reload");
		}
	}
};
</script>
<style scoped lang="css">
#login {
	height: 50%;
	width: 100%;
	position: absolute;
	top: 0;
	left: 0;
	content: "";
	z-index: 0;
}
</style>
