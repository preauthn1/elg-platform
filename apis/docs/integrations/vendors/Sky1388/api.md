# Sky1388 — API 規格

> 來源：`API_0_3_7.pdf`（API Technical Guide Version 0.3.7，2025-08-06 最新版）為主要事實來源；`Flow API EN V2.pdf`、`API Documentation_0_2_8.pdf`（v0.2.8 舊版）為流程 / 歷史參考。
> 抓取日期：2026-06-03
> 對應遊戲商：**Sky1388**（vendor_id=28，**Transfer Wallet 轉帳錢包**）

---

## 通用約定

### 請求格式

| 項目 | 值 |
|---|---|
| Base URL | `http://<hostname>/api/wsv1_0/`（hostname 由 Sky1388 提供，**結尾含 trailing slash**） |
| Path | 各 API 名稱（如 `/user_register`、`/user_transfer`） |
| HTTP Method | 文件 PHP 範例支援 `GET` / `POST`（QueryString 形式）；**APIs 專案實作一律使用 `GET`** |
| 參數傳遞 | 所有參數以 query string 形式攜帶，含 `signature` |

### 共同請求參數

幾乎所有 API 都帶以下兩個欄位：

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | Agency 唯一識別碼（由 Sky1388 提供，範例 `WEBGAME`） |
| `signature` | String | ✅ | 簽章，見[簽名](#簽名) |

### 共同回應欄位

| 欄位 | 說明 |
|---|---|
| `status` | `OK` = 成功；`ERROR` = 失敗（比對前 `strtolower`） |
| `error_code` | 失敗時的字串錯誤碼（見[錯誤碼表](#錯誤碼表)） |

> 部分 API（如 `user_play_game`、`user_detail`、`user_transfer`）成功時直接平鋪回傳業務欄位（`url` / `balance` / `trxid` 等），無 `data` 包層。

> `username` 規則：String(6-12)，只允許小寫字母 `a~z` 與數字 `0~9`，須唯一。

---

## merchant API

> 代理（Partner System）主動呼叫 Sky1388 的所有 API。每支介面都帶 `agid` + `signature`。

---

### Player

#### 1. User register

`/user_register` — 不存在則建立玩家帳號。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | Agency id |
| `username` | String(6-12) | ✅ | 玩家帳號，唯一，僅 `a~z`、`0~9` |
| `password` | String(6-12) | ✅ | 僅 alphanumeric |
| `lang` | String(3) | ✅ | 見[語言代碼表](#語言代碼表) |
| `signature` | String | ✅ | |

**成功回應**（`status = OK`）：`user_status`（1=active / 0=inactive / 8=suspend）、`username`、`balance`。

---

#### 2. Get User Detail

`/user_detail` — 取得玩家明細，含 Sky1388 端的 `balance`。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ✅ | |
| `signature` | String | ✅ | |

**成功回應**：`user_status`、`username`、`balance`。

> APIs `Driver::getBalance()` 呼叫此 API，讀取 `response['balance']` 後以 `Common::numFloor($balance, 4)` 正規化。

---

#### 3. Set user suspend

`/user_suspend` — 將玩家設為停權。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ✅ | |
| `signature` | String | ✅ | |

**成功回應**：`user_status = 8`。

---

#### 4. Set user unsuspend

`/user_unsuspend` — 解除停權。參數同 §3。成功回應含 `user_status`。

---

#### 18. User Reset Password

`/user_reset_password`

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ✅ | |
| `password` | String(6-12) | ✅ | |
| `signature` | String | ✅ | |

---

#### 19. User login

`/user_login` — 取得 lobby session（用於 Game URL 啟動參數）。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ✅ | |
| `password` | String(6-12) | ✅ | |
| `signature` | String | ✅ | |

**成功回應**：`session`（用於[Game URL 啟動參數](#game-url-啟動參數)）。

---

#### 20. User reset language

`/user_set_lang`

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ✅ | |
| `lang` | String(3) | ✅ | 見[語言代碼表](#語言代碼表) |
| `signature` | String | ✅ | |

---

### Wallet（轉帳錢包核心）

#### 6. Fund transfer

`/user_transfer` — 玩家上下分（存款 / 提款）。**轉帳錢包資金流的核心 API。**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `amount` | Decimal | ✅ | **不可帶千分位逗號**（`1000.00`✅，`1,000.00`❌）；`500` = 存入 Sky1388；`-500` = 提出 |
| `username` | String(6-12) | ✅ | |
| `orderid` | int | ✅ | 代理（Partner system）端交易 ID（冪等鍵） |
| `rolling` | Decimal | ❌ | 不可為負；預設不帶 |
| `wallet_type` | String | ❌ | 預設 `main`；見[Wallet type 表](#wallet-type-表) |
| `signature` | String | ✅ | |

**成功回應**（`status = OK`）：`trxid`（Sky1388 系統 trx）、`orderid`（代理交易 ID）、`user_status`、`username`、`balance`。

> **APIs Driver 實作**：
> - `transferToVendor`（存款）：`amount` 為正。
> - `transferToLocal`（提款）：`amount = Common::numMul($vendorAmount, -1)`（轉負值）。
> - 送出欄位：`agid`、`amount`、`username`、`orderid`、`rolling`（空字串）、`wallet_type`（空字串）。
> - 回應 `status` 經 `strtolower` 後：`ok` → SUCCESS、`error` → FAILED、其他/壞回應 → TIMEOUT（不還錢，交由查單確認）。
> - ⚠️ `TRX_EXIST`（重複交易）落在 `status=error`，目前判 FAILED，實際應為冪等成功（見 [integration.md](integration.md#1-trx_exist-應視為冪等成功轉帳重送時)）。

---

#### 7. Get fund transfer detail

`/user_transfer_detail` — 查單，確認某筆 transfer 的最終狀態。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ✅ | |
| `trxid` | int | ❌ | Sky1388 系統 trx id |
| `orderid` | int | ✅ | 代理端 trx id |
| `signature` | String | ✅ | |

**成功回應**：`username`、`trxid`、`amount`、`trx_type`（**1=deposit、2=withdraw**）、`orderid`。

> APIs `Driver::checkTransferStatus($transactionNo)` 以 `orderid = $transactionNo`（本系統交易號）查詢；`status` 經 `strtolower`：`ok` → SUCCESS、`error` → FAILED、其他 → TIMEOUT。
> ⚠️ `TRX_INVALID`（交易不存在）在查單時是否代表「可安全判定最終失敗」須向客服確認（見 [integration.md](integration.md#2-trx_invalid-查單意義待確認)）。

---

#### 17. User transfer history

`/user_transfer_history` — 期間內轉帳紀錄（每頁 20 筆，最多取最近三天）。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ❌ | 留空查全 agent |
| `start_date` | String | ✅ | |
| `end_date` | String | ✅ | |
| `page` | int | ❌ | |
| `signature` | String | ✅ | |

**成功回應** `list[]`：`username`、`trxid`、`amount`、`orderid`、`type`（deposit/withdraw）、`datetime`；外層含 `total`。

---

#### 22. User reset rolling

`/user_reset_rolling` — 調整玩家錢包 rolling。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ✅ | |
| `rolling` | Decimal | ✅ | 可正可負 |
| `wallet_type` | String | ❌ | 預設 `main` |
| `signature` | String | ✅ | |

---

### Game

#### 9. Get Game list

`/user_game_list` — 取得遊戲清單。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `status` | number | ✅ | 篩選遊戲狀態：`0` 或 `1`(active) 或 `9`(inactive) |
| `signature` | String | ✅ | |

回應每筆：`game_code`（啟動遊戲時帶此 code）、`game_name`、`game_type`（見[Game type 表](#game-type-表)）、`game_h5`（true=支援瀏覽器）、`game_display_name`、`game_image_url`。

---

#### 10. Get game list by type

`/user_game_list_by_type` — 參數與回應同 §9。

---

#### 11. User Play Game

`/user_play_game` — 取得玩家進入遊戲的 URL。**APIs `Driver::getGameUrl()` 呼叫此 API。**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ✅ | |
| `game_code` | String | ✅ | 由 Game list 取得 |
| `game_support` | String | ❌ | 傳 `h5` 回傳手機 H5 版 URL；空則桌面版 |
| `lang` | String | ✅ | 見[語言代碼表](#語言代碼表) |
| `game_back_url` | String | ✅ | 玩家點離開時的返回 URL |
| `signature` | String | ✅ | |

**成功回應**：`url`（玩家進入遊戲的完整 URL）。

> APIs 實作送出 `game_support => 'H5'`（大寫）、`lang` 經 `mapLanguageCode()` 轉換，回應同時填入 `pc` 與 `mobile`。
> ⚠️ 文件 `game_support` 範例值為小寫 `h5`，實作送大寫 `H5`（見 [integration.md](integration.md#3-game_support-大小寫落差)）。

---

#### 14. Get Platform List

`/user_platform_list` — 取得第三方平台清單。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ✅ | |
| `signature` | String | ✅ | |

回應每筆：`platform_code`（如 `fgg`、`bg`、`evosync`）、`platform_name`、`platform_image_url`。

---

#### 15. User Play Platform Game

`/user_play_platform_game` — 進入第三方平台遊戲。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ✅ | |
| `platform_code` | String | ✅ | 由 Platform list 取得 |
| `mobile` | int | ✅ | `1`=手機 lobby / `0`=PC |
| `back_url` | String | ✅ | 返回 URL |
| `signature` | String | ✅ | |

**成功回應**：`url`。

---

#### 21. User gamejp

`/user_gamejp` — 取得 jackpot 金額。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `signature` | String | ✅ | |

**成功回應**：`jackpot`。

---

### Report

> **資料保留 / 範圍限制**：遊戲歷史（§12/13/16）`start_date` 最多回溯最近三天（`MAX_LAST_THREE_DAYS`）；`username` 留空查全 agent 時，日期範圍不得超過 10 分鐘（`MAX_10_MINS`）。每頁預設 20 筆，`page_size` 最多 500。

#### 8. Get user report summary

`/user_report_summary` — 期間內輸贏彙總。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ❌ | 留空查全 agent |
| `start_date` | String | ✅ | |
| `end_date` | String | ✅ | |
| `signature` | String | ✅ | |

**成功回應**：`username`、`total_bet`、`total_win`、`total_winlose`（= `total_bet − total_win`）。

---

#### 12. User game history

`/user_game_history` — 回合層級遊戲紀錄。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ❌ | 留空查全 agent（範圍限 10 分鐘） |
| `start_date` | String | ✅ | |
| `end_date` | String | ✅ | |
| `page` | int | ❌ | |
| `page_size` | int | ❌ | 最多 500 |
| `signature` | String | ✅ | |

回應每筆：`matchid`、`username`、`game_type`、`game_code`、`bet_time`、`bet`、`win`、`refund`、`winlose`、`jackpot_type`(Minor/Major/Grand)、`jackpot`、`bet_ip`。

---

#### 13. User game history (v2)

`/user_game_history_v2` — 同 §12，差異為 v2 回應**移除 `error_code` 欄位**（Revision 0.3.4）。其餘參數與回應欄位與 §12 相同。

---

#### 16. User platform game history

`/user_platform_game_history` — 第三方平台遊戲紀錄。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agid` | String | ✅ | |
| `username` | String(6-12) | ❌ | |
| `start_date` | String | ✅ | |
| `end_date` | String | ✅ | |
| `page` | int | ❌ | |
| `page_size` | int | ❌ | 最多 500 |
| `signature` | String | ✅ | |

回應每筆：§12 欄位再加 `created_time`、`status`（**0=pending、1=settled、9=cancel**）。

---

## Game URL 啟動參數

由 `/user_login`（§19）取得 `session` 後，依啟動方式組合 URL 參數（`API_0_3_7.pdf` §30/§31）：

| 啟動方式 | 參數格式 | lang 取值 |
|---|---|---|
| Desktop App / Android / iOS（§30） | `lang=xxx&sessionKey=xxx` | `eng \| zh-cn \| vi \| id \| khm \| th` |
| 瀏覽器（§31） | `l=xxx&s=xxx` | 同上 |

> ⚠️ 注意：此處 lang 取值（`zh-cn`、`vi`、`khm`…）與[語言代碼表](#語言代碼表)的三碼（`chs`、`vnm`…）**不同**，屬遊戲端 launch 參數的另一套對照，使用時以該章節為準。

---

## Confirm Transfer Callback（v0.3.7 新增，選用）

> 來源：`API_0_3_7.pdf` §32。**已實作**：`App\Http\Controllers\External\TransferWallets\Sky1388Controller::confirmTransfer`，
> 路由 `routes/external/tw.php`（`POST external/api/tw/sky1388/confirm_transfer`）。WTT（Wintoto）已啟用；
> 其餘白標若 Sky1388 後台也開啟此防護，需另外把同一組 URL 提供給該白標對應的 Sky1388 商戶設定。

用途：玩家上 / 下分時，Sky1388 反向呼叫代理後台確認該筆 `user_transfer` 是否確實來自代理系統（防止偽造上下分）。

**Sky1388 → 代理 Request（POST JSON）**：

| 欄位 | 格式 | 說明 |
|---|---|---|
| `agid` | string | 上下分代理（對應 user_transfer agid） |
| `order` | string | 上下分訂單 ID（對應 user_transfer orderid） |
| `amount` | number | 上下分金額（對應 user_transfer amount） |
| `kiosk` | string | 上下分玩家（對應 user_transfer username） |
| `time` | string | 時間戳（Unix epoch 秒） |

範例：`{"kiosk":"test00001","order":"01053950008178","amount":100,"time":1754471647}`

**代理 → Sky1388 Response**：

| 欄位 | 格式 | 說明 |
|---|---|---|
| `status` | string | 成功 `0000`、失敗 `9999` |
| `err_message` | string | 錯誤訊息 |

範例：`{"status":"0000","err_message":""}`

> 若白標需啟用此防護，須在 `routes/external/` 新增對應 endpoint 並提供給 Sky1388 後台設定。

---

## 簽名

Sky1388 所有代理端（Partner System）送出的請求，都必須附帶 `signature` 欄位，供 Sky1388 驗證資料完整性與來源真實性。

> 來源：`API_0_3_7.pdf` §27「Signature generate code」、§28「Example Coding for PHP」、§29「Example Coding For Curl」。

### 演算法總覽

| 項目 | 值 |
|---|---|
| 雜湊演算法 | `SHA1` |
| 私鑰來源 | `PRIVATE_KEY`（官方稱 privateKey / AGENCY_KEY，依每個 agent 不同，須向 Sky1388 索取） |
| 待簽字串 | 各參數**依欄位名 ASCII 由小到大排序（`ksort`）後，將「值」以空字串串接**，再接上 privateKey |
| 編碼 | 不做 URL Encode，欄位名與欄位值皆使用 raw value |

### 構成步驟

#### Step 1 — 移除 `signature` 並對參數 `ksort`

排序「依欄位名」，但最終串接的只取「值」。

#### Step 2 — 將排序後的「值」以空字串串接，再接 privateKey

```
原始字串 = implode('', $sortedValues) . $privateKey
```

#### Step 3 — 對該字串做 SHA1

```php
$signature = sha1(implode('', $params) . $privateKey);
```

### 官方範例（§27）

取 `user_transfer_detail`，參數為 `agid`、`username`、`trxid`、`orderid`：

```
agid     = a123
username = u123
trxid    = t123
orderid  = o123
```

依欄位名 ASCII 排序後（agid → orderid → trxid → username）串接「值」：

```php
signature = sha1("a123" . "o123" . "t123" . "u123" . "abc123"); // abc123 = privateKey
// signature = 3fb05f2a4d4f8cf011e5d03e0fe1ca52dd37130c
```

> ⚠️ 官方文字敘述「依欄位名排序」，PHP 範例 `Signature_Genarate` 直接對整個 `$Params` 陣列 `ksort`（依 key 排序）後 `implode('', $Params)`（取 value 串接）。兩者一致——重點是**排序依 key、串接取 value**。

### 官方 PHP 產生與驗證函式（§27）

```php
function Signature_Genarate($Params, $privateKey = false)
{
    if (!empty($Params['signature'])) {
        unset($Params['signature']);
    }
    ksort($Params);
    $Params['signature'] = sha1(implode('', $Params) . $privateKey);

    return $Params;
}

function Signature_Verify($Params, $privateKey = false)
{
    if (!is_array($Params) || !$privateKey) {
        return false;
    }

    $CSignature = '';
    if (!empty($Params['signature'])) {
        $CSignature = $Params['signature'];
        unset($Params['signature']);
    }
    ksort($Params);
    $Signature = sha1(implode('', $Params) . $privateKey);

    return ($Signature === $CSignature) ? true : false;
}
```

> 驗證失敗時系統回傳 `error_code = SINGATURE_INVALID`（**原文拼字，非本平台錯誤**，見[錯誤碼表](#錯誤碼表)）。

### APIs 專案實作（交叉驗證）

`app/Vendors/Sky1388/Driver.php::sign()`：

```php
private function sign($data)
{
    ksort($data);
    $str = implode('', $data) . $this->infoDetail->PRIVATE_KEY;
    $sign = sha1($str);

    return $sign;
}
```

與官方 `Signature_Genarate` 一致（`ksort` 依 key → `implode('', $data)` 取 value 串接 → 接 `PRIVATE_KEY` → `sha1`）。實作將回傳值另存入 `$data['signature']` 再送出，符合官方流程。

### 常見錯誤

| 症狀 | 可能原因 |
|---|---|
| `SINGATURE_INVALID` | privateKey 錯誤、或排序 / 串接邏輯不符（漏 `ksort`、誤對值做 URL Encode） |
| 偶發失敗 | 待簽參數集合與實際送出的參數不一致（例如有額外或缺漏欄位參與 implode） |

---

## 錯誤碼表

> 來源：`API_0_3_7.pdf` §23。錯誤碼為**字串**，由 `error_code` 欄位回傳。

| ERROR CODE | 說明 |
|---|---|
| `AGENCY_INVALID` | Agent 不存在或無效 |
| `EMPTY` | 紀錄為空 |
| `EMPTY_REPORT_SUMMARY` | 無任何紀錄 |
| `GET_GAME_JP_FAIL` | 取得 jackpot 失敗 |
| `GET_GAME_LIST_FAIL` | Agent 無任何授權遊戲 |
| `GET_PLATFORM_FAIL` | 取得平台清單失敗 |
| `GET_PLATFORM_LOGIN_URL_FAIL` | 取得平台 lobby url 失敗 |
| `INSUFFICIENT_AGENT_FUND` | Agent 餘額不足 |
| `INSUFFICIENT_USER_FUND` | 玩家餘額不足 |
| `LOGIN_FAIL` | 玩家登入失敗 |
| `MAX_10_MINS` | 日期範圍超過 10 分鐘（查全 agent 時） |
| `MAX_LAST_THREE_DAYS` | 僅能取最近三天紀錄 |
| `NETWORK_ISSUE` | 網路連線錯誤 |
| `PARAM_INVALID` | 參數無效（agid, userid, password, ip, lang） |
| `PLAY_GAME_FAIL` | 玩家不允許進入此遊戲 |
| `RESET_PASSWORD_FAIL` | 重設密碼失敗 |
| `SINGATURE_INVALID` | 簽章無效（**原文拼字 SINGATURE，非 SIGNATURE**） |
| `TRX_EXIST` | 交易重複（⚠️ transfer 重送時應視為冪等成功，見 [integration.md](integration.md#1-trx_exist-應視為冪等成功轉帳重送時)） |
| `TRX_INVALID` | 交易不存在（⚠️ 查單語意待客服確認，見 [integration.md](integration.md#2-trx_invalid-查單意義待確認)） |
| `UPDATE_LANG_FAIL` | 更新語言失敗 |
| `UPDATE_ROLLING_FAIL` | 更新 kiosk 錢包 rolling 失敗 |
| `USER_EXIST` | 帳號重複 |
| `USER_INVALID` | 玩家不存在或無效 |
| `USER_STATUS_ACTIVE` | 玩家已啟用 |
| `USER_STATUS_SUSPEND` | 玩家已停權 |

---

## 語言代碼表

> 來源：`API_0_3_7.pdf` §24（用於 `lang` 參數，String(3)）。

| CODE | 說明 |
|---|---|
| `chs` | 中文 |
| `eng` | 英文 |
| `ind` | 印尼文 |
| `thi` | 泰文 |
| `vnm` | 越南文 |

---

## Game type 表

> 來源：`API_0_3_7.pdf` §25。

| GAME TYPE | 說明 |
|---|---|
| `singleplayer_games` | 單人遊戲 |
| `online_casino` | 線上賭場 |
| `multiplayer_p2p` | 多人 P2P |
| `online_games` | 線上遊戲 |

---

## Wallet type 表

> 來源：`API_0_3_7.pdf` §26（用於 `user_transfer` / `user_reset_rolling` 的 `wallet_type`）。

| WALLET TYPE | 說明 |
|---|---|
| `main` | 主錢包（預設） |
| `singleplayer_games` | 單人遊戲錢包 |
| `online_casino` | 線上賭場錢包 |
| `multiplayer_p2p` | 多人 P2P 錢包 |
| `online_games` | 線上遊戲錢包 |

---

## Revision History（官方，節錄）

| 日期 | 版本 | 變更 |
|---|---|---|
| 2016-04-10 | 0.1 | 初版 |
| 2018-07-30 | 0.2.1 | game history / list 回傳改為陣列索引，遊戲支援瀏覽器 |
| 2021-03-12 | 0.3.1 | 新增 platform game |
| 2023-06-01 | 0.3.4 | 新增 `user_game_history_v2`（回傳移除 error_code） |
| 2024-07-02 | 0.3.5 | 新增 `user_game_list_by_type` |
| 2024-02-19 | 0.3.6 | game list 新增 status 參數 |
| 2025-08-06 | **0.3.7** | 新增 Confirm Transfer Callback 設定 |
