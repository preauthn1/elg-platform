# Saba — API 規格

> 來源：`api_ch_20230613.pdf`（**主要事實來源**，343 頁，簡中）；  
> 舊版：`api_ch_20210930.pdf`、`api_ch_20220711.pdf`、`api_ch.pdf`（參考）；  
> 文檔站：<https://developer.onebook.dev/DocumentDownload/api_integration_ch.html>  
> 抓取日期：2026-06-03

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律 `POST`
- **Content-Type**：`application/x-www-form-urlencoded`（`Http::asForm()` 送出）
- **Base URL**：`http://{domain name}/api/{InterfaceName}`，`{domain name}` 為對外網域（閘道網域，由對接方提供），存於 `vendor_infos.info.API_URL`
- **時間格式**：所有 API 一律使用 **ISO-8601**
- **回應格式**：JSON

### 驗證方式

官方文檔**未定義任何簽章（signature）/ HMAC 機制**，本整合無簽章演算法。

| 機制 | 說明 |
|---|---|
| `vendor_id`（厂商标识符） | 每支 API 必帶；`error_code=9` 代表此值錯誤或未授權 |
| IP 白名單（閘道層） | 閘道層保護模型，實際白名單由對接閘道控管 |

> 請求參數以明文 form 欄位直接送出，無加密。

### 通用回應格式

```jsonc
{
  "error_code": 0,
  "message": "",
  "Data": { /* 視 API 而定，可能為 object / array / 字串 / 不存在 */ }
}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `error_code` | int | `0`=成功；非 0=失敗 |
| `message` | String | 成功時通常為空字串或 `"Success"`；失敗時為錯誤描述 |
| `Data` | Object \| Array \| String \| 不存在 | 成功時的回傳資料 |

### 共通錯誤碼（多支 API 共用）

| `error_code` | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 執行失敗 |
| `2` | 會員不存在 |
| `9` | 厂商标识符失效（`vendor_id` 錯誤 / 未授權） |
| `10` | 系統維護中 |

---

## Merchant API

代理（厂商）主動呼叫 Saba 的所有 API。每支 API 以 form 表單 `POST` 至 `http://{domain name}/api/{InterfaceName}`，必帶 `vendor_id`，**無 signature 欄位**。

### 會員

#### 1. CreateMember（建立新會員）

在 Saba 建立新會員資料。**建立會員後須先成功 `FundTransfer` 才能下注。**

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/CreateMember` |

**參數：**

| 參數 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `vendor_id` | string | 50 | Y | 厂商标识符 |
| `vendor_member_id` | string | 30 | Y | 會員帳號（建議與 username 相同，支援 ASCII 33–126） |
| `operatorId` | string | 50 | Y | 厂商 ID（廠商自行定義） |
| `firstname` | string | 50 | N | 會員姓氏 |
| `lastname` | string | 50 | N | 會員名字 |
| `username` | string | 30 | Y | 會員登入名稱（支援 ASCII 33–126） |
| `oddstype` | string | — | Y | 賠率 / 盤口類型（見附件「賠率類型表」） |
| `currency` | int | — | Y | 幣別（見附件「幣別表」） |
| `maxtransfer` | decimal | — | Y | 單筆最大轉帳金額（最高 9,999,999,999） |
| `mintransfer` | decimal | — | Y | 單筆最小轉帳金額（須 ≥ 0） |
| `custominfo1`~`custominfo5` | string | 200 | N | 廠商備註 |

**回傳：**

```jsonc
{ "error_code": 0, "message": "" }
```

**錯誤碼：**

| code | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 執行失敗 |
| `2` | 會員名稱（username）重複或使用不支援的字元 |
| `3` | OperatorId 錯誤 |
| `4` | 賠率類型格式錯誤 |
| `5` | 幣別格式錯誤 |
| `6` | `vendor_member_id` 重複或使用不支援字元 |
| `7` | 最小限制轉帳金額大於最大限制轉帳金額 |
| `8` | 無效的前綴字符 |
| `9` | 厂商标识符失效 |
| `10` | 系統維護中 |
| `12` | 長度限制 30（`Vendor_Member_ID` 或 `UserName`） |
| `13` | 超過最大限制轉帳金額（須 < 100 億） |

---

#### 2. UpdateMember（更新會員資料）

更新 Saba 中既有會員資料。

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/UpdateMember` |

**參數：** `vendor_id`、`vendor_member_id`（必填）；`firstname` / `lastname`（選填）；`maxtransfer` / `mintransfer` / `oddstype`（必填）；`custominfo1`~`custominfo5`（選填）。欄位語意同 CreateMember。

**錯誤碼：**

| code | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 執行過程中失敗 |
| `2` | 會員不存在 |
| `9` | 厂商标识符失效 |
| `10` | 系統維護中 |

---

#### 3. KickUser（注銷 / 踢出會員）

將會員踢出沙巴體育。

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/KickUser` |

**參數：**

| 參數 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `vendor_id` | string | 50 | Y | 厂商标识符 |
| `vendor_member_id` | string | 30 | Y | 會員帳號 |

**錯誤碼：**

| code | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 執行過程中失敗 |
| `2` | 會員不存在 |
| `3` | 會員不在線 |
| `9` | 厂商标识符失效 |
| `10` | 系統維護中 |

---

#### 4. CheckIsOnline（檢查會員是否在線）

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/CheckIsOnline` |

**參數：**

| 參數 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `vendor_id` | string | 50 | Y | 厂商标识符 |
| `vendor_member_ids` | string | 5100 | Y | 會員帳號（多筆以 `,` 分隔） |

**回傳：**

```jsonc
{
  "error_code": 0,
  "message": "",
  "Data": [
    { "vendor_member_id": "Member001", "is_online": true },
    { "vendor_member_id": "Member002", "is_online": false },
    { "vendor_member_id": "Member003", "error_code": 2 }
  ]
}
```

**錯誤碼：**

| code | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 執行過程中失敗 |
| `2` | 傳入的會員帳號為空值 |
| `9` | 厂商标识符失效 |
| `10` | 系統維護中 |

---

#### 5. CheckUserBalance（檢查會員餘額）

查詢會員在 Saba 端的餘額。

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/CheckUserBalance` |

**參數：**

| 參數 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `vendor_id` | string | 50 | Y | 厂商标识符 |
| `vendor_member_ids` | string | 5100 | Y | 會員帳號（多筆以 `,` 分隔；本專案傳單一帳號） |
| `wallet_id` | int | — | Y | 錢包代碼（`1`=沙巴體育；`5`=AG） |

**回傳：**

```jsonc
{
  "error_code": 0,
  "message": "",
  "Data": [
    { "vendor_member_id": "Member001", "balance": 100, "outstanding": 100, "currency": 20, "error_code": 0 }
  ]
}
```

> 本專案 `Driver::getBalance()` 取 `Data[0].balance`。

**錯誤碼（API 層）：**

| code | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 執行失敗 |
| `2` | 傳入的會員帳號為空值 |
| `7` | 取得非沙巴體育用戶餘額錯誤 |
| `9` | 厂商标识符失效 |
| `10` | 系統維護中 |
| `11` | 單一錢包機制不支援此功能 |

**用戶層錯誤碼（`Data[].error_code`）：**

| code | 說明 |
|---|---|
| `0` | 執行成功 |
| `2` | 會員不存在 |
| `3` | 會員被封存 |
| `6` | 會員尚未轉帳過 |
| `7` | 取得非體育用戶餘額錯誤 |

---

### 錢包轉帳

#### 6. FundTransfer（額度轉入 / 轉出）

轉入 / 轉出沙巴體育額度。

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/FundTransfer` |

**文檔操作建議（原文）：**

- **存款（direction=1）**：先扣下會員在貴司的額度 → 遇成功才把額度加進沙巴；遇失敗返回額度；遇 pending → 5 分鐘後做 CheckFundTransfer。
- **提款（direction=0）**：先不要加錢進貴司錢包 → 遇成功才加額度；遇失敗不加額度；遇 pending → 5 分鐘後做 CheckFundTransfer。

**參數：**

| 參數 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `vendor_id` | string | 50 | Y | 厂商标识符 |
| `vendor_member_id` | string | 30 | Y | 會員帳號 |
| `vendor_trans_id` | string | 50 | Y | 厂商交易編號（本專案 = `OPERATOR_ID . '_' . billNo`） |
| `amount` | decimal | 10,2 | Y | 轉帳總金額（本專案以 `Common::numFloor($credit, 2)` 無條件捨去） |
| `currency` | int | — | Y | 幣別（見附件「幣別表」） |
| `direction` | int | — | Y | `0`=從體育轉出（提款）；`1`=轉入體育（存款） |
| `wallet_id` | int | — | Y | 產品代碼（`1`=體育；`5`=AG；`13`=Exchange） |

**回傳：**

```jsonc
{
  "error_code": 0,
  "message": "Success",
  "Data": {
    "trans_id": 53,
    "before_amount": 101220,
    "after_amount": 101230,
    "system_id": "SampleSiteUUS01001",
    "status": 0
  }
}
```

**`Data.status` 狀態碼：**

| status | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 系統錯誤（轉帳失敗） |
| `2` | 未知狀態（Pending），請呼叫 CheckFundTransfer 確認 |

**`error_code` 錯誤碼：**

| code | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 執行過程中失敗 |
| `2` | 會員不存在 |
| `3` | 會員餘額不足 |
| `4` | 金額超出最小或最大限制轉帳金額 |
| `5` | 重複的 `vendor_trans_id` |
| `6` | 幣別錯誤 |
| `7` | 傳入參數錯誤 |
| `8` | 玩家盈餘限制（玩家贏超過系統可轉出有效值） |
| `9` | 厂商标识符失效 |
| `10` | 系統維護中 |
| `11` | 系統忙碌中，請稍後再試 |
| `12` | 無效的前綴字符 |
| `13` | 會員不能被解封存 |
| `15` | 會員被鎖定 |
| `16` | 單一錢包不支援轉出 |

> 三態 → `VendorTransferResultEnum` 對應見 `integration.md` 的「轉帳錢包流程與狀態機」章節。

---

#### 7. CheckFundTransfer（檢查額度轉帳狀態）

確認額度轉帳的最終狀態。FundTransfer 回 TIMEOUT 時，5 分鐘後呼叫此 API。

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/CheckFundTransfer` |

**文檔狀態語意（原文）：**

- `error_code=0`：`status=0` 轉帳成功；`status=1` 轉帳失敗
- `error_code=1`：`status=2` 請再次呼叫 CheckFundTransfer
- `error_code=2`：`status=null` 代表無此轉帳，請重新做轉帳
- `error_code=3`：`status=2` 代表請於 `message` 的秒數後再做 CheckFundTransfer

**參數：**

| 參數 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `vendor_id` | string | 50 | Y | 厂商标识符 |
| `vendor_trans_id` | string | 50 | Y | 厂商交易編號 |
| `wallet_id` | int | — | Y | 產品代碼（`1`=體育；`5`=AG） |

**回傳：**

```jsonc
{
  "error_code": 0,
  "message": "",
  "Data": {
    "trans_id": 9535717,
    "transfer_date": "2013-10-05T13:20:00",
    "amount": 100,
    "currency": 20,
    "before_amount": 100,
    "after_amount": 200,
    "status": 0
  }
}
```

**`Data.status` 狀態碼：**

| status | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 交易失敗 |
| `2` | 未知狀態（Pending） |

**`error_code` 錯誤碼：**

| code | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 執行過程中失敗 |
| `2` | 交易紀錄不存在 |
| `3` | 等待後再次確認（依 `message` 秒數） |
| `7` | `wallet_id` 輸入錯誤或 `vendor_trans_id` 為空 |
| `9` | 厂商标识符失效 |
| `10` | 系統維護中 |

---

### 遊戲登入

#### 8. GetSabaUrl（取得登入連結）

取得玩家進入遊戲的登入連結。

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/GetSabaUrl` |

> 文檔說明：語系（`lang`）及 `OType`（盤口）參數需於**取得連結後自行帶入使用**。  
> 範例：`http://example.invalid/Deposit_ProcessLogin.aspx?token=[REDACTED]

**`OType` 對照：**

| OType | 盤口 |
|---|---|
| `1` | 歐洲盤 |
| `2` | 中國盤 |
| `3` | 印尼盤 |
| `4` | 馬來盤 |
| `5` | 美國盤 |

**參數：**

| 參數 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `vendor_id` | string | 50 | Y | 厂商标识符 |
| `vendor_member_id` | string | 30 | N | 會員帳號（不帶則取得未登入前的連結） |
| `platform` | int | 10 | Y | 投注平台：`1`=桌機；`2`=手機 h5；`3`=手機純文字 |

**回傳：**

```jsonc
{
  "error_code": 0,
  "message": "",
  "Data": "http://example.invalid/Deposit_ProcessLogin.aspx?token=[REDACTED]"
}
```

**錯誤碼：**

| code | 說明 |
|---|---|
| `0` | 執行成功 |
| `1` | 執行過程中失敗 |
| `2` | 會員不存在 |
| `3` | 輸入參數有誤 / 無資料 |
| `9` | 厂商标识符失效 |
| `10` | 系統維護中 |

> 本專案 `Driver::getGameUrl()`：以 `platform = MOBILE?2:1` 取得連結後，附加 `&lang={語系}`（語系對照見附錄）。

---

#### 9. GetWidgetUrl（取得小工具連結）

> ⚠️ **此端點未見於 `api_ch_20230613.pdf`**（全文無 `widget` 字樣），屬本專案 / 對接閘道的擴充端點，規格需向 RD / 對接方確認。下列為**程式碼推得**的請求樣貌，非官方文檔欄位。

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/GetWidgetUrl` |

**程式碼推得的參數（待官方佐證）：**

| 參數 | 來源 | 說明 |
|---|---|---|
| `vendor_id` | `info.VENDOR_ID` | 厂商标识符 |
| `vendor_member_id` | `saba_accounts.username_modified` | 會員帳號（含前綴） |
| `language` | 語系對照（見附錄） | 語言代碼 |
| `widgetId` | `info.WIDGET_ID`（預設 `00000000`） | 小工具 ID |
| `domain` | `params->exitUrl` | 返回網域 |
| `odds_type` | `info.ODDSTYPE`（預設 `1`） | 盤口 / 賠率類型 |

回傳 `Data` 為小工具 URL。錯誤碼研判沿用共通格式（`0` 成功），需確認。

---

### 報表 / 注單

#### 10. GetBetDetail（取得注單資訊）

依版本號滾動拉取最近注單（每次最多 500 筆）。

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/GetBetDetail` |

**滾動拉單規則（原文重點）：**

- 新接入第一筆從 `version_key=0` 開始拉。
- 每次回傳 `last_version_key`，下次帶入上次取得的值。
- 帶入值與回傳 `last_version_key` **相同** → 10 分鐘後再做一次（避免漏票）。
- 帶入值與回傳 `last_version_key` **不同但沒收到票** → 此區間無貴司的票，用較大的 `last_version_key` 繼續往下拉。
- 建議頻率 **6~10 秒一次**。
- `version_key` 遞增且不重複，**不一定連號**（all pool，跳號正常）；一次最多 500 張，偶爾不到 500 屬正常。
- 同一張票狀態變更（如 waiting→running→lose），`trans_id` 不變但 `version_key` 會變化多次。

**參數：**

| 參數 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `vendor_id` | string | 50 | Y | 厂商标识符 |
| `version_key` | int | — | Y | 版本號（首次帶 `0`） |
| `options` | string | 50 | N | `info`：回傳 `betteamname` 參數（僅支援 Sport Type 1~99） |

**回傳（節錄）：**

```jsonc
{
  "error_code": 0,
  "message": "",
  "Data": {
    "last_version_key": 7953688,
    "BetDetails": [
      {
        "trans_id": 8534897217,
        "vendor_member_id": "1000007",
        "operator_id": "LIC",
        "league_id": 3,
        "match_id": 36510279,
        "sport_type": 1,
        "bet_type": 413,
        "odds": 18,
        "stake": 2,
        "transaction_time": "2016-03-14T23:39:58.733",
        "ticket_status": "void",
        "winlost_amount": 0,
        "after_amount": 3444.4056,
        "currency": 20,
        "winlost_datetime": "2016-03-16T00:00:00",
        "odds_type": 3,
        "version_key": 358199674
      }
    ]
  }
}
```

**重要欄位：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `trans_id` | bigint | 注單編號（同一張票狀態變更時不變） |
| `vendor_member_id` | string | 會員帳號 |
| `operator_id` | string | 厂商 ID（自定義） |
| `sport_type` | tinyint | 體育種類（`bet_type=29/2799` 時為 null）；見附件「體育種類表」 |
| `bet_type` | smallint | 下注類型；見附件「下注類型表」 |
| `odds` | smallmoney | 注單賠率 |
| `stake` | money | 會員投注金額 |
| `transaction_time` | datetime | 投注交易時間 |
| `ticket_status` | string(15) | 注單狀態（見下表） |
| `winlost_amount` | money | 此注輸 / 贏的金額 |
| `after_amount` | money | 下注後餘額 |
| `currency` | tinyint | 幣別 |
| `winlost_datetime` | smalldatetime | 決勝時間（**請依此欄位做後台報表對帳**） |
| `odds_type` | tinyint | 賠率 / 盤口類型 |
| `version_key` | bigint | 版本號 |
| `parlay_ref_no` | bigint | 混合過關注單號（用於 `GetSystemParlayDetail`） |
| `resettlementinfo` | object | 重新結算資訊（重新結算才出現，含 `winlost` / `ActionDate` / `ticket_status` / `balancechange` / `action`） |

**`ticket_status` 注單狀態：**

| 狀態 | 含義 |
|---|---|
| `waiting` | 等待中（交易員尚未接受，例如賠率轉換中） |
| `running` | 進行中（尚未結算） |
| `void` | 作廢（running 狀態下退回下注金，交易員對賽事存疑） |
| `refund` | 退款（running 狀態下退回下注金，賽事取消或意外） |
| `reject` | 已取消（waiting 狀態下退回下注金） |
| `lose` | 輸（已結算） |
| `won` | 贏（已結算） |
| `draw` | 和局（已結算） |
| `half won` | 半贏（贏一半下注額） |
| `half lose` | 半輸（輸一半下注額） |

> 對帳時請以 `winlost_datetime`（決勝時間）歸帳，並以 `version_key` 輪詢補抓狀態變更。

**錯誤碼：** 沿用共通格式（`0` 成功 / `9` 厂商标识符失效 / `10` 系統維護中）。

---

#### 11. GetVersionkeyByDate（取得指定日期 Version Key）

取得指定日期的第一筆 version key，用於回溯重抓某日注單。

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/GetVersionkeyByDate` |

> 取得的 version key **減 1** 後帶入 `GetBetDetail`，可重新取得指定日期的注單。

**參數：**

| 參數 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `vendor_id` | string | 50 | Y | 厂商标识符 |
| `winlost_date` | DateTime | 30 | Y | 注單帳務日期（同 GetBetDetail 回傳的 `winlost_datetime`） |

**回傳：**

```jsonc
{ "error_code": 0, "message": "", "Data": "<version key>" }
```

---

#### 12. GetMaintenanceTime（取得維護時間）

取得下次系統維護時間。

| 項目 | 值 |
|---|---|
| Endpoint | `POST /api/GetMaintenanceTime` |

**參數：** `vendor_id`（必填）。

> 維護中時其他 API 回 `error_code=10`（系統維護中），例：`System is under maintenance`。

---

## 本整合無簽章演算法

Saba 官方文檔（`api_ch_20230613.pdf`）**未定義簽章機制**，故本整合無 signature / HMAC 步驟。所有請求參數以明文 form 欄位送出，驗證僅靠 `vendor_id` 字串與閘道層 IP 白名單。

---

## 附錄：語言代碼（實作對照）

本專案 `Driver::getLanguage()` 映射表：

| 內部語系 | Saba `lang` |
|---|---|
| EN（英文） | `en` |
| ZH_TW（繁中） | `ch` |
| ZH_CN（簡中） | `cs` |
| ID_ID（印尼） | `id` |
| VI_VN（越南） | `vn` |
| TH_TH（泰文） | `th` |
| 其他 / 預設 | `en` |

> 完整官方語系代碼對照見原文檔附件「語系表」（page 209）。Saba 文檔範例使用 `lang=cs`（簡中）與 `OType` 併入登入 URL。
