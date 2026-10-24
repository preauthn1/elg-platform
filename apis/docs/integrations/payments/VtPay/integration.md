# VtPay

> 對接心得。通用接入規範見 target-apis plugin 的 `payment-integration` skill（方法論已遷市集）；廠商規格見 `docs/integrations/payments/VtPay/api.md`。

## 速查表

| 項目 | 值 |
|---|---|
| `cash_flow_id` | 依 `cash-flow:list` 查詢 |
| 範圍 | 代收 + 代付 |
| 加密 | AES-128-CBC，需隨機 IV |
| 必要 `agent_cash_flow.info` | `API_URL`、`API_SECRET`（Base64） |

## AES 加解密注意事項

- `API_SECRET` 為 **Base64 編碼**字串，使用前必須 `base64_decode()`，解碼後長度為 16 bytes
- IV 為**隨機 16 bytes**，每次加密重新產生（`random_bytes(16)`）
- 加密 payload 結構：`{"iv": base64(iv), "value": encrypted}` 再 Base64 包一層

```php
// 加密
$key = base64_decode($secret);
$iv = random_bytes(16);
$encrypted = openssl_encrypt($jsonData, 'AES-128-CBC', $key, 0, $iv);
$payload = json_encode(['iv' => base64_encode($iv), 'value' => $encrypted], JSON_UNESCAPED_SLASHES);
$encryptedBase64 = base64_encode($payload);
```

## 金額處理

- 金額比較一律 `Common::numCompare()`，禁止原生浮點數比較
- 代收回調 `paid_amount` 為實際到帳金額，需與訂單金額匹配（手續費除外）

## 訂單狀態與冪等性

- 查詢訂單必須 `lockForUpdate()`，避免併發重複處理
- 已處理訂單以 `findAndLockDepositOrder()` + `checkDepositOrderStatus()` 流程攔截
- 訂單狀態：`3 = 成功`、`5 = 失敗`；回調回應需加密 `{"result": "success"}`

## 錯誤訊息格式

統一格式：`VtPay {operation} error: {message}`，例如：

```php
throw new ApiException("VtPay deposit error: {$response['msg']}");
```

API 錯誤需 log 完整 request + response context。

## 常見坑

| 症狀 | 原因 | 處理 |
|---|---|---|
| `openssl_encrypt` 回 false | `API_SECRET` 未 Base64 解碼 / 長度非 16 bytes | 確認密鑰格式並驗證解碼後長度 |
| 收不到回調 | `notify_url` 未公開、防火牆 / WAF 攔截 | 檢查伺服器 access log 是否有請求 |
| 回調金額不匹配 | 手續費扣除、幣別換算 | 用 `Common::numCompare()` 與訂單金額比對；必要時聯繫廠商 |
