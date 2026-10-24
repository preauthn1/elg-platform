# Wcasino（WCasino / WON）— 廠商 API 規格

> 原始來源：
> - `WCasino API_LiveCasino_SeamlessWallet_v3.2 en.pdf`（主要依據，2024 版，最後更新記錄 2023-02-13 V1.5）
> - `WON API视讯游戏接口_单一钱包_v3.1_中文.pdf`（中文對照，版號較舊）
> - `WON API_LiveCasino_SeamlessWallet_v3.1 en.pdf`（舊英文版，已由 v3.2 取代）
> - `其他資訊.txt`（補充時區與資料保留期說明）
>
> 抓取日期：2026-06-03
>
> 我方實作分析、落差清單與整合落地建議見同目錄 `integration.md`。

Wcasino 為線上視訊博彩遊戲商（WCasino / WON 品牌），本專案以 **Seamless Wallet（單一錢包）** 模式接入（vendor_id=111）。遊戲商不持有玩家餘額，所有扣款與入款均透過回調由代理端處理。

---

## 通用約定

### 請求格式

- **Encoding**：UTF-8
- **Query 請求**（代理主動呼叫）：HTTP GET/POST，參數以 Query String 傳入
- **回調請求**（Wcasino → 代理）：HTTP POST，支援 Form POST 與 JSON 兩種格式；APIs 專案採用 **JSON**（`Content-Type: application/json`）
- 參數 key **全小寫**，且**不做 URLEncode**（官方文件特別強調）

### 通用請求欄位（代理主動呼叫 Wcasino 端 API）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | Y | 代理應用程式 ID（`CLIENT_ID`） |
| `sign` | String | Y | 簽名，見「簽名演算法」章節 |

### 通用回應格式

```json
{
  "result": 0,
  "desc": "succeed"
}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `result` | Int | `0` 為成功；負數為錯誤碼（見錯誤碼附錄） |
| `desc` | String | 描述訊息 |

> `result >= 0` 為成功；`result < 0` 為異常。

### Base URL

| 環境 | URL |
|---|---|
| 正式 | `vendor_infos.info.AUTH_URL`（各白標設定不同，由 Wcasino 提供） |
| 測試 | `vendor_infos.info.AUTH_URL`（測試環境另行申請） |

> 文件範例中可見 `https://example.invalid`，但此 URL 是否為正式生產 Base URL 未在文件中明確確認，實際以代理 `AUTH_URL` 設定為準。

---

## 簽名演算法

> 適用於所有**代理主動呼叫**的 API（Login、Kickout、UserUpdate、QuotaSet 等）。
> 回調端（Wcasino → 代理）的驗簽邏輯相同，但須額外排除 `bets` 欄位再計算。

### 演算法總覽

| 項目 | 值 |
|---|---|
| 雜湊演算法 | MD5 |
| 編碼 | UTF-8 |
| 參數序列化格式 | `key=value` 以 `&` 連接 |
| 排序方式 | 所有參數 key 轉小寫後按 ASCII 升序排列（空值參數不參與簽名） |
| 附加步驟 | 排序後的字串末尾追加 `&key={CLIENT_SECRET}` |
| 輸出格式 | MD5 hex 字串（32 字元小寫） |

> 官方文件特別標注：**不要對參數做 URLEncode**，直接使用原始值拼接後計算 MD5。

### Step 1 — 收集所有非空值參數（排除 sign 與 bets 欄位）

```text
appid    = wxd930ea5d5a258f4f
body     = test
device_info = 1000
mch_id   = 10000100
nonce_str = ibuaiVcKdpRxkhJA
```

### Step 2 — key 全部轉小寫，按 ASCII 升序排列並拼接

```text
stringA = "appid=wxd930ea5d5a258f4f&body=test&device_info=1000&mch_id=10000100&nonce_str=ibuaiVcKdpRxkhJA"
```

### Step 3 — 末尾追加 `&key={CLIENT_SECRET}`

```text
stringSignTemp = stringA + "&key=192006250b4c09247ec02edce69f6a2d"
```

### Step 4 — 計算 MD5

```text
sign = MD5(stringSignTemp)   // hex lowercase，32 字元
```

### PHP 完整範例（代理主動呼叫用）

```php
private function sign(array $fields): string
{
    $data = [];
    foreach ($fields as $key => $value) {
        $data[] = strtolower($key) . '=' . $value;
    }
    sort($data);
    $rawData = implode('&', $data) . '&key=' . $this->infoDetail->CLIENT_SECRET;

    return md5($rawData);
}
```

> 來源：`app/Vendors/Wcasino/Driver.php`

### 回調端驗簽（`WcasinoSWController::verifySign()`）

Wcasino 回調（`/api/deduct`、`/api/deposit`、`/api/rollback`）請求中包含一個 `bets` JSON 欄位，**該欄位不加入簽名計算**。驗簽時需先移除 `sign` 與 `bets` 後再走上述流程。

```php
unset($data['sign'], $data['bets']);
$data = array_change_key_case($data, CASE_LOWER);
$data = array_filter($data);             // 移除空值
ksort($data);
$dataString = urldecode(http_build_query($data));
$encryptedKey = md5($dataString . '&key=' . $vendorInfoDetail->CLIENT_SECRET);
return $sign === $encryptedKey;
```

> 注意：`http_build_query` 會做 URL encode，因此用 `urldecode` 還原後再拼接 `&key=`，與文件「不做 URLEncode」的要求一致。

### 常見錯誤

| 症狀 | 可能原因 |
|---|---|
| Wcasino 回 `result: -1007` (Sign error) | CLIENT_SECRET 錯誤；或對空值欄位做了排序（空值需排除） |
| 回調驗簽失敗 | `bets` 欄位未排除；或字串做了額外 URL encode |
| 本地簽名一致但驗簽仍失敗 | key 大小寫問題，Wcasino 要求 key 全小寫後才排序 |

---

## 代理主動呼叫 API（Wcasino 端）

### 2.2 Login — 取得遊戲進入網址（/api/login）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | Y | 代理 ID |
| `username` | String | Y | 玩家帳號 |
| `nickname` | String | N | 暱稱（若空值則隨機產生） |
| `state` | Byte | N | 帳號狀態：1=開放, 0=禁止, -1=鎖定, -2=禁止下注, -3=最大贏, -4=最大輸 |
| `commratio` | String | N | 佣金比例欄位 |
| `quotas` | String | N | 下注限額 ID（逗號分隔，最多 3 個，見 `/api/quota/list`） |
| `iscreate` | Byte | Y | 帳號不存在時是否自動建立（`1`=是） |
| `clienttype` | Byte | Y | 裝置類型（1=PC, 2=手機, 3=iPad） |
| `language` | Byte | Y | 語言代碼（見語言代碼附錄） |
| `portrait` | String | N | 玩家頭像 |
| `currency` | String | N | 幣種（CNH、USD 等） |
| `returnurl` | String | N | 返回原網站的 URL |
| `gt` | Byte | N | 指定遊戲類型 |
| `gid` | Integer | N | 指定進入遊戲 ID |
| `ist` | Byte | N | 試玩模式（ist=1 時 Demo 餘額 1000，無記錄） |
| `token` | String | N | Company Session Token |
| `sign` | String | Y | 簽名 |

**Response（成功）**：

```json
{
  "result": 0,
  "openurl": "http://example.invalid/api/opengame.jsp?code=...&language=1",
  "desc": "succeed"
}
```

| 欄位 | 說明 |
|---|---|
| `openurl` | 玩家進入遊戲大廳的 URL |

### 2.3 Kick — 強制踢出玩家（/api/kickout）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | Y | 代理 ID |
| `username` | String | Y | 玩家帳號 |
| `sign` | String | Y | 簽名 |

### 2.5 修改玩家資訊（/api/user/update）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | Y | 代理 ID |
| `username` | String | Y | 玩家帳號 |
| `nickname` | String | N | 暱稱 |
| `state` | Byte | N | 帳號狀態 |
| `commratio` | String | N | 佣金比例 |
| `quotas` | String | N | 下注限額 ID |
| `sign` | String | Y | 簽名 |

### 2.6 取得玩家資訊（/api/user/info）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | Y | 代理 ID |
| `username` | String | Y | 玩家帳號 |
| `sign` | String | Y | 簽名 |

**Response data 欄位**：

| 欄位 | 說明 |
|---|---|
| `state` | 帳號狀態 |
| `betstate` | 下注狀態 |
| `commratio` | 佣金比例 |
| `quota` | 下注限額 |
| `balance` | 玩家餘額 |
| `recharge` | 累計充值 |
| `drawtotal` | 累計提款 |
| `lastlogintime` | 最後登入時間 |
| `loginname` | 登入帳號 |
| `createtime` | 建立時間 |
| `nickname` | 暱稱 |

### 2.7.4 取得下注限額列表（/api/quota/list）

GET，回傳 `array` 欄位（`ID`、`title`、`up`=MAX、`down`=MIN）。

### 2.7.5 設定下注限額（/api/quota/set）

POST，參數 `qids`（逗號分隔 quota ID，最多 3 個）。

### 2.10 取得注單（/api/data/getbets）

GET，`size` <= 2000，以 `id` + `serialnumber` 為主鍵；拉取後須呼叫 Remove Bets 清除。

> **重要**：保留期僅 **48 小時**，逾期資料不可再取，須配合 2.11 移除注單成對使用。

### 2.11 移除注單（/api/data/removebets）

POST，`ids` 為逗號分隔的 Bet ID。確認已拉取並本地儲存後才呼叫；呼叫後資料從 Wcasino 端刪除。

### 2.12 遊戲列表（/api/data/getgames）

GET，回傳遊戲 ID、名稱、遊戲類型等。

遊戲狀態值：

| 值 | 說明 |
|---|---|
| `-1` | 關閉 |
| `0` | 閒置 |
| `1` | 開放 |
| `2` | 封鎖 |
| `3` | 維護 |

### 2.13 遊戲歷史（/api/record/game/history）

GET，分頁查詢，依 `gameid` + `index` + `size` 取得局史。

### 2.14 下注統計（/api/record/bets/stat）

GET，依時間戳（秒）查詢特定玩家的總下注筆數、金額、輸贏。

### 2.14.1 下注統計 v2（/api/record/bets/statistic）

GET，支援按遊戲組/幣種分組統計（`gtype` 參數）。

### 2.15 下注明細（/api/record/bets/detail）

GET，分頁查詢注單詳情，每頁最多 2000 筆。保留期 **60 天**。

注單 `state` 狀態碼：

| 值 | 說明 |
|---|---|
| `-4` | 網路異常 |
| `-3` | 錯誤 |
| `-2` | 取消下注 |
| `-1` | 作廢下注 |
| `0` | 未結算 |
| `1` | 已結算 |
| `2` | 補結算 |

### 2.16 玩家帳變明細（/api/record/account/detail）

GET，查詢玩家帳戶操作紀錄（Deposit/Withdraw/Bet/Settle/Cancel 等）。

### 2.17 重新結算注單（/api/user/bets/settle）

GET/POST，對未結算注單強制重算。

> ⚠️ **警告：一旦結算後不可回退下注金額，且已結算的注單亦無法再次呼叫。**

### 2.18 荷官小費紀錄（/api/record/reward/detail）

GET，查詢玩家給荷官的小費紀錄，分頁最多 2000 筆，保留期 60 天。

---

## Seamless Wallet 回調 API（Wcasino → 代理）

Wcasino 在玩家下注、結算、退款時，主動 HTTP POST 至代理提供的回調 URL。代理必須實作以下 4 個 endpoint：

| # | 用途 | APIs 路由 |
|---|---|---|
| 1 | 查詢玩家餘額 | `GET /wcasino/api/balance` |
| 2 | 扣款（下注 / 小費） | `POST /wcasino/api/deduct` |
| 3 | 入款（派彩 / 取消 / 重新結算） | `POST /wcasino/api/deposit` |
| 4 | 回滾（異常補償） | `POST /wcasino/api/rollback` |

> 文件將上述 4 個 endpoint 以 HTTP/HTTPS（Form POST）與 JSON 兩種傳輸格式分別描述（Section 2.8 vs 2.9），功能完全相同；APIs 專案使用 **JSON 格式**（`Content-Type: application/json`）。

### 通用約定

#### 安全驗證

- **IP 白名單**：Wcasino 會從固定 IP 發起回調，代理須設定白名單過濾（`Common::verifyIPWhitelistByVendorId()`）。
- **簽名驗證**：每個回調請求皆含 `sign` 欄位，演算法見「簽名演算法」章節。
- `bets` 欄位**不參與簽名計算**，驗簽時需先移除。

#### 冪等機制

每次回調都有唯一的 `notifyid`。因網路異常 Wcasino 可能重複發送相同請求，代理必須透過 `notifyid`（搭配 `serialnumber` + `type`）做冪等判斷，避免重複扣款或入款。

APIs 專案透過 `wcasinosw_notify_records` 表 + `UNIQUE(bet_id, notify_id, type, is_rollback)` 唯一鍵實現。

#### API Type（操作類型碼）

| Code | 說明 | 錢包操作 |
|---|---|---|
| `1` | Bet（下注） | 扣款 |
| `7` | Void Bet（作廢下注，含玩家 / BO 兩種情境） | 扣款或入款 |
| `9` | Settle（派彩） | 入款 |
| `10` | Re Settle（重新結算） | 扣款或入款 |
| `28` | User Tips（玩家小費） | 扣款 |

### 1. 查詢玩家餘額（Balance）

Wcasino 在玩家進入遊戲時，或網路中斷後恢復時呼叫此 API 查詢玩家最新餘額。

| 項目 | 值 |
|---|---|
| Method | `GET` |
| Path | `/wcasino/api/balance`（代理提供） |

#### Request 參數（Query String）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | Y | 代理應用程式 ID（`CLIENT_ID`） |
| `username` | String | Y | 玩家帳號（唯一識別碼） |
| `notifyid` | String | Y | 通知 ID，每次請求唯一，用於冪等去重 |
| `sign` | String | Y | 簽名 |
| `token` | String | N | Merchant Token（JSON 格式版帶此欄位） |

#### Success Response

```json
{
  "result": 0,
  "desc": "OK",
  "balance": "20000"
}
```

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `result` | Int | Y | `0` 為成功，負數為錯誤碼（見附錄） |
| `desc` | String | Y | 描述 |
| `balance` | Double | Y | 玩家目前餘額（代理幣值） |

#### Error Response 範例

```json
{ "result": -1030, "desc": "Insufficient balance" }
```

---

### 2. 扣款（Deduct）

玩家下注或小費時，Wcasino 呼叫此 API 從代理端扣除對應金額。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Path | `/wcasino/api/deduct`（代理提供） |
| Content-Type | `application/json` |

#### 觸發時機

- 玩家確認下注（type=1 Bet）
- 玩家給荷官小費（type=28 User Tips）
- 同一次通知中**可能包含同一玩家的多筆下注**（購買區多押）

#### Request Body

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | Y | 代理應用程式 ID |
| `username` | String | Y | 玩家帳號 |
| `amount` | Double | Y | 扣款金額（**負數**） |
| `notifyid` | String | Y | 通知 ID，用於冪等去重 |
| `type` | Int | Y | API Type（見上方對照表） |
| `serialnumber` | String | Y | 注單序號（Bet and Reward No） |
| `currency` | String | N | 幣種（CNH、USD 等） |
| `sign` | String | Y | 簽名 |
| `token` | String | N | Merchant Token |
| `bets` | JSONObject | N | 下注明細（**不參與簽名**），包含：`id`、`serialnumber`、`userid`、`username`、`gametype`、`gameid`、`gamerounno`、`choosebankpoint`、`betpoint`、`betamount`、`winlost`、`commamount`、`commratio`、`createtime`、`state` |

#### Request 範例

```json
{
  "appid": "{appid}",
  "type": 1,
  "amount": -50.0,
  "serialnumber": "{serialnumber}",
  "currency": "CNH",
  "username": "{username}",
  "notifyid": "{notifyid}",
  "sign": "{sign}",
  "token": "{token}",
  "bets": [{ "id": 12, "serialnumber": 12, "betamount": 50.0, "gamerounno": 2098, "gametype": 2, "state": 1, "...": "..." }]
}
```

#### Success Response

```json
{
  "result": "0",
  "desc": "OK",
  "balance": "20000",
  "orderno": "1000305"
}
```

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `result` | Int | Y | `0` 成功 |
| `desc` | String | Y | 描述 |
| `balance` | Double | Y | 扣款後玩家餘額 |
| `serialnumber` | String | Y | 回傳同一筆序號（供三方後續核帳） |
| `orderno` | String | Y | 代理端訂單號 |

#### 錯誤回傳規則

| 情境 | 應回傳 result |
|---|---|
| 成功 | `0` |
| 餘額不足 | `-1030` |
| 其他系統錯誤 | `-1001` |

---

### 3. 入款（Deposit）

結算、取消、重新結算、玩家主動取消下注、平台退款時，Wcasino 呼叫此 API 將金額回存至代理玩家帳戶。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Path | `/wcasino/api/deposit`（代理提供） |
| Content-Type | `application/json` |

#### 觸發時機

1. 玩家贏得彩金（Settle，type=9）：同一回合若代理無任何注單，不會通知。
2. 重新結算（Re Settle，type=10）：同上，無注單則不通知。
3. 取消無效注單（type=7 Void Bet）：無注單則不通知。
4. 玩家主動取消下注（type=7）。
5. 平台扣款失敗補款（Refund）。

> 一次入款通知**可能包含多位玩家的資料**（同回合多人獲獎）。

#### Request Body

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | Y | 代理應用程式 ID |
| `username` | String | Y | 玩家帳號 |
| `amount` | Double | Y | 入款金額（**正數**） |
| `notifyid` | String | Y | 通知 ID |
| `type` | Int | Y | API Type |
| `serialnumber` | String | Y | 注單序號 |
| `currency` | String | N | 幣種 |
| `sign` | String | Y | 簽名 |
| `token` | String | N | Merchant Token |
| `bets` | JSON | N | 注單明細（同 Deduct，**不參與簽名**） |

#### Success Response

```json
{
  "result": "0",
  "desc": "OK",
  "balance": "20000",
  "orderno": "1000305"
}
```

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `result` | Int | Y | `0` 成功 |
| `desc` | String | Y | 描述 |
| `orderno` | String | Y | 代理端訂單號 |
| `balance` | Double | Y | 入款後玩家餘額 |

> `type=7 CANCEL` 依 `amount` 正負區分：`amount >= 0` 為 `executeBetCancel`；`amount < 0` 為 `executeSettlementCancel`。

---

### 4. 回滾（Rollback）

當 Deduct 或 Deposit 請求超時/無回應，或回傳 HTTP 403、405 等異常時，Wcasino 呼叫此 API 通知代理回滾。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Path | `/wcasino/api/rollback`（代理提供） |
| Content-Type | `application/json` |

#### 觸發條件

1. Deduct/Deposit 請求長時間無回應 → 呼叫 rollback。
2. Deduct/Deposit 回傳 HTTP 異常（403、405 等） → 呼叫 rollback。
3. rollback 若連續失敗 3 次，Wcasino 端記錄進資料庫並停止重試。

#### 重要注意事項（文件原文紅字，四個 Note）

- **Note I**：rollback 通知到達時，原始 deposit/deduct 不一定已成功，代理需先以 `notifyid + type + serialnumber` 核實是否有已處理的紀錄，再決定是否回滾。
- **Note II**：rollback 只針對**最後一筆** deposit/deduct 請求，不是針對下注單本身。
- **Note III**：若代理**未收到**原始 deposit/deduct（例如網路斷線），rollback 到達時不需實際回滾，直接回傳成功即可。
- **Note IV**：rollback 的 type 與對應 deposit/deduct 相同。

#### Request Body

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | Y | 代理應用程式 ID |
| `username` | String | Y | 玩家帳號 |
| `amount` | Double | Y | 正數 |
| `notifyid` | String | Y | 通知 ID |
| `type` | Int | Y | API Type（與對應 deduct/deposit 一致） |
| `serialnumber` | String | Y | 注單序號 |
| `errmsg` | String | Y | 錯誤訊息（URLEncoder 編碼） |
| `token` | String | N | Merchant Token |
| `sign` | String | Y | 簽名 |

#### Success Response

```json
{
  "result": "0",
  "desc": "OK",
  "balance": "20000"
}
```

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `result` | Int | Y | `0` 成功 |
| `desc` | String | Y | 描述 |
| `balance` | Double | Y | 目前玩家餘額 |

#### Rollback 各 type 處理邏輯

| type | 原始動作 | 回滾動作 |
|---|---|---|
| `1` (BET) | Deduct 扣款 | `executeBetCancel`：取消注單，餘額還原 |
| `7` (CANCEL) | Deduct/Deposit Cancel | `executeRollbackBetCancel`：反取消，還原為 PENDING |
| `9` (SETTLE) | Deposit 派彩 | `executeSettlementCancel`：退回派彩，還原為 PENDING |
| `10` (RESETTLE) | Re Settle | `executeRollbackResettlement`：反重新結算 |

---

## 狀態機一覽

```
初始
  │
  ▼
[PENDING] ──── Deduct(type=1) ─────────────────── 扣款，建立 BetRecord
  │
  ├── Rollback(type=1) ──────────────────────────── 取消注單，還原餘額
  │
  ├── Deposit(type=7, amount>=0) ─────────────────── BetCancel → CANCELLED
  │       └── Rollback(type=7) ─────────────────── 反取消 → PENDING
  │
  └── Deposit(type=9) ──────────────────────────── 結算 → SETTLED
          │
          ├── Rollback(type=9) ─────────────────── 退派彩 → PENDING
          │
          ├── Deposit(type=10) ─────────────────── ReSettle（win_lose += delta）
          │       └── Rollback(type=10) ────────── 反重新結算
          │
          └── Deposit(type=7, amount<0) ────────── SettlementCancel → PENDING
```

---

## 遊戲類型（Game Type Code）

| Code | 遊戲 |
|---|---|
| `4` | Baccarat（百家樂；Gamestyle Code 1-11=傳統, 12=Super Baccarat） |
| `5` | Roulette（輪盤） |
| `10` | Dragon Tiger（龍虎；Gamestyle 1-11=傳統, 12=Super Dragon Tiger） |
| `11` | Sic Bo（骰寶） |
| `18` | Color Disc（色盤） |
| `19` | Fish Prawn Crab（魚蝦蟹） |

## 語言代碼

| Code | 縮寫 | 語言 |
|---|---|---|
| `1` | CN | 簡體中文 |
| `2` | US | English |
| `5` | TH | ภาษาไทย |
| `6` | HK | 繁體中文 |
| `10` | VN | Tiếng Việt |
| `11` | ID | Bahasa |

---

## 資料保留期

| 資料類型 | 保留期 |
|---|---|
| GETBETS / REMOVE BETS | **48 小時** |
| 注單/帳變/小費明細（record/detail） | **60 天** |
| 前台資料（玩家查詢） | 永久保留可查詢 |

> 來源：`其他資訊.txt`。對帳工具須在 60 天期限內完成拉取。

時區：**UTC+8（CST/SGT）**

---

## 錯誤碼附錄

| Code | 適用介面 | 說明 |
|---|---|---|
| `0` | 全部 | 成功 |
| `-1000` | 全部 | 系統未知錯誤 |
| `-1001` | 全部 | 系統內部異常 |
| `-1002` | 全部 | 參數錯誤 |
| `-1005` | 全部 | Appid 錯誤 |
| `-1006` | 全部 | Appid 不存在 |
| `-1007` | 全部 | 簽名錯誤 |
| `-1008` | 全部 | 已過期 |
| `-1009` | 全部 | 請求過於頻繁 |
| `-1010` | 全部 | 非法 IP |
| `-1011` | 用戶介面 | 未註冊 |
| `-1012` | 用戶介面 | 用戶不存在 |
| `-1013` | 用戶介面 | 用戶已存在 |
| `-1014` | 用戶介面 | 用戶被禁止 |
| `-1015` | 用戶介面 | 密碼錯誤 |
| `-1020` | 錢包介面 | 用戶餘額不足 |
| `-1021` | 錢包介面 | 存取款金額不得為零 |
| `-1022` | 用戶介面 | 限額不存在 |
| `-1030` | 錢包介面 | 扣款金額不足 |
| `-1040` | 驗證碼 | 驗證碼錯誤 |
| `-1041` | 全部 | 非法域名 |
| `-3002` | 全部 | 餘額不足（玩家端顯示） |
| `-3003` | 全部 | 超過下注設定金額 |
| `-3004` | 全部 | 帳戶無法遊戲 |
| `-3006` | 全部 | 達到最大贏取限額 |
| `-3007` | 全部 | 達到最大損失限額 |
| `-3008` | 全部 | 下注限度尚未設定 |
| `-3009` | 全部 | 下注限度已變更，請重試 |
| `-3014` | 全部 | 遊戲暫停服務 |
| `-3017` | 全部 | 發生錯誤 [4003] |
| `-3018` | 全部 | 發生錯誤 [4004] |
| `-3020` | 全部 | 發生錯誤 [5003] |
