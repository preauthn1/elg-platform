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
　　


#general
<!-- START_c3fa189a6c95ca36ad6ac4791a873d23 -->
## login
-會員登入(不需要token)

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
        "username": "Admin",
        "token": "roOAsDmfT9nP1Y9uKD8aMylk6HcYPlfz"
    }
}
```

### HTTP Request
`POST api/login`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | string |  required  | 代理帳號
    password | string |  required  | 代理密碼

<!-- END_c3fa189a6c95ca36ad6ac4791a873d23 -->

<!-- START_251ae2f999ed24b033dc7019479bc321 -->
## languages
-語系列表(不需要token)

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

<!-- START_61739f3220a224b34228600649230ad1 -->
## logout
-會員登出

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

<!-- START_6ddbe43d68bb3493cf15bed23c548a12 -->
## agent/list
-營運商列表(只能查看自己的下線)

> Example request:

```bash
curl -X POST "http://localhost/api/agent/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"username":"xxx","email":"xxx","status":"1 or 0","page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/agent/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "username": "xxx",
    "email": "xxx",
    "status": "1 or 0",
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
            "id": "營運商系統編號,ex:1",
            "parent": "上層系統編號(0的話是第一層),ex:0",
            "code": "推薦碼,ex:123456",
            "username": "營運商帳號,ex:dmin",
            "email": "營運商email,ex:user@example.invalid",
            "nickname": "暱稱,ex:xxx",
            "mobile": "手機號碼,ex:0912345678",
            "percentage": "分成百分比,ex:5",
            "timezone_id": "時區系統編號,ex:1",
            "country_id": "國家系統編號,ex:1",
            "zip_code": "區碼,ex:230",
            "district": "地區,ex:中正區",
            "city": "城市,ex:台北市",
            "address": "地址,ex:忠孝西路",
            "email_validate": "是否需要電子信箱認證0:不必需1:必需,ex:1",
            "mobile_validate": "是否需要手機認證0:不必需1:必需,ex:1",
            "frontend_register": "前端是否顯示會員註冊0:不開放1:開放,ex:1",
            "status": "狀態(0:凍結,1:正常),ex:1",
            "last_login": "最後登入時間,ex:2019-04-09 01:28:23"
        },
        {
            "id": 2,
            "parent": 1,
            "code": "KkgWvB",
            "username": "test",
            "email": "user@example.invalid",
            "nickname": "xxx",
            "mobile": "0912345678",
            "percentage": 5,
            "timezone_id": 1,
            "country_id": 1,
            "zip_code": "12345",
            "district": "中正區",
            "city": "台北市",
            "address": "忠孝西路",
            "email_validate": 1,
            "mobile_validate": 0,
            "frontend_register": 0,
            "status": 2,
            "last_login": null
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
`POST api/agent/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  optional  | 營運商系統編號
    username | string |  optional  | 帳號
    email | string |  optional  | 電子信箱
    status | string |  optional  | 營運商狀態(0:凍結,1:正常)
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_6ddbe43d68bb3493cf15bed23c548a12 -->

<!-- START_55fc43eee7726946f1e50a3eb9da4c59 -->
## agent/view
-營運商詳細資料(只能查看自己的下線)

> Example request:

```bash
curl -X POST "http://localhost/api/agent/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1"}'

```

```javascript
const url = new URL("http://localhost/api/agent/view");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
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
    "message": "SUCCESS",
    "data": {
        "id": "營運商系統編號,ex:1",
        "parent": "上層系統編號(0的話是第一層),ex:0",
        "code": "推薦碼,ex:123456",
        "username": "營運商帳號,ex:Admin",
        "email": "營運商email,ex:user@example.invalid",
        "nickname": "暱稱,ex:xxx",
        "mobile": "手機號碼,ex:0912345678",
        "percentage": "分成百分比,ex:5",
        "timezone_id": "時區系統編號,ex:1",
        "country_id": "國家系統編號,ex:1",
        "zip_code": "郵遞區號,ex:853",
        "district": "地區,ex:中正區",
        "city": "城市,ex:台北市",
        "address": "地址,ex:忠孝西路1號1樓",
        "email_validate": "是否需要電子信箱認證0:不必需1:必需,ex:1",
        "mobile_validate": "是否需要手機認證0:不必需1:必需,ex:1",
        "frontend_register": "前端是否顯示會員註冊0:不開放1:開放,ex:1",
        "status": "狀態-0凍結1正常,ex:1",
        "last_login": "最後登入時間,ex:2019-04-01 09:36:46"
    }
}
```

### HTTP Request
`POST api/agent/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | string |  required  | 營運商系統編號

<!-- END_55fc43eee7726946f1e50a3eb9da4c59 -->

<!-- START_6952d481f3fd2bbfb8b13ea2bbbf117d -->
## agent/add
-新增營運商(只能新增自己的下線)

> Example request:

```bash
curl -X POST "http://localhost/api/agent/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"username":"agent_username","email":"user@example.invalid","nickname":"xxx","password":"CAt1kBpDixgHfyxx","password_confirmation":"vJmoyxcL572JtOGs","mobile":"0912345678","percentage":5,"timezone_id":1,"country_id":"\u53f0\u7063","zip_code":"100","district":"\u4e2d\u6b63\u5340","city":"\u53f0\u5317\u5e02","address":"\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13","email_validate":true,"mobile_validate":true,"frontend_register":true}'

```

```javascript
const url = new URL("http://localhost/api/agent/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "username": "agent_username",
    "email": "user@example.invalid",
    "nickname": "xxx",
    "password": "CAt1kBpDixgHfyxx",
    "password_confirmation": "vJmoyxcL572JtOGs",
    "mobile": "0912345678",
    "percentage": 5,
    "timezone_id": 1,
    "country_id": "\u53f0\u7063",
    "zip_code": "100",
    "district": "\u4e2d\u6b63\u5340",
    "city": "\u53f0\u5317\u5e02",
    "address": "\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13",
    "email_validate": true,
    "mobile_validate": true,
    "frontend_register": true
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
    "data": "系統編號,ex:1"
}
```

### HTTP Request
`POST api/agent/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | unique |  required  | 帳號
    email | email |  required  | 電子信箱
    nickname | string |  optional  | 暱稱
    password | string |  required  | 營運商密碼
    password_confirmation | string |  required  | 營運商確認密碼
    mobile | string |  optional  | 聯絡電話
    percentage | integer |  optional  | 分成百分比
    timezone_id | integer |  optional  | 時區系統編號
    country_id | string |  optional  | 國家
    zip_code | string |  optional  | 郵遞區號
    district | string |  optional  | 區域
    city | string |  optional  | 城市
    address | string |  optional  | 地址
    email_validate | boolean |  required  | 是否需要電子信箱認證0:不必需1:必需
    mobile_validate | boolean |  required  | 是否需要手機認證0:不必需1:必需
    frontend_register | boolean |  required  | 是否開放會員註冊0:不開放,1:開放

<!-- END_6952d481f3fd2bbfb8b13ea2bbbf117d -->

<!-- START_3025d81cf425c2c8875e2a93477caca1 -->
## agent/edit
-編輯營運商(只能管理自己的下線)

> Example request:

```bash
curl -X POST "http://localhost/api/agent/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1","email":"user@example.invalid","nickname":"xxx","password":"2ojHwUZiuJ4ElBok","password_confirmation":"guSqgwMAGHNCOQLT","mobile":"0912345678","percentage":5,"timezone_id":1,"country_id":"\u53f0\u7063","zip_code":"100","district":"\u4e2d\u6b63\u5340","city":"\u53f0\u5317\u5e02","address":"\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13","email_validate":true,"mobile_validate":true,"frontend_register":true,"status":true}'

```

```javascript
const url = new URL("http://localhost/api/agent/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": "1",
    "email": "user@example.invalid",
    "nickname": "xxx",
    "password": "2ojHwUZiuJ4ElBok",
    "password_confirmation": "guSqgwMAGHNCOQLT",
    "mobile": "0912345678",
    "percentage": 5,
    "timezone_id": 1,
    "country_id": "\u53f0\u7063",
    "zip_code": "100",
    "district": "\u4e2d\u6b63\u5340",
    "city": "\u53f0\u5317\u5e02",
    "address": "\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13",
    "email_validate": true,
    "mobile_validate": true,
    "frontend_register": true,
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
    "message": "SUCCESS"
}
```

### HTTP Request
`POST api/agent/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | string |  required  | 營運商系統編號
    email | email |  optional  | 電子信箱
    nickname | string |  optional  | 暱稱
    password | string |  optional  | 營運商密碼
    password_confirmation | string |  optional  | 如需修改密碼，需傳營運商確認密碼
    mobile | string |  optional  | 聯絡電話
    percentage | integer |  optional  | 分成百分比
    timezone_id | integer |  optional  | 時區系統編號
    country_id | string |  optional  | 國家
    zip_code | string |  optional  | 郵遞區號
    district | string |  optional  | 區域
    city | string |  optional  | 城市
    address | string |  optional  | 地址
    email_validate | boolean |  required  | 是否需要電子信箱認證0:不必需1:必需
    mobile_validate | boolean |  required  | 是否需要手機認證0:不必需1:必需
    frontend_register | boolean |  required  | 是否開放會員註冊0:不開放,1:開放
    status | boolean |  required  | 狀態0:停用,1:啟用

<!-- END_3025d81cf425c2c8875e2a93477caca1 -->

<!-- START_4acd8429ac6162f74e3dc35c658cee3c -->
## agent/changePasswordBySelf
-變更自己的密碼

> Example request:

```bash
curl -X POST "http://localhost/api/agent/changePasswordBySelf" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"password":"aaa123456","new_password":"abc123456","new_password_confirmation":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/agent/changePasswordBySelf");

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
`POST api/agent/changePasswordBySelf`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    password | string |  required  | 舊密碼
    new_password | string |  required  | 新密碼
    new_password_confirmation | string |  required  | 確認新密碼

<!-- END_4acd8429ac6162f74e3dc35c658cee3c -->

<!-- START_b8b26a37b5a07cc2566b9cd9fa803bb9 -->
## agent/changePassword
-修改營運商密碼(只能編輯自己的下線)

> Example request:

```bash
curl -X POST "http://localhost/api/agent/changePassword" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1","new_password":"abc123456","new_password_confirmation":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/agent/changePassword");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": "1",
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
`POST api/agent/changePassword`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | string |  required  | 營運商系統編號
    new_password | string |  required  | 新密碼
    new_password_confirmation | string |  required  | 確認新密碼

<!-- END_b8b26a37b5a07cc2566b9cd9fa803bb9 -->

<!-- START_c86e31728ea371c58c4cc99d52a37499 -->
## agent/delete
-凍結營運商

> Example request:

```bash
curl -X POST "http://localhost/api/agent/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/agent/delete");

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
    "message": "Success"
}
```

### HTTP Request
`POST api/agent/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 營運商系統編號

<!-- END_c86e31728ea371c58c4cc99d52a37499 -->

<!-- START_602678ae66a365cac26da47fb8ba5ccd -->
## agentMenu/list
-營運商選單列表(只可查看自己及自己的下線)

> Example request:

```bash
curl -X POST "http://localhost/api/agentMenu/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":"1"}'

```

```javascript
const url = new URL("http://localhost/api/agentMenu/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "agent_id": "1"
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
            "id": 1,
            "parent": 0,
            "code": "member",
            "name": "會員管理",
            "url": "member",
            "symbol": "member",
            "action": "create,update",
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
`POST api/agentMenu/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | string |  required  | 營運商系統編號

<!-- END_602678ae66a365cac26da47fb8ba5ccd -->

<!-- START_f92c45cac609bd4f095be3a0fef1cf54 -->
## agentPermission/setting
-設定營運商權限

> Example request:

```bash
curl -X POST "http://localhost/api/agentPermission/setting" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"menus":"{\"1\":\"view,update,delete\",\"2\":\"view\",\"3\":\"view\"}"}'

```

```javascript
const url = new URL("http://localhost/api/agentPermission/setting");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "agent_id": 1,
    "menus": "{\"1\":\"view,update,delete\",\"2\":\"view\",\"3\":\"view\"}"
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
`POST api/agentPermission/setting`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  required  | 營運商系統編號
    menus | array |  required  | 選單{"選單編號":"操作功能"}

<!-- END_f92c45cac609bd4f095be3a0fef1cf54 -->

<!-- START_9962620c59037f9312cc97ba18fb9a7c -->
## member/list
-會員列表(只能查看自己的會員)

> Example request:

```bash
curl -X POST "http://localhost/api/member/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"username":"xxx","email":"xxx","status":true,"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/member/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "username": "xxx",
    "email": "xxx",
    "status": true,
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
            "id": 1,
            "parent": 0,
            "username": "member",
            "email": "user@example.invalid",
            "nickname": "xxx",
            "first_name": "HELLO",
            "last_name": "WORLD",
            "birthday": "1990-01-01",
            "mobile": "0912345678",
            "country_id": 1,
            "zip_code": "230",
            "district": "中正區",
            "city": "台北市",
            "address": "忠孝西路一段",
            "status": 1,
            "is_validate": 1,
            "mobile_validate": 1,
            "email_validate": 1,
            "last_login": "2019-03-26 03:44:34"
        },
        {
            "id": 2,
            "parent": 1,
            "username": "member2",
            "email": "user@example.invalid",
            "nickname": "xxx",
            "first_name": "HELLO",
            "last_name": "WORLD",
            "birthday": "1990-01-01",
            "mobile": "0912345678",
            "country_id": 1,
            "zip_code": "230",
            "district": "中正區",
            "city": "台北市",
            "address": "忠孝西路一段",
            "status": 1,
            "is_validate": 1,
            "mobile_validate": 1,
            "email_validate": 1,
            "last_login": "2019-03-26 03:44:34"
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
`POST api/member/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | string |  optional  | 會員帳號
    email | string |  optional  | 會員電子信箱
    status | boolean |  optional  | 會員狀態
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_9962620c59037f9312cc97ba18fb9a7c -->

<!-- START_e07e87057f0ee9cf666722482300808b -->
## member/view
-會員詳細資料(只能查看自己的會員)

> Example request:

```bash
curl -X POST "http://localhost/api/member/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/member/view");

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
        "id": 1,
        "parent": 0,
        "username": "member",
        "email": "user@example.invalid",
        "nickname": "xxx",
        "first_name": "HELLO",
        "last_name": "WORLD",
        "birthday": "1990-01-01",
        "mobile": "0912345678",
        "country_id": 1,
        "zip_code": "230",
        "district": "中正區",
        "city": "台北市",
        "address": "忠孝西路一段",
        "status": 1,
        "is_validate": 1,
        "mobile_validate": 1,
        "email_validate": 1,
        "last_login": "2019-03-26 03:44:34"
    }
}
```

### HTTP Request
`POST api/member/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 會員系統編號

<!-- END_e07e87057f0ee9cf666722482300808b -->

<!-- START_d01641d6dd4cb8b47935866c79b277a6 -->
## member/add
-新增會員(只能新增自己的會員)

> Example request:

```bash
curl -X POST "http://localhost/api/member/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"username":"username","password":"GtXwSFVopKwiTVuS","password_confirmation":"rl3MkwjbctIhQnJB","email":"user@example.invalid","nickname":"xxx","first_name":"CHIA HUA","last_name":"CHAN","birthday":"1990-01-01","mobile":"0912345678","timezone_id":1,"country_id":1,"zip_code":"100","district":"\u4e2d\u6b63\u5340","city":"\u53f0\u5317\u5e02","address":"\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13","status":true}'

```

```javascript
const url = new URL("http://localhost/api/member/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "username": "username",
    "password": "GtXwSFVopKwiTVuS",
    "password_confirmation": "rl3MkwjbctIhQnJB",
    "email": "user@example.invalid",
    "nickname": "xxx",
    "first_name": "CHIA HUA",
    "last_name": "CHAN",
    "birthday": "1990-01-01",
    "mobile": "0912345678",
    "timezone_id": 1,
    "country_id": 1,
    "zip_code": "100",
    "district": "\u4e2d\u6b63\u5340",
    "city": "\u53f0\u5317\u5e02",
    "address": "\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13",
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
    "message": "SUCCESS",
    "data": "會員系統編號,ex:2"
}
```

### HTTP Request
`POST api/member/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | unique |  required  | 會員帳號，限英數字串，4-10個字
    password | string |  required  | 會員密碼，6-12個字
    password_confirmation | string |  required  | 確認會員密碼
    email | email |  optional  | 會員電子信箱，最多60個字
    nickname | string |  optional  | 暱稱，最多60個字
    first_name | string |  optional  | 英文名，最多15個字
    last_name | string |  optional  | 英文姓，最多10個字
    birthday | string |  optional  | 出生年月日，需大於18歲
    mobile | string |  optional  | 聯絡電話
    timezone_id | integer |  optional  | 時區系統編號
    country_id | integer |  optional  | 國家
    zip_code | string |  optional  | 郵遞區號
    district | string |  optional  | 區域
    city | string |  optional  | 城市
    address | string |  optional  | 地址
    status | boolean |  optional  | 狀態0:停用1:啟用

<!-- END_d01641d6dd4cb8b47935866c79b277a6 -->

<!-- START_b4acd3a40a9205347d8efc6a5d6f9b4a -->
## member/edit
-編輯會員(只能編輯自己的會員)

> Example request:

```bash
curl -X POST "http://localhost/api/member/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1","email":"user@example.invalid","password":"3ZlEzw7KbAdC4Ygw","password_confirmation":"f82wYl88MoGpGyqK","nickname":"xxx","first_name":"CHIA HUA","last_name":"CHAN","birthday":"1990-01-01","mobile":"0912345678","timezone_id":1,"country_id":1,"zip_code":"100","district":"\u4e2d\u6b63\u5340","city":"\u53f0\u5317\u5e02","address":"\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13","status":true,"is_validate":true}'

```

```javascript
const url = new URL("http://localhost/api/member/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": "1",
    "email": "user@example.invalid",
    "password": "3ZlEzw7KbAdC4Ygw",
    "password_confirmation": "f82wYl88MoGpGyqK",
    "nickname": "xxx",
    "first_name": "CHIA HUA",
    "last_name": "CHAN",
    "birthday": "1990-01-01",
    "mobile": "0912345678",
    "timezone_id": 1,
    "country_id": 1,
    "zip_code": "100",
    "district": "\u4e2d\u6b63\u5340",
    "city": "\u53f0\u5317\u5e02",
    "address": "\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13",
    "status": true,
    "is_validate": true
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
    id | string |  required  | 會員系統編號,需為自己的會員
    email | email |  optional  | 會員電子信箱，最多60個字
    password | string |  optional  | 會員密碼
    password_confirmation | string |  optional  | 如需修改密碼，需傳會員確認密碼
    nickname | string |  optional  | 暱稱，最多60個字
    first_name | string |  optional  | 英文名，最多15個字
    last_name | string |  optional  | 英文姓，最多10個字
    birthday | string |  optional  | 出生年月日，需大於18歲
    mobile | string |  optional  | 聯絡電話
    timezone_id | integer |  optional  | 時區系統編號
    country_id | integer |  optional  | 國家
    zip_code | string |  optional  | 郵遞區號
    district | string |  optional  | 區域
    city | string |  optional  | 城市
    address | string |  optional  | 地址
    status | boolean |  optional  | 狀態(0:停用1:正常)
    is_validate | boolean |  optional  | 認證狀態(0:未通過1:已通過)

<!-- END_b4acd3a40a9205347d8efc6a5d6f9b4a -->

<!-- START_b4313203875f188e18806bda330ee325 -->
## member/changePassword
-修改會員密碼(只能編輯自己的會員)

> Example request:

```bash
curl -X POST "http://localhost/api/member/changePassword" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1","new_password":"abc123456","new_password_confirmation":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/member/changePassword");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": "1",
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
`POST api/member/changePassword`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | string |  required  | 會員系統編號
    new_password | string |  required  | 新密碼
    new_password_confirmation | string |  required  | 確認新密碼

<!-- END_b4313203875f188e18806bda330ee325 -->

<!-- START_cb5107c9486361e80ee17e063989db0b -->
## member/delete
-凍結會員(只能編輯自己的會員)

> Example request:

```bash
curl -X POST "http://localhost/api/member/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/member/delete");

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
    "message": "Success"
}
```

### HTTP Request
`POST api/member/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 會員系統編號

<!-- END_cb5107c9486361e80ee17e063989db0b -->

<!-- START_71b8a207093b82954a25b404ef59c011 -->
## validation/list
-驗證類型列表

> Example request:

```bash
curl -X POST "http://localhost/api/validation/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/validation/list");

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
            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:ID frontend",
            "status": "狀態(0:未啟用1:啟用),ex:1"
        },
        {
            "id": 2,
            "name": "ID backend",
            "status": 0
        }
    ]
}
```

### HTTP Request
`POST api/validation/list`


<!-- END_71b8a207093b82954a25b404ef59c011 -->

<!-- START_3703b9be144a0389e66f2c31e0ab2eef -->
## validation/setting
-設定營運商使用何種驗證

> Example request:

```bash
curl -X POST "http://localhost/api/validation/setting" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"type_id":1,"status":true}'

```

```javascript
const url = new URL("http://localhost/api/validation/setting");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "type_id": 1,
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
`POST api/validation/setting`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    type_id | integer |  required  | 模版編號
    status | boolean |  optional  | 狀態0:停用,1:啟用 

<!-- END_3703b9be144a0389e66f2c31e0ab2eef -->

<!-- START_dbd03c93f80e7ecdc1ff438e6de2741a -->
## template/list
-模版列表

> Example request:

```bash
curl -X POST "http://localhost/api/template/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/template/list");

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
            "name": "模版名稱,ex:pink",
            "thumbnail": "模版縮圖,ex:http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "status": "狀態,ex:1",
            "created_at": "建立時間,ex:2019-01-01 00:00:00",
            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
            "admin": 1
        },
        {
            "id": 2,
            "name": "blue",
            "thumbnail": "http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "status": 0,
            "created_at": "2019-01-01 00:00:00",
            "updated_at": "2019-12-31 23:59:59",
            "admin": 1
        }
    ]
}
```

### HTTP Request
`POST api/template/list`


<!-- END_dbd03c93f80e7ecdc1ff438e6de2741a -->

<!-- START_d80c1ad53b583e97bf49e3843a21025c -->
## agentVendor/list
-遊戲商設定列表

> Example request:

```bash
curl -X POST "http://localhost/api/agentVendor/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/agentVendor/list");

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
            "id": "遊戲商系統編號,ex:1",
            "name": "遊戲商名稱,ex:AG",
            "image": "遊戲商圖片,ex:http:\/\/xxx.xxx.xx\/xxxxx.jpg",
            "info": "遊戲商資訊所需欄位",
            "status": "狀態:0未啟用，1啟用",
            "vendor_status": "遊戲商狀態:1開放中，2開發中,ex:1"
        },
        {
            "id": "遊戲商系統編號,ex:2",
            "name": "遊戲商名稱,ex:Joker",
            "image": "遊戲商圖片,ex:http:\/\/xxx.xxx.xx\/xxxxx.jpg",
            "info": "遊戲商資訊所需欄位",
            "status": 0,
            "vendor_status": 1
        }
    ]
}
```

### HTTP Request
`POST api/agentVendor/list`


<!-- END_d80c1ad53b583e97bf49e3843a21025c -->

<!-- START_0ebbbdebf8a4290b8636fcc7b0f7a8ab -->
## agentVendor/setting
-遊戲商設定

> Example request:

```bash
curl -X POST "http://localhost/api/agentVendor/setting" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":1,"info":"AES\u52a0\u5bc6\u5f8c\u7684\u5b57\u4e32","status":true}'

```

```javascript
const url = new URL("http://localhost/api/agentVendor/setting");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": 1,
    "info": "AES\u52a0\u5bc6\u5f8c\u7684\u5b57\u4e32",
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
`POST api/agentVendor/setting`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | integer |  required  | 遊戲商編號
    info | string |  optional  | 遊戲商API資訊 
    status | boolean |  required  | 狀態0:停用,1:啟用 

<!-- END_0ebbbdebf8a4290b8636fcc7b0f7a8ab -->

<!-- START_2b5cd2e838f416558f33639929ba4cc8 -->
## memberValidation/list
-會員驗證列表

> Example request:

```bash
curl -X POST "http://localhost/api/memberValidation/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"member_id":1,"status":1}'

```

```javascript
const url = new URL("http://localhost/api/memberValidation/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "member_id": 1,
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
            "image": "圖片位置,ex:http:\/\/xxx.xxx.xx\/xxxxx.jpg",
            "remark": "備註,ex:圖片不清楚",
            "status": 2
        },
        {
            "id": 2,
            "type_id": 2,
            "image": "http:\/\/xxx.xxx.xx\/xxxxx.jpg",
            "remark": "",
            "status": 0
        }
    ]
}
```

### HTTP Request
`POST api/memberValidation/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    member_id | integer |  required  | 會員編號
    status | integer |  optional  | 認證狀態0:未通過,1:通過,2:拒絕

<!-- END_2b5cd2e838f416558f33639929ba4cc8 -->

<!-- START_9fcf6dad20ba194819d4a41856e64fe9 -->
## memberValidation/view
-會員驗證詳情

> Example request:

```bash
curl -X POST "http://localhost/api/memberValidation/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/memberValidation/view");

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
        "id": 2,
        "name": "身份證正面",
        "type_id": 2,
        "image": "http:\/\/xxx.xxx.xx\/xxxxx.jpg",
        "remark": "",
        "status": 0
    }
}
```

### HTTP Request
`POST api/memberValidation/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 會員認證項目編號

<!-- END_9fcf6dad20ba194819d4a41856e64fe9 -->

<!-- START_33d8ffac675c876b3d1e3db8cfc435e0 -->
## memberValidation/update
-更新會員驗證狀態

> Example request:

```bash
curl -X POST "http://localhost/api/memberValidation/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"status":1,"reamrk":"\u7167\u7247\u4e0d\u6e05\u695a"}'

```

```javascript
const url = new URL("http://localhost/api/memberValidation/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "status": 1,
    "reamrk": "\u7167\u7247\u4e0d\u6e05\u695a"
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
`POST api/memberValidation/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 會員驗證檔案系統編號
    status | integer |  required  | 狀態：1:認證成功，2:認證失敗
    reamrk | string |  optional  | 備註

<!-- END_33d8ffac675c876b3d1e3db8cfc435e0 -->

<!-- START_3074c5e80ecd0546c4d313c9a1b64894 -->
## agentDomain/list
-取得可用域名(只能查詢自己的及自己下線的)

> Example request:

```bash
curl -X POST "http://localhost/api/agentDomain/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"domain":"agent1","page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/agentDomain/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "agent_id": 1,
    "domain": "agent1",
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
            "domain": "域名,ex:agent1",
            "template_id": "模版系統編號,ex:1",
            "logo_pc": "電腦版Logo,ex:http:\/\/xxxxx.xxx.xxx\/xxx.jpg",
            "logo_mobile": "手機版Logo,ex:http:\/\/xxxxx.xxx.xxx\/xxx.jpg",
            "title": "網站名稱,ex:Hello",
            "status": "狀態0:停用1:啟用,ex:1"
        },
        {
            "id": 2,
            "domain": "agent2",
            "template_id": "2",
            "logo_pc": "http:\/\/xxxxx.xxx.xxx\/xxx.jpg",
            "logo_mobile": "http:\/\/xxxxx.xxx.xxx\/xxx.jpg",
            "title": "Hello",
            "status": 1
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
`POST api/agentDomain/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  optional  | 類型名稱
    domain | string |  optional  | 類型名稱
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_3074c5e80ecd0546c4d313c9a1b64894 -->

<!-- START_4c42978f9fb650d286caa0d95c0d6978 -->
## agentDomain/view
-取得可用域名詳情(只會顯示自己的)

> Example request:

```bash
curl -X POST "http://localhost/api/agentDomain/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/agentDomain/view");

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
        "domain": "域名,ex:agent1",
        "template_id": "模版系統編號,ex:1",
        "logo_pc": "電腦版Logo,ex:http:\/\/xxxxx.xxx.xxx\/xxx.jpg",
        "logo_mobile": "手機版Logo,ex:http:\/\/xxxxx.xxx.xxx\/xxx.jpg",
        "title": "網站名稱,ex:Hello",
        "status": "狀態0:停用1:啟用,ex:1"
    }
}
```

### HTTP Request
`POST api/agentDomain/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 域名編號

<!-- END_4c42978f9fb650d286caa0d95c0d6978 -->

<!-- START_78edb60fa184f9820cd59b8d73b2ca55 -->
## agentDomain/setting
-設定域名要使用的模版

> Example request:

```bash
curl -X POST "http://localhost/api/agentDomain/setting" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"template_id":1,"logo_pc":"gIJxt8o4qwZuFCBU","logo_mobile":"iqlBOg9ihPqZSle5","title":"Hello Money"}'

```

```javascript
const url = new URL("http://localhost/api/agentDomain/setting");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "template_id": 1,
    "logo_pc": "gIJxt8o4qwZuFCBU",
    "logo_mobile": "iqlBOg9ihPqZSle5",
    "title": "Hello Money"
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
`POST api/agentDomain/setting`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 域名系統編號
    template_id | integer |  optional  | 模版系統編號
    logo_pc | string |  optional  | base64 file 電腦版Logo圖片
    logo_mobile | string |  optional  | base64 file 手機版Logo圖片
    title | string |  optional  | 網站名稱

<!-- END_78edb60fa184f9820cd59b8d73b2ca55 -->

<!-- START_bfe944498cb3c57eae0da01c80609d10 -->
## country/list
-國家列表

> Example request:

```bash
curl -X POST "http://localhost/api/country/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/country/list");

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
            "code": "代碼,ex:TW",
            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:台灣"
        },
        {
            "id": 3,
            "code": "HK",
            "name": "香港"
        }
    ]
}
```

### HTTP Request
`POST api/country/list`


<!-- END_bfe944498cb3c57eae0da01c80609d10 -->

<!-- START_6a1f66369355dbd076826d171fd05d7f -->
## currency/list
-匯率列表

> Example request:

```bash
curl -X POST "http://localhost/api/currency/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/currency/list");

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
`POST api/currency/list`


<!-- END_6a1f66369355dbd076826d171fd05d7f -->

<!-- START_3f57f7b96a3322cc7ac65bfa960f7e75 -->
## analyticType/list
-第三方分析類型列表

> Example request:

```bash
curl -X POST "http://localhost/api/analyticType/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/analyticType/list");

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
            "id": "類型編號,ex:1",
            "name": "類型名稱,ex:fb",
            "sort": "排序,ex:1"
        },
        {
            "id": 2,
            "type": "ga",
            "sort": 2
        }
    ]
}
```

### HTTP Request
`POST api/analyticType/list`


<!-- END_3f57f7b96a3322cc7ac65bfa960f7e75 -->

<!-- START_832ffc9077536c1aa68dad202350505f -->
## analytics/list
-第三方分析設定列表

> Example request:

```bash
curl -X POST "http://localhost/api/analytics/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"status":true}'

```

```javascript
const url = new URL("http://localhost/api/analytics/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
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
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "type": "代碼,ex:1",
            "code": "名稱,ex:45121196152222635"
        },
        {
            "id": 2,
            "type": 2,
            "code": "86458966411"
        }
    ]
}
```

### HTTP Request
`POST api/analytics/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    status | boolean |  optional  | 狀態

<!-- END_832ffc9077536c1aa68dad202350505f -->

<!-- START_55c4a54526729932f52da41b3159f720 -->
## analytics/setting
-設定第三方分析程式碼

> Example request:

```bash
curl -X POST "http://localhost/api/analytics/setting" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"type":1,"code":"86586453","status":true}'

```

```javascript
const url = new URL("http://localhost/api/analytics/setting");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "type": 1,
    "code": "86586453",
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
`POST api/analytics/setting`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    type | integer |  required  | 系統編號
    code | string |  required  | 程式碼
    status | boolean |  required  | 狀態

<!-- END_55c4a54526729932f52da41b3159f720 -->

<!-- START_60fde7e9822283540150be3eec91994d -->
## bulletinType/list
-公告類型列表

> Example request:

```bash
curl -X POST "http://localhost/api/bulletinType/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/bulletinType/list");

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
`POST api/bulletinType/list`


<!-- END_60fde7e9822283540150be3eec91994d -->

<!-- START_13442701231570c69bebf2c8e110bdd4 -->
## bulletin/list
-公告列表

> Example request:

```bash
curl -X POST "http://localhost/api/bulletin/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"type_id":1,"language_id":1,"country_id":1,"title":"Hello","content":"Hello","page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/bulletin/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "type_id": 1,
    "language_id": 1,
    "country_id": 1,
    "title": "Hello",
    "content": "Hello",
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
            "language_id": "語系編號,ex:1",
            "country_id": "國家編號,ex:1",
            "title": "標題,ex:Hello World",
            "content": "內容,ex:Test",
            "start_time": "開始時間,ex:2019-04-01 00:00:00",
            "end_time": "結束時間,ex:2019-04-01 23:59:59",
            "created_at": "新增時間,ex:2019-03-01 23:59:59",
            "updated_at": "最後更新時間,ex:2019-04-01 00:00:00",
            "admin": "最後更新者,ex:1"
        },
        {
            "id": 2,
            "type_id": 1,
            "language_id": 2,
            "country_id": 0,
            "title": "Hi",
            "content": "Test 2",
            "start_time": "2019-04-01 00:00:00",
            "end_time": "2019-04-01 23:59:59",
            "created_at": "2019-03-01 23:59:59",
            "updated_at": "2019-04-01 00:00:00",
            "admin": 1
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
`POST api/bulletin/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    type_id | integer |  optional  | 類型編號
    language_id | integer |  optional  | 語系編號
    country_id | integer |  optional  | 國家編號
    title | string |  optional  | 公告標題
    content | string |  optional  | 公告標題
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_13442701231570c69bebf2c8e110bdd4 -->

<!-- START_c48e9a0435274b1ec82ee646e492a0f2 -->
## bulletin/view
-公告詳細資料

> Example request:

```bash
curl -X POST "http://localhost/api/bulletin/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/bulletin/view");

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
        "language_id": "語系編號,ex:1",
        "country_id": "國家編號,ex:1",
        "title": "標題,ex:Hello World",
        "content": "內容,ex:Test",
        "start_time": "開始時間,ex:2019-04-01 00:00:00",
        "end_time": "結束時間,ex:2019-04-01 23:59:59",
        "created_at": "新增時間,ex:2019-03-01 23:59:59",
        "updated_at": "最後更新時間,ex:2019-04-01 00:00:00",
        "admin": "最後更新者,ex:1"
    }
}
```

### HTTP Request
`POST api/bulletin/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  optional  | 系統編號

<!-- END_c48e9a0435274b1ec82ee646e492a0f2 -->

<!-- START_442f6b4d027ff58a6915e6f0cf9f47ba -->
## bulletin/add
-新增公告

> Example request:

```bash
curl -X POST "http://localhost/api/bulletin/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"type_id":1,"language_id":1,"country_id":1,"title":"Hello World","content":"test","start_time":"2019-03-01 23:59:59,","end_time":"2019-04-01 23:59:59,"}'

```

```javascript
const url = new URL("http://localhost/api/bulletin/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "type_id": 1,
    "language_id": 1,
    "country_id": 1,
    "title": "Hello World",
    "content": "test",
    "start_time": "2019-03-01 23:59:59,",
    "end_time": "2019-04-01 23:59:59,"
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
    "data": "系統編號,ex:1"
}
```

### HTTP Request
`POST api/bulletin/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    type_id | integer |  required  | 類型系統編號
    language_id | integer |  required  | 語系系統編號
    country_id | integer |  optional  | 國家系統編號
    title | string |  required  | 標題
    content | string |  required  | 內容
    start_time | datetime |  required  | 公告開始時間
    end_time | datetime |  required  | 公告結束時間

<!-- END_442f6b4d027ff58a6915e6f0cf9f47ba -->

<!-- START_2ba0e3f0ffd0c0c1dc35ab850c6ecb77 -->
## bulletin/edit
-編輯公告

> Example request:

```bash
curl -X POST "http://localhost/api/bulletin/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"type_id":1,"language_id":1,"country_id":1,"title":"Hello World","content":"test","start_time":"2019-03-01 23:59:59,","end_time":"2019-04-01 23:59:59,"}'

```

```javascript
const url = new URL("http://localhost/api/bulletin/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "type_id": 1,
    "language_id": 1,
    "country_id": 1,
    "title": "Hello World",
    "content": "test",
    "start_time": "2019-03-01 23:59:59,",
    "end_time": "2019-04-01 23:59:59,"
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
`POST api/bulletin/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 公告系統編號
    type_id | integer |  required  | 類型系統編號
    language_id | integer |  required  | 語系系統編號
    country_id | integer |  optional  | 國家系統編號
    title | string |  required  | 標題
    content | string |  required  | 內容
    start_time | datetime |  required  | 公告開始時間
    end_time | datetime |  required  | 公告結束時間

<!-- END_2ba0e3f0ffd0c0c1dc35ab850c6ecb77 -->

<!-- START_bc9a602a7b32a3d6b98db08d9557b814 -->
## orderType/list
訂單類型列表

> Example request:

```bash
curl -X POST "http://localhost/api/orderType/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/orderType/list");

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
`POST api/orderType/list`


<!-- END_bc9a602a7b32a3d6b98db08d9557b814 -->

<!-- START_ffe6dabde8e2b3e9cdf0e77acca8f0aa -->
## order/list
-訂單列表(只能查看自己的會員)

> Example request:

```bash
curl -X POST "http://localhost/api/order/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"ordery_type_id":1,"currency_id":1,"username":"abc","order_no":"123456","status":1,"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/order/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "ordery_type_id": 1,
    "currency_id": 1,
    "username": "abc",
    "order_no": "123456",
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
            "order_no": "訂單編號,ex:16431681",
            "username": "會員帳號,ex:test",
            "order_type_id": "訂單類別系統編號,ex:2",
            "currency_id": "幣種系統編號,ex:1",
            "member_id": "會員系統編號,ex:1",
            "amount": "金額,ex:100",
            "point": "平台點數,ex:100",
            "status": "狀態0建立1成功2失敗,ex:1",
            "created_at": "建立時間,ex:2019-03-06 16:55:22",
            "updated_at": "更新時間,ex:2019-03-08 17:43:28"
        },
        {
            "id": 2,
            "order_no": "x16431681",
            "username": "test",
            "order_type_id": 2,
            "currency_id": 1,
            "member_id": 3,
            "amount": 300,
            "point": 300,
            "status": 1,
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
`POST api/order/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    ordery_type_id | integer |  optional  | 訂單系統編號
    currency_id | integer |  optional  | 幣別系統編號
    username | string |  optional  | 會員帳號
    order_no | string |  optional  | 訂單編號
    status | integer |  optional  | 狀態
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_ffe6dabde8e2b3e9cdf0e77acca8f0aa -->

<!-- START_71c63ad6f2fe8a542474daa568f7148e -->
## order/view
-訂單詳細資料(只能查看自己的會員)

> Example request:

```bash
curl -X POST "http://localhost/api/order/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/order/view");

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
        "order_type_id": "訂單類別系統編號,ex:2",
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
`POST api/order/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 訂單系統編號

<!-- END_71c63ad6f2fe8a542474daa568f7148e -->

<!-- START_796332bb7cd7c09d24178b0fbdc2e450 -->
## vendor/liset
-遊戲商列表

> Example request:

```bash
curl -X POST "http://localhost/api/vendor/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}"
```

```javascript
const url = new URL("http://localhost/api/vendor/list");

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
{}
```

### HTTP Request
`POST api/vendor/list`


<!-- END_796332bb7cd7c09d24178b0fbdc2e450 -->

<!-- START_dea7ab680e6f1060c0fac042b096d3d1 -->
## report/deposit
-儲值報表(只能看到自己下面的營運商資料)

> Example request:

```bash
curl -X POST "http://localhost/api/report/deposit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"member_id":1,"start_date":"2019-01-01","end_date":"2019-12-31"}'

```

```javascript
const url = new URL("http://localhost/api/report/deposit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "agent_id": 1,
    "member_id": 1,
    "start_date": "2019-01-01",
    "end_date": "2019-12-31"
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
            "type": "支付方式編號,ex:1",
            "total": "總金額,ex:100",
            "date": "日期,ex:2019-04-01"
        },
        {
            "type": 1,
            "total": 200,
            "date": "2019-04-02"
        }
    ]
}
```

### HTTP Request
`POST api/report/deposit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  optional  | 營運商系統編號
    member_id | integer |  optional  | 會員系統編號
    start_date | date |  required  | 開始日期
    end_date | date |  required  | 結束日期

<!-- END_dea7ab680e6f1060c0fac042b096d3d1 -->

<!-- START_f10eee5882df4616d7319ba25435ea6b -->
## report/transaction
-轉點報表(只能看到自己下面的營運商資料)

> Example request:

```bash
curl -X POST "http://localhost/api/report/transaction" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"member_id":1,"type_id":1,"start_date":"2019-01-01","end_date":"2019-12-31"}'

```

```javascript
const url = new URL("http://localhost/api/report/transaction");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "agent_id": 1,
    "member_id": 1,
    "type_id": 1,
    "start_date": "2019-01-01",
    "end_date": "2019-12-31"
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
            "type": "如果有type_id就依遊戲商分類，其他用轉點類型分,ex:1",
            "total": "總金額,ex:100",
            "date": "日期,ex:2019-04-01"
        },
        {
            "type": 1,
            "total": 200,
            "date": "2019-04-02"
        }
    ]
}
```

### HTTP Request
`POST api/report/transaction`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  optional  | 營運商系統編號
    member_id | integer |  optional  | 會員系統編號
    type_id | integer |  required  | 轉點類型(1:轉入,2:轉出,3:儲值)
    start_date | date |  required  | 開始日期
    end_date | date |  required  | 結束日期

<!-- END_f10eee5882df4616d7319ba25435ea6b -->

<!-- START_f4ce5d08eff2e237afb5641d02dbbd9b -->
## report/register
-註冊報表(只能看到自己下面的營運商資料)

> Example request:

```bash
curl -X POST "http://localhost/api/report/register" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"start_date":"2019-01-01","end_date":"2019-12-31"}'

```

```javascript
const url = new URL("http://localhost/api/report/register");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "agent_id": 1,
    "start_date": "2019-01-01",
    "end_date": "2019-12-31"
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
            "total": "總人數,ex:100",
            "date": "日期,ex:2019-04-01"
        },
        {
            "total": 200,
            "date": "2019-04-02"
        }
    ]
}
```

### HTTP Request
`POST api/report/register`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  required  | 營運商系統編號
    start_date | date |  required  | 開始日期
    end_date | date |  required  | 結束日期

<!-- END_f4ce5d08eff2e237afb5641d02dbbd9b -->

<!-- START_7037662dc34926d14f69ebd9d49af382 -->
## report/numberOfPeople
-營運商下線/會員人數

> Example request:

```bash
curl -X POST "http://localhost/api/report/numberOfPeople" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1}'

```

```javascript
const url = new URL("http://localhost/api/report/numberOfPeople");

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
    "message": "SUCCESS",
    "data": {
        "agent": "下線人數,ex:10",
        "member": "會員人數,ex:200"
    }
}
```

### HTTP Request
`POST api/report/numberOfPeople`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  required  | 營運商系統編號

<!-- END_7037662dc34926d14f69ebd9d49af382 -->

<!-- START_ca4af332d915ffe6921a5d3d18a2a388 -->
## event/list
- 活動列表

> Example request:

```bash
curl -X POST "http://localhost/api/event/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/event/list");

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
            "prize_type_id": "獎勵活動類型：ex:1",
            "prize_number": "取得獎勵數是,ex:1",
            "prize_expire_date": "獎勵兌換到期日,ex:0000-00-00 00:00:00",
            "detail(需滿足的條件)": [
                {
                    "id": 1,
                    "action_id": "條件類型,ex:1",
                    "order_type_id": null,
                    "vendor_id": null,
                    "game_id": null,
                    "rate": "0.00",
                    "point": 100,
                    "start_time": "2019-06-14 00:00:00",
                    "end_time": "2019-07-11 23:59:59"
                },
                {
                    "id": 2,
                    "action_id": 2,
                    "order_type_id": null,
                    "vendor_id": null,
                    "game_id": null,
                    "rate": "0.00",
                    "point": 500,
                    "start_time": "2019-06-14 00:00:00",
                    "end_time": "2019-07-15 23:59:59"
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
`POST api/event/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_ca4af332d915ffe6921a5d3d18a2a388 -->

<!-- START_4f5081ab8209985c08894b0e9cd863c7 -->
## event/view
- 活動詳情

> Example request:

```bash
curl -X POST "http://localhost/api/event/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/event/view");

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
{}
```

### HTTP Request
`POST api/event/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 活動編號

<!-- END_4f5081ab8209985c08894b0e9cd863c7 -->

<!-- START_f3c4655862b32d9df3eb058ae9a4771a -->
## event/add
-新增活動

> Example request:

```bash
curl -X POST "http://localhost/api/event/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"type_id":1,"title":"\u5132\u503c\u6eff\u984d\u9001","content":"\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx","languages":"[{\"language_id\":\"*\u8a9e\u7cfb\u7de8\u865f\",\"title\":\"\u6d3b\u52d5\u6a19\u984c\",\"content\":\"\u6d3b\u52d5\u8aaa\u660e\"},{\"language_id\":2,\"name\":\"\u5132\u503c\u6eff\u984d\u9001\",\"content\":\"\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx\"}]","details":"[{\"action_id\":\"*\u734e\u52f5\u689d\u4ef6\u985e\u578b\",\"order_type_id\":\"\u652f\u4ed8\u65b9\u5f0f\u7de8\u865f\",\"vendor_id\":\"\u904a\u6232\u5546\u7de8\u865f\",\"point\":\"*\u9700\u5927\u65bc\u7b49\u65bc\u591a\u5c11\u9ede\u6578\",\"start_time\":\"*\u958b\u59cb\u6642\u9593\",\"end_time\":\"*\u7d50\u675f\u6642\u9593\"}]","prize_type_id":1,"prize_name":"e6a8oDYyKhPPJoqO","prize_background":"eXTtWV6hgghyptlK","prize_number":13,"prize_expire_date":"4FMRs1z0jLq2q6PT","prizes":"[{\"name\":\"*\u734e\u52f5\u540d\u7a31\",\"point\":\"*\u734e\u52f5\u5e73\u53f0\u9ede\u6578\",\"percentage\":\"*\u4e2d\u734e\u6a5f\u7387\",\"number\":\"*\u734e\u54c1\u6559\u91cf\"}]","assign":"[{\"agent_id\":\"*\u71df\u904b\u5546\u7cfb\u7d71\u7de8\u865f\",\"percentage\":\"*\u4e2d\u734e\u6bd4\u4f8b\"}]"}'

```

```javascript
const url = new URL("http://localhost/api/event/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "type_id": 1,
    "title": "\u5132\u503c\u6eff\u984d\u9001",
    "content": "\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx",
    "languages": "[{\"language_id\":\"*\u8a9e\u7cfb\u7de8\u865f\",\"title\":\"\u6d3b\u52d5\u6a19\u984c\",\"content\":\"\u6d3b\u52d5\u8aaa\u660e\"},{\"language_id\":2,\"name\":\"\u5132\u503c\u6eff\u984d\u9001\",\"content\":\"\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx\"}]",
    "details": "[{\"action_id\":\"*\u734e\u52f5\u689d\u4ef6\u985e\u578b\",\"order_type_id\":\"\u652f\u4ed8\u65b9\u5f0f\u7de8\u865f\",\"vendor_id\":\"\u904a\u6232\u5546\u7de8\u865f\",\"point\":\"*\u9700\u5927\u65bc\u7b49\u65bc\u591a\u5c11\u9ede\u6578\",\"start_time\":\"*\u958b\u59cb\u6642\u9593\",\"end_time\":\"*\u7d50\u675f\u6642\u9593\"}]",
    "prize_type_id": 1,
    "prize_name": "e6a8oDYyKhPPJoqO",
    "prize_background": "eXTtWV6hgghyptlK",
    "prize_number": 13,
    "prize_expire_date": "4FMRs1z0jLq2q6PT",
    "prizes": "[{\"name\":\"*\u734e\u52f5\u540d\u7a31\",\"point\":\"*\u734e\u52f5\u5e73\u53f0\u9ede\u6578\",\"percentage\":\"*\u4e2d\u734e\u6a5f\u7387\",\"number\":\"*\u734e\u54c1\u6559\u91cf\"}]",
    "assign": "[{\"agent_id\":\"*\u71df\u904b\u5546\u7cfb\u7d71\u7de8\u865f\",\"percentage\":\"*\u4e2d\u734e\u6bd4\u4f8b\"}]"
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
    "data": "系統編號"
}
```

### HTTP Request
`POST api/event/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    type_id | integer |  required  | 類型(1:任務,2:活動)
    title | string |  required  | 活動標題
    content | string |  required  | 預設內容
    languages | array |  optional  | option 多語活動標題/內容
    details | array |  required  | 獎勵內容
    prize_type_id | integer |  required  | 獎勵類型
    prize_name | string |  required  | 獎勵遊戲名稱
    prize_background | string |  required  | 獎勵遊戲背景圖(base64 file)
    prize_number | integer |  required  | 獲得遊戲次數
    prize_expire_date | dateTime |  required  | 遊戲次數到期時間
    prizes | array |  required  | 獎勵內容
    assign | array |  required  | 指定開獎

<!-- END_f3c4655862b32d9df3eb058ae9a4771a -->

<!-- START_e5311539b65da39c90017a70949ea82a -->
## event/edit
-編輯活動

> Example request:

```bash
curl -X POST "http://localhost/api/event/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"type_id":1,"title":"\u5132\u503c\u6eff\u984d\u9001","content":"\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx","languages":"[{\"language_id\":\"*\u8a9e\u7cfb\u7de8\u865f\",\"title\":\"\u6d3b\u52d5\u6a19\u984c\",\"content\":\"\u6d3b\u52d5\u8aaa\u660e\"},{\"language_id\":2,\"name\":\"\u5132\u503c\u6eff\u984d\u9001\",\"content\":\"\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx\"}]","details":"[{\"action_id\":\"*\u734e\u52f5\u689d\u4ef6\u985e\u578b\",\"order_type_id\":\"\u652f\u4ed8\u65b9\u5f0f\u7de8\u865f\",\"vendor_id\":\"\u904a\u6232\u5546\u7de8\u865f\",\"point\":\"*\u9700\u5927\u65bc\u7b49\u65bc\u591a\u5c11\u9ede\u6578\",\"start_time\":\"*\u958b\u59cb\u6642\u9593\",\"end_time\":\"*\u7d50\u675f\u6642\u9593\"}]","prize_type_id":1,"prize_name":"cQEXk3kcS8YUrJkK","prize_background":"XI5MPzEikgvLbNet","prize_number":11,"prize_expire_date":"1FDNfATwbG3F2iFR","prizes":"[{\"name\":\"*\u734e\u52f5\u540d\u7a31\",\"point\":\"*\u734e\u52f5\u5e73\u53f0\u9ede\u6578\",\"percentage\":\"*\u4e2d\u734e\u6a5f\u7387\",\"number\":\"*\u734e\u54c1\u6578\u91cf\"}]","assign":"[{\"agent_id\":\"*\u71df\u904b\u5546\u7cfb\u7d71\u7de8\u865f\",\"percentage\":\"*\u4e2d\u734e\u6bd4\u4f8b\"}]"}'

```

```javascript
const url = new URL("http://localhost/api/event/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "type_id": 1,
    "title": "\u5132\u503c\u6eff\u984d\u9001",
    "content": "\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx",
    "languages": "[{\"language_id\":\"*\u8a9e\u7cfb\u7de8\u865f\",\"title\":\"\u6d3b\u52d5\u6a19\u984c\",\"content\":\"\u6d3b\u52d5\u8aaa\u660e\"},{\"language_id\":2,\"name\":\"\u5132\u503c\u6eff\u984d\u9001\",\"content\":\"\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx\"}]",
    "details": "[{\"action_id\":\"*\u734e\u52f5\u689d\u4ef6\u985e\u578b\",\"order_type_id\":\"\u652f\u4ed8\u65b9\u5f0f\u7de8\u865f\",\"vendor_id\":\"\u904a\u6232\u5546\u7de8\u865f\",\"point\":\"*\u9700\u5927\u65bc\u7b49\u65bc\u591a\u5c11\u9ede\u6578\",\"start_time\":\"*\u958b\u59cb\u6642\u9593\",\"end_time\":\"*\u7d50\u675f\u6642\u9593\"}]",
    "prize_type_id": 1,
    "prize_name": "cQEXk3kcS8YUrJkK",
    "prize_background": "XI5MPzEikgvLbNet",
    "prize_number": 11,
    "prize_expire_date": "1FDNfATwbG3F2iFR",
    "prizes": "[{\"name\":\"*\u734e\u52f5\u540d\u7a31\",\"point\":\"*\u734e\u52f5\u5e73\u53f0\u9ede\u6578\",\"percentage\":\"*\u4e2d\u734e\u6a5f\u7387\",\"number\":\"*\u734e\u54c1\u6578\u91cf\"}]",
    "assign": "[{\"agent_id\":\"*\u71df\u904b\u5546\u7cfb\u7d71\u7de8\u865f\",\"percentage\":\"*\u4e2d\u734e\u6bd4\u4f8b\"}]"
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
`POST api/event/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    type_id | integer |  optional  | 類型(1:任務,2:活動)
    title | string |  optional  | 活動標題
    content | string |  optional  | 預設內容
    languages | array |  optional  | option 多語活動標題/內容
    details | array |  required  | 獎勵內容
    prize_type_id | integer |  optional  | 獎勵類型
    prize_name | string |  optional  | 獎勵遊戲名稱
    prize_background | string |  optional  | 獎勵遊戲背景圖(base64 file)
    prize_number | integer |  optional  | 獲得遊戲次數
    prize_expire_date | dateTime |  optional  | 遊戲次數到期時間
    prizes | array |  optional  | 獎勵內容
    assign | array |  required  | 指定開獎

<!-- END_e5311539b65da39c90017a70949ea82a -->

<!-- START_e7fe49ec62189acb2b8c567f77871d6d -->
## prizeType/list
-獎勵類型列表

> Example request:

```bash
curl -X POST "http://localhost/api/prizeType/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"status":true}'

```

```javascript
const url = new URL("http://localhost/api/prizeType/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
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
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "name": "預設名稱,ex:Scratch",
            "status": "狀態0停用1正常,ex:1",
            "created_at": "建立時間,ex:2019-03-08 17:55:41",
            "updated_at": "最後編輯時間,ex:2019-03-08 17:55:41",
            "admin": "最後編輯者,ex:1"
        },
        {
            "id": 2,
            "name": "Roulette",
            "status": 1,
            "created_at": "2019-03-08 17:55:41",
            "updated_at": "2019-03-08 17:55:41",
            "admin": 1
        }
    ]
}
```

### HTTP Request
`POST api/prizeType/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    status | boolean |  required  | 狀態

<!-- END_e7fe49ec62189acb2b8c567f77871d6d -->

<!-- START_a625b1f9809f49eab5388ecd34aba285 -->
## prizeType/view
-獎勵類型詳細資料

> Example request:

```bash
curl -X POST "http://localhost/api/prizeType/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/prizeType/view");

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
    "data": [
        {
            "id": "系統編號,ex:1",
            "name": "預設名稱,ex:Scratch",
            "status": "狀態0停用1正常,ex:1",
            "created_at": "建立時間,ex:2019-03-06 16:55:22",
            "updated_at": "最後更新時間,ex:2019-03-08 17:43:28",
            "admin": "最後更新者,ex:1",
            "names": [
                {
                    "language_id": 1,
                    "name": "Scratch"
                },
                {
                    "language_id": 2,
                    "name": "刮刮卡"
                }
            ]
        }
    ]
}
```

### HTTP Request
`POST api/prizeType/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_a625b1f9809f49eab5388ecd34aba285 -->

<!-- START_255e7dbcecdbed1fb22e2437878aba6e -->
## prizeType/add
-新增獎勵類型

> Example request:

```bash
curl -X POST "http://localhost/api/prizeType/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"name":"Scratch","names":"[{\"language_id\":1,\"name\":\"Scratch\"},{\"language_id\":2,\"name\":\"\u522e\u522e\u5361\"}]","status":true}'

```

```javascript
const url = new URL("http://localhost/api/prizeType/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "name": "Scratch",
    "names": "[{\"language_id\":1,\"name\":\"Scratch\"},{\"language_id\":2,\"name\":\"\u522e\u522e\u5361\"}]",
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
    "message": "Success",
    "data": "系統編號"
}
```

### HTTP Request
`POST api/prizeType/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    name | string |  required  | 預設獎勵類型名稱
    names | array |  optional  | option 多語獎勵類型名稱
    status | boolean |  required  | 狀態(0:停用,1:啟用)

<!-- END_255e7dbcecdbed1fb22e2437878aba6e -->

<!-- START_322795746621416762e301bb13f0dbf1 -->
## prizeType/edit
-編輯獎勵類型

> Example request:

```bash
curl -X POST "http://localhost/api/prizeType/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"name":"Scratch","names":"[{\"language_id\":1,\"name\":\"Scratch\"},{\"language_id\":2,\"name\":\"\u522e\u522e\u5361\"}]","status":true}'

```

```javascript
const url = new URL("http://localhost/api/prizeType/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "name": "Scratch",
    "names": "[{\"language_id\":1,\"name\":\"Scratch\"},{\"language_id\":2,\"name\":\"\u522e\u522e\u5361\"}]",
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
`POST api/prizeType/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    name | string |  optional  | 預設獎勵類型名稱
    names | array |  optional  | 多語獎勵類型名稱
    status | boolean |  optional  | 狀態(0:停用,1:啟用)

<!-- END_322795746621416762e301bb13f0dbf1 -->

<!-- START_d4f6a34bc0552fb22e0ae40936fced3e -->
## param/list
-參數列表

> Example request:

```bash
curl -X POST "http://localhost/api/param/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"key":"event_type"}'

```

```javascript
const url = new URL("http://localhost/api/param/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "key": "event_type"
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
            "parameter": "代碼,ex:mission",
            "value": "值,ex:1"
        },
        {
            "parameter": "activity",
            "value": "2"
        }
    ]
}
```

### HTTP Request
`POST api/param/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    key | string |  required  | 鍵值

<!-- END_d4f6a34bc0552fb22e0ae40936fced3e -->


