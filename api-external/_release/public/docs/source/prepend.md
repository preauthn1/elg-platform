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
　　
