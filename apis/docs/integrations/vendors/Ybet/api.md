# Ybet（YEEBET 億博）— 廠商 API 規格

> 原始來源：`YEEBET API v3.2 (CN) 2022.pdf`（主要事實來源，46 頁）、`YEEBET API v3.2 (EN) 2022.pdf`（56 頁，交叉校驗）；同資料夾另含環境憑證文字檔。
> 抓取日期：2026-06-03
>
> 我方實作分析、整合陷阱、Driver 方法對照與落地對應見同目錄 `integration.md`。

YEEBET（億博）為**真人視訊**遊戲商，提供百家樂、輪盤、龍虎、骰寶、牛牛、三公等遊戲。本專案以 **vid 52 / Transfer Wallet（轉帳錢包，文件稱「用戶轉點形式」）** 模式接入。

---

## 通用約定

### 請求格式

- **協定**：`http(s)://host:port/api/{接口名}`，`api` 為必加前綴
- **HTTP Method**：依各接口而定（多數 `POST`，部分查詢為 `GET`，見各接口表格）
- **編碼**：所有參數與回傳統一 **UTF-8**
- **大小寫**：接口名稱、參數名稱統一**小寫**

### ⚠️ 參數傳輸位置注意

文件未明確規範參數放 body 或 query string。本專案實作 `Driver::sendRequest()` 以 `POST` 發送，但**把所有參數組到 URL query string**（程式碼註解：「雖然是 post 請求, 但必須把參數組合到網址而不是 body 中」）。正式對接前建議向 Ybet 客服確認最終採用方式。

### 通用回應欄位

所有接口皆以 JSON 回傳，欄位一律小寫：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `result` | Int | 狀態碼。`0`（部分接口為正數如 `1`）= 成功；負數 = 異常（見錯誤碼表） |
| `desc` | String | 描述訊息，成功多為 `OK`，失敗為原因 |

### ⚠️ result 成功值不一致

| 接口範圍 | 成功值 |
|---|---|
| §2.2–2.7（平台接口、轉點接口） | `result === 0` |
| §2.10–2.18（注單/報表：`getbets`、`removebets`、`getgames`、`game/history`、`bets/follow`、`bets/settle` 等） | `result === 1` |

實作判斷時須逐接口確認，不可一律假設 `0`。

### 環境與憑證

| 環境 | API Base URL | BO 後台 |
|---|---|---|
| Production（正式） | `https://api.yeebet.vip/` | `https://admin.yeebet.vip/` |
| Staging（測試） | `http://example.invalid/` | `http://example.invalid/#/login` |

> `APP_ID`、`Secret Key` 為每個白標各自分配，憑證屬機密，本文件不轉載明文。文件 PDF 內的示例 URL（`http://example.invalid/` 等）為撰寫時的測試域名，非正式環境。

---

## 錢包模式

| 模式 | 本專案 | 說明 |
|---|---|---|
| **轉帳錢包（用戶轉點形式）** | ✅ 採用 | 玩家餘額存放於 Ybet；進遊戲前需以 `/api/user/dw` 將代理錢包餘額轉入，離開後轉回 |
| 單一錢包（商戶錢包形式） | ❌ 未採用 | 餘額存代理端，Ybet 透過回調即時扣加款 |

---

## 簽名演算法

所有接口請求都必須附帶 `sign` 參數。簽名採 **MD5**，預設編碼 **UTF-8**（文件 §1「加密簽名說明」）。

| 項目 | 值 |
|---|---|
| 演算法 | MD5 |
| 編碼 | UTF-8 |
| 參數排序 | 所有參數（**空值不參與**）依 **ASCII 由小到大** 排序 |
| 拼接 | 排序後以 `key=value` 形式、用 `&` 連接 |
| 金鑰 | 末尾追加 `&key={Secret Key}` |
| 輸出 | `MD5(拼接字串)`，作為 `sign` 參數值 |

**Step 1** — 參數排序並拼接（空值不參與）：

```
stringA = "appid=wxd930ea5d5a258f4f&body=test&device_info=1000&mch_id=10000100&nonce_str=ibuaiVcKdpRxkhJA"
```

**Step 2** — 末尾追加 Secret Key：

```
stringSignTemp = stringA + "&key=192006250b4c09247ec02edce69f6a2d"
```

**Step 3** — MD5：

```
sign = MD5(stringSignTemp)
```

**PHP 實作（Driver::generateSignature()）：**

```php
private function generateSignature(array $data): array
{
    // 1. 參數名稱自然排序（ksort 依 key ASCII）
    ksort($data);

    // 2. 排序後組成 key=value 並以 & 連接
    $signature = http_build_query($data);

    // 3. 末尾追加 &key={Secret Key}
    $signature .= '&key=' . $this->secretKey;

    // 4. MD5（PHP md5() 輸出小寫 hex）
    $signature = md5($signature);

    // 5. 設為請求參數的 sign 欄位
    $data['sign'] = $signature;

    return $data;
}
```

**注意事項：**

1. **空值處理**：文件要求「空值不參與簽名」。若 `$data` 含空字串欄位，`http_build_query` 仍會輸出 `key=`，可能與 Ybet 端不符。組裝參數時應確保不帶空值欄位。
2. **`http_build_query` URL encoding**：特殊字元會被 URL encode，需確認 Ybet 端是否也以 encode 後字串計算 MD5。
3. **大小寫**：PHP `md5()` 輸出小寫，與文件示例一致。
4. **排序基準**：`ksort` 對應文件「ASCII 排序」。

**完整請求範例（以餘額查詢為例）：**

```jsonc
// 1. 原始參數
{ "appid": "xtdPOT8BD7N9", "username": "FAKHD10001" }

// 2. ksort 後拼接
//    appid=xtdPOT8BD7N9&username=FAKHD10001

// 3. 追加金鑰
//    appid=xtdPOT8BD7N9&username=FAKHD10001&key={Secret Key}

// 4. MD5 → sign
// 5. 最終請求（實作組到 URL query string）
//    POST /api/user/balance?appid=...&username=...&sign=<md5>
```

---

## merchant API（代理 → Ybet）

所有接口共同必帶參數：`appid`（分配應用 ID）、`sign`（簽名）。

### 平台接口

#### 1. 登入 `/api/login`

登入並取得進入遊戲連結。本專案 `getGameUrl()` 使用此接口。

| 項目 | 值 |
|---|---|
| Method | POST |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | ✅ | 分配應用 ID |
| `username` | String | ✅ | 登入名 |
| `nickname` | String | ❌ | 暱稱（不傳則系統隨機生成） |
| `state` | byte | ❌ | 狀態（1 開放, 0 禁止, -1 鎖定, -2 禁止投注） |
| `commratio` | String | ❌ | 占成字段 |
| `quotas` | String | ❌ | 限額字段（如「1,2,3」逗號分割） |
| `iscreate` | byte | ✅ | 是否建立帳號（1 是, 0 否） |
| `clienttype` | byte | ✅ | 手機標識（1 PC, 2 手機, 3 iPad） |
| `language` | byte | ✅ | 語言標識（見附錄語言代碼） |
| `portrait` | String | ❌ | 用戶頭像 |
| `currency` | String | ❌ | 貨幣編碼 |
| `returnurl` | String | ❌ | 返回地址 |
| `gt` | Byte | ❌ | 遊戲類型（4 百家樂, 5 輪盤, 10 龍虎, 11 骰寶…） |
| `gid` | Integer | ❌ | 遊戲 id，選擇單一遊戲進入 |
| `ist` | Byte | ❌ | 試玩標識（ist=1 試玩；試玩金幣 1000 不記帳） |
| `token` | String | ❌ | 商戶 sessionToken |
| `sign` | String | ✅ | 簽名 |

> 實作 `getGameUrl()` 固定傳：`appid`、`username`、`iscreate=0`、`clienttype=2`（手機）、`language`（依會員語系映射）。

**成功回應：**

```jsonc
{ "result": 0, "desc": "OK", "openurl": "http://.../api/opengame.jsp?code=...&language=1" }
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `result` | Int | 成功 `>= 0`，`< 0` 異常 |
| `desc` | String | 描述 |
| `openurl` | String | 進入遊戲連結 |

**錯誤回應：**

```jsonc
{ "result": -1, "desc": "error!" }
```

---

#### 2. 踢出遊戲 `/api/kickout`

| 項目 | 值 |
|---|---|
| Method | POST |
| 請求參數 | `appid` ✅、`username` ✅、`sign` ✅ |

成功：`{"result":"0","desc":"OK"}`；失敗：`{"desc":"error!","result":-1}`。

---

#### 3. 建立用戶 `/api/register`

| 項目 | 值 |
|---|---|
| Method | POST |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | ✅ | 分配應用 ID |
| `username` | String | ✅ | 登入名 |
| `nickname` | String | ❌ | 暱稱（不傳系統隨機生成） |
| `state` | byte | ❌ | 狀態（1 開放, 0 禁止, -1 鎖定） |
| `commratio` | String | ❌ | 佣金占成 |
| `quotas` | String | ❌ | 限額字段（「1,2,3」逗號分割） |
| `returnurl` | String | ❌ | 返回地址 |
| `sign` | String | ✅ | 簽名 |

成功：`{"result":"0","desc":"OK"}`；失敗示例：`{"result":-4,"desc":"signature error!"}`。

---

#### 4. 修改用戶基本資訊 `/api/user/update`

| 項目 | 值 |
|---|---|
| Method | POST |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | ✅ | 分配應用 ID |
| `username` | String | ✅ | 登入名 |
| `nickname` | String | ❌ | 暱稱 |
| `state` | byte | ❌ | 狀態（1 開放, 0 禁止, -1 鎖定） |
| `betstate` | byte | ❌ | 下注狀態（0 開放, -1 禁止, -2 超出可贏, -3 超出可輸） |
| `commratio` | String | ❌ | 佣金占成 |
| `quotas` | String | ❌ | 限額字段 |
| `sign` | String | ✅ | 簽名 |

成功：`{"result":"0","desc":"OK"}`。

---

#### 5. 取得用戶基本資訊 `/api/user/info`

| 項目 | 值 |
|---|---|
| Method | GET |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | ✅ | 分配應用 ID |
| `username` | String | ✅ | 登入名 |
| `sign` | String | ✅ | 簽名 |

**成功回應：**

```jsonc
{ "result": 0, "userdata": { "betstate": 0, "quota": "A,B", "commratio": "11111", "state": 0 } }
```

| `userdata.` 欄位 | 說明 |
|---|---|
| `state` | 狀態（0 開放, -1 禁止, -2 鎖定） |
| `betstate` | 下注狀態（0 開放, -1 禁止, -2 超出可贏, -3 超出可輸） |
| `commratio` | 佣金占成 |
| `quota` | 限額方案 |

---

### 用戶轉點（轉帳錢包）

#### 6. 取得用戶餘額 `/api/user/balance`

| 項目 | 值 |
|---|---|
| Method | GET |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | ✅ | 分配應用 ID |
| `username` | String | ✅ | 登入名 |
| `sign` | String | ✅ | 簽名 |

**成功回應：**

```jsonc
{ "result": 0, "balance": 100 }
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `result` | Int | 成功 0、失敗 < 0 |
| `balance` | Float | 餘額 |
| `isonline` | Boolean | 是否在線 |
| `isplaying` | Boolean | 是否有投注 |

---

#### 7. 用戶存取款 `/api/user/dw`

轉點核心接口。**`amount` 正數=存款（轉入 Ybet）、負數=取款（轉出 Ybet）**。本專案 `transferToVendor()`（存款）傳正數；`transferToLocal()`（提款）傳 `'-' + 金額`。

| 項目 | 值 |
|---|---|
| Method | POST |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | ✅ | 分配應用 ID |
| `username` | String | ✅ | 登入名 |
| `tradeno` | long | ❌ | 訂單號（不傳時系統預設生成）；實作固定帶 `tradeno`，用於後續查單 |
| `amount` | Double | ✅ | 金額（負數取款，正數存款） |
| `sign` | String | ✅ | 簽名 |

**成功回應：**

```jsonc
{ "result": "0", "desc": "OK", "balance": "20000" }
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `result` | Int | 成功 0、失敗 < 0 |
| `desc` | String | 描述 |
| `orderno` | long | 訂單號 |
| `balance` | double | 用戶餘額 |

> 失敗示例：`{"result":-4,"desc":"signature error!"}`。餘額不足為 `-1020`，扣款金額不足為 `-1030`。

---

#### 8. 取得存取款記錄 `/api/user/trade`

查詢轉點記錄。本專案 `checkTransferStatus()` 用於查單確認最終狀態。

| 項目 | 值 |
|---|---|
| Method | POST |

**請求參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `appid` | String | ✅ | 分配應用 ID |
| `username` | String | ✅ | 登入名 |
| `tradeno` | String | ❌ | 訂單號 |
| `begintime` | Int（秒） | ✅ | 起始時間（Unix 秒） |
| `endtime` | Int（秒） | ✅ | 結束時間（Unix 秒） |
| `index` | int | ✅ | 起始頁 |
| `size` | int | ✅ | 頁面大小 |
| `sign` | String | ✅ | 簽名 |

> 實作查單時：`begintime` = 交易建立時間 -5 秒、`endtime` = +5 秒、`index=0`、`size=1`。

**成功回應：**

```jsonc
{ "result": 0, "data": [{}], "total": 1 }
```

| `data[].` 欄位 | 類型 | 說明 |
|---|---|---|
| `ID` | Long | 記錄 ID |
| `type` | byte | 記錄類型（7 存款, 8 取款） |
| `tradeno` | long | 記錄編號 |
| `payway` | long | 支付方式（11 接口形式） |
| `amount` | double | 金額 |
| `state` | byte | 狀態（-1 失敗, 1 申請, 2 處理中, 11 成功） |
| `desc` | String | 備註 |
| `auditid` | String | 操作者 id |
| `updatetime` / `createtime` | int | 修改 / 建立時間 |

---

#### 9. 限額列表 `/api/quota/list`

| 項目 | 值 |
|---|---|
| Method | GET |

請求：`appid` ✅、`notifyid`（訊息號）❌、`sign` ✅。

**成功回應：**

```jsonc
{ "result": 0, "array": [
  { "ID": 1, "up": 75.0, "title": "0.5-75.0", "down": 0.5 },
  { "ID": 2, "up": 150.0, "title": "0.5-150.0", "down": 0.5 }
], "arraysize": 3 }
```

| `array[].` 欄位 | 說明 |
|---|---|
| `ID` | 限額 id（用於用戶限額設定回傳） |
| `title` | 標題 |
| `up` / `down` | 上限 / 下限 |

---

#### 10. 限額設定 `/api/quota/set`

| 項目 | 值 |
|---|---|
| Method | POST |

請求：`appid` ✅、`username` ✅、`qids`（限額 ID，多組逗號分割「1,3,4」）✅、`sign` ✅。
成功：`{"result":"0","desc":"OK"}`。

---

### 注單 / 報表

> ⚠️ 本組接口成功值為 `"result":1`（非 0），實作判斷須留意。

#### 11. 取得遊戲注單 `/api/data/getbets`

拉取尚未被處理（消費）的注單，配合 §12 `removebets` 形成「拉取→處理→回報」的對帳閉環。

請求：`appid` ✅、`size`（查詢大小）❌、`sign` ✅。

**成功回應：**

```jsonc
{ "result": 0, "array": [{
  "createtime": "2016-06-28 14:51:53", "betpoint": "PLAYER", "gameround": 2098,
  "commratio": "", "shoenum": 6, "shoeid": 27, "userid": 4, "sbanker": 0,
  "commamount": 0.0, "winlost": 50.0, "gametype": 2, "tableid": 6,
  "choosebankpoint": "", "id": 12, "serialnumber": 12, "state": 1,
  "username": "FAKHD10001", "betamount": 50.0
}], "arraysize": 1 }
```

| `array[].` 欄位 | 類型 | 說明 |
|---|---|---|
| `id` | Long | 注單 ID |
| `serialnumber` | Long | 注單編號 |
| `userid` | Long | 用戶 ID |
| `username` | String | 用戶名稱 |
| `gametype` | Byte | 遊戲類型 |
| `gameid` | Long | 遊戲 id |
| `gameno` / `gameround` | Long | 遊戲局號 |
| `choosebankpoint` | String | 三公選莊 |
| `bettype` | String | 投注類型（默認 0） |
| `betpoint` | String | 投注點 |
| `betamount` | Double | 投注金額 |
| `winlost` | Double | 輸贏 |
| `commamount` | Double | 洗碼量 |
| `commratio` | String | 占成字段 |
| `createtime` | String | 建立時間 |
| `state` | — | 注單狀態（-2 撤銷, -1 廢棄, 0 投注, 1 結算） |

---

#### 12. 回調已處理注單 `/api/data/removebets`

回報已被代理消費（處理）的注單，使其不再被 `getbets` 拉到。

請求：`appid` ✅、`ids`（已消費注單 id，逗號分割）❌、`sign` ✅。
成功：`{"desc":"ok!","result":1}`；失敗：`{"desc":"error!","result":-1}`。

---

#### 13. 遊戲列表 `/api/data/getgames`

請求：`appid` ✅、`notifyid` ✅、`sign` ✅。

**成功回應：**

```jsonc
{ "result": 0, "array": [{ "id": 0, "state": 1 }], "arraysize": 1 }
```

| `array[].` 欄位 | 說明 |
|---|---|
| `id` | 遊戲 ID |
| `gametype` / `gamestyle` | 遊戲類型 / 風格 |
| `title` | 遊戲標題 |
| `state` | 遊戲狀態（-1 關閉, 0 閒置, 1 開啟, 2 封盤, 3 維護） |
| `betstate` | 投注狀態（0 關閉, 1 開啟） |
| `rank` | 排序（1,2,3,4） |
| `updatetime` | 修改時間 |

---

#### 14. 遊戲歷史結果 `/api/record/game/history`

請求：`appid` ✅、`notifyid` ✅、`gameid` ✅、`index`（頁碼,0 開始）✅、`size` ✅、`sign` ✅。

**成功回應：**

```jsonc
{ "result": 0, "array": [{ "gameid": 0, "gameresult": "1;2;3;4;5;6;7;8" }], "arraysize": 1 }
```

| `array[].` 欄位 | 說明 |
|---|---|
| `gameid` | 遊戲 ID |
| `gameno` | 遊戲期號 |
| `gameresult` | 遊戲結果（牌值編碼，見附錄 §3.5） |
| `state` | 遊戲狀態（5 已結束） |
| `finishtime` | 結束時間 |

---

#### 15. 注單統計 `/api/record/bets/stat`

請求：`appid` ✅、`notifyid` ✅、`username` ✅、`begintime`（秒）✅、`endtime`（秒）✅、`sign` ✅。

**成功回應：**

```jsonc
{ "result": 0, "data": { "betnum": 0, "sbetamount": 0 }, "desc": "ok" }
```

| `data.` 欄位 | 說明 |
|---|---|
| `betnum` | 注單數量 |
| `sbetamount` | 注單總金額 |
| `swinlost` | 注單總輸贏 |

---

#### 16. 注單詳細 `/api/record/bets/detail`

請求：`appid` ✅、`notifyid` ✅、`username` ✅、`begintime`（秒）✅、`endtime`（秒）✅、`index`（頁碼,0 開始）✅、`size` ✅、`sign` ✅。

**成功回應：**

| `array[].` 欄位 | 說明 |
|---|---|
| `id` | 注單 id |
| `gameid` | 遊戲 ID |
| `gameroundid` | 遊戲期 id |
| `bettype` | 投注類型 |
| `betamount` | 投注金額 |
| `balance` | 投注後金額 |
| `betodds` | 投注時賠率 |
| `createtime` | 投注時間 |

---

#### 17. 投注跟隨 `/api/user/bets/follow`

請求：`appid` ✅、`notifyid` ✅、`username` ✅、`betid`（被跟隨的注單 id）✅、`sign` ✅。
成功：`{"desc":"ok!","result":1}`；失敗：`{"desc":"error!","result":-1}`。

> 文件回應表此節印刷有誤（四欄皆 `desc`/`result`），以成功 / 失敗示例為準。

---

#### 18. 帳戶明細 `/api/record/account/detail`

請求：`appid` ✅、`notifyid` ✅、`username` ✅、`begintime`（秒）✅、`endtime`（秒）✅、`index`（頁碼,0 開始）✅、`size` ✅、`sign` ✅。

**成功回應：**

| `array[].` 欄位 | 說明 |
|---|---|
| `id` | 帳單 id |
| `optype` | 操作類型（見附錄帳號操作類型） |
| `orderno` | 遊戲期 id |
| `amount` | 變化金額 |
| `balance` | 變化後金額 |
| `createtime` | 建立時間 |

---

#### 19. 重新結算 `/api/user/bets/settle`

請求：`appid` ✅、`notifyid` ✅、`id`（注單 ID）✅、`sign` ✅。
成功：`{"desc":"ok!","result":1}`；失敗：`{"desc":"error!","result":-1}`。

---

## 注單狀態與帳號操作類型

### `/api/data/getbets` 注單狀態 state

| 值 | 含義 |
|---|---|
| `-2` | 撤銷 |
| `-1` | 廢棄 |
| `0` | 投注（未結算） |
| `1` | 結算 |

> 對帳寫入 BetRecord 時，僅 `state=1`（結算）才有最終輸贏；`-2`/`-1` 須沖銷對應注單。

### 帳號操作類型 optype（§3.6，`/api/record/account/detail`）

| 值 | 描述 | 值 | 描述 |
|---|---|---|---|
| `1` | 存款 | `8` | 撤銷投注 |
| `2` | 取款 | `9` | 金額轉移 |
| `3` | 投注 | `23` | 派送福利 |
| `4` | 結算 | `24` | 派送回水 |
| `5` | 取消結果 | `25` | 派送彩金 |
| `6` | 更正結果 | `28` | 個人打賞 |
| `7` | 異常回滾金額 | — | — |

### `/api/user/trade` 存取款記錄 state

| 值 | 含義 |
|---|---|
| `-1` | 失敗 |
| `1` | 申請 |
| `2` | 處理中 |
| `11` | 成功 |

> `checkTransferStatus` 以此判斷轉點最終狀態（`11`→SUCCESS、`-1`→FAILED、其餘中間態→TIMEOUT）。

---

## 附錄

### 語言代碼（§3.2）

| 取值 | 語言 |
|---|---|
| `1` | 簡體中文 |
| `2` | 英文 |
| `3` | 韓文 |
| `4` | 日本文 |
| `5` | 泰文 |

### 遊戲類型（§3.3）

| 取值 | 遊戲 |
|---|---|
| `4` | 百家樂 |
| `5` | 輪盤 |
| `10` | 龍虎 |
| `11` | 骰寶 |
| `14` | 牛牛 |
| `17` | 三公 |

### 撲克牌結果編碼（§3.5）

牌值編碼 = `(點數-1) × 4 + 花色`，花色順序：方塊♦(1)、梅花♣(2)、紅心♥(3)、黑桃♠(4)。例：方塊A=1、黑桃K=52。完整對照表見原始 PDF §3.5。

### 貨幣編碼（§3.7）

支援 173 種標準 ISO 幣別碼（`AED`…`ZWL`）＋ 3 種千分位變體（`VND2`=越南盾1000、`MMK2`=緬甸元1000、`IDR2`=印尼盧比1000）。常用：`CNY`(32)、`CNH`(31)、`USD`(153)、`THB`(142)、`VND`(157)、`IDR`(65)、`MYR`(106)、`KRW`(81)。完整對照見原始 PDF §3.7。

### 玩法解析（§3.4）

含百家樂（TIED/PLAYER/BANKER/SBANKER/BANKERD/PLAYERD/BIG/SMALL/SUPER_SIX/LUCKY_SIX）、輪盤（猜點數 / 大小單雙紅黑 / 前中後期 / 線注 / 分注等 8 種 bettype）、龍虎（TIED/TIGER/DRAGON/ODD/EVEN）等投注點對照，完整內容見原始 PDF §3.4。

---

## 錯誤碼（§3.1）

| 代碼 | 適用 | 描述 |
|---|---|---|
| `0` | 所有接口 | 成功 |
| `-1000` | 所有接口 | 系統未知異常，請聯繫 |
| `-1001` | 所有接口 | 系統內部異常，請聯繫 |
| `-1002` | 所有接口 | 參數錯誤，請檢查所傳參數 |
| `-1005` | 所有接口 | Appid 錯誤 |
| `-1006` | 所有接口 | Appid 不存在，請聯繫 |
| `-1007` | 所有接口 | 簽名錯誤，請檢查簽名 |
| `-1008` | 所有接口 | 過期的請求 |
| `-1009` | 所有接口 | 請求太頻繁 |
| `-1010` | 所有接口 | 非法 IP |
| `-1011` | 用戶接口 | 未註冊，請先註冊 |
| `-1012` | 用戶接口 | 用戶不存在 |
| `-1013` | 用戶接口 | 用戶已經存在 |
| `-1014` | 用戶接口 | 用戶被禁止 |
| `-1015` | 用戶接口 | 用戶密碼錯誤 |
| `-1020` | 錢包接口 | 用戶餘額不足 |
| `-1021` | 用戶接口 | 存取款金額為非零數 |
| `-1022` | 用戶接口 | 限額不存在 |
| `-1030` | 錢包接口 | 扣款金額不足 |
| `-1040` | 驗證碼 | 驗證碼錯誤 |
| `-1041` | 所有接口 | 非法域名 |
