# Haolong（vendor_id=4）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| **vendor_id** | `4`（`VendorIdEnum::HAOLONG`） |
| **錢包模式** | Transfer Wallet（`AbstractTransferWallet`） |
| **品牌別名** | 豪龍 / 28Win |
| **遊戲類型** | 4D / 多寶彩票（Big-Small / 3D / 5D / 6D 等） |
| **簽名演算法** | **無**（使用 `apiUser` / `apiPass` 帳密認證） |
| **Content-Type** | `application/x-www-form-urlencoded`，POST |
| **回應格式** | **XML**（`<response>` 根節點），非 JSON |
| **Base URL** | `http://example.invalid`（**HTTP only，無 TLS**） |
| **成功判定** | `errorCode == 0`（整數比較） |
| **時區** | 文件未明載，待確認 |
| **玩家密碼（vworld 舊帳號）** | 固定 `!Pa$$w0rd` |
| **語言支援** | 僅 `zh-cn` / `en-us`（ZH_TW/ZH_CN → `zh-cn`，其餘 → `en-us`） |
| **Callback Controller** | **無**（Transfer Wallet，無回調路由） |
| **對帳資料保留期** | 官方文件未載明，待確認 |

---

## 高風險陷阱

### 1. 回應為 XML，非 JSON

Haolong 所有 API 回傳 XML，根節點為 `<response>`，與本平台其他遊戲商（多為 JSON）**完全不同**。實作端以 `simplexml_load_string()` 解析。

**影響**：任何直接用 `->json()` 或 `json_decode()` 解析 Haolong 回應的程式碼都會靜默失敗。對帳、整合測試須使用 XML 解析。

---

### 2. `balance` 帶千分位逗號，必須 strip 後才能運算

官方範例 `<balance>16,500.00</balance>`——含千分位逗號 `,`。

**影響**：若未去逗號直接轉型（如 `(float)` 或 `bcadd`），PHP 會截斷逗號後的數字，造成金額錯誤。

**實作處理**：`getBalance()` 中 `str_replace(',', '', $balance)` 後再 `Common::numFloor($balance, 4)`。

**延伸風險**：目前只有 `getBalance()` 做了去逗號處理。若對帳報表中 `winLoss.total`、`betData.bet`、`betData.strike` 等欄位也帶逗號並進入運算，須同樣正規化，否則違反「禁止外部 float 直接使用」規範。

---

### 3. `createPlayer.aspx` 未在 Driver 被呼叫

官方文件第 2 支 API `createPlayer.aspx` 用於建立下線玩家戶口，但 `app/Vendors/Haolong/Driver.php` 中無對應呼叫。

**影響**：玩家開戶流程不明。若 `AbstractTransferWallet` 的通用流程或其他機制已涵蓋，則無問題；若實際上需要先建立 Haolong 帳號，則現有流程可能跳過此步驟，導致後續 `deposit`/`betLogin` 失敗。

**建議**：確認玩家首次進入 Haolong 的帳號建立流程。

---

### 4. HTTP only，無 TLS

`API_URL` 為 `http://`，不加密傳輸。`apiUser`/`apiPass` 及 `user`/`pass` 認證帳密以明文在網路傳遞。

**建議**：確認廠商是否提供 HTTPS endpoint；若長期僅 HTTP，log 記錄認證欄位時須確保 mask。

---

### 5. 同一 `errorCode` 在不同 API 語意不同

錯誤碼 `3`、`4`、`5` 在不同 API 下代表截然不同的意義（例：`4` 在 CreatePlayer 是「Login ID 已存在」，在 Deposit/Withdraw 是「Invalid Account」）。

**影響**：僅靠 `errorCode` 判斷失敗原因不足；需同時參考 `<desc>`，並依 API context 解讀。

---

## 錢包流程（Transfer Wallet）

```
玩家進入遊戲
     │
     ▼
deposit.aspx（transferToVendor）── 把本地餘額轉入 Haolong
     │
     ▼
betLogin.aspx ── 取得 sessionID + tokenCode
     │
     ▼
bet.aspx?user&lang&sessionID&tokenCode ── 進入投注廳（PC）
API_HTML5_URL?...（同參數） ── H5 行動版
     │
   （玩家投注 / 開彩）
     │
     ▼
withdraw.aspx（transferToLocal）── 把 Haolong 餘額轉回本地
```

### 進入遊戲的兩步驟（重要）

`getGameUrl()` 不是單一呼叫，而是二步驟：

1. 呼叫 `betLogin.aspx` → 取得 `sessionID` + `tokenCode`
2. 以這兩個 token 組裝 `bet.aspx?user=...&lang=...&sessionID=...&tokenCode=...`

### 轉帳結果三態

| 情況 | 回傳 `VendorTransferResultEnum` |
|---|---|
| `errorCode == 0` | `SUCCESS` |
| `errorCode != 0`（明確失敗） | `FAILED` |
| 無回應 / 無 `errorCode`（不確定） | `TIMEOUT`（不還錢，等人工確認） |

### 各 API 與實作對應

| 階段 | API | 實作方法 |
|---|---|---|
| 存款 | `deposit.aspx` | `transferToVendor()` |
| 提款 | `withdraw.aspx` | `transferToLocal()` |
| 取遊戲 URL | `betLogin.aspx` → 組 `bet.aspx` / H5 URL | `getGameUrl()` |
| 查餘額 | `getProfile.aspx` | `getBalance()` |
| 改密碼 / 狀態 | `changePasswordStatus.aspx` | `resetPassword()` |

---

## 冪等性

Haolong 文件未提供轉帳 unique key 機制（無類似 `ExternalTransactionId` 的欄位）。`deposit.aspx` / `withdraw.aspx` 回應不含可供追蹤的交易 Id。

**影響**：TIMEOUT 後無法用廠商 API 查單確認狀態，只能靠內部 DB 狀態與人工核對餘額差異。

**建議**：確認廠商是否有補單/查單機制。

---

## 整合落地建議

### vendor_infos.info 欄位對應

| info 欄位 | 對應廠商概念 | 說明 |
|---|---|---|
| `API_URL` | `http://example.invalid` | API base URL |
| `API_HTML5_URL` | H5 投注廳 URL | 組 H5 遊戲連結用 |
| `API_USER` | `apiUser` | API 等人帳號 |
| `API_PASSWORD` | `apiPass` | API 等人密碼 |
| `HAOLONG_USER` | `user`（代理帳號） | 管理型 API 的代理帳號 |
| `HAOLONG_PASSWORD` | `pass`（代理密碼） | 管理型 API 的代理密碼 |
| `USE_PREFIX` | 是否加代理前綴 | `1` = 在 `loginID` 前加 `HAOLONG_USER`；預設 `1` |

### 關鍵路徑

| 項目 | 路徑 |
|---|---|
| Driver | `app/Vendors/Haolong/Driver.php` |
| Account Model | `app/Vendors/Models/HaolongAccount.php` |
| BetRecord Model | `app/Vendors/Models/HaolongBetRecord.php` |
| Driver 註冊 | `config/vendor.php`（`VendorIdEnum::HAOLONG->value => Vendors\Haolong\Driver::class`） |
| Callback Controller | **無**（Transfer Wallet，無 `routes/external/sw.php` 條目） |

### 廠商概念 → APIs 專案對應

| 廠商概念 | APIs 對應 |
|---|---|
| `apiUser` / `apiPass` | `vendor_infos.info.API_USER` / `.API_PASSWORD` |
| `user` / `pass`（代理） | `vendor_infos.info.HAOLONG_USER` / `.HAOLONG_PASSWORD` |
| `loginID`（下線玩家） | `haolong_accounts.username`（視 `USE_PREFIX` 加前綴） |
| `balance`（帶千分位逗號） | `str_replace(',', '', ...)` → `Common::numFloor(x, 4)` |
| vworld 舊帳號密碼 | `is_vworld=1` → 固定 `!Pa$$w0rd` |
| 語言代碼 | `ZH_TW`/`ZH_CN` → `zh-cn`；其餘 → `en-us` |
| 注單對帳 | `betlist.aspx`（投注明細）、`winLoss.aspx`（輸贏彙總）、`winNumber.aspx`（中獎）、`result.aspx`（開彩） |
| XML 解析 | `simplexml_load_string($response)` |

---

## Gaps / 待 Haolong 確認

| # | 問題 | 影響 |
|---|---|---|
| 1 | `createPlayer.aspx` 玩家開戶流程——Driver 未呼叫，但玩家如何在 Haolong 端建立帳號？ | 玩家首次進入遊戲能否成功 |
| 2 | 是否提供 HTTPS endpoint？目前文件只有 `http://` | 資安合規 |
| 3 | 轉帳是否有唯一 transaction key 可供補單/查單？ | TIMEOUT 後的冪等補償 |
| 4 | 報表資料（`betlist` / `winLoss` 等）保留多久？ | 對帳時效 |
| 5 | 報表欄位（如 `winLoss.total`、`betData.strike`）是否也帶千分位逗號？ | 金額正規化範圍 |
| 6 | `result.aspx`（開彩成績）完整回傳欄位——官方文件無範例 | 對帳欄位設計 |
| 7 | 系統時區（伺服器端時間欄位使用哪個時區） | 對帳時間換算 |
| 8 | `loginID` 在 `getProfile.aspx` 是選填；不傳時回傳的是代理帳號資料還是錯誤？ | `getBalance()` 呼叫方式 |
