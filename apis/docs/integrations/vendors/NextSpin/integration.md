# NextSpin（vendor_id=48）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 基本資訊

| 項目 | 值 |
|---|---|
| Vendor ID | `VendorIdEnum::NS = 48` |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| 遊戲類型 | 老虎機 / 電子遊藝（Slot、Table、Arcade、Bingo） |
| 回調方向 | NextSpin → EC（authorize callback，tw.php 路由） |
| 是否有 SW callback | 否 |

---

## 落地路徑

| 項目 | 路徑 |
|---|---|
| Driver | `app/Vendors/NextSpin/Driver.php`（`AbstractTransferWallet`） |
| TW Callback Controller | `app/Http/Controllers/External/TransferWallets/NextSpinController.php` |
| 帳號 Model | `app/Vendors/Models/NsAccount.php` |
| 注單 Model | `app/Vendors/Models/NsBetRecord.php` |
| Error Code Enum | `app/Vendors/NextSpin/ErrorCodeEnum.php` |
| 路由（TW callback） | `routes/external/tw.php` |
| Vendor ID Enum | `VendorIdEnum::NS` |

### 路由對應

| Method | Path | Controller | 說明 |
|---|---|---|---|
| POST | `/nextspin/authorize` | `NextSpinController@authorize` | 登入驗證（NextSpin → EC） |

---

## vendor_infos.info 欄位

| 欄位 | 說明 |
|---|---|
| `API_URL` | NextSpin API base URL |
| `SECRET_KEY` | 共享密鑰（betDetail token 計算用） |
| `ACCESS_TOKEN` | MemberAccessToken（遊戲啟動時帶入 URL，NextSpin 用來驗 authorize callback） |
| `GAME_URL` | 遊戲啟動 URL 前綴 |
| `Merchant_Code` | 商戶代碼（`merchantCode` 請求參數） |
| `CURRENCY` | 幣別 |

---

## 驗證機制

- **Bearer Token**：所有對 NextSpin 的請求均在 Header 帶 `Authorization: Bearer {MemberAccessToken}`
- **Header API 識別**：Header `API: <operation>`（如 `API: deposit`）——用於告訴 NextSpin 呼叫哪個介面
- **可選 Digest**：部分 API 帶 `Header Digest: MD5(requestBody)` 驗證 body 完整性
- **serialNo 格式**：`{timestamp_ms}{rand(10,99)}`（13 位 Unix 毫秒時間戳 + 2 位隨機數）
- **betDetail 特殊 token**：`MD5(ticketId + "|" + secretKey)`——與主 Bearer Token 不同，每次查 betDetail 須重算

---

## authorize callback（NextSpin → EC）

NextSpin 在玩家進入遊戲時主動呼叫 EC，驗證 token 並取得玩家餘額。

| 欄位 | 說明 |
|---|---|
| `balance` | **固定回傳 `0`**——Transfer Wallet 模式，餘額在 NextSpin 端，EC 不回傳真實餘額 |
| `token` | MemberAccessToken，NextSpin 用來驗證玩家身份 |

> 回傳 `balance: 0` 是正確設計，不是 bug。

---

## 架構特點：NextSpinController 被 SpadeGaming 繼承

`NextSpinController` 同時作為 `SpadeGamingController` 的父類別（兩者 authorize callback 協議格式相似）。

**影響**：修改 NextSpinController 時，必須同時評估對 SpadeGaming 的影響。

---

## BetRecord 對帳流程

| 操作 | API | 說明 |
|---|---|---|
| transferToVendor | `deposit` | Header `API: deposit`，轉入 NextSpin |
| transferToLocal | `withdraw` | Header `API: withdraw`，轉回本地 |
| 注單查詢 | `getBetHistory` + `betDetail` | 對帳拉單；betDetail token 須重算 |

- 對帳保留期：**文件未載明**，需向 NextSpin 客服確認

---

## 成功判斷

| 條件 | 說明 |
|---|---|
| HTTP 200 + `statusCode: "00"` | 主要成功判斷 |
| HTTP 200 + `status: 1` | 部分 API 採用 |

---

## 高風險陷阱

### 1. Header `API: <operation>` 不可遺漏

NextSpin 依此 Header 路由到對應處理器。**若遺漏，服務端無法辨識 API 類型，請求會失敗**，且錯誤訊息可能不明確。

### 2. authorize callback `balance: 0` 是設計非 bug

Transfer Wallet 模式下，本平台不持有 NextSpin 側餘額，authorize callback 固定回 `0`。若改回真實餘額，NextSpin 可能產生帳目混亂。

### 3. betDetail token 與 Bearer Token 不同

| Token 類型 | 計算方式 | 用途 |
|---|---|---|
| Bearer Token（MemberAccessToken） | 由 `vendor_infos.info.ACCESS_TOKEN` 取得 | 所有 API Header `Authorization` |
| betDetail token | `MD5(ticketId + "\|" + secretKey)` | 僅用於 betDetail API 的 `token` 參數 |

### 4. SpadeGaming 共用 NextSpinController

修改 NextSpin authorize 邏輯前，確認 SpadeGaming 的 callback 格式是否兼容，避免連帶影響。

---

## IP 白名單

- 需向 NextSpin 提供 EC 伺服器對外 IP，加入 NextSpin 端白名單
- EC 端收到 callback 時需以 `Common::verifyIPWhitelistByVendorId` 驗證來源 IP
