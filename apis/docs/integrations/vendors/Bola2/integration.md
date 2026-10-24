# Bola2（vendor_id=45）
> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| Vendor ID | `45`（`VendorIdEnum::BOLA`） |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| 繼承類別 | `AbstractTransferWallet` |
| 請求方式 | `POST` JSON（`Update User Profile` 例外：`PUT`） |
| 簽名 | `MD5(AgentName + Random + API Key)` → `HashKey` 欄位 |
| 成功判定 | 一般 API：`Status === 200`；轉帳（deposit/withdraw）：`Status === 201` |
| 金額精度 | `Common::numFloor(value, 4)`（小數 4 位無條件捨去） |
| 時區 | 文件未明述；`TransactionDate` 等報表時間以 Bola2 伺服器時區為準，待確認 |
| IP 白名單 | 文件未提及；驗證僅靠 `HashKey` 簽名 |
| Driver | `app/Vendors/Bola2/Driver.php` |
| Callback | 無（轉帳錢包，無 seamless callback） |

---

## 高風險陷阱

### 1. `Status 201` 是轉帳成功碼，不是錯誤

Bola2 的 Deposit / Withdraw 成功回應為 **`Status 201`**（Created），`Message` 欄位為交易 ID，**不是** HTTP 4xx 錯誤。一般 API（Login、Signup、Report）成功為 `Status 200`。

實作以 `Status === 201` 判定 `SUCCESS`，其餘回傳 `FAILED` / `TIMEOUT`（`VendorTransferResultEnum`）。

> 風險：若把 201 誤認為錯誤碼，每次轉帳都會判定失敗，導致玩家無法進入遊戲。

### 2. `getbalance` URL 全小寫，大小寫敏感

文件 endpoint 為 `.../user/getbalance/{username}`（全小寫），但現行實作呼叫的是 `user/getBalance/{username}`（駝峰 `B`）。若 Bola2 後端路由大小寫敏感，會回 `404`。

> 現狀：實作使用 `getBalance`，需向 Bola2 客服確認伺服器是否有做大小寫不敏感路由對映。

### 3. `Update User Profile` 使用 HTTP `PUT`

此端點（`.../user/update/{username}`）為 `PUT` method，與其他 API 的 `POST` 不同。若統一用 `POST` 會 `405 Method Not Allowed`。

---

## 轉帳流程

```
玩家進入遊戲
   │
   ▼
（首次）Signup 建立 Bola2 帳號  ── POST /user/signup
   │
   ▼
Deposit 轉入餘額                ── POST /transfer/deposit/{username}  → Status 201
   │
   ▼
Login 取得遊戲 URL              ── POST /user/login                   → Message = {URL}
   │
   ▼
玩家於 Bola2 端下注 / 派彩
   │
   ▼
Withdraw 轉回餘額               ── POST /transfer/withdraw/{username} → Status 201
   │
   ▼
Transaction Report 拉注單對帳
```

### Driver 方法對應

| Driver 方法 | Endpoint | 成功判定 |
|---|---|---|
| `getGameUrl()` | `POST /user/login` | `Status === 200`，遊戲 URL 在 `Message` |
| `getBalance()` | `POST /user/getbalance/{username}` | `Status === 200`，餘額在 `Message`（字串） |
| `transferToVendor()` | `POST /transfer/deposit/{username}` | `Status === 201` → `SUCCESS`；其他 → `FAILED`/`TIMEOUT` |
| `transferToLocal()` | `POST /transfer/withdraw/{username}` | `Status === 201` → `SUCCESS`；其他 → `FAILED`/`TIMEOUT` |

---

## 冪等性

Bola2 為轉帳錢包，**無 callback**，無需實作 callback 冪等性。

轉帳（Deposit / Withdraw）回傳的 `TransactionId`（在 `Message` 欄位）為三方交易識別碼。文件未明述重複送相同 `Amount` 時的行為——建議儲存 `TransactionId`，若需確認轉帳是否重複可用 `Transaction Report` 比對。

---

## 整合落地建議

### vendor_infos 設定對應

| Bola2 概念 | vendor_infos 欄位 | 說明 |
|---|---|---|
| Base URL | `info.API_URL` | Driver 建構時以 `trim` 去除尾端斜線 |
| 代理帳號 | `info.AGENT_NAME` | 每次請求的 `AgentName` 欄位 |
| API 簽名金鑰 | `info.API_KEY` | 僅參與 HashKey 計算，不直接傳輸 |

### 模型與路徑

| 項目 | 路徑 |
|---|---|
| Driver | `app/Vendors/Bola2/Driver.php` |
| 玩家帳號模型 | `app/Vendors/Models/Bola2Account.php` |
| 注單模型 | `app/Vendors/Models/Bola2BetRecord.php` |
| config 註冊 | `config/vendor.php`：`VendorIdEnum::BOLA->value => Vendors\Bola2\Driver::class` |
| Callback 路由 | **無**（`routes/external/` 無 Bola2 條目） |

### 玩家密碼

玩家密碼不存 DB，由 `BaseAbstractVendor::getDefaultPassword(username, created_at)` 動態產生。

### 金額正規化

```php
// getBalance() 回傳的 Message 為字串金額，需正規化
Common::numFloor($response['Message'], 4)
```

---

## Gaps / 待確認

| 項目 | 狀態 | 說明 |
|---|---|---|
| `getbalance` vs `getBalance` URL 大小寫 | 待客服確認 | 文件全小寫，實作駝峰；若 404 需修 Driver |
| 正式 Base URL | 待客服提供 | 文件以 `.../` 占位，存於 `vendor_infos.info.API_URL` |
| 轉帳失敗業務細分碼 | 無 | 僅有 200/201/400/403，餘額不足等情境無細分碼 |
| `StatusDescr` / `Result` 完整對照表 | 無官方資料 | 報表欄位 `Result`（`"2"` / `"3"` 等）無官方說明，對帳以 `Stake`/`Payout` 數值為準 |
| 對帳資料保留期 | 待客服確認 | 文件未說明 Transaction Report 可回溯多久 |
| `Signup` 是否由 Driver 呼叫 | 待確認 | 現行 Driver 未實作 Signup；玩家建立流程待釐清 |
| IP 白名單規範 | 文件未提及 | 需向 Bola2 確認是否有來源 IP 限制 |
