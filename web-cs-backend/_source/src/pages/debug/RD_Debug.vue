<template>
	<div id="page-forms">
		<v-container grid-list-xl fluid>
			<v-layout row wrap>
				<v-flex lg10>
					<h3>解密小工具</h3>

					<div style="padding:15px;">
						<div>
							<b>請輸入加密字串：</b>
						</div>

						<v-text-field placeholder="請輸入加密字串" v-model="encryptText"></v-text-field>
						<div>
							<b>得到的解密結果為：</b>
						</div>
						<div style="color: #c45b5b;">{{ getDecryptText }}</div>
					</div>

					<div style="margin-top:50px;">
						<h3>加密小工具</h3>
					</div>

					<div style="padding:15px;">
						<div>
							<b>請輸入原始Json字串：</b>
						</div>

						<v-text-field placeholder="請輸入加密字串" v-model="encryptHelper_inputText"></v-text-field>
						<div>
							<b>得到的加密結果為：</b>
						</div>
						<div style="color: #54a3cd;">{{ getEncryptText }}</div>
					</div>
				</v-flex>
				<v-flex lg2>
					<div></div>
				</v-flex>
			</v-layout>
		</v-container>
	</div>
</template>

<script>
import axios from "axios";
import secret from "@/util/secret";
import md5 from "js-md5";
import Global from "@/Global";

export default {
	components: {},
	data() {
		return {
			search: "",
			totalDesserts: 0,
			myRecordList: [],
			loading: false,
			encryptText: "",
			decryptText: "尚未輸入",

			encryptHelper_inputText: "",
			encryptHelper_resultText: "尚未輸入"
		};
	},

	mounted() {
		//debug資訊
		console.log(process.env.BUILD_ENV);
	},
	computed: {
		getDecryptText() {
			let result = secret.Decrypt(this.encryptText);
			if (result == "" || result == null) return "解密失敗";
			return result;
		},
		getEncryptText() {
			let md5_key = Global.$API_MD5_KEY;
			let params = secret.Encrypt(this.encryptHelper_inputText);
			let key = md5(params + md5_key);
			return '{ "params" : "' + params + '" , "key" : "' + key + '" }';
		}
	},
	methods: {
		getCategoriesByPagination() {}
	}
};
</script>