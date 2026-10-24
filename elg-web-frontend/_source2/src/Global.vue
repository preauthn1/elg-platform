<script>

const $API_URL = process.env.API_URL;							//! API
const $CHATAPI_URL = process.env.CHATAPI_URL;					//! SOCKET
const $API_CHATPATH = process.env.API_CHATPATH;					//! 聊天室
const $CUSTOMER_SERVICE = process.env.CUSTOMER_SERVICE;			//! 連接客服

//const $API_CHATPATH = 'http://localhost:8081/' //本機聊天室

const $API_MD5_KEY = "&zSMvVTK4gbDvCcU";
const $API_AES_KEY = "M53B?xKnTVtVnjM^"; //USE in utils/secret.js
const $API_IV = "3579653693526433"; //USE in utils/secret.js
//login lobby聊天室用
const $CHATAPI_MD5_KEY = "k^QtGkDg7FXJ#@QC";
const $CHATAPI_AES_KEY = "mu5*rwp5j%3U4kZG"; //USE in utils/secret.js
const $CHATAPI_IV = "9240533016891799"; //USE in utils/secret.js

// 聊天室SIGN_DATA加密
const $CHAT_MD5_KEY = "k^QtGkDg7FXJ#@QC";
const $CHAT_AES_KEY = "geSFWewRF15463U45aw"; //USE in utils/secret.js
const $CHAT_IV = "95498413211799"; //USE in utils/secret.js

export default {
	$API_MD5_KEY,
	$API_URL,
	$API_AES_KEY,
	$API_IV,
	$API_CHATPATH,
	$CUSTOMER_SERVICE,
	$CHAT_MD5_KEY,
	$CHAT_AES_KEY,
	$CHAT_IV,
	$CHATAPI_URL,
	$CHATAPI_MD5_KEY,
	$CHATAPI_AES_KEY,
	$CHATAPI_IV
};
</script>
