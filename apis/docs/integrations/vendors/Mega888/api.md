# Mega888 — 三方 API 規格

> 來源：整合自原始廠商對接文檔（README，含端點與簽名規格）。
> 文件版本 v1.4（2018-08-08），以本專案實作為準。
> 本檔為廠商 API 純規格；本平台落地分析見 `integration.md`。

---

## 通用約定

### JSON-RPC 2.0 請求格式

```jsonc
POST <API_URL><method>
Content-Type: application/json

{
  "id": "<UUID，同 params.random>",
  "method": "open.mega.xxx",
  "params": {
    "random": "<UUID 隨機字串>",
    "digest": "<簽名摘要，見簽名節>",
    "sn": "<厅代码>",
    "...": "其餘業務欄位"
  },
  "jsonrpc": "2.0"
}
```

> 文件 §接口示例 採 `application/x-www-form-urlencoded` 且 body 為 `json=<JSON字串>`；本專案實作採 `application/json` 直送 JSON 物件。

### 通用回應格式

成功（`error` 為 `null`）：

```jsonc
{
  "id": "<請求 id>",
  "result": { "...": "業務結果，型別依各 API" },
  "error": null,
  "jsonrpc": "2.0"
}
```

失敗（`error` 為物件）：

```jsonc
{
  "id": "<請求 id>",
  "error": {
    "code": "2206",
    "sn": "demo",
    "message": "登录名(loginId)已存在,注册用户失败",
    "reason": "xxx",
    "action": null
  },
  "detail": { "cxt": "/user-api", "method": "open.mega.user.create", "elapsed": 4, "result": null },
  "jsonrpc": "2.0"
}
```

`response['error']` 非 `null` 即視為失敗；成功時讀 `response['result']`。

### Base URL

| 環境 | URL |
|---|---|
| 測試（文件 §测试环境） | `http://example.invalid/mega-cloud/api`（厅代码 `SN: ld00`） |
| 正式 | 文件未載明，以 Startup Form / 商務提供為準，存入 `vendor_infos.info.API_URL` |

> 注意：文件測試環境出現兩個不一致的網域（`example.invalid/mega-cloud/api` 與 `example.invalid/mega/api/`）。

### 連線限制

HTTP 連線須控制在 100 個以內。

---

## 會員管理 API（本平台 → Mega）

### 1. 創建會員帳號 `open.mega.user.create`

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` | string | ✅ | UUID |
| `digest` | string | ✅ | `md5(random + sn + secretCode)` |
| `sn` | string | ✅ | 厅代码 |
| `nickname` | string | ❌ | 會員暱稱 |
| `agentLoginId` | string | ✅ | 代理商帳號 |

回應 `result`：`success`(bool) / `userId`(long) / `nickname` / `regType` / `loginId`。

> 建帳號取得的 `loginId` 寫入 `mega888_accounts.player_id`，`userId` 寫入 `mega888_accounts.user_id`。

---

### 2. 查詢會員資訊 `open.mega.user.get`

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` / `digest` / `sn` | — | ✅ | `digest = md5(random + sn + loginId + secretCode)` |
| `loginId` | string | ✅ | 用戶登入 ID |

回應 `result` 主要欄位：`sn` / `userId` / `name` / `nickname` / `regTime` / `currency`(幣種 ID) / `userStatus`(見用戶狀態對照) / `loginId` / `regType` / `regIp` / `loginLastUpdateTime` / `loginIp` / `loginCount` / `remark`。

---

### 3. 啟用 / 停用 / 凍結 / 解凍 / 在線狀態 / 登出

以下 API 參數結構相同（`random` / `digest = md5(random + sn + loginId + secretCode)` / `sn` / `loginId`）：

| method | 用途 | `result` 語意 |
|---|---|---|
| `open.mega.user.enable` | 啟用會員 | `1` 成功 / `0` 失敗 |
| `open.mega.user.disable` | 停用會員 | `1` / `0` |
| `open.mega.user.block` | 凍結帳戶（凍結後禁止出款） | `1` / `0` |
| `open.mega.user.unblock` | 解凍帳戶 | `1` / `0` |
| `open.mega.user.isOnline` | 是否在線 | `1` 在線 / `0` 不在線 |
| `open.mega.user.logout` | 會員登出 | `1` 成功 / `0` 失敗 |

> 文件部分 post 範例把 `digest` key 誤拼為 `diest`（typo），以欄位說明表的 `digest` 為準。

---

## 錢包 API（本平台 → Mega）

### 4. 查詢帳戶餘額 `open.mega.balance.get`

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` / `digest` / `sn` | — | ✅ | `digest = md5(random + sn + loginId + secretCode)` |
| `loginId` | string | ✅ | 用戶登入 ID |

回應 `result`：`double` 帳戶餘額。

---

### 5. 額度轉換 `open.mega.balance.transfer`

控制玩家在 Mega 端錢包額度的轉入／轉出。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` / `digest` / `sn` | — | ✅ | `digest = md5(random + sn + loginId + amount + secretCode)` |
| `loginId` | string | ✅ | 用戶登入 ID |
| `amount` | string | ✅ | **負數＝從 Mega 轉出（提款）**，**正數＝轉入（存款）**，支援 2 位小數 |
| `bizId` | long | ❌ | 轉帳業務 ID，由調用方提供 |
| `checkBizId` | string | ❌ | `1` 檢查 bizId 唯一性 / `0` 不檢查（預設） |

回應 `result`：`float` 轉帳後帳戶餘額。

---

### 6. 額度轉換記錄查詢 `open.mega.balance.transfer.query`

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` / `digest` / `sn` | — | ✅ | `digest = md5(random + sn + secretCode)` |
| `loginId` | string | ✅ | 用戶登入 ID |
| `agentLoginId` | string | ✅ | 代理登入 ID |
| `startTime` / `endTime` | date | ❌ | 查詢時間區間 |
| `bizId` | long | ❌ | 業務單據 ID |
| `sortHint` | string | ❌ | `asc` / `desc` |
| `pageIndex` | int | ❌ | 預設 1 |
| `pageSize` | int | ❌ | 預設 15 |
| `etag` | string | ❌ | 快取加速標記 |

回應 `result`：`items`（記錄列表）/ `total` / `pageIndex` / `pageSize` / `stats`（小計）/ `etag`。
`items[]`：`cashflowId` / `sn` / `userId` / `accountItem`(會計項目) / `operateTime` / `amount` / `bizId` / `balance`。
`stats`：`userCount` / `incomeAmount` / `expenditureAmount` / `balanceAmount`。

---

## 遊戲與下載

### 7. 取得 App 下載地址 `example.invalid.url.download`

Mega888 為原生 App 下載式入口，**無 web/mobile 直開遊戲 URL**。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` / `digest` / `sn` | — | ✅ | `digest = md5(random + sn + secretCode)` |
| `agentLoginId` | string | ✅ | 代理登入帳號 |

回應 `result`：`string` App 下載連結。

> 本專案 `getGameUrl()` 呼叫此 API，回傳 `VendorGameUrlResultData` 中 `pc`/`mobile` 為空字串，App 下載連結來自 `vendor_infos.info.DOWNLOAD_URL` / `DOWNLOAD_URL2`（非 API 回傳值）。

---

## 報表

### 8. 查詢遊戲記錄 `open.mega.player.game.log.url.get`

回傳查看符合條件遊戲記錄的 URL 字串（非結構化資料）。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` / `digest` / `sn` | — | ✅ | `digest = md5(random + sn + loginId + secretCode)` |
| `loginId` | string | ✅ | 用戶登入 ID |
| `startTime` | DateTime | ✅ | 例 `2018-01-01 00:00:00` |
| `endTime` | DateTime | ✅ | 例 `2018-01-01 23:59:59` |

回應 `result`：查看遊戲記錄的 URL。

> 注單明細**只能查最近 5 天**，單次跨度 ≤ 1 天、每次 ≤ 1000 筆。

---

### 9. 查玩家總帳 `open.mega.player.total.report`

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` / `digest` / `sn` | — | ✅ | `digest = md5(random + sn + agentLoginId + secretCode)` |
| `agentLoginId` | string | ✅ | 代理登入帳號 |
| `type` | string | ❌ | 報表類型，預設 `1`；mega 電游 / BG 視訊 = `1` / `2` |
| `startTime` / `endTime` | string | ✅ | 查詢區間 |

回應 `result`：`List<Map>`，每筆含 `bet`(下注額) / `win`(輸贏) / `yield`(收益率) / `userId` / `loginId` / `name` / `memo` / `tel`。

---

### 10. 視訊注單分頁列表 `open.mega.video.order.page`

（v1.4 新增，BG 視訊用）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` / `digest` / `sn` | — | ✅ | `digest = md5(random + sn + loginId + secretCode)` |
| `loginId` | string | ✅ | 會員登入帳號 |
| `startTime` / `endTime` | string | ✅ | 查詢區間 |
| `pageIndex` | int | ❌ | 預設 1 |
| `pageSize` | int | ❌ | 預設 20 |

回應 `result`：`items` / `total` / `pageIndex` / `pageSize` / `etag` / `stats`。
`items[]` 主要欄位：`orderId` / `tranId` / `sn` / `uid` / `loginId` / `moduleId` / `moduleName` / `gameId` / `gameName` / `orderStatus` / `bAmount`(下注額) / `aAmount`(結算額) / `validBet`(打碼量) / `payment`(派彩/輸贏) / `orderFrom`(1 pc/2 Android/3 iOS/4 pad/5 h5) / `orderTime` / `orderTimeBj`(北京時間) / `lastUpdateTime` / `issueId` / `playId` / `playName`。
`stats`：`userCount` / `bAmountTotal` / `aAmountTotal` / `validAmountTotal` / `validBetTotal` / `paymentTotal`。

---

### 11. 視訊注單詳細 `open.mega.video.order.detail`

（v1.4 新增）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` / `digest` / `sn` | — | ✅ | `digest = md5(random + sn + loginId + secretCode)` |
| `loginId` | string | ✅ | 會員登入帳號 |
| `orderId` | long | ✅ | 注單號 |

回應 `result` 主要欄位：`sn` / `userId` / `loginId` / `orderId` / `gameId` / `gameType` / `gameName` / `state`(0 未結算/1 已結算/2 注單錯誤/4 結算錯誤/8 已取消) / `bettingType` / `time` / `amount`(下注金額) / `payment`(結算金額) / `serialNo`(局號) / `tableId` / `result`(開獎結果) / `baccarat` / `openingTime` / `paymentTime` / `validBet`(打碼量) / `noComm`(1 免佣/0 非免佣) / `fmtBetResult` / 結果圖片清單 / `infoStaticHtml`。

---

## 登入驗證 callback（Mega → 本平台）

文件 §会员登入 明載此介面「由接入方實現供 Mega 服務調用」。

### `open.operator.user.login`

**方向**：Mega → 本平台

**本專案路由**：`POST /external/tw/mega888/login`（`routes/external/tw.php`）

**本專案 Controller**：`app/Http/Controllers/External/TransferWallets/Mega888Controller@login`

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `random` | string | ✅ | UUID |
| `digest` | string | ✅ | `md5(random + sn + loginId + secretCode)` |
| `sn` | string | ✅ | 厅代码 |
| `loginId` | string | ✅ | 玩家登入帳號 |
| `password` | string | ✅ | 玩家登入密碼 |

本平台回應 `result`（仍包在 JSON-RPC envelope 中）：

| 欄位 | 值 |
|---|---|
| `success` | `'1'` 成功 / `'0'` 失敗 |
| `sessionId` | Laravel Session Id（成功時） |
| `msg` | 說明文字（如 `'登錄成功'`、`'帳號不存在'`、`'密碼錯誤'`、`'Method錯誤'`） |

**驗證流程**：
1. IP 白名單（`DetectIp` middleware）
2. method 檢查：僅接受 `open.operator.user.login`
3. `params.loginId` 查 `Mega888Account.player_id`
4. `Hash::check(params.password, account.password)`

> 本專案目前**未驗證 digest**——見 `integration.md` 高風險陷阱#2。

---

## 簽名

MD5 digest 演算法：

```
digest = strtoupper(md5(random + SN + <string> + API_KEY))
```

### 各 API digest 明文組成

| API method | digest 明文（文件載明） | `<string>` |
|---|---|---|
| `open.mega.user.create` | `random + sn + secretCode` | 空字串 |
| `open.mega.user.get` / `enable` / `disable` / `block` / `unblock` / `isOnline` / `logout` | `random + sn + loginId + secretCode` | `loginId` |
| `open.mega.balance.get` | `random + sn + loginId + secretCode` | `loginId` |
| `open.mega.balance.transfer` | `random + sn + loginId + amount + secretCode` | `loginId + amount` |
| `open.mega.balance.transfer.query` | `random + sn + secretCode` | 空字串 |
| `open.operator.user.login`（callback） | `random + sn + loginId + secretCode` | `loginId` |
| `open.mega.player.game.log.url.get` | `random + sn + loginId + secretCode` | `loginId` |
| `example.invalid.url.download` | `random + sn + secretCode` | 空字串 |
| `open.mega.player.total.report` | `random + sn + agentLoginId + secretCode` | `agentLoginId` |
| `open.mega.video.order.page` / `open.mega.video.order.detail` | `random + sn + loginId + secretCode` | `loginId` |

本專案 `Driver::digest()` 實作：

```php
private function digest(string $string): array
{
    $random = Str::uuid();
    return [
        'random' => $random,
        'digest' => strtoupper(md5($random . $this->vendorInfoDetailData->SN . $string . $this->vendorInfoDetailData->API_KEY)),
    ];
}
```

**注意事項**：
- 文件 §摘要算法 文字描述用小寫 `md5(...)`，但 §额度转换 / §会员登入 範例為大寫 hex；本專案以 `strtoupper()` 輸出大寫，與範例一致。
- 文件 §接口示例 以 `secretCode = sha1Base64(agentPassword)` 推導密鑰；本專案直接用 `API_KEY` 當 secretCode（未做 SHA1），推測 `API_KEY` 已是衍生後的值。

---

## 用戶狀態對照 / 注單狀態

### 用戶狀態（`userStatus`）

| Code | 狀態名稱 | 說明 |
|---|---|---|
| 1 | 啟用 | 正常狀態 |
| 2 | 掛起 | 未啟用 |
| 3 | 取消 | — |
| 4 | 停用 | 禁止登入 |
| 5 | 凍結 | 允許登入、下注，不能出款 |
| 6 | 停權 | 允許登入，禁止下注、出款 |
| 7 | 預註冊 | 未啟用 |

> 此為 Mega 端 `userStatus`，與本專案 `mega888_accounts.status`（0 關閉 / 1 正常）不同維度。

### 視訊注單狀態（`video.order.page` 的 `orderStatus`）

| 值 | 狀態 |
|---|---|
| 1 | 未結算 |
| 2 | 結算贏 |
| 3 | 結算和 |
| 4 | 結算輸 |
| 5 | 取消 |
| 6 | 過期 |
| 7 | 系統取消 |

### 視訊注單詳細狀態（`video.order.detail` 的 `state`）

| 值 | 狀態 |
|---|---|
| 0 | 未結算 |
| 1 | 已結算 |
| 2 | 注單錯誤 |
| 4 | 結算錯誤 |
| 8 | 已取消 |

---

## 錯誤碼

文件僅示範一個錯誤碼：

| code | 說明 |
|---|---|
| `2206` | 登入名（loginId）已存在，注冊用戶失敗 |

完整錯誤碼表文件未提供，其餘錯誤以 `error.message` 欄位描述。
