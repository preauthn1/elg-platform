<script>
const $API_URL = process.env.API_URL;							//! API
// const $API_URL = 'https://example.invalid/'
// const $API_URL =
//   'http://0.0.0.0/everluckgames/WEB_CustomerServiceSocket/_release/public/'

const $API_MD5_KEY = 'k^QtGkDg7FXJ#@QC'
const $API_AES_KEY = 'mu5*rwp5j%3U4kZG' //USE in utils/secret.js
const $API_IV = '9240533016891799' //USE in utils/secret.js
//聊天室SIGN_DATA加密
const $CHAT_AES_KEY = 'geSFWewRF15463U45aw' //USE in utils/secret.js
const $CHAT_IV = '95498413211799' //USE in utils/secret.js

export default {
  $API_MD5_KEY,
  $API_URL,
  $API_AES_KEY,
  $API_IV,
  $CHAT_AES_KEY,
  $CHAT_IV
}
</script>



