# eBet — API 規格

> 原始來源（官方文檔，主要事實來源）：
> - `API document v6.1.2m.pdf`（v6.1.2m，141 頁，**Multiple wallets API document**，本文件主依據）
> - `API document v6.1.2s.pdf`（v6.1.2s，186 頁，Single wallet 版，僅供參考；本專案 Driver 為轉帳錢包，不採用）
> - 整合資料：`form/eBET Integration Form（渠道对接文档）.xlsx`、`wtotoidr.txt`、`後台.txt`（含 channelId、Public/Private Key 範例）
> - 線上 Swagger：`http://example.invalid/swaggernew/`（含 `channel_request.html`）
>
> 抓取日期：2026-06-03
> 本專案 Vendor：`VendorIdEnum::EBET`（vid **13**），錢包型態 **Transfer Wallet（轉帳錢包，多錢包 m 版）**

> 我方實作分析見 `integration.md`（陷阱、rechargestatus 三態、落地建議、Gaps）。

eBet 為線上真人 / 電子遊戲商（百家樂、龍虎、輪盤、骰寶、Slot、Niuniu、區塊鏈百家樂等），提供 General / Multiple wallets 與 Single wallet 兩種錢包模式。本專案採 **Transfer Wallet（多錢包 m 版）**，繼承 `AbstractTransferWallet`。

---

## 目錄

- [通用約定](#通用約定)
- [1. Create User（syncuser）](#1-create-usersyncuser)
- [2. User Information（userinfo）](#2-user-informationuserinfo)
- [3. Recharge（recharge）](#3-rechargerecharge)
- [4. Check Recharge Status（rechargestatus）](#4-check-recharge-statusrechargestatus)
- [5. Batch Get Money（getusermoney）](#5-batch-get-moneygetusermoney)
- [6. User Bet History（userbethistory）](#6-user-bet-historyuserbethistory)
- [7. User Transaction（usertransaction）](#7-user-transactionusertransaction)
- [8. 其他報表 / 限額 API](#8-其他報表--限額-api)
- [9. Slot Game List（slotgamelist）](#9-slot-game-listslotgamelist)
- [遊戲進入（H5 / launchUrl / loginslot）](#遊戲進入h5--launchurl--loginslot)
- [簽名](#簽名)
- [apiStatusCode 狀態碼](#apistatuscode-狀態碼)
- [錢包 typeId](#錢包-typeid)

---

## 通用約定

### 請求格式

| 項目 | 值 |
|---|---|
| HTTP Method | `POST` |
| Content-Type | `application/json` |
| 驗證方式 | IP 白名單（eBet 後台設定）＋ RSA 簽章（見[簽名](#簽名)節） |
| 大小寫敏感 | 請求參數須完全依文件大小寫（`channelId`、`username` 小寫等） |

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | int | 狀態碼（`200` 成功；其餘見 [apiStatusCode](#apistatuscode-狀態碼)） |
| `apiVersion` | string | eBet API 版本（如 `1.3.51`） |

### Base URL

| 環境 | URL |
|---|---|
| Production | 由 eBet 客服提供（文件範例：`api_domain:8888`，各 API 為 `<api_domain>:8888/api/<action>`） |
| 線上 Swagger | `http://example.invalid/swaggernew/` |
| H5 遊戲 | 由 eBet 提供（整合資料範例 `https://example.invalid/h5/c4381e`） |

> 正式 Base URL 由 eBet 客服申請，由 `vendor_infos.info.API_URL` 設定（結尾帶 `/`）。

### 資料保留期

eBet 後台資料保留 **60 天**（文檔 §5.9）；超過須聯絡客服。對帳工具須在此期限內完成抓取。

### 時間格式

- eBet server DB 時間為 **Beijing time（UTC+8）**（§5.11）
- 接受格式：`yyyy-MM-dd HH:mm:ss`（UTC+8）或 `yyyy-MM-ddTHH:mm:ssZ`（UTC+0）
- 報表 API 回傳的 `time` 常見為 ISO `...Z`（UTC+0），對帳寫入須轉換至專案 DB 預設時區（UTC）

---

## 1. Create User（syncuser）

由 channel 向 eBet 建立新玩家。eBet **不保存密碼**（存在 channel 端）；玩家先在 channel 平台建立帳密，登入時 eBet 向 channel 驗證。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/syncuser` |
| 待簽字串 | `username`（見[簽名](#簽名)節） |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 玩家帳號（小寫） |
| `channelId` | int | ✅ | 渠道 ID |
| `signature` | string | ✅ | RSA 簽章 |
| `subChannelId` | int | ✅ | 子渠道 ID，無則 `0`（後台取得） |
| `currency` | string | ✅ | 幣別（多幣別渠道必填） |

### 回應

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | int | `200` 成功；`401` User already exists；其餘見 apiStatusCode |
| `apiVersion` | string | API 版本 |

```jsonc
// Sample request
{ "username": "apitest01", "channelId": 1,
  "signature": "FVXroBHYjnRqSJwbYYsfaV9RduMVg1L/5KAPnFW7iSgDSKF2iZrVW+xl9F3oKypUwgjm38JSCHgQWUrFxI0QGA==" }
// Sample response
{ "status": 200, "apiVersion": "1.3.42" }
```

> `status=401`（User already exists）在建立流程中可視為「已存在 → 視同成功」，避免重複建立報錯。

---

## 2. User Information（userinfo）

查玩家資訊與餘額（含多錢包明細）。**免登入即可查**，常用於 recharge 前後確認餘額。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/userinfo` |
| 待簽字串 | `username + timestamp` |
| Driver 使用 | ✅ `getBalance()` 讀回應 `money` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 玩家帳號（小寫） |
| `channelId` | int | ✅ | 渠道 ID |
| `signature` | string | ✅ | RSA 簽章 |
| `timestamp` | long | ✅ | Unix time |
| `currency` | string | ✅ | 幣別（多幣別渠道必填） |
| `subChannelId` | int | （Driver 帶入） | 子渠道 ID |

### 回應

| 欄位 | 類型 | 說明 |
|---|---|---|
| `username` | string | 玩家帳號 |
| `channelId` / `subChannelId` / `userId` | int | 渠道 / 子渠道 / eBet 內部 user id |
| `money` | double | 玩家總餘額 |
| `timestamp` | long | Unix time |
| `status` | int | 狀態碼 |
| `apiVersion` | string | API 版本 |
| `wallet` | object[] | 多錢包明細，每筆 `{ typeId, money }`（typeId 見[錢包 typeId](#錢包-typeid)） |

```jsonc
// Sample response
{ "username": "apitest01", "channelId": 1, "subChannelId": 0, "userId": 7609,
  "money": 1234.56,
  "wallet": [ { "typeId": 0, "money": 1234.56 }, { "typeId": 1, "money": 0 }, { "typeId": 2, "money": 0 } ],
  "timestamp": 1560223327, "status": 200, "apiVersion": "1.3.51" }
```

> ⚠️ `money` 為 `double`，進入專案運算 / 比較前以 `Common::numAdd('0', (string)$money, 4)` 正規化。

---

## 3. Recharge（recharge）

對玩家錢包加值（存款，`money` 為正）或扣除（提款，`money` 為負）。

> ⚠️ **回 `200` 僅代表已收到請求，NOT 代表轉帳成功。** 必須再以 `rechargestatus`（§4）用同一 `rechargeReqId` 確認最終結果。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/recharge` |
| 待簽字串 | `username + timestamp` |
| Driver 使用 | ✅ `transferToVendor`（+）/ `transferToLocal`（-）→ `transfer()` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 玩家帳號（小寫） |
| `channelId` | int | ✅ | 渠道 ID |
| `money` | double | ✅ | 充值金額：**`+` 加值（deposit）、`-` 扣除（withdraw）**；支援小數第 2 位、無上限 |
| `rechargeReqId` | string | ✅ | 充值唯一 ID，防重送；本專案用 `transactionNo` |
| `signature` | string | ✅ | RSA 簽章 |
| `timestamp` | long | ✅ | Unix time |
| `typeId` | int | ✅ | 充值目標錢包（Driver 固定 `0` General；見[錢包 typeId](#錢包-typeid)） |
| `currency` | string | ✅ | 幣別（多幣別渠道必填） |
| `subChannelId` | int | （Driver 帶入） | 子渠道 ID |

> Driver 金額以 `Common::numFloor($amount, 2)` 截到 2 位（符合文件「支援小數第二位」規定）。

### 回應

| 欄位 | 類型 | 說明 |
|---|---|---|
| `money` | double | 充值後玩家餘額 |
| `rechargeReqId` | string | 回傳同一充值 ID |
| `status` | int | `200` = 已受理（**非成功**，須查 rechargestatus） |
| `apiVersion` | string | API 版本 |

```jsonc
// Sample request
{ "channelId": 1, "username": "apitest01", "timestamp": 1560223858340,
  "signature": "TJv4IyOZDZmCc7w+GLj8u/n+eW7u2kMMWzG+6VWUrWqgxmynel2zfH...",
  "money": "1000", "rechargeReqId": "testrecharge0001" }
// Sample response
{ "money": 2234.56, "rechargeReqId": "testrecharge0001", "status": 200, "apiVersion": "1.3.42" }
```

---

## 4. Check Recharge Status（rechargestatus）

以 `rechargeReqId` 查充值交易最終狀態，作為 recharge 的冪等性與成敗判斷依據。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/rechargestatus` |
| 待簽字串 | `rechargeReqId` |
| Driver 使用 | ✅ `checkTransferStatus()`（輪詢 `API_LOOP_TIME` 次，間隔 `SLEEP_SECOND` 秒） |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `channelId` | int | ✅ | 渠道 ID |
| `rechargeReqId` | string | ✅ | 充值唯一 ID |
| `signature` | string | ✅ | RSA 簽章（簽 `rechargeReqId`） |
| `currency` | string | ✅ | 幣別（多幣別渠道必填） |

### 回應

| 欄位 | 類型 | 說明 |
|---|---|---|
| `rechargeReqId` | string | 同一充值 ID |
| `status` | int | 充值狀態碼（見下表） |
| `apiVersion` | string | API 版本 |

### status → 交易結果

| status | 含義 | Driver 處置 |
|---|---|---|
| `200` | Successful | `SUCCESS` |
| `5001` | Recharge failed | `FAILED` |
| `-1` | Recharge record no exist（尚未入帳） | 繼續輪詢；耗盡 → `TIMEOUT` |
| `0` | Recharging（處理中）| 文件：3 秒後再查；Driver 現行：`break` → `TIMEOUT`（見 `integration.md` 陷阱 §5） |
| 其他 | 各類錯誤碼 | `break` → `TIMEOUT` |

```jsonc
// Sample request
{ "channelId": 1, "signature": "EnqFS6WULtesfHVEb8cnSDQygXqDUWU5uPJCDHu67DKSWdPwwADj9H...",
  "rechargeReqId": "testrecharge0001" }
// Sample response
{ "rechargeReqId": "testrecharge0001", "status": 200, "apiVersion": "1.3.42" }
```

---

## 5. Batch Get Money（getusermoney）

批次查多位玩家當前餘額（含多錢包）。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/getusermoney` |
| 待簽字串 | `username` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 多位以 `,` 分隔；全部玩家傳 `""` |
| `channelId` | int | ✅ | 渠道 ID |
| `signature` | string | ✅ | RSA 簽章 |
| `startTimeStr` / `endTimeStr` | string | ❌ | 時間範圍（預設當日） |
| `pageNum` / `pageSize` | int | ❌ | 分頁（pageSize 預設 10，最大 5000） |
| `subChannelId` | int | ❌ | 子渠道 |
| `minMoney` | int | ❌ | 過濾小於此金額者不顯示 |
| `walletType` | int | ❌ | 指定查某錢包（typeId） |
| `currency` | string | ❌ | 幣別（多幣別渠道必填） |

### 回應

`status` / `apiVersion` ＋ `results` 陣列，每筆 `{ username, money, wallet:[{typeId, money}] }`（玩家不存在則不出現在 results）。

---

## 6. User Bet History（userbethistory）

依玩家 / 時間範圍取注單明細，可用於排程定期抓取。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/userbethistory` |

### 請求參數（部分）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ❌ | 空=全部玩家 |
| `startTimeStr` / `endTimeStr` | string | ❌ | 時間範圍 |
| `judgeTime` | int | ❌ | `0`=以派彩時間查；`1`=以投注時間查 |
| `platform` | int | ❌ | 平台來源（-1 Other / 0 Android / 1 iOS / 2 Mobile H5 / 3 PC H5） |

> 完整請求 / 回應欄位見原始文件 §4.5（p.40~66），本次未完整抓取，標 gaps。

---

## 7. User Transaction（usertransaction）

取玩家交易流水（含投注、派彩、轉帳、紅包、預扣等所有 type）。資料量大，建議帶時間範圍與分頁。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/usertransaction` |
| 待簽字串 | `username + timestamp` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ❌ | 玩家帳號 |
| `startTimeStr` / `endTimeStr` | string | ❌ | 時間範圍 |
| `channelId` | int | ✅ | 渠道 ID |
| `subChannelId` | int | ✅ | 子渠道（無則 0） |
| `pageNum` / `pageSize` | int | ❌ | 分頁（pageSize 預設 10，最大 5000） |
| `timestamp` | int | ✅ | Unix time |
| `signature` | string | ✅ | RSA 簽章 |
| `currency` | string | ✅ | 幣別（多幣別渠道必填） |
| `tradeType` | int[] | ❌ | 交易類型過濾（見下表） |

### 回應

| 欄位 | 類型 | 說明 |
|---|---|---|
| `count` | int | 總筆數 |
| `status` | int | 狀態碼 |
| `remainingVisits` | int | 剩餘可呼叫次數（500 次/分） |
| `transactions` | object[] | 交易明細 |
| `apiVersion` | string | API 版本 |

每筆 `transactions[]`：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `transactionId` | string | 交易 ID |
| `userId` / `username` / `subChannelId` | int/string | 玩家識別 |
| `tradeType` | int | 交易類型（見下表） |
| `roundNo` | string | 局號 |
| `transBeforeBalance` / `transBalance` / `transAfterBalance` | double | 交易前 / 變動 / 交易後餘額 |
| `time` | string | 交易時間（`yyyy-MM-dd HH:mm:ss` 或 ISO `...Z`） |
| `bonusId` / `bonusName` | int/string | 當 `tradeType` ∈ {23, 24} 時出現 |

### tradeType

| code | type | 說明 |
|---|---|---|
| `1` | Bet | 投注（玩家確認下注時記錄） |
| `2` | Payout | 派彩（該局結算後派發） |
| `3` | Tips | 給荷官小費（僅 VIP 桌） |
| `4` | Transfer | 轉入玩家錢包（recharge 正值） |
| `5` | Transfer out | 轉出玩家錢包（recharge 負值） |
| `6` | Bet Award | 投注累積達標獎勵 |
| `8` | Match Fee | 進賽事房報名費扣款 |
| `9` | Match Refund | 賽事剩餘退回 |
| `11` | RefundwhenBetErr | 預扣成功但下注失敗的退款 |
| `14` / `15` | MatchAward / getBackMatchAward | 賽事獎勵發放 / 回收（查詢需同時帶 14、15） |
| `23` / `24` | Lucky / Limited Red Packet | 紅包 |
| `27` / `28` | withholding / withholdingRefund | 指定注項預扣 / 預扣退回 |

```jsonc
// Sample response（節錄）
{ "count": 4, "remainingVisits": 498, "status": 200, "apiVersion": "1.3.42",
  "transactions": [
    { "userId": 7609, "username": "apitest01", "tradeType": 4, "roundNo": "testrecharge0001",
      "transBeforeBalance": 1234.56, "transBalance": 1000, "transAfterBalance": 2234.56,
      "transactionId": "5cff209f13cc6f5be789a13e", "time": "2019-06-11T03:31:43.068Z", "subChannelId": 0 },
    { "userId": 7609, "username": "apitest01", "tradeType": 1, "roundNo": "NNPT1-190611114243",
      "transBeforeBalance": 2234.56, "transBalance": -100, "transAfterBalance": 2134.56,
      "transactionId": "5cff2308115fd35cb4b88000", "time": "2019-06-11T03:42:00.265Z", "subChannelId": 0 },
    { "userId": 7609, "username": "apitest01", "tradeType": 2, "roundNo": "NNPT1-190611114243",
      "transBeforeBalance": 2134.56, "transBalance": 195, "transAfterBalance": 2329.56,
      "transactionId": "5cff23494f484d5c0a119ebb", "time": "2019-06-11T03:43:05.902Z", "subChannelId": 0 }
  ] }
```

---

## 8. 其他報表 / 限額 API

| Endpoint | 章節 | 用途 |
|---|---|---|
| `/api/getbetlimit` | §4.8 | 查單一玩家各房型下注限額（待簽：`username + channelId + timestamp`） |
| `/api/updatebetlimit` | §4.9 | 更新單一玩家下注限額 |
| `/api/userbetsummary` | §4.10 | 注單彙總報表 |
| `/api/userbettracer` | §4.11 | 注單詳情追蹤 |
| `/api/getcurrentluzhu` | §4.12 | 取當前路單 |
| `/api/totaluserbetsummary` | §4.13 | 玩家投注總彙總報表 |
| `/api/logout` | §4.14 | 登出遊戲 |

---

## 9. Slot Game List（slotgamelist）

取渠道可用的老虎機清單（真人遊戲不在此 API）。m 版文件未收錄，規格整理自 RD-Studio 舊指令 `get_ebet_games` 的實際請求／回應。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/slotgamelist` |
| 待簽字串 | `channelId + timestamp` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `channelId` | int | ✅ | 渠道 ID |
| `timestamp` | int | ✅ | Unix time |
| `signature` | string | ✅ | RSA 簽章 |

### 回應

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | int | 狀態碼（`200` 成功） |
| `slotGames` | object[] | 老虎機清單 |

每筆 `slotGames[]`：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `gameID` | string | 遊戲代碼（平台以此為 `games.game_type`；亦為 loginslot 的 `gameID`） |
| `english` | string | 英文名稱 |
| `chinese` | string | 中文名稱（簡中） |
| `providerId` | string | 老虎機供應商代碼（如 `genesis`／`radi8`／`bunfox`；loginslot 需帶回，平台落 `games.remark`） |

本平台落地見 `integration.md`「遊戲清單同步」。

---

## 遊戲進入（H5 / launchUrl / loginslot）

本專案 `Driver::getGameUrl` 依設定與遊戲類型走三種路徑：

| 條件 | 行為 | 文檔依據 |
|---|---|---|
| Slot（`remark` ∈ `genesis`/`radi8`/`bunfox`） | POST `API_URL.'loginslot'`，回應 `slotURL` | **文檔未列**（見 `integration.md` Gaps） |
| 真人且 `USE_API_URL=1` | POST `API_URL.'launchUrl'` 取 `launchUrl`，再拼 H5 query | **文檔未列**（見 `integration.md` Gaps） |
| 真人且 `USE_API_URL=0` | 直接 `HTML5_URL` + query | §3.1.5 H5 login url set |

### H5 url query 參數（§3.1.5）

| 參數 | 說明 |
|---|---|
| `gameType` | 進指定遊戲大廳（0 multi-Baccarat / 1 Baccarat / 2 Dragon Tiger / 3 Roulette / 4 Sic Bo / 5 Slot / 6 Trial slot / 7 Blockchain baccarat / 8 Niuniu） |
| `tableCode` | 進指定桌台（清單向客服索取） |
| `orientation` | `1` 橫屏 / `2` 直屏（僅 3.0 版適用；預設直屏） |
| `language` | 介面語言（如 `zh_tw`/`zh_cn`/`en_us`/`ms_my`/`in_id`） |
| `mode` | `trial` 以 demo 帳號登入 |
| `exitUrl` | 離開遊戲跳轉頁（需 URL encode；PC 不支援） |
| `username` / `accessToken` | 玩家帳號 / access token（本專案 `accessToken = [REDACTED] |

> Driver 真人路徑帶 `orientation=2`、`language`（經 `mapLanguageCode`）、`gameType`、`username`、`accessToken`。
> 語言對照：ZH_TW→`zh_tw`、ZH_CN→`zh_cn`、MS_MY→`ms_my`、ID_ID→`in_id`、其他→`en_us`。

---

## 簽名

| 項目 | 值 |
|---|---|
| 演算法 | RSA |
| 雜湊 | MD5（`MD5withRSA`） |
| Padding | PKCS1（`CRYPT_RSA_SIGNATURE_PKCS1`） |
| 金鑰 | 簽名用 **Private Key**（PKCS#8 PEM）；eBet 驗章用 **Public Key**（X.509 PEM） |
| 輸出編碼 | Base64 |

> ⚠️ **子渠道不可取得 private key / public key / API 位址**（§5.10.5）。

### 各 API 待簽字串

| API | 待簽字串 |
|---|---|
| syncuser（§4.1） | `username` |
| getusermoney（§4.6） | `username` |
| userinfo（§4.2） | `username + timestamp` |
| recharge（§4.3） | `username + timestamp` |
| usertransaction（§4.7） | `username + timestamp` |
| rechargestatus（§4.4） | `rechargeReqId` |
| getbetlimit（§4.8） | `username + channelId + timestamp` |
| Normal Login（§3.1.1） | `username + timestamp` |
| Token Login（§3.1.2） | `timestamp + accessToken` |

> 拼接為字串直接相接（無分隔符），例如 `username=apitest01` + `timestamp=1560223858340` → `apitest011560223858340`。

### 本專案實作（Driver::getSign）

```php
private function getSign(string $string)
{
    $privateKey = openssl_get_privatekey(str_replace('\\n', "\n", $this->detailData->PRIVATE_KEY));
    if (openssl_sign($string, $ret, $privateKey, OPENSSL_ALGO_MD5)) {
        $ret = base64_encode($ret);
    }
    return $ret ?? '';
}
```

| 文檔 / phpseclib | Driver（OpenSSL） |
|---|---|
| `setHash("md5")` + `setSignatureMode(PKCS1)` | `OPENSSL_ALGO_MD5`（openssl_sign 預設 padding 即 PKCS1） |
| `$rsa->sign($plaintext)` | `openssl_sign($string, $ret, $privateKey, OPENSSL_ALGO_MD5)` |
| `base64_encode(...)` | `base64_encode($ret)` |
| 私鑰 PEM | `PRIVATE_KEY`（字面 `\n` 還原為換行後傳入 `openssl_get_privatekey`） |

Driver 各 API 待簽字串組裝：

| Driver 方法 | API | 待簽字串 | 文件一致 |
|---|---|---|---|
| `getBalance` | userinfo | `$username . $timestamp` | ✅ |
| `transfer` | recharge | `$username . $timestamp` | ✅ |
| `checkTransferStatus` | rechargestatus | `$transactionNo`（= rechargeReqId） | ✅ |
| `getGameUrl`（真人 launchUrl） | launchUrl | `$channelId . $timestamp` | 端點未在文件中，待確認 |
| `getGameUrl`（Slot loginslot） | loginslot | `$username . $timestamp` | 端點未在文件中，待確認 |

### 常見錯誤

| 症狀 | 可能原因 |
|---|---|
| 回 `4026`（Signature error） | 待簽字串拼接順序錯、私鑰 `\n` 未還原為換行、或用了錯誤金鑰 |
| 回 `4027`（IP is not authorized） | 來源 IP 未加入 eBet 白名單（§1.5 三段白名單：遊戲前端、呼叫 eBet API、後台登入） |
| 驗章本地成功但 eBet 拒絕 | hash 演算法不一致（須 MD5 而非 SHA）、或 padding 非 PKCS1 |

---

## apiStatusCode 狀態碼

| Code | 說明 | Code | 說明 |
|---|---|---|---|
| `-1` | Recharge record no exist（充值紀錄不存在） | `4003` | System busy, try back later |
| `0` | Recharging（充值處理中） | `4025` | Parameter error |
| `200` | Successful | `4026` | Signature error |
| `201` | Repeated rechargeId | `4027` | IP is not authorized |
| `202` | Channel no exist | `4028` | Trial user can't recharge credit |
| `203` | RechargeId can't null | `4029` | Function disable |
| `204` | Recharge money can't null | `4030` | Data type error |
| `205` | Recharge money too small | `4037` | User not existence |
| `207` | Refund Money Inconsistent | `4038` | Frequent request |
| `208` | Record is not existed | `4202` | Visit count no enough |
| `209` | Check record already refund | `5001` | Recharge failed |
| `401` | User or password error | `5002` | Not enough cash |
| `410` | Token error | `5003` | Sub channel no exist |
| `500` | Server error | `5005` | Wallet not enabled |
| `505` | Channel under maintenance | `5006` | Unable to extract in the game |

來源：v6.1.2m 文檔 §6.3。

---

## 錢包 typeId

| typeId | 錢包 | 說明 |
|---|---|---|
| `0` | General Wallet | 通用錢包（含所有遊戲），轉帳 / 充值預設 |
| `1` | Live Wallet | 真人遊戲錢包（百家樂等） |
| `2` | No Dealer Wallet | 電子 / Slot 錢包（無真人互動） |
| `3`~`6` | 保留 | 尚未開放使用 |

規則（§2.2）：未指定錢包全進 General；game wallet 只能用於指定遊戲；game wallet 用完才動用 general；派彩依比例分配回 game / general 錢包。

> Driver 充值 / 提款固定使用 `typeId=0`（General）。
