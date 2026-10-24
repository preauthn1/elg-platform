# 二、開發金流商所屬的服務器 Driver

> ← [金流商接入完整操作流程](index.md)

---

## 步驟一：配置金流商設定

### 基本說明

在 `config/payment.php` 中註冊金流商的 Driver 類別，讓系統能夠找到並載入對應的金流供應商。

> config/payment.php
```php
// config/payment.php
'providers' => [
    // ...existing providers...
    
    'EPICPAY' => App\Services\Payment\Providers\EpicPay\Driver::class,
],
```

### 配置說明

| 欄位        | 說明                 |
|-----------|--------------------|
| **Key**   | 金流商代號（大寫，用於系統識別）   |
| **Value** | Driver 類別的完整命名空間路徑 |

**注意事項**：

- ✅ Key 必須使用大寫（例如：`EPICPAY`）
- ✅ Value 必須是完整的命名空間路徑
- ✅ 確保對應的 Driver 類別已存在

---


## 步驟二：建立金流供應商 Driver 並實作 API

### 基本說明

使用 `make:payment` 命令建立金流供應商的基礎檔案架構，然後實作各項業務邏輯。
```bash
php artisan make:payment
```

這時命令會詢問：

1. 金流商英文代號（大駝峰格式，例如：`EpicPay`）
2. 是否建立代付檔案（yes/no）

直接指定名稱

```bash
php artisan make:payment EpicPay
```

#### 相關文件

- 命令文件：`app/Console/Commands/CashFlow/MakePaymentProvider.php`
- 供應商目錄：`app/Services/Payment/Providers/`

### 產生的檔案結構

執行成功後會在 `app/Services/Payment/Providers/EpicPay/` 建立：

```
app/Services/Payment/Providers/EpicPay/
├── Driver.php                 # 主驅動類別（必須）
├── Deposit.php                # 代收處理
├── DepositCallback.php        # 代收回調
├── Withdrawal.php             # 代付處理
├── WithdrawalCallback.php     # 代付回調
└── EpicPayTrait.php           # 共用方法（建議）
```

### 實作業務邏輯

每個供應商需實作以下 5 個 class，各自繼承對應的 Abstract 基底類別並使用共用 Trait。

---

#### `Driver.php`

**角色**：組合所有子類別，作為系統呼叫金流商功能的唯一入口。不含業務邏輯。

**你負責**：
- `__construct(AgentCashFlow $agentCashFlow)`：依供應商支援的功能實例化對應的子類別，賦值給 protected property

**框架已處理**：
- `deposit()`、`depositCallback()`、`withdrawal()`、`withdrawalCallback()` 等 public 方法的分發邏輯
- 未宣告對應 property 時，自動拋出「功能不支援」例外

---

#### `{Name}Trait.php`

所有子類別共用的 Trait，存放 HTTP 請求與簽名相關的 protected 方法。其他供應商專用的輔助方法（如銀行代碼轉換）也一律放在此 Trait 中。

**你負責**：
- `generateSign()`：依照金流商 API 文件定義的演算法產生簽名，供代收、代付請求與回調驗章時共用

---

#### `Deposit.php`

**角色**：接收系統的代收請求，呼叫金流商 API 建立付款，回傳供用戶跳轉的支付連結。

**你負責**：
- `handle(DepositRequestData $request): DepositResponseData`
  - 依 API 文件組裝請求參數（會員資訊、金額、訂單號、回調 URL 等）
  - 判斷回應是否成功（依該金流商的狀態欄位與成功值定義）
  - 回傳 `DepositResponseData`，指定回應類型（URL 跳轉或表單提交）與支付連結

**框架已處理**：
- 訂單的建立（`$request->order` 已存在，`handle()` 中不可寫入訂單）
- 路由與 Controller 的串接

---

#### `DepositCallback.php`

**角色**：接收金流商主動發來的付款結果通知，驗證後更新訂單狀態。

**你負責**：
- `handle(Request $request): DepositCallbackResponseData`
  - 驗證回調欄位格式（`$request->validate`）
  - 驗證簽名，確認通知來自真實的金流商
  - 判斷哪個欄位、哪個值代表「成功」或「失敗」
- 宣告 `$notifySuccessText` / `$notifyFailText`（成功／失敗時回傳給金流商的字串）

**框架已處理**：
- DB 交易管理
- 訂單的查詢與鎖定（防競態）
- 冪等保護（重複通知不重複處理）
- BotNotify 錯誤通知

##### 代收訂單狀態流轉

代收訂單使用 `OrderStatusEnum`。注意「回調終態」（callback 不能再改）與「業務終態」（訂單生命週期完結）是**兩個維度**：

| 值 | case | 回調可否修改 | 業務終態？ | 說明 |
|---|---|---|---|---|
| **0** | **`CREATED`** | **可（唯一可修改狀態）** | 否 | 訂單建立後預設狀態，等待回調 |
| 1 | `SUCCESS` | 否 | **否（中間狀態）** | 回調已驗證但**尚未上分**，等待 `PaymentService` 推進到 `DEPOSIT_FINISHED` |
| 2 | `FAILED` | 否 | 是 | 建立失敗或審核不通過 |
| **3** | **`DEPOSIT_FINISHED`** | 否 | **是（最終「已上分」狀態）** | 三方回調成功且已實際上分到會員/代理帳戶 |
| 4 | `WAITING_MANUAL_DEPOSIT` | 否 | 是 | 三方回調成功但金額不符，等待人工確認 |

> **「已上分」=`DEPOSIT_FINISHED (3)`，不是 `SUCCESS (1)`**。
> `updateDepositOrderSuccess()` 只把訂單推進到 `SUCCESS (1)`（回調驗證完成的中間狀態），
> 後續由 `PaymentService::UpdateMemberBalance / UpdateAgentBalance` 實際入帳並轉為 `DEPOSIT_FINISHED (3)`。
> Method 命名雖含 `Success` 但語意是「**回調**處理成功」，不代表業務「**上分**完成」。

**回調終態規則**：`DepositCallbackTrait::checkDepositOrderStatus()` 只允許 `CREATED (0)` 接受回調修改，
其他狀態一律拋 `DepositOrderException(code=200)`，由 `handleDepositOrderException()` 統一轉換為
回傳給金流商的**冪等成功響應**，達到「重複通知不重複處理」的保護。

---

#### `Withdrawal.php`

**角色**：接收系統的出款請求，組裝參數後呼叫金流商 API 發起代付。

**你負責**：
- `handle(WithdrawalRequestData $request): WithdrawalResponseData`
  - **於 `handle()` 開頭呼叫 `$this->validatePayoutOrder($request->order)`**，驗證訂單狀態是否為 21（代付提交中）；失敗時 `createFailureResponse()` 回傳
  - 依 API 文件組裝請求參數（銀行代碼、帳號、姓名、金額等格式）
  - 判斷回應是否成功（依該金流商的狀態欄位與成功值定義）
- 宣告 `$orderPrefix`（流水號前綴，各供應商不同）

**框架已處理（Trait 提供工具，由你呼叫）**：
- `validatePayoutOrder()` / `getWithdrawalOrder()`：訂單合法性驗證
- `Common::genTransactionNo($prefix)`：流水號產生
- `handlePayoutRequestException()` / `handlePayoutResponseFailure()`：HTTP 異常與 API 失敗的訂單記錄
- `handlePayoutSuccess()`：成功時建立 PayoutRecord 並回傳 `WithdrawalResponseData`

---

#### `WithdrawalCallback.php`

**角色**：接收金流商主動發來的出款結果通知，驗證後更新代付訂單狀態。

**你負責**：
- `handle(Request $request): WithdrawalCallbackResponseData`
  - 驗證回調欄位格式（`$request->validate`）
  - 驗證簽名，確認通知來自真實的金流商
  - 判斷哪個欄位、哪個值代表「成功」或「失敗」
- 宣告 `$notifySuccessText` / `$notifyFailText`（成功／失敗時回傳給金流商的字串）

**框架已處理**：
- DB 交易管理
- PayoutRecord 與訂單的查詢與鎖定（防競態）
- 冪等保護（重複通知不重複處理）
- 金額匹配驗證
- 代付記錄與訂單狀態更新
- BotNotify 錯誤通知

##### 代付訂單狀態與回調放行規則

代付訂單使用 `WithdrawalOrderStatusEnum`。下表節錄與代付相關的狀態，「回調」欄是三方回調抵達時框架怎麼處理（`WithdrawalPayoutTrait::checkWithdrawalOrderStatus()`）：

| 值 | case | 意思 | 回調 | 可否重新發起代付 |
|---|---|---|---|---|
| 21 | `PAYMENT_SUBMITTED` | 已送出，結果還不知道 | 放行，回調就是定案依據 | 不行，鎖住等回調或人工查證 |
| 22 | `PAYMENT_PROCESSING` | 金流商已受理，等回調 | 放行 | 不行 |
| 24 | `THIRD_PARTY_SUCCESS` | 金流商付款成功（終態） | 拒絕（400）並告警 | 不行 |
| 29 | `THIRD_PARTY_FAILED` | 金流商明確拒絕 | **拒絕（400）並告警** | **可以，29 不是終態** |
| 4 | `TRANSFERRED` | 後台人工確認已轉帳 | 拒絕（400）並告警 | 不行 |

**規則**：只有 21、22 的訂單接受回調，其餘狀態一律拒絕並發 BotNotify 告警。

**29 為什麼拒絕回調、卻可以重新發起**：29 代表「確定沒付出去」，所以營運可以從後台再送一次代付。正因為可能已經重送，舊請求的補發回調就不能再放行，否則新舊兩筆會交錯更新同一張訂單。29 收到的若是「成功」回調，代表訂單可能被誤標成 29，重新發起就會重複出款，告警會要求人工立即核對三方實際出款結果。

**判斷「這筆回調處理過沒」看的是代付紀錄本身，不是訂單狀態**（`WithdrawalCallbackTrait::isPayoutRecordProcessed()`）：

```php
// 只有該筆 payout record 自己是 SUCCESS 才視為已處理
return $payoutRecord->is_success_payout === WithdrawalPayoutStatusEnum::SUCCESS;
```

一張訂單可能有多筆代付紀錄（重新發起過）。若以訂單狀態 24 判斷，其餘紀錄的成功回調會被當成「已處理」靜默吞掉，等於丟失另一筆真實出款的唯一通知。

> 送出代付後怎麼判 21 還是 29：只有金流商**明確回覆拒絕**才能寫 29；逾時、HTTP 錯誤、看不懂的回應一律留在 21。把不確定的結果寫成 29，就等於允許同一筆錢付第二次。常見寫錯的樣態見 [附錄-常見缺陷類型](附錄-常見缺陷類型.md)。

---

## 章節檢查清單

- [ ] **步驟一**：在 `config/payment.php` 註冊 Driver 類別
    - [ ] Key 為大寫，Value 為完整的命名空間路徑
- [ ] **步驟二**：執行 `php artisan make:payment` 建立架構
    - [ ] 建立的 5-6 個類別檔案均存在於正確目錄
- [ ] **實作業務邏輯**：
    - [ ] `{Name}Trait`：已實作 API 所需的簽名 (sign) 演算法
    - [ ] `Deposit`：已組裝正確請求參數並能回傳支付連結 (URL/Form)
    - [ ] `DepositCallback`：已實作回調驗章、狀態判斷與金額驗證
    - [ ] `Withdrawal`：已實作代付請求並宣告正確的 `$orderPrefix`（如需要）
    - [ ] `WithdrawalCallback`：已實作代付回調邏輯與狀態變更（如需要）
- [ ] **回調響應**：回傳給金流商的字串（如 `OK` 或 `success`），須符合 API 規範

