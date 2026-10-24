# Vpower（vendor_id=22）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 品牌名 | Stargame |
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 Vpower 端保管，進出場各轉一次 |
| 請求方向 | **本平台 → Vpower**（代理主動呼叫，GET + Signature）；無回調 |
| 驗證 | `AppId` + IP 白名單 + 每筆請求 `Signature`（HMAC-SHA1 → Base64 → URL Encode） |
| 成功判定 | `ErrorCode == 0`（`getacc` 以 `1=New User created` 為成功） |
| 轉入 | `creditxf`（Amount 正值）→ `transferToVendor()` |
| 轉出 | `creditxf`（Amount 負值）→ `transferToLocal()` |
| 轉帳查單 | `creditcheck`（Act=creditcheck）→ `checkTransferStatus()` |
| 登入 URL | `open_game?id=<gameid>&token=[REDACTED] exitUrl>` |
| Deep link | `vpower://token=[REDACTED] / Android 啟動 Stargame App） |
| vid / SW 版 | vid=22 為轉帳錢包；**vid=117（VpowerSW）為另一個獨立的單一錢包接入，本卡不涵蓋** |

## 高風險陷阱

### 1. `ErrorCode=21` 坍縮為 FAILED（最高風險）

Driver `transfer()` 在 `ErrorCode != 0` 時一律回 `VendorTransferResultEnum::FAILED`，包含 `ErrorCode=21`（Player in an active gaming session）。

但 `21` 在**提款方向**（`transferToLocal`）是**可重試 / 待處理**狀態，官方建議先 `signout` 踢玩家再扣款。目前被當永久失敗，可能造成玩家餘額卡在 Vpower 端無法領回。

> ⚠️ 上線前必須確認上層對 FAILED 的重試策略，或補實作 `signout` + 重試邏輯。

### 2. `signout` 未實作

Driver 未呼叫 `signout`，提款前未強制踢玩家，依賴 Vpower 端自行判定是否在遊戲中。與陷阱 §1 連動。

### 3. `RequestID` 格式未驗證

官方規定 `RequestID = Username + Timestamp(ms) + 4 random digit`。Driver 直接以訂單 `transactionNo` 當 `RequestID`，若 `transactionNo` 不符 Vpower 格式 / 長度限制，將回 `ErrorCode=7（RequestId Format Error）`。

### 4. PHP 簽章範例 Bug（官方文件錯誤）

V1.0.17 文件 §Signature 的 PHP 範例把 `hash_hmac` 參數順序寫錯（演算法名放第 2 位，應放第 1 位）。**本專案實作已採正確順序**，但此錯誤可能誤導後續維護者。見 `api.md §簽章` 說明。

### 5. 金額精度未正規化

Driver 以 `$amount = 0 + $credit`（原生數值運算）組 `creditxf` 的 `Amount` 參數，未經 `Common::numAdd` 正規化。雖僅組 query string，仍建議確認是否符合 Vpower「2 位小數」要求。

### 6. `lang` 未帶入 Game URL

`getGameUrl()` 計算 `$language` 但組 `open_game` query 時只帶 `id`/`token`/`back`，未帶 `lang`，遊戲 UI 語言預設 English。

### 7. `getacc` 未實作

Driver 假設玩家帳號已存在，未呼叫 `getacc`（`getacc` 本身具「不存在則自動建立」特性）。需確認 `VpowerAccount` 建立時機。

## 轉帳錢包流程

```
tkreq  ── 為玩家取 Token（有效 7 天）
  │
creditxf(正值)  ── 本地餘額轉入 Vpower  ─▶ transferToVendor()
  │
組 Game URL：<Game URL>/open_game?id=<gameid>&token=[REDACTED] exitUrl>
  │
玩家遊戲
  │
（可選）signout  ── 踢玩家（避免提款時 ErrorCode=21）
  │
creditxf(負值)  ── 提回 Vpower 餘額到本地  ─▶ transferToLocal()
```

逾時回 `TIMEOUT`，由 `checkTransferStatus()`（`creditcheck`）確認最終結果：

| `state` | 意義 | Driver 映射 |
|---|---|---|
| `0` | 未處理 | `TIMEOUT` |
| `1` | 成功 | `SUCCESS` |
| `2` | 失敗 | `FAILED` |

## 冪等性

- `creditxf` 的 `RequestID` 為冪等鍵；重送相同 `RequestID` 得 `ErrorCode=6`。
- 目前 Driver 以 `transactionNo` 為 `RequestID`（見陷阱 §3）。
- `creditcheck` 按 `RequestID` 查單，`List` 空回 `FAILED`，無 JSON 回應回 `TIMEOUT`。

## 整合落地建議

| Vpower 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Vpower/Driver.php`（`AbstractTransferWallet`） |
| 轉帳入口 | `transferToVendor()` / `transferToLocal()` → `transfer()` |
| 轉帳查單 | `checkTransferStatus()`（`Act=creditcheck`） |
| 餘額查詢 | `getBalance()`（`Act=getbal`） |
| 登入 URL | `getGameUrl()`（`Act=tkreq` 取 Token → 組 `open_game` URL） |
| 玩家帳號 Model | `App\Vendors\Models\VpowerAccount`（`username`） |
| 注單 Model | `App\Vendors\Models\VpowerBetRecord` |
| Callback controller | **無**（轉帳錢包無回調；SW 版 vid=117 才有） |
| External 路由 | **無**（轉帳錢包全為主動呼叫） |
| `AppId` | `vendor_infos.info.API_APPID` |
| `SecretKey`（簽章金鑰） | `vendor_infos.info.API_KEY` |
| API URL（`/open_api`） | `vendor_infos.info.API_URL` |
| Game URL（`/open_game`） | `vendor_infos.info.API_GAMEURL` |
| Deep link / App 下載 | `IOS_DOWNLOAD` / `ANDROID_DOWNLOAD` |
| 對帳 / 報表 | `glog`（注單明細）、`gloggrp`（5 分鐘彙總）、`dailysum`（每日彙總）、`hoursum`（每小時彙總） |
| `glog` 限制 | 每頁最多 200 筆、單次上限 20,000 筆、時間範圍最長 7 天 |
| IP 白名單 | 代理出口 IP 須向 Vpower 加白（`vendor_infos.info` 無此欄位，需在 Vpower 後台設定） |

## Gaps / 待確認

- 正式 API URL / Game URL domain、APP Server IP 白名單清單（文件以佔位符表示，由 Vpower 客服提供）
- `creditxf` 逾時後以相同 `RequestID` 重送的語意（是否冪等回原結果，或直接回 `6`）
- `transactionNo` 是否符合 Vpower 的 `RequestID` 格式要求（見陷阱 §3）
- 提款方向 `ErrorCode=21` 的重試策略（見陷阱 §1 / §2）
- 報表 / 注單資料的**保留期限**（文件未明確說明）
- `creditcheck` 省略空欄位 vs 帶空值，Vpower 端驗章是否一致（見 `api.md §簽章` 明文順序說明）
- `glog` 分頁上限 100 或 200（文件前後矛盾，見 `api.md`）
- `getacc` 回傳 `ErrorCode=0` vs `1` 的成功判定（文件 `updatepwd` 章節亦有類似矛盾，見 `api.md`）
