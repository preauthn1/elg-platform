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
    username | string |  required  | 會員帳號
    password | string |  required  | 會員密碼

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

<!-- START_0816bb2ed6c760dfe9f246da4844a9e9 -->
## user/list
-管理者列表

> Example request:

```bash
curl -X POST "http://localhost/api/user/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"status":true,"page":"1","per_page":"30"}'

```

```javascript
const url = new URL("http://localhost/api/user/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
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
            "parent": "上層系統編號(0的話是第一層),ex:0",
            "username": "管理員帳號,ex:Admin",
            "nickname": "管理員暱稱,ex:XXX",
            "email": "管理員email,ex:user@example.invalid",
            "mobile": "手機號碼,ex:0912345678",
            "timezone_id": "時區系統編號,ex:1",
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
    status | boolean |  optional  | option 管理員狀態
    page | numeric |  required  | 第幾頁
    per_page | numeric |  required  | 每頁筆數

<!-- END_0816bb2ed6c760dfe9f246da4844a9e9 -->

<!-- START_738381316e92326985aea7068c8c5b09 -->
## user/view
-管理者詳細資料

> Example request:

```bash
curl -X POST "http://localhost/api/user/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1"}'

```

```javascript
const url = new URL("http://localhost/api/user/view");

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
    "message": "Success",
    "data": {
        "parent": "上層系統編號(0的話是第一層),ex:0",
        "username": "管理員帳號,ex:Admin",
        "nickname": "管理員暱稱,ex:XXX",
        "email": "管理員email,ex:user@example.invalid",
        "mobile": "手機號碼,ex:0912345678",
        "timezone_id": "時區系統編號,ex:1",
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
-新增管理者

> Example request:

```bash
curl -X POST "http://localhost/api/user/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"parent":"1","username":"xxx","nickname":"xxx","email":"user@example.invalid","password":"sTHGxMHYtXhzFnVk","password_confirmation":"Rg3b7umj4Lnlnld9","mobile":"+886912345678","timezone_id":"1","status":true}'

```

```javascript
const url = new URL("http://localhost/api/user/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "parent": "1",
    "username": "xxx",
    "nickname": "xxx",
    "email": "user@example.invalid",
    "password": "sTHGxMHYtXhzFnVk",
    "password_confirmation": "Rg3b7umj4Lnlnld9",
    "mobile": "+886912345678",
    "timezone_id": "1",
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
    "data": "管理者編號,ex:1"
}
```

### HTTP Request
`POST api/user/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    parent | numeric |  optional  | 上層的系統編號(沒選擇就是建立自己的下線)
    username | string |  required  | 帳號(只可輸入英數，不可重覆，字數長度為4-10)
    nickname | string |  optional  | 暱稱
    email | email |  required  | 電子信箱
    password | string |  required  | 管理員密碼(字數長度為4-15)
    password_confirmation | string |  required  | 確認密碼
    mobile | string |  optional  | 聯絡電話
    timezone_id | numeric |  optional  | 時區系統編號
    status | boolean |  optional  | 狀態-0凍結1正常

<!-- END_10b7085b525a35c366ef92c43c14dae0 -->

<!-- START_72b90abec8b2584f33ab57648a653280 -->
## user/edit
-編輯管理者

> Example request:

```bash
curl -X POST "http://localhost/api/user/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1","parent":"1","nickname":"xxx","email":"user@example.invalid","password":"ZC6HCjqfwXh40FAw","password_confirmation":"vA7RoobQaxYfCqCS","mobile":"0912345678","timezone_id":"1","status":true}'

```

```javascript
const url = new URL("http://localhost/api/user/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": "1",
    "parent": "1",
    "nickname": "xxx",
    "email": "user@example.invalid",
    "password": "ZC6HCjqfwXh40FAw",
    "password_confirmation": "vA7RoobQaxYfCqCS",
    "mobile": "0912345678",
    "timezone_id": "1",
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
    id | string |  required  | 管理員系統編號
    parent | numeric |  optional  | 上層的系統編號
    nickname | string |  optional  | 暱稱
    email | email |  optional  | 電子信箱
    password | string |  optional  | 管理員密碼(字數長度為4-15)
    password_confirmation | string |  optional  | 如需修改密碼，需傳確認密碼
    mobile | string |  optional  | 聯絡電話
    timezone_id | numeric |  optional  | 時區系統編號
    status | boolean |  optional  | 狀態-0凍結1正常

<!-- END_72b90abec8b2584f33ab57648a653280 -->

<!-- START_258d1d8ce18c41d12abd5aafc12ff68a -->
## user/delete
-凍結管理者

> Example request:

```bash
curl -X POST "http://localhost/api/user/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1"}'

```

```javascript
const url = new URL("http://localhost/api/user/delete");

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
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"password":"aaa123456","new_password":"abc123456","new_password_confirmation":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/user/changePasswordBySelf");

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
-修改其他管理者密碼

> Example request:

```bash
curl -X POST "http://localhost/api/user/changePassword" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"new_password":"abc123456","new_password_confirmation":"abc123456"}'

```

```javascript
const url = new URL("http://localhost/api/user/changePassword");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
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
-管理者選單列表(只可查看自己及自己的下線)

> Example request:

```bash
curl -X POST "http://localhost/api/userMenu/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"user_id":1}'

```

```javascript
const url = new URL("http://localhost/api/userMenu/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "user_id": 1
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
            "parent": 0,
            "code": "user",
            "name": "帳號管理",
            "url": "user",
            "symbol": "user",
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
`POST api/userMenu/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    user_id | integer |  required  | 管理者系統編號

<!-- END_5349e8dd6baba9d4d2d4636577b5b8cf -->

<!-- START_721ed1ed15bf66f755aecb7ce52fcc5c -->
## userPermission/setting
-設定管理者權限

> Example request:

```bash
curl -X POST "http://localhost/api/userPermission/setting" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"user_id":1,"menus":"{\"1\":\"view,update,delete\",\"2\":\"view\",\"3\":\"view\"}"}'

```

```javascript
const url = new URL("http://localhost/api/userPermission/setting");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "user_id": 1,
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
`POST api/userPermission/setting`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    user_id | integer |  required  | 管理者系統編號
    menus | array |  required  | 選單{"選單編號":"操作功能"}

<!-- END_721ed1ed15bf66f755aecb7ce52fcc5c -->

<!-- START_6ddbe43d68bb3493cf15bed23c548a12 -->
## agent/list
-營運商列表

> Example request:

```bash
curl -X POST "http://localhost/api/agent/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"parent":0,"status":true,"page":1,"per_page":30}'

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
    "parent": 0,
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
    "message": "Success",
    "data": [
        {
            "id": "營運商系統編號,ex:1",
            "parent": "上層系統編號(0的話是第一層),ex:0",
            "username": "營運商帳號,ex:dmin",
            "nickname": "營運商暱稱,ex:XXX",
            "email": "營運商email,ex:user@example.invalid",
            "mobile": "手機號碼,ex:0912345678",
            "percentage": "分成百分比,ex:5",
            "timezone_id": "時區系統編號,ex:1",
            "status": "狀態-0凍結1正常,ex:1"
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
    parent | integer |  optional  | 上層編號
    status | boolean |  optional  | 營運商狀態
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_6ddbe43d68bb3493cf15bed23c548a12 -->

<!-- START_55fc43eee7726946f1e50a3eb9da4c59 -->
## agent/view
-營運商詳細資料

> Example request:

```bash
curl -X POST "http://localhost/api/agent/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

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
        "parent": "上層系統編號(0的話是第一層),ex:0",
        "username": "營運商帳號,ex:Admin",
        "nickname": "營運商暱稱,ex:XXX",
        "email": "營運商email,ex:user@example.invalid",
        "mobile": "手機號碼,ex:0912345678",
        "percentage": "分成百分比,ex:5",
        "timezone_id": "時區系統編號,ex:1",
        "country_id": "國家系統編號,ex:1",
        "zip_code": "郵遞區號,ex:853",
        "district": "地區,ex:中正區",
        "city": "城市,ex:台北市",
        "address": "地址,ex:忠孝西路1號1樓",
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
    id | integer |  required  | 營運商系統編號

<!-- END_55fc43eee7726946f1e50a3eb9da4c59 -->

<!-- START_6952d481f3fd2bbfb8b13ea2bbbf117d -->
## agent/add
-新增營運商

> Example request:

```bash
curl -X POST "http://localhost/api/agent/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"username":"agent_username","nickname":"XXX","email":"user@example.invalid","password":"GJ0hogTYmlAZ7JXO","password_confirmation":"GdIgoyJcuRjYpiXo","mobile":"0912345678","percentage":5,"timezone_id":1,"country_id":0,"zip_code":100,"district":"\u4e2d\u6b63\u5340","city":"\u53f0\u5317\u5e02","address":"\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13","frontend_register":true,"status":true}'

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
    "nickname": "XXX",
    "email": "user@example.invalid",
    "password": "GJ0hogTYmlAZ7JXO",
    "password_confirmation": "GdIgoyJcuRjYpiXo",
    "mobile": "0912345678",
    "percentage": 5,
    "timezone_id": 1,
    "country_id": 0,
    "zip_code": 100,
    "district": "\u4e2d\u6b63\u5340",
    "city": "\u53f0\u5317\u5e02",
    "address": "\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13",
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
    "message": "Success",
    "data": "營運商編號,ex:1"
}
```

### HTTP Request
`POST api/agent/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    username | unique |  required  | 帳號
    nickname | string |  optional  | 暱稱
    email | email |  required  | 電子信箱
    password | string |  required  | 營運商密碼
    password_confirmation | string |  required  | 營運商確認密碼
    mobile | string |  optional  | 聯絡電話
    percentage | integer |  optional  | 分成百分比
    timezone_id | integer |  optional  | 時區系統編號
    country_id | integer |  optional  | 國家
    zip_code | integer |  optional  | 郵遞區號
    district | string |  optional  | 區域
    city | string |  optional  | 城市
    address | string |  optional  | 地址
    frontend_register | boolean |  optional  | 前端是否顯示會員註冊0:不開放1:開放
    status | boolean |  optional  | 狀態-0凍結1正常

<!-- END_6952d481f3fd2bbfb8b13ea2bbbf117d -->

<!-- START_3025d81cf425c2c8875e2a93477caca1 -->
## agent/edit
-編輯營運商

> Example request:

```bash
curl -X POST "http://localhost/api/agent/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1","nickname":"XXX","email":"user@example.invalid","password":"rgt5gax0ikTqVDMG","password_confirmation":"jD3OLWKFp0WtXbDj","mobile":"0912345678","percentage":5,"timezone_id":"1","country_id":"\u53f0\u7063","zip_code":"100","district":"\u4e2d\u6b63\u5340","city":"\u53f0\u5317\u5e02","address":"\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13","frontend_register":true,"status":true}'

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
    "nickname": "XXX",
    "email": "user@example.invalid",
    "password": "rgt5gax0ikTqVDMG",
    "password_confirmation": "jD3OLWKFp0WtXbDj",
    "mobile": "0912345678",
    "percentage": 5,
    "timezone_id": "1",
    "country_id": "\u53f0\u7063",
    "zip_code": "100",
    "district": "\u4e2d\u6b63\u5340",
    "city": "\u53f0\u5317\u5e02",
    "address": "\u5fe0\u5b5d\u897f\u8def1\u865f1\u6a13",
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
    "message": "Success"
}
```

### HTTP Request
`POST api/agent/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | string |  required  | 營運商系統編號
    nickname | string |  optional  | 暱稱
    email | email |  optional  | 電子信箱
    password | string |  required  | 營運商密碼
    password_confirmation | string |  required  | 如需修改密碼，需傳營運商確認密碼
    mobile | string |  optional  | 聯絡電話
    percentage | integer |  optional  | 分成百分比
    timezone_id | string |  optional  | 時區系統編號
    country_id | string |  optional  | 國家
    zip_code | string |  optional  | 郵遞區號
    district | string |  optional  | 區域
    city | string |  optional  | 城市
    address | string |  optional  | 地址
    frontend_register | boolean |  optional  | 前端是否顯示會員註冊0:不開放1:開放
    status | boolean |  optional  | 狀態-0凍結1正常

<!-- END_3025d81cf425c2c8875e2a93477caca1 -->

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
-營運商選單列表(沒有傳送agent_id則傳送所有的選單)

> Example request:

```bash
curl -X POST "http://localhost/api/agentMenu/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1}'

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
    agent_id | integer |  optional  | 營運商系統編號

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

<!-- START_dbd03c93f80e7ecdc1ff438e6de2741a -->
## template/list
-模版列表

> Example request:

```bash
curl -X POST "http://localhost/api/template/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"name":"index","status":true,"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/template/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "name": "index",
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
`POST api/template/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    name | string |  optional  | 模版名稱
    status | boolean |  optional  | 狀態
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_dbd03c93f80e7ecdc1ff438e6de2741a -->

<!-- START_bb2d779fe7c6ca0db2fe08e58558914e -->
## template/view
-模版明細

> Example request:

```bash
curl -X POST "http://localhost/api/template/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/template/view");

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
        "name": "模版名稱,ex:pink",
        "thumbnail": "模版縮圖,ex:http:\/\/xxx.xxx.xxx\/xxx.jpg",
        "status": "狀態,ex:1",
        "created_at": "建立時間,ex:2019-01-01 00:00:00",
        "updated_at": "更新時間,ex:2019-12-31 23:59:59",
        "admin": 1
    }
}
```

### HTTP Request
`POST api/template/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 模版編號

<!-- END_bb2d779fe7c6ca0db2fe08e58558914e -->

<!-- START_5a1a392d633cf084de779413e91e0b5c -->
## template/add
-新增模版

> Example request:

```bash
curl -X POST "http://localhost/api/template/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"name":"template2","thumbnail":"mTbMqTsgvVmWFdvq","status":true}'

```

```javascript
const url = new URL("http://localhost/api/template/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "name": "template2",
    "thumbnail": "mTbMqTsgvVmWFdvq",
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
    "data": "系統編號,ex:1"
}
```

### HTTP Request
`POST api/template/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    name | string |  required  | 類型名稱
    thumbnail | string |  required  | base64 file 模版縮圖
    status | boolean |  optional  | 狀態:0停用,1正常(預設0)

<!-- END_5a1a392d633cf084de779413e91e0b5c -->

<!-- START_ec1a60719f48387b62d35d41c31aae1c -->
## template/edit
-修改模版

> Example request:

```bash
curl -X POST "http://localhost/api/template/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"name":"template2","thumbnail":"txwb1iSl4jfY17he","status":true}'

```

```javascript
const url = new URL("http://localhost/api/template/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "name": "template2",
    "thumbnail": "txwb1iSl4jfY17he",
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
`POST api/template/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    name | string |  optional  | 類型名稱
    thumbnail | string |  optional  | base64 file 模版縮圖
    status | boolean |  optional  | 狀態:0停用,1正常

<!-- END_ec1a60719f48387b62d35d41c31aae1c -->

<!-- START_88343b873274c0a7d06555194fc1b680 -->
## template/delete
-停用模版

> Example request:

```bash
curl -X POST "http://localhost/api/template/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/template/delete");

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
`POST api/template/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_88343b873274c0a7d06555194fc1b680 -->

<!-- START_9e3a4a0a150bf82378997d94fa02d104 -->
## agentTemplate/list
-營運商模版列表

> Example request:

```bash
curl -X POST "http://localhost/api/agentTemplate/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/agentTemplate/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "agent_id": 1,
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
            "id": "模版編號,ex:1",
            "template_name": "模版名稱,ex:pink",
            "template_thumbnail": "模版縮圖,ex:http:\/\/xxxx.xxx.xxx\/xxx.jpg",
            "status": "啟用狀態(0:停用,1:啟用),ex:1",
            "created_at": "建立時間,ex:2019-01-01 00:00:00",
            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
            "admin": 1
        },
        {
            "id": 2,
            "template_name": "black",
            "template_thumbnail": "http:\/\/xxxx.xxx.xxx\/xxx.jpg",
            "status": 0,
            "created_at": "2019-01-01 00:00:00",
            "updated_at": "2019-12-31 23:59:59",
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
`POST api/agentTemplate/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  required  | 營運商編號
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_9e3a4a0a150bf82378997d94fa02d104 -->

<!-- START_d7033d280cfbaf7e37199769a89bbd1a -->
## agentTemplate/setting
-設定營運商可用模版

> Example request:

```bash
curl -X POST "http://localhost/api/agentTemplate/setting" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"template_id":1,"status":true}'

```

```javascript
const url = new URL("http://localhost/api/agentTemplate/setting");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "agent_id": 1,
    "template_id": 1,
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
`POST api/agentTemplate/setting`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  required  | 營運商編號
    template_id | integer |  required  | 模版編號
    status | boolean |  optional  | 狀態0:停用,1:啟用 

<!-- END_d7033d280cfbaf7e37199769a89bbd1a -->

<!-- START_3074c5e80ecd0546c4d313c9a1b64894 -->
## agentDomain/list
-域名列表

> Example request:

```bash
curl -X POST "http://localhost/api/agentDomain/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"domain":"agent1","template_id":2,"status":true,"page":1,"per_page":30}'

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
    "template_id": 2,
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
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "agent_id": "營運商系統編號,ex:1",
            "domain": "域名,ex:agent1",
            "template_id": "模版系統編號,ex:1",
            "status": "狀態,ex:1",
            "created_at": "建立時間,ex:2019-01-01 00:00:00",
            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
            "admin": 1
        },
        {
            "id": "系統編號,ex:2",
            "agent_id": "營運商系統編號,ex:1",
            "domain": "域名,ex:agent2",
            "template_id": "模版系統編號,ex:2",
            "status": "狀態,ex:0",
            "created_at": "建立時間,ex:2019-01-01 00:00:00",
            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
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
`POST api/agentDomain/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  optional  | 營運商名稱
    domain | string |  optional  | 域名
    template_id | integer |  optional  | 模版名稱
    status | boolean |  optional  | 狀態
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_3074c5e80ecd0546c4d313c9a1b64894 -->

<!-- START_4f1408c5ec112a81cea401c4adfbc01c -->
## agentDomain/add
-新增域名

> Example request:

```bash
curl -X POST "http://localhost/api/agentDomain/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"domain":"agent1","status":true}'

```

```javascript
const url = new URL("http://localhost/api/agentDomain/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "agent_id": 1,
    "domain": "agent1",
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
    "data": "系統編號,ex:1"
}
```

### HTTP Request
`POST api/agentDomain/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  required  | 營運商系統編號
    domain | string |  required  | 域名
    status | boolean |  optional  | 狀態:0停用,1正常(預設為0)

<!-- END_4f1408c5ec112a81cea401c4adfbc01c -->

<!-- START_6de46bc4e8ea80039d3357e064a6178a -->
## agentDomain/edit
-編輯域名

> Example request:

```bash
curl -X POST "http://localhost/api/agentDomain/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"agent_id":1,"domain":"agent1","status":true}'

```

```javascript
const url = new URL("http://localhost/api/agentDomain/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "agent_id": 1,
    "domain": "agent1",
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
`POST api/agentDomain/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    agent_id | integer |  optional  | 營運商系統編號
    domain | string |  optional  | 域名
    status | boolean |  optional  | 狀態:0停用,1正常

<!-- END_6de46bc4e8ea80039d3357e064a6178a -->

<!-- START_2d32bb77d9239170755a8b047c2646a5 -->
## agentDomain/delete
-停用域名

> Example request:

```bash
curl -X POST "http://localhost/api/agentDomain/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":"1"}'

```

```javascript
const url = new URL("http://localhost/api/agentDomain/delete");

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
    "message": "Success"
}
```

### HTTP Request
`POST api/agentDomain/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | string |  required  | 系統編號

<!-- END_2d32bb77d9239170755a8b047c2646a5 -->

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
{}
```

### HTTP Request
`POST api/country/list`


<!-- END_bfe944498cb3c57eae0da01c80609d10 -->

<!-- START_649e379ec5e595012dc2dec95ae2cc11 -->
## country/view
-國家詳細資料

> Example request:

```bash
curl -X POST "http://localhost/api/country/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/country/view");

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
`POST api/country/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_649e379ec5e595012dc2dec95ae2cc11 -->

<!-- START_b657502f16d1289701cdef98cb4c389c -->
## country/add
-新增國家

> Example request:

```bash
curl -X POST "http://localhost/api/country/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"code":"TW","name":"\u53f0\u7063","names":"[{\"language_id\":1,\"name\":\"Taiwan\"},{\"language_id\":2,\"name\":\"\u53f0\u7063\"}]","country_code":886,"phone_rule":"^9\\d{8}$","status":true}'

```

```javascript
const url = new URL("http://localhost/api/country/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "code": "TW",
    "name": "\u53f0\u7063",
    "names": "[{\"language_id\":1,\"name\":\"Taiwan\"},{\"language_id\":2,\"name\":\"\u53f0\u7063\"}]",
    "country_code": 886,
    "phone_rule": "^9\\d{8}$",
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
    "data": "系統編號"
}
```

### HTTP Request
`POST api/country/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    code | string |  required  | 國家代碼
    name | string |  required  | 預設值國家名稱
    names | array |  optional  | option 多語國家名稱
    country_code | integer |  required  | 國碼
    phone_rule | string |  required  | 手機驗證正規表示式
    status | boolean |  required  | 狀態(0:停用,1:啟用)

<!-- END_b657502f16d1289701cdef98cb4c389c -->

<!-- START_3aeaadceb0edca850507e226d2e62f28 -->
## country/edit
-編輯國家

> Example request:

```bash
curl -X POST "http://localhost/api/country/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"code":"TW","name":"\u53f0\u7063","names":"[{\"language_id\":1,\"name\":\"Hello\"},{\"language_id\":2,\"name\":\"\u4f60\u597d\"}]","country_code":886,"phone_rule":"^9\\d{8}$","status":true}'

```

```javascript
const url = new URL("http://localhost/api/country/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "code": "TW",
    "name": "\u53f0\u7063",
    "names": "[{\"language_id\":1,\"name\":\"Hello\"},{\"language_id\":2,\"name\":\"\u4f60\u597d\"}]",
    "country_code": 886,
    "phone_rule": "^9\\d{8}$",
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
`POST api/country/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    code | string |  optional  | 國家代碼
    name | string |  optional  | 國家名稱
    names | array |  optional  | 多語國家名稱
    country_code | integer |  optional  | 國碼
    phone_rule | string |  optional  | 手機驗證正規表示式
    status | boolean |  optional  | 狀態(0:停用,1:啟用)

<!-- END_3aeaadceb0edca850507e226d2e62f28 -->

<!-- START_c449d95e190699952d2d0de39e515e1f -->
## country/delete
-停用國家

> Example request:

```bash
curl -X POST "http://localhost/api/country/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/country/delete");

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
`POST api/country/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_c449d95e190699952d2d0de39e515e1f -->

<!-- START_6a1f66369355dbd076826d171fd05d7f -->
## currency/list
-幣種列表

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
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "code": "代碼,ex:TWD",
            "name": "名稱,ex:New Taiwan dollar",
            "rate": "匯率,ex:1.00000",
            "status": "狀態0停用1正常,ex:1",
            "created_at": "2019-03-08 17:55:41",
            "updated_at": "2019-03-08 17:55:41",
            "admin": 1
        },
        {
            "id": 2,
            "code": "THB",
            "name": "Thai baht",
            "rate": "0.90000",
            "status": 1,
            "created_at": "2019-03-08 17:55:41",
            "updated_at": "2019-03-08 17:55:41",
            "admin": 1
        }
    ]
}
```

### HTTP Request
`POST api/currency/list`


<!-- END_6a1f66369355dbd076826d171fd05d7f -->

<!-- START_256fcc40d16d75bbf88328cedf5ed38e -->
## currency/view
-國家詳細資料

> Example request:

```bash
curl -X POST "http://localhost/api/currency/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/currency/view");

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
            "code": "代碼,ex:TWD",
            "name": "預設名稱,New Taiwan dollar",
            "rate": "匯率,ex:1",
            "status": "狀態0停用1正常,ex:1",
            "created_at": "建立時間,ex:2019-03-06 16:55:22",
            "updated_at": "最後更新時間,ex:2019-03-08 17:43:28",
            "admin": "最後更新者,ex:1",
            "names": [
                {
                    "language_id": 1,
                    "name": "New Taiwan dollar"
                },
                {
                    "language_id": 2,
                    "name": "台幣"
                }
            ]
        }
    ]
}
```

### HTTP Request
`POST api/currency/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_256fcc40d16d75bbf88328cedf5ed38e -->

<!-- START_ac05fe8ebc40d3bba0df3f0137fc0de4 -->
## currency/add
-新增幣種

> Example request:

```bash
curl -X POST "http://localhost/api/currency/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"code":"TWD","name":"\u53f0\u5e63","rate":1,"names":"[{\"language_id\":1,\"name\":\"New Taiwan Dollar\"},{\"language_id\":2,\"name\":\"\u53f0\u5e63\"}]","status":true}'

```

```javascript
const url = new URL("http://localhost/api/currency/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "code": "TWD",
    "name": "\u53f0\u5e63",
    "rate": 1,
    "names": "[{\"language_id\":1,\"name\":\"New Taiwan Dollar\"},{\"language_id\":2,\"name\":\"\u53f0\u5e63\"}]",
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
    "data": "系統編號"
}
```

### HTTP Request
`POST api/currency/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    code | string |  required  | 貨幣代碼
    name | string |  required  | 預設貨幣名稱
    rate | float |  required  | 匯率
    names | array |  optional  | option 多語貨幣名稱
    status | boolean |  required  | 狀態(0:停用,1:啟用)

<!-- END_ac05fe8ebc40d3bba0df3f0137fc0de4 -->

<!-- START_22b4d94a77f73a409805efef712a8682 -->
## currency/edit
-編輯幣種

> Example request:

```bash
curl -X POST "http://localhost/api/currency/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"code":"TWD","name":"\u53f0\u5e63","rate":1,"names":"[{\"language_id\":1,\"name\":\"New Taiwan Dollar\"},{\"language_id\":2,\"name\":\"\u53f0\u5e63\"}]","status":true}'

```

```javascript
const url = new URL("http://localhost/api/currency/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "code": "TWD",
    "name": "\u53f0\u5e63",
    "rate": 1,
    "names": "[{\"language_id\":1,\"name\":\"New Taiwan Dollar\"},{\"language_id\":2,\"name\":\"\u53f0\u5e63\"}]",
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
`POST api/currency/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    code | string |  optional  | option 貨幣代碼
    name | string |  optional  | option 貨幣名稱
    rate | float |  optional  | 匯率
    names | array |  optional  | option 多語貨幣名稱
    status | boolean |  optional  | 狀態(0:停用,1:啟用)

<!-- END_22b4d94a77f73a409805efef712a8682 -->

<!-- START_117054af5d9a4ca26e64f703730ebf5c -->
## country/delete
-停用幣種

> Example request:

```bash
curl -X POST "http://localhost/api/currency/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/currency/delete");

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
`POST api/currency/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_117054af5d9a4ca26e64f703730ebf5c -->

<!-- START_e99c2dddd254b2ee8065c26dfdb66b7a -->
## advertisementPath/list
-廣告版位列表

> Example request:

```bash
curl -X POST "http://localhost/api/advertisementPath/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"name":"index","status":true,"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/advertisementPath/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "name": "index",
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
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "name": "位置名稱,ex:index_top",
            "column": "欄位數量,ex:2",
            "width": "圖片寬度,ex:1280",
            "height": "圖片高度,ex:300",
            "status": "狀態,ex:1",
            "created_at": "建立時間,ex:2019-01-01 00:00:00",
            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
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
`POST api/advertisementPath/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    name | string |  optional  | 類型編號
    status | boolean |  optional  | 狀態
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_e99c2dddd254b2ee8065c26dfdb66b7a -->

<!-- START_01a1e07c8d2afb86c3c978077b098df5 -->
## advertisementPath/view
-廣告版位明細

> Example request:

```bash
curl -X POST "http://localhost/api/advertisementPath/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/advertisementPath/view");

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
        "name": "位置名稱,ex:index_top",
        "column": "欄位數量,ex:2",
        "width": "圖片寬度,ex:1280",
        "height": "圖片高度,ex:300",
        "status": "狀態,ex:1",
        "created_at": "建立時間,ex:2019-01-01 00:00:00",
        "updated_at": "更新時間,ex:2019-12-31 23:59:59",
        "admin": 1
    }
}
```

### HTTP Request
`POST api/advertisementPath/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 版位編號

<!-- END_01a1e07c8d2afb86c3c978077b098df5 -->

<!-- START_308cf1fd98be57a38879606416b20a63 -->
## advertisementPath/add
-新增廣告版位

> Example request:

```bash
curl -X POST "http://localhost/api/advertisementPath/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"name":"index_top","column":2,"width":1280,"height":300,"status":true}'

```

```javascript
const url = new URL("http://localhost/api/advertisementPath/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "name": "index_top",
    "column": 2,
    "width": 1280,
    "height": 300,
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
    "data": "系統編號,ex:1"
}
```

### HTTP Request
`POST api/advertisementPath/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    name | string |  required  | 類型名稱
    column | integer |  required  | 欄位數量
    width | integer |  required  | 圖片寬度
    height | integer |  required  | 圖片高度
    status | boolean |  required  | 狀態

<!-- END_308cf1fd98be57a38879606416b20a63 -->

<!-- START_951272312bdedcc074b77db630f289a9 -->
## advertisementPath/edit
-編輯廣告版位

> Example request:

```bash
curl -X POST "http://localhost/api/advertisementPath/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"name":"index_top","column":2,"width":1280,"height":300,"status":true}'

```

```javascript
const url = new URL("http://localhost/api/advertisementPath/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "name": "index_top",
    "column": 2,
    "width": 1280,
    "height": 300,
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
`POST api/advertisementPath/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    name | string |  optional  | 類型名稱
    column | integer |  optional  | 欄位數量
    width | integer |  optional  | 圖片寬度
    height | integer |  optional  | 圖片高度
    status | boolean |  optional  | 類型系統編號

<!-- END_951272312bdedcc074b77db630f289a9 -->

<!-- START_628a40e301d4789f377e834b7323de83 -->
## advertisementPath/delete
-停用廣告版位

> Example request:

```bash
curl -X POST "http://localhost/api/advertisementPath/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/advertisementPath/delete");

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
`POST api/advertisementPath/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_628a40e301d4789f377e834b7323de83 -->

<!-- START_70ff8243ecfae28f699a2f37ae77f2a5 -->
## advertisement/list
-廣告列表

> Example request:

```bash
curl -X POST "http://localhost/api/advertisement/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"path_id":1,"path_name":"index","start_time":"2019-01-01 00:00:00","end_time":"2019-01-31 23:59:59","language_id":1,"country_id":1,"agent_id":1,"status":true}'

```

```javascript
const url = new URL("http://localhost/api/advertisement/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "path_id": 1,
    "path_name": "index",
    "start_time": "2019-01-01 00:00:00",
    "end_time": "2019-01-31 23:59:59",
    "language_id": 1,
    "country_id": 1,
    "agent_id": 1,
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
            "id": "版位系統編號,ex:1",
            "name": "版位名稱,ex:index_top",
            "column": "欄位數量,ex:2",
            "width": "圖片寬度,ex:640",
            "height": "圖片高度,ex:300",
            "status": "狀態0:停用1:啟用,ex:1",
            "images": [
                {
                    "id": "系統編號,ex:1",
                    "language_id": "語系系統編號,ex:1",
                    "country_id": "國家系統編號,ex:0",
                    "agent_id": "營運商系統編號,ex:0",
                    "image": "圖片位置,ex:http:\/\/xxx.xxx.xxx\/xxx.jpg",
                    "url": "連結網址,ex:http:\/\/xxx.xxx.xxx",
                    "game_id": "遊戲編號,ex:1",
                    "start_time": "開始時間,ex:2019-04-01 00:00:00",
                    "end_time": "結束時間,ex:2019-04-30 00:00:00",
                    "sort": "排序,ex:1"
                }
            ]
        }
    ]
}
```

### HTTP Request
`POST api/advertisement/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    path_id | integer |  optional  | 類型編號
    path_name | string |  optional  | 類型名稱
    start_time | date |  required  | 開始日期
    end_time | date |  required  | 結束日期
    language_id | integer |  optional  | 語系編號
    country_id | integer |  optional  | 國家編號
    agent_id | integer |  optional  | 營運商編號
    status | boolean |  optional  | 狀態

<!-- END_70ff8243ecfae28f699a2f37ae77f2a5 -->

<!-- START_c33e87c3180fbe7b046ca445398fa21b -->
## advertisement/view
-廣告明細

> Example request:

```bash
curl -X POST "http://localhost/api/advertisement/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/advertisement/view");

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
        "path_id": "版位系統編號,ex:1",
        "language_id": "語系系統編號,ex:1",
        "country_id": "國家系統編號,ex:0",
        "agent_id": "營運商系統編號,ex:0",
        "image": "圖片位置,ex:http:\/\/xxx.xxx.xxx\/xxx.jpg",
        "url": "連結網址,ex:http:\/\/xxx.xxx.xxx",
        "game_id": "遊戲編號,ex:1",
        "sort": "排序,ex:1",
        "start_time": "開始時間,ex:2019-04-01 00:00:00",
        "end_time": "結束時間,ex:2019-04-30 00:00:00",
        "created_at": "建立時間,ex:2019-01-01 00:00:00",
        "updated_at": "更新時間,ex:2019-04-31 23:59:59",
        "admin": 1
    }
}
```

### HTTP Request
`POST api/advertisement/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 廣告編號

<!-- END_c33e87c3180fbe7b046ca445398fa21b -->

<!-- START_d835049005c393d6f40b70d3cf1b6da7 -->
## advertisement/add
-新增廣告

> Example request:

```bash
curl -X POST "http://localhost/api/advertisement/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"path_id":1,"language_id":1,"country_id":"{\"2\",\"3\",\"4\"}","agent_id":"{\"1\"}","image":"PyAXKZfpva3QJJw4","url":"5BEevbf5ZMpXIHBJ","game_id":7,"game_type_id":2,"sort":7,"start_time":"2019-03-01 00:00:00","end_time":"2019-03-31 23:59:59"}'

```

```javascript
const url = new URL("http://localhost/api/advertisement/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "path_id": 1,
    "language_id": 1,
    "country_id": "{\"2\",\"3\",\"4\"}",
    "agent_id": "{\"1\"}",
    "image": "PyAXKZfpva3QJJw4",
    "url": "5BEevbf5ZMpXIHBJ",
    "game_id": 7,
    "game_type_id": 2,
    "sort": 7,
    "start_time": "2019-03-01 00:00:00",
    "end_time": "2019-03-31 23:59:59"
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
`POST api/advertisement/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    path_id | integer |  required  | 位置系統編號
    language_id | integer |  required  | 語系系統編號
    country_id | string |  optional  | 國家系統編號(json格式)
    agent_id | string |  optional  | 營運商系統編號(json格式)
    image | string |  optional  | base64 file 廣告圖片
    url | string |  optional  | 點下圖片後的連結位置
    game_id | integer |  optional  | 遊戲系統編號
    game_type_id | integer |  optional  | 遊戲類型系統編號
    sort | integer |  optional  | 排序
    start_time | dateTime |  required  | 開始日期
    end_time | dateTime |  required  | 結束日期

<!-- END_d835049005c393d6f40b70d3cf1b6da7 -->

<!-- START_3882c4a4d3d03cbf6d2674de2c19078c -->
## advertisement/edit
-編輯廣告

> Example request:

```bash
curl -X POST "http://localhost/api/advertisement/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"language_id":1,"country_id":"{\"2\",\"3\",\"4\"}","agent_id":"{\"1\"}","image":"igW7RznmtbnKImWF","url":"ZjZi6z68LUywONnu","game_id":15,"game_type_id":14,"sort":18,"start_time":"2019-03-01 00:00:00","end_time":"2019-03-31 23:59:59"}'

```

```javascript
const url = new URL("http://localhost/api/advertisement/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "language_id": 1,
    "country_id": "{\"2\",\"3\",\"4\"}",
    "agent_id": "{\"1\"}",
    "image": "igW7RznmtbnKImWF",
    "url": "ZjZi6z68LUywONnu",
    "game_id": 15,
    "game_type_id": 14,
    "sort": 18,
    "start_time": "2019-03-01 00:00:00",
    "end_time": "2019-03-31 23:59:59"
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
`POST api/advertisement/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    language_id | integer |  optional  | 語系系統編號
    country_id | string |  optional  | 國家系統編號(json格式)
    agent_id | string |  optional  | 營運商系統編號(json格式)
    image | string |  optional  | base64 file 廣告圖片
    url | string |  optional  | 點下圖片後的連結位置
    game_id | integer |  optional  | 遊戲系統編號
    game_type_id | integer |  optional  | 遊戲類型系統編號
    sort | integer |  optional  | 排序
    start_time | dateTime |  optional  | 開始日期
    end_time | dateTime |  optional  | 結束日期

<!-- END_3882c4a4d3d03cbf6d2674de2c19078c -->

<!-- START_cae9419e3ec2a93b156789490953c317 -->
## gameType/list
-遊戲類型列表

> Example request:

```bash
curl -X POST "http://localhost/api/gameType/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"status":true}'

```

```javascript
const url = new URL("http://localhost/api/gameType/list");

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
            "parent": "上層編號,ex:0",
            "name": "預設名稱,ex:Slot",
            "status": "狀態0停用1正常,ex:1",
            "created_at": "建立時間,ex:2019-03-08 17:55:41",
            "updated_at": "最後編輯時間,ex:2019-03-08 17:55:41",
            "admin": "最後編輯者,ex:1"
        },
        {
            "id": 2,
            "parent": 0,
            "name": "Reality",
            "status": 1,
            "created_at": "2019-03-08 17:55:41",
            "updated_at": "2019-03-08 17:55:41",
            "admin": 1
        }
    ]
}
```

### HTTP Request
`POST api/gameType/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    status | boolean |  required  | 狀態

<!-- END_cae9419e3ec2a93b156789490953c317 -->

<!-- START_e67b6ae1ee5e784c5b19732cc2d436d3 -->
## gameType/view
-遊戲類型詳細資料

> Example request:

```bash
curl -X POST "http://localhost/api/gameType/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/gameType/view");

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
            "parent": "上層編號,ex:0",
            "name": "預設名稱,ex:Slot",
            "status": "狀態0停用1正常,ex:1",
            "created_at": "建立時間,ex:2019-03-06 16:55:22",
            "updated_at": "最後更新時間,ex:2019-03-08 17:43:28",
            "admin": "最後更新者,ex:1",
            "names": [
                {
                    "language_id": 1,
                    "name": "Slot"
                },
                {
                    "language_id": 2,
                    "name": "拉霸遊戲"
                }
            ]
        }
    ]
}
```

### HTTP Request
`POST api/gameType/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_e67b6ae1ee5e784c5b19732cc2d436d3 -->

<!-- START_b4be0ccee43d5f8806352135aa0f8089 -->
## gameType/add
-新增遊戲類型

> Example request:

```bash
curl -X POST "http://localhost/api/gameType/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"parent":0,"name":"Slot","names":"[{\"language_id\":1,\"name\":\"Solt\"},{\"language_id\":2,\"name\":\"\u62c9\u9738\u904a\u6232\"}]","status":true}'

```

```javascript
const url = new URL("http://localhost/api/gameType/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "parent": 0,
    "name": "Slot",
    "names": "[{\"language_id\":1,\"name\":\"Solt\"},{\"language_id\":2,\"name\":\"\u62c9\u9738\u904a\u6232\"}]",
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
`POST api/gameType/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    parent | integer |  required  | 上層編號，如果第一層為0
    name | string |  required  | 預設遊戲類型名稱
    names | array |  optional  | option 多語遊戲類型名稱
    status | boolean |  required  | 狀態(0:停用,1:啟用)

<!-- END_b4be0ccee43d5f8806352135aa0f8089 -->

<!-- START_ce2fe3ab7aeda9ffb7838a5295e4582b -->
## gameType/edit
-編輯遊戲類型

> Example request:

```bash
curl -X POST "http://localhost/api/gameType/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"parent":0,"name":"Slot","names":"[{\"language_id\":1,\"name\":\"Solt\"},{\"language_id\":2,\"name\":\"\u62c9\u9738\u904a\u6232\"}]","status":true}'

```

```javascript
const url = new URL("http://localhost/api/gameType/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "parent": 0,
    "name": "Slot",
    "names": "[{\"language_id\":1,\"name\":\"Solt\"},{\"language_id\":2,\"name\":\"\u62c9\u9738\u904a\u6232\"}]",
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
`POST api/gameType/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    parent | integer |  optional  | 上層編號，如果第一層為0
    name | string |  optional  | 預設遊戲類型名稱
    names | array |  optional  | 多語遊戲類型名稱
    status | boolean |  optional  | 狀態(0:停用,1:啟用)

<!-- END_ce2fe3ab7aeda9ffb7838a5295e4582b -->

<!-- START_73ae29200a579417e5de3b351ae26a2b -->
## gameType/delete
-刪除遊戲類型

> Example request:

```bash
curl -X POST "http://localhost/api/gameType/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/gameType/delete");

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
`POST api/gameType/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_73ae29200a579417e5de3b351ae26a2b -->

<!-- START_3a550a652a6a9c4029744e63b5f01fb7 -->
## game/list
-遊戲列表

> Example request:

```bash
curl -X POST "http://localhost/api/game/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":1,"type_id":1,"name":"diamond","is_new":true,"is_hot":false,"status":true,"page":1,"per_page":30}'

```

```javascript
const url = new URL("http://localhost/api/game/list");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": 1,
    "type_id": 1,
    "name": "diamond",
    "is_new": true,
    "is_hot": false,
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
    "message": "Success",
    "data": [
        {
            "id": "系統編號,ex:1",
            "vendor_id": "遊戲商編號,ex:1",
            "type_id": "遊戲類型系統編號,ex:123456",
            "game_type": "遊戲代碼,ex:XX101",
            "name": "預設名稱,ex:Diamond Queen",
            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
            "image": "遊戲圖片,ex:http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "is_new": "是否為新遊戲(0:否,1:是),ex:1",
            "is_hot": "是否為熱門遊戲(0:否,1:是),ex:0",
            "status": "狀態0停用1正常,ex:1",
            "created_at": "建立時間,ex:2019-03-08 17:55:41",
            "updated_at": "最後編輯時間,ex:2019-03-08 17:55:41",
            "admin": "最後編輯者,ex:1"
        },
        {
            "id": 2,
            "vendor_id": 1,
            "type_id": 2,
            "game_type": "XX102",
            "name": "Alibaba",
            "lottery_pool": "0",
            "image": "http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "is_new": 1,
            "is_hot": 0,
            "status": 0,
            "created_at": "2019-03-08 17:55:41",
            "updated_at": "2019-03-08 17:55:41",
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
`POST api/game/list`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | integer |  optional  | 遊戲商編號
    type_id | integer |  optional  | 類型編號
    name | string |  optional  | 遊戲名稱
    is_new | boolean |  optional  | 是否為新遊戲(0:否,1:是)
    is_hot | boolean |  optional  | 是否為熱門遊戲(0:否,1:是)
    status | boolean |  optional  | 狀態
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_3a550a652a6a9c4029744e63b5f01fb7 -->

<!-- START_726260f1ac70630cd2503b32356b7af9 -->
## game/view
-遊戲詳細資料

> Example request:

```bash
curl -X POST "http://localhost/api/game/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/game/view");

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
            "vendor_id": "遊戲商編號,ex:1",
            "type_id": "遊戲類型系統編號,ex:123456",
            "game_type": "遊戲代碼,ex:XX101",
            "name": "預設名稱,ex:Diamond Queen",
            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
            "image": "遊戲圖片,ex:http:\/\/xxx.xxx.xxx\/xxx.jpg",
            "is_new": "是否為新遊戲(0:否,1:是),ex:1",
            "is_hot": "是否為熱門遊戲(0:否,1:是),ex:0",
            "status": "狀態0停用1正常,ex:1",
            "created_at": "建立時間,ex:2019-03-08 17:55:41",
            "updated_at": "最後編輯時間,ex:2019-03-08 17:55:41",
            "admin": "最後編輯者,ex:1",
            "names": [
                {
                    "language_id": 1,
                    "name": "Diamond Queen"
                },
                {
                    "language_id": 2,
                    "name": "鑽石女王"
                }
            ]
        }
    ]
}
```

### HTTP Request
`POST api/game/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_726260f1ac70630cd2503b32356b7af9 -->

<!-- START_7acb3c38ad2af9c8ce713c9484f3d89d -->
## game/add
-新增遊戲

> Example request:

```bash
curl -X POST "http://localhost/api/game/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"vendor_id":1,"type_id":1,"game_type":"1","name":"Slot","names":"[{\"language_id\":1,\"name\":\"Solt\"},{\"language_id\":2,\"name\":\"\u62c9\u9738\u904a\u6232\"}]","image":"3NaBurd06HUlPsUR","is_new":true,"is_hot":false,"status":true}'

```

```javascript
const url = new URL("http://localhost/api/game/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "vendor_id": 1,
    "type_id": 1,
    "game_type": "1",
    "name": "Slot",
    "names": "[{\"language_id\":1,\"name\":\"Solt\"},{\"language_id\":2,\"name\":\"\u62c9\u9738\u904a\u6232\"}]",
    "image": "3NaBurd06HUlPsUR",
    "is_new": true,
    "is_hot": false,
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
`POST api/game/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    vendor_id | integer |  required  | 遊戲商系統編號
    type_id | integer |  required  | 遊戲類型系統編號
    game_type | string |  required  | 遊戲代碼
    name | string |  required  | 預設遊戲類型名稱
    names | array |  optional  | 多語遊戲類型名稱
    image | string |  optional  | base64 file 遊戲圖片
    is_new | boolean |  optional  | 是否為新遊戲(0:否,1:是)
    is_hot | boolean |  optional  | 是否為熱門遊戲(0:否,1:是)
    status | boolean |  required  | 狀態(0:停用,1:正常)

<!-- END_7acb3c38ad2af9c8ce713c9484f3d89d -->

<!-- START_b11750ddd59092ac54e35bc3ce67526f -->
## game/edit
-編輯遊戲

> Example request:

```bash
curl -X POST "http://localhost/api/game/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"vendor_id":1,"type_id":1,"game_type":"1","name":"Slot","names":"[{\"language_id\":1,\"name\":\"Solt\"},{\"language_id\":2,\"name\":\"\u62c9\u9738\u904a\u6232\"}]","image":"iTGlnuZ99eYWHWLd","is_new":true,"is_hot":false,"status":true}'

```

```javascript
const url = new URL("http://localhost/api/game/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "vendor_id": 1,
    "type_id": 1,
    "game_type": "1",
    "name": "Slot",
    "names": "[{\"language_id\":1,\"name\":\"Solt\"},{\"language_id\":2,\"name\":\"\u62c9\u9738\u904a\u6232\"}]",
    "image": "iTGlnuZ99eYWHWLd",
    "is_new": true,
    "is_hot": false,
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
`POST api/game/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    vendor_id | integer |  optional  | option 遊戲商系統編號
    type_id | integer |  optional  | option 遊戲類型系統編號
    game_type | string |  optional  | option 遊戲代碼
    name | string |  optional  | option 預設遊戲類型名稱
    names | array |  optional  | option 多語遊戲類型名稱
    image | string |  optional  | base64 file 遊戲圖片
    is_new | boolean |  optional  | 是否為新遊戲(0:否,1:是)
    is_hot | boolean |  optional  | 是否為熱門遊戲(0:否,1:是)
    status | boolean |  optional  | option 狀態(0:停用,1:正常)

<!-- END_b11750ddd59092ac54e35bc3ce67526f -->

<!-- START_5029fe4769b7bc08c0691e0f09a0bdff -->
## game/delete
-停用遊戲

> Example request:

```bash
curl -X POST "http://localhost/api/game/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/game/delete");

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
`POST api/game/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_5029fe4769b7bc08c0691e0f09a0bdff -->

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
            "type": "類型,ex:1",
            "code": "代碼,ex:45121196152222635"
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
    type | integer |  required  | 類型編號
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
            "name": "預設名稱,ex:最新消息",
            "created_at": "新增時間,ex:2019-04-19 16:25:07",
            "updated_at": "最後更新時間,ex:2019-04-19 16:25:07",
            "admin": "最後更新者系統編號,ex:1"
        },
        {
            "id": 2,
            "name": "優惠消息",
            "created_at": "2019-04-19 16:25:07",
            "updated_at": "2019-04-19 16:25:07",
            "admin": 1
        }
    ]
}
```

### HTTP Request
`POST api/bulletinType/list`


<!-- END_60fde7e9822283540150be3eec91994d -->

<!-- START_5646f40f2c38865251deca8a36016f9e -->
## bulletinType/view
-公告類型詳細資料

> Example request:

```bash
curl -X POST "http://localhost/api/bulletinType/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/bulletinType/view");

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
        "name": "預設名稱,ex:最新消息",
        "status": "狀態0:停用1:正常,ex:1",
        "created_at": "建立時間,ex:2019-03-06 16:55:22",
        "updated_at": "最後更新時間,ex:2019-03-08 17:43:28",
        "admin": "最後更新者,ex:1",
        "names": [
            {
                "language_id": 1,
                "name": "News"
            },
            {
                "language_id": 2,
                "name": "最新消息"
            }
        ]
    }
}
```

### HTTP Request
`POST api/bulletinType/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_5646f40f2c38865251deca8a36016f9e -->

<!-- START_f8f6a43aba1e66b11fc94f6cb99fc358 -->
## bulletinType/add
-新增公告類型

> Example request:

```bash
curl -X POST "http://localhost/api/bulletinType/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"name":"News","names":"[{\"language_id\":1,\"name\":\"News\"},{\"language_id\":2,\"name\":\"\u65b0\u805e\"}]"}'

```

```javascript
const url = new URL("http://localhost/api/bulletinType/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "name": "News",
    "names": "[{\"language_id\":1,\"name\":\"News\"},{\"language_id\":2,\"name\":\"\u65b0\u805e\"}]"
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
`POST api/bulletinType/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    name | string |  required  | 預設公告類型名稱
    names | array |  optional  | option 多語公告類型名稱

<!-- END_f8f6a43aba1e66b11fc94f6cb99fc358 -->

<!-- START_e6e72c7465577c3ae688775764e61bbd -->
## bulletinType/edit
-編輯公告類型

> Example request:

```bash
curl -X POST "http://localhost/api/bulletinType/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"name":"News","names":"[{\"language_id\":1,\"name\":\"News\"},{\"language_id\":2,\"name\":\"\u65b0\u805e\"}]"}'

```

```javascript
const url = new URL("http://localhost/api/bulletinType/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "name": "News",
    "names": "[{\"language_id\":1,\"name\":\"News\"},{\"language_id\":2,\"name\":\"\u65b0\u805e\"}]"
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
`POST api/bulletinType/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    name | string |  required  | 預設公告類型名稱
    names | array |  optional  | option 多語公告類型名稱

<!-- END_e6e72c7465577c3ae688775764e61bbd -->

<!-- START_5005a7d14ffec338fdc783d64e37cf9e -->
## bulletinType/delete
-停用公告類型

> Example request:

```bash
curl -X POST "http://localhost/api/bulletinType/delete" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/bulletinType/delete");

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
`POST api/bulletinType/delete`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_5005a7d14ffec338fdc783d64e37cf9e -->

<!-- START_13442701231570c69bebf2c8e110bdd4 -->
## bulletin/list
-公告列表

> Example request:

```bash
curl -X POST "http://localhost/api/bulletin/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"type_id":1,"language_id":1,"country_id":1,"agent_id":1,"title":"Hello","content":"Hello","page":1,"per_page":30}'

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
    "agent_id": 1,
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
            "agent_id": "代理編號,ex:1",
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
            "agent_id": 0,
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
    agent_id | integer |  optional  | 代理編號
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
        "agent_id": "代理編號,ex:1",
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
    id | integer |  required  | 系統編號

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
    type_id | integer |  optional  | 類型系統編號
    language_id | integer |  optional  | 語系系統編號
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
-訂單列表

> Example request:

```bash
curl -X POST "http://localhost/api/order/list" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"ordery_type_id":1,"currency_id":1,"member_id":1,"amount":1,"status":1,"page":1,"per_page":30}'

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
    "member_id": 1,
    "amount": 1,
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
            "ordery_type_id": "訂單類別系統編號,ex:2",
            "currency_id": "幣種系統編號,ex:1",
            "member_id": "會員系統編號,ex:1",
            "amount": "金額,ex:100",
            "point": "平台點數,ex:100",
            "status": "狀態0建立1成功2失敗,ex:1",
            "created_at": "建立時間,ex:2019-03-06 16:55:22",
            "updated_at": "更新時間,ex:2019-03-08 17:43:28"
        },
        {
            "id": "系統編號,ex:123",
            "ordery_type_id": "訂單類別系統編號,ex:2",
            "currency_id": "幣種系統編號,ex:1",
            "member_id": "會員系統編號,ex:1",
            "amount": "金額,ex:300",
            "point": "平台點數,ex:300",
            "status": "狀態0建立1成功2失敗,ex:0",
            "created_at": "建立時間,ex:2019-03-06 16:55:22",
            "updated_at": "更新時間,ex:2019-03-08 17:43:28"
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
    member_id | integer |  optional  | 會員系統編號
    amount | integer |  optional  | 金額
    status | integer |  optional  | 狀態
    page | integer |  required  | 第幾頁
    per_page | integer |  required  | 每頁筆數

<!-- END_ffe6dabde8e2b3e9cdf0e77acca8f0aa -->

<!-- START_71c63ad6f2fe8a542474daa568f7148e -->
## order/view
-訂單詳細資料

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
`POST api/order/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 訂單系統編號

<!-- END_71c63ad6f2fe8a542474daa568f7148e -->

<!-- START_dea7ab680e6f1060c0fac042b096d3d1 -->
## report/deposit
-儲值報表

> Example request:

```bash
curl -X POST "http://localhost/api/report/deposit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"start_date":"2019-01-01","end_date":"2019-12-31"}'

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
    agent_id | integer |  required  | 營運商系統編號
    start_date | date |  required  | 開始日期
    end_date | date |  required  | 結束日期

<!-- END_dea7ab680e6f1060c0fac042b096d3d1 -->

<!-- START_f10eee5882df4616d7319ba25435ea6b -->
## report/transaction
-轉點報表

> Example request:

```bash
curl -X POST "http://localhost/api/report/transaction" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"agent_id":1,"start_date":"2019-01-01","end_date":"2019-12-31"}'

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
`POST api/report/transaction`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    agent_id | integer |  required  | 營運商系統編號
    start_date | date |  required  | 開始日期
    end_date | date |  required  | 結束日期

<!-- END_f10eee5882df4616d7319ba25435ea6b -->

<!-- START_f4ce5d08eff2e237afb5641d02dbbd9b -->
## report/register
-註冊報表

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
            "prize_number": "取得獎勵數量,ex:1",
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
    -d '{"type_id":1,"title":"\u5132\u503c\u6eff\u984d\u9001","content":"\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx","languages":"[{\"language_id\":\"*\u8a9e\u7cfb\u7de8\u865f\",\"title\":\"\u6d3b\u52d5\u6a19\u984c\",\"content\":\"\u6d3b\u52d5\u8aaa\u660e\"},{\"language_id\":2,\"name\":\"\u5132\u503c\u6eff\u984d\u9001\",\"content\":\"\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx\"}]","details":"[{\"action_id\":\"*\u734e\u52f5\u689d\u4ef6\u985e\u578b\",\"order_type_id\":\"\u652f\u4ed8\u65b9\u5f0f\u7de8\u865f\",\"vendor_id\":\"\u904a\u6232\u5546\u7de8\u865f\",\"point\":\"*\u9700\u5927\u65bc\u7b49\u65bc\u591a\u5c11\u9ede\u6578\",\"start_time\":\"*\u958b\u59cb\u6642\u9593\",\"end_time\":\"*\u7d50\u675f\u6642\u9593\"}]","prize_type_id":1,"prize_name":"D7K36S6L51wptWhZ","prize_background":"0Q675qNRizRU1YJE","prize_number":11,"prize_expire_date":"YxzkTCV1U397F1tU","prizes":"[{\"name\":\"*\u734e\u52f5\u540d\u7a31\",\"point\":\"*\u734e\u52f5\u5e73\u53f0\u9ede\u6578\",\"percentage\":\"*\u4e2d\u734e\u6a5f\u7387\",\"number\":\"*\u734e\u54c1\u6559\u91cf\"}]","assign":"[{\"agent_id\":\"*\u71df\u904b\u5546\u7cfb\u7d71\u7de8\u865f\",\"percentage\":\"*\u4e2d\u734e\u6bd4\u4f8b\"}]"}'

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
    "prize_name": "D7K36S6L51wptWhZ",
    "prize_background": "0Q675qNRizRU1YJE",
    "prize_number": 11,
    "prize_expire_date": "YxzkTCV1U397F1tU",
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
    -d '{"id":1,"type_id":1,"title":"\u5132\u503c\u6eff\u984d\u9001","content":"\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx","languages":"[{\"language_id\":\"*\u8a9e\u7cfb\u7de8\u865f\",\"title\":\"\u6d3b\u52d5\u6a19\u984c\",\"content\":\"\u6d3b\u52d5\u8aaa\u660e\"},{\"language_id\":2,\"name\":\"\u5132\u503c\u6eff\u984d\u9001\",\"content\":\"\u65b0\u5e74\u9650\u5b9a\u6d3b\u52d5xxxxxxx\"}]","details":"[{\"action_id\":\"*\u734e\u52f5\u689d\u4ef6\u985e\u578b\",\"order_type_id\":\"\u652f\u4ed8\u65b9\u5f0f\u7de8\u865f\",\"vendor_id\":\"\u904a\u6232\u5546\u7de8\u865f\",\"point\":\"*\u9700\u5927\u65bc\u7b49\u65bc\u591a\u5c11\u9ede\u6578\",\"start_time\":\"*\u958b\u59cb\u6642\u9593\",\"end_time\":\"*\u7d50\u675f\u6642\u9593\"}]","prize_type_id":1,"prize_name":"j1ONoHA2Y9P0HTMV","prize_background":"iDw5YY8FBy7jReV8","prize_number":17,"prize_expire_date":"em34EZW70V2dRVVA","prizes":"[{\"name\":\"*\u734e\u52f5\u540d\u7a31\",\"point\":\"*\u734e\u52f5\u5e73\u53f0\u9ede\u6578\",\"percentage\":\"*\u4e2d\u734e\u6a5f\u7387\",\"number\":\"*\u734e\u54c1\u6578\u91cf\"}]","assign":"[{\"agent_id\":\"*\u71df\u904b\u5546\u7cfb\u7d71\u7de8\u865f\",\"percentage\":\"*\u4e2d\u734e\u6bd4\u4f8b\"}]"}'

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
    "prize_name": "j1ONoHA2Y9P0HTMV",
    "prize_background": "iDw5YY8FBy7jReV8",
    "prize_number": 17,
    "prize_expire_date": "em34EZW70V2dRVVA",
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

<!-- START_350104b3de8242bc4a250081c559b3dc -->
## language/edit
-編輯語系狀態

> Example request:

```bash
curl -X POST "http://localhost/api/language/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"status":false}'

```

```javascript
const url = new URL("http://localhost/api/language/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
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
`POST api/language/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    status | boolean |  optional  | 語系狀態

<!-- END_350104b3de8242bc4a250081c559b3dc -->

<!-- START_796332bb7cd7c09d24178b0fbdc2e450 -->
## vendor/list
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
{
    "code": 1000,
    "message": "SUCCESS",
    "data": [
        {
            "id": "系統編號,ex:1",
            "name": "名稱,ex:AG",
            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
            "percentage": "拆帳百分比,ex:10",
            "image": "圖片地址,ex:http:\/\/xxxxx.xx.xx\/xx.jpg",
            "status": "狀態(0:停用1:啟用2:開發中),ex:1"
        },
        {
            "id": 2,
            "name": "MG",
            "rate": "1.5",
            "percentage": 5,
            "image": "圖片地址,ex:http:\/\/xxxxx.xx.xx\/xx.jpg",
            "status": 1
        }
    ]
}
```

### HTTP Request
`POST api/vendor/list`


<!-- END_796332bb7cd7c09d24178b0fbdc2e450 -->

<!-- START_0bddf6ef76071fb4684229879742ac53 -->
## vendor/view
-遊戲商列表

> Example request:

```bash
curl -X POST "http://localhost/api/vendor/view" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1}'

```

```javascript
const url = new URL("http://localhost/api/vendor/view");

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
        "name": "名稱,ex:AG",
        "rate": "平台幣跟遊戲幣的轉換率,ex:10",
        "percentage": "拆帳百分比,ex:10",
        "image": "圖片地址,ex:http:\/\/xxxxx.xx.xx\/xx.jpg",
        "status": "狀態(0:停用1:啟用2:開發中),ex:1"
    }
}
```

### HTTP Request
`POST api/vendor/view`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號

<!-- END_0bddf6ef76071fb4684229879742ac53 -->

<!-- START_016aac9bdb733a2f9874e5bbe25f57c4 -->
## vendor/add
-新增遊戲商

> Example request:

```bash
curl -X POST "http://localhost/api/vendor/add" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"name":"AG","rate":"1.5","percentage":"30","image":"kf1Zm5i5mDwY7Jq5","status":1}'

```

```javascript
const url = new URL("http://localhost/api/vendor/add");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "name": "AG",
    "rate": "1.5",
    "percentage": "30",
    "image": "kf1Zm5i5mDwY7Jq5",
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
    "data": "系統編號,ex:1"
}
```

### HTTP Request
`POST api/vendor/add`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    name | string |  required  | 遊戲商名稱
    rate | flaot |  required  | 域名
    percentage | flaot |  required  | 域名
    image | string |  required  | base64 file 廣告圖片
    status | integer |  required  | 狀態(0停用,1正常,2開發中)

<!-- END_016aac9bdb733a2f9874e5bbe25f57c4 -->

<!-- START_155b66d308cb0ef18333f17fa803aad2 -->
## vendor/edit
-編輯域名

> Example request:

```bash
curl -X POST "http://localhost/api/vendor/edit" \
    -H "Authorization: Bearer: {token}" \
    -H "Accept-Language: {locale}" \
    -H "Content-Type: application/json" \
    -d '{"id":1,"name":"AG","rate":"1.5","percentage":"30","image":"wMF2LDBZ4Ud5jee1","status":1}'

```

```javascript
const url = new URL("http://localhost/api/vendor/edit");

let headers = {
    "Authorization": "Bearer: {token}",
    "Accept-Language": "{locale}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

let body = {
    "id": 1,
    "name": "AG",
    "rate": "1.5",
    "percentage": "30",
    "image": "wMF2LDBZ4Ud5jee1",
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
    "message": "Success"
}
```

### HTTP Request
`POST api/vendor/edit`

#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
    id | integer |  required  | 系統編號
    name | string |  required  | 遊戲商名稱
    rate | flaot |  required  | 域名
    percentage | flaot |  required  | 域名
    image | string |  required  | base64 file 廣告圖片
    status | integer |  required  | 狀態(0停用,1正常,2開發中)

<!-- END_155b66d308cb0ef18333f17fa803aad2 -->


