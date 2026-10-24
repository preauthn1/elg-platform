params加密方式

1.將所有要傳送的參數串成JSON格式，ex:{"username":"test","password":"123456"}　　

2.使用KEY及VI做AES加密，ex:EQs6YJl8pkjOkxioovtJ+6Z4nozFbhNzfmUh7TRqzhJkVGW39oImW+aSG828/aHg

key加密方式

1.將params串上MD5_KEY,ex:EQs6YJl8pkjOkxioovtJ+6Z4nozFbhNzfmUh7TRqzhJkVGW39oImW+aSG828/aHg&zSMvVTK4gbDvCcU

2.使用md5加密，ex:md5(EQs6YJl8pkjOkxioovtJ+6Z4nozFbhNzfmUh7TRqzhJkVGW39oImW+aSG828/aHg&zSMvVTK4gbDvCcU)

　　

使用API

POST http://domain/api/login

　　

Example :

　　

Request

http://xxxx/api/login

　　

Header

Authorization: Bearer {token} [login不需要]

Accept-Language:en

　　

Body

params=EQs6YJl8pkjOkxioovtJ+6Z4nozFbhNzfmUh7TRqzhJkVGW39oImW+aSG828/aHg 

key=f9926329f1fc9b22ad12991fc359e091

　　

Response

{
    "code": 1000,
    "message": "QEYQj1CE5k0rDfRfKnzZOnJort08cESM"
}
　　

聊天室不需登入，所以沒有TOKEN，直接用簽名驗證使用者是否正確

sign使用md5加密：API_USER+API_MD5_KEY+API_KEY+API_IV+時間戳記+API_AES_KEY+第三方的玩家編號

Header

Authorization: Signature {sign}


 * @bodyParam API_USER string required 串接API的帳號 Example: nExJ2bb0R0
 
 * @bodyParam API_KEY string required 串接API的KEY Example:  Eb8zCL7eTAszY7d
 
 * @bodyParam TIMESTAMP integer required 時間戳記 Example: 1566479627
 
 * @bodyParam THIRD_PARTY_ID string required 第三方的玩家編號(不可重覆) Example:1
 
 ------以下資訊為第一次進入聊天室時建立會員資訊使用，未帶入可使用member/edit修改------
 
 * @bodyParam USERNAME string optional 好友ID Example:ABC
 
 * @bodyParam NICKNAME string optional 暱稱 Example:ABC
 
 * @bodyParam THUMBNAIL url optional 大頭照 Example:http://xxx.xxx.xx/xx.jpg
 
 