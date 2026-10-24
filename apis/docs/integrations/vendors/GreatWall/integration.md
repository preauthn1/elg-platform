# GreatWall（vendor_id=5）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| **vendor_id** | `5`（`VendorIdEnum::GreatWall`） |
| **錢包模式** | Transfer Wallet（`AbstractTransferWallet`） |
| **品牌別名** | GW99 / iGaming |
| **簽名演算法** | 參數依 key 升冪排序 + `&PrivateKey=<key>` → SHA256 raw bytes → Base64 |
| **HTTP Header** | `merchantName`（商戶名）、`signature`（簽名） |
| **Content-Type** | `application/json`，POST |
| **Base URL（測試）** | `http://example.invalid` |
| **Base URL（正式）** | `http://example.invalid` |
| **成功判定** | `result.code == "0"`（字串比較） |
| **TIMEOUT 觸發** | 回應中 `code` 欄位**不存在** |
| **時區** | UTC+8（推測，3.14 註記佐證；各欄位未逐一標注） |
| **玩家帳號格式** | `{API_AGENT}{username}`（本名限 1-9 字元；其餘 API 須傳完整帳號） |
| **Callback Controller** | **無**（Transfer Wallet，無回調路由） |
| **對帳資料保留期** | 交易記錄 1 個月；遊戲記錄延遲 10-30 分鐘 |

---

## 高風險陷阱

### 1. 成功碼 `"0"` vs `"00000"` — 字串比較陷阱

文件不同 API 範例中，成功碼出現 `"0"`（多數 API）與 `"00000"`（3.4 玩家列表範例）兩種寫法。

**影響**：PHP 嚴格比較（`===`）下 `'00000' === '0'` 為 `false`。若廠商真的對某些 API 回傳 `"00000"`，實作的 `code == '0'` 判斷（PHP 鬆散比較）同樣為 `false`（`'00000' == '0'` 也是 `false`），導致誤判成失敗。

**現況**：本專案未使用 3.4，影響面有限。但若未來串接其他 API 出現非 `"0"` 的成功碼，需特別留意。

**建議**：向廠商客服確認統一成功碼值。

---

### 2. `http_build_query` 可能 URL-encode PrivateKey 特殊字元

`getSign()`（Driver.php:255-266）使用 `http_build_query($data)` 串接參數，這會對特殊字元做 URL encode（例如 `%` → `%25`、`@` → `%40`）。

**影響**：官方簽名範例（`wS%EVgkS2b4sU0@zdiGN`）的明文為**未 encode** 的原始字串。若實際 PrivateKey 含特殊字元，`http_build_query` encode 後的字串與廠商期望的明文不一致，會導致 `-00002 加密簽名無效`。

**現況**：目前測試/正式 PrivateKey 中若無特殊字元則無症狀。已知風險，列為待確認項。

---

### 3. `-90608 尚未處理` 被 `checkTransferStatus` 歸為 FAILED

`checkTransferStatus()`（Driver.php:200-224）將非 `"0"` 的所有 code 一律歸 `FAILED`，包含 `-90608 尚未處理`。

**影響**：文件中 `-90608` 語意為「pending」——交易仍在處理中，尚未有最終結果。若補償查單時交易仍 pending，會被誤判為失敗，可能導致不必要的退款或重試。

**建議**：確認是否應將 `-90608` 視為需重試的 TIMEOUT，而非 FAILED。

---

### 4. `Player/Info` 頻率限制（非官方文件，來自營運經驗）

實作（Driver.php:139-144）限制每 5 分鐘最多呼叫 10 次，超過第 11 次拋 `ApiException`。此限制源自 target/tickets work_item 3758 的營運經驗，官方 PDF 7.9 未明文列出。若邏輯依賴頻繁查餘額需特別注意。

---

## 錢包流程（Transfer Wallet）

```
進遊戲（存款 / 開分）：
  代理錢包餘額 → transferToVendor() → POST /Api/IGame/Transaction/Create (Amount 為正)
                                       ↓
                             ┌─ code=="0" ──────────→ SUCCESS
                             ├─ code 存在且非"0" ──→ FAILED（退款）
                             └─ code 不存在 ────────→ TIMEOUT → checkTransferStatus()

出遊戲（提款 / 洗分）：
  遊戲商餘額 → transferToLocal() → POST /Api/IGame/Transaction/Create (Amount 為負)
                                    ↓（同上三態判斷）

查餘額：getBalance() → POST /Api/IGame/Player/Info（每 5 分鐘限 10 次）

取遊戲入口：getGameUrl() → POST /Api/IGame/Appurl → 組 deeplink lobbygw99://...

補償查單：checkTransferStatus() → POST /Api/IGame/Transaction/Check
```

### TIMEOUT 補償（checkTransferStatus）

| 查單結果 | 處理 |
|---|---|
| `code == "0"` | SUCCESS |
| `-90608` 尚未處理 | 目前歸 FAILED（見高風險陷阱 #3，語意應為 pending） |
| `-90609` 交易不存在 | FAILED |
| 其他非 "0" | FAILED |

### 轉帳金額限制

| 方向 | 範圍 |
|---|---|
| 開分（Amount 正數） | 10 ~ 200,000 |
| 洗分（Amount 負數） | -10 ~ -20,000 |

---

## 冪等性

| 冪等 key | 欄位 | 說明 |
|---|---|---|
| 轉帳唯一性 | `ExternalTransactionId`（Varchar 20） | 廠商端去重；重複送相同 Id 會回 `-90610 外部交易 Id 在 API 系統重複` |
| 交易 Id 產生 | `getTransactionNo()` | `ymdHisv` + 4 碼亂數，≤ 20 字元 |
| TIMEOUT 後重送 | 不重送相同 Id | 改以 `Transaction/Check` 查狀態；不退款 |

---

## 整合落地建議

### vendor_infos.info 欄位對應

| info 欄位 | 對應廠商概念 | 說明 |
|---|---|---|
| `API_URL` | Base URL | 正式 `http://example.invalid`（實作自動 `rtrim('/')` 去尾斜線） |
| `API_MERCHANT_NAME` | `merchantName` Header | 商戶名（非 `API_AGENT`） |
| `API_AGENT` | 代理前綴 | 組完整玩家帳號：`API_AGENT . username` |
| `API_KEY` | PrivateKey | 簽名金鑰，附在簽名字串最尾端 |

### 關鍵路徑

| 項目 | 路徑 |
|---|---|
| Driver | `app/Vendors/GreatWall/Driver.php` |
| Account Model | `app/Vendors/Models/GreatWallAccount.php` |
| BetRecord Model | `app/Vendors/Models/GreatWallBetRecord.php` |
| Driver 註冊 | `config/vendor.php`（`VendorIdEnum::GreatWall->value => Vendors\GreatWall\Driver::class`） |
| Callback Controller | **無**（Transfer Wallet，無 `routes/external/sw.php` 條目） |

### 廠商概念 → APIs 專案對應

| 廠商概念 | APIs 對應 |
|---|---|
| `merchantName` Header | `vendor_infos.info.API_MERCHANT_NAME` |
| `signature` Header | `Driver::getSign()` 產生 |
| `PlayerAccount`（完整帳號） | `API_AGENT . username` |
| `ExternalTransactionId` | `Driver::getTransactionNo()` 產生 |
| 開分（Amount > 0） | `transferToVendor()` |
| 洗分（Amount < 0） | `transferToLocal()` |
| App deeplink | `lobbygw99://lobbygw99?account={完整帳號}&password=[REDACTED] |
| H5 登入 token | `/Api/IGame/Player/Token`（3.19）**目前未串接** |
| 注單對帳 | `/Api/IGame/Game/Log/Agent`（3.14，延遲 10-30 分） |
| 交易記錄 | `/Api/IGame/Transaction/Log`（3.11，保留 1 個月） |

### 登入方式

- **App**：deeplink `lobbygw99://lobbygw99?account={完整帳號}&password=[REDACTED]
- **H5**：需先呼叫 3.19 取 `altoken`，組合 `https://example.invalid/GW99.html?altoken=[REDACTED] 5 分鐘）。目前 `getGameUrl()` 未實作此流程。

---

## Gaps / 待 GreatWall 確認

| # | 問題 | 影響 |
|---|---|---|
| 1 | IP 白名單是否需要？PDF §1 說需要，`注意事項.txt` 說不需要 | 環境配置 |
| 2 | 成功碼是否統一為 `"0"`？3.4 範例中出現 `"00000"` | 字串比較邏輯 |
| 3 | `-90608 尚未處理` 應視為 pending 還是 FAILED？ | checkTransferStatus 處理邏輯 |
| 4 | `http_build_query` 對 PrivateKey 特殊字元 URL-encode 是否合規？ | 簽名正確性 |
| 5 | H5 token（3.19）流程：`getGameUrl` 是否應補串 `/Api/IGame/Player/Token`？ | H5 登入支援 |
| 6 | `Player/Info` 5 分鐘 10 次頻率限制是否為官方規格？ | 查餘額限流設計 |
| 7 | 支援幣別清單與 `Amount` 小數位數上限 | 金額精度 |
| 8 | `WinAmount`（3.14）不含紅包，紅包需從 3.11 `OperationMethod=3` 加總——確認此為設計而非 bug | 對帳準確性 |
