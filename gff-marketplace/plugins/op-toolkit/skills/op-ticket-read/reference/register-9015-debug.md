# 註冊回「驗證碼錯誤」（9992）排查

`frontend/api/guest/register` 回 9992（驗證碼錯誤）時，真因多半是 `validate_tokens` 找不到符合的紀錄（token 過期）。舊版程式在這裡回 9015（手機號碼無效），看到舊 log 的 9015 也照本頁查。

拋點：`RegisterController` 裡 `ValidateToken:[REDACTED] 那段查詢之後的兩處 `error_message.9992`（行號會變，用搜尋找）。

過濾條件（同一段 `ValidateToken:[REDACTED] 查詢）：

| 欄位 | 條件 |
|---|---|
| `token` | = 請求的 `mobile_token` |
| `status` | = 0（未使用） |
| `expire_date` | >= now()（有效期依站台設定） |

排查 SOP（log + DB 雙線）：

1. GCP log 取 `jsonPayload.payload.response.data.file` 拿到拋錯 file:line（走 `gcp-log-troubleshoot`）
2. 取 `request.params.mobile` 與 `request.params.mobile_token`
3. DB 查（走 `whitelabel-db-query` 對該白標下唯讀 SQL）：`SELECT * FROM validate_tokens WHERE token=[REDACTED]' OR info='<mobile>' ORDER BY id DESC`
4. 比對請求時間 vs `expire_date`，多半已過期

常見成因（依頻率）：

| 成因 | 說明 |
|---|---|
| token 過期（最常見） | 用戶收到 SMS 後拖太久才提交 |
| token 與 mobile 不匹配 | 查到 token 但 `info` 和請求的手機不同，也拋 9992 |
| token 已被使用 | `status != 0` |
