# Ace333SW（vendor_id=109）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless / Wallet API）；玩家餘額由本平台保管 |
| 回調方向 | **ACE333 → 本平台**（本平台為 server，被呼叫方） |
| 驗證 | HTTP Header `token`（PROVIDER 配發；**未帶或為空即拒收** `Invalid token`，見陷阱 7）+ IP 白名單 |
| 成功判定 | `error === "0"`，HTTP 一律 200 |
| 時區 | UTC |
| 資料保留期 | **14 天** |
| 金額精度 | 小數 2 位；Jackpot 貢獻額小數 6 位 |

## 陷阱與落差

### 1. FundRequest / FundReturn / FundBetResult 未實作

路由及 Controller 中均無這三個快節奏遊戲回調。若 ACE333 平台有快節奏遊戲並啟用，現行實作無法處理，需確認是否上線此類遊戲。

### 2. Refund 冪等鍵可能不足

文件要求以 `referenceID + playSessionID` 組合判斷冪等，Controller 目前以 `status == 9`（依 `betRefundID` 查 bet record）判斷。若 ACE333 以不同 `playSessionID` 重試 refund 但帶相同 `betRefundID`，實作可正確防重；若帶不同 `betRefundID` 則可能重複退款。需確認 ACE333 實際重試行為。

### 3. `GetVersion` 端點未實作

文件要求代理提供 `GET api/getversion`（PROVIDER 查詢 Wallet API 版本），路由中未見。需確認是否補實作。

### 4. `GetBalance` 未使用 `lockForUpdate()`

`GET ace333sw/getbalance` 為高頻查詢，代理端 `getBalance()` 未套用 `lockForUpdate()`。

### 5. `free spin` 不應扣款

`roundDetails` 含 `free spin` 時，`betAmount` 為參考值（前一局押注），**代理不應扣款**。需確認 Controller 的 `bet()` 已正確處理此情境。

### 6. BetResult `balance` 欄位使用方式

文件 BetResult 帶 `balance`（Bet 後扣款剩餘，供參考），Controller `validate` 僅驗核心欄位，`balance` 存入 `info` JSON，未用於任何邏輯。此設計可接受，留存確認記錄。

### 7. 回調 token 缺漏時不能交給 `getVendorInfo()`（target/op_tickets#3085）

`callAction()` 以 header `token` 呼叫 `BaseController::getVendorInfo($token, ...)` 比對 `vendor_infos.info.CLIENT_ID`；該方法對**空字串**的
語意是「退回該玩家所屬商戶」，會整段跳過比對。現行做法是取得 header 後先擋空值（缺漏或空字串一律拋 `ApiException('Invalid token')`，
與 token 錯誤同一回應），再交給 `getVendorInfo()`。

另記一項文件與代碼的落差待 ACE333 確認：api.md 說回調帶的是 `<API_SERVICE_TOKEN>`、下方落地表把它對到 `CLIENT_SECRET`，但代碼比對的是
`CLIENT_ID`；生產 log 的 `token` header 長度與 `CLIENT_ID` 一致、與 `CLIENT_SECRET` 不符，三方送的確是 `CLIENT_ID`。`CLIENT_ID` 是否屬
機密值（而非可公開的商戶編號）**未驗證**——若只是識別碼，這道驗證的強度接近零。

## BetRecord 狀態機

`BET` →（BetResult 派彩）`PAID`（終態）或（Refund 退款）`REFUNDED`（終態）。

- 同一 `playSessionID` 可能有多筆不同 `referenceID` 的 Bet（Stud Poker 2 / Multiplayer），需累加到同一 BetRecord。
- `free spin` bet：不扣款，BetRecord `betAmount` 記錄但不觸發錢包扣款。
- JackpotWin：與 BetResult 分開，單獨加款。

## 整合落地建議

| ACE333 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Ace333SW/Driver.php` |
| Callback Controller | `app/Http/Controllers/External/SingleWallets/Ace333SWController.php` |
| 路由 | `routes/external/sw.php` prefix `ace333sw` |
| `vendor_infos.info.CLIENT_ID` | 商戶識別（Controller `vendorInfoOperatorField`） |
| `CLIENT_SECRET` | Wallet API token / H5 Auth `accessToken` |
| `CLIENT_H5_SECRET_KEY` | H5 Login 簽章的 `key` 欄位 |
| `CLIENT_H5_DEKIMITER` | 查詢字串分隔符 |
| `CLIENT_H5_ENCRYPT_KEY` | DES-CBC 加解密金鑰（兼 IV） |
| `CLIENT_H5_MD5_KEY` | MD5 簽章附加金鑰 |
| `CLIENT_GAME_URL` | 遊戲 Base URL |
| `CLIENT_H5_AUTH_URL` | H5 Auth API Base URL |
| `CURRENCY` | 幣別代碼 |
| `ace333sw_accounts.vendor_uid` | ACE333 側 `playerID` |
| `ace333sw_accounts.vendor_username` | ACE333 側 `accountID` |
| 對帳保留期 | **14 天**（PlaySessions / FailedTransactions / CheckPlaySession） |

## Gaps / 待 ACE333 確認

- 正式環境 Base URL（由 ACE333 客服個別提供）
- Wallet API 回調來源 IP 白名單（文件未列出）
- FundRequest / FundReturn 觸發閾值（文件未公開）
- `CLIENT_H5_DEKIMITER` 正式值
- 是否有快節奏遊戲上線（影響是否需補實作 Fund 系列端點）
- 回調 header `token` 對應 `CLIENT_ID` 還是 `CLIENT_SECRET`、`CLIENT_ID` 是否屬機密值（陷阱 7）
