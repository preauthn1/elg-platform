# Gfg2 — 三方 API 規格

> 本檔為廠商 API 純規格；本平台落地分析見 `integration.md`。
>
> 原始文件：`GFG大运转账钱包API对接文档.rtf`（v2.2.8，2023-07-03）

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律使用 `POST`
- **Content-Type**：`application/json`
- **驗證方式**：HTTP Header `Authorization: md5(paramStr + key)`（見[簽名](#簽名)）
- **Body**：param 欄位序列化為 JSON 字串

### 通用回應格式

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 狀態碼；`0` = 成功，非 `0` = 失敗（見[錯誤碼](#錯誤碼)） |
| `timestamp` | Long | 時間戳 |
| `data` | Object / Null | 成功時的回傳資料 |
| `msg` | String | 失敗原因描述 |

### Base URL

| 環境 | URL |
|---|---|
| Production（MYR） | `http://example.invalid/` |
| Staging（MYR） | 同上（以 `companyKey` 區分正式/測試帳號） |

> 正式上線前請向 GFG2 客服確認最新 URL。

### 時區

- 系統採用 **北京時間（UTC+8 / GMT+8）**
- `takeBetLogs` 所有時間欄位均為 UTC+8
- `startTime` / `endTime` 傳入時須使用 UTC+8

### 資料保留

| 項目 | 保留期 |
|---|---|
| API 資料 | 90 天 |
| 前台可查詢 | 30 天 |

### 通用請求欄位

每支 API 都必須包含以下欄位（除特別標注外）：

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agent` | String / Int | ✅ | 代理 ID（`vendor_infos.info.CLIENT_AGENT`） |
| `companyKey` | String | ✅ | 公司標識（格式：`{agentId}_{currency}`，如 `1784194_myr`，存於 `vendor_infos.info.CLIENT_ID`） |
| `timestamp` | Long | ✅ | Unix 毫秒時間戳，如 `1488781836949` |
| `account` | String | 視 API 而定 | 玩家帳號（格式：`{CLIENT_CLASS}_{username}`） |

> **注意**：文件 v2.2.8 仍列 `companyKey` 為必填，但實作中已於 2024-11-01 移除此欄位。詳見 `integration.md` 高風險陷阱。

---

## Merchant API

### 1. 取得遊戲列表（getGameList）

取得代理下所有遊戲及其狀態。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/getGameList` |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agent` | Int | ✅ | 代理 ID |
| `companyKey` | String | ✅ | 公司標識 |
| `timestamp` | Long | ✅ | Unix 毫秒時間戳 |

**成功回應：**

```jsonc
{
  "code": 0,
  "timestamp": "20200225111111",
  "data": {
    "agent": 1001,
    "games": [
      {
        "gameId": 6001,
        "gameName": "百家乐",
        "state": 0,         // 0：关闭，1：开启
        "gameType": "ChessCard"
      }
    ]
  },
  "msg": ""
}
```

**回應欄位：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `data.agent` | Int | 代理 ID |
| `data.games[].gameId` | Int | 遊戲 ID |
| `data.games[].gameName` | String | 遊戲名稱 |
| `data.games[].state` | Int | 0：關閉，1：開啟 |
| `data.games[].gameType` | String | 遊戲類型（Chess/Fish/Slot/ChessCard 等） |

---

### 2. 玩家登入取遊戲連結（login）

玩家登入並取得遊戲 URL，支援登入時直接上分（可選）。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/login` |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account` | String | ✅ | 玩家帳號（最大 38 字元，格式：`{agent}_{username}`） |
| `gameId` | Int | ✅ | 遊戲編號；`0` = 進入大廳 |
| `ip` | String | ✅ | 客戶端 IP |
| `agent` | String | ✅ | 代理 ID |
| `companyKey` | String | ✅ | 公司標識 |
| `platform` | Int | ✅ | 設備類型：PC=0, Wap=1, App=2 |
| `theme` | String | ✅ | 遊戲主題（如 `S007`） |
| `timestamp` | String | ✅ | Unix 毫秒時間戳 |
| `isH5Lobby` | Boolean | ✅ | `true` 時返回 H5 大廳 |
| `token` | String | ✅ | 玩家單次登入憑證，唯一標識，限 128 字元 |
| `orderId` | String | 非必填 | 上分流水號，與 `money` 配合使用（格式：`{代理}{yyyyMMddHHmmssSSS}{account}`） |
| `money` | Float | 非必填 | 登入時上分金額，最多 2 位小數 |
| `languageType` | String | 非必填 | 語系代碼（見[語言代碼](#附錄語言代碼)） |
| `lobbyType` | Int | 非必填 | 大廳類型：0=亮色，1=暗色 |
| `nickName` | String | 非必填 | 更新暱稱，不填則用系統暱稱 |
| `exitUrl` | String | 非必填 | 返回地址 |
| `appUrl` | String | 非必填 | 接收回調的域名地址 |
| `p1` / `p2` | String | 非必填 | 擴展參數 |

**成功回應：**

```jsonc
{
  "code": 0,
  "timestamp": "20200225111111",
  "data": {
    "url": "http://example.invalid/index.html?xxx"
  },
  "msg": ""
}
```

> 當回應 `code=803` 時（實體機台遊戲場景），建議錯誤提示：「请确认是否已退出实体机台游戏，如已退出，则实体机台游戏因物理因素偶有信号延迟，请稍候。若5分钟后仍未改善，请联系客服人员。」

---

### 3. 查詢玩家餘額（queryUserScore）

查詢指定玩家在遊戲商端的餘額。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/queryUserScore` |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account` | String | ✅ | 玩家帳號 |
| `agent` | String | ✅ | 代理 ID |
| `companyKey` | String | ✅ | 公司標識 |
| `timestamp` | String | ✅ | Unix 毫秒時間戳 |

**成功回應：**

```jsonc
{
  "code": 0,
  "timestamp": 20200225111111,
  "data": {
    "money": 100   // decimal(15,2)
  },
  "msg": ""
}
```

---

### 4. 上分（轉帳錢包存款）（doTransferDepositTask）

將代理端餘額轉入玩家的遊戲商帳號。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/doTransferDepositTask` |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account` | String | ✅ | 玩家帳號 |
| `agent` | String | ✅ | 代理 ID |
| `companyKey` | String | ✅ | 公司標識 |
| `orderId` | String | ✅ | 流水號（格式：`{代理}{yyyyMMddHHmmssSSS}{account}`） |
| `money` | Float | ✅ | 上分金額，最多 2 位小數 |
| `timestamp` | String | ✅ | Unix 毫秒時間戳 |

**成功回應：**

```jsonc
{
  "code": 0,
  "timestamp": 20200225111111,
  "data": {
    "money": 100,
    "orderId": "20200225112524556"
  },
  "msg": ""
}
```

**冪等說明：**

| 回應碼 | 含義 | 代理處理 |
|---|---|---|
| `0` | 上分成功 | 正常 |
| `5` | 訂單重複（已成功） | **不需回滾** |
| `1` | 失敗 | **需回滾**本地餘額 |
| `10` | 訂單號格式錯誤 | **需回滾**本地餘額 |

---

### 5. 下分（轉帳錢包提款）（doTransferWithdrawTask）

從玩家的遊戲商帳號提回代理端。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/doTransferWithdrawTask` |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account` | String | ✅ | 玩家帳號 |
| `agent` | String | ✅ | 代理 ID |
| `companyKey` | String | ✅ | 公司標識 |
| `orderId` | String | ✅ | 流水號（格式：`{代理}{yyyyMMddHHmmssSSS}{account}`） |
| `money` | Float | ✅ | 下分金額（正數） |
| `timestamp` | String | ✅ | Unix 毫秒時間戳 |

**成功回應：**

```jsonc
{
  "code": 0,
  "timestamp": 20200225111111,
  "data": {
    "money": 100,
    "orderId": "202002251125496235"
  },
  "msg": ""
}
```

**冪等說明：**

| 回應碼 | 含義 | 代理處理 |
|---|---|---|
| `0` | 下分成功 | 加回本地餘額 |
| `5` | 訂單重複 | 若本地未加分則**補加** |
| `6` | GFG2 餘額不足 | **不加**本地餘額 |
| `10` | 訂單號格式錯誤 | **不加**本地餘額 |

---

### 6. 查詢轉帳訂單狀態（queryTransferOrder）

查詢上下分訂單的最新狀態。用於網路超時等狀態不明時確認實際結果。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/queryTransferOrder` |

> **注意**：文件原文 endpoint 名稱為 `queryTransfeOrder`（少一個 `r`），實作中正確呼叫 `queryTransferOrder`。詳見 `integration.md` 高風險陷阱。

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account` | String | ✅ | 玩家帳號 |
| `agent` | String | ✅ | 代理 ID |
| `companyKey` | String | ✅ | 公司標識 |
| `orderId` | String | ✅ | 流水號 |
| `timestamp` | String | ✅ | Unix 毫秒時間戳 |

**成功回應：**

```jsonc
{
  "code": 0,
  "timestamp": 20200225111111,
  "data": {
    "money": 100,
    "orderId": "20200225112445156"
  },
  "msg": ""
}
```

---

### 7. 拉取注單明細（takeBetLogs）

依時間段批次拉取玩家注單，用於對帳。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/takeBetLogs` |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agent` | String | ✅ | 代理 ID |
| `companyKey` | String | ✅ | 公司標識 |
| `startTime` | String | ✅ | 查詢起始時間（含），格式：`yyyy-MM-dd HH:mm:ss`（UTC+8） |
| `endTime` | String | ✅ | 查詢截止時間（含），格式：`yyyy-MM-dd HH:mm:ss`（UTC+8） |
| `size` | Int | ✅ | 每頁筆數，上限 `100` |
| `page` | Int | ✅ | 頁號，`0` 代表第 1 頁 |
| `timestamp` | String | ✅ | Unix 毫秒時間戳 |

**成功回應：**

```jsonc
{
  "code": 0,
  "timestamp": 20200225111111,
  "data": {
    "total": 45514,
    "bets": [
      {
        "gameId": 1001,
        "account": "QP123456",
        "accountId": "572156124",
        "platform": "App",
        "roundId": "R202002017828825146887463355874",
        "gameResult": 1,        // -1:輸, 0:平, 1:贏
        "fieldId": 1234,
        "filedName": "百家乐初级场",
        "tableId": 1,
        "chair": 1,
        "bet": 100,
        "validBet": 100,
        "win": 95,
        "lose": 0,
        "fee": 5,
        "enterMoney": 1000,
        "createTime": "2020-02-01 09:10:01",
        "roundBeginTime": "2020-02-01 09:10:01",
        "roundEndTime": "2020-02-01 09:10:03",
        "ip": "0.0.0.0"
      }
    ]
  },
  "msg": ""
}
```

**注單欄位說明：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `gameId` | Int | 遊戲 ID |
| `account` | String | 玩家帳號 |
| `accountId` | String | 玩家帳號 ID |
| `platform` | String | 設備：App / PC / WAP |
| `roundId` | String | 局號（唯一） |
| `gameResult` | Int | 輸贏：-1=輸, 0=平, 1=贏 |
| `fieldId` | Int | 場 ID |
| `filedName` | String | 場名稱（文件原文 typo：`filedName` 而非 `fieldName`） |
| `tableId` | Int | 桌子 ID |
| `chair` | Int | 座位號 |
| `bet` | Decimal | 下注金額 |
| `validBet` | Decimal | 有效下注 |
| `win` | Decimal | 派獎金額 |
| `lose` | Decimal | 盈虧金額（= 派獎 - 下注 - 手續費） |
| `fee` | Decimal | 服務費（= MAX(派獎 - 下注, 0) × 手續費率） |
| `enterMoney` | Decimal | 遊戲初始金額 |
| `createTime` | String | 創建時間（UTC+8），格式 `yyyy-MM-dd HH:mm:ss` |
| `roundBeginTime` | String | 遊戲開始時間（UTC+8） |
| `roundEndTime` | String | 遊戲結束時間（UTC+8） |
| `ip` | String | 玩家 IP |

**計算公式：**

```
lose = win - bet - fee

fee = MAX((win - bet), 0) × fee_rate
  （fee_rate 依各遊戲設定，盈虧大於 0 才收取）
```

| 情境 | bet | win | fee | lose |
|---|---|---|---|---|
| 玩家贏錢（2 倍，fee_rate=5%） | 100 | 200 | 5 | 95 |
| 玩家輸錢 | 100 | 0 | 0 | -100 |
| 部分返還（龍虎斗，返 80%，fee_rate=5%） | 100（validBet=20） | 80 | 0 | -20 |

---

### 8. 踢人下線（kickUser）

強制讓玩家下線。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/kickUser` |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account` | String | ✅ | 玩家帳號 |
| `companyKey` | String | ✅ | 公司標識 |

**成功回應：**

```jsonc
{
  "code": 0,
  "timestamp": 20200225111111
}
```

---

## 簽名

所有代理端送出的 API 請求，都必須在 HTTP header 加入 `Authorization` 欄位。

### 演算法

| 項目 | 值 |
|---|---|
| 演算法 | MD5 |
| 輸出格式 | 小寫十六進位字串（lowercase hex） |
| 公式 | `md5(paramStr + key)` |
| paramStr | 請求 Body（整個 param JSON 序列化為字串） |
| key | 後台設定的 `key`（`vendor_infos.info.CLIENT_SECRET`） |

### 計算步驟

**Step 1 — 將請求參數序列化為 JSON 字串：**

```php
$paramStr = json_encode($params);
// 例：{"timestamp":1680608286141,"agent":"1784199","companyKey":"1784194_myr","account":"2989062_abc"}
```

> 注意：JSON 序列化的欄位順序必須與最終 POST Body 一致。

**Step 2 — 計算 MD5：**

```php
$authorization = strtolower(md5($paramStr . $key));
```

**Step 3 — 寫入 HTTP Header：**

```http
Authorization: 52c6a51892ee4665a9bbd11c507c8c4e
Content-Type: application/json
```

### PHP 官方範例

```php
$key = "mykey";
$param = ['a' => 1, 'b' => 2];
$paramStr = json_encode($param);           // {"a":1,"b":2}
$authorization = strtolower(md5($paramStr . $key));

$request->body    = $paramStr;
$request->headers['Authorization'] = $authorization;
```

### 實作對照（`app/Vendors/Gfg2/Driver.php`）

```php
$authToken = [REDACTED] . $this->infoDetail->CLIENT_SECRET);

Http::logAction("Gfg2_{$action}")
    ->withHeaders(['Authorization' => $authToken])
    ->asJson()
    ->post($url, $data);
```

> 實作中未呼叫 `strtolower()`，但 PHP `md5()` 預設即輸出小寫十六進位，與文件一致。

### 簽名相關錯誤碼

| 錯誤碼 | 說明 | 可能原因 |
|---|---|---|
| `4` | 簽名驗證未通過 | `key` 錯誤、或 JSON 序列化字串與 Body 不一致 |
| `701` | 無效簽名 | 同上，另一個錯誤碼區段的相同問題 |
| `13` | 非法的公司 key | `companyKey` 欄位填寫錯誤 |

---

## 錯誤碼

| code | 說明 |
|---|---|
| `0` | 成功 |
| `1` | 失敗（登入時表示帳號不存在） |
| `2` | 訂單正在處理中 |
| `3` | 訂單不存在 |
| `4` | 簽名驗證未通過 |
| `5` | 訂單重複 |
| `6` | 餘額不足，下分失敗 |
| `7` | 請求過於頻繁 |
| `8` | 遊戲中，不能上下分 |
| `9` | 遊戲已關閉 |
| `10` | 非法參數 |
| `11` | 失敗 |
| `12` | 後台驗證失敗 |
| `13` | 非法的公司 key |
| `14` | 呼叫登入注冊介面返回未知錯誤碼 0 |
| `16` | 找不到 provider 或 provider 未初始化（**文件未列，實作 `acceptedErrorCodes()` 已包含**） |
| `17` | 找不到對應的貨幣代碼（**文件未列，實作已包含**） |
| `18` | API 找不到對應資料的通用代碼（**文件未列，實作已包含**） |
| `403` | 禁止訪問 |
| `404` | 找不到伺服器 |
| `503` | 伺服器內部錯誤（如資料庫執行出錯） |
| `504` | 伺服器已關閉 |
| `601` | 玩家資料異常，需重新登入 |
| `602` | 請求太頻繁 |
| `701` | 無效簽名 |
| `702` | 請求參數非法 |
| `703` | 獲取的資料非法 |
| `704` | 非法的公司 ID |
| `705` | 無效的錢包類型 |
| `1001` | 無效的 MAC 地址 |
| `1002` | 無效的第三方參數 |
| `1003` | IP 地址被封禁 |
| `1004` | MAC 地址被封禁 |
| `1005` | 帳戶已被停用，請聯絡客服 |
| `1006` | 玩家不存在（uid 或帳號不存在） |
| `1007` | 密碼錯誤 |
| `1009` | 無效的登入類型 |
| `1010` | 無效的手機資訊 |
| `1011` | 注冊失敗，帳號已存在 |
| `1012` | 注冊失敗，參數錯誤 |
| `1013` | 注冊密碼不符合規則 |
| `1014` | 非法的帳號（不符合帳號規則） |
| `1015` | 帳號重複登入 |
| `1016` | 不在白名單內，禁止登入 |
| `1017` | 驗證失敗 |
| `1018` | token 失效 |
| `1019` | 公司 ID 錯誤 |
| `1020` | 平台生成帳號失敗 |
| `1021` | 帳號為空 |
| `1022` | 驗證碼為空 |
| `1023` | 因多次輸入密碼錯誤，帳戶已被限制若干分鐘不能登入 |
| `1024` | 帳號或密碼錯誤 |
| `1025` | 登入異常，請聯絡線上客服 |
| `1026` | 用戶名格式不正確 |
| `1027` | 用戶名連續出現四個及以上的相同字母或數字 |
| `1029` | 當前區域被限制登入 |
| `10001` | game id 不存在（**文件未列，實作 `acceptedErrorCodes()` 已包含**） |

> 錯誤碼 `16`、`17`、`18`、`10001` 為實作中已知但文件未載入的代碼，可能為 GFG2 後台新增或平台自定義，待 GFG2 客服確認。

---

## 附錄：語言代碼

| `languageType` | 語言 |
|---|---|
| `zh_cn` | 簡體中文 |
| `zh_tw` | 繁體中文 |
| `ko_kr` | 韓文 |
| `en_us` | 英語 |
| `th_th` | 泰語 |
| `vi_vn` | 越南語 |
| `en_in` | 印度語 |
| `pt_pt` | 葡萄牙語 |
| `es_es` | 西班牙語 |
| `ja_jp` | 日文 |

**實作 `Driver.php mapLanguageCode()` 映射：**

| `LanguageEnum` | 映射值 |
|---|---|
| `ZH_TW` | `zh_tw` |
| `ZH_CN` | `zh_cn` |
| `VI_VN` | `vi_vn` |
| `TH_TH` | `th_th` |
| `TA`（Tamil） | `en_in` |
| 其他 | `en_us`（預設） |
