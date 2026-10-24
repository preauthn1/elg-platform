# 金流商測試規範

> 為金流商 Driver 撰寫單元測試的規範與範例。`payment-integration` skill 載入後同時生效。

## 測試目錄

```
tests/Unit/Services/Payment/Providers/{ProviderName}/
├── DepositTest.php
├── DepositCallbackTest.php
├── WithdrawalTest.php
└── WithdrawalCallbackTest.php
```

## 完整參考範例

- 代收 + 代付 + 回調完整範例：`tests/Unit/Services/Payment/Providers/TopPay/`
- 含 `Http::fake` 範例：`tests/Unit/Services/Payment/Providers/YeePay/`

## Http::fake 標準格式

```php
Http::fake([
    '*' => Http::response([
        'code'    => 0,
        'message' => 'success',
        'data'    => ['order_no' => 'TEST123', 'pay_url' => 'https://example.invalid'],
    ], 200),
]);
```

## 測試覆蓋要求

每個金流商必須覆蓋以下情境：

### 代收（`DepositTest`）

- [ ] 正常下單，回傳正確的 `DepositResult`
- [ ] 第三方回傳錯誤代碼，拋出 `ApiException`
- [ ] 網路逾時，拋出 `ApiException`

### 代收回調（`DepositCallbackTest`）

- [ ] 正常成功回調，訂單狀態更新為成功
- [ ] 簽名驗證失敗，回傳錯誤響應
- [ ] 重複回調（冪等性），正確回傳 200 不重複處理
- [ ] 金額不符，訂單標記為失敗

### 回調防線與往返紀錄（代收代付共用，SKILL.md §6.0／§6.1／§12.1）

- [ ] 來源 IP 不在 `cash_flows.whitelist` → 回該家約定的失敗文本、**訂單完全不動**、往返紀錄 `result=failed:ip_not_whitelisted`
- [ ] 白名單為空 → 同樣拒絕（fail-closed），不是放行
- [ ] 訂單／payout record 屬於別的 `agent_cash_flow` → 找單回 null、往返紀錄 `result=failed:another_channel`
- [ ] 被擋下那筆沒有 `orderable_id`，但 `order_no`／三方單號仍對得回本單
- [ ] 往返紀錄不含憑證：斷言回應與紀錄裡看不到商戶金鑰字串
- [ ] 各判定點的 `result` 互不相同（查無訂單／已處理過／未終態），不可全落在 `failed:exception`
- [ ] 三方以非 2xx 帶業務錯誤碼時 → `result` 記三方的碼；body 解不出業務信封（502 HTML、空 body）才記 `failed:exception`

### 代付（`WithdrawalTest`）

- [ ] 正常提交代付，建立 `PayoutRecord`
- [ ] 白名單內的明確拒絕碼 → payout 記錄 `THIRD_PARTY_REJECTED`、訂單轉 29
- [ ] 白名單外業務碼、歧義碼（如訂單號重複）、2xx 解不出業務碼、連線逾時 → 拋例外、訂單停 21
- [ ] 前置步驟（login / token）失敗 → 回 `createFailureResponse()`、不建 payout 記錄

### 代付回調（`WithdrawalCallbackTest`）

三態慣例（SKILL.md §8）每家必測三案例＋冪等：

- [ ] 明確成功回調，訂單更新為 24
- [ ] 明確失敗回調，訂單更新為 29
- [ ] 不明確／未知碼回調（INDETERMINATE），訂單**維持原狀態（21 或 22）**、不寫任何 DB、仍回 ack
- [ ] 重複回調，冪等性防護

## 強制要求

- 所有測試檔案首行：`declare(strict_types=1);`
- 使用 `Http::fake()` 模擬所有外部請求，**禁止**真實 HTTP 呼叫
- 使用 `Factory` 建立測試資料，禁止硬編碼 ID
- 測試方法命名：`test_{場景}_{預期結果}()`，例：`test_deposit_returns_redirect_url()`
- 使用 `$this->assertSame()` 而非 `assertEquals()` 進行嚴格比對

## 執行測試

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan test tests/Unit/Services/Payment/Providers/{ProviderName}/"
```
