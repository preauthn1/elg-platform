# FumaSW — 三方 API 規格

> 本檔為廠商 API 純規格；本平台落地分析見 `integration.md`。
>
> 原始文件：`FumaGams无缝钱包API说明文档2026-01-14.pdf`（Ver 1.04，Date: 2026-1-4）

---

## 通用約定

### 請求格式（代理 → FumaGames）

- **HTTP Method**：`POST`
- **Content-Type**：`application/x-www-form-urlencoded`
- 每次請求帶兩個 form 參數：

| 參數 | 說明 |
|---|---|
| `dc` | 客戶域名識別碼（由 FumaGames 分配） |
| `x` | JSON payload 以 AES-128-CBC 加密後的 Base64 URL Safe 密文 |

### 回調格式（FumaGames → 代理）

- **HTTP Method**：`POST`
- **Content-Type**：`application/x-www-form-urlencoded`
- FumaGames 同樣以 `dc` + `x` 兩個欄位傳入代理 callback URL
- 代理必須先解密 `x` 才能取得 `action`、`uid`、`transferId` 等欄位

### 通用回應格式（代理 → FumaGames）

代理回傳 **JSON**，`Content-Type: application/json`：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | String(4) | 成功：`"0000"`；失敗見[錯誤碼](#錯誤碼) |
| `balance` | Double | 操作後玩家當前餘額（action 1/2/3 均須帶） |
| `err_text` | String(255) | 失敗時說明原因（選填） |

### Base URL

文件以 `{API_Address}` / `{HttpUrl}` 佔位，**正式 Base URL 由 FumaGames 客服提供，文件未揭露**（見 `integration.md` Gaps）。

---

## 加密（代理 → FumaGames）

### 憑證欄位

| 欄位 | 說明 | 儲存位置 |
|---|---|---|
| `dc` | 客戶域名識別碼（由 FumaGames 分配） | `vendor_infos.info.DC` |
| `KEY` | AES 加密金鑰（16 bytes） | `vendor_infos.info.KEY` |
| `IV` | AES 初始向量（16 bytes） | `vendor_infos.info.IV` |

### 加密步驟

1. 將請求參數組合成 JSON Object，序列化為 UTF-8 字串（`$jsonString`）
2. **Space Padding**：計算 `$jsonString` 長度對 16 取餘，不足部分以空格 `' '` 補齊至 16 的倍數
3. 呼叫 `openssl_encrypt($data, 'AES-128-CBC', $key, OPENSSL_NO_PADDING, $iv)` 加密
4. 對加密結果進行 `base64_encode()`
5. 替換 URL 不安全字元：`+` → `-`、`/` → `_`、`=` → `''`（移除）
6. 最終密文即為 form 參數 `x` 的值

### PHP 範例

```php
function encrypt(string $source, string $key, string $iv): string
{
    // Step 1: Space padding to 16-byte boundary
    $paddingChar = ' ';
    $size = 16;
    $x = strlen($source) % $size;
    $padLength = $size - $x;
    for ($i = 0; $i < $padLength; $i++) {
        $source .= $paddingChar;
    }

    // Step 2: AES-128-CBC encrypt (no padding, manual above)
    $encrypted = openssl_encrypt($source, 'AES-128-CBC', $key, OPENSSL_NO_PADDING, $iv);

    // Step 3: Base64 encode + URL safe replace
    $encrypted = base64_encode($encrypted);
    $encrypted = str_replace(['+', '/', '='], ['-', '_', ''], $encrypted);

    return $encrypted;
}
```

### 完整請求範例（Action 5 進入遊戲）

```php
$dc  = '{ DC }';
$key = '{ KEY }';
$iv  = '{ IV }';
$apiUrl = '{ API_URL }';

$data = [
    'action'     => 5,
    'ts'         => round(microtime(true) * 1000),
    'parent'     => 'testag',
    'uid'        => 'testpl01',
    'lang'       => 'en',
    'balance'    => 10000,
    'gType'      => '0',
    'windowMode' => '2',
    'lobbyURL'   => 'https://example.invalid',
    'backBtn'    => 'false',
    'mute'       => 0,
];

$jsonString  = json_encode($data);
$encryptData = encrypt($jsonString, $key, $iv);

// POST to FumaGames
$params = ['dc' => $dc, 'x' => $encryptData];
// 以 application/x-www-form-urlencoded 格式 POST 到 $apiUrl . '/Se_token.aspx'
```

---

## 解密（FumaGames → 代理 callback）

FumaGames 呼叫代理 callback 時，同樣以 `dc` + `x` 兩個 form 欄位傳入。代理須先解密 `x` 才能取得實際 action / uid / transferId 等資料。

### 解密步驟

1. 取出 `x` 欄位密文
2. 反轉 URL Safe 替換：`-` → `+`、`_` → `/`
3. `base64_decode()` 解碼
4. 呼叫 `openssl_decrypt($data, 'AES-128-CBC', $key, OPENSSL_NO_PADDING, $iv)` 解密
5. `trim()` 去除尾部空格（官方文件範例）
6. `json_decode()` 轉成陣列

### PHP 範例

```php
function decrypt(string $data, string $key, string $iv): array
{
    // Step 1: Reverse URL safe replace
    $data = str_replace(['-', '_'], ['+', '/'], $data);

    // Step 2: Base64 decode
    $data = base64_decode($data);

    // Step 3: AES-128-CBC decrypt
    $decrypted = openssl_decrypt($data, 'AES-128-CBC', $key, OPENSSL_NO_PADDING, $iv);

    // Step 4: Remove padding (官方範例用 trim，實作可用 pkcs7_unpad)
    $decrypted = trim($decrypted);

    // Step 5: JSON decode
    return json_decode($decrypted, true);
}
```

> **注意**：官方 PHP 範例（§19.2）使用 `trim()` 清除空格 padding，實作中 `FumaSWService::pkcs7_unpad()` 改為 PKCS7 邏輯。兩者差異見 `integration.md` 高風險陷阱。

### 身份驗證方式

FumaGames 無 HMAC / Token Header 驗簽，身份驗證依賴：
1. **AES 解密成功**：只有持有正確 `KEY` / `IV` 的合法來源才能加密出可被解密的 `x`
2. **IP 白名單**：代理須將 FumaGames callback 來源 IP 加入白名單（來源 IP 需向客服索取）

---

## callback API（FumaGames → 代理）

### Action 對照表

| Action | 說明 | 觸發時機 |
|---|---|---|
| `1` | 取得餘額（Get Balance） | 玩家登入遊戲後，每 4 秒輪詢一次，直到玩家離開 |
| `2` | 扣除下注金額（Bet） | 玩家下注時 |
| `3` | 通知中獎結果派彩（Settle） | 遊戲結果確定後 |

### 我方 callback 端點

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `routes/external/sw.php` → `Route::post('fuma', ...)` |
| Controller | `App\Http\Controllers\External\SingleWallets\FumaSWController::apiSwitch()` |

---

### Action 1：取得餘額（Get Balance）

玩家登入遊戲後，FumaGames 每 4 秒輪詢一次代理端取得最新餘額。

**解密後 JSON 欄位（FumaGames → 代理）：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | `1` |
| `ts` | Long | 當前系統時間（毫秒時間戳） |
| `uid` | String(30) | 玩家帳號（只限 a-z 與 0-9） |
| `currency` | String(3) | 貨幣別 |

```jsonc
{
  "action": 1,
  "ts": 1447452951820,
  "uid": "testpl01",
  "currency": "MYR"
}
```

**成功回應（代理 → FumaGames）：**

```jsonc
{
  "status": "0000",
  "balance": 10000
}
```

**失敗回應範例：**

```jsonc
{
  "status": "7501",
  "err_text": "User ID cannot be found.."
}
```

---

### Action 2：扣除下注金額（Bet）

玩家下注時，FumaGames 呼叫代理扣除下注金額並回傳剩餘餘額。

**解密後 JSON 欄位（FumaGames → 代理）：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | `2` |
| `ts` | Long | 當前系統時間 |
| `transferId` | Long | 交易唯一序號（冪等性 key） |
| `uid` | String(30) | 玩家帳號 |
| `roundId` | Long | 回合 Id，同一回合的 action 2 與 action 3 的 roundId 相同 |
| `currency` | String(3) | 貨幣類別 |
| `bet` | Double | 扣除的金額（正值）；免費旋轉時為 `0` |
| `gType` | Integer | 遊戲類型 |
| `freewin_roundId` | Long | 免費旋轉回合 Id（選填，需向 FumaGames 申請才會傳送） |

> **免費旋轉說明**：免費旋轉時，action 3 之前會發送 `bet=0` 的 action 2。若有 10 次免費旋轉，則會有 10 組 action 2 + action 3 資料，共用同一個 `freewin_roundId`。

```jsonc
{
  "action": 2,
  "ts": 1447452951820,
  "uid": "testpl01",
  "roundId": 1,
  "transferId": 22334,
  "currency": "MYR",
  "bet": 10.00,
  "gType": 5,
  "freewin_roundId": 1
}
```

**成功回應（代理 → FumaGames）：**

```jsonc
{
  "status": "0000",
  "balance": 10000
}
```

**失敗回應範例：**

```jsonc
{
  "status": "6006",
  "err_text": "Your Cash Balance not enough."
}
```

---

### Action 3：通知中獎結果（Settle）

遊戲結果確定後，FumaGames 呼叫代理派發獎金並回傳玩家最新餘額。

**解密後 JSON 欄位（FumaGames → 代理）：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | `3` |
| `ts` | Long | 當前系統時間 |
| `transferId` | Long | 交易唯一序號 |
| `uid` | String(30) | 玩家帳號 |
| `roundId` | Long | 回合 Id（與 action 2 相同） |
| `freewin` | Integer | `1`=免費贏，`0`=非免費贏 |
| `lastfree` | Integer | `1`=最後一次免費旋轉，`0`=不是 |
| `freetotalwin` | Double | 免費旋轉累計贏分（`lastfree=1` 時帶入；需向 FumaGames 申請） |
| `gType` | Integer | 遊戲類型 |
| `des` | String(10) | 中獎描述 |
| `currency` | String(3) | 貨幣類別 |
| `bet` | Double | 押注金額（已於 action 2 扣除，此欄位僅做記錄） |
| `win` | Double | 玩家贏分（需實際加入玩家錢包；`win=0` 表示未贏，仍須回傳成功） |
| `ipAddress` | String(50) | 玩家登入 IP |
| `freewin_roundId` | Long | 免費旋轉回合 Id（選填，需向 FumaGames 申請） |

> **注意**：`bet` 在 action 3 中已是「僅記錄」用途，實際扣款已在 action 2 完成。`win` 才是需要實際加入玩家錢包的金額。

```jsonc
// Action 3 無官方 Example JSON，以欄位定義類推
{
  "action": 3,
  "ts": 1447452951820,
  "uid": "testpl01",
  "roundId": 1,
  "transferId": 22335,
  "currency": "MYR",
  "bet": 10.00,
  "win": 20.00,
  "gType": 5,
  "freewin": 0,
  "lastfree": 0,
  "des": "Win",
  "ipAddress": "0.0.0.0"
}
```

**成功回應（代理 → FumaGames）：**

```jsonc
{
  "status": "0000",
  "balance": 10000
}
```

---

## 代理 API（本平台 → FumaGames）

所有 API 由代理端主動發起，以 AES-128-CBC 加密後 POST 至 FumaGames。

### Endpoint 對照表

| 功能 | Path | action |
|---|---|---|
| 踢出單一玩家 | `/Se_kick.aspx` | 4 |
| 取得遊戲 Token / 進入遊戲 | `/Se_token.aspx` | 5 |
| 每日對帳報表 | `/Se_Report.aspx` | 6 |
| 查詢遊戲列表 | `/Se_GameList.aspx` | 7 |
| 查詢玩家是否在遊戲中 | `/Se_InGame.aspx` | 8 |
| 踢出所有下線用戶 | `/Se_KickDownlineUsers.aspx` | 9 |
| 查詢遊戲結果（URL） | `/Se_QueryGameResult.aspx` | 10 |
| 創建 API 子代理 | `/CreateAgent.aspx` | 15 |
| 查詢遊戲詳細信息（JSON） | `/Se_QueryGameJsonResult.aspx` | 16 |

---

### Action 4：踢出玩家

**請求欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | 固定為 `4` |
| `ts` | Long | 當前系統時間（毫秒） |
| `parent` | String(24) | 代理帳號 |
| `uid` | String(30) | 玩家帳號 |

> 若玩家在線，會收到被踢出提示；若正在遊戲中，遊戲將被中止並直接結算。

---

### Action 5：進入遊戲（取得 Token）

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `{API_URL}/Se_token.aspx` |

**請求欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | 固定為 `5` |
| `ts` | Long | 當前系統時間（毫秒時間戳） |
| `parent` | String(24) | 代理帳號 |
| `uid` | String(30) | 玩家帳號（只限 a-z 與 0-9） |
| `balance` | Double | 玩家當前餘額 |
| `lang` | String(2) | 語系（見語言對照表） |
| `gType` | String(2) | 遊戲類型（參照附錄） |
| `windowMode` | String(1) | 暫時無用，固定送 `"2"` |
| `lobbyURL` | String(100) | 遊戲大廳網址（`windowMode=2` 時有效） |
| `backBtn` | String | 是否顯示返回按鈕（`"true"` / `"false"`，預設 `"false"`） |
| `mute` | Integer | 音效開關（`0` 開啟，`1` 靜音，預設 `0`） |

**語言對照表：**

| 代碼 | 語言 |
|---|---|
| `en` | 英語 |
| `zhft` | 繁體中文 |
| `id` | 印尼語 |
| `vi` | 越南語 |
| `th` | 泰語 |
| `my` | 緬甸語 |
| `ru` | 俄語 |
| `de` | 德語 |
| `fr` | 法語 |
| `ja` | 日語 |
| `kk` | 哈薩克語 |
| `km` | 高棉語 |
| `pt` | 葡萄牙語 |

> 尚未支援的語系預設以英文開啟。

**回應欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `status` | String(4) | `"0000"` 成功；錯誤見錯誤碼表 |
| `path` | String(255) | 開啟遊戲的連結（AES 加密後的完整 URL） |
| `err_text` | String(255) | 錯誤訊息 |

---

### Action 6：每日對帳報表

| 項目 | 值 |
|---|---|
| Endpoint | `{API_URL}/Se_Report.aspx` |

**請求欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | `6` |
| `ts` | Long | 當前系統時間 |
| `parent` | String(24) | 代理帳號 |
| `date` | String(10) | 查詢日期（`yyyy-MM-dd`） |

> 每次查詢限三個月內資料。查詢當日時，範圍為當日 00:00:00 至當前時間。

**回應 data 陣列欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `uid` | String(30) | 玩家帳號 |
| `bet` | Double | 押注金額 |
| `win` | Double | 遊戲贏分 |
| `netWin` | Double | 總輸贏（netWin = win - bet，負值表示代理盈利） |
| `count` | Integer | 筆數 |

---

### Action 7：查詢遊戲列表

| 項目 | 值 |
|---|---|
| Endpoint | `{API_URL}/Se_GameList.aspx` |

**請求欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | `7` |
| `ts` | Long | 當前系統時間 |
| `parent` | String(24) | 代理帳號 |
| `lang` | String | 語系（同語言對照表） |

**回應 data 陣列欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `gType` | Integer | 遊戲類型代碼 |
| `image` | String | 遊戲圖片地址 |
| `name` | String | 遊戲名稱 |

---

### Action 8：查詢玩家是否在遊戲中

| 項目 | 值 |
|---|---|
| Endpoint | `{API_URL}/Se_InGame.aspx` |

**請求欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | `8` |
| `ts` | Long | 當前系統時間 |
| `parent` | String(24) | 代理帳號 |
| `uid` | String(30) | 玩家帳號 |

**回應 data 欄位（成功時）：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `gType` | Integer | 遊戲類型 |
| `ipAddr` | String(50) | 玩家登入 IP |
| `loginTime` | String(19) | 登入遊戲時間（`yyyy-MM-dd HH:mm:ss`） |
| `balance` | Number | 帳戶餘額 |

> 若玩家不在遊戲中，回傳 `status: "7505"`。

---

### Action 9：踢出所有下線用戶

| 項目 | 值 |
|---|---|
| Endpoint | `{API_URL}/Se_KickDownlineUsers.aspx` |

**請求欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | `9` |
| `ts` | Long | 當前系統時間 |
| `parent` | String(24) | 上線帳號 |

> 若 parent 為董事帳號，所有下線（含經理、代理、總代理、會員）皆被踢出；若為代理帳號，該代理下所有會員被踢出。

---

### Action 10：查詢遊戲結果（URL）

| 項目 | 值 |
|---|---|
| Endpoint | `{API_URL}/Se_QueryGameResult.aspx` |

**請求欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | `10` |
| `ts` | Long | 當前系統時間 |
| `parent` | String(24) | 代理帳號 |
| `uid` | String(30) | 玩家帳號 |
| `lang` | String(2) | 語系 |
| `seqNo` | Long | 回合 Id，不為 0 時查詢該回合資料 |
| `gType` | Integer | 遊戲類型 |

**回應欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `status` | String(4) | 成功 `"0000"` |
| `path` | String | 遊戲結果 URL |
| `err_text` | String(255) | 錯誤訊息 |

> 時間範圍需在三十天以內。

---

### Action 15：創建 API 子代理

| 項目 | 值 |
|---|---|
| Endpoint | `{API_URL}/CreateAgent.aspx` |

**請求欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | `15` |
| `ts` | Long | 當前系統時間 |
| `parent` | String | 代理帳號 |
| `childAgent` | String | 要創建的子代理帳號 |
| `walletType` | Int | 錢包類型，Seamless 傳 `1` |
| `seamlessUrl` | String | 無縫錢包 callback URL |

**回應 data 欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `key` | String | 加密 KEY |
| `iv` | String | 加密 IV |
| `dc` | String | 代理識別 dc |
| `password` | String | 子代理密碼，用於後台登入 |

---

### Action 16：查詢遊戲詳細信息（JSON）

| 項目 | 值 |
|---|---|
| Endpoint | `{API_URL}/Se_QueryGameJsonResult.aspx` |

**請求欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `action` | Integer | `16`（**注意**：文件範例 JSON 誤標 `12`，以章節定義 `16` 為準） |
| `ts` | Long | 當前系統時間 |
| `parent` | String | 代理帳號 |
| `uid` | String | 玩家帳號 |
| `starttime` | String | 查詢起始時間（`yyyy-MM-dd HH:mm:ss`） |
| `endtime` | String | 查詢結束時間（`yyyy-MM-dd HH:mm:ss`） |
| `lang` | String | 語系 |
| `seqNo` | Long | 回合 Id，不為 0 時查詢該回合資料 |
| `gtype` | Integer | 遊戲類型（`0` = 查詢所有遊戲記錄） |

**回應 data 陣列欄位：**

| 參數 | 類型 | 說明 |
|---|---|---|
| `time` | String | 交易日期（`yyyy-MM-dd HH:mm:ss`） |
| `gname` | String | 遊戲名稱 |
| `gtype` | String | 遊戲類型 |
| `uid` | String | 玩家 ID |
| `seqNo` | String | 回合 Id |
| `transferId` | String | 交易唯一序號 |
| `bet` | Number | 下注金額 |
| `win` | Number | 獎勵金額 |
| `startV` | Number | 開始金額（下注前） |
| `endV` | Number | 結束金額 |
| `des` | String | 中獎描述 |

> 查無資料時回傳 `status: "0000"`, `data: []`。查詢時間範圍限 30 天以內。

---

## 附錄：遊戲列表

| gType | 遊戲名稱 |
|---|---|
| `1` | ForTune Mahjong 財神麻將 |

> 官方文件僅列出 gType=1。實際可售遊戲列表請透過 action 7 動態查詢，或向 FumaGames 客服取得完整清單。

---

## 錯誤碼

| Code | 說明 |
|---|---|
| `0000` | 成功 |
| `6006` | 餘額不足（Your Cash Balance not enough） |
| `7501` | 玩家不存在（User ID cannot be found） |
| `7505` | 玩家未在遊戲中（User is not playing） |
| `9005` | SAML 時間戳驗證失敗（timestamp validation 容差未說明） |
| `9011` | 交易序號重複（Duplicate transactions） |
| `9012` | 回合不存在（Round not found），Settle 依 roundId 查無對應下注記錄時回此碼 |
| `9999` | 其他失敗（Failed）；如未符合其他錯誤，在 `err_text` 說明 |
