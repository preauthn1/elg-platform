# Lfc888（vendor_id=100）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| Vendor ID | `VendorIdEnum::LFC888 = 100` |
| 廠商品牌 | BT Gaming |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| 請求格式 | POST，`Accept: application/json`，`Content-Type: application/json; charset=UTF-8` |
| 簽名欄位 | `check_code`（MD5，`security_code` 起頭 + 其餘參數字母序） |
| 成功判定 | `status.code === 1000` |
| 時區 | **UTC-4**（所有時間用 RFC3339 加 `-04:00`） |
| 交易狀態 | `pending`（擱置）/ `transferred`（已完成） |
| 對帳保留期 | **90 天**；查詢時間條件以**投注時間**為準 |

Base URL：

| 環境 | URL |
|---|---|
| 測試（Staging） | `https://example.invalid/v2_2` |
| 正式（Production） | `https://example.invalid/v2_2` |

---

## 高風險陷阱

### 1. `deposit_amount` / `withdraw_amount` 類型：Float → String（v2.3.7 已改）

v2.3.7（2025-07-18）更新日誌明載 `deposit_amount` / `withdraw_amount` **從 Float 改為 String**。  
現行實作仍傳 `(float) $params->vendorAmount`：

```php
// 現行實作（有問題）
'deposit_amount' => (float) $params->vendorAmount,

// 應改為
'deposit_amount' => Common::numAdd('0', (string) $params->vendorAmount, 4),
```

多數 JSON 解析器接受數字格式，但若 LFC 端嚴格驗型別（或對小數精度敏感），會回 `6107`/`6108`。  
建議改傳已正規化的字串金額，並同步修正入口端的 `Common::num*` 正規化。

### 2. `checkTransferStatus` 只看 `status.code`，未區分 `pending` vs `transferred`

04-05 Get transaction status 的 `data.status` 有兩個值：
- `transferred`：交易**已完成**
- `pending`：交易**仍在途**（未最終結算）

現行 `checkTransferStatus()` 實作：只要 `status.code === 1000` 就回 `VendorTransferResultEnum::SUCCESS`。  
**未確認 `pending` 時 `status.code` 是否也為 `1000`**。若是，則在途交易會被誤判為「已成功」，導致補單情境下對在途交易的錯誤處理。

建議確認後在 `status.code === 1000` 時再加判 `data.status === 'transferred'`，`pending` 改回 `TIMEOUT`（讓上層重試）。

### 3. UTC-4 時區換算陷阱

LFC 的所有時間（`status.time`、報表欄位 `bet_time`、`end_time`、`result_time`）均為 **UTC-4**（美東），使用 RFC3339 格式如 `2021-05-18T19:03:59-04:00`。

本平台 DB 預設以 UTC 儲存。對帳時若直接比較時間字串會有 4 小時落差，必須換算。  
報表 API 不可跨日查詢（以 UTC-4 日界為準），換算後的查詢起訖也需對應調整。

### 4. `plat_username` 不納入 `check_code`（已知，但代碼意圖有誤）

`getCheckCode()` 對全部送出參數做 `ksort` 並全部納入簽章。LFC 文件規定 `plat_username` 排除在 `check_code` 計算之外。  
**目前 `getGameUrl()` 未送出 `plat_username`，故無實際衝突**，但代碼意圖有誤——若日後新增 `plat_username` 傳遞，需在計算前先 `unset`，否則簽章驗證會失敗（錯誤碼 `4103`）。

---

## 錢包流程（Transfer Wallet）

```
玩家進入遊戲：
  代理 → 04-04 Withdraw（先把 LFC 端殘留餘額提回，避免重複計）
  代理 → 06-01 Launch game（取得 game_url）
  代理 → 04-03 Deposit（把玩家額度儲值進 LFC）
  玩家 → 進入 game_url 遊玩

玩家離開遊戲：
  代理 → 04-04 Withdraw（把 LFC 端餘額提回代理）

逾時補救：
  代理 → 04-05 Get transaction status（以 external_order_id 回查最終狀態）
         ↳ status = transferred → SUCCESS
         ↳ status = pending     → 等待後重查（勿視為成功）
         ↳ 404 / status.code ≠ 1000 → FAILED
```

### 轉帳狀態判定

| 情況 | `status.code` | `data.status` | 平台判定（建議） |
|---|---|---|---|
| 成功完成 | `1000` | `transferred` | `SUCCESS` |
| 在途（未結算） | `1000`（待確認）| `pending` | `TIMEOUT`（重試） |
| 業務失敗 | `6101`/`6102` 等 | — | `FAILED` |
| 逾時 / 無回應 | 無陣列回應 | — | `TIMEOUT` |

> `external_order_id` 必須唯一；重複送出會收到 `6104`。逾時**禁止直接重打**，改用 04-05 回查。

---

## 冪等性

- `external_order_id` 是轉帳冪等保護的核心，每筆 deposit / withdraw 都必須帶入唯一值。
- 重複使用相同 `external_order_id` 打 deposit/withdraw → `6104`（已存在），不可作為「重試成功」判斷。
- 逾時後必須以 04-05 Get transaction status 回查 `data.status`，確認 `transferred` 才視為成功。
- 報表 API（07-01 ~ 07-05）以投注時間為查詢基準，不可跨日；在保留期（90 天）內每日拉取寫入 `lfc888_bet_records`。

---

## 整合落地建議

| LFC888 概念 | APIs 專案對應 |
|---|---|
| Vendor ID | `VendorIdEnum::LFC888 = 100`（`app/Enums/Vendor/VendorIdEnum.php`） |
| Driver 路徑 | `app/Vendors/Lfc888/Driver.php`（繼承 `AbstractTransferWallet`） |
| Driver 註冊 | `config/vendor.php`：`VendorIdEnum::LFC888->value => Vendors\Lfc888\Driver::class` |
| 帳號 Model | `App\Vendors\Models\Lfc888Account` |
| 注單 Model | `App\Vendors\Models\Lfc888BetRecord` |
| `API_URL` | `vendor_infos.info.API_URL`（Driver 取後 `trim(..., '/')`) |
| `account_id` | `vendor_infos.info.PLATFORM_ID` |
| `security_code`（簽章金鑰） | `vendor_infos.info.SECURITY_CODE` |
| `currency` | `vendor_infos.info.CURRENCY`（正式站 MYR） |
| 遊戲 URL | `getGameUrl()` → `06-01 /agent/user_login` |
| 餘額查詢 | `getBalance()` → `04-02 /user/get_balance`（餘額以 `Common::numFloor($balance, 4)` 正規化） |
| 存款（transferToVendor） | `04-03 /user/deposit_amount` |
| 提款（transferToLocal） | `04-04 /user/withdraw_amount` |
| 轉帳驗證（checkTransferStatus） | `04-05 /user/get_transaction_status` |
| 注單同步 | `07-01 ~ 07-05` 報表 API，逐日拉取（不可跨日） |
| 對帳保留期 | **90 天**；查詢條件以投注時間為準 |
| 時區換算 | LFC 時間為 UTC-4，DB 儲存 UTC，需換算 |

### 遊戲類型（`game_type`）

`fish`（捕魚機）、`table`（牌桌）、`p2p`（對戰棋牌）、`slot`（老虎機）、`arcade`（街機）、`live`（視訊）

### 語系對照（Driver `mapLanguageCode`）

| 專案 LanguageEnum | LFC `lang` |
|---|---|
| ZH_TW | `zh-tw` |
| ZH_CN | `zh-cn` |
| ID_ID | `id` |
| TH_TH | `th` |
| 其他 | `en`（預設） |

---

## Gaps / 待 Lfc888 / BT Gaming 確認

1. **`pending` 時的 `status.code` 是否為 `1000`？** 影響 `checkTransferStatus` 是否需細分 `data.status`（見高風險陷阱 §2）。待客服確認。
2. **`deposit_amount` / `withdraw_amount` 嚴格要求字串格式嗎？** v2.3.7 已改為 String，確認 LFC 端是否仍接受數字格式（見高風險陷阱 §1）。
3. **`plat_username` 若需傳遞，`check_code` 排除方式是否如文件所述？** 目前未送出此參數，暫無衝突，但日後擴充需確認。
4. **正式 Base URL 完整性**：正式站 `https://example.invalid/v2_2` 來自 `商戶資訊.txt`，文件正文以 `<API URL>` 佔位，未直接載明；以憑證檔為準。
5. **07-03 Query player finances 完整欄位**：本份文件未逐欄展開，需完整欄位請查原始 PDF 章節 07-03。
