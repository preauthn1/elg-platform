# Astar — 單一錢包（Seamless Wallet）API 文檔

> **原始來源**
> - `游戏服务器对接文档`（Astar 新版，2026-10-01 由 PM 提供全文）— **本檔主要依據**
> - `ASTAR 无缝钱包对接文档v3-cn 231228.pdf`（v3，2023-12-28）— 已被上者取代，僅保留差異註記
> - `實作 API.txt`、`其他資訊.txt`、`正式域名.txt`、`測試域名.txt`（環境參數、回調授權、IP 白名單、資料保留期）
>
> 改寫日期：2026-10-01（前次 2026-06-03 依 v3 撰寫）
>
> 我方實作分析、整合落地建議、gaps、踩坑陷阱見同目錄 **`integration.md`（對接心得）**。

Astar（`VendorIdEnum::ASTAR = 101`）採 **單一錢包（Seamless Wallet）** 模式：玩家餘額由代理端（本平台）保管，Astar 在玩家下注 / 結算 / 退款時即時回調本平台 endpoint 進行扣款與派彩。遊戲分兩大類，**兩類的金額欄位語意不同**（見下方「遊戲類型與金額語意」）：

- **真人視訊**：百家樂、龍虎、輪盤、骰寶、牛牛、炸金花、色碟
- **捕魚棋牌**：二人麻將、百人牛牛、加倍鬥地主、經典搶莊牛牛、卡五星麻將

本文件大部分 endpoint **由本平台實作，供 Astar 調用**；路由前綴 `/api/sw/astar/gamepool/{gamehall}/...`。**唯一例外**是「細單數據 API」，那支是 Astar 提供、由本平台主動呼叫。

> **無簽章演算法**：文件未定義任何簽章，請求驗證僅靠 `Authorization` Header（固定 Token）＋ IP 白名單。

---

## 通用約定

### 角色與方向

| 角色 | 說明 |
|---|---|
| Astar（遊戲商） | 絕大多數 API 的**發起方**，POST / GET 至本平台 |
| 本平台（代理 / 我司） | callback 的**接收方（server）**，須實作整組 `/api/sw/astar/gamepool/{gamehall}/...` endpoint |

`gamehall` 為遊戲廳代號，由 Astar 分配（Yes2Win 正式為 `TH41`，文件範例用 `LP`）。

> **大小寫敏感且兩種寫法並存**：進入遊戲網址與細單 API 的 `{gamehall}` 用**大寫**，回調 endpoint 路徑的 `{gamehall}` 用**小寫**。文件在兩處都特別標註。

### 請求格式

- **HTTP Method**：依各 API，多數為 `POST`，`Balance`、`Round Check` 與細單數據 API 為 `GET`
- **Content-Type**：`application/x-www-form-urlencoded`（GET 方法除外）
- **時區**：**UTC-04:00**
- **時間格式**：RFC3339，例 `1996-12-19T16:39:57.001-04:00`

### 驗證方式

| 機制 | 說明 |
|---|---|
| `Authorization` Header | 固定不變的「遊戲商 Token」（GS Token），由本平台提供給 Astar，Astar 調用本平台接口時帶上。文件範例用 `ASTAR-8888`；實際值存在 `vendor_infos.info.CS_TOKEN` |
| IP 白名單 | Astar 來源 IP（正式 `0.0.0.0`）須加白 |

### 通用回應格式（本平台 → Astar）

所有 callback 回應共用以下結構，最外層分 `data` 與 `status`：

| 欄位 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `data` | Object / Array | ✗ | 各 API 的回傳資料；無資料時為空物件 `{}` 或空陣列 |
| `status.code` | String | ✅ | 狀態碼，成功為 `"0"`（見下方「錯誤碼」節） |
| `status.message` | String | ✗ | 狀態訊息，成功為 `"success"` |
| `status.datetime` | String | ✗ | 回應時間（RFC3339，UTC-04:00） |
| `status.tracecode` | String | ✅ | 追蹤碼 |

```jsonc
{
  "data": { "balance": 8888, "currency": "CNY" },
  "status": {
    "code": "0",
    "message": "success",
    "datetime": "2024-08-20T01:44:33-04:00",
    "tracecode": "bvSG8F71h1"
  }
}
```

> 成功與否以 `status.code` 為準，**HTTP 一律回 200**（含錯誤情境）。

### Base URL

| 用途 | 測試（試玩） | 正式 |
|---|---|---|
| Astar 遊戲域名 | `https://example.invalid` | `https://example.invalid` |
| 本平台回調 Base URL | `https://example.invalid/api/sw/astar` | `https://example.invalid/api/sw/astar` |

本平台回調各 endpoint 再接 `/gamepool/{gamehall}/...`。

> v3 時期的 `正式域名.txt` 曾給過細單域名 `example.invalid` / `example.invalid`，與新版文件不符；以新版文件與 `vendor_infos.info.GAME_URL` 實際值（Yes2Win = `https://example.invalid`）為準。

### 進入遊戲 URL（前端，非 API）

```
{GAME_URL}/{GAMEHALL}?token=[REDACTED]
```

- `token` **長度須小於 120 bytes 且不含特殊字元**
- 直接進廳：拼接 `&lobbyId={lobbyId}`
- 直接進桌：拼接 `&tableId={tableId}`

玩家進入後，Astar 會先呼叫本平台的 `auth` 取得帳號／限紅／代理資訊，再呼叫 `balance` 取得餘額，兩者都通過才能登入遊戲。

### MTcode 格式

每筆交易的唯一值。**同一回合的 `rollout` 與其對應 `rollin` / `refund` 使用相同且唯一的 mtcode**，廠商（本平台）須做唯一性判斷防止重複交易。

```
{env}-{action}-{roundid}
```

| 環境 | rollout | rollin | refund |
|---|---|---|---|
| 整合站（Astar 測試環境） | `test-rollout-{roundid}` | `test-rollin-{roundid}` | `{roundid}` |
| 正式站 | `rel-rollout-{roundid}` | `rel-rollin-{roundid}` | `{roundid}` |

> ⚠️ **v3 文件寫「測試 `rel`、正式 `pro`」是錯的**，新版文件明確列出正式站用 `rel-`，與 Yes2Win / TPower 正式環境實際收到的值一致。
>
> **refund 的 mtcode 沒有 `{env}-{action}-` 前綴**，直接就是 roundid —— 這點 v3 文件未寫明，新版文件的表格證實了 TPower 2026-09-05 的 log 實證。

`roundid` 格式為 `gamehall + 注單號`，例 `LP51223114`。

---

## 遊戲類型與金額語意

`tabletype` 決定金額欄位怎麼解讀，**兩類語意完全不同，不可混用**。

| tabletype | 遊戲 | 類別 | tablename 前綴 |
|---|---|---|---|
| `1` | 百家樂 | 真人視訊 | `BACCARAT` |
| `2` | 龍虎 | 真人視訊 | `DRAGON-TIGER` |
| `3` | 輪盤 | 真人視訊 | `ROULETTE` |
| `4` | 骰寶 | 真人視訊 | `SIC-BO` |
| `5` | 牛牛 | 真人視訊 | `DOU-NIU` |
| `12` | 炸金花 | 真人視訊 | `WIN3CARDS` |
| `14` | 色碟 | 真人視訊 | `COLORDISC` |
| `100002` | 二人麻將 | 捕魚棋牌 | `BOARDCARD` |
| `100004` | 百人牛牛 | 捕魚棋牌 | `BOARDCARD` |
| `100009` | 加倍鬥地主 | 捕魚棋牌 | `BOARDCARD` |
| `100033` | 經典搶莊牛牛 | 捕魚棋牌 | `BOARDCARD` |
| `100037` | 卡五星麻將 | 捕魚棋牌 | `BOARDCARD` |

`tablename` 結構為 `遊戲類型 + '#' + 名稱`，例 `BACCARAT#LV05`、`BOARDCARD#100033`。捕魚類前綴為 `FISHINGGAME`。

### 金額欄位對照

| 欄位 | 真人視訊 | 捕魚棋牌 |
|---|---|---|
| rollout `amount` | **下注金額** | 進入遊戲的**帶入金額** |
| rollin `amount` | 結算金額 | 用戶**當前餘額** |
| rollin `bet` | **下注金額** | 同局 rollout 的**帶入金額** |
| rollin `win` | **輸贏金額**（可為負，含 jackpot、不含抽水） | 用戶**當前餘額**；該局輸贏 = 當前餘額 − 帶入金額 |
| rollin `validbet` | 有效投注 | **文件未定義** |
| rollin `betpoint` | 逐注區明細 | **固定 null** |
| rollin `result` | 遊戲結果 | **固定 null** |

> 🔴 **捕魚棋牌的回調拿不到真實投注額**。回調只給得出「帶入」與「當前餘額」，相減得到該局淨輸贏；局內押了幾手、每手多少、有效投注多少，回調一律不帶。真實投注額要走下方的「轉帳錢包注單查詢 API」（`betAmount` 欄位，2026-10-01 實測可用）。
>
> 🔴 **實際送的 `bet` 與文件不符**：文件說捕魚棋牌的 `bet` = 同局帶入金額（正數），但 Yes2Win 正式環境 230 筆全部送**負的**帶入金額（例 `bet=-120.44`、`validbet=-120.44`），`validbet` 同樣是負數。詳見 `integration.md`「gaps / 待 Astar 確認」。

---

## 遊戲細單

### 細單詳情頁（前端網址）

讓客服與玩家查看詳細投注資訊、遊戲結果與輸贏。**前後台細單保留 3 個月（不含本月）** —— 例如 2024/04/15 只能往前查到 2024/01/01 之後。

```
{細單網頁網址}?token=[REDACTED]
```

| 環境 | 細單網頁網址 |
|---|---|
| 測試（試玩） | `https://example.invalid/history_detail/index.html` |
| 正式 | `https://example.invalid/history_detail/index.html` |

流程：玩家點開注單（網址帶 token）→ **Astar 呼叫本平台的 `detailtoken` 回調驗證 token**，取回 roundid 與玩家帳號 → Astar 用那些資訊渲染詳情頁。

頁面提供：網頁標題統一為 `{gamehall} 遊戲細單` / `{gamehall} GameDetail`；下注時間、結束時間、成單時間；遊戲名稱；單號、玩家帳號、代理帳號（皆由 `detailtoken` 提供，玩家端不顯示代理帳號）；總派彩；投注額與贏分；遊戲盤面與影片回放。

### 細單數據 API（Astar 提供，本平台主動呼叫）⭐

只取資料、不開頁面。**這是文件裡唯一一支「本平台 → Astar」的 outbound API。**

| 項目 | 值 |
|---|---|
| URI | `{GAME_URL}/client/api/{GAMEHALL}/detail` |
| Method | `GET` |
| `{GAMEHALL}` | **大寫**（Yes2Win = `TH41`） |

**Request**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `token` | string | ✅ | 細單連結 token，**由本平台自訂**；Astar 收到後會回呼本平台的 `detailtoken` 回調驗證 |
| `language` | string | ✗ | `zh-cn`（預設）/ `en` / `zh-tw` |

**Response（`value`）**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `CORRECTTIME` | datetime | ✅ | 注單結算時間（服務器時間） |
| `BETTIME` | datetime | ✅ | 玩家下注時間 |
| `STATUS` | string | ✅ | 注單狀態（`A` 已派彩 / `C` 注單取消） |
| `GAMENAME` | string | ✅ | 遊戲英文名稱 |
| `INNINGID` | long | ✅ | 牌局唯一 ID |
| `BETPOINT` | string | ✅ | 下注點位 |
| `WINPOINT` | string | ✅ | 開獎點位 / 結算點位 |
| `JP` | long | ✗ | 是否彩金注單 |
| `AMOUNT` | decimal | ✅ | **下注金額**（2 位小數，向下取整） |
| `CARDRESULT` | string | ✗ | 開牌結果 |
| `COMMAMOUNT` | decimal | ✅ | 抽水金額（2 位小數） |
| `USERID` | long | ✅ | 玩家唯一 ID |
| `USERNAME` | string | ✅ | 玩家用戶名 |
| `USER_RESULT` | decimal | ✅ | **玩家輸贏金額**（2 位小數） |
| `SHOWRESULT` | string | ✅ | 本地化後的開獎結果描述 |
| `channel` | string | ✅ | 遊戲廳 / 渠道 |
| `CURRENCY` | string | ✅ | 玩家幣別 |
| `CQ9ID` | string | ✅ | 第三方平台注單號 |
| `CQ9CORRECTTIME` | datetime | ✅ | 第三方結算時間（無則用 `CORRECTTIME`） |
| `TABLENUM` | string | △ | 桌號（展示用） |
| `COMPINFO` | string | △ | 廠商資訊 |
| `SNUM` | long | △ | 靴號 / 局序號 |
| `GNUM` | int | △ | 遊戲編號 |
| `LOBBYID` | long | △ | 大廳 ID |
| `TABLENUMORG` | string | △ | 原始桌號 |
| `ACCOUNT` | string | △ | 玩家帳號（條件性設置） |
| `LOGINDOMAIN` | string | ✅ | 公司 API 登入域名 |
| `TABLEID` | long | ✅ | 桌台唯一 ID |
| `COMMRATIO` | string | ✅ | 抽水比例 JSON |

**Example**

```bash
curl 'https://example.invalid/client/api/LP/detail?token=[REDACTED]' \
  -H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)' \
  -H 'Accept: application/json'
```

```jsonc
{
  "state": 0,
  "message": "ASTAR",
  "value": {
    "CORRECTTIME": "2024-08-19T13:22:45",
    "BETTIME": "2024-08-19T13:20:10",
    "GAMENAME": "Baccarat",
    "INNINGID": 987654321,
    "BETPOINT": "BANKER",
    "WINPOINT": "BANKER",
    "JP": 0,
    "AMOUNT": 100.00,
    "CARDRESULT": "BANKER WIN",
    "COMMAMOUNT": 5.00,
    "USERID": 123456,
    "USERNAME": "player_test_01",
    "USER_RESULT": -5.00,
    "SHOWRESULT": "庄赢",
    "channel": "LP",
    "CURRENCY": "CNY",
    "CQ9ID": "20240819-88990011",
    "CQ9CORRECTTIME": "2024-08-19T13:22:40",
    "TABLENUM": "A01",
    "COMPINFO": "Live Casino",
    "SNUM": 32,
    "GNUM": 101,
    "LOBBYID": 10,
    "TABLENUMORG": "GG#A01",
    "ACCOUNT": "player_test_01",
    "LOGINDOMAIN": "https://example.invalid",
    "TABLEID": 556677,
    "COMMRATIO": "{\"commissionRate\":\"0.05\"}"
  }
}
```

> ⚠️ **本平台目前呼叫不通，而且文件沒說捕魚棋牌的 `AMOUNT` 是什麼**：範例是百家樂（`AMOUNT` = 押注額）；捕魚棋牌的 `AMOUNT` 究竟是該局真實押注還是帶入金額，文件兩處都沒交代。呼叫前置條件與實作落差見 `integration.md`。

---

## 轉帳錢包注單查詢 API（Astar 提供，✅ 2026-10-01 實測可用）

> 來源：Astar《第三方接入游戏API文档》v3.0 ＋ 注單查詢章節（2026-10-01 由 PM 提供）。文件名義上是給轉帳錢包接入方用的，但**單一錢包商戶（我方 `GAMEHALL = TH41`）實測可以呼叫**。

| 項目 | Yes2Win 實際值 |
|---|---|
| API 域名 | `https://example.invalid`（**與遊戲域名 `GAME_URL` 不同台**） |
| 渠道編碼 `{channel}` | `TH41`（同 `GAMEHALL`） |
| `apiKey` | 另行取得，**不入 repo**；應存進 `vendor_infos.info`（目前尚未建欄位） |

**為什麼要用它**：這組是**唯一能拿到捕魚棋牌真實投注額**的管道。`version=3.1` / `playType=4` 的注單帶 `betAmount`、`validBetAmount`、`money`，正是 rollin 回調缺的欄位；而且批量拉取（單次最多 200 筆），不像細單 API 要一單一查、也不依賴我方 `detailtoken` 回調。

通用：回應皆為 `{state, message, value}`，`state = 0` 為成功。渠道不存在時回 `{"state":202,"message":"渠道不符"}`（HTTP 200）。

### 鑑權 — 換取 Bearer Token（所有注單 API 的前置）

`GET /channelApi/V3/API/{channel}/auth?apiKey=[REDACTED]

`value` 即安全令牌（JWT，實測長度 164）。**每次取得的 token 相同，請求一次即可**。之後所有注單 API 的 HTTP header 帶：

```
Authorization: Bearer {token}
```

| 情境 | HTTP |
|---|---|
| 沒帶 `Authorization` | **400** |
| `Authorization` 值錯誤（含直接把 `apiKey` 當 token 送） | **403** |
| 正常 | 200 |

> ⚠️ **踩過的坑**：直接送 `Authorization: {apiKey}` 一律回 403 且 body 為空，很容易誤判成 IP 白名單或金鑰無效。必須先走 `/auth` 換 token。另外請求要帶 `User-Agent`，否則部分路徑會回 HTML。

| API | URI | 用途 | 限流 |
|---|---|---|---|
| 注單詳情連結 | `/channelApi/V3/API/{channel}/getBetDetailUrl` | 取棋牌注單詳情頁 URL | — |
| 注單彙總 | `/channelApi/V3/API/{channel}/getRecordTotalByTime` | 時段彙總 | **10 秒 2 次** |
| 注單查詢（推薦） | `/channelApi/V3/API/{channel}/getRecordByCondition` | 時段分頁拉單 | **10 秒 10 次**，單次 ≤200 筆 |
| 注單查詢二 | `/channelApi/V3/API/{channel}/getRecordByTime` | 時段分頁拉單 | 同上 |
| 注單查詢三 | `/channelApi/V3/API/{channel}/getRecordByMaxId` | 依 `recordId` 水位拉單 | 同上 |

### getBetDetailUrl — 取棋牌注單詳情連結

`GET /channelApi/V3/API/{channel}/getBetDetailUrl?recordId={recordId}&lang={lang}`

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `recordId` | long | ✅ | 注單 ID |
| `lang` | string | ✗ | `zh-cn`（預設）/ `zh-tw` / `en-us` / `ko-kr` / `th-th` / `vi-vn` |

`value` 為注單詳情 URL 字串。

### getRecordTotalByTime — 注單彙總

`GET ...?startTime={startTime}&endTime={endTime}&agentName={agentName}`

`startTime` / `endTime` 格式 `yyyy-MM-dd HH:mm:ss`，**區間 31 天內**；`agentName` 選填。

```jsonc
{ "state": 0, "message": "成功", "value": {
    "totalOrder": 187,            // 總筆數
    "totalBetAmount": 266208.0,   // 總投注金額
    "totalCommAmount": 219764.1,  // 總洗碼金額
    "totalResultAmount": -38914.9 // 總輸贏金額
}}
```

### getRecordByCondition — 時段分頁拉單（官方推薦）

`GET ...?startTime=&endTime=&agentName=&correctTime=&pageIndex=&pageSize=&version=`

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `startTime` | string | ✅ | `yyyy-MM-dd HH:mm:ss`，**支援最近一個月**；空格須轉義為 `%20` |
| `endTime` | string | ✅ | 同上；**與 `startTime` 區間 24 小時內** |
| `agentName` | string | ✗ | 代理名稱 |
| `correctTime` | string | ✅ | 結算時間水位 = 上次拉到的最後一筆的結算時間；首次帶 `startTime` |
| `pageIndex` | int | ✗ | 頁碼，預設 1 |
| `pageSize` | int | ✗ | 每頁筆數，預設 100，範圍 0~200 |
| `version` | string | ✗ | `3.0`（預設）只有真人；**`3.1` 才含棋牌、捕魚** |

`value` 含 `gameRecords[]`、`lastRecordId`、`total`、`pageIndex`、`currPageSize`、`pageSize`。

### getRecordByTime — 時段分頁拉單

參數同上但**沒有 `correctTime`**，`pageIndex` 改為必填。

### getRecordByMaxId — 依 recordId 水位拉單（真人＋棋牌捕魚）

`GET ...?maxRecordId={maxRecordId}&type={type}&pageIndex={pageIndex}&pageSize={pageSize}`

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `maxRecordId` | long | ✅ | 上次拉到的最後一筆 `recordId`，每次拉完要更新 |
| `type` | int | ✗ | 預設 `0`，範圍 0~5。`0` 全部、`1` 真人、**`4` 棋牌**、`5` 捕魚 |
| `pageIndex` | int | ✅ | 頁碼 |
| `pageSize` | int | ✗ | 預設 100，範圍 0~200 |

> **這支最適合回補棋牌**：`type=4` 直接只要棋牌，且以 `recordId` 當水位不受時間窗限制。

### 注單欄位詳解

| 欄位 | 類型 | 說明 |
|---|---|---|
| `id` | int | 注單 id |
| `status` | string | `A` 已派彩 / `B` 未結算（狀態異常）/ `C` 注單取消 |
| `userName` | string | 用戶名稱 |
| `channel` | string | 渠道號 |
| `agent` | string | 代理號 |
| `createTime` | datetime | 建立時間，**北京時間** `yyyy-MM-dd HH:mm:ss` |
| `groupFor` | date | 更數（例：2024-05-31 12:00 ~ 06-01 12:00 的更數是 `2024-05-31`） |
| `gameType` | int | 真人視訊類型（1 百家樂、2 龍虎、3 輪盤、4 骰寶、5 牛牛、12 炸金花、14 色碟） |
| `tableId` | string | 桌號 |
| `roundId` | string | 局 id |
| **`betAmount`** | double | **投注額** |
| **`validBetAmount`** | double | **有效投注額** |
| `betPoint` | string | `status=A` 時為 `注點名:下注金額@輸贏額@洗碼量#`，多注點以 `#` 分隔；`status=C` 時只有 `注點名:下注金額#`（無輸贏額與洗碼量） |
| `odds` | double | 賠率（翻倍數） |
| `money` | double | 輸贏金額 |
| `currency` | string | 幣種 |
| `serviceMoney` | double | 服務費（抽水） |
| `beginTime` / `endTime` | string | 遊戲開始／結束時間，**北京時間** |
| `isBanker` | int | 已廢棄欄位 |
| `gameInfo` | string | 發牌情況（建議預留 1000 bytes） |
| `gameResult` | string | 開獎結果 |
| `jp` | int | `0` 非免佣 / `1` 免佣（僅百家樂莊注點生效） |
| `info1` | string | 額外欄位（建議預留 1000 bytes） |
| `shoeNum` / `gamblingNum` | int | 靴數 / 局數 |
| `enterType` | int | 下注方式：1 大廳 2 換桌列表 3 多台 4 桌台內 |
| `ip` | string | 玩家投注時客端 IP |
| **`recordId`** | long | 記錄 id（**V3.1**），`getRecordByMaxId` 的水位欄位 |
| **`playType`** | int | **`1` 真人 / `4` 棋牌 / `5` 捕魚**（V3.1） |
| `cname` / `ename` | string | 中文／英文遊戲名稱 |

---

## 遊戲 API（本平台實作，供 Astar 調用）

### Auth — 驗證 Game Token

驗證玩家 GameToken，回傳此玩家的所有設定資訊。

| 項目 | 值 |
|---|---|
| URI | `/gamepool/{gamehall}/player/auth` |
| Method | `POST` |

**Request**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `gametoken` | string | ✅ | 玩家進入遊戲連結中的 token |

**Response（`data`）**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `account` | string | ✅ | 玩家顯示帳號（長度限制 36 字元） |
| `balance` | float64 | ✗ | 玩家餘額 |
| `betthreshold` | JSON | ✅ | 限紅 `{maximum, minimum}`；無特殊設置皆 0 |
| `currency` | string | ✅ | 玩家幣別 |
| `id` | string | ✅ | 玩家 id（唯一值，登入名稱） |
| `parentid` | string | ✗ | 代理 id（唯一值）。非空時限 4–30 位數字、字母、底線；空值則沿用既有代理或建立 `{gamehall}-AGENT` |

```bash
curl --location --request POST '回調地址/gamepool/lp/player/auth' \
  --header 'Authorization: ASTAR-8888' \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'gametoken=[REDACTED]'
```

### Balance — 查詢餘額

餘額顯示在遊戲介面；扣款結算仍使用個人錢包。

| 項目 | 值 |
|---|---|
| URI | `/gamepool/{gamehall}/player/balance/{id}` |
| Method | `GET` |

**Request**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `id` | string | ✅ | 玩家 id（路徑參數） |
| `gamecode` | string | ✗ | 廠商遊戲代碼，可忽略 |

**Response（`data`）**：`balance`（float64，✅）、`currency`（string，✗）。

### Logout — 登出

| 項目 | 值 |
|---|---|
| URI | `/gamepool/{gamehall}/player/logout` |
| Method | `POST` |

**Request**：`gametoken`（string，✅）。**Response**：`data` 為空物件 `{}`。

### Rollout — 扣款

玩家個人錢包發生扣款行為。

| 項目 | 值 |
|---|---|
| URI | `/gamepool/{gamehall}/table/rollout` |
| Method | `POST` |

**Request**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `gametoken` | string | ✅ | 玩家遊戲 token |
| `id` | string | ✅ | 玩家 id |
| `mtcode` | string | ✅ | 交易代碼（`{env}-rollout-{roundid}`） |
| `round` | string | ✅ | 遊戲注單號 |
| `amount` | float64 | ✅ | 真人：下注金額；捕魚棋牌：帶入金額。最大 12 位數、小數後 4 位 |
| `betpoint` | string | ✅ | 下注資訊 `下注金額@輸贏額@洗碼量#`，多注點以 `#` 分隔（**僅限真人**） |
| `datetime` | datetime | ✅ | 投注時間 |
| `gamecode` | string | ✅ | 廠商遊戲代碼 |
| `ip` | string | ✅ | 客端 IP |
| `roundnumber` | string | ✅ | 遊戲局號 |
| `tabletype` | string | ✅ | 遊戲類型（見「遊戲類型與金額語意」） |
| `tablename` | string | ✅ | 桌台名稱（`類型#名稱`） |
| `tableid` | string | ✅ | 桌台 Id |

**Response（`data`）**：`amount`（✗）、`balance`（✅）、`currency`（✗）。

```bash
curl --location --request POST '回調地址/gamepool/lp/table/rollout' \
  --header 'Authorization: ASTAR-8888' \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'gametoken=[REDACTED]' \
  --data-urlencode 'id=37786249' \
  --data-urlencode 'mtcode=test-rollout-LP10817' \
  --data-urlencode 'round=LP10817' \
  --data-urlencode 'amount=10.00' \
  --data-urlencode 'betpoint=PLAYER:3000.00@0.00@0.00#' \
  --data-urlencode 'datetime=2024-08-11T21:53:19-04:00' \
  --data-urlencode 'gamecode=LP01' \
  --data-urlencode 'ip=0.0.0.0' \
  --data-urlencode 'roundnumber=75939860' \
  --data-urlencode 'tabletype=1' \
  --data-urlencode 'tablename=BACCARAT#LV05' \
  --data-urlencode 'tableid=3004'
```

### Rollin — 入款（結算）

結算金額轉至個人錢包。`rollin amount = rollout amount + win - rake - roomfee`。

> ⚠️ 這條公式**只對真人成立**。捕魚棋牌的 `amount` 直接等於 `win`（皆為「當前餘額」），Yes2Win 316 筆實證無一例外。

| 項目 | 值 |
|---|---|
| URI | `/gamepool/{gamehall}/table/rollin` |
| Method | `POST` |

**Request**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `gametoken` | string | ✅ | 玩家遊戲 token |
| `id` | string | ✅ | 玩家 id |
| `mtcode` | string | ✅ | 交易代碼（`{env}-rollin-{roundid}`） |
| `round` | string | ✅ | 遊戲注單號 |
| `amount` | float64 | ✅ | 真人：結算金額；捕魚棋牌：用戶當前餘額 |
| `bet` | float64 | ✅ | 真人：下注金額；捕魚棋牌：同局 rollout 帶入金額 |
| `betpoint` | string | ✅ | 下注資訊（**僅限真人**） |
| `win` | float64 | ✅ | 真人：輸贏金額（可為負）；捕魚棋牌：用戶當前餘額。含 jackpot、不含抽水 |
| `rake` | float64 | ✅ | 抽水金額 |
| `datetime` | datetime | ✅ | 結算時間 |
| `gamecode` | string | ✅ | 廠商遊戲代碼 |
| `roomfee` | float64 | ✗ | 開房費用 |
| `validbet` | float64 | ✅ | 有效投注 |
| `roundnumber` | string | ✗ | 遊戲局號 |
| `tabletype` | string | ✅ | 遊戲類型（見「遊戲類型與金額語意」） |
| `tablename` | string | ✅ | 桌台名稱 |
| `tableid` | string | ✅ | 桌 id |
| `result` | string | ✅ | 遊戲結果（**僅限真人**） |

**Response（`data`）**：`balance`（✗）、`currency`（✗）。

### Order Detail（Game Result）— 細單 Token 驗證

驗證細單 Token 正確性並取回相關細單資訊。**這是「細單數據 API」與細單詳情頁的前置**：Astar 拿到 token 後呼叫本支驗證，拿回 roundid 才能渲染。

| 項目 | 值 |
|---|---|
| URI | `/gamepool/{gamehall}/game/detailtoken` |
| Method | `POST` |

**Request**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `token` | string | ✅ | 細單連結 token。**由本平台自訂編碼**——文件建議以 md5 加密 roundid（例：要看 `LP10817` 的單，token 可設為 `LP10817`，或 md5 後的 `95140A069ADE0F57`） |

**Response（`data`）**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `roundid` | string | ✅ | 遊戲注單號 |
| `account` | string | ✅ | 玩家帳號（長度限制 36 字元） |
| `paccount` | string | ✗ | 代理帳號（由 Astar 依來源判斷是否需要，無值則細單頁不顯示） |
| `id` | string | ✗ | 玩家 id |

> 🔴 **token 是「一個 token 對應一筆 roundid」的識別碼，不是 session token。** 本平台現行實作拿它比對 `session_token`，語意錯誤且必定失敗，詳見 `integration.md`。

---

## 重送機制（Resend mechanism Flow）

Astar **每 15 分鐘**定時以 `Round Check` 對未完成的回合補單 / 退款，查詢範圍為**本月 1 號 0 點起至當前時間 10 分鐘以前**。

> 例：09:00 時撈取 `2024-08-01T12:00:00-04:00` ~ `2024-08-12T04:45:00-04:00` 區間的資料做補單、退款。
>
> v3 文件寫「每半小時」，新版改為每 15 分鐘。

**未完成的回合**：發生 Rollout 成功扣款，但無對應 mtcode 的 Rollin 或 Refund 紀錄。

- **補單**：廠商存在訂單紀錄，且 Astar 存在該遊戲注單 → 進行 Rollin
- **退款**：廠商存在訂單紀錄，但 Astar 不存在該遊戲注單 → 進行 Refund

### Round Check — 查詢未完成注單

RoundID 必須符合格式才查得到。

| 項目 | 值 |
|---|---|
| URI | `/gamepool/{gamehall}/game/roundcheck` |
| Method | `GET` |

**Request**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `fromdate` | string | ✅ | 開始時間，`yyyy-MM-dd'T'HH:mm:ssXXX` |
| `todate` | string | ✅ | 結束時間，同上格式（須早於現在 1 小時，否則 code 22） |

**Response（`data`）**：string array，每筆為 `roundid`。

```jsonc
{
  "data": ["LP10818", "LP10819", "LP10820"],
  "status": { "code": "0", "message": "success", "datetime": "2024-08-20T23:32:46-04:00", "tracecode": "check001" }
}
```

### Refund — 下注取消

依 rollout 的扣款金額退款至個人錢包。

| 項目 | 值 |
|---|---|
| URI | `/gamepool/{gamehall}/game/bet/refund` |
| Method | `POST` |

**Request**

| 參數 | 類型 | 必要性 | 說明 |
|---|---|---|---|
| `mtcode` | string | ✅ | 交易代碼（refund 情境下即為純 roundid，無 `{env}-{action}-` 前綴） |
| `userid` | string | ✗ | 玩家 id |

**Response（`data`）**：`balance`（✗）、`currency`（✗）。

> 本平台實作以 mtcode 推回 round（純字串或三段式皆可），詳見 `integration.md`「MTcode 校驗」。

---

## 遊戲特殊說明

- **真人遊戲**：每一個 round 的 rollout 都會有一個相同 round 的 rollin 對應，**一對一關係**。
- **捕魚棋牌**：文件未明說配對關係。Yes2Win 實證為「一次 rollout + 一次 rollin = 一局」，且同帳號相鄰兩局中 169/220 的下一局帶入金額等於上一局的當前餘額（整疊籌碼每局重鎖），間隔中位數 35 秒。

---

## 錯誤碼（Error Code）

成功為 `code = "0"`，HTTP 一律 200。

| code | message |
|---|---|
| 0 | success |
| 1 | Insufficient balance |
| 2 | Player not found |
| 3 | Bet is not allowed |
| 4 | Token invalid |
| 5 | Authorization invalid |
| 6 | Player is frozen |
| 7 | Bad parameters |
| 8 | Game is not found or disabled |
| 9 | Invalid GameToken |
| 10 | Invalid Round |
| 11 | Invalid MTCode |
| 12 | Trying to cancel a bet from an already closed round |
| 13 | Rollout is exist |
| 14 | Rollin is exist |
| 15 | Round ended already |
| 16 | Data not found |
| 17 | Refund not allowed |
| 18 | Already refunded |
| 19 | GameTeam not found |
| 20 | GameCode is duplicate |
| 21 | Duplicate game registered |
| 22 | ToDate must earlier then now one hour |
| 23 | Single wallet recover failed |
| 24 | Recover failed |
| 25 | Failed log compair error |
| 26 | Duplicate record |
| 27 | Duplicate order |
| 29 | Cannot connect to other services |
| 30 | Illegal opetation |
| 31 | Order already exists |
| 32 | Index not found |
| 33 | Record not found |
| 34 | Order not found |
| 35 | Amend log not found |
| 36 | Failed log not found |
| 37 | Duplicate amends |
| 38 | Not refund yet |
| 39 | Genre is not valid |
| 40 | Cannot be refunded again |
| 41 | Time format error |
| 42 | No past time |
| 43 | Rollout record not found |
| 44 | Currency does not match Agent's currency |
| 45 | Failed to process record or Bet record status is not complete |
| 46 | Indexid Status does not equal to failure or not found |
| 47 | Duplicate request in a moment |
| 49 | Single wallet server error. |
| 50 | Refund failed. Please try again later. |
| 53 | The transaction can not be refunded. |
| 55 | Order already closed. |
| 56 | Token not found. |
| 57 | Agent's wallet insufficient balance |
| 100 | Something wrong |
| 101 | Get balance failed |
| 102 | Auth service error |
| 103 | Order service error |

> 表中跳號 `48` / `51` / `52` / `54`（原因不明）；`message` 拼字（`compair` / `opetation`）為官方原樣保留。對應 `App\Vendors\Astar\Enums\ErrorCodeEnum`。

---

## 幣種

| 代碼 | 類型 | 代碼 | 類型 |
|---|---|---|---|
| CNY | 人民幣 | AED | 迪拉姆 |
| USD | 美元 | TWD | 新台幣 |
| KRW | 韓元 | PKR | 巴基斯坦盧比 |
| HKD | 港元 | MXN | 墨西哥幣 |
| AUD | 澳元 | BRL | 巴西幣 |
| THB | 泰銖 | SGD | 新加坡幣 |
| VND | 越南盾 | CLP | 智利比索 |
| VND(K) | 越南盾（K） | PEN | 秘魯新索爾 |
| IDR | 印尼盾 | VES | 委內瑞拉玻利瓦爾 |
| IDR(K) | 印尼盾（K） | HNL | 宏都拉斯倫皮拉 |
| MYR | 馬來西亞令吉 | CRC | 哥斯大黎加科朗 |
| INR | 印度盧比 | GTQ | 瓜地馬拉格查爾 |
| MMK | 緬甸基亞特 | TZS | 坦尚尼亞先令 |
| KES | 肯亞先令 | GBP | 英鎊 |
| USDT | 泰達幣 | PHP | 菲律賓披索 |
| BDT | 孟加拉塔卡 | | |
