# Vsport（vendor_id=53）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 平台 | IGK Sportsbook API（體育博彩） |
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 Vsport 端保管，進出場各轉一次 |
| 請求方向 | **本平台 → Vsport**（代理主動呼叫，GET）；無回調 |
| 驗證 | `secret`（query string）+ IP 白名單；**無請求簽章演算法** |
| 成功判定 | `errcode == 0` |
| 轉入 | `deposit`（serial）→ `transferToVendor()` |
| 轉出 | `withdraw`（serial）→ `transferToLocal()` |
| 轉帳查單 | `check_payment`（serial）→ `checkTransferStatus()` |
| 登入 URL | `login_mobile` 回傳行動版 URL；**60 秒過期** |
| API endpoint | `api.aspx`（XML）/ `apijs.aspx`（JSON）；本專案用 `api.aspx` + Accept header（見陷阱 §3） |
| Live URL | `https://example.invalid/api.aspx` |
| UAT URL | `https://example.invalid/api.aspx` |

## 高風險陷阱

### 1. `login_mobile` 參數名：`bettingHost` vs `host`

doc §3.22 參數表寫 `bettingHost`，但 Driver 送出參數名為 `host`（Driver 內有註解說明此差異）。目前以 Driver 實測值為準。**若 Vsport 端未來嚴格按文件驗參數，可能回 `-4` / `-5`。**

### 2. 語言碼後綴逗號 Bug

`Driver::mapLanguageCode()` 對 `ZH_TW`/`ZH_CN` 回傳 `'ZH-CN,'`、`ID_ID` 回傳 `'ID-ID,'`（**字串尾帶逗號**），但文件語言碼為 `ZH-CN`/`ID-ID`（無逗號）。VI/TH/MY 則無逗號。

> ⚠️ 疑似筆誤，尾逗號可能導致 Vsport 無法解析語言，進場 URL 語言異常。

### 3. API Endpoint：`api.aspx` vs `apijs.aspx`

doc 區分兩個 endpoint：`api.aspx`（XML 回應）、`apijs.aspx`（JSON 回應）。Driver 以 `API_URL` 設定為 `.../api.aspx`，並以 `Http::asJson()->get()` 送出（`asJson()` 在 GET 下僅加 `Accept: application/json` Header，不改 endpoint）。

> 須確認 Vsport 端 `api.aspx` 是否依 `Accept` 標頭回 JSON，或應改用 `apijs.aspx`。若實際回 XML，Driver `->json()` 解析失敗會落入 TIMEOUT 分支造成誤判。

### 4. PC 與 Mobile 使用同一 URL

`getGameUrl()` 以 `login_mobile` 取得行動版 URL，同時填入 `pc` 與 `mobile`，PC 端亦進入行動版介面。doc 另有 `login_newdesktop`（URL 含 `mb=2`）可提供桌面版體驗；目前共用行動版為設計選擇，非 bug。

## 轉帳錢包流程

```
create  ── 建立會員（開戶）
  │
deposit(serial, amount)  ── 本地 → Vsport  ─▶ transferToVendor()
  │  └─ 逾時 → checkTransferStatus(serial) 查 check_payment
  │
login_mobile  ── 取 URL（60 秒過期）  ─▶ getGameUrl()
  │
玩家於 Vsport 下注 / 結算
  │
withdraw(serial, amount)  ── Vsport → 本地  ─▶ transferToLocal()
  │
對帳：fetch + mark_fetched / ticket / fetch_summary
```

### 轉帳結果判讀

| 條件 | Driver 映射 |
|---|---|
| `errcode == 0` | `SUCCESS` |
| `errcode == 2`（Serial exist） | `FAILED` |
| 連線逾時 / 回應非陣列 / 缺 `errcode` / 其他 errcode | `TIMEOUT`（後由 `checkTransferStatus` 確認） |

`checkTransferStatus` 判讀：`errcode=0` 且 `result.payment` 恰 1 筆且 username 相符 → `SUCCESS`；查得但不符 → `FAILED`；其他 → `TIMEOUT`。

## 冪等性

- `deposit`/`withdraw` 的 `serial` 為冪等鍵；重送相同 `serial` 得 `errcode=2`（Serial exist），Driver 映射為 `FAILED`。
- `check_payment` 按 `serial` 查單。
- 文件未明確定義逾時後以相同 `serial` 重送的語意（待客服確認）。

## 整合落地建議

| Vsport 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Vsport/Driver.php`（`AbstractTransferWallet`） |
| 玩家帳號 Model | `app/Vendors/Models/VsportAccount.php`（`vendor_username_column = username`） |
| 注單 Model | `app/Vendors/Models/VsportBetRecord.php`（`bet_time_column=created_time`、`payout_time_column=bet_time`；時間欄位為 UTC） |
| 轉帳入口 | `transferToVendor()`（`deposit`）/ `transferToLocal()`（`withdraw`） |
| 轉帳查單 | `checkTransferStatus()`（`check_payment`） |
| 餘額查詢 | `getBalance()`（`balance`） |
| 登入 URL | `getGameUrl()`（`login_mobile`） |
| Callback controller | **無**（轉帳錢包無回調） |
| External 路由 | **無** |
| `API_URL` | `vendor_infos.info.API_URL`（完整 URL，含 `/api.aspx`） |
| `secret` | `vendor_infos.info.SECRET`（認證憑證，須遮罩） |
| `agent` | `vendor_infos.info.AGENT` |
| `accType` | `vendor_infos.info.ACC_TYPE`（`MY/HK/ID/EU`） |
| `host` | `vendor_infos.info.HOST`（下注站主網域，`login_mobile` 使用） |
| `theme` | `vendor_infos.info.THEME`（選填） |
| 對帳 / 報表 | `ticket`（時間範圍）、`fetch`/`mark_fetched`（遞增）、`fetch_summary`/`fetch_summary_wd`（彙總） |
| 對帳保留期 | 文件**未明確記載**，需向 Vsport 客服確認 |
| IP 白名單 | 代理出口 IP 須向 Vsport 加白（未加白回 `errcode=-3`） |

## Gaps / 待確認

- API endpoint 回應格式：`api.aspx` 是否依 `Accept: application/json` 回 JSON（見陷阱 §3）
- `login_mobile` 參數名 `host` vs `bettingHost`（見陷阱 §1）
- 語言碼尾逗號（`ZH-CN,`、`ID-ID,`）是否影響 Vsport 語言解析（見陷阱 §2）
- 逾時後以相同 `serial` 重送 `deposit`/`withdraw` 的語意（TIMEOUT 重試安全性）
- 注單資料保留期限（文件未明確說明）
- `errcode` 正數值在不同 action 含義不同，排查時須結合 `errtext`（見 `api.md §錯誤碼`）
