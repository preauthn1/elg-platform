# YeePay 廠商規格

> 官方 API 文檔：https://example.invalid/#/apidocument（需密碼存取）
> 內部實作路徑：`app/Services/Payment/Providers/YeePay/`

YeePay 是面向越南市場的第三方支付平台，支援代收（Deposit）與代付（Withdrawal），幣別為 **VND**。代收提供三種支付方式：QR 掃碼（pay_type=7）、MoMo 電子錢包（pay_type=8）、銀行轉帳（pay_type=9）。所有請求使用 **FORM-DATA** 格式（`application/x-www-form-urlencoded`），響應為 JSON。驗證採用 **MD5 簽名**（ASCII 升序排序後拼接，大寫輸出）。

---

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [回調處理](#5-回調處理)
6. [簽名流程](#6-簽名流程)
7. [銀行代碼映射](#7-銀行代碼映射)
8. [參考資料](#8-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 商戶後台 | `https://example.invalid/` |
| 官方 API 文檔 | `https://example.invalid/#/apidocument`（需密碼） |
| 代收 API URL | 由 `DEPOSIT_URL` 配置（config key：`agent_cash_flows.info.DEPOSIT_URL`） |
| 代付 API URL | 由 `WITHDRAWAL_URL` 配置（config key：`agent_cash_flows.info.WITHDRAWAL_URL`） |
| 請求格式 | FORM-DATA（`application/x-www-form-urlencoded`） |
| 響應格式 | JSON |
| 認證方式 | MD5 簽名（`sign` 欄位，見第 6 節） |
| 代收回調響應 | 純文字 `SUCCESS` / `FAIL` |
| 代付回調響應 | 純文字 `SUCCESS` / `FAIL` |

---

## 2. 支援的支付方式與幣別

### VND（越南盾）

| 支付類型 | pay_type | 訂單 key | 說明 | 最低 | 最高 |
|---|---|---|---|---|---|
| Bank QR 掃碼 | `7` | `7` | 銀行 QR 掃碼入金 | 200,000 VND | 250,000,000 VND |
| MoMo 電子錢包 | `8` | `8` | MoMo 電子錢包入金 | 50,000 VND | 15,000,000 VND |
| 銀行轉帳 | `9` | `9` | 網銀直連轉帳 | 200,000 VND | 250,000,000 VND |
| 代付（銀行轉帳） | — | — | 越南各大銀行代付 | 200,000 VND | 250,000,000 VND |

> 代收 `pay_type` 直接使用訂單類型的 `key` 欄位（`order->orderType->key`）傳入 API。

---

## 3. 代收 API

### 代收發起 — `POST {DEPOSIT_URL}`

**請求格式**：FORM-DATA

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `uid` | ✅ | string | 商戶 UID（`MERCHANT_UID`） |
| `merchant_num` | ✅ | string | 商戶號（`MERCHANT_NUM`） |
| `merchant_order` | ✅ | string | 商戶訂單號（`order.order_no`，系統內唯一） |
| `coin` | ✅ | decimal | 代收金額（VND，單位：1 = 1 越南盾） |
| `pay_callbackurl` | ✅ | string | 支付完成後跳轉 URL（`returnUrl`） |
| `pay_notifyurl` | ✅ | string | 代收回調 URL（`callbackUrl`） |
| `pay_date` | ✅ | string | 下單時間（格式：`YYYY-MM-DD HH:MM:SS`） |
| `extend` | - | string | 備用欄位（固定傳空字串 `""`） |
| `pay_type` | ✅ | string | 支付類型（`7`=QR / `8`=MoMo / `9`=銀行轉帳） |
| `userinfo` | ✅ | string | 會員帳號（`member.username`） |
| `user_ip` | ✅ | string | 玩家 IP |
| `sign` | ✅ | string | MD5 簽名（見第 6 節） |

**請求範例**

```
uid=12345&merchant_num=MERC001&merchant_order=ORD20260601001&coin=500000&pay_callbackurl=https://example.invalid/return&pay_notifyurl=https://example.invalid/callback/yeepay/deposit&pay_date=2026-06-01+10:00:00&extend=&pay_type=7&userinfo=player001&user_ip=0.0.0.0&sign=ABCD1234EF567890ABCD1234EF567890
```

**成功響應**

```json
{
    "code": 1,
    "payurl": "https://example.invalid/checkout?token=[REDACTED]",
    "message": "success"
}
```

**失敗響應**

```json
{
    "code": 0,
    "message": "Invalid merchant"
}
```

**成功判定**：`code === 1`，否則取 `message` 拋出 `ApiException`。

> `payurl` 需 `urldecode()` 後作為收銀台跳轉 URL。

---

## 4. 代付 API

### 代付發起 — `POST {WITHDRAWAL_URL}`

**請求格式**：FORM-DATA

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `uid` | ✅ | string | 商戶 UID（`MERCHANT_UID`） |
| `merchant_num` | ✅ | string | 商戶號（`MERCHANT_NUM`） |
| `order` | ✅ | string | 商戶代付流水號（`Common::genTransactionNo('YEE')` 生成） |
| `coin` | ✅ | integer | 代付金額（格式：`%.0f`，**不可含小數點**） |
| `userinfo` | ✅ | string | 提款人帳號（`member.username`） |
| `bank_name` | ✅ | string | 收款銀行代碼（YeePay 三方代碼，需透過 `convertPayoutBankCode()` 轉換，見第 7 節） |
| `target_bank` | ✅ | string | 收款銀行帳號（`withdrawal_order.account_no`） |
| `target_bank_user` | ✅ | string | 收款人姓名（`withdrawal_order.account_name`） |
| `extend` | - | string | 備用欄位（固定傳空字串 `""`） |
| `order_date` | ✅ | string | 下單時間（格式：`YYYY-MM-DD HH:MM:SS`） |
| `notifyurl` | ✅ | string | 代付回調 URL |
| `user_ip` | ✅ | string | 使用者 IP |
| `sign` | ✅ | string | MD5 簽名（見第 6 節） |

> ⚠️ **金額限制**：`coin` 欄位使用 `sprintf('%.0f', $amount)` 格式化，**不可包含小數點**，否則 YeePay 會拒絕請求。

**請求範例**

```
uid=12345&merchant_num=MERC001&order=YEE20260601ABC&coin=500000&userinfo=player001&bank_name=970436&target_bank=9876543210&target_bank_user=Nguyen Van A&extend=&order_date=2026-06-01+10:00:00&notifyurl=https://example.invalid/callback/yeepay/withdrawal&user_ip=0.0.0.0&sign=ABCD1234EF567890ABCD1234EF567890
```

**成功響應**

```json
{
    "code": 1,
    "message": "success"
}
```

**失敗響應**

```json
{
    "code": 0,
    "message": "Insufficient balance"
}
```

**成功判定**：`code === 1`，否則調用 `handlePayoutResponseFailure()`。

> 注意：YeePay 代付請求成功時**不返回三方訂單號** `serial_number`，三方訂單號僅在代付回調中提供。

---

## 5. 回調處理

### 5.1 代收回調

**推送方式**：HTTP POST FORM-DATA（由 YeePay 主動推送至 `pay_notifyurl`）

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `code` | ✅ | integer | 狀態碼：`1`=成功（僅此值存在於回調） |
| `merchant_num` | ✅ | string | 商戶號 |
| `coin` | ✅ | string | 代收金額（VND，單位：1 = 1 越南盾） |
| `pay_coin` | ✅ | string | 實際支付金額（可能與下單金額不同） |
| `merchant_order` | ✅ | string | 商戶訂單號（`order_no`，尋單依據） |
| `serial_number` | ✅ | string | YeePay 三方交易號 |
| `order_time` | ✅ | string | 下單時間（格式：`YYYY-MM-DD HH:MM:SS`） |
| `success_time` | ✅ | string | 付款成功時間（格式：`YYYY-MM-DD HH:MM:SS`） |
| `sign` | ✅ | string | MD5 簽名（見第 6 節） |

**成功判定**：**驗簽通過即視為成功，不讀取 `code` 欄位。**

**回調響應**：回傳純文字。

```
SUCCESS   ← 處理成功
FAIL      ← 處理失敗
```

**回調範例**

```json
{
    "code": 1,
    "merchant_num": "MERC001",
    "coin": "500000",
    "pay_coin": "500000",
    "merchant_order": "ORD20260601001",
    "serial_number": "YEE_TXN_123456789",
    "order_time": "2026-06-01 10:00:00",
    "success_time": "2026-06-01 10:05:23",
    "sign": "ABCD1234EF567890ABCD1234EF567890"
}
```

---

> ### ⚠️ 安全性警示：代收回調無失敗分支
>
> **YeePay 代收回調的設計是：只要驗簽通過，系統即無條件將訂單標記為成功（`updateDepositOrderSuccess`），完全不讀取 `code` 欄位的值。**
>
> 這意味著：
> - 若有惡意方偽造簽名通過（例如 API_SECRET 洩露），可在不實際付款的情況下觸發訂單成功。
> - 即使 YeePay 發來 `code=0` 的失敗通知（正常情況 YeePay 應只推送成功），系統仍會視為成功處理。
>
> **現行行為的依據**（來自代碼注釋）：
> ```php
> // YeePay 只有成功才會收到回調
> // 更新訂單為成功（會自動檢查金額是否匹配）
> ```
>
> **維護建議**：若 YeePay 官方確認「回調必為成功」，應在文檔中確認此設計假設；若無官方保證，建議補充 `code === 1` 的明確判斷，以降低誤判風險。

---

### 5.2 代付回調

**推送方式**：HTTP POST FORM-DATA（由 YeePay 主動推送至 `notifyurl`）

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `code` | ✅ | integer | 狀態碼：`1`=成功，`0`=失敗 |
| `merchant_num` | ✅ | string | 商戶號 |
| `coin` | ✅ | string | 代付金額 |
| `merchant_order` | ✅ | string | 商戶代付流水號（`payout_record` 尋單依據） |
| `serial_number` | ✅ | string | YeePay 三方交易號（存入 `third_party_order_no`） |
| `order_time` | ✅ | string | 下單時間（格式：`YYYY-MM-DD HH:MM:SS`） |
| `success_time` | ✅ | string | 處理完成時間（格式：`YYYY-MM-DD HH:MM:SS`） |
| `message` | ✅ | string | 狀態說明文字 |
| `sign` | ✅ | string | MD5 簽名（見第 6 節） |

**成功判定**：`code === 1`（代付回調有明確的成功/失敗分支）。

**回調響應**：回傳純文字。

```
SUCCESS   ← 處理成功
FAIL      ← 處理失敗
```

**回調範例（成功）**

```json
{
    "code": 1,
    "merchant_num": "MERC001",
    "coin": "500000",
    "merchant_order": "YEE20260601ABC",
    "serial_number": "YEE_PAYOUT_987654321",
    "order_time": "2026-06-01 10:00:00",
    "success_time": "2026-06-01 10:15:00",
    "message": "success",
    "sign": "ABCD1234EF567890ABCD1234EF567890"
}
```

**代付冪等性**：系統透過 `findAndLockPayoutRecord(merchant_order)` 查詢代付記錄；若已處理則直接回傳 `SUCCESS`，不重複更新訂單狀態。

---

## 6. 簽名流程

所有請求與回調均使用 **MD5 簽名**，密鑰為 `API_SECRET`，輸出為**大寫 MD5 字串**。

### 簽名步驟

1. 取得請求/回調參數，**排除 `sign` 欄位本身**。
2. **過濾掉值為 `null`、`""`（空字串）、`0`、`"0"` 的欄位**（以 PHP strict 比較判定）。
3. 對剩餘參數的 key 進行 **ASCII 升序排序**（`ksort`）。
4. 拼接為 `key1=value1&key2=value2&...&key=SECRET_KEY` 格式（最後加上 `&key={API_SECRET}`）。
5. 對整串字串進行 `urldecode()` 後計算 **MD5**，結果轉**大寫**。

### PHP 實作參考

```php
protected function generateSign(array $params, string $secret): string
{
    // 排除 sign 欄位
    unset($params['sign']);

    // 過濾值為 0、空字串、null 的欄位（strict 比較）
    $filtered = array_filter($params, fn ($value) => !in_array($value, [null, '', 0, '0'], true));

    // ASCII 升序排序
    ksort($filtered);

    // 拼接為 key=value&... 並附加 &key=SECRET
    $queryString = urldecode(http_build_query($filtered));
    $signString  = $queryString . '&key=' . $secret;

    // MD5 後轉大寫
    return strtoupper(md5($signString));
}
```

### 驗簽（回調端）

```php
protected function checkSign(array $params, string $secret): bool
{
    $sign     = $params['sign'] ?? '';
    $expected = $this->generateSign($params, $secret);

    return hash_equals($expected, $sign);
}
```

> ⚠️ **注意**：過濾步驟採 **PHP strict 比較**（`in_array(..., true)`），數字 `0` 與字串 `"0"` 均會被排除。請確認業務欄位值為正常非零值，否則可能被意外剔除導致簽名不符。

---

## 7. 銀行代碼映射

### VND — 代付 bank_name（YeePay 三方代碼）

代付請求的 `bank_name` 欄位使用 YeePay 定義的**數字銀行代碼**（Interbank Code）。系統透過 `convertPayoutBankCode()` 將內部 `bank_code` 轉換為以下三方代碼後傳入。

| YeePay 代碼 | 銀行名稱 |
|---|---|
| `970416` | Asia Commercial Bank（ACB） |
| `970418` | Bank for Investment and Development of Vietnam（BIDV） |
| `970422` | Military Commercial Joint Stock Bank（MBBank） |
| `970425` | An Binh Commercial Joint Stock Bank（ABBank） |
| `970409` | Bac A Commercial Joint Stock Bank（BacABank） |
| `970438` | Bao Viet Joint Stock Commercial Bank（BaoViet Bank） |
| `970406` | DongA Commercial Joint Stock Bank（DongA Bank） |
| `970431` | Vietnam Export Import Commercial Joint Stock Bank（Eximbank） |
| `970437` | Ho Chi Minh City Development Bank JSC（HDBank） |
| `970452` | Kien Long Commercial Joint Stock Bank（Kienlongbank） |
| `970449` | Lien Viet Post Joint Stock Commercial Bank（LienVietPostBank） |
| `970426` | Maritime Commercial Joint Stock Bank（MSB） |
| `970428` | Nam A Commercial Joint Stock Bank（NamABank） |
| `970448` | Orient Commercial Joint Stock Bank（OCB） |
| `970430` | Petrolimex Joint Stock Commercial Bank（PGBank） |
| `970412` | PVcomBank |
| `970400` | Saigon Bank for Industry and Trade（SaigonBank） |
| `970429` | Saigon Joint Stock Commercial Bank（SCB） |
| `970440` | Southeast Asia Commercial Joint Stock Bank（SeABank） |
| `970443` | Saigon-Hanoi Commercial Joint Stock Bank（SHB） |
| `970424` | Shinhan Bank Vietnam Limited |
| `970407` | Techcombank |
| `970423` | Tien Phong Commercial Joint Stock Bank（TPBank） |
| `970436` | Vietcombank |
| `970441` | Vietnam International Commercial Joint Stock Bank（VIB） |
| `970432` | Vietnam Prosperity Joint Stock Commercial Bank（VPBank） |
| `970415` | VietinBank |
| `970433` | Vietnam Thuong Tin Commercial Joint Stock Bank（Vietbank） |
| `970403` | Sacombank |
| `546034` | CAKE Digital Bank |
| `970405` | Agribank |
| `970446` | Vietnam Co-operative Bank（Co-opBank） |
| `970434` | Indovina Bank Limited |
| `970419` | National Citizen Commercial Joint Stock Bank（NCB） |
| `970414` | Ocean Commercial Joint Stock Bank（OceanBank） |
| `970427` | Viet A Commercial Joint Stock Bank（VietABank） |
| `970457` | Woori Bank Vietnam |
| `970454` | Viet Capital Commercial Joint Stock Bank（BVBank） |
| `970421` | Vietnam-Russia Joint Venture Bank（VRB） |
| `970408` | Global Petro Commercial Joint Stock Bank（GPBank） |
| `422589` | CIMB Bank Vietnam |
| `970442` | Hong Leong Bank Vietnam |
| `970456` | Industrial Bank of Korea — HCM Branch（IBK HCM） |
| `970455` | Industrial Bank of Korea — Hanoi Branch（IBK Hanoi） |
| `970439` | Public Bank Vietnam |
| `970458` | UOB Vietnam |

> ⚠️ **MoMo 已移除**：`MOMO` 代碼已於工單 #3674（2026-03-31）從代付銀行清單移除，不再支援代付至 MoMo 電子錢包。代收仍支援 MoMo（pay_type=8）。

---

## 8. 參考資料

| 資料來源 | 說明 |
|---|---|
| [官方 API 文檔](https://example.invalid/#/apidocument) | YeePay 完整 API 規格（需密碼存取） |
| GitLab #3039 | [GFF][TP] YEEPAY-VND 代收代付配置（費率、限額、回調 IP） |
| GitLab #3040 | [GFF][YES2WIN] YEEPAY-VND 代收代付配置（費率、限額、回調 IP） |
| GitLab #3243 | [GFF][TP][APIs] YEEPAY-VND 代收代付接入主工單 |
| GitLab #3674 | [APIs] 更新 YeePay 代付銀行配置（新增 Woori 等銀行、移除 MoMo） |

### 回調 IP 白名單

YeePay 的回調來源 IP（商戶需加入白名單）：

| 說明 | IP |
|---|---|
| YeePay Callback Server | `0.0.0.0` |

### 費率參考（以工單記錄為基準，各商戶以後台設定為準）

| 支付類型 | 費率 |
|---|---|
| 代收 Bank QR / Transfer | 1.80% |
| 代收 MoMo | 2.40% |
| 代付 | 0%（工單記載） |

### 內部實作路徑

```
app/Services/Payment/Providers/YeePay/
├── Driver.php              # 組合代收/代付子類別，掛載到 YEEPAY config key
├── Deposit.php             # 代收實作，POST {DEPOSIT_URL}，成功判定 code===1
├── DepositCallback.php     # 代收回調處理，驗簽通過即視為成功（無失敗分支）
├── Withdrawal.php          # 代付實作，POST {WITHDRAWAL_URL}，coin 不可含小數點
├── WithdrawalCallback.php  # 代付回調處理，code===1 判定成功
└── YeePayTrait.php         # 共用：HTTP 請求（sendRequest）、MD5 簽名（generateSign）、驗簽（checkSign）
```

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：Codebase 實作、GitLab 工單 #3039、#3040、#3243、#3674；官方 API 文檔（WebFetch 403，需密碼）*
