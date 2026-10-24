# WmliveSW（WM 完美真人）— 廠商 API 規格

> 原始來源：<https://example.invalid/wallet_index.html>（WM 完美真人 單一錢包 API v1.4）
> 抓取日期：2026-06-03
>
> 我方實作分析、整合陷阱、BetRecord 狀態機、冪等性設計與落地對應見同目錄 `integration.md`。

WM 完美真人（WM Live）為線上真人荷官遊戲商，提供**單一錢包（Single Wallet）**模式接入。兩個方向：WM 透過 Callback 主動呼叫代理端 API（`CallBalance`、`PointInout`、`TimeoutBetReturn`、`SendMemberReport`），代理亦可主動查詢 WM 端（`GetDateTimeReport`、`GetUnsettleReport` 等）。vendor_id = 126。

---

## 通用約定

### 請求格式（代理 → WM）

- **HTTP Method**：`POST`
- **Content-Type**：`application/x-www-form-urlencoded`（`asForm`）
- **Base URL**：由 `vendor_infos.info.API_URL` 設定（正式 URL 由 WM 客服提供）

### 通用請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | 功能名稱，如 `LoginGame`、`MemberRegister` |
| `vendorId` | String | ✅ | 商戶 ID（對應 `vendor_infos.info.VENDOR_ID`） |
| `signature` | String | ✅ | 商戶碼（對應 `vendor_infos.info.SIGNATURE`），用於身份識別，非加密簽章 |
| `timestamp` | Integer | 多數必填 | Unix 時間戳，須在 WM 伺服器時間 ±30 秒內，超時回 `10202` |
| `syslang` | Integer | 否 | 系統語言：`0`=中文，`1`=英文 |

### 通用回應欄位（WM → 代理）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `errorCode` | Integer | `0`=成功；非零=失敗 |
| `errorMessage` | String | 錯誤說明 |
| `result` | String/Object/Array | 各功能專屬回傳資料 |

### 身份驗證（代理端接收 WM 回調時）

WM 呼叫代理端 Callback 時傳入：
- `signature`：商戶碼（不加密），代理端比對 `vendor_infos.info.SIGNATURE` 識別商戶
- **IP 白名單**：由代理端呼叫 `Common::verifyIPWhitelistByVendorId()` 驗證來源 IP

> WM 無 3DES/HMAC 等密碼學簽章機制，安全靠 signature + IP 白名單雙重確認。

---

## WM 呼叫代理的回調 API（Single Wallet）

Callback URL：`POST /wmlive`（定義於 `routes/external/sw.php`）

WM 主動 POST 至代理端的統一入口，依 `cmd` 欄位分派：

| `cmd` | 用途 |
|---|---|
| `CallBalance` | 查詢玩家餘額 |
| `PointInout` | 加扣點（下注/派彩/重對） |
| `TimeoutBetReturn` | 逾時回滾交易 |
| `SendMemberReport` | 傳送結算報表（需三方開通） |

### 通用約定（回調）

#### 請求格式（WM → 代理）

```http
POST /wmlive
Content-Type: application/x-www-form-urlencoded
```

所有欄位以 form 格式傳入（非 JSON body）。

#### 安全驗證

| 機制 | 說明 |
|---|---|
| IP 白名單 | `Common::verifyIPWhitelistByVendorId(VendorIdEnum::WMLIVESW)` |
| `signature` 識別商戶 | 比對 `vendor_infos.info.SIGNATURE` 找到對應 vendorInfo |

#### 回應格式（代理 → WM）

```json
// 成功
{
  "errorCode": 0,
  "errorMessage": "",
  "result": {}
}

// 失敗
{
  "errorCode": 400,
  "errorMessage": "system error",
  "result": ""
}
```

> 回應 HTTP status 一律 200；WM 端以 `errorCode` 判斷成功與否。

---

### CallBalance（查詢玩家餘額）

WM 在玩家進入遊戲或開始新局前，主動向代理查詢玩家餘額。

**Request（WM → 代理）：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `cmd` | String | `"CallBalance"` |
| `signature` | String | 商戶碼（用於識別 vendorInfo） |
| `user` | String | 玩家帳號 |
| `requestDate` | String | 請求時間（格式 `Y-m-d H:i:s`） |

**Request 範例：**

```
cmd=CallBalance&signature=XXXX&user=wm_player123&requestDate=2026-06-03+10:30:00
```

**成功回應（代理 → WM）：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `errorCode` | Integer | `0` |
| `errorMessage` | String | `""` |
| `result.user` | String | 玩家帳號 |
| `result.money` | String | 玩家當前餘額（已套用 `rate` 換算） |
| `result.responseDate` | String | 回應時間（`Y-m-d H:i:s`） |

```json
{
  "errorCode": 0,
  "errorMessage": "",
  "result": {
    "user": "wm_player123",
    "money": "1000.0000",
    "responseDate": "2026-06-03 10:30:01"
  }
}
```

**處理邏輯：**
- 帳號 `status == 0`（停用）時回錯
- 餘額 = `WalletService::getMemberWallets()` 取得後乘以 `vendorInfo.rate`

---

### PointInout（加扣點）

WM 下注、派彩、重打時透過此 API 通知代理進行錢包操作。

**Request（WM → 代理）：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `cmd` | String | `"PointInout"` |
| `signature` | String | 商戶碼 |
| `user` | String | 玩家帳號 |
| `money` | String | 操作金額（正數） |
| `requestDate` | String | 請求時間 |
| `dealid` | String | 交易單號（唯一識別，用於冪等） |
| `gtype` | String | 遊戲類別代碼（`101`–`108`，`5000+`） |
| `type` | String | 格式 `gtype_期號_局號_動作類型` |
| `betdetail` | String | 下注內容與金額 |
| `gameno` | String | 格式 `gtype_期號_局號` |
| `code` | String | 操作類型代碼（見下表） |
| `category` | String | `"1"`=下注/派彩，`"2"`=打賞 |
| `betId` | Integer | 否，注單號（重打時使用） |
| `payout` | Decimal | 否，派彩金額（重打時使用） |

### code 值對照

| `code` | 方向 | 說明 |
|---|---|---|
| `0` | 扣點 | 電子遊戲結算 |
| `1` | 加點 | 正常派彩（加點） |
| `2` | 扣點 | 正常下注（扣點） |
| `3` | 加點 | 重對加點（補派彩） |
| `4` | 扣點 | 重對扣點（補扣款） |
| `5` | 加點 | 重新派彩 |

> 代理端路由：`code` 為 `0/2/4` → `deductBalance()`；`1/3/5` → `increaseBalance()`。

**成功回應（代理 → WM）：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `result.dealid` | String | 同 request 的 `dealid` |
| `result.money` | String | 原始操作金額（WM 幣值） |
| `result.cash` | String | 交易後玩家餘額（已套用 rate） |
| `result.responseDate` | String | 回應時間 |

```json
{
  "errorCode": 0,
  "errorMessage": "",
  "result": {
    "dealid": "123456789",
    "money": "100.0000",
    "cash": "900.0000",
    "responseDate": "2026-06-03 10:31:00"
  }
}
```

**扣點（下注）處理邏輯：**

1. 呼叫 `BetHelper::isMemberAbleToBet()` 確認帳號可下注
2. 開 DB Transaction
3. 以 `dealid` 查 `wmlivesw_deal_records`，若存在則拒絕（冪等）
4. 建立 `WmliveswDealRecord`（`firstOrCreate`），`status=0` 初始
5. `WalletService::getMemberLockedWallets()` 取得錢包（含禮碼）
6. 若餘額不足拋出 exception
7. 優先扣禮碼（`RollingChipService::sub()`），不足再扣真金
8. 更新錢包 `balance`
9. 建立 `TransactionLog`
10. 更新 DealRecord `status=1`（成功），記錄 `rolling_chips` 使用量
11. 回傳餘額（真金 + 禮碼合計，乘 rate）

**加點（派彩）處理邏輯：**

1. 開 DB Transaction
2. 以 `dealid` 查 DealRecord，若已存在則拒絕
3. 建立 DealRecord（`status=0`）
4. 取得遊戲錢包，加入 `amount`（÷ rate 換算）
5. 帳號 `status == 0`（已停用）時不實際更新餘額，但仍記錄 TransactionLog
6. 更新 DealRecord `status=1`
7. 回傳新餘額

**冪等性：**

| 場景 | 處理方式 |
|---|---|
| 相同 `dealid` 再次送入 | DealRecord 已存在 → 回錯 `"Deal record already exists"`（不重複處理） |
| `firstOrCreate` 防競態 | DealRecord 使用 `firstOrCreate` + 檢查 `wasRecentlyCreated` |

---

### TimeoutBetReturn（逾時回滾）

WM 端若交易逾時或需取消，透過此 API 通知代理回滾對應的 dealid。

**Request（WM → 代理）：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `cmd` | String | `"TimeoutBetReturn"` |
| `signature` | String | 商戶碼 |
| `user` | String | 玩家帳號 |
| `requestDate` | String | 請求時間 |
| `dealid` | String | 要回滾的原始交易單號 |
| `gtype` | String | 遊戲類別代碼 |
| `money` | String | 回滾金額（正數=派彩回滾，負數=下注回滾） |
| `type` | String | 格式 `gtype_期號_局號_類型` |
| `betdetail` | String | 下注內容 |
| `gameno` | String | 格式 `gtype_期號_局號` |
| `code` | String | `"1"`=加點，`"2"`=扣點 |
| `category` | String | `"1"`=下注/派彩，`"2"`=打賞 |

**成功回應（代理 → WM）：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `result.balance` | String | 回滾後玩家餘額 |
| `result.updated_at` | String | 更新時間 |

**回滾處理邏輯：**

1. 開 DB Transaction
2. 以 `dealid` 查詢 `WmliveswDealRecord`，不存在回錯
3. `is_rollback == 1`（已回滾）→ 拋出 exception
4. 判斷允許回滾條件：
   - `status == 1 && deal_code == 2`：扣點成功可回滾（取消下注，退款）
   - `status == 2 && deal_code == 1`：加點失敗可回滾（重試後退款）
   - 其他情況拒絕回滾
5. 分拆退款金額（`splitRefundAmount()`）：優先還真金，再還禮碼
6. 退款至禮碼錢包（若有禮碼使用）：建立 `RollingChipLog`（type=`BET_CANCEL`）
7. 退款至遊戲錢包：建立 `TransactionLog`
8. 更新 DealRecord `is_rollback = 1`

**文件特殊說明：**

> 若 WM 先發送 `TimeoutBetReturn`（回滾）才發送對應的 `PointInout`（下注），代理應記錄 dealid 並拒絕該次回滾，等收到後續的 PointInout 再正常處理。（目前實作若 dealid 不存在直接回錯，屬保守處理，建議確認 WM 是否會有此時序問題。）

---

### SendMemberReport（結算報表推送）

> ⚠️ **此功能需聯絡 WM 客服特別開通**，並需先關閉打賞功能。

WM 結算完成後主動推送注單彙總報表至代理，每次最多 25 筆。

**Request（WM → 代理）：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `cmd` | String | `"SendMemberReport"` |
| `signature` | String | 商戶碼 |
| `requestDate` | String | 請求時間 |
| `totalorder` | Integer | 本次推送筆數（max 25） |
| `result` | Array | 注單資料陣列 |

**result 陣列欄位：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `user` | String | 玩家帳號 |
| `betId` | String | 注單號（唯一，用於冪等） |
| `betTime` | String | 下注時間 |
| `bet` | String | 下注金額 |
| `validbet` | String | 有效投注額 |
| `water` | String | 水錢 |
| `result` | String | 結果描述 |
| `betResult` | String | 下注內容（如 `"Banker"`） |
| `waterbet` | String | 下注水錢 |
| `winLoss` | String | 輸贏金額 |
| `gid` | String | 遊戲類別代碼 |
| `event` | String | 期號 |
| `eventChild` | String | 局號 |
| `tableId` | String | 桌台 ID |
| `gameResult` | String | 牌面結果 |
| `gname` | String | 遊戲名稱 |
| `betwalletid` | String | 下注 dealid（多筆逗號分隔） |
| `resultwalletid` | String | 派彩 dealid（無派彩時為 `-1`） |
| `commission` | String | `"0"`=有水，`"1"`=免水 |
| `reset` | String | `"Y"`=重打，`"N"`=正常 |
| `settime` | String | 結算時間 |
| `page` | Integer | 頁碼 |

**成功回應：**

```json
{
  "errorCode": 0,
  "errorMessage": "",
  "result": {}
}
```

**Settlement 處理邏輯：**

1. 遍歷 `result` 陣列，逐筆處理
2. 依 `user` 取得會員（`getMemberByThirdPartyUsername()`）
3. 以 `betId` 做 `firstOrCreate` 建立 `WmliveswBetRecord`（冪等）
4. 若 BetRecord 已存在且 `status == 1`（已完成）→ 跳過
5. 以 `betwalletid` 逗號拆分，取得相關 DealRecord 清單；若有派彩再加 `resultwalletid`
6. 批次更新 DealRecord：`is_settlement = 1`、填入 `bet_id`
7. 加總所有 DealRecord 的 `rolling_chips`
8. 呼叫 `TotalBetRecordService::create()` 寫入 TBR
9. 更新 BetRecord `rolling_chips`、`status = 1`
10. 任一筆失敗 → BetRecord `status = 2`，記 emergency log，但不中止整批

**冪等性：**

| 場景 | 處理方式 |
|---|---|
| 相同 `betId` 再次推送 | `firstOrCreate` 跳過，若 `status == 1` 直接 `continue` |
| BetRecord 存在但 `status == 0/2` | 重新嘗試 settlement 流程（覆蓋式修復） |

**金額換算：**

所有金額欄位（`bet`、`validbet`、`winLoss`）存入 DB 前均 `÷ vendorInfo.rate`（`Common::numDiv`）。

---

## 代理主動呼叫 WM 的 API

以下為代理主動呼叫 WM 端的功能；所有請求發往 `vendor_infos.info.API_URL`。

### Hello（連線測試）

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"Hello"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |

**Response：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `errorCode` | Integer | `0`=成功 |
| `errorMessage` | String | 錯誤描述 |
| `result` | String | 成功時回傳 `"HELLO"` |

---

### MemberRegister（建立帳號）

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"MemberRegister"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `user` | String | ✅ | 玩家帳號（5–48 字元，建議加 prefix，如 `wm_abc123`） |
| `password` | String | ✅ | 密碼（6–64 字元） |
| `username` | String | ✅ | 顯示名稱（max 48 字元） |
| `profile` | Integer | 否 | 頭像 ID |
| `maxwin` | Integer | 否 | 最大贏分限制 |
| `maxlose` | Integer | 否 | 最大輸分限制 |
| `mark` | String | 否 | 備註（max 20 字元） |
| `rakeback` | Integer | 否 | 重設水錢：`0`=否，`1`=是 |
| `limitType` | String | 否 | 限注類型，見 WM Limit 文件 |
| `chip` | String | 否 | 籌碼面值（逗號分隔，5–10 個值） |
| `timestamp` | Integer | ✅ | Unix 時間戳 |
| `syslang` | Integer | 否 | 語言 |

**Response：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `errorCode` | Integer | `0`=成功，`104`=帳號已存在 |
| `errorMessage` | String | 錯誤描述 |
| `result` | String | 成功時回傳 `"新增完成"` |

**常見錯誤碼：**

| 碼 | 說明 |
|---|---|
| `104` | 帳號已存在 |
| `10403` | user 不可為空 |
| `10404`–`10409` | 參數長度錯誤 |
| `10419`–`10422` | 籌碼格式錯誤 |
| `10520` | 上層代理已停用 |

---

### ChangePassword（修改密碼）

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"ChangePassword"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `user` | String | ✅ | 玩家帳號 |
| `newpassword` | String | ✅ | 新密碼 |
| `timestamp` | Integer | ✅ | Unix 時間戳 |
| `syslang` | Integer | 否 | 語言 |

**Response：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `errorCode` | Integer | `0`=成功 |
| `result` | String | 成功時回傳 `"修改完成"` |

---

### LoginGame（取得遊戲連結）

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"LoginGame"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `user` | String | ✅ | 玩家帳號 |
| `password` | String | ✅ | 密碼 |
| `lang` | Integer | 否 | 語言代碼（見語言對照表） |
| `mode` | String | 否 | 直進遊戲大廳（如 `onlybac`） |
| `tableid` | String | 條件 | 部分遊戲模式必填 |
| `voice` | String | 否 | 音效語言 |
| `returnurl` | String | 否 | 登出後返回 URL |
| `isTest` | Integer | 否 | `1`=試玩模式 |
| `size` | Integer | 否 | `1`=iframe 嵌入 |
| `slot` | Integer | 否 | `1`=直進電子遊戲 |
| `slotCode` | Integer | 條件 | 電子遊戲類型（`slot=1` 時） |
| `slotGameId` | String | 條件 | 電子遊戲代碼（`slot=1` 時） |
| `timestamp` | Integer | ✅ | Unix 時間戳 |
| `syslang` | Integer | 否 | 語言 |

**Response：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `errorCode` | Integer | `0`=成功 |
| `result` | String | 遊戲登入 URL |

---

### LogoutGame（登出遊戲）

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"LogoutGame"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `user` | String | 否 | 玩家帳號；不傳則登出所有玩家 |
| `timestamp` | Integer | ✅ | Unix 時間戳 |
| `syslang` | Integer | 否 | 語言 |

---

### EnableorDisablemem（啟用/停用帳號）

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"EnableorDisablemem"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `user` | String | ✅ | 玩家帳號，多帳號逗號分隔 |
| `type` | String | ✅ | `"login"` 或 `"bet"` |
| `status` | String | ✅ | `"Y"`=啟用，`"N"`=停用 |
| `timestamp` | Integer | ✅ | Unix 時間戳 |
| `syslang` | Integer | 否 | 語言 |

---

### EditLimit（修改下注限制）

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"EditLimit"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `user` | String | ✅ | 玩家帳號 |
| `maxwin` | Integer | 否 | 最大贏分 |
| `maxlose` | Integer | 否 | 最大輸分 |
| `reset` | Integer | 否 | `1`=定時重置 |
| `limitType` | String | 否 | 限注類型，如 `2,5,9,14` |
| `timestamp` | Integer | 否 | Unix 時間戳 |
| `syslang` | Integer | 否 | 語言 |

---

### GetDateTimeReport（依時間查詢注單）

| 項目 | 值 |
|---|---|
| 資料保留 | **60 天** |
| 查詢限制 | 單次最大 24 小時；有資料 ≥30 秒/次；無資料 ≥10 秒/次 |

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"GetDateTimeReport"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `user` | String | 否 | 玩家帳號；不傳查全部 |
| `startTime` | String | ✅ | 格式 `YYYYMMDDhhmmss` |
| `endTime` | String | 否 | 格式同上；不傳查到現在 |
| `timetype` | Integer | 否 | `0`=下注時間，`1`=結算時間（**建議用 1**） |
| `datatype` | Integer | 否 | `0`=輸贏，`1`=打賞，`2`=全部 |
| `gameno1` | Integer | 否 | 期號 |
| `gameno2` | Integer | 否 | 局號 |
| `timestamp` | Integer | ✅ | Unix 時間戳 |
| `syslang` | Integer | 否 | 語言 |

**Response 主要欄位：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `errorCode` | Integer | `0`=成功，`107`=無資料 |
| `user` | String | 玩家帳號 |
| `betId` | String | 注單號 |
| `betTime` | String | 下注時間 |
| `bet` | String | 下注金額 |
| `validbet` | String | 有效投注額 |
| `water` | String | 水錢 |
| `result` | String | 結果 |
| `betResult` | String | 下注內容 |
| `winLoss` | String | 輸贏金額 |
| `gid` | String | 遊戲類別代碼 |
| `event` / `round` | String | 期號 |
| `eventChild` / `subround` | String | 局號 |
| `tableId` | String | 桌台 ID |
| `gameResult` | String | 牌面結果 |
| `gname` | String | 遊戲名稱 |
| `betwalletid` | String | 下注 dealid（多筆逗號分隔） |
| `resultwalletid` | String | 派彩 dealid |
| `commission` | String | `0`=有水，`1`=免水 |
| `reset` | String | `Y`=重打，`N`=正常 |
| `settime` | String | 結算時間 |
| `slotGameId` | String | 電子遊戲代碼（如適用） |

---

### GetTipReport（打賞紀錄）

| 項目 | 值 |
|---|---|
| 資料保留 | **60 天** |

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"GetTipReport"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `user` | String | 否 | 玩家帳號 |
| `startTime` | String | ✅ | 格式 `YYYYMMDD` |
| `endTime` | String | ✅ | 格式 `YYYYMMDD` |
| `timestamp` | Integer | ✅ | Unix 時間戳 |
| `syslang` | Integer | 否 | 語言 |

---

### GetUnsettleReport（未結算注單）

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"GetUnsettleReport"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `Time` | Integer | ✅ | 查詢日期，格式 `20190108` |
| `timestamp` | Integer | 否 | Unix 時間戳 |
| `syslang` | Integer | 否 | 語言 |

---

### GetDealidStatus（查詢交易紀錄）

**Request（dealid 或 user+時間範圍，二選一）：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"GetDealidStatus"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `user` | String | 條件 | 玩家帳號（需搭配 startTime/endTime） |
| `dealid` | Integer | 條件 | 交易 ID（與 user 二選一） |
| `startTime` | String | 條件 | 格式 `20170809115959` |
| `endTime` | String | 條件 | 格式 `20170809115959` |
| `timestamp` | Integer | 否 | Unix 時間戳 |
| `syslang` | Integer | 否 | 語言 |

**Response 主要欄位：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `dealid` | Integer | 交易號 |
| `requestDate` | Integer | 請求時間戳 |
| `responseDate` | Integer | 回應時間戳 |
| `money` | Float | 處理金額 |
| `cash` | Float | 交易後餘額 |
| `type` | String | `0`=查餘額，`1`=加點，`2`=扣點，`3`=重對加點，`4`=重對扣點 |
| `status` | String | `pending`/`success`/`failure`/`rollback` |
| `feedback_status` | String | 代理端回饋狀態 |
| `gamestatus` | String | `0`=未開始，`1`=已開始，`2`=重打，`9`=已取消 |

---

### GetReportDetail（注單詳情連結）

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `cmd` | String | ✅ | `"GetReportDetail"` |
| `vendorId` | String | ✅ | 商戶 ID |
| `signature` | String | ✅ | 商戶碼 |
| `user` | String | ✅ | 玩家帳號 |
| `betId` | Integer | ✅ | 注單號 |
| `url` | String | 否 | `Y`/`N`，是否回傳 URL（預設 `Y`） |
| `limit` | String | 否 | `Y`/`N`，是否啟用時效限制（預設 `Y`） |
| `limitTime` | Integer | 否 | 連結有效秒數（預設 `300`） |
| `syslang` | Integer | 否 | 語言 |

**Response：**

| 欄位 | 說明 |
|---|---|
| `url` | 注單詳情頁面 URL |

---

## 遊戲類型（gtype / gid）對照

| 代碼 | 遊戲名稱 |
|---|---|
| `101` | Baccarat（百家樂） |
| `102` | Dragon Tiger（龍虎） |
| `103` | Roulette（輪盤） |
| `104` | Sicbo（骰寶） |
| `105` | Niuniu（牛牛） |
| `107` | Fantan（番攤） |
| `108` | Sedie（色碟） |
| `5000+` | 電子遊戲（Electronic Games） |

---

## 語言代碼（lang）對照

| 代碼 | 語言 |
|---|---|
| `0` | 簡體中文 |
| `1` | 英文（預設） |
| `2` | 泰文 |
| `3` | 越南文 |
| `4` | 日文 |
| `5` | 韓文 |
| `6` | 印地文 |
| `7` | 馬來文 |
| `8` | 印尼文 |
| `9` | 繁體中文 |
| `10` | 西班牙文 |
| `11` | 俄文 |

---

## 遊戲大廳模式（mode）對照

| `mode` 值 | 遊戲 |
|---|---|
| `onlybac` | 百家樂大廳（gameCode `101`） |
| `onlydgtg` | 龍虎大廳（gameCode `102`） |
| `onlyrou` | 輪盤大廳（gameCode `103`） |
| `onlysicbo` | 骰寶大廳（gameCode `104`） |
| `onlyniuniu` | 牛牛大廳（gameCode `105`） |
| `onlyfantan` | 番攤大廳（gameCode `107`） |
| `onlysedie` | 色碟大廳（gameCode `108`） |

---

## 全域錯誤碼

| 碼 | 類型 | 說明 |
|---|---|---|
| `0` | 成功 | 操作成功 |
| `101` | 認證錯誤 | 身份驗證失敗 |
| `102` | 參數錯誤 | 時間格式錯誤 |
| `103` | 參數錯誤 | 商戶 ID/碼格式錯誤 |
| `104` | 帳號錯誤 | 帳號已存在 |
| `106` | 系統錯誤 | 資料建立失敗 |
| `107` | 成功（無資料） | 查詢成功但無符合資料 |
| `900` | 參數錯誤 | 功能不存在 |
| `911` | 系統維護 | 系統維護中 |
| `10201` | 參數錯誤 | 查詢超過 24 小時限制 |
| `10202` | 參數錯誤 | 時間戳異常（超過 ±30 秒） |
| `10301` | 參數錯誤 | 商戶 ID 為空 |
| `10302` | 參數錯誤 | 商戶 ID 無效 |
| `10303` | 認證錯誤 | 商戶碼錯誤 |
| `10304` | 認證錯誤 | 商戶碼為空 |
| `10410` | 失敗 | 前筆交易待確認，請稍後 |
| `10411` | 失敗 | 請 30 秒後重試 |
| `10418` | 失敗 | 請 10 秒後重試 |
| `10501` | 帳號錯誤 | 帳號不存在 |
| `10502` | 帳號錯誤 | 帳號名稱為空 |
| `10505` | 帳號錯誤 | 帳號已停用 |
| `10507` | 帳號錯誤 | 帳號不屬於此商戶 |
| `10511` | 帳號錯誤 | 新密碼與舊密碼相同 |
| `10512` | 帳號錯誤 | 帳號或密碼格式錯誤 |
| `10601` | 設定錯誤 | 限注設定不可用 |
| `10705` | 參數錯誤 | 缺少必要參數 |
| `10805` | 失敗 | 餘額不足 |
| `10910` | 參數錯誤 | betId 不可為空 |
| `10911` | 資料錯誤 | 注單紀錄不存在 |
| `10912` | 參數錯誤 | 時間戳格式錯誤 |
