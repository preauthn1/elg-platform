# target / ION-Slot — API 規格

> 來源：`PGS对接文件_轉帳模式_20221006.pdf`（天马游戏平台 API Document — 转帐模式 / transfer mode v1.1.1）
> 補充：`info.txt`（DSG移工商戶資料）、`20210602_gamelist.txt`、限紅列表圖、`dev-api文檔(CN).url`
> 抓取日期：2026-06-03
> 對應 vendor：`VendorIdEnum::target = 24`（Transfer Wallet）
>
> 逐欄參數外部文件站：`http://example.invalid/api-document/transfer/{EN,CN}`（開發環境，可能無法存取）。標記 `[impl]` 的欄位來自專案實作 `app/Vendors/target/Driver.php` 交叉驗證；標記 `[doc]` 的來自 PDF；其餘為 gap。

---

## 通用約定

### 規格標準

| 項目 | 值 |
|---|---|
| 風格 | REST API |
| Content-Type | `application/json`（缺少回 HTTP 415） |
| 編碼 | UTF-8 |
| HTTP Method | `GET`（queryString）、`POST`（requestBody JSON）、`PUT` |
| 時區 | UTC (+0)；格式 ISO8601（`yyyy-MM-ddTHH:mm:ssZ`） |
| 金額欄位 | float，小數點 2 位（`betAmount`、`payoutAmount`、轉帳 `amount` 最低 0.1） |
| 玩家帳號 | 限英數字、dash (`-`)、underline (`_`)，長度上限 50 字 |
| 回應語系 | 預設中文；Header `locale: en-us` 改英文（僅支援 `zh-cn`、`en-us`） |

### 驗證方式（無簽章雜湊）

target 不使用簽章演算法。每次請求在 body（POST）或 queryString（GET）直接帶：

| 欄位 | 說明 |
|---|---|
| `merchantID` | 運營商編號，target 預先提供 |
| `merchantSecretKey` | 運營商密鑰，target 預先提供；**所有 API 共用同一組密鑰** |

另一層防護：IP 白名單（每商戶最多 **10 組**），雙側防火牆（遊戲方 + 運營商）。

> ⚠️ `merchantSecretKey` 屬敏感憑證，寫入 log / 通知前必須遮罩（`Common::maskCredentials()`）。

### 通用請求 Body 範例（POST）

```jsonc
{
  "merchantID": 125001,
  "merchantSecretKey": "6754fbdb62cb",
  "playerName": "test_user_001"
}
```

### 通用回應格式

成功（HTTP 200，`code = 0`）：

```jsonc
{
  "code": 0,
  "message": null,
  "data": { "link": "http://0.0.0.0/game-play/dfdc/?eyJkY........" }
}
```

失敗（HTTP 400，`code` 為錯誤碼）：

```jsonc
{
  "code": 400001,
  "message": "亲，您所填寫的參數有缺少或參數格式不正確",
  "data": null
}
```

### HTTP 狀態碼

| 狀態碼 | 說明 |
|---|---|
| `200` | 正常（業務成功仍須看 body `code === 0`） |
| `400` | 邏輯/驗證/格式錯誤 |
| `415` | Unsupported Media Type（缺 `Content-Type: application/json`） |
| `500` | 系統錯誤，請聯絡 target 技術人員 |

### 商戶幣別

- 一組商戶配一種幣別；多幣別需申請多組商戶。
- API 數據（`betAmount`、`payoutAmount`）以**平台幣**方式顯示。
- target 可針對商戶配置「幣值轉換」（精度考量）。

本商戶實測（`info.txt`）：DSG移工，幣別 VND，MerchantID `175029`，正式 API `https://example.invalid/v1`。

---

## Merchant API

所有 API 端點相對於 `vendor_infos.info.API_URL`（已含 `/v1`，建構時 `trim(..., '/')`）。

### API 清單

| API | Method | Path | 用途 | Driver 呼叫 |
|---|---|---|---|---|
| 2.1.1 取得遊戲啟動連結 | `GET` | `/game/link` | 玩家進入遊戲 URL | ✅ `getGameUrl` |
| 2.2.1 檢查用戶是否存在 | `GET` | `/player/exist` | 查玩家帳號是否已建立 | — |
| 2.2.2 建立玩家帳戶 | `POST` | `/player` | 新增玩家 | — |
| 2.4.1 取得玩家錢包餘額 | `GET` | `/wallet/amount` | 查 target 端玩家餘額 | ✅ `getBalance` |
| 2.4.2 玩家錢包轉帳（存款） | `POST` | `/wallet/transfer-in` | 運營商 → target | ✅ `transferToVendor` |
| 2.4.3 玩家錢包轉帳（提款） | `POST` | `/wallet/transfer-out` | target → 運營商 | ✅ `transferToLocal` |
| 2.4.4 查詢轉帳記錄 | `GET` | `/transfer` | 查單筆轉帳最終狀態 | ✅ `checkTransferStatus` |
| 3.1.1 取得投注記錄 | `GET` | `/game-records` | 對帳投注明細 | — |
| 3.1.3 取得商戶總報表 | `GET` | `/game-records/report` | 商戶彙總報表 | — |
| 4.6.1 取得遊戲列表 | `GET` | `/games` | 遊戲清單（含 `isPrePaidMode`） | — |
| 4.8.1 取得活動派獎記錄 | `GET` | `/event/prize-records` | 活動派獎明細 | — |

---

### 2.1.1 取得遊戲啟動連結 — `GET /game/link`

#### 請求參數（queryString）

| 欄位 | 來源 | 說明 |
|---|---|---|
| `merchantID` | [impl] | 運營商編號 |
| `merchantSecretKey` | [impl] | 運營商密鑰 |
| `playerName` | [impl] | 玩家帳號 |
| `GameID` | [impl] | 遊戲代碼（由 API 4.6.1 取得） |
| `lang` | [impl] | 語言：`zh-tw` / `zh-cn` / `en`（fallback `en`） |
| `liveCasinoLevelID` | [impl] | Live Casino 限紅等級；僅 `LIVE_BET_LIMIT` 非空時帶入 |

#### 回應

```jsonc
{
  "code": 0,
  "message": null,
  "data": { "link": "http://0.0.0.0/game-play/dfdc/?eyJkY........" }
}
```

實作取 `data.link`，同時填入 `pc` 與 `mobile`（共用同一 URL）。

---

### 2.2.1 檢查用戶是否存在 — `GET /player/exist`

查玩家帳號是否已建立。共用 `merchantID` / `merchantSecretKey` + `playerName`（推測；逐欄待外部文件站確認）。

> Driver 未呼叫此 API。

---

### 2.2.2 建立玩家帳戶 — `POST /player`

新增玩家。逐欄參數待外部文件站確認（gap）。

> Driver 未呼叫（玩家建立路徑待確認，可能在 AbstractTransferWallet 或其他流程）。

---

### 2.4.1 取得玩家錢包餘額 — `GET /wallet/amount`

#### 請求參數（queryString）

| 欄位 | 來源 | 說明 |
|---|---|---|
| `merchantID` | [impl] | 運營商編號 |
| `merchantSecretKey` | [impl] | 運營商密鑰 |
| `playerName` | [impl] | 玩家帳號 |

#### 回應

```jsonc
{
  "code": 0,
  "message": null,
  "data": { "amount": 123.45 }
}
```

實作取 `data.amount`，經 `Common::numFloor($amount, 4)` 正規化後回傳。

---

### 2.4.2 玩家錢包轉帳（存款）— `POST /wallet/transfer-in`

把運營商餘額轉入 target。對應 `Driver::transferToVendor()`。

#### 請求參數（body JSON）

| 欄位 | 來源 | 說明 |
|---|---|---|
| `merchantID` | [impl] | 運營商編號 |
| `merchantSecretKey` | [impl] | 運營商密鑰 |
| `playerName` | [impl] | 玩家帳號 |
| `amount` | [impl] | 轉帳金額（最低 0.1、小數 2 位）；帶 `VendorTransferData::$vendorAmount` |
| `merchantOrderNo` | [impl] | 運營商訂單號（唯一識別）；帶 `VendorTransferData::$transactionNo` |

#### 結果判斷

| 條件 | VendorTransferResultEnum |
|---|---|
| 連線逾時 / 回應非 JSON / 無 `code` 欄位 | `TIMEOUT`（不還錢，待 `checkTransferStatus` 補查） |
| `code !== 0` | `FAILED` |
| `code === 0` | `SUCCESS` |

---

### 2.4.3 玩家錢包轉帳（提款）— `POST /wallet/transfer-out`

把 target 端餘額轉回運營商。對應 `Driver::transferToLocal()`。

請求參數與結果判斷同 [2.4.2](#242-玩家錢包轉帳存款--post-wallettransfer-in)（方向相反，endpoint 不同）。

---

### 2.4.4 查詢轉帳記錄 — `GET /transfer`

依運營商訂單號查單筆轉帳最終狀態。對應 `Driver::checkTransferStatus($transactionNo)`。

#### 請求參數（queryString）

| 欄位 | 來源 | 說明 |
|---|---|---|
| `merchantID` | [impl] | 運營商編號 |
| `merchantSecretKey` | [impl] | 運營商密鑰 |
| `merchantTransactionNo` | [impl] | 運營商交易號（即先前 transfer-in/out 的 `merchantOrderNo`） |

#### 結果判斷

| 條件 | VendorTransferResultEnum |
|---|---|
| 連線逾時 / 回應非 JSON / 無 `code` 欄位 | `TIMEOUT` |
| `code != 0` 或 `data` 為空 | `FAILED` |
| `code == 0` 且 `data` 非空 | `SUCCESS` |

---

### 3.1.1 取得投注記錄 — `GET /game-records`

對帳用投注明細。逐欄請求/回應結構待外部文件站確認（gap）。

**特殊遊戲欄位語意**：

| 欄位 | 一般遊戲 | 預付模式（捕魚，`isPrePaidMode=true`） |
|---|---|---|
| `betAmount` | 投注金額 | 進房預扣全部金幣 |
| `payoutAmount` | 派彩金額 | 退房返還剩餘金幣 |
| `validAmount` | 有效流水 | 實際發射子彈金幣 |

Live Casino 補充：`validAmount` 以局為單位計算、多注平均分攤；對押時歸 0。

> Driver 未呼叫此 API。若帶指定參數可額外回應局號、該局玩家總流水（PDF 補充）。

---

### 3.1.3 取得商戶總報表資訊 — `GET /game-records/report`

商戶彙總報表。逐欄參數待外部文件站確認（gap）。Driver 未呼叫。

---

### 4.6.1 取得遊戲列表 — `GET /games`

回傳可開放遊戲清單，含遊戲名稱（依 `locale` header 語系）與 `isPrePaidMode`。
Driver 已呼叫（`getGameList()`，遊戲清單同步）。

請求（queryString）：

| 欄位 | 說明 |
|---|---|
| `merchantID` | 運營商編號 |
| `MerchantSecretKey` | 運營商密鑰。**注意本端點為大寫 M 開頭**，與其他端點的 `merchantSecretKey` 命名不一致（線上整合驗證，見 [integration.md](integration.md)） |

回應 `data[]`（欄位自線上整合觀測，PDF 未列逐欄結構）：

| 欄位 | 型別 | 說明 |
|---|---|---|
| `gameID` | string | 遊戲編號（開遊戲 `game/link` 用此值；平台以此為 `games.game_type` 唯一鍵） |
| `gameName` | string | 遊戲名稱（依 `locale` header 語系） |
| `gameType` | int | 遊戲分類碼（見下表） |
| `imageUrl` | string | 遊戲圖示 URL |
| `gameCode` | string | 廠商別名代碼 |

遊戲分類碼：

| gameType | 語意 |
|---|---|
| 0 | 無分類 |
| 1000 | 老虎機 |
| 2000 | 棋牌 |
| 2500 | 視訊 |
| 3000 | 機台 |
| 4000 | 角鬥場 |
| 5000 | 彩票樂透 |
| 6000 | 柏青斯洛 |
| 7000 | 捕魚 |

附錄 GameID 示例（非完整清單，完整以本 API 回傳為準）：

| GameID | 遊戲名稱 |
|---|---|
| `1004` | 金吉報喜_無盡寶藏 |
| `1007` | 財神到_彩金版 |
| `1013` | 88Fortune |

---

### 4.8.1 取得活動派獎記錄 — `GET /event/prize-records`

活動派獎明細（v1.0.11 / 2021-09-09 新增）。逐欄參數待外部文件站確認（gap）。Driver 未呼叫。

---

### 語言代碼對照（實作 mapping）

| 本地 LanguageEnum | 送給 target 的 `lang` |
|---|---|
| `ZH_TW` | `zh-tw` |
| `ZH_CN` | `zh-cn` |
| 其他 / 預設 | `en` |

> 注意：PDF 的 `locale` header 僅提 `zh-cn`、`en-us`；`game/link` 的 `lang` 是否支援 `zh-tw` 待向客服確認（見 [integration.md](integration.md) gaps）。

---

## 錯誤碼

| 錯誤碼 | 說明 |
|---|---|
| `400001` | 參數有缺少或參數格式不正確 |
| `403001~403099` | 各種請求參數異常（時間格式、空值、超出範圍等） |
| `403601` | 狀態不合法（操作的資料已被設為關閉/禁用） |
| `404001~404099` | 查無資料（編號錯誤、帳號不存在） |
| `500001` | 系統錯誤，請聯絡 target 技術人員 |
| `500029` | 資料重複錯誤（鍵值或命名不允許重複） |

> 完整錯誤碼明細位於外部文件站 `http://example.invalid/api-document/transfer/enums/{en,cn}`（開發環境，可能無法存取）— 列為 gap。

---

## 簽章機制

target 轉帳模式 **不使用簽章雜湊演算法**（與使用 3DES/MD5 的其他廠商不同）。

驗證依賴兩層：

1. **白名單 IP**：每商戶最多 10 組，雙側防火牆設置。對接前須提供運營商 IP 給 target 開通。
2. **共用密鑰明文傳遞**：`merchantID` + `merchantSecretKey` 隨每次請求帶入。

實作組裝（`app/Vendors/target/Driver.php`）：

```php
$params = [
    'playerName'        => $this->vendorAccount->username,
    'merchantSecretKey' => $this->vendorInfoDetail->API_KEY,
    'merchantID'        => $this->vendorInfoDetail->API_APPID,
    // ... 該 API 專屬參數
];
```

- GET 類（`game/link`、`wallet/amount`、`transfer`）：以 queryString 帶出。
- POST 類（`wallet/transfer-in`、`wallet/transfer-out`）：以 `->asJson()->post($url, $params)` 送出。

> ⚠️ `merchantSecretKey` 屬敏感憑證，必須在記錄 log / 通知前遮罩。`Http::logAction("PGS_*")` 需確認是否已做遮罩處理（見 [integration.md](integration.md) 高風險陷阱 §1）。
