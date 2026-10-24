import Global from '@/Global'
const CryptoJS = require('crypto-js') //引用AES源码js
const KEY = Global.$API_AES_KEY
const IV = Global.$API_IV
const CHAT_KEY = Global.$CHAT_AES_KEY
const CHAT_IV = Global.$CHAT_IV

function Encrypt(data) {
  //加密
  let key_hash = CryptoJS.MD5(KEY).toString()
  let key = CryptoJS.enc.Utf8.parse(key_hash)
  let iv = CryptoJS.enc.Utf8.parse(IV)
  let encrypted = CryptoJS.AES.encrypt(data, key, {
    iv: iv,
    mode: CryptoJS.mode.CBC,
    padding: CryptoJS.pad.Pkcs7,
  })
  return encrypted.toString() //返回的是base64格式的密文
}
function Decrypt(encrypted) {
  //解密
  let key_hash = CryptoJS.MD5(KEY).toString()
  let key = CryptoJS.enc.Utf8.parse(key_hash)
  let iv = CryptoJS.enc.Utf8.parse(IV)
  let decrypted = CryptoJS.AES.decrypt(encrypted, key, {
    iv: iv,
    mode: CryptoJS.mode.CBC,
    padding: CryptoJS.pad.Pkcs7,
  })
  return decrypted.toString(CryptoJS.enc.Utf8)
}
//聊天室用
function EncryptChat(chatdata) {
  //加密
  let key_hash = CryptoJS.MD5(CHAT_KEY).toString()
  let chatkey = CryptoJS.enc.Utf8.parse(key_hash)
  let chativ = CryptoJS.enc.Utf8.parse(CHAT_IV)
  let encryptchated = CryptoJS.AES.encrypt(chatdata, chatkey, {
    iv: chativ,
    // mode: CryptoJS.mode.CBC,
    padding: CryptoJS.pad.Pkcs7,
  })
  return encryptchated.toString() //返回的是base64格式的密文
}
function DecryptChat(encryptchated) {
  //解密
  let key_hash = CryptoJS.MD5(CHAT_KEY).toString()
  let chatkey = CryptoJS.enc.Utf8.parse(key_hash)
  let chativ = CryptoJS.enc.Utf8.parse(CHAT_IV)
  let decryptchated = CryptoJS.AES.decrypt(encryptchated, chatkey, {
    iv: chativ,
    mode: CryptoJS.mode.CBC,
    padding: CryptoJS.pad.Pkcs7,
  })
  return decryptchated.toString(CryptoJS.enc.Utf8)
}
// export function getEAES(data) { //加密
//     var encrypted = Encrypt(data, KEY, IV);
//     // var encrypted1 = CryptoJS.enc.Utf8.parse(encrypted);
//     return encrypted;
// }
// export function getDAES(data) {//解密
//     return Decrypt(data, KEY, IV);
// }

// const key = CryptoJS.enc.Utf8.parse("M53B?xKnTVtVnjM^");  //十六位十六进制数作为密钥
// const iv = CryptoJS.enc.Utf8.parse('3579653693526433');   //十六位十六进制数作为密钥偏移量

// 加密方法
// function Encrypt(word) {
//     let srcs = CryptoJS.enc.Utf8.parse(word);
//     let encrypted = CryptoJS.AES.encrypt(srcs, key, { iv: iv, mode: CryptoJS.mode.CBC, padding: CryptoJS.pad.Pkcs7 });
//     return encrypted.ciphertext.toString().toUpperCase();
// }
// 解密方法
// function Decrypt(word) {
//     let encryptedHexStr = CryptoJS.enc.Hex.parse(word);
//     let srcs = CryptoJS.enc.Base64.stringify(encryptedHexStr);
//     let decrypt = CryptoJS.AES.decrypt(srcs, key, { iv: iv, mode: CryptoJS.mode.CBC, padding: CryptoJS.pad.Pkcs7 });
//     let decryptedStr = decrypt.toString(CryptoJS.enc.Utf8);
//     return decryptedStr.toString();
// }
export default {
  Decrypt,
  Encrypt,
  DecryptChat,
  EncryptChat,
}
