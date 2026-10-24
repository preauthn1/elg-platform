<script>
const $API_URL = process.env.API_URL; //請至 config/env.js 設定
const $API_MD5_KEY = "k^QtGkDg7FXJ#@QC";
const $API_AES_KEY = "mu5*rwp5j%3U4kZG"; //USE in utils/secret.js
const $API_IV = "9240533016891799"; //USE in utils/secret.js

const $LOCALE_LANG = localStorage.getItem("LOCALE");

export default {
	$API_MD5_KEY,
	$API_URL,
	$API_AES_KEY,
	$API_IV,
	$LOCALE_LANG
};
</script>
