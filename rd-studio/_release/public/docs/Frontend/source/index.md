---
title: API Reference

language_tabs:
- bash
- javascript

includes:

search: true

toc_footers:
- <a href='http://example.invalid/mpociot/documentarian'>Documentation Powered by Documentarian</a>
---
<!-- START_INFO -->
# Info

<!-- END_INFO -->
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
　　


#general
<!-- START_dc49219aba26987a6f91e75ae9767057 -->
## api/callback
> Example request:

```bash
curl -X POST "http://localhost/api/callback" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/callback");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Accept": "application/json",
    "Content-Type": "application/json",
}

fetch(url, {
    method: "POST",
    headers: headers,
})
    .then(response => response.json())
    .then(json => console.log(json));
```


### HTTP Request
`POST api/callback`


<!-- END_dc49219aba26987a6f91e75ae9767057 -->

<!-- START_c3fa189a6c95ca36ad6ac4791a873d23 -->
## login
-會員登入(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/login" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"username":"username","password":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/login");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "username": "username",
    "password": "abc123456"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": {
        "id": 1,
        "username": "test",
        "email": "user@example.invalid",
        "nickname": "Test",
        "balance": "100.00",
        "image": "頭像，ex:http:\/\/xxxx.xxxx.xxx",
        "first_name": "Test",
        "last_name": "Lin",
        "mobile": "09123456789",
        "last_login": "2019-01-01 00:00:00",
        "token": "roOAsDmfT9nP1Y9uKD8aMylk6HcYPlfz"
    }
}
```
> Example response (200):

```json
{
    "code": 9007,
    "message": "手機未認證",
    "data": "會員編號,ex:1"
}
```

### HTTP Request
`POST api/login`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | string |  required  | 會員帳號
    password | string |  required  | 會員密碼

<!-- END_c3fa189a6c95ca36ad6ac4791a873d23 -->

<!-- START_01298058fadc1532cadf543e62b33892 -->
## create
-會員註冊(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/create" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"code":"ABC123","username":"username","password":"JSBPNn9AP7Jap74O","password_confirmation":"nYeaaXuc6iEMmpv6","email":"user@example.invalid","nickname":"user","image":"base64 file","first_name":"CHIA HUA","last_name":"CHAN","birthday":"1990-01-01","mobile":"0912345678","timezone_id":1,"country_id":1,"zip_code":"100","district":"\u4e2d\u6b63\u5340","city":"\u53f0\u5317\u5e02","address":"\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13"}'

```

```javascript
const url = new URL("http://localhost/api/create");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "code": "ABC123",
    "username": "username",
    "password": "JSBPNn9AP7Jap74O",
    "password_confirmation": "nYeaaXuc6iEMmpv6",
    "email": "user@example.invalid",
    "nickname": "user",
    "image": "base64 file",
    "first_name": "CHIA HUA",
    "last_name": "CHAN",
    "birthday": "1990-01-01",
    "mobile": "0912345678",
    "timezone_id": 1,
    "country_id": 1,
    "zip_code": "100",
    "district": "\u4e2d\u6b63\u5340",
    "city": "\u53f0\u5317\u5e02",
    "address": "\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": "會員系統編號,ex:1"
}
```

### HTTP Request
`POST api/create`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    code | string |  optional  | 代理的推薦碼
    username | string |  required  | 會員帳號(不可重覆)
    password | string |  required  | 會員密碼
    password_confirmation | string |  required  | 確認會員密碼
    email | email |  optional  | 會員電子信箱(不可重覆)
    nickname | string |  optional  | 暱稱(不可重覆)
    image | string |  optional  | 會員頭像
    first_name | string |  optional  | 英文名
    last_name | string |  optional  | 英文姓
    birthday | date |  optional  | 出生年月日
    mobile | string |  optional  | 聯絡電話(不可重覆)
    timezone_id | integer |  optional  | 時區系統編號
    country_id | integer |  optional  | 國家
    zip_code | string |  optional  | 郵遞區號
    district | string |  optional  | 區域
    city | string |  optional  | 城市
    address | string |  optional  | 地址

<!-- END_01298058fadc1532cadf543e62b33892 -->

<!-- START_b4d74abc669250f8312e4f371310aa46 -->
## sendValidateCode
-傳送驗證碼，需傳入驗證過的電子信箱或手機號碼(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/sendValidateCode" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"type":1,"member_id":1,"username":"test1234","verify_by":1,"mobile":2147483647,"email":"user@example.invalid"}'

```

```javascript
const url = new URL("http://localhost/api/sendValidateCode");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "type": 1,
    "member_id": 1,
    "username": "test1234",
    "verify_by": 1,
    "mobile": 2147483647,
    "email": "user@example.invalid"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success"
}
```

### HTTP Request
`POST api/sendValidateCode`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    type | integer |  required  | 類型(1:資料驗證2:忘記密碼3:找回密碼)
    member_id | integer |  optional  | 會員編號(資料驗證時必需傳入)
    username | string |  optional  | 會員帳號(由營運商設定是否必填)
    verify_by | integer |  optional  | 驗證方式(1:手機2Email)
    mobile | integer |  optional  | 會員電話
    email | email |  optional  | 會員電子信箱

<!-- END_b4d74abc669250f8312e4f371310aa46 -->

<!-- START_cbd91a59cb95004800f8fad905ac6ee8 -->
## checkValidateCode
-確認驗證碼是否正確(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/checkValidateCode" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"token":"184438"}'

```

```javascript
const url = new URL("http://localhost/api/checkValidateCode");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "token": "184438"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": "會員帳號"
}
```

### HTTP Request
`POST api/checkValidateCode`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    token | string |  required  | 驗證碼

<!-- END_cbd91a59cb95004800f8fad905ac6ee8 -->

<!-- START_b4f4625b609a18310a50b1dddf752a55 -->
## resetPassword
-利用驗證碼重設密碼(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/resetPassword" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"token":"NgF7huNcta13V5WyJwyGL34xQlHT3Acp","new_password":"abc123456","new_password_confirmation":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/resetPassword");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "token": "NgF7huNcta13V5WyJwyGL34xQlHT3Acp",
    "new_password": "abc123456",
    "new_password_confirmation": "abc123456"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success"
}
```

### HTTP Request
`POST api/resetPassword`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    token | string |  required  | 驗證碼
    new_password | string |  required  | 新密碼
    new_password_confirmation | string |  required  | 確認新密碼

<!-- END_b4f4625b609a18310a50b1dddf752a55 -->

<!-- START_254349f0265fada41f09fed8771a5553 -->
## countries
-國家列表(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/countries" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/countries");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Accept": "application/json",
    "Content-Type": "application/json",
}

fetch(url, {
    method: "POST",
    headers: headers,
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "code": "代碼,ex:TW",
            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:台灣",
            "country_code": "國碼,ex:886",
            "phone_rule": "手機驗證正規式,ex:xxx"
        },
        {
            "id": 2,
            "code": "HK",
            "name": "香港",
            "country_code": "60",
            "phone_rule": "xxx"
        }
    ]
}
```

### HTTP Request
`POST api/countries`


<!-- END_254349f0265fada41f09fed8771a5553 -->

<!-- START_3a3de8519134b961245b00843c83314c -->
## currencies
-幣種列表(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/currencies" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/currencies");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Accept": "application/json",
    "Content-Type": "application/json",
}

fetch(url, {
    method: "POST",
    headers: headers,
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "code": "代碼,ex:TWD",
            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:台幣",
            "rate": "匯率,ex:1.00000"
        },
        {
            "id": 2,
            "code": "THB",
            "name": "泰銖",
            "rate": "0.90000"
        }
    ]
}
```

### HTTP Request
`POST api/currencies`


<!-- END_3a3de8519134b961245b00843c83314c -->

<!-- START_251ae2f999ed24b033dc7019479bc321 -->
## languages
-語系列表(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/languages" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/languages");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Accept": "application/json",
    "Content-Type": "application/json",
}

fetch(url, {
    method: "POST",
    headers: headers,
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "code": "代碼,ex:en",
            "name": "名稱,ex:English"
        },
        {
            "id": 2,
            "code": "zh-tw",
            "name": "繁體中文"
        }
    ]
}
```

### HTTP Request
`POST api/languages`


<!-- END_251ae2f999ed24b033dc7019479bc321 -->

<!-- START_3d9bd80e71e863166b0a4dd6c9f3c41a -->
## timezones
-時區列表(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/timezones" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/timezones");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Accept": "application/json",
    "Content-Type": "application/json",
}

fetch(url, {
    method: "POST",
    headers: headers,
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "time_offset": "時間偏移,ex:UTC-12:00"
        },
        {
            "id": 2,
            "time_offset": "UTC-11:00"
        }
    ]
}
```

### HTTP Request
`POST api/timezones`


<!-- END_3d9bd80e71e863166b0a4dd6c9f3c41a -->

<!-- START_609694dd4b6b1697cc5e4989f109f447 -->
## advertisements
-廣告列表(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/advertisements" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"path_id":1}'

```

```javascript
const url = new URL("http://localhost/api/advertisements");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "path_id": 1
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": [
        {
            "image": "圖片位置,ex:http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "url": "連結網址,ex:http:\/\/xxx.xxx.xxx",
            "game_id": "遊戲編號,ex:1",
            "game_type_id": "遊戲類型編號,ex:0"
        },
        {
            "image": "http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "url": "",
            "game_id": 0,
            "game_type_id": 1
        }
    ]
}
```

### HTTP Request
`POST api/advertisements`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    path_id | integer |  required  | 類型編號

<!-- END_609694dd4b6b1697cc5e4989f109f447 -->

<!-- START_d417a7acdf6fc8e45de1a335fbf7a69a -->
## template
-取得模版編號(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/template" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"domain":"example.invalid"}'

```

```javascript
const url = new URL("http://localhost/api/template");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "domain": "example.invalid"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": {
        "id": "模版編號,ex:1",
        "name": "模版名稱,ex:v1",
        "logo_pc": "電腦版LOGO,ex:http:\/\/xxxx.xxx.xxx",
        "logo_mobile": "手機版LOGO,ex:http:\/\/xxxx.xxx.xxx",
        "title": "網站名稱,ex:XSG CASINO"
    }
}
```

### HTTP Request
`POST api/template`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    domain | string |  required  | 域名

<!-- END_d417a7acdf6fc8e45de1a335fbf7a69a -->

<!-- START_0f45c869ba3cce3537ed078a377bb95a -->
## analytics
-取得營運商的資料分析代碼(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/analytics" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1}'

```

```javascript
const url = new URL("http://localhost/api/analytics");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "agent_id": 1
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": [
        {
            "type": "代碼,ex:1",
            "code": "名稱,ex:45121196152222635"
        },
        {
            "type": 2,
            "code": "86458966411"
        }
    ]
}
```

### HTTP Request
`POST api/analytics`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  required  | 代理系統編號

<!-- END_0f45c869ba3cce3537ed078a377bb95a -->

<!-- START_af786c7d3099aa626451097a59188cce -->
## gameTypes
遊戲類型列表(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/gameTypes" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":1}'

```

```javascript
const url = new URL("http://localhost/api/gameTypes");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": 1
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Reality",
            "number": "此分類的遊戲數量,ex:64",
            "submenu": [
                {
                    "id": 3,
                    "name": "Baccarat"
                },
                {
                    "id": 4,
                    "name": "Roulette"
                }
            ]
        },
        {
            "id": 2,
            "name": "Slot",
            "number": 30
        }
    ],
    "total": "總遊戲數量,ex:94"
}
```

### HTTP Request
`POST api/gameTypes`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | integer |  optional  | 遊戲商編號

<!-- END_af786c7d3099aa626451097a59188cce -->

<!-- START_f6c9c363b31cfe789c46f47bcbd3dea8 -->
## games
- 遊戲列表

> Example request:

```bash
curl -X POST "http://localhost/api/games" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":1,"type_id":1,"key":"diamond","page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/games");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": 1,
    "type_id": 1,
    "key": "diamond",
    "page": 1,
    "per_page": 30
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "vendor_id": "遊戲商編號,ex:1",
            "vendor_name": "遊戲商名稱,ex:AG",
            "type_id": "遊戲類型系統編號,ex:123456",
            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
            "image": "遊戲圖片,ex:http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "is_new": "是否為新遊戲(0:否,1:是),1",
            "is_hot": "是否為熱門遊戲(0:否,1:是),0",
            "is_favourite": "是否為我的最愛(0:否,1:是),0"
        },
        {
            "id": 2,
            "vendor_id": 1,
            "vendor_name": "Joker",
            "type_id": "1",
            "name": "Alibaba",
            "lottery_pool": "123456",
            "image": "http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "is_new": 0,
            "is_hot": 1,
            "is_favourite": 1
        }
    ],
    "pagination": {
        "per_page": "單頁筆數,ex:20",
        "total": "總筆數,ex:10",
        "pages": "總頁數,ex:1",
        "previous": "上一頁,ex:",
        "next": "下一頁,ex:1",
        "current": "目前頁面,ex:"
    }
}
```

### HTTP Request
`POST api/games`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | integer |  optional  | 遊戲商編號
    type_id | integer |  optional  | 類型編號
    key | string |  optional  | 遊戲名稱或類型
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_f6c9c363b31cfe789c46f47bcbd3dea8 -->

<!-- START_274dc8296b443f9da7a31dff5252f1aa -->
## lottery
- 遊戲彩金列表

> Example request:

```bash
curl -X POST "http://localhost/api/lottery" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"number":10}'

```

```javascript
const url = new URL("http://localhost/api/lottery");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "number": 10
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": [
        {
            "id": "遊戲系統編號,ex:1",
            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
            "image": "遊戲圖片,ex:http:\/\/xxx.xxx.xxx\/xxx.jpg"
        },
        {
            "id": 2,
            "name": "Alibaba",
            "lottery_pool": "123456",
            "image": "http:\/\/xxx.xxx.xxx\/xxx.jpg"
        }
    ]
}
```

### HTTP Request
`POST api/lottery`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    number | integer |  optional  | 筆數(預設為15筆)

<!-- END_274dc8296b443f9da7a31dff5252f1aa -->

<!-- START_250b8751b99a645521d0e0bd8afebdab -->
## member
-會員明細

> Example request:

```bash
curl -X POST "http://localhost/api/member" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/member");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Accept": "application/json",
    "Content-Type": "application/json",
}

fetch(url, {
    method: "POST",
    headers: headers,
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": {
        "id": "會員系統編號,ex:1",
        "parent": "代理系統編號,ex:0",
        "username": "會員帳號,ex:hello.world",
        "balance": "會員餘額,ex:100",
        "image": "會員頭像,ex:http:\/\/xxxxx.xxxx",
        "email": "會員信箱,ex:user@example.invalid",
        "nickname": "暱稱,ex:user",
        "first_name": "會員名字,ex:hello",
        "last_name": "會員姓氏,ex:world",
        "birthday": "出生年月日,ex:1999-01-01",
        "mobile": "電話號碼,ex:09123456789",
        "timezone_id": "時區系統編號,ex:1",
        "country_id": "國家系統編號,ex:1",
        "zip_code": "郵遞區號,ex:23045",
        "district": "居住地區,ex:中正區",
        "city": "居住城市,ex:台北市",
        "address": "居住地址,ex:忠孝西路一段",
        "status": "狀態,ex:1",
        "is_validate": "是否通過身份認證,ex:1",
        "mobile_validate": "是否通過手機認證,ex:1",
        "email_validate": "是否通過email認證,ex:1",
        "last_login": "最後登入時間,ex:2019-03-26 03:44:34",
        "setting": {
            "activity_limit_time": 30,
            "push_notify": 1,
            "sms_notify": 1,
            "email_notify": 1,
            "message_box_notify": 1,
            "message_popup_notify": 1
        }
    }
}
```

### HTTP Request
`POST api/member`


<!-- END_250b8751b99a645521d0e0bd8afebdab -->

<!-- START_54efcdcc958cf3327d47fa584fd55a58 -->
## update
-修改會員資料

> Example request:

```bash
curl -X POST "http://localhost/api/update" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"email":"user@example.invalid","nickname":"user","image":"base64 file","first_name":"CHIA HUA","last_name":"CHAN","birthday":"1990-01-01","mobile":"0912345678","timezone_id":1,"country_id":1,"zip_code":"100","district":"\u4e2d\u6b63\u5340","city":"\u53f0\u5317\u5e02","address":"\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13"}'

```

```javascript
const url = new URL("http://localhost/api/update");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "email": "user@example.invalid",
    "nickname": "user",
    "image": "base64 file",
    "first_name": "CHIA HUA",
    "last_name": "CHAN",
    "birthday": "1990-01-01",
    "mobile": "0912345678",
    "timezone_id": 1,
    "country_id": 1,
    "zip_code": "100",
    "district": "\u4e2d\u6b63\u5340",
    "city": "\u53f0\u5317\u5e02",
    "address": "\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success"
}
```

### HTTP Request
`POST api/update`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    email | email |  required  | 會員電子信箱(不可重覆)
    nickname | string |  required  | 暱稱(不可重覆)
    image | string |  optional  | 會員頭像
    first_name | string |  required  | 英文名
    last_name | string |  required  | 英文姓
    birthday | date |  optional  | 出生年月日
    mobile | string |  optional  | 聯絡電話(不可重覆)
    timezone_id | integer |  optional  | 時區系統編號
    country_id | integer |  optional  | 國家
    zip_code | string |  optional  | 郵遞區號
    district | string |  optional  | 區域
    city | string |  optional  | 城市
    address | string |  optional  | 地址

<!-- END_54efcdcc958cf3327d47fa584fd55a58 -->

<!-- START_dd73fe89d9872ce37d284636141ae526 -->
## changePassword
-變更會員密碼

> Example request:

```bash
curl -X POST "http://localhost/api/changePassword" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"password":"aaa123456","new_password":"abc123456","new_password_confirmation":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/changePassword");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "password": "aaa123456",
    "new_password": "abc123456",
    "new_password_confirmation": "abc123456"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success"
}
```

### HTTP Request
`POST api/changePassword`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    password | string |  required  | 舊密碼
    new_password | string |  required  | 新密碼
    new_password_confirmation | string |  required  | 確認新密碼

<!-- END_dd73fe89d9872ce37d284636141ae526 -->

<!-- START_e99404c79821854886c3e91c8991dad5 -->
## createSetting
-新增會員設定

> Example request:

```bash
curl -X POST "http://localhost/api/createSetting" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"activity_limit_time":0,"push_notify":true,"sms_notify":true,"email_notify":true,"message_box_notify":true,"message_popup_notify":true}'

```

```javascript
const url = new URL("http://localhost/api/createSetting");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "activity_limit_time": 0,
    "push_notify": true,
    "sms_notify": true,
    "email_notify": true,
    "message_box_notify": true,
    "message_popup_notify": true
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success"
}
```

### HTTP Request
`POST api/createSetting`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    activity_limit_time | integer |  required  | 最長非活動狀態時間
    push_notify | boolean |  required  | 推送通知
    sms_notify | boolean |  required  | 短信提示
    email_notify | boolean |  required  | 電子郵件通知
    message_box_notify | boolean |  required  | 信息收件箱
    message_popup_notify | boolean |  required  | 彈出信息

<!-- END_e99404c79821854886c3e91c8991dad5 -->

<!-- START_1ce80c9f0ebf80ac578b7f578909e3d9 -->
## updateSetting
-修改會員設定

> Example request:

```bash
curl -X POST "http://localhost/api/updateSetting" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"activity_limit_time":0,"push_notify":true,"sms_notify":true,"email_notify":true,"message_box_notify":true,"message_popup_notify":true}'

```

```javascript
const url = new URL("http://localhost/api/updateSetting");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "activity_limit_time": 0,
    "push_notify": true,
    "sms_notify": true,
    "email_notify": true,
    "message_box_notify": true,
    "message_popup_notify": true
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success"
}
```

### HTTP Request
`POST api/updateSetting`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    activity_limit_time | integer |  optional  | 最長非活動狀態時間
    push_notify | boolean |  optional  | 推送通知
    sms_notify | boolean |  optional  | 短信提示
    email_notify | boolean |  optional  | 電子郵件通知
    message_box_notify | boolean |  optional  | 信息收件箱
    message_popup_notify | boolean |  optional  | 彈出信息

<!-- END_1ce80c9f0ebf80ac578b7f578909e3d9 -->

<!-- START_23f27bd3e78196f5b29b5993c8c5032b -->
## validations
-會員驗證列表

> Example request:

```bash
curl -X POST "http://localhost/api/validations" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"status":1}'

```

```javascript
const url = new URL("http://localhost/api/validations");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "status": 1
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "type_id": "認證類型系統編號,ex:1",
            "name": "認證類型名稱,ex:身份證",
            "image": "圖片位置,ex:http:\/\/xxx.xxx.xx\/xxxxx.jpg",
            "remark": "備註,ex:圖片不清楚",
            "status": 2
        },
        {
            "id": 2,
            "type_id": 2,
            "name": "護照",
            "image": "http:\/\/xxx.xxx.xx\/xxxxx.jpg",
            "remark": "",
            "status": 0
        }
    ]
}
```

### HTTP Request
`POST api/validations`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    status | integer |  optional  | 狀態0:未通過,1:通過,2:拒絕

<!-- END_23f27bd3e78196f5b29b5993c8c5032b -->

<!-- START_ab39595da36afef26ac5b3507ca4b5dd -->
## validation/create
-新增會員驗證

> Example request:

```bash
curl -X POST "http://localhost/api/validation/create" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"type_id":1,"image":"Gjsc9heRz0RtWsRN"}'

```

```javascript
const url = new URL("http://localhost/api/validation/create");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "type_id": 1,
    "image": "Gjsc9heRz0RtWsRN"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success"
}
```

### HTTP Request
`POST api/validation/create`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    type_id | integer |  required  | 會員驗證類型系統編號
    image | string |  optional  | base64 file 驗證用圖片

<!-- END_ab39595da36afef26ac5b3507ca4b5dd -->

<!-- START_26d1fa6818f62946d2b04af3f9ccf79d -->
## validation/update
-修改會員驗證

> Example request:

```bash
curl -X POST "http://localhost/api/validation/update" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1","image":"3ltzXdxi5kwvByhV"}'

```

```javascript
const url = new URL("http://localhost/api/validation/update");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": "1",
    "image": "3ltzXdxi5kwvByhV"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success"
}
```

### HTTP Request
`POST api/validation/update`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | string |  required  | 會員驗證檔案系統編號
    image | string |  optional  | base64 file 驗證用圖片

<!-- END_26d1fa6818f62946d2b04af3f9ccf79d -->

<!-- START_90ef323ebf2694784ff38b5b0da151d6 -->
## bulletinTypes
公告類型列表

> Example request:

```bash
curl -X POST "http://localhost/api/bulletinTypes" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/bulletinTypes");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Accept": "application/json",
    "Content-Type": "application/json",
}

fetch(url, {
    method: "POST",
    headers: headers,
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:最新消息"
        },
        {
            "id": 2,
            "name": "優惠消息"
        }
    ]
}
```

### HTTP Request
`POST api/bulletinTypes`


<!-- END_90ef323ebf2694784ff38b5b0da151d6 -->

<!-- START_9077b28ce738f9f09d46d3899a938f97 -->
## bulletins
公告列表

> Example request:

```bash
curl -X POST "http://localhost/api/bulletins" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"type_id":1,"country_id":1,"agent_id":1,"title":"Hello","page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/bulletins");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "type_id": 1,
    "country_id": 1,
    "agent_id": 1,
    "title": "Hello",
    "page": 1,
    "per_page": 30
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "type_id": "類型編號,ex:1",
            "title": "標題,ex:Hello World",
            "content": "內容,ex:Test"
        },
        {
            "id": "系統編號,ex:2",
            "type_id": "類型編號,ex:1",
            "title": "標題,ex:Hello World",
            "content": "內容,ex:Test"
        }
    ],
    "pagination": {
        "per_page": "單頁筆數,ex:20",
        "total": "總筆數,ex:10",
        "pages": "總頁數,ex:1",
        "previous": "上一頁,ex:",
        "next": "下一頁,ex:1",
        "current": "目前頁面,ex:"
    }
}
```

### HTTP Request
`POST api/bulletins`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    type_id | integer |  optional  | 類型編號
    country_id | integer |  optional  | 國家編號
    agent_id | integer |  optional  | 代理編號
    title | string |  optional  | 公告標題
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_9077b28ce738f9f09d46d3899a938f97 -->

<!-- START_ef5b85d6249eaae1f6670fcdcb9d2c9b -->
## bulletin
公告明細

> Example request:

```bash
curl -X POST "http://localhost/api/bulletin" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/bulletin");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": {
        "id": "系統編號,ex:1",
        "type_id": "類型編號,ex:1",
        "title": "標題,ex:Hello World",
        "content": "內容,ex:Test"
    }
}
```

### HTTP Request
`POST api/bulletin`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_ef5b85d6249eaae1f6670fcdcb9d2c9b -->

<!-- START_f9297f7f4b9892404226894faaf096bd -->
## myGames
- 遊戲列表

> Example request:

```bash
curl -X POST "http://localhost/api/myGames" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":1,"type_id":1,"key":"diamond","personal":1,"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/myGames");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": 1,
    "type_id": 1,
    "key": "diamond",
    "personal": 1,
    "page": 1,
    "per_page": 30
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "vendor_id": "遊戲商編號,ex:1",
            "vendor_name": "遊戲商名稱,ex:AG",
            "type_id": "遊戲類型系統編號,ex:123456",
            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
            "image": "遊戲圖片,ex:http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "is_new": "是否為新遊戲(0:否,1:是),1",
            "is_hot": "是否為熱門遊戲(0:否,1:是),0",
            "is_favourite": "是否為我的最愛(0:否,1:是),0"
        },
        {
            "id": 2,
            "vendor_id": 1,
            "vendor_name": "Joker",
            "type_id": "1",
            "name": "Alibaba",
            "lottery_pool": "123456",
            "image": "http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "is_new": 0,
            "is_hot": 1,
            "is_favourite": 1
        }
    ],
    "pagination": {
        "per_page": "單頁筆數,ex:20",
        "total": "總筆數,ex:10",
        "pages": "總頁數,ex:1",
        "previous": "上一頁,ex:",
        "next": "下一頁,ex:1",
        "current": "目前頁面,ex:"
    }
}
```

### HTTP Request
`POST api/myGames`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | integer |  optional  | 遊戲商編號
    type_id | integer |  optional  | 類型編號
    key | string |  optional  | 遊戲名稱或類型
    personal | integer |  optional  | 個人化(1:我的最愛2:最常遊玩3:贏最多)
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_f9297f7f4b9892404226894faaf096bd -->

<!-- START_e603291030175f2543520c0aeb8a059f -->
## favouriteGame
- 加入/取消我的最愛遊戲

> Example request:

```bash
curl -X POST "http://localhost/api/favouriteGame" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"game_id":1,"status":true}'

```

```javascript
const url = new URL("http://localhost/api/favouriteGame");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "game_id": 1,
    "status": true
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success"
}
```

### HTTP Request
`POST api/favouriteGame`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    game_id | integer |  required  | 遊戲系統編號
    status | boolean |  required  | 狀態(0:取消,1:加入)

<!-- END_e603291030175f2543520c0aeb8a059f -->

<!-- START_1bde2ae91edc93f7bbabeaac8cf40f10 -->
## vendors
-遊戲商列表

> Example request:

```bash
curl -X POST "http://localhost/api/vendors" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"status":"GHIaCxBqksIO9Kqw"}'

```

```javascript
const url = new URL("http://localhost/api/vendors");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "status": "GHIaCxBqksIO9Kqw"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "name": "名稱,ex:AG",
            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
            "image": "圖片地址,ex:http:\/\/xxxxx.xx.xx\/xx.jpg",
            "need_login": "是否需要另外登入0:不需1:需要,ex:0",
            "status": "狀態1:開啟2:coming soon,ex:1"
        },
        {
            "id": 2,
            "name": "MG",
            "rate": "1.5",
            "image": "圖片地址,ex:http:\/\/xxxxx.xx.xx\/xx.jpg",
            "need_login": 1,
            "status": 2
        }
    ]
}
```

### HTTP Request
`POST api/vendors`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    status | interger |  optional  | 狀態1:開啟2:coming soon

<!-- END_1bde2ae91edc93f7bbabeaac8cf40f10 -->

<!-- START_597b0e22c0b4e3533c5b066211d356bb -->
## vendorInfo
-取遊戲商登入資訊(需要另外登入的遊戲商才適用)

> Example request:

```bash
curl -X POST "http://localhost/api/vendorInfo" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":"8r3CZ6J5QKte5tir"}'

```

```javascript
const url = new URL("http://localhost/api/vendorInfo");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": "8r3CZ6J5QKte5tir"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": {
        "username": "遊戲商帳號,ex:xxx",
        "password": "遊戲商密碼,ex:123456",
        "ios_download": "iOS下載連結,ex:https:\/\/xxx.x.xxx.x",
        "ios_open": "iOS打開APP,ex:lobby:\/\/xxxxx",
        "android_download": "Android下載連結,ex:https:\/\/xxx.x.xxx.x",
        "android_open": "Android打開APP,ex:lobby:\/\/xxxxx"
    }
}
```

### HTTP Request
`POST api/vendorInfo`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | interger |  required  | 遊戲商系統編號

<!-- END_597b0e22c0b4e3533c5b066211d356bb -->

<!-- START_9a616548a95db6007078c9c1ca62c348 -->
## getVendorBalance
-遊戲商平台餘額

> Example request:

```bash
curl -X POST "http://localhost/api/getVendorBalance" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":"zIyCoG3ZMjM7Tc12"}'

```

```javascript
const url = new URL("http://localhost/api/getVendorBalance");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": "zIyCoG3ZMjM7Tc12"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": "0.00"
}
```

### HTTP Request
`POST api/getVendorBalance`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | interger |  required  | 遊戲商系統編號

<!-- END_9a616548a95db6007078c9c1ca62c348 -->

<!-- START_36145607fee603518d590bd3ffbc10b2 -->
## resetVendorPassword
-取遊戲商登入資訊(需要另外登入的遊戲商才適用)

> Example request:

```bash
curl -X POST "http://localhost/api/resetVendorPassword" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":"PcRqO67dmt4wHzCr","new_password":"f1sbY1ERLTvjR4qs"}'

```

```javascript
const url = new URL("http://localhost/api/resetVendorPassword");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": "PcRqO67dmt4wHzCr",
    "new_password": "f1sbY1ERLTvjR4qs"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS"
}
```

### HTTP Request
`POST api/resetVendorPassword`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | interger |  required  | 遊戲商系統編號
    new_password | string |  required  | 新密碼(6-14位數，只可輸入英數字)

<!-- END_36145607fee603518d590bd3ffbc10b2 -->

<!-- START_012b433d65eb272ea1ae1a662376bd77 -->
## getGameUrl
-取遊戲連結

> Example request:

```bash
curl -X POST "http://localhost/api/getGameUrl" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"game_id":"gURNAfJDz51bCUdX"}'

```

```javascript
const url = new URL("http://localhost/api/getGameUrl");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "game_id": "gURNAfJDz51bCUdX"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": "https:\/\/example.invalid\/forwardGame.do?params=zC8r+rGZDg..."
}
```

### HTTP Request
`POST api/getGameUrl`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    game_id | interger |  required  | 遊戲系統編號

<!-- END_012b433d65eb272ea1ae1a662376bd77 -->

<!-- START_252b18e1896189679d24bc8621b2ef41 -->
## balanceToVendor
-平台餘額轉帳至遊戲商餘額

> Example request:

```bash
curl -X POST "http://localhost/api/balanceToVendor" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":"ecXsJ1LM1lpa0Xwh","point":"v9b7ge7ogJdc9O59"}'

```

```javascript
const url = new URL("http://localhost/api/balanceToVendor");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": "ecXsJ1LM1lpa0Xwh",
    "point": "v9b7ge7ogJdc9O59"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": {
        "balance": "100",
        "vendor_balance": "200"
    }
}
```

### HTTP Request
`POST api/balanceToVendor`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | interger |  required  | 遊戲商系統編號
    point | interger |  required  | 轉入金額

<!-- END_252b18e1896189679d24bc8621b2ef41 -->

<!-- START_e9ee68acf23565ca8187ebc7fb4a67ba -->
## vendorToBalance
-遊戲商餘額轉帳至平台餘額

> Example request:

```bash
curl -X POST "http://localhost/api/vendorToBalance" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":"N166EwVXPjxWB9h2","point":"XiqD8QN26oB7nXqq"}'

```

```javascript
const url = new URL("http://localhost/api/vendorToBalance");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": "N166EwVXPjxWB9h2",
    "point": "XiqD8QN26oB7nXqq"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": {
        "balance": "100",
        "vendor_balance": "200"
    }
}
```

### HTTP Request
`POST api/vendorToBalance`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | interger |  required  | 遊戲商系統編號
    point | interger |  required  | 轉出金額

<!-- END_e9ee68acf23565ca8187ebc7fb4a67ba -->

<!-- START_a524d236dd691776be3315d40786a1db -->
## transactions
-轉點記錄列表

> Example request:

```bash
curl -X POST "http://localhost/api/transactions" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":"xDAY7ypwfdCxATWw","start_time":"2019-05-01 00:00:00","end_time":"2019-05-05 23:59:59","page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/transactions");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": "xDAY7ypwfdCxATWw",
    "start_time": "2019-05-01 00:00:00",
    "end_time": "2019-05-05 23:59:59",
    "page": 1,
    "per_page": 30
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "vendor_id": "遊戲商系統編號,ex:2",
            "vendor_name": "遊戲商名稱,ex:Joker",
            "type": "類型1:轉入遊戲商2:轉入平台,ex:1",
            "point": "平台點數,ex:100",
            "vendor_point": "遊戲商點數,ex:100",
            "balance": "平台點數餘額,ex:0",
            "vendor_balance": "遊戲商點數餘額,ex:100",
            "rate": "比值(平台點數:遊戲商點數,rate:1),ex:1",
            "status": "狀態1000成功, 其他看result,ex:1000",
            "result": "結果",
            "created_at": "建立時間,ex:2019-03-06 16:55:22",
            "updated_at": "更新時間,ex:2019-03-08 17:43:28"
        },
        {
            "id": 2,
            "vendor_id": 1,
            "vendor_name": "AG",
            "type": 2,
            "point": 100,
            "vendor_point": 50,
            "balance": 100,
            "vendor_balance": 50,
            "rate": "2",
            "status": "1000",
            "result": "{\"balance\":100,\"vendor_balance\":50}",
            "created_at": "2019-03-06 16:55:22",
            "updated_at": "2019-03-08 17:43:28"
        }
    ],
    "pagination": {
        "per_page": "單頁筆數,ex:20",
        "total": "總筆數,ex:10",
        "pages": "總頁數,ex:1",
        "previous": "上一頁,ex:",
        "next": "下一頁,ex:1",
        "current": "目前頁面,ex:"
    }
}
```

### HTTP Request
`POST api/transactions`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | interger |  required  | 遊戲商系統編號
    start_time | dateTime |  optional  | 狀態
    end_time | dateTime |  optional  | 狀態
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_a524d236dd691776be3315d40786a1db -->

<!-- START_da554241525ac9a66d4124013d5cba4c -->
## lastTransactionToVendor
-最後一次轉點進遊戲記錄

> Example request:

```bash
curl -X POST "http://localhost/api/lastTransactionToVendor" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/lastTransactionToVendor");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Accept": "application/json",
    "Content-Type": "application/json",
}

fetch(url, {
    method: "POST",
    headers: headers,
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": {
        "id": 7,
        "member_id": 1,
        "vendor_id": 1,
        "point": "10.00",
        "balance": "990.00",
        "vendor_balance": "10.00",
        "status": 1000,
        "created_at": "2019-04-09 10:20:44"
    }
}
```

### HTTP Request
`POST api/lastTransactionToVendor`


<!-- END_da554241525ac9a66d4124013d5cba4c -->

<!-- START_9003078435f4d001e76912a8100e5c67 -->
## orderTypes
訂單類型列表

> Example request:

```bash
curl -X POST "http://localhost/api/orderTypes" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/orderTypes");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Accept": "application/json",
    "Content-Type": "application/json",
}

fetch(url, {
    method: "POST",
    headers: headers,
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:AliPay"
        },
        {
            "id": 2,
            "name": "UnionPay"
        }
    ]
}
```

### HTTP Request
`POST api/orderTypes`


<!-- END_9003078435f4d001e76912a8100e5c67 -->

<!-- START_285c87403b6cfdebe26bc357f22e870f -->
## orders
-訂單列表(只能查看自己的訂單記錄)

> Example request:

```bash
curl -X POST "http://localhost/api/orders" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"order_type_id":1,"start_time":"2019-05-01 00:00:00","end_time":"2019-05-05 23:59:59","status":1,"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/orders");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "order_type_id": 1,
    "start_time": "2019-05-01 00:00:00",
    "end_time": "2019-05-05 23:59:59",
    "status": 1,
    "page": 1,
    "per_page": 30
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "order_type_id": "訂單類別系統編號,ex:2",
            "currency_id": "幣種系統編號,ex:1",
            "member_id": "會員系統編號,ex:1",
            "amount": "金額,ex:100",
            "point": "平台點數,ex:100",
            "balance": "儲值後餘額,ex:100",
            "status": "狀態0建立1成功2失敗,ex:1",
            "created_at": "建立時間,ex:2019-03-06 16:55:22",
            "updated_at": "更新時間,ex:2019-03-08 17:43:28"
        },
        {
            "id": 2,
            "order_type_id": 2,
            "currency_id": 1,
            "member_id": 1,
            "amount": 300,
            "point": 300,
            "balance": 400,
            "status": 2,
            "created_at": "2019-03-06 16:55:22",
            "updated_at": "2019-03-08 17:43:28"
        }
    ],
    "pagination": {
        "per_page": "單頁筆數,ex:20",
        "total": "總筆數,ex:10",
        "pages": "總頁數,ex:1",
        "previous": "上一頁,ex:",
        "next": "下一頁,ex:1",
        "current": "目前頁面,ex:"
    }
}
```

### HTTP Request
`POST api/orders`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    order_type_id | integer |  optional  | 狀態
    start_time | dateTime |  optional  | 狀態
    end_time | dateTime |  optional  | 狀態
    status | integer |  optional  | 狀態
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_285c87403b6cfdebe26bc357f22e870f -->

<!-- START_cd95d3e90339c282e0b608349e80a381 -->
## order
-訂單明細(只能查看自己的訂單記錄)

> Example request:

```bash
curl -X POST "http://localhost/api/order" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/order");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": {
        "id": "系統編號,ex:1",
        "ordery_type_id": "訂單類別系統編號,ex:2",
        "currency_id": "幣種系統編號,ex:1",
        "member_id": "會員系統編號,ex:1",
        "amount": "金額,ex:100",
        "point": "平台點數,ex:100",
        "status": "狀態0建立1成功2失敗,ex:1",
        "created_at": "建立時間,ex:2019-03-06 16:55:22",
        "updated_at": "更新時間,ex:2019-03-08 17:43:28"
    }
}
```

### HTTP Request
`POST api/order`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 訂單系統編號

<!-- END_cd95d3e90339c282e0b608349e80a381 -->

<!-- START_1ae2cc25e1afb2b87a70a92c6cd5a56a -->
## order/create
-新增訂單

> Example request:

```bash
curl -X POST "http://localhost/api/order/create" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"order_type_id":1,"amount":100,"callback":"http:\/\/xxx.xxx.xx"}'

```

```javascript
const url = new URL("http://localhost/api/order/create");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "order_type_id": 1,
    "amount": 100,
    "callback": "http:\/\/xxx.xxx.xx"
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS",
    "data": "支付頁面網址,ex:http:\/\/xxx.xxxx\/pay"
}
```

### HTTP Request
`POST api/order/create`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    order_type_id | integer |  required  | 訂單類別系統編號(銀聯)
    amount | integer |  required  | 金額
    callback | string |  required  | 支付後導回哪個網址

<!-- END_1ae2cc25e1afb2b87a70a92c6cd5a56a -->

<!-- START_de3413bf02c9bb71627fa96e1c1c409f -->
## events
- 活動列表

> Example request:

```bash
curl -X POST "http://localhost/api/events" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/events");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "page": 1,
    "per_page": 30
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": [
        {
            "id": 1,
            "title": "活動標題,ex:儲值滿額送",
            "content": "活動內容,ex:端午假期。。。",
            "type_id": "類型編號(1:活動,2:任務),ex:1",
            "agent_id": "營運商系統編號,ex:1",
            "prize_id": "獎勵活動編號：ex:1",
            "prize_number": "取得獎勵數量,ex:1",
            "prize_expire_date": "獎勵兌換到期日,ex:0000-00-00 00:00:00",
            "count": "可抽獎次數,ex:10",
            "type": "遊戲(1:刮刮卡Scratch,2:輪盤Roulete,3:直接兌獎),ex:1",
            "start_time": "活動開始時間，ex:2019-06-14 00:00:00",
            "end_time": "活動結束時間，ex:2019-07-11 23:59:59",
            "show_exchange": "兌換按鈕0:不顯示1:顯示,ex:1",
            "detail(需滿足的條件)": [
                {
                    "id": 1,
                    "action_id": "條件類型,ex:1",
                    "order_type_id": null,
                    "order_type_name": null,
                    "vendor_id": null,
                    "vendor_name": null,
                    "game_id": null,
                    "rate": "0.00",
                    "point": 100,
                    "start_time": "2019-06-14 00:00:00",
                    "end_time": "2019-07-11 23:59:59",
                    "now_point": "目前進度,ex:50",
                    "action_name": "指定動作文字,ex:首次儲值"
                },
                {
                    "id": 2,
                    "action_id": 2,
                    "order_type_id": null,
                    "order_type_name": null,
                    "vendor_id": null,
                    "vendor_name": null,
                    "game_id": null,
                    "rate": "0.00",
                    "point": 500,
                    "start_time": "2019-06-14 00:00:00",
                    "end_time": "2019-07-15 23:59:59",
                    "now_point": 50,
                    "action_name": "贏得金額"
                }
            ]
        }
    ],
    "pagination": {
        "per_page": "單頁筆數,ex:20",
        "total": "總筆數,ex:10",
        "pages": "總頁數,ex:1",
        "previous": "上一頁,ex:",
        "next": "下一頁,ex:1",
        "current": "目前頁面,ex:"
    }
}
```

### HTTP Request
`POST api/events`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_de3413bf02c9bb71627fa96e1c1c409f -->

<!-- START_444df18fc50df5526572851d9def4d45 -->
## event
- 活動詳情

> Example request:

```bash
curl -X POST "http://localhost/api/event" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/event");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": {
        "id": 1,
        "title": "活動標題,ex:儲值滿額送",
        "content": "活動內容,ex:端午假期。。。",
        "type_id": "類型編號(1:活動,2:任務),ex:1",
        "agent_id": "營運商系統編號,ex:1",
        "prize_id": "獎勵活動編號：ex:1",
        "prize_number": "取得獎勵數是,ex:1",
        "prize_expire_date": "獎勵兌換到期日,ex:0000-00-00 00:00:00",
        "count": "可抽獎次數,ex:10",
        "type": "遊戲(1:刮刮卡Scratch,2:輪盤Roulete,3:直接兌獎),ex:1",
        "start_time": "活動開始時間，ex:2019-06-14 00:00:00",
        "end_time": "活動結束時間，ex:2019-07-11 23:59:59",
        "show_exchange": "兌換按鈕0:不可按1:可按,ex:1",
        "show_exchange_text": "兌換按鈕文字,ex:立即兌換",
        "detail(需滿足的條件)": [
            {
                "id": 1,
                "action_id": "條件類型,ex:1",
                "order_type_id": null,
                "order_type_name": null,
                "vendor_id": null,
                "vendor_name": null,
                "game_id": null,
                "rate": "0.00",
                "point": 100,
                "start_time": "2019-06-14 00:00:00",
                "end_time": "2019-07-11 23:59:59",
                "now_point": "目前進度,ex:50",
                "action_text": "指定動作文字,ex:首次儲值"
            },
            {
                "id": 2,
                "action_id": 2,
                "order_type_id": null,
                "order_type_name": null,
                "vendor_id": null,
                "vendor_name": null,
                "game_id": null,
                "rate": "0.00",
                "point": 500,
                "start_time": "2019-06-14 00:00:00",
                "end_time": "2019-07-15 23:59:59",
                "now_point": 30,
                "action_text": "贏得金額"
            }
        ],
        "prize(獎品列表)": [
            {
                "id": 1,
                "name": "獎項名稱,ex:紅利66",
                "point": "獲得點數,ex:66",
                "number": "獎項數量,ex:100"
            },
            {
                "id": 2,
                "name": "紅利888",
                "point": 888,
                "number": 10
            }
        ]
    }
}
```

### HTTP Request
`POST api/event`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 活動系統編號

<!-- END_444df18fc50df5526572851d9def4d45 -->

<!-- START_ccb9124848d4d640cf30215ed55e0722 -->
## prizes
- 取得中獎列表

> Example request:

```bash
curl -X POST "http://localhost/api/prizes" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/prizes");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "page": 1,
    "per_page": 30
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "event_name": "活動名稱,ex:首儲獎勵",
            "name": "獎項名稱,ex:紅利66",
            "point": "取得點數,ex:66",
            "updated_at": "取得時間,ex:2019-01-01 00:00:00"
        },
        {
            "id": 2,
            "event_name": "活動名稱,ex:首儲獎勵",
            "name": "銘謝惠顧",
            "point": 0,
            "updated_at": "2019-01-01 00:00:00"
        }
    ],
    "pagination": {
        "per_page": "單頁筆數,ex:20",
        "total": "總筆數,ex:10",
        "pages": "總頁數,ex:1",
        "previous": "上一頁,ex:",
        "next": "下一頁,ex:1",
        "current": "目前頁面,ex:"
    }
}
```

### HTTP Request
`POST api/prizes`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_ccb9124848d4d640cf30215ed55e0722 -->

<!-- START_3ce6b61758c7eec99d3d49e2c81c4983 -->
## getPrize
- 取得中獎項目

> Example request:

```bash
curl -X POST "http://localhost/api/getPrize" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"event_id":1}'

```

```javascript
const url = new URL("http://localhost/api/getPrize");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "event_id": 1
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "成功",
    "data": {
        "id": 15,
        "event_id": 16,
        "type_id": 2,
        "name": "首儲大輪盤",
        "background": "輪盤背景圖片，刮刮卡刮卡圖層",
        "number": 1,
        "expire_date": "2019-07-31 23:59:59",
        "prizes(獎項列表，輪盤才會用到)": [
            {
                "id": "獎項編號，ex:73",
                "name": "獎項名稱，ex:不無小補50"
            },
            {
                "id": 81,
                "name": "銘謝惠顧"
            }
        ],
        "prize(獲得獎項)": {
            "id": "獎項編號，ex:81",
            "name": "獎項名稱，ex:銘謝惠顧",
            "prize_member": "兌獎記錄系統編號 ,ex:7"
        }
    }
}
```

### HTTP Request
`POST api/getPrize`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    event_id | integer |  required  | 活動系統編號

<!-- END_3ce6b61758c7eec99d3d49e2c81c4983 -->

<!-- START_893051129bf7d5cb81aa32bef35c6ba7 -->
## setPrize
- 設定中獎項目為已領取

> Example request:

```bash
curl -X POST "http://localhost/api/setPrize" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"prize_member_id":1}'

```

```javascript
const url = new URL("http://localhost/api/setPrize");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "prize_member_id": 1
}

fetch(url, {
    method: "POST",
    headers: headers,
    body: body
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "Success"
}
```

### HTTP Request
`POST api/setPrize`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    prize_member_id | integer |  required  | 兌獎記錄系統編號

<!-- END_893051129bf7d5cb81aa32bef35c6ba7 -->

<!-- START_61739f3220a224b34228600649230ad1 -->
## logout
會員登出

> Example request:

```bash
curl -X POST "http://localhost/api/logout" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/logout");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Accept": "application/json",
    "Content-Type": "application/json",
}

fetch(url, {
    method: "POST",
    headers: headers,
})
    .then(response => response.json())
    .then(json => console.log(json));
```

> Example response (200):

```json
{
    "code": 1000,
    "message": "SUCCESS"
}
```

### HTTP Request
`POST api/logout`


<!-- END_61739f3220a224b34228600649230ad1 -->


