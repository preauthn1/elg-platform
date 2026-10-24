/**
 * 傳送數據加密，生成密鑰和參數，需要先設置 AES_KES,AES_IV,MD5_KEY 變量
 * 然後使用 generateKeyParams 生成密鑰和參數
 */

var CryptoJS = require("crypto-js");
/**
 * 加密
 * @param {string} text 
 * @returns {string}
 */
function encrypt(text) {
    var aes_key = bru.getCollectionVar("AES_KEY");
    var aes_iv = bru.getCollectionVar("AES_IV");
    //加密
    let key_hash = CryptoJS.MD5(aes_key).toString();
    let key = CryptoJS.enc.Utf8.parse(key_hash);
    let iv = CryptoJS.enc.Utf8.parse(aes_iv);
    let encrypted = CryptoJS.AES.encrypt(text, key, {
        iv: iv,
        mode: CryptoJS.mode.CBC,
        padding: CryptoJS.pad.Pkcs7
    });
    return encrypted.toString(); //返回的是base64格式的密文
}

/**
 * 生成密钥和参数
 * @param {object} params 
 * @returns {object}
 */ 
function generateKeyParams(params) {
    var CryptoJS = require("crypto-js");
    var md5_key = bru.getCollectionVar("MD5_KEY");
    var params = encrypt(JSON.stringify(params));
    var key = CryptoJS.MD5(params + md5_key).toString();
    return {
        key: key,
        params: params
    }
}

module.exports = {
   generateKeyParams
};