# QuickPay 廠商規格

> 官方 API 文檔：https://example.invalid/quickPay2022
> 內部實作路徑：`app/Services/Payment/Providers/QuickPay/`

QuickPay（由 DGPay 提供）是面向越南市場（VND）的第三方支付平台，支援代收（Deposit）與代付（Withdrawal）。所有請求使用 **application/x-www-form-urlencoded（FORM）** 格式提交，響應為 JSON。驗證方式為 **MD5 簽名**（參數按 ASCII 碼排序後拼接 key）。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [代收回調](#5-代收回調)
6. [代付回調](#6-代付回調)
7. [簽名流程](#7-簽名流程)
8. [錯誤碼對照](#8-錯誤碼對照)
9. [銀行代碼映射（VND 代付）](#9-銀行代碼映射vnd-代付)
10. [參考資料](#10-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL | `https://api.quickpay.support` |
| 商戶後台 | `https://manager.quickpay.center/login` |
| 請求格式 | `application/x-www-form-urlencoded` |
| 響應格式 | JSON |
| 簽名方式 | MD5（參數 ASCII 升序排列後拼接 `key=SECRET_KEY`，大寫） |
| 代收 Endpoint | `POST /pay` |
| 代付 Endpoint | `POST /applyfor` |

> 配置中 API Base URL 對應 `info['API_URL']`，商戶 ID 對應 `info['CLIENT_ID']`，簽名密鑰對應 `info['SECRET_KEY']`。

---

## 2. 支援的支付方式與幣別

### VND（越南盾）

| 支付類型 | channel 值 | 說明 |
|---|---|---|
| Momo 電子錢包 | `923` | 越南 Momo 掃碼支付 |
| 銀行轉帳 | `908` | 一般銀行帳號轉帳 |
| 掃碼支付（QR） | `909` | 銀行 QR 掃碼轉帳 |
| 網銀支付 | `907` | （目前不支援） |

> 代付 channel 固定為 `712`（越南銀行轉帳），見 `Withdrawal.php`。

### 代收限額

| 幣別 | 單筆最低 | 單筆最高 |
|---|---|---|
| VND | 100,000 | 300,000,000 |

### 代付限額

| 幣別 | 單筆最低 | 單筆最高 |
|---|---|---|
| VND | 300,000 | 100,000,000 |

---

## 3. 代收 API

### `POST /pay`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `uid` | ✅ | string | 商戶 ID（`CLIENT_ID`） |
| `orderid` | ✅ | string | 商戶平台訂單號（1~32 字元，唯一） |
| `channel` | ✅ | integer | 支付類型代碼（見支付方式表，如 `908`、`909`、`923`） |
| `notify_url` | ✅ | string | 異步回調 URL（≤100 字元） |
| `return_url` | ✅ | string | 付款完成後跳轉 URL（≤100 字元） |
| `amount` | ✅ | integer | 訂單金額（越南盾整數，單位：VND） |
| `userip` | ✅ | string | 客戶端 IP（≤40 字元） |
| `timestamp` | ✅ | integer | Unix 時間戳（秒） |
| `custom` | - | string | 附言（≤100 字元，可空字串） |
| `sign` | ✅ | string | MD5 簽名（大寫，見簽名流程） |

> 注意：所有字串值傳入前需 `trim()`，否則空白字元會導致簽名不符。

**請求範例**

```
uid={CLIENT_ID}&orderid=ORDER20260101001&channel=908&notify_url=https%3A%2F%example.invalid%2Fcallback%2Fdeposit&return_url=https%3A%2F%example.invalid%2Fpay%2Freturn&amount=500000&userip=0.0.0.0&timestamp=1735689600&custom=&sign={SIGN}
```

**成功響應**

```json
{
    "status": 10000,
    "result": {
        "transactionid": "QP20260101000001",
        "payurl": "https://pay.quickpay.support/checkout?token=[REDACTED]"
    }
}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | integer | `10000` 為成功，其他值為錯誤碼 |
| `result.transactionid` | string | 三方平台交易流水號 |
| `result.payurl` | string | 收銀台付款 URL，需導向玩家 |

**失敗響應範例**

```json
{
    "status": 21014,
    "message": "商户平台订单号重复"
}
```

**成功判定**：`response['status'] == 10000`

---

## 4. 代付 API

### `POST /applyfor`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `uid` | ✅ | string | 商戶 ID（`CLIENT_ID`） |
| `orderid` | ✅ | string | 商戶平台訂單號（由系統自動生成，前綴 `QUICKPAY`） |
| `channel` | ✅ | integer | 代付通道，固定為 `712`（VND 銀行轉帳） |
| `notify_url` | ✅ | string | 異步回調 URL |
| `amount` | ✅ | integer | 代付金額（VND 整數） |
| `userip` | ✅ | string | 發起端 IP |
| `timestamp` | ✅ | integer | Unix 時間戳（秒） |
| `custom` | - | string | 附言（可空字串） |
| `bank_account` | ✅ | string | 收款人開戶姓名（1~50 字元） |
| `bank_no` | ✅ | string | 收款銀行帳號（1~20 字元） |
| `bank_id` | ✅ | integer | 銀行編號（三方銀行代碼，見銀行代碼映射表） |
| `bank_province` | - | string | 開戶行省份（可空，VND 通常不用） |
| `bank_city` | - | string | 開戶行城市（可空，VND 通常不用） |
| `bank_sub` | - | string | 開戶支行（可空，VND 通常不用） |
| `user_name` | ✅ | string | 收款人姓名（與 `bank_account` 一致） |
| `sign` | ✅ | string | MD5 簽名（大寫，見簽名流程） |

> `bank_id` 由內部 `available_banks` 配置映射：從 target 銀行代碼（`BankCode_ELG`）轉換為三方代碼（`BankCode_Third`），透過 `convertPayoutBankCode()` 完成轉換。

**成功響應**

```json
{
    "status": 10000,
    "result": {
        "transactionid": "QP20260101000002"
    }
}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | integer | `10000` 為成功，其他值為錯誤碼 |
| `result.transactionid` | string | 三方平台代付流水號 |

**失敗響應範例**

```json
{
    "status": 30020,
    "message": "商户余额不足"
}
```

**成功判定**：`response['status'] == 10000`

> 代付為非同步處理，送單成功後最終結果透過回調通知。

---

## 5. 代收回調

QuickPay 在交易完成後，以 **POST application/x-www-form-urlencoded** 格式推送到代收請求中的 `notify_url`。

### 回調欄位

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `status` | ✅ | integer | 交易狀態碼：`10000` 為成功，其他為失敗 |
| `sign` | ✅ | string | MD5 簽名（大寫），驗證方式與請求簽名相同 |
| `result` | ✅ | string | JSON 字串，需 `json_decode` 解析 |

**`result` 解析後欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `orderid` | string | 商戶平台訂單號 |
| `transactionid` | string | 三方平台交易流水號 |
| `amount` | integer/string | 實際到帳金額（VND） |

**回調範例**

```
status=10000&sign=ABCDEF1234567890ABCDEF1234567890&result=%7B%22orderid%22%3A%22ORDER20260101001%22%2C%22transactionid%22%3A%22QP20260101000001%22%2C%22amount%22%3A500000%7D
```

解析後 `result`：

```json
{
    "orderid": "ORDER20260101001",
    "transactionid": "QP20260101000001",
    "amount": 500000
}
```

**成功判定**：`status == 10000`

**平台回應**：處理成功回傳純文字 `success`；失敗回傳 `fail`。

### 處理流程

1. 驗證 `status`、`sign`、`result` 三個欄位必填
2. 計算簽名（含 `status`、`sign`、`result` 三個欄位參與簽名），比對 `sign` 是否一致
3. `json_decode($result)` 取得 `orderid`、`transactionid`、`amount`
4. 依 `orderid` 查詢並鎖定訂單（`lockForUpdate`）
5. 檢查訂單是否已處理（冪等性防禦）
6. `status == 10000` → 更新訂單為成功；否則 → 更新為失敗

---

## 6. 代付回調

代付結果以 **POST application/x-www-form-urlencoded** 格式推送到代付請求中的 `notify_url`。

### 回調欄位

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `status` | ✅ | integer | 代付狀態碼：`10000` 為成功，其他為失敗 |
| `sign` | ✅ | string | MD5 簽名（大寫） |
| `result` | ✅ | string | JSON 字串，需 `json_decode` 解析 |

**`result` 解析後欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `orderid` | string | 商戶平台訂單號 |
| `amount` | integer/string | 代付金額（VND） |

**回調範例**

```
status=10000&sign=ABCDEF1234567890ABCDEF1234567890&result=%7B%22orderid%22%3A%22QUICKPAY20260101001%22%2C%22amount%22%3A1000000%7D
```

解析後 `result`：

```json
{
    "orderid": "QUICKPAY20260101001",
    "amount": 1000000
}
```

**成功判定**：`status == 10000`

**平台回應**：處理成功回傳純文字 `SUCCESS`；失敗回傳 `FAIL`。

> 注意：代收回調成功回應為小寫 `success`，代付為大寫 `SUCCESS`，兩者不同。

### 處理流程

1. 驗證 `status`、`sign`、`result` 三個欄位必填
2. 驗證簽名
3. `json_decode($result)` 取得 `orderid`、`amount`
4. 依 `orderid` 查詢代付請求記錄（`lockForUpdate`）
5. 檢查記錄是否已處理（冪等性防禦）
6. 依記錄查詢代付訂單（`lockForUpdate`）
7. 僅成功時驗證金額（失敗時金額可能不準確，不作驗證）
8. 更新代付請求記錄與訂單狀態

---

## 7. 簽名流程

QuickPay 使用 **MD5** 作為簽名演算法。

### 簽名步驟

1. **排除** `sign` 欄位後，取所有剩餘參數
2. 將參數按**欄位名 ASCII 碼從小到大（a→z）升序**排列（`ksort`）
3. 以 `key=value&key=value&...` 格式拼接（每個 value 需先 `trim()`）
4. 最後拼接 `&key={SECRET_KEY}`
5. 對整個字串做 **MD5** 運算
6. 將結果轉為**大寫**

### 範例

假設代收請求參數（已 trim、已排序）如下：

```
amount=500000
channel=908
custom=
notify_url=https://example.invalid/callback/deposit
orderid=ORDER20260101001
return_url=https://example.invalid/pay/return
timestamp=1735689600
uid=YOUR_CLIENT_ID
userip=0.0.0.0
```

拼接字串：

```
amount=500000&channel=908&custom=&notify_url=https://example.invalid/callback/deposit&orderid=ORDER20260101001&return_url=https://example.invalid/pay/return&timestamp=1735689600&uid=YOUR_CLIENT_ID&userip=0.0.0.0&key={SECRET_KEY}
```

簽名結果 = `strtoupper(md5(上方字串))`

### PHP 實作參考

```php
protected function generateSignature(array $params, string $secretKey): string
{
    unset($params['sign']);
    ksort($params);

    $queryString = '';
    foreach ($params as $key => $value) {
        $queryString .= $key . '=' . trim((string) $value) . '&';
    }

    $stringToSign = $queryString . 'key=' . $secretKey;

    return strtoupper(md5($stringToSign));
}
```

> 回調驗證使用同一套簽名演算法：將回調收到的 `status`、`sign`、`result` 三個欄位（`sign` 本身不參與運算）重新計算，比對是否一致。

---

## 8. 錯誤碼對照

### 參數驗證失敗（2xxxx 系列）

| 錯誤碼 | 說明 |
|---|---|
| `20001` | 發送模式錯誤 |
| `20002` | header 錯誤 |
| `20003` | 沒有取得參數 |
| `20004` | 參數異常 |
| `20005` | 沒有取得商號 |
| `20041` | 沒有取得 sign |
| `20042` | sign 不相符 |
| `20091` | 幣別禁用 |
| `20092` | 權限禁用 |
| `20093` | 商戶禁用 |
| `20094` | 交易類型禁用 |
| `20095` | 平台 API 線禁用 |
| `20096` | 商戶 API 線禁用 |
| `20097` | 商戶單點 API 禁用 |
| `21011` | 沒有取得商戶平台訂單號 |
| `21012` | 商戶平台訂單號長度小於 1 字元 |
| `21013` | 商戶平台訂單號長度大於 32 字元 |
| `21014` | 商戶平台訂單號重複 |
| `21016` | 沒有取得支付類型 |
| `21017` | 支付類型不是數字 |
| `21018` | 支付類型不存在 |
| `21019` | 支付類型未啟用 |
| `21020` | 支付類型錯誤 |
| `21021` | 沒有取得回調連結 |
| `21022` | 回調連結長度大於 100 字元 |
| `21026` | 沒有取得跳轉連結 |
| `21027` | 跳轉連結長度大於 100 字元 |
| `21031` | 沒有取得金額 |
| `21032` | 金額不是數字 |
| `21033` | 金額小於單筆最小量 |
| `21034` | 金額大於單筆最大量 |
| `21035` | 金額大於單日交易最大量 |
| `21036` | 沒有取得客戶端 IP |
| `21037` | 客戶端 IP 長度大於 40 字元 |
| `21041` | 沒有取得時間戳記 |
| `21042` | 時間戳記不是數字 |
| `21046` | 沒有取得附言 |
| `21047` | 附言長度大於 100 字元 |
| `21071` | 起始時間長度大於 19 字元 |
| `21072` | 起始時間格式錯誤 |
| `21076` | 截止時間長度大於 19 字元 |
| `21077` | 截止時間格式錯誤 |
| `21081` | 頁數不是數字 |
| `21086` | 行數不是數字 |
| `21087` | 行數小於 1 |
| `21088` | 行數大於 1000 |
| `21101` | 沒有取得收款人開戶姓名 |
| `21102` | 收款人開戶姓名長度小於 1 字元 |
| `21103` | 收款人開戶姓名長度大於 50 字元 |
| `21106` | 沒有取得收款人銀行帳號 |
| `21107` | 收款人銀行帳號長度小於 1 字元 |
| `21108` | 收款人銀行帳號長度大於 20 字元 |
| `21111` | 沒有取得銀行編號 |
| `21112` | 銀行編號不是數字 |
| `21113` | 銀行編號不存在 |
| `21114` | 銀行編號未啟用 |
| `21115` | 銀行編號錯誤 |
| `21116` | 沒有取得開戶支行 |
| `21117` | 開戶支行長度小於 1 字元 |
| `21118` | 開戶支行長度大於 20 字元 |
| `21121` | 沒有取得開戶行所在省份 |
| `21122` | 開戶行所在省份長度小於 1 字元 |
| `21123` | 開戶行所在省份長度大於 20 字元 |
| `21126` | 沒有取得開戶行所在城市 |
| `21127` | 開戶行所在城市長度小於 1 字元 |
| `21128` | 開戶行所在城市長度大於 20 字元 |
| `21136` | 不允許的 IP |
| `21137` | 不允許的代付 IP |
| `21141` | 沒有取得 IFSC |
| `21142` | IFSC 長度大於 20 字元 |
| `21143` | 沒有取得 pay_type |
| `21144` | pay_type 與金流商支付通道不符 |
| `21145` | bank_sub 非 Email 格式 |

### 資料處理失敗（3xxxx 系列）

| 錯誤碼 | 說明 |
|---|---|
| `30001` | 商戶不存在 |
| `30002` | 商戶沒有開通此交易渠道 |
| `30003` | 商戶沒有設定此交易渠道 |
| `30004` | 商戶沒有開通此交易類型 |
| `30011` | 交易單建立失敗 |
| `30016` | 交易單不存在 |
| `30020` | 商戶餘額不足 |
| `30021` | 平台 API 沒有支援此幣別 |
| `30100` | 支付通道阻塞 |
| `30901` | 訂單失效 |
| `30906` | 登入失敗 |
| `30907` | 餘額不足 |
| `30911` | 驗證失敗 |
| `30912` | 實名失敗 |
| `30916` | 交易失敗 |
| `30921` | 交易超時 |

### 系統阻擋（9xxxx 系列）

| 錯誤碼 | 說明 |
|---|---|
| `90001` | 維護中 |
| `90091` | API IP 跳轉過多 |
| `90092` | API IP 在黑名單中 |
| `90093` | 沒有提供收銀台模式 |
| `90901` | 金流通道回傳錯誤格式 |
| `90902` | 金流通道沒有回傳結果 |
| `90903` | 金流通道回傳失敗 |
| `90904` | 金流通道沒有回傳連結 |
| `90905` | 金流通道回傳連結為空 |
| `90906` | 金流通道交易失敗 |
| `99990` | 系統商 API 錯誤 |
| `99999` | 沒有此 API |

---

## 9. 銀行代碼映射（VND 代付）

代付 `bank_id` 為 QuickPay 三方銀行編號，由內部 `available_banks` 配置的 `BankCode_Third` 欄位取得（透過 `convertPayoutBankCode()` 從 target 內部代碼轉換）。以下為工單記錄的 VND 支援銀行：

| 銀行名稱 | target 代碼 | 說明 |
|---|---|---|
| ABBANK | `ABBANK` | An Binh Commercial Joint Stock Bank |
| ACB | `ACB` | Asia Commercial Bank |
| AGRIBANK | `AGRIBANK` | Vietnam Bank for Agriculture and Rural Development |
| BAB | `BAB` | Bac A Bank |
| BIDV | `BIDV` | Bank for Investment and Development of Vietnam |
| BVB | `BVB` | Bao Viet Bank |
| DONGA | `DONGA` | DongA Bank |
| EIB | `EIB` | Eximbank Vietnam |
| EXB | `EXB` | Exim Bank |
| GPB | `GPB` | GPBank |
| HDB | `HDB` | HD Bank |
| KLB | `KLB` | Kien Long Commercial Joint Stock Bank |
| LPB | `LPB` | LienVietPostBank |
| MBBANK | `MBBANK` | Military Commercial Joint Stock Bank |
| MSB | `MSB` | Maritime Bank |
| NAMA | `NAMA` | Nam A Commercial Joint Stock Bank |
| NCB | `NCB` | National Citizen Commercial Joint Stock Bank |
| OCB | `OCB` | Orient Commercial Joint Stock Bank |
| OCEAN | `OCEAN` | Oceanbank |
| PGB | `PGB` | PG Bank |
| SAIGON | `SAIGON` | Saigon Commercial Bank |
| SCB | `SCB` | Sacombank |
| SEAB | `SEAB` | SeABank |
| SHB | `SHB` | Saigon-Hanoi Commercial Joint Stock Bank |
| STB | `STB` | Saigon Thuong Tin Commercial Bank |
| TECHB | `TECHB` | Techcombank |
| TPB | `TPB` | Tien Phong Commercial Bank (TP Bank) |
| VAB | `VAB` | VietABank |
| VCB | `VCB` | Vietcombank |
| VIB | `VIB` | Vietnam International Commercial Joint Stock Bank |
| VIETINBANK | `VIETINBANK` | VietinBank |
| VPB | `VPB` | VP Bank |

> target 代碼到三方 `bank_id`（數字編號）的實際對應值，儲存於各白標 `agent_cash_flows.info.available_banks` 配置中，非寫死於程式碼。

---

## 10. 參考資料

- 官方 API 文檔：https://example.invalid/quickPay2022
- 生產環境 API：`https://api.quickpay.support`
- 商戶後台：`https://manager.quickpay.center/login`
- 內部實作路徑：`app/Services/Payment/Providers/QuickPay/`
  - `Driver.php` — 組合代收/代付子類別
  - `QuickPayTrait.php` — 請求發送（`sendRequest`）、簽名生成（`generateSignature`）、錯誤碼解析（`resolveStatusMessage`）
  - `Deposit.php` — 代收實作，呼叫 `POST /pay`
  - `DepositCallback.php` — 代收回調處理
  - `Withdrawal.php` — 代付實作，呼叫 `POST /applyfor`
  - `WithdrawalCallback.php` — 代付回調處理
- 相關 GitLab 工單：
  - [#3436](http://example.invalid:1688/target/tickets/-/work_items/3436) — APIs 代收代付接入
  - [#3503](http://example.invalid:1688/target/tickets/-/work_items/3503) — TPower VND 配置
  - [#3504](http://example.invalid:1688/target/tickets/-/work_items/3504) — Yes2Win VND 配置
  - [#2795](http://example.invalid:1688/target/tickets/-/work_items/2795) — Yes2Win 初始配置（含完整銀行列表）

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：官方 API 文檔（https://example.invalid/quickPay2022）、GitLab 工單 #3436、#3503、#3504、#2795、Codebase 實作*
