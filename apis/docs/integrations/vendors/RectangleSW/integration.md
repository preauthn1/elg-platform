# RectangleSW（vendor_id=234）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；本檔聚焦 RectangleSW 單錢包接入差異。
> 官方 API 規格見同目錄 `api.md`（*Rectangle Games Integration Document* v1.24）。

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 234 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::RECTANGLESW`（[VendorIdEnum.php:241](../../../../app/Enums/Vendor/VendorIdEnum.php#L241)） |
| 架構 | Single Wallet（單一錢包） |
| 官方規格 | `docs/integrations/vendors/RectangleSW/api.md`（v1.24） |
| Driver | `app/Vendors/RectangleSW/Driver.php` |
| 主要 Controller | `app/Http/Controllers/External/SingleWallets/RectangleSwController.php` |
| 路由 | `routes/external/sw.php`（`rectangle` prefix） |
| 資料表 | `rectanglesw_accounts`、`rectanglesw_bet_records` |
| 整合測試 | 目前**無**（TODO：補 Feature test） |

## 路由

| 方法 | 路徑 | Controller Action |
|---|---|---|
| `POST` | `rectangle/api/v1/authenticate` | `authenticate()` |
| `GET` | `rectangle/api/v1/wallets` | `wallets()` |
| `POST` | `rectangle/api/v1/transactions` | `transactions()` |

## vendor_info.info 設定欄位

`Driver::VendorInfoDetailData`：

| 欄位 | 說明 |
|---|---|
| `API_URL` | Rectangle 遊戲清單 API 的 base URL |
| `OC` | Operator Code，同時作為 `vendorInfoOperatorField`（部分廠商 IP 白名單／帳號綁定會用此欄位識別站台） |
| `SECRET` | HMAC 簽名用密鑰 |
| `CURRENCY` | 幣別代碼 |

## 簽名驗證

`RectangleSwController::isSignatureValid()`：

- 解析 `Authorization` header，須包含 `RG-HMAC-SHA256` scheme 與 `Signature=<hex>`
- 簽名字串組成：`Host header . X-Content-SHA256 header . X-Date header`，以 `SECRET` 做 HMAC-SHA256——與官方規格（`api.md` §4 Hash Authentication）的 `HMAC-SHA256(pre-shared key, Host + X-Content-SHA256 + X-Date)` 一致
- `example.invalid['SECRET']` 為空字串時**略過驗簽直接放行**——僅適用於測試環境，正式站台務必確保 `SECRET` 已設定
- Hash Authentication 官方文件標註為**選用**（optional），實際安全防線以 IP 白名單為主（`api.md` §3.1）；本專案兩者皆有實作（`Common::verifyIPWhitelistByVendorId()` + 簽名驗證）

## PayoutTransactionV1 是唯一的交易端點

不同於 PP 等廠商將 Bet / Result / Refund 拆成獨立方法，Rectangle 把投注與派彩合併在同一個 `POST /api/v1/transactions`：

- `betAmount` / `winAmount` 同時攜帶，`win_lose` 由程式計算（`winAmount - betAmount`）
- `isEndRound` 表示該局是否結束，寫入 `rectanglesw_bet_records.is_end_round`
- 官方規格（`api.md` §8）確認 **PayoutTransactionV1 是唯一交易端點**，無獨立的 Refund / Result API；投注與派彩合併處理屬官方設計，非本專案遺漏

### Idempotency-Key header 未讀取（與官方規格的差異）

官方規格要求運營商依請求的 `Idempotency-Key` header 判斷重複請求（見 `api.md` §8「重試規則」），但目前 `transactions()` 只以 body 內 `transactionId` 做冪等判斷、未讀取該 header。實務上兩者通常一致（同一筆交易重試時 `transactionId` 不變），功能上等價；若未來 Rectangle Games 重試行為改為沿用同一 `Idempotency-Key` 但攜帶不同 `transactionId`，現有實作會誤判為新交易。TODO：視風險評估是否需改為讀取 header。

## 冪等性

- `rectanglesw_bet_records.reference_no`（= 官方 `transactionId`）有 **DB unique constraint**
- 進處理流程前先 `where('reference_no', $transactionId)->exists()` 查詢一次，命中則直接回傳當前餘額
- 高併發下即使查詢時未命中，寫入仍可能因 unique constraint 衝突（MySQL error `1062`），`transactions()` 有 catch 此情況並同樣回傳當前餘額（而非報錯）——雙重防護，避免競態下重複扣款

## 幣別限制

`transactions()` 的 validator 目前寫死 `currency` 僅接受 `MYR`（`'currency' => 'required|string|in:MYR'`）。若要開放其他幣別站台使用本廠商，需同步放寬此驗證規則。

## 遊戲清單同步（`ShouldSyncGameList`）

- `getGameList()` 呼叫 `GET {API_URL}/api/v1/available-games?oc={OC}`
- 遊戲狀態對照：官方 `ACTIVE` → 本地 `status = 1`，其餘 → `0`
- 遊戲 URL 存於 `example.invalid`（JSON，`{"url": "..."}`），`getGameUrl()` 由此讀出並組 query string（`oc` / `t` session token / `l` language / `r` exitUrl）

## 語言對照

`Driver::getLanguage()`：

| `LanguageEnum` | Rectangle 語言碼 |
|---|---|
| `ZH_CN` | `zh` |
| `ZH_TW` | `tzh` |
| `TH_TH` | `th` |
| `ID_ID` | `id` |
| 其他 | `en`（預設） |

## 錯誤碼回傳規則

| 情境 | 回傳 code |
|---|---|
| IP 不在白名單 | `2000` |
| token 無效 / 帳號停用 | `1200` |
| 幣別不合法 | `1601` |
| 缺少必填參數 | `1800` |
| 簽名驗證失敗 | `2000` |
| 餘額不足 | `1602` |
| DB 例外 / 未預期例外 | `2000` |

> 已對照官方規格（`api.md` §13）核實：以上皆為 Rectangle Games 官方定義的錯誤碼（非本專案自訂）。
