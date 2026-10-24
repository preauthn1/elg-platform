# FFFSW（FFF Marble League）— API 整合文件

> 原始來源：
> - `三方API說明-代理-v0.0.5.html`（代理主動呼叫 API，版本 0.0.5 / 2024-12-01）
> - `三方API訂單通知-0.0.2.html`（訂單通知回調建議規格，版本 0.0.2 / 2024-10-16）
> - `sample_code_0.0.2/Php_HashCrypt_0.0.2_20241003/ApiAuth.php`（官方 PHP 簽名 / 加密範例）
>
> 抓取日期：2026-06-03
>
> 我方實作分析見 integration.md

> ⚠️ **錢包模式待確認**：專案 config 標 `is_single_wallet=1`，但官方代理 API 同時包含轉帳錢包端點，亦另有回調規格，採哪套資金流尚未在程式碼體現。詳見 integration.md。

---

## 通用約定

### 代理主動 API 格式（代理 → FFFSW）

| 項目 | 值 |
|---|---|
| HTTP Method | 一律 `POST` |
| Content-Type | `application/x-www-form-urlencoded` |
| Header `referer` | 貴司 server IP |
| Header `content_type` | `application/x-www-form-urlencoded` |

每個請求帶三個 POST body 欄位：

| 欄位 | 說明 |
|---|---|
| `params` | 業務參數 ksort → query string → AES-256-CBC 加密 + Base64 後的密文 |
| `sign` | `md5(明文 query string + md5Key)` |
| `key` | md5Key（**明文帶入**） |

業務參數中每個請求都必帶 `_t`（unix timestamp 秒）。詳見〈簽名 / 加密〉節。

### 訂單通知回調格式（FFFSW → 業主，若採回調式接入）

| 項目 | 值 |
|---|---|
| HTTP Method | `POST` |
| Content-Type | `application/json` |
| Body | 明文 JSON（無 `params` / `sign` 包裝） |

### 通用回應格式（FFFSW → 代理，代理主動 API）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | `0` 表成功，非 0 表失敗 |
| `message` | String | 描述（可能為簡中） |
| `data` | Object \| Array | 回傳資料；無資料時為空物件 / 空陣列 |
| `_.rt` | Int | 回應時間（unix timestamp） |
| `_.et` | Float | 執行時間（秒） |

```jsonc
{ "code": 0, "message": "XXXXX", "data": {}, "_": { "rt": 1710741526, "et": 0.0468 } }
```

### Base URL

| 環境 | URL |
|---|---|
| 測試 / 正式 | 文件標示「請向我方索取」（gaps，見 integration.md） |

專案從 `vendor_infos.info['API_URL']` 讀取，Driver `sendRequest` 串接各 API path。

### 系統規則

1. 正常一律回 HTTP 200；收到 **HTTP 500 代表 FFFSW 服務異常**，應掛起維護、停止發送 API 請求並聯絡客服。
2. 系統時區為 **UTC+8**；時間區間查詢**最多 5 分鐘（300 秒）**。
3. 所有金額支援至小數第 2 位。

---

## 代理主動呼叫 API

| # | 用途 | Endpoint | 本平台狀態 |
|---|---|---|---|
| 1 | 取得遊戲連結（登入） | `POST /open/v2/user/login` | ✅ 已實作（`Driver::getGameUrl`） |
| 2 | 玩家存款（轉入） | `POST /open/v2/transfer/charge` | 未實作 |
| 3 | 玩家取款（轉出） | `POST /open/v2/transfer/withdraw` | 未實作 |
| 4 | 玩家餘額查詢 | `POST /open/v2/transfer/wallet` | 未實作 |
| 5 | 注單查詢（依建立時間） | `POST /open/v2/order` | 未實作 |
| 6 | 注單查詢（依結算時間） | `POST /open/v2/order/updated` | 未實作 |
| 7 | 開獎記錄 | `POST /open/v2/lottery/history` | 未實作 |
| 8 | 轉帳記錄查詢 | `POST /open/v2/transfer/exchangelog` | 未實作 |
| 9 | 用戶下注限額 | `POST /open/v2/user/betlimit` | 未實作 |
| 10 | 用戶下注狀態 | `POST /open/v2/user/betStatus` | 未實作 |

---

### 1. 取得遊戲連結（登入）

`POST /open/v2/user/login`

**Request（加密前業務參數）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `xusername` | String | ✅ | 玩家帳號（2–16 字，首字英文小寫，符號僅允許 `-` 或 `_`） |
| `xpassword` | String | ✅ | 玩家密碼 |
| `cry` | String | ✅ | 玩家幣別（小寫），預設 `cny` |
| `lang` | String | Optional | 進入語系，預設 `zh`（簡中）；見〈附錄〉 |
| `_t` | Int | ✅ | unix timestamp（秒） |

**Response `data`**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `username` | String | 玩家帳號 |
| `balance` | Float | 玩家餘額（小數 2 位） |
| `token` | String | 玩家權杖 |
| `game_url` | String | 登入連結 |

```jsonc
{
  "code": 0,
  "message": "登录成功",
  "data": {
    "username": "play01",
    "balance": 98099,
    "token": "195b7e627a868a847f0c53a98c8077a1",
    "game_url": "http://example.invalid/pc/#/game?token=[REDACTED]"
  }
}
```

> APIs 實作：`Driver::getGameUrl()` 呼叫此端點，取 `data.game_url` 作為連結；只有大廳，無需帶 `gameCode`。`Driver::mapLanguageCode()` 對映語系：`2→tw`、`3→zh`、`6→vn`、`7→td`，其餘 default `en`。

---

### 2. 玩家存款（轉入）

`POST /open/v2/transfer/charge`

**Request（加密前業務參數）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `order_no` | String | ✅ | 訂單編號（限 30 字） |
| `amount` | Int | ✅ | 存入金額 |
| `_t` | Int | ✅ | timestamp |

**Response `data`**

| 欄位 | 說明 |
|---|---|
| `username` | 玩家帳號 |
| `balance` | 轉入後餘額 |
| `order_no` | 訂單編號 |

---

### 3. 玩家取款（轉出）

`POST /open/v2/transfer/withdraw`

Request 同存款（`username` / `order_no` / `amount` / `_t`）。

**Response `data`**

| 欄位 | 說明 |
|---|---|
| `username` | 玩家帳號 |
| `balance` | 轉出後餘額 |
| `order_id` | FFFSW 系統訂單編號 |

---

### 4. 玩家餘額查詢

`POST /open/v2/transfer/wallet`

**Request（加密前業務參數）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `_t` | Int | ✅ | timestamp |

**Response `data`**：`username`、`balance`。

---

### 5. 注單查詢（依建立時間）

`POST /open/v2/order`

**Request（加密前業務參數）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `from_time` | String | ✅ | 開始時間（`Y-m-d H:i:s`，UTC+8） |
| `end_time` | String | ✅ | 結束時間（UTC+8，區間 **≤ 5 分鐘**） |
| `_t` | Int | ✅ | timestamp |
| `username` | String | Optional | 玩家帳號 |
| `status` | Int | Optional | 注單狀態碼篩選 |
| `limit` | Int | Optional | 每頁筆數（預設 100） |
| `page` | Int | Optional | 頁碼 |
| `lottery_id` | Int | Optional | 彩種 ID |
| `lang` | String | Optional | 語系 |

**Response**

分頁欄位：`total` / `per_page` / `current_page` / `last_page` + `data[]`。

每筆注單欄位（節錄）：

| 欄位 | 說明 |
|---|---|
| `id` | 注單編號 |
| `period_number` | 下注期數 |
| `status.id` | 注單狀態碼（見〈附錄〉） |
| `status.name` | 狀態說明 |
| `amount` | 下注總金額 |
| `amount_unit` | 每注金額 |
| `qty` | 下單注數 |
| `odds` | 賠率 |
| `bonus_amount` | 派彩金額 |
| `bonus_qty` | 中獎注數 |
| `create_time` | 下注時間 |
| `bonus_time` | 結算時間（可能為 null） |
| `lottery.name` | 彩種名稱（如 `Marbles 10PK`） |
| `playName` | 下注玩法名稱 |

---

### 6. 注單查詢（依結算時間）

`POST /open/v2/order/updated`

Request / Response 結構與「注單查詢（依建立時間）」完全相同，差異為查詢時間軸以**結算時間**為準。

---

### 7. 開獎記錄

`POST /open/v2/lottery/history`

**Request（加密前業務參數）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `lottery_id` | Int | ✅ | 彩種 ID |
| `_t` | Int | ✅ | timestamp |
| `start` | String | Optional | 查詢日期（`Y-m-d`，UTC+8）；有 `start` 則回該天全部開獎，`limit` 失效 |
| `limit` | Int | Optional | 回傳筆數（預設 100，最多 300）；無 `start` 時才生效 |

**Response `data`**：`counter` + `lists[]`（每筆含 `lottery_id`、`open_datetime`、`period_number`、`lottery_number`）。

---

### 8. 轉帳記錄查詢

`POST /open/v2/transfer/exchangelog`

> ⚠️ 只能查**最近 7 天**。

**Request（加密前業務參數）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `from_time` | String | ✅ | 開始時間（UTC+8） |
| `end_time` | String | ✅ | 結束時間（UTC+8，限 7 天內） |
| `_t` | Int | ✅ | timestamp |
| `order_no` | String | Optional | 訂單編號篩選 |
| `lang` | String | Optional | 語系 |

**Response `data.data[]` 欄位**

| 欄位 | 說明 |
|---|---|
| `order_no` | 訂單編號 |
| `type_id` | `166` = 轉入；`-166` = 轉出 |
| `before_amount` | 轉帳前餘額 |
| `amount` | 轉帳金額 |
| `after_amount` | 轉帳後餘額 |
| `create_time` | 建立時間 |
| `info` | 備註 |
| `currency_id` | 幣別 ID |
| `currency_code` | 幣別代碼 |

---

### 9. 用戶下注限額

`POST /open/v2/user/betlimit`（v0.0.3 新增）

**Request（加密前業務參數）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `xusername` | String | ✅ | 玩家帳號 |
| `bet_maximum` | Decimal | Optional | 最高下注限額 |
| `bet_minimum` | Decimal | Optional | 最低下注限額 |
| `_t` | Int | ✅ | timestamp |

**Response `data`**：空陣列。

---

### 10. 用戶下注狀態

`POST /open/v2/user/betStatus`（v0.0.5 新增）

**Request（加密前業務參數）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `xusername` | String | ✅ | 玩家帳號 |
| `status` | Int | ✅ | `0` = 允許下注；`1` = 鎖定下注 |
| `_t` | Int | ✅ | timestamp |

**Response `data`**：`username`、`status`。

---

## 訂單通知回調 API（FFFSW → 業主，若採回調式接入）

> **本平台目前未實作任何 FFFSW 回調 controller / route**（`routes/external/` 與 `app/Http/Controllers/External/` 皆無命中）。以下為 FFFSW 建議規格。

**串接方式（官方「下方選項二則一」）**：
1. 業主提供自家通知串接文件（FFFSW 照業主規格實作），或
2. 業主實作 FFFSW 的建議規格（本節內容）。

**回應約定**：業主回 `code: 0` 表同意，`code > 0` 表拒絕（FFFSW 以 `code` 判定，HTTP 狀態非依據）。

**金額正負號慣例**：`order.amount` 下注為**負數**，中獎 / 撤單為**正數**。

---

### 回調 1. 扣款通知（下注）

**Request（FFFSW → 業主，JSON）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account` | String | ✅ | 玩家帳號 |
| `order.id` | String | ✅ | 注單號碼 |
| `order.period_number` | String | ✅ | 期號 |
| `order.amount` | Float | ✅ | 下注總金額（**負數**） |
| `order.amount_unit` | Int | ✅ | 下注單位 |
| `order.qty` | Int | ✅ | 下注數 |
| `order.create_time` | String | ✅ | 下注時間 |

```jsonc
{
  "account": "f06041067",
  "order": {
    "id": "24081400001",
    "period_number": "20240410219",
    "amount": -1,
    "amount_unit": -1,
    "qty": 1,
    "create_time": "2024-08-15 13:57:51"
  }
}
```

**Response（業主 → FFFSW）**

```jsonc
{ "code": 0, "message": "成功", "data": {} }   // 同意
{ "code": 1, "message": "失敗", "data": {} }   // 拒絕
```

---

### 回調 2. 取得餘額

**Request（FFFSW → 業主，JSON）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account` | String | ✅ | 玩家帳號 |

```jsonc
{ "account": "f06041067" }
```

**Response（業主 → FFFSW）**

> ⚠️ `balance` 在**頂層**（其他三種回調的資料在 `data` 內）：

```jsonc
{ "code": 0, "message": "success", "balance": 1045.00 }   // 同意
{ "code": 1, "message": "error",   "balance": 0 }          // 拒絕
```

---

### 回調 3. 中獎通知

**Request（FFFSW → 業主，JSON）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account` | String | ✅ | 玩家帳號 |
| `order.id` | String | ✅ | 注單號碼 |
| `order.period_number` | String | ✅ | 期號 |
| `order.amount` | Float | ✅ | 下注總金額（中獎時為**正數**） |
| `order.amount_unit` | Int | ✅ | 下注單位 |
| `order.bonus_amount` | Float | ✅ | **中獎金額** |
| `order.qty` | Int | ✅ | 下注數 |
| `order.create_time` | String | ✅ | 下注時間 |

```jsonc
{
  "account": "f06041067",
  "order": {
    "id": "24081400001",
    "period_number": "20240410219",
    "amount": 1.35,
    "amount_unit": 1.35,
    "bonus_amount": 2.7,
    "qty": 1,
    "create_time": "2024-08-15 13:57:51"
  }
}
```

**Response**

```jsonc
{ "code": 0, "message": "成功", "data": {} }   // 同意
{ "code": 1, "message": "失敗", "data": {} }   // 拒絕
```

---

### 回調 4. 撤單通知

**Request（FFFSW → 業主，JSON）**

欄位同「扣款通知」（`account` + `order.{id, period_number, amount, amount_unit, qty, create_time}`），`amount` 為**正數**（退回）。

```jsonc
{
  "account": "f06041067",
  "order": {
    "id": "24081400001",
    "period_number": "20240410219",
    "amount": 1.35,
    "amount_unit": 1.35,
    "qty": 1,
    "create_time": "2024-08-15 13:57:51"
  }
}
```

**Response**

```jsonc
{ "code": 0, "message": "成功", "data": {} }   // 同意
{ "code": 1, "message": "失敗", "data": {} }   // 拒絕
```

---

## 簽名 / 加密

### 演算法總覽

| 項目 | 值 |
|---|---|
| 對稱演算法 | AES-256-CBC |
| IV | 每次隨機產生（`openssl_random_pseudo_bytes`），**附在密文前綴**一起 Base64 |
| Padding | PKCS7（OpenSSL 預設） |
| AES key 來源 | `vendor_infos.info['AES_KEY']` |
| 簽名演算法 | `md5(明文 query string + md5Key)` |
| MD5 key 來源 | `vendor_infos.info['MD5_KEY']`（亦以 `key` 明文帶入 POST body） |

### 構成步驟

1. 業務參數陣列依 key 值**升序排序**（`ksort`）
2. 組成 query string（`k=v` 以 `&` 串接）
3. 將 query string 用 **AES-256-CBC** 加密（IV 前綴 + Base64）→ 放入 `params`
4. 將 **明文 query string**（非密文）與 md5 key 拼接後做 **MD5** → 放入 `sign`
5. POST body：`params`（密文）+ `sign` + `key`（明文 md5 key）

> `sign` 對的是**明文 query string**，非加密後的 `params`。

### 官方 PHP 範例

```php
// 1. 排序組 query string
ksort($params);
$data = implode('&', array_map(fn($k, $v) => "$k=$v", array_keys($params), $params));

// 2. AES-256-CBC 加密（IV 前綴 + Base64）
$iv  = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
$enc = base64_encode($iv . openssl_encrypt($data, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv));

// 3. 簽名
$sign = md5($data . $md5Key);

// 4. POST body
$postData = ['params' => $enc, 'sign' => $sign, 'key' => $md5Key];
```

### 本平台實作對照（`Driver.php`）

| 步驟 | Driver 方法 | 一致性 |
|---|---|---|
| 排序組 query string | `makeParams()`（`ksort` + `k=v` + `implode('&')`） | ✅ |
| AES-256-CBC 加密 | `encrypt()`（隨機 IV + `iv.ciphertext` Base64） | ✅ |
| 簽名 | `produceSign()`（`md5($data . $signature)`） | ✅ |
| POST body | `['params' => $enc, 'sign' => $sign, 'key' => $md5Key]` | ✅（含明文 `key`） |

Driver 以 `asForm()` 送出，`timeout => 5`，`verify => false`（停用 SSL 驗證，對應官方 cURL 範例）。

### 回調驗簽（FFFSW → 業主方向）

官方提供 `validNotify`：取出 `sign`、移除後對其餘參數 `makeParams` 再 `produceSign` 比對。

> 訂單通知文件（0.0.2）的回調 body 為**明文 JSON**，並未展示 `params`/`sign` 包裝；回調驗簽機制視協商結果而定，須向客服確認。

### 常見錯誤

| 症狀 | 可能原因 |
|---|---|
| `code != 0` 且 message 含解密相關 | AES key 錯，或 IV 前綴 / Base64 流程不一致 |
| sign 驗不過 | `sign` 對的是**明文 query string**（非密文），且需先 `ksort`；或 md5 key 錯 |
| 欄位錯位 | 未做 `ksort`，雙方 query string 順序不一致 |

---

## 附錄

### 支援語系

| code | 語系 |
|---|---|
| `zh` | 簡中 |
| `tw` | 繁中 |
| `en` | 英文 |
| `vn` | 越南文 |
| `td` | 泰文 |
| `kr` | 韓文 |
| `pt` | 葡萄牙文 |
| `jp` | 日文 |
| `91` | 印地文 |
| `mx` | 西班牙文 |
| `id` | 印尼文 |

> `Driver::mapLanguageCode()`：`2→tw`、`3→zh`、`6→vn`、`7→td`，其餘 default `en`（`kr` / `pt` / `jp` / `91` / `mx` / `id` 尚未對映）。

### 支援幣別

`cny` / `usd` / `sgd` / `myr` / `vnd` / `thb` / `idr` / `twd` / `krw` / `inr` / `bdt` / `azn` / `amd` / `byn` / `kgs` / `mdl` / `kzt` / `rub` / `uzs` / `tzs` / `php`。預設 `cny`。

### 注單狀態碼（`status.id`，代理 API 注單查詢）

| `status.id` | 說明 |
|---|---|
| `0` | 下注（進行中） |
| `1` | 未中獎（已結算） |
| `2` | 已中獎 |
| `3` | 和（退款） |
| `-1` | 撤單 |

### FffswBetRecord 狀態對映

| `fffsw_bet_records.status` | 意義 | 對應 FFFSW `status.id` |
|---|---|---|
| `-2` | 回滾 | — |
| `-1` | 取消 | `-1` 撤單 |
| `0` | 未完成 | `0` 下注 |
| `1` | 已扣款 | `1` 未中獎 |
| `2` | 已派彩 | `2` 已中獎 / `3` 和 |

---

## Change Log（官方文件）

**代理 API（`三方API說明-代理`）**

| 版本 | 日期 | 重點 |
|---|---|---|
| 0.0.1 | 2024-09-30 | 初版 |
| 0.0.2 | 2024-10-03 | 新增用戶轉帳查詢 |
| 0.0.3 | 2024-11-01 | 新增用戶下注限額設定 |
| 0.0.4 | 2024-11-04 | 新增貨幣 |
| 0.0.5 | 2024-12-01 | 新增用戶下注狀態設定 |

**訂單通知（`三方API訂單通知`）**

| 版本 | 日期 | 重點 |
|---|---|---|
| 0.0.1 | 2024-08-15 | 初版 |
| 0.0.2 | 2024-10-16 | 增加餘額 / 中獎 / 撤單說明 |
