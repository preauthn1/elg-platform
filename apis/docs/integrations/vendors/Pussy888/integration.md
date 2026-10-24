# Pussy888（vendor_id=23）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | Transfer Wallet；玩家餘額由 Pussy888 端持有，代理透過 setScore 轉入/轉出 |
| 回調方向 | **無**（Transfer Wallet，Pussy888 不回調本平台） |
| HTTP Method | GET query string（`account.ashx?action=...`） |
| 驗證 | `authcode`（授權碼）+ `sign`（MD5 簽名）；無 IP 白名單（待確認） |
| 成功判定 | `success === true`（主判）；`code == 0` 為輔（setScore 的 code 存在文件不一致，見下） |
| 時區 | 文件未明示，待確認 |
| 兩組 Base URL | `API_URL1`（設分 / 帳號 / 列表）；`AipUrl2`（報表 / 明細）— 報表尚未實作 |

## 高風險陷阱

### 1. setScore 成功碼矛盾（高風險）

文件 §三的 Code 表標示 **`code:0` = 成功**，但同一頁的回應範例卻是 `{"code":1,"success":true}`（`code:1` 與 `success:true` 並存）。

現行實作（`Driver.php`）以 `success === true` 且 `code == 0` 判成功——若三方實際回 `code:1` 則會被誤判為失敗。

> **上線前須向客服確認 setScore 成功的 `code` 真實值**（`0` 或 `1`），並對齊實作。

### 2. -3 / -4 / -6 應視為 TIMEOUT 而非 FAILED（高風險）

setScore 錯誤碼：

| Code | 語意 | 正確處理 |
|---|---|---|
| `-3` | 正在設分，稍後 | TIMEOUT → 查單確認 |
| `-4` | 設分太頻繁，稍後 | TIMEOUT → 查單確認 |
| `-6` | 系統異常，稍後 | TIMEOUT → 查單確認 |

現行 `transfer()` 對 `code != '0'` 一律拋 `ApiException`（上層判 FAILED），把這三個「不確定態」當成確定失敗，存在「實際成功卻判失敗」的資損風險。

> 應比照 Pussy888 文件語意，將 `-3/-4/-6` 改走 `VendorTransferResultEnum::TIMEOUT`，再由上層透過 `checkTransferStatus` 查單。

### 3. AipUrl2 未配置（報表對帳）

文件明確區分 `AipUrl1`（設分 / 帳號 / 列表）與 `AipUrl2`（報表 / 明細 / 查分）。現行 Driver 只讀取 `API_URL1`，報表類 API 尚未實作；若接入對帳，需補 `AipUrl2` 配置（`vendor_infos.info.API_URL2`）。

## 錢包流程

```
本地存款
  │
  ▼
transferToVendor(type=1)
  → setServerScore(scoreNum=+credit)  → 玩家在 Pussy888 端加分
  │
  ▼
玩家遊玩（餘額在 Pussy888 端）
  │
  ▼
transferToLocal(type=2)
  → setServerScore(scoreNum=-credit)  → 玩家在 Pussy888 端扣分
```

- `scoreNum > 0`：存入（`transferToVendor`）
- `scoreNum < 0`：取出（`transferToLocal`）
- 餘額查詢：`getUserInfo` → 取 `MoneyNum`

## 簽名注意事項

- 主流程：`strtoupper(md5(strtolower(authcode + userName + time + secretKey)))`
- `AreaMoney`（設分查詢）簽名例外：`loginUser` 放在最前面
- 文件只規範輸入轉小寫，**未明示輸出大小寫**；實作輸出大寫，已通過驗證，勿改

## 實作細節

### transfer() 的 username 覆蓋行為

`transfer()` 入口收到 `$username`，但第 140 行以 `$this->vendorAccount->player_id` 覆蓋；呼叫端實際傳入的是 `username`。若 `player_id` 與 `username` 在資料上一致則無影響；否則需確認 `pussy888_accounts` 兩欄關係。

### billNo 未帶入請求

`transfer()` 收 `$billNo`（= `transactionNo`）但未帶入 GET 參數。文件 setScore 無訂單號欄位（`-1` 訂單錯誤暗示後端可能自行生成），無代理側冪等鍵；冪等性依賴 `AbstractTransferWallet` 上層機制。

## 整合落地建議

| Pussy888 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Pussy888/Driver.php`（繼承 `AbstractTransferWallet`） |
| Vendor 註冊 | `config/vendor.php`：`VendorIdEnum::PUSSY->value => Vendors\Pussy888\Driver::class` |
| Callback / Routes | 無（Transfer Wallet 不需回調） |
| `authcode` | `vendor_infos.info.API_AUTHCODE` |
| `secretKey` | `vendor_infos.info.API_SECRETKEY` |
| `AipUrl1`（設分 / 帳號 / 列表） | `vendor_infos.info.API_URL1` |
| `AipUrl2`（報表 / 明細，待補） | `vendor_infos.info.API_URL2`（未配置） |
| H5 遊戲入口 | `vendor_infos.info.H5_URL` |
| App 下載連結 | `vendor_infos.info.DOWNLOAD_URL` |
| addUser 上級代理 | `vendor_infos.info.AGENT_NAME` |
| 玩家帳號 | `pussy888_accounts.player_id` / `username` |
| IP 白名單 | 文件未提及，上線前確認是否需報備出口 IP |
| 對帳 API | `GameLog` / `UsersscoreLog` / `AccountReport`（均走 AipUrl2，尚未實作） |
| 對帳保留期 | 文件未載明，待向客服確認 |

## Gaps / 待確認

- **setScore 成功 `code` 值**：`0` 還是 `1`（文件 Code 表 vs 回應範例不一致）
- `-3/-4/-6` 是否應走 TIMEOUT 並查單（高風險，見上方陷阱 §2）
- `editUser` / `ChangePwd` 的 DES 加密金鑰與模式（文件僅標「DES 加密」，未載演算法參數）
- IP 白名單要求（文件未提，正式上線前向客服確認）
- 對帳保留期（文件未載明）
- `GameLog` 回應欄位（PDF 第 13 頁被截斷，無完整欄位範例）
- `timestamp` 是否有時效窗驗證（文件 13 位毫秒，實作未明示採幾位）
- `pussy888_accounts.player_id` vs `username` 兩欄實際是否一致
