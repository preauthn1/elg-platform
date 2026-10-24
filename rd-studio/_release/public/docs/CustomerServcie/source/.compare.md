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

Welcome to the generated API reference.
[Get Postman Collection](http://localhost/docs/collection.json)

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
 
 

#general
<!-- START_b8a26549776bc13ed0fd6a49df203ae6 -->
## Authenticate the request for channel access.

> Example request:

```bash
curl -X POST "http://localhost/broadcasting/auth" 
```

```javascript
const url = new URL("http://localhost/broadcasting/auth");

let headers = {
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
`POST broadcasting/auth`


<!-- END_b8a26549776bc13ed0fd6a49df203ae6 -->

<!-- START_c3fa189a6c95ca36ad6ac4791a873d23 -->
## login
-會員登入(Header不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/login" \
    -H "Content-Type: application/json" \
    -d '{"username":"username","password":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/login");

let headers = {
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
        "nickname": "Test",
        "thumbnail": "頭像，ex:http:\/\/xxxx.xxxx.xxx",
        "last_login": "2019-01-01 00:00:00",
        "token": "roOAsDmfT9nP1Y9uKD8aMylk6HcYPlfz"
    }
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

<!-- START_251ae2f999ed24b033dc7019479bc321 -->
## languages
-語系列表(不需要token)

> Example request:

```bash
curl -X POST "http://localhost/api/languages" 
```

```javascript
const url = new URL("http://localhost/api/languages");

let headers = {
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

<!-- START_0816bb2ed6c760dfe9f246da4844a9e9 -->
## user/list
-營運商/客服列表

> Example request:

```bash
curl -X POST "http://localhost/api/user/list" \
    -H "Content-Type: application/json" \
    -d '{"username":"nisi","nickname":"rerum","status":true,"page":"1","per_page":"30"}'

```

```javascript
const url = new URL("http://localhost/api/user/list");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "username": "nisi",
    "nickname": "rerum",
    "status": true,
    "page": "1",
    "per_page": "30"
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
            "username": "營運商\/客服帳號,ex:Admin",
            "nickname": "營運商\/客服暱稱,ex:XXX",
            "thumbnail": "縮圖,ex:http:\/\/xxx.xx.xxx",
            "limit_of_credit": "開洗分額度(0為無上限),ex:0",
            "used_credit": "已使用額度,ex:1",
            "status": "狀態-0凍結1正常,ex:1",
            "last_login": "最後登入時間,ex:2019-04-01 09:36:46"
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
`POST api/user/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | string |  optional  | option 營運商/客服帳號
    nickname | string |  optional  | option 營運商/客服名稱
    status | boolean |  optional  | option 營運商/客服狀態
    page | numeric |  required  | 第幾頁
    per_page | numeric |  required  | 每頁筆數

<!-- END_0816bb2ed6c760dfe9f246da4844a9e9 -->

<!-- START_738381316e92326985aea7068c8c5b09 -->
## user/view
-管理者/營運商/客服詳細資料(只能查看自己下層的帳號)

> Example request:

```bash
curl -X POST "http://localhost/api/user/view" \
    -H "Content-Type: application/json" \
    -d '{"id":"1"}'

```

```javascript
const url = new URL("http://localhost/api/user/view");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": "1"
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
        "username": "管理者\/營運商\/客服帳號,ex:Admin",
        "API_USER": "使用API的帳號,ex:Admin",
        "nickname": "管理者\/營運商\/客服暱稱,ex:XXX",
        "thumbnail": "縮圖,ex:http:\/\/xxx.xx.xxx",
        "limit_of_credit": "開洗分額度(0為無上限),ex:0",
        "used_credit": "已使用額度,ex:1",
        "status": "狀態-0凍結1正常,ex:1",
        "last_login": "最後登入時間,ex:2019-04-01 09:36:46"
    }
}
```

### HTTP Request
`POST api/user/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | numeric |  required  | 管理員系統編號

<!-- END_738381316e92326985aea7068c8c5b09 -->

<!-- START_10b7085b525a35c366ef92c43c14dae0 -->
## user/add
-建立營運商/客服

> Example request:

```bash
curl -X POST "http://localhost/api/user/add" \
    -H "Content-Type: application/json" \
    -d '{"username":"xxx","nickname":"xxx","password":"illo","password_confirmation":"at","thumbnail":"dolor","limit_of_credit":13}'

```

```javascript
const url = new URL("http://localhost/api/user/add");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "username": "xxx",
    "nickname": "xxx",
    "password": "illo",
    "password_confirmation": "at",
    "thumbnail": "dolor",
    "limit_of_credit": 13
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
    "data": "營運商\/客服編號,ex:1"
}
```

### HTTP Request
`POST api/user/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | string |  required  | 帳號(只可輸入英數，不可重覆，字數長度為4-10)
    nickname | string |  optional  | 暱稱
    password | string |  required  | 管理員密碼(字數長度為4-15)
    password_confirmation | string |  required  | 確認密碼
    thumbnail | string |  optional  | base64 file 個人頭像
    limit_of_credit | integer |  optional  | 開/洗分上限金額(預設為0無上限)

<!-- END_10b7085b525a35c366ef92c43c14dae0 -->

<!-- START_72b90abec8b2584f33ab57648a653280 -->
## user/edit
-編輯營運商/客服(只能編輯自己下層的帳號)

> Example request:

```bash
curl -X POST "http://localhost/api/user/edit" \
    -H "Content-Type: application/json" \
    -d '{"id":"1","nickname":"xxx","password":"totam","password_confirmation":"cum","thumbnail":"tempora","limit_of_credit":2,"status":true}'

```

```javascript
const url = new URL("http://localhost/api/user/edit");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": "1",
    "nickname": "xxx",
    "password": "totam",
    "password_confirmation": "cum",
    "thumbnail": "tempora",
    "limit_of_credit": 2,
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
`POST api/user/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | string |  required  | 營運商/客服系統編號
    nickname | string |  optional  | 暱稱
    password | string |  optional  | 管理員密碼(字數長度為4-15)
    password_confirmation | string |  optional  | 如需修改密碼，需傳確認密碼
    thumbnail | string |  optional  | base64 file 個人頭像
    limit_of_credit | integer |  optional  | 開/洗分上限金額(預設為0無上限)
    status | boolean |  optional  | 狀態-0凍結1正常

<!-- END_72b90abec8b2584f33ab57648a653280 -->

<!-- START_258d1d8ce18c41d12abd5aafc12ff68a -->
## user/delete
-凍結管理者

> Example request:

```bash
curl -X POST "http://localhost/api/user/delete" \
    -H "Content-Type: application/json" \
    -d '{"id":"1"}'

```

```javascript
const url = new URL("http://localhost/api/user/delete");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": "1"
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
`POST api/user/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | numeric |  required  | 管理員系統編號

<!-- END_258d1d8ce18c41d12abd5aafc12ff68a -->

<!-- START_1a6b5db92a9389be06a6a690e5e1e724 -->
## user/changePasswordBySelf
-修改自己的密碼

> Example request:

```bash
curl -X POST "http://localhost/api/user/changePasswordBySelf" \
    -H "Content-Type: application/json" \
    -d '{"password":"aaa123456","new_password":"abc123456","new_password_confirmation":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/user/changePasswordBySelf");

let headers = {
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
`POST api/user/changePasswordBySelf`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    password | string |  required  | 舊密碼
    new_password | string |  required  | 新密碼(字數長度為4-15)
    new_password_confirmation | string |  required  | 確認新密碼

<!-- END_1a6b5db92a9389be06a6a690e5e1e724 -->

<!-- START_407404878bf31488b51bf1c86a71d7b3 -->
## user/changePassword
-修改其他管理者密碼(只能修改自己下層的帳號)

> Example request:

```bash
curl -X POST "http://localhost/api/user/changePassword" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"new_password":"abc123456","new_password_confirmation":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/user/changePassword");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
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
`POST api/user/changePassword`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 管理員系統編號
    new_password | string |  required  | 新密碼(字數長度為4-15)
    new_password_confirmation | string |  required  | 確認新密碼

<!-- END_407404878bf31488b51bf1c86a71d7b3 -->

<!-- START_5349e8dd6baba9d4d2d4636577b5b8cf -->
## userMenu/list
-管理者選單列表

> Example request:

```bash
curl -X POST "http://localhost/api/userMenu/list" 
```

```javascript
const url = new URL("http://localhost/api/userMenu/list");

let headers = {
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
            "id": 1,
            "parent": 0,
            "code": "user",
            "name": "帳號管理",
            "url": "user",
            "symbol": "user",
            "action": "create,update",
            "target": "_blank",
            "submenu": {
                "id": 2,
                "parent": 1,
                "code": "user_list",
                "name": "帳號列表",
                "url": "user\/list",
                "symbol": "",
                "action": "list",
                "submenu": {
                    "id": 3,
                    "parent": 2,
                    "code": "user_create",
                    "name": "新增帳號",
                    "url": "user\/create",
                    "symbol": "",
                    "action": "create"
                }
            }
        },
        {
            "id": 4,
            "parent": 0,
            "code": "report",
            "name": "報表管理",
            "url": "report",
            "symbol": "report",
            "action": "list"
        }
    ]
}
```

### HTTP Request
`POST api/userMenu/list`


<!-- END_5349e8dd6baba9d4d2d4636577b5b8cf -->

<!-- START_a6059f1a266cc4ba0e24aa23e88bf049 -->
## setting/view
-查看系統設定詳情

> Example request:

```bash
curl -X POST "http://localhost/api/setting/view" 
```

```javascript
const url = new URL("http://localhost/api/setting/view");

let headers = {
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
    "data": {
        "name": "聊天室名稱",
        "background_image": "聊天室背景圖",
        "background_color": "聊天室背景色",
        "header_background_color": "Header背景色",
        "header_font_color": "Header文字色"
    }
}
```

### HTTP Request
`POST api/setting/view`


<!-- END_a6059f1a266cc4ba0e24aa23e88bf049 -->

<!-- START_2df8b92c152bf70ea24385072ad3f303 -->
## setting/save
-儲存系統設定

> Example request:

```bash
curl -X POST "http://localhost/api/setting/save" \
    -H "Content-Type: application/json" \
    -d '{"name":"xxx","background_image":"id","background_color":"sunt","header_background_color":"ut","header_font_color":"explicabo"}'

```

```javascript
const url = new URL("http://localhost/api/setting/save");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "name": "xxx",
    "background_image": "id",
    "background_color": "sunt",
    "header_background_color": "ut",
    "header_font_color": "explicabo"
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
`POST api/setting/save`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    name | string |  optional  | 聊天室名稱
    background_image | string |  optional  | 聊天室背景圖(base64 encode)
    background_color | string |  optional  | 聊天室背景色
    header_background_color | string |  optional  | Header背景色
    header_font_color | string |  optional  | Header文字色

<!-- END_2df8b92c152bf70ea24385072ad3f303 -->

<!-- START_51b0b93b3211c7b041b3257373a6e6fd -->
## transaction/list
- 開分/洗分記錄列表

> Example request:

```bash
curl -X POST "http://localhost/api/transaction/list" \
    -H "Content-Type: application/json" \
    -d '{"username":1,"status":true,"start_time":"2019-01-01 00:00:00","end_time":"2019-01-01 00:00:00","page":"1","per_page":"30"}'

```

```javascript
const url = new URL("http://localhost/api/transaction/list");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "username": 1,
    "status": true,
    "start_time": "2019-01-01 00:00:00",
    "end_time": "2019-01-01 00:00:00",
    "page": "1",
    "per_page": "30"
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
            "user_id": "管理員系統編號,ex:1",
            "user_username": "管理員帳號,ex:cs2",
            "member_id": "會員系統編號,ex:1",
            "member_username": "會員帳號,ex:abc",
            "type": "類型1:轉入，2:轉出,ex:1",
            "amount": "金額,ex:100",
            "remark": "備註",
            "status": "開洗分狀態0:失敗,1:成功",
            "created_at": "建立時間,ex:2019-01-01 00:00:00",
            "updated_at": "更新時間,ex:2019-01-01 00:00:00"
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
`POST api/transaction/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | integer |  optional  | 會員帳號
    status | boolean |  optional  | 開洗分狀態0:失敗,1:成功
    start_time | dateTime |  optional  | 開始時間
    end_time | dateTime |  optional  | 結束時間
    page | numeric |  required  | 第幾頁
    per_page | numeric |  required  | 每頁筆數

<!-- END_51b0b93b3211c7b041b3257373a6e6fd -->

<!-- START_5e159444c86511798b8c30ee40b6e73f -->
## transaction/add
- 新增開分/洗分記錄

> Example request:

```bash
curl -X POST "http://localhost/api/transaction/add" \
    -H "Content-Type: application/json" \
    -d '{"member_id":1,"type":1,"amount":100,"remark":"perferendis"}'

```

```javascript
const url = new URL("http://localhost/api/transaction/add");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "member_id": 1,
    "type": 1,
    "amount": 100,
    "remark": "perferendis"
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
    "data": "開分\/洗分記錄系統編號,ex:1"
}
```

### HTTP Request
`POST api/transaction/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    member_id | integer |  required  | 好友系統編號
    type | integer |  required  | 類型1:轉入，2:轉出
    amount | integer |  required  | 金額
    remark | string |  optional  | 備註

<!-- END_5e159444c86511798b8c30ee40b6e73f -->

<!-- START_07b6aa8649f3b25babdfd7de16f8101d -->
## transaction/edit
- 編輯開分/洗分記錄

> Example request:

```bash
curl -X POST "http://localhost/api/transaction/edit" \
    -H "Content-Type: application/json" \
    -d '{"transaction_id":1,"remark":"earum"}'

```

```javascript
const url = new URL("http://localhost/api/transaction/edit");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "transaction_id": 1,
    "remark": "earum"
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
`POST api/transaction/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    transaction_id | integer |  required  | 開分/洗分記錄系統編號
    remark | string |  required  | 備註

<!-- END_07b6aa8649f3b25babdfd7de16f8101d -->

<!-- START_61739f3220a224b34228600649230ad1 -->
## logout
-會員登出

> Example request:

```bash
curl -X POST "http://localhost/api/logout" 
```

```javascript
const url = new URL("http://localhost/api/logout");

let headers = {
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

<!-- START_2d683cc1c7ede959c7275e5115a7ce4e -->
## member/search
-帳號查詢

> Example request:

```bash
curl -X POST "http://localhost/api/member/search" \
    -H "Content-Type: application/json" \
    -d '{"username":"hello"}'

```

```javascript
const url = new URL("http://localhost/api/member/search");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "username": "hello"
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
            "id": "會員系統編號,ex:1",
            "third_party_id": "第三方使用者帳號,ex:Admin",
            "username": "使用者帳號,ex:Admin",
            "nickname": "使用者暱稱(如果有設定別稱會顯示別稱),ex:XXX",
            "thumbnail": "縮圖,ex:http:\/\/xxx.xx.xxx",
            "friend_id": "好友編號",
            "status": "好友狀態0:不是好友，1:好友，2:黑名單,ex:1"
        }
    ]
}
```

### HTTP Request
`POST api/member/search`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | string |  required  | 好友ID

<!-- END_2d683cc1c7ede959c7275e5115a7ce4e -->

<!-- START_b4acd3a40a9205347d8efc6a5d6f9b4a -->
## member/edit
-編輯自己的帳號資訊

> Example request:

```bash
curl -X POST "http://localhost/api/member/edit" \
    -H "Content-Type: application/json" \
    -d '{"username":"xxx","nickname":"xxx","thumbnail":"rerum","status":true}'

```

```javascript
const url = new URL("http://localhost/api/member/edit");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "username": "xxx",
    "nickname": "xxx",
    "thumbnail": "rerum",
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
`POST api/member/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | string |  optional  | 好友ID
    nickname | string |  optional  | 暱稱
    thumbnail | string |  optional  | base64 file 個人頭像
    status | boolean |  optional  | 狀態-0凍結1正常

<!-- END_b4acd3a40a9205347d8efc6a5d6f9b4a -->

<!-- START_36ae2341fc127f4b105b604e67a3e22c -->
## friend/list
-好友列表

> Example request:

```bash
curl -X POST "http://localhost/api/friend/list" \
    -H "Content-Type: application/json" \
    -d '{"status":1}'

```

```javascript
const url = new URL("http://localhost/api/friend/list");

let headers = {
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
    "message": "Success",
    "data": [
        {
            "friend_id": "好友系統編號,ex:1",
            "member_id": "會員系統編號,ex:1",
            "room_id": "聊天室系統編號(沒聊過天會是空值),ex:1",
            "type": "類型0:客服聊天，1:個人聊天，2:群組聊天室,ex:1",
            "third_party_id": "第三方使用者帳號,ex:Admin",
            "username": "使用者帳號,ex:Admin",
            "nickname": "使用者暱稱(如果有設定別稱會顯示別稱),ex:XXX",
            "thumbnail": "縮圖,ex:http:\/\/xxx.xx.xxx"
        }
    ]
}
```

### HTTP Request
`POST api/friend/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    status | integer |  required  | 1:好友,2:黑名單

<!-- END_36ae2341fc127f4b105b604e67a3e22c -->

<!-- START_dd8dfc730015c32e45087a09c24f2d5e -->
## friend/show
-好友詳情

> Example request:

```bash
curl -X POST "http://localhost/api/friend/view" \
    -H "Content-Type: application/json" \
    -d '{"member_id":"1"}'

```

```javascript
const url = new URL("http://localhost/api/friend/view");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "member_id": "1"
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
        "friend_id": "好友系統編號,ex:1",
        "member_id": "會員系統編號,ex:1",
        "type": "類型0:客服聊天，1:個人聊天，2:群組聊天室,ex:1",
        "third_party_id": "第三方使用者帳號,ex:Admin",
        "username": "使用者帳號,ex:Admin",
        "nickname": "使用者暱稱(如果有設定別稱會顯示別稱),ex:XXX",
        "thumbnail": "縮圖,ex:http:\/\/xxx.xx.xxx",
        "status": "狀態1:好友,2:黑名單,ex:1"
    }
}
```

### HTTP Request
`POST api/friend/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    member_id | numeric |  required  | 會員系統編號

<!-- END_dd8dfc730015c32e45087a09c24f2d5e -->

<!-- START_3730ce451c628ffd94a5898529b2db48 -->
## friend/add
-新增好友

> Example request:

```bash
curl -X POST "http://localhost/api/friend/add" \
    -H "Content-Type: application/json" \
    -d '{"member_id":"1","nickname":"xxx"}'

```

```javascript
const url = new URL("http://localhost/api/friend/add");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "member_id": "1",
    "nickname": "xxx"
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
`POST api/friend/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    member_id | numeric |  required  | 會員系統編號
    nickname | string |  optional  | 暱稱

<!-- END_3730ce451c628ffd94a5898529b2db48 -->

<!-- START_1352d4bcbad89437453de1b0df5679d0 -->
## friend/edit
-編輯好友資訊

> Example request:

```bash
curl -X POST "http://localhost/api/friend/edit" \
    -H "Content-Type: application/json" \
    -d '{"friend_id":"1","nickname":"xxx","status":true}'

```

```javascript
const url = new URL("http://localhost/api/friend/edit");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "friend_id": "1",
    "nickname": "xxx",
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
`POST api/friend/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    friend_id | numeric |  required  | 好友系統編號
    nickname | string |  optional  | 暱稱
    status | boolean |  optional  | 狀態-1:好友2黑名單

<!-- END_1352d4bcbad89437453de1b0df5679d0 -->

<!-- START_011c7f81411089446c8abaf5886260db -->
## friend/delete
-刪除好友

> Example request:

```bash
curl -X POST "http://localhost/api/friend/delete" \
    -H "Content-Type: application/json" \
    -d '{"friend_id":"1"}'

```

```javascript
const url = new URL("http://localhost/api/friend/delete");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "friend_id": "1"
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
`POST api/friend/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    friend_id | numeric |  required  | 好友系統編號

<!-- END_011c7f81411089446c8abaf5886260db -->

<!-- START_104c4e3575f229720e398baf333e39db -->
## room/list
-聊天室列表

> Example request:

```bash
curl -X POST "http://localhost/api/room/list" 
```

```javascript
const url = new URL("http://localhost/api/room/list");

let headers = {
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
            "type": "聊天室類型0:客服聊天，1:個人聊天，2:群組聊天室,ex:1",
            "code": "聊天室編號,ex:abcd",
            "name": "聊天室名稱,ex:XXX",
            "thumbnail": "聊天室縮圖,ex:http:\/\/xxx.xx.xxx",
            "members": "1,2,3",
            "message_id": "最後一則訊息編號",
            "message_member_id": "傳送最後一則訊息的會員編號",
            "message_username": "傳送最後一則訊息的會員帳號",
            "message_nickname": "傳送最後一則訊息的會員暱稱",
            "message_type": "傳送最後一則訊息的類型1:文字，2:圖片，3:紅包，4:聲音，5:影片",
            "message_content": "傳送最後一則訊息的內容",
            "message_time": "傳送最後一則訊息的時間，EX:2019-08-23 11:37:25"
        }
    ]
}
```

### HTTP Request
`POST api/room/list`


<!-- END_104c4e3575f229720e398baf333e39db -->

<!-- START_1ed65972b90ef71533ede84315a6bc6e -->
## room/enter
-進入聊天室(聊天室編號及好友系統編號擇一傳送即可，如皆傳送，以聊天室編號為主)

> Example request:

```bash
curl -X POST "http://localhost/api/room/enter" \
    -H "Content-Type: application/json" \
    -d '{"code":"abcd","friend_id":1}'

```

```javascript
const url = new URL("http://localhost/api/room/enter");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "code": "abcd",
    "friend_id": 1
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
        "room": {
            "id": "房間編號，EX:8",
            "type": "類型0:客服聊天，1:個人聊天，2:群組聊天室",
            "code": "聊天室編號，EHxX2SyGTvfoGXuXNewWRIms",
            "name": "聊天室名稱",
            "thumbnail": "聊天室頭像"
        },
        "message預設為最後三十筆(時間順序)": [
            {
                "id": "會員編號，EX:8",
                "username": "好友ID",
                "nickname": "暱稱",
                "thumbnail": "頭像",
                "type": "類型1:文字，2:圖片，3:紅包，4:聲音，5:影片",
                "content": "聊天內容",
                "is_self": "屬於誰的對話0:自己，1:別人",
                "created_at": "2019-08-23 08:58:43"
            }
        ]
    }
}
```

### HTTP Request
`POST api/room/enter`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    code | string |  optional  | 聊天室編號
    friend_id | integer |  optional  | 好友系統編號

<!-- END_1ed65972b90ef71533ede84315a6bc6e -->

<!-- START_e9ea8604230d5cf3b8d1a195dda1bee8 -->
## room/history
-聊天室歷史訊息

> Example request:

```bash
curl -X POST "http://localhost/api/room/history" \
    -H "Content-Type: application/json" \
    -d '{"code":"abcd","page":"1","per_page":"30"}'

```

```javascript
const url = new URL("http://localhost/api/room/history");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "code": "abcd",
    "page": "1",
    "per_page": "30"
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
    "data(時間倒序)": [
        {
            "id": "會員編號，EX:8",
            "username": "好友ID",
            "nickname": "暱稱",
            "thumbnail": "頭像",
            "type": "類型1:文字，2:圖片，3:紅包，4:聲音，5:影片",
            "content": "聊天內容",
            "is_self": "屬於誰的對話0:自己，1:別人",
            "created_at": "2019-08-23 08:58:43"
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
`POST api/room/history`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    code | string |  required  | 聊天室編號
    page | numeric |  required  | 第幾頁
    per_page | numeric |  required  | 每頁筆數

<!-- END_e9ea8604230d5cf3b8d1a195dda1bee8 -->

<!-- START_a1ee86f56523a349a36541d62058eee2 -->
## readMessage
-標記為已讀的最後編號

> Example request:

```bash
curl -X POST "http://localhost/api/readMessage" \
    -H "Content-Type: application/json" \
    -d '{"code":"abcd","message_id":1}'

```

```javascript
const url = new URL("http://localhost/api/readMessage");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "code": "abcd",
    "message_id": 1
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
`POST api/readMessage`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    code | string |  required  | 聊天室編號
    message_id | integer |  required  | 訊息編號

<!-- END_a1ee86f56523a349a36541d62058eee2 -->

<!-- START_3c160c8f9e008739f31c0a493c28397b -->
## note/list
-記事列表

> Example request:

```bash
curl -X POST "http://localhost/api/note/list" \
    -H "Content-Type: application/json" \
    -d '{"room_id":1,"username":0,"status":true,"start_time":"2019-01-01 00:00:00","end_time":"2019-01-01 00:00:00","page":"1","per_page":"30"}'

```

```javascript
const url = new URL("http://localhost/api/note/list");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "room_id": 1,
    "username": 0,
    "status": true,
    "start_time": "2019-01-01 00:00:00",
    "end_time": "2019-01-01 00:00:00",
    "page": "1",
    "per_page": "30"
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
            "room_id": "聊天室系統編號,ex:1",
            "member_id": "會員系統編號,ex:1",
            "member_username": "會員帳號,ex:abc",
            "title": "標題,ex:test",
            "created_at": "建立時間,ex:2019-01-01 00:00:00",
            "create_user": "建立者,ex:b",
            "update_user": "最後修改者,ex:a",
            "status": "狀態0:未結案，1:已結案"
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
`POST api/note/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    room_id | integer |  optional  | 聊天室系統編號
    username | integer |  optional  | 會員帳號
    status | boolean |  optional  | 記事狀態0:未結案,1:已結案
    start_time | dateTime |  optional  | 開始時間
    end_time | dateTime |  optional  | 結束時間
    page | numeric |  required  | 第幾頁
    per_page | numeric |  required  | 每頁筆數

<!-- END_3c160c8f9e008739f31c0a493c28397b -->

<!-- START_cfa839d3be5644d936f272261cb30d66 -->
## note/add
-新增記事

> Example request:

```bash
curl -X POST "http://localhost/api/note/add" \
    -H "Content-Type: application/json" \
    -d '{"room_id":1,"title":"xxx","status":false}'

```

```javascript
const url = new URL("http://localhost/api/note/add");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "room_id": 1,
    "title": "xxx",
    "status": false
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
    "data": "記事編號,ex:1"
}
```

### HTTP Request
`POST api/note/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    room_id | integer |  required  | 聊天室系統編號
    title | string |  required  | 內容
    status | boolean |  required  | 狀態0:未結案,1:已結案

<!-- END_cfa839d3be5644d936f272261cb30d66 -->

<!-- START_cbf8f7296d4ef7b7d716dcd97b36d34c -->
## note/edit
-編輯記事

> Example request:

```bash
curl -X POST "http://localhost/api/note/edit" \
    -H "Content-Type: application/json" \
    -d '{"note_id":1,"title":"xxx","status":false}'

```

```javascript
const url = new URL("http://localhost/api/note/edit");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "note_id": 1,
    "title": "xxx",
    "status": false
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
`POST api/note/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    note_id | integer |  required  | 記事系統編號
    title | string |  required  | 內容
    status | boolean |  required  | 狀態0:未結案,1:已結案

<!-- END_cbf8f7296d4ef7b7d716dcd97b36d34c -->

<!-- START_c8ac44adbb89aedf73810151335966e3 -->
## note_content/list
-記事內容列表

> Example request:

```bash
curl -X POST "http://localhost/api/note_content/list" \
    -H "Content-Type: application/json" \
    -d '{"note_id":"1","start_time":"2019-01-01 00:00:00","end_time":"2019-01-01 00:00:00","page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/note_content/list");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "note_id": "1",
    "start_time": "2019-01-01 00:00:00",
    "end_time": "2019-01-01 00:00:00",
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
            "content": "內容,ex:hello world",
            "create_user": "建立者，ex:abc",
            "created_at": "建立時間，ex:2019-01-01 00:00:00"
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
`POST api/note_content/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    note_id | required |  optional  | integer 記事編號
    start_time | dateTime |  optional  | 開始時間
    end_time | dateTime |  optional  | 結束時間
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_c8ac44adbb89aedf73810151335966e3 -->

<!-- START_89f3995cdf05c2880c5aeab3c71280e4 -->
## note_content/add
-新增記事內容

> Example request:

```bash
curl -X POST "http://localhost/api/note_content/add" \
    -H "Content-Type: application/json" \
    -d '{"note_id":1,"content":"xxx"}'

```

```javascript
const url = new URL("http://localhost/api/note_content/add");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "note_id": 1,
    "content": "xxx"
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
    "data": "記事編號,ex:1"
}
```

### HTTP Request
`POST api/note_content/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    note_id | integer |  required  | 記事系統編號
    content | string |  required  | 內容

<!-- END_89f3995cdf05c2880c5aeab3c71280e4 -->

<!-- START_83b5e0c12ff8f0769c7da98187d283a9 -->
## note_content/edit
-編輯記事內容

> Example request:

```bash
curl -X POST "http://localhost/api/note_content/edit" \
    -H "Content-Type: application/json" \
    -d '{"content_id":1,"content":"1"}'

```

```javascript
const url = new URL("http://localhost/api/note_content/edit");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "content_id": 1,
    "content": "1"
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
`POST api/note_content/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    content_id | integer |  required  | 記事內容系統編號
    content | string |  required  | 記事內容系統編號

<!-- END_83b5e0c12ff8f0769c7da98187d283a9 -->

<!-- START_6335b2fcf94215b301134b87ec0009b0 -->
## note_content/delete
-刪除記事

> Example request:

```bash
curl -X POST "http://localhost/api/note_content/delete" \
    -H "Content-Type: application/json" \
    -d '{"content_id":"1"}'

```

```javascript
const url = new URL("http://localhost/api/note_content/delete");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "content_id": "1"
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
`POST api/note_content/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    content_id | numeric |  required  | 記事內容系統編號

<!-- END_6335b2fcf94215b301134b87ec0009b0 -->

<!-- START_0b7f6240bbdcc1d229cf476d88a26cdf -->
## sendMessage
-傳送訊息

> Example request:

```bash
curl -X POST "http://localhost/api/sendMessage" \
    -H "Content-Type: application/json" \
    -d '{"code":"abcd","type":1,"content":"1"}'

```

```javascript
const url = new URL("http://localhost/api/sendMessage");

let headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "code": "abcd",
    "type": 1,
    "content": "1"
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
`POST api/sendMessage`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    code | string |  required  | 聊天室編號
    type | integer |  required  | 訊息類型1:文字，2:圖片，3:紅包，4:聲音，5:影片
    content | string |  required  | 訊息內容

<!-- END_0b7f6240bbdcc1d229cf476d88a26cdf -->


