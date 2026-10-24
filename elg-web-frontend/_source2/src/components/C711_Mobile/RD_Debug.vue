
<style>
</style>

<template>
	<div id="C711Mobile">
		<div id="agentPage" class="pageBg CwidthHeight widthHeightvh fontFamily">
			<LoginHeader></LoginHeader>

			<div style="padding:25px;">
				<div>
					<b>請填寫加密後的字串：</b>
				</div>

				<div>
					<textarea
						type
						v-model="encryptText"
						placeholder="請填寫加密的字串(若非正確格式會解析出空白)"
						style="width:300px;height:100px;"
					></textarea>
				</div>

				<hr />

				<div>
					<b>解密後：</b>
					<div>{{decryptText}}</div>
				</div>
			</div>
		</div>
	</div>
</template>
<script>
import { mapGetters, mapMutations } from "vuex";
import maskModal from "./Modal";
import value_utils from "@/utils/value_utils";
import secret from "@/utils/secret";

import LoginHeader from "@/components/C711_Mobile/Login_header";

export default {
	components: {
		LoginHeader,
		maskModal
	},

	computed: {
		...mapGetters(["getAgentHomePageSummary"]),

		decryptText() {
			let myText = this.encryptText;

			if (myText == null || myText.length <= 1) return "";

			var result = secret.Decrypt(this.encryptText);
			return result;
		}
	},

	data: function() {
		return {
			encryptText: "",
			isToEnd: false
		};
	},
	mounted() {},

	created() {},
	methods: {}
};
</script>
