# GitHub Copilot 指引 — target/support

本套件為私有 Composer 函式庫（命名空間 `target\support`），封裝跨服務共用的金流閘道與點數業務邏輯，供多個 Laravel 消費端應用程式透過 `composer require target/support:dev-master` 引入。

---

## 金流閘道開發規則

### 新增閘道必須同時修改兩處

1. **`src/Enums/CashFlowEnum.php`** — 新增 `DEPOSIT_*` 或 `WITHDRAW_*` 整數常數。
2. **`src/Helpers/CashFlows/CashFlowHelper.php`** — 在建構子的 `switch` 區塊新增對應的 `case`。

缺少任一處會導致 `InvalidArgumentException`。

### 閘道類別結構

所有閘道放在 `src/Helpers/CashFlows/`，必須繼承 `CashFlowBase`，並實作兩個方法：

```php
// send() 回傳格式
return ['type' => 1, 'url' => '...'];
// type: 0=失敗, 1=轉址, 2=QRCode, 3=Form POST, 9=前端彈窗(PIN流程)

// callback() 回傳格式
return new CallbackResponse($status, $responseString, $order);
// status: 1=成功上分, 0=失敗
```

### callback() 必須遵守的固定結構

```php
DB::beginTransaction();
try {
    $order = Order::lockForUpdate()->find($id); // 必須鎖行
    // ... 業務邏輯 ...
    $order->status = 1; // 0=待處理, 1=成功, 2=失敗, 4=鎖分模式略過
    $order->save();
    DB::commit();
    return new CallbackResponse(1, 'success', $order);
} catch (\Exception $e) {
    DB::rollBack();
    return new CallbackResponse(0, 'fail', null);
}
```

---

## 關鍵慣例

### 憑證存取
閘道憑證透過 `json_decode($agentCashFlow->info, true)` 取得。常見鍵名：`APIUrl`、`ApiKey`、`ApiPass`、`Merchant`。各閘道所需鍵名以該閘道類別為準。

### 點數運算
**禁止**對浮點數直接使用 `+` / `-`，務必使用 `pointAdd` / `pointSub`（乘以 10000、floor、再除回）。

### 驗證
使用繼承自 `CashFlowBase` 的 `$this->validate($request, $rules)`，失敗時拋出 code `2002` 的 `Exception`。**不可**直接呼叫 `$request->validate()`。

### VendorSend（cURL 封裝）
```php
$vendor = new VendorSend(['json_post' => true]); // POST body 為 JSON
$vendor = new VendorSend();                       // POST body 為 form-urlencoded（預設）
$result = $vendor->send($action, $url, 'POST', $body, $headers);
```

---

## 回調後的上分流程（`CashFlowHelper::callback`）

```
callback 回傳 status == 1
    ├─ member_id > 0（會員訂單）
    │   ├─ event_mode > 0（鎖分模式）→ 只發 Telegram 通知，不上分
    │   └─ 一般 → updateMemberBalance() + 清 Redis 'member' + FirebaseCloudMessage
    └─ member_id == 0（代理訂單）→ updateAgentBalance()
```

---

## 開發工作流程

```bash
# 執行測試
vendor/bin/phpunit

# 程式碼規範檢查（合併前必須全數通過）
vendor/bin/phpcs --standard=psr2 src
vendor/bin/phpmd ./src text codesize,design,naming,unusedcode
```

- 在 `develop` 分支開發；**禁止直接推送至 `master`**。
- 發 Merge Request 至 `master`，需 `@mars` 與 `@rory` 審核。
- 在消費端更新套件時，必須在獨立的 `composer` 分支執行 `composer update`，再合併至目標分支。

---

## 參考檔案

| 檔案 | 用途 |
|---|---|
| `src/Helpers/CashFlows/CashFlowBase.php` | 所有閘道的抽象基底類別 |
| `src/Helpers/CashFlows/CashFlowHelper.php` | 閘道工廠 + 回調後編排邏輯 |
| `src/Enums/CashFlowEnum.php` | 所有閘道的正式數字 ID |
| `src/Helpers/VendorSend.php` | cURL 封裝工具 |
| `src/Models/CallbackResponse.php` | 每個 `callback()` 回傳的 DTO |
| `src/Services/CashFlowService.php` | 上分邏輯與訂單編號產生 |
| `src/Helpers/CashFlows/TngpinPay.php` | PIN 流程（type 9）範例 |
| `src/Helpers/CashFlows/DGPay.php` | 多版本（V1/V2）轉址流程範例 |

