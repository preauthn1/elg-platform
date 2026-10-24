# Bigpayz (GlobePay365)

> 對接心得。通用接入規範見 target-apis plugin 的 `payment-integration` skill（方法論已遷市集）；廠商規格見 `docs/integrations/payments/GlobePay/api.md`。

## 速查表

| 項目 | 值 |
|---|---|
| 範圍 | 代收 + 代付 |
| 加密 | AES-256-CBC（PBKDF2 衍生 256 位密鑰）+ RSA SHA-1 簽名 |
| 請求格式 | JSON（不是 form-urlencoded） |
| 必要 `agent_cash_flow.info` | `API_URL`、`MerchantCode`、`AES_KEY`、`MerchantPrivateKey`、`GlobePay365PublicKey`、`Currency` |
| 成功狀態碼 | `Status === 6`（注意：多數 provider 為 `0`） |

## 加解密與簽名特性

- **AES key 衍生**：`AES_KEY` 不直接當密鑰，須先 `hash_pbkdf2('sha1', $aesKey, $aesKey, 1000, 32, true)` 衍生為 256 位（同 key 同 salt 是廠商規格）
- **IV 處理**：隨機 16 bytes，加密後**前置在密文前**（不是分開傳），整體 base64
- **簽名**：商戶用私鑰對加密後的 Data 字串簽 RSA SHA-1，base64 後傳；回調用廠商公鑰驗

## 狀態碼陷阱

回調狀態碼 6 才是成功，**勿沿用 EpicPay / 其他 provider 的 `0=success` 慣例**：

| Status | 含義（代收） | 含義（代付） |
|---|---|---|
| 6 | Success | Success |
| 7 | Failed | Failed |
| 8 | Cancelled | Rejected |

## 必填欄位陷阱

| 場景 | 必填欄位 |
|---|---|
| 代收 BMR（網銀） | `SourceClientBankCode` |
| 代付 INR（印度盧比） | `DestinationClientBranchCode`（IFSCode） |

漏填會被廠商拒單。

## 實作位置

`app/Services/Payment/Providers/GlobePay/` — 採用 EpicPay 標準的 Driver + Deposit + DepositCallback + Withdrawal + WithdrawalCallback + Trait 結構。
