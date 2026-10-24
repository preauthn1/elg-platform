# Scr（vendor_id=3）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 遊戲商全名 | SCR（918KISS） |
| 錢包模式 | Transfer Wallet（轉帳錢包）；918 端持有玩家餘額 |
| 遊戲啟動 | **APP only**，無 web / H5；玩家需安裝 918KISS APP |
| 驗證方式 | IP 白名單 + MD5 `sign` 欄位 |
| 成功判定 | `code === '0'`（但見下方 CRITICAL 風險） |
| 回調方向 | **無**（Transfer Wallet 不需提供 callback endpoint） |
| Base URL | Port 9991（主 API） / Port 9919（getOrder 查單）；正式 host 由業務提供 |
| 實作 Driver | `app/Vendors/Scr/Driver.php`（`extends AbstractTransferWallet`） |

## CRITICAL 風險：setScore 成功碼自相矛盾

`setScore.ashx`（上下分）的 Code 表寫 `code:0 = 成功`，但**同一章節的成功回應 JSON 範例卻是 `code:1`**：

```jsonc
{ "acc": "test001", "code": 1, "money": "4930.12", "msg": "success", "success": true }
```

**APIs 專案 `Driver::handleTransfer` 以 `(string) $result['code'] === '0'` 判定成功**（採信 Code 表）。若 918 正式環境回傳 `code:1` 代表成功，現行實作將誤判為失敗 → 提款流程會把成功的轉出標為失敗。

> **上線前必須以正式環境實測 setScore 成功回應的 `code` 實際值，並依結果校正判斷邏輯。**

## 其他陷阱

### 1. getOrder 未實作（逾時補單盲點）

`getOrder.ashx`（查訂單）是逾時補單的關鍵介面，但 Driver **尚未封裝此介面**。上下分逾時（TIMEOUT 狀態）目前無自動查單補償路徑，須人工介入。

- `getOrder.ashx` 使用獨立 port（`:9919`），且簽名公式以 `orderid` 取代 `userName`，與其他介面不同。
- 若補實作此介面，必須使用 `getOrder` 專用簽名（見 api.md 簽名章節）。

### 2. `strtoupper` 簽名偏差

`Driver::getSignature` 輸出的是**大寫 hex**，而文件公式僅寫 `md5(...)` 未明示大小寫。若 918 端對 `sign` 大小寫敏感且要求小寫，將觸發 `code:-2 簽名錯誤`。

> 現行 Driver 若生產環境正常運作，代表 918 接受大寫 hex 或大小寫不敏感。出現大量 `-2` 時第一個排查點即為移除 `strtoupper`。

### 3. time 欄位非真毫秒

`time = date('U') . '000'`（秒級補三個 `0`，非真毫秒）。若 918 對時間戳有嚴格時效校驗且窗口很窄，可能偶發簽名失效。

### 4. editUser OldPassWd 可能不符

`Driver::resetPassword` 帶 `OldPassWd = [REDACTED] 可能與 918 端實際密碼不符，導致改密失敗。

### 5. Base URL 備忘檔不可直接採用

`注意事項(*站).txt` 出現多組環境位址，部分已標註「錯的」。正式 `HOST` 一律由業務交付後寫入 `vendor_infos.info.HOST`，不從備忘檔讀取。

## 錢包流程

```
建立玩家帳號（addUser, UserType=1）
         │
         ▼
存款：transferToVendor → setScore.ashx action=setServerScore scoreNum > 0
（代理錢包 → 918 端玩家餘額）
         │
         ▼
玩家於 918KISS APP 內遊戲、下注、派彩（918 端處理，本平台不介入）
         │
         ▼
提款：transferToLocal → setScore.ashx action=setServerScore scoreNum < 0
（918 端餘額 → 代理錢包）
         │
         ▼
對帳：GameLog.ashx（注單） / UserscoreLog.ashx（上下分明細）
```

- **餘額查詢**：`getUserInfo` → 讀 `MoneyNum`（Driver `getBalance`）。
- **上下分冪等**：`orderid`（本系統交易號）為冪等鍵；重複回 `code:-1 訂單錯誤`；逾時需走 `getOrder.ashx` 查單（**目前未實作**）。

## 實作對照表

| 項目 | 官方文件 | APIs 實作 | 狀態 |
|---|---|---|---|
| 簽名公式 | `md5((authcode+userName+time+secretKey).tolower)` | `strtoupper(md5(strtolower(...)))` | ⚠️ 輸出大寫，待確認 |
| 餘額查詢 | `getUserInfo` → `MoneyNum` | `action=getUserInfo`，讀 `MoneyNum` | ✅ |
| 存款 | `setServerScore`，`scoreNum > 0` | `scoreNum = +vendorAmount` | ✅ |
| 提款 | `setServerScore`，`scoreNum < 0` | `scoreNum = -vendorAmount` | ✅ |
| 上下分成功判定 | Code 表 `0`（JSON 範例 `1`） | `code === '0'` | ⚠️ CRITICAL，須實測 |
| 改密碼 | `editUser`，`pwdtype` 0=加密 1=明文 | `pwdtype=1`（明文） | ✅ |
| 逾時補單 | 走 `getOrder.ashx` 查訂單 | **未實作** | ❌ 缺口 |
| 上下分 action | `setServerScore`（玩家） | 固定 `setServerScore` | ✅ |

## 整合落地建議

| SCR 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Scr/Driver.php`（`AbstractTransferWallet`，實作 `transferToVendor` / `transferToLocal` / `getBalance` / `getGameUrl` / `resetPassword`） |
| Vendor 註冊 | `config/vendor.php`：`VendorIdEnum::SCR->value (3) => Vendors\Scr\Driver::class` |
| 帳號 Model | `app/Vendors/Models/ScrAccount.php`（`player_id` = 918 帳號，`password` = 918 密碼） |
| 注單 Model | `app/Vendors/Models/ScrBetRecord.php` |
| 回調 Controller | **無**（Transfer Wallet 不提供 callback） |
| `vendor_infos.info` | `HOST`（正式 Base URL）/ `AUTH_CODE`（授權碼）/ `SECRET_KEY`（簽名密鑰）/ `DOWNLOAD_PAGE`（APK 下載頁） |
| 遊戲啟動 | 無 web URL；`getGameUrl` 回傳 APP deep-link：<br>iOS `LobbyKiss64://account={u}&password=[REDACTED] `lobbykiss://lobbykiss?account={u}&password=[REDACTED] |
| IP 白名單 | 918 端驗本平台出口 IP（由 918 設定） |
| 對帳 | `GameLog.ashx`（注單）、`UserscoreLog.ashx`（上下分明細）、`AccountReport.ashx`（每日輸贏）；保留期須向客服確認 |

## Gaps / 待客服確認

- **上下分成功 Code**：Code 表 `0` vs JSON 範例 `1`，上線前必須正式環境實測。
- **正式 Base URL**：須由業務交付；備忘檔位址不可直接採用。
- **getOrder 未實作**：逾時補單缺補償邏輯，需補實作或訂人工 SOP。
- **getOrder 獨立 port 9919**：若需啟用，`vendor_infos.info` 需另設 `HOST2` 或在 Driver 硬碼分流（待 RD 設計）。
- **簽名大小寫**：`strtoupper` 是否可移除，正式環境實測後確認。
- **資料保留期**：`GameLog.ashx` 等報表的回追窗口須向客服確認。
- **UserAreaId 代碼**：`RandomUserName` 的地區代碼清單取自舊版文件，使用前向客服確認 3.0 版是否一致。
- **報表 / 查詢類錯誤碼**：`GameLog`、`AccountReport`、`AgentMoneyLog` 等介面未列完整錯誤碼，失敗回應解析需補充。
