# Ia（vendor_id=21）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| Vendor ID | `21`（`VendorIdEnum::IA`） |
| 遊戲類型 | 電競 / 體育（DOTA2、ProDota Cup 等） |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| Driver | `app/Vendors/Ia/Driver.php`（繼承 `AbstractTransferWallet`） |
| 簽名機制 | **雙層加密**：內層 MD5 auth_key + 外層 AES-256-CBC（body 全加密） |
| HTTP header | `pch`（商戶識別，客服提供） |
| Content-Type | `text/plain`（body 為 Base64 密文） |
| 成功判斷 | `code === '1'`（**注意：非文檔的 `status`**） |
| 注單保留期 | **3 天**（須高頻拉取對帳） |
| 賬變保留期 | 70 天 |
| 限流 | `/index/gethotgame` 每分鐘最多 1 次 |
| 轉帳二次確認 | **每次 deposit / withdraw 後必須立即呼叫 `/user/searchorder`** |
| config key | `vendor_infos.info`：`API_URL`、`API_KEY`（AES key）、`API_IV`、`API_PCH`（pch header）、`CURRENCY` |

## 高風險陷阱

### 1. 成功欄位名：文件 `status` vs 實作 `code`

- **官方 PDF（2019）**：回應骨架為 `{"status": 1, "error_code": ..., "message": ..., "data": ..., "ext": []}` ，成功時 `status = 1`。
- **實作**：`Driver.php` 全部讀 `code` 欄位（`(string) $response['code'] === '1'`）；`getBalance` 讀 `$response['code'] === 1`（整數比較）。
- **後果**：若三方實際回傳 `status` 而非 `code`，所有判讀都會錯誤。最可能的解釋是 showdoc 線上版本已將欄位改為 `code`（PDF 為舊版）。
- **必須行動**：接入前以抓包或 showdoc 線上文檔（需密碼 `1234qwer`）確認實際欄位名。

### 2. `data.data` 巢狀結構（getBalance）

- 文件描述 `/user/balance` 的 `data` 欄位為餘額純量：`"data": 30.32`。
- 實作讀 **`$response['data']['data']`**（巢狀兩層）。
- 若實際回傳純量，實作會取到 `null`，導致餘額永遠為 0。

### 3. deposit / withdraw 回應無 `data.status`

- 文件描述 deposit/withdraw 回應只有 `status`（外層）+ `message`，**無** `data` 物件。
- 實作在確認轉帳成功時同時檢查 `$response['data']['status'] === '1'`。
- 若回應本身不含 `data.status`，實作會落入 `TIMEOUT` 分支，再由 `searchorder` 二次確認收斂——功能上安全，但在每次轉帳都會多一次查單請求。

### 4. 注單 3 天保留窗口

- `/wager/getproject` 和 `/wager/getactivitylog` **只保留最近 3 天**。
- 對帳任務**必須高頻拉取**（建議每小時或每 6 小時），否則資料永久遺失，無法事後補帳。

### 5. AES cipher 文件未指定

- 文件只說「AES 加密（客服提供 IV/KEY）」，未載明位元數或區塊模式。
- 實作使用 `aes-256-cbc`（OpenSSL）。
- 若三方使用不同規格（如 128-bit 或 ECB），所有請求都會解密失敗。

## 錢包流程（Transfer Wallet）

```
進入遊戲：
  本地錢包
    → POST /user/deposit（transferToVendor）
    → 立即 POST /user/searchorder（checkTransferStatus 二次確認）
    → 若 searchorder.data.status === '1' → SUCCESS
    → POST /user/lunch（getGameUrl）→ 取得遊戲 URL → 玩家進場

離開 / 提回：
  POST /user/withdraw（transferToLocal）
    → 立即 POST /user/searchorder（checkTransferStatus 二次確認）
    → 若 searchorder.data.status === '1' → SUCCESS
```

**搜尋訂單（searchorder）邏輯**：

| 情況 | 回傳結果 |
|---|---|
| `code === '1'` 且 `data.status === '1'` | `VendorTransferResultEnum::SUCCESS` |
| `code !== '1'` | `VendorTransferResultEnum::FAILED` |
| `data.status !== '1'` | `VendorTransferResultEnum::FAILED` |
| 缺少 `code` 或 `data.status` | `VendorTransferResultEnum::TIMEOUT`（上層後續再查） |

> 注意：`checkTransferStatus()` 送 `order_id` 但**未送 `type` 參數**（預設 -1 查全部），文件支援 `type=1`（存款）/ `type=2`（提款）的分類查詢但實作未採用。

## 冪等性

- 每筆轉帳攜帶唯一 `order_id`（最長 32 碼，英數字組合）。
- deposit / withdraw 後必立即查單（`searchorder`）確認最終狀態，不依賴單次呼叫回應。
- 逾時或結構異常一律回 `TIMEOUT`，讓上層機制後續查單收斂，**避免重複轉帳**。
- 文件未明載 `order_id` 是否跨存提全域唯一（`searchorder` 未送 `type` 的隱含假設），需與 Ia 客服確認。

## 整合落地建議

| Ia 概念 | APIs 專案對應 |
|---|---|
| `API_URL`（Base URL） | `vendor_infos.info.API_URL`（Driver 自動 `trim('/')` ） |
| `API_KEY`（AES 256 key） | `vendor_infos.info.API_KEY` |
| `API_IV`（AES IV） | `vendor_infos.info.API_IV` |
| `pch` header | `vendor_infos.info.API_PCH` |
| 幣種 | `vendor_infos.info.CURRENCY`（如 `RMB`、`TWD`，見 api.md 幣種附錄） |
| 玩家帳號 | `ia_accounts.username`（`IaAccount` model） |
| 轉帳 / 遊戲入口 | `app/Vendors/Ia/Driver.php`（`transferToVendor` / `transferToLocal` / `getGameUrl` / `getBalance` / `checkTransferStatus`） |
| 注單 / 對帳 | `IaBetRecord` model；資料來源 `/wager/getproject`（3 天窗口，高頻拉取） |
| Driver 註冊 | `config/vendor.php`：`VendorIdEnum::IA->value => Vendors\Ia\Driver::class` |
| 回調 Controller | **無**（轉帳錢包不需回調） |

`vendor_infos.info` 必要欄位：

```jsonc
{
  "API_URL":  "https://{ia_api_domain}",
  "API_KEY":  "<AES-256 key，客服提供>",
  "API_IV":   "<AES IV，客服提供>",
  "API_PCH":  "<pch header 值，客服提供>",
  "CURRENCY": "RMB"
}
```

## Gaps / 待 Ia 確認

| # | 項目 | 說明 |
|---|---|---|
| 1 | **成功欄位名（首要）** | PDF 寫 `status`，實作讀 `code`。showdoc 線上版（需密碼 `1234qwer`）未在本次取得，無法當場判定。**接入前必須確認實際欄位名**。 |
| 2 | **正式 Base URL** | 文件以 `{ia_api_domain}` 佔位，正式網域必須由客服提供。 |
| 3 | **AES cipher 規格** | 文件未明寫位元數/模式；實作用 `aes-256-cbc`，需與客服確認一致。 |
| 4 | **餘額欄位巢狀層級** | 文件說 `data` 為純量，實作讀 `data.data`（巢狀），需確認實際回傳結構。 |
| 5 | **deposit / withdraw 回應是否含 `data.status`** | 文件未載明，實作依賴此欄位；確認後可決定是否移除不必要的 searchorder 呼叫。 |
| 6 | **`order_id` 全域唯一性** | searchorder 未送 `type`，隱含 order_id 跨存提唯一。若同一 order_id 可跨類型，需加 `type` 參數。 |
| 7 | **帳號建立流程** | Driver 未顯式呼叫 `/user/register`；帳號由 `AbstractTransferWallet` 共用機制處理，待 RD 確認 Ia 帳號何時建立。 |
| 8 | **完整錯誤碼表** | 文件僅示例 `error_code: 1001`，無完整碼表；需向客服索取。 |
