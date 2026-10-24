# N918（vendor_id=33）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 基本資訊

| 項目 | 值 |
|---|---|
| Vendor ID | 33（`VendorIdEnum::N918`） |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| 繼承類別 | `AbstractTransferWallet` |
| Driver | `app/Vendors/N918/Driver.php` |
| Account Model | `App\Vendors\Models\N918Account` |
| BetRecord Model | `App\Vendors\Models\N918BetRecord` |
| 時區 | GMT+8（文件未明載，依慣例） |
| App 類型 | **App-based**（iOS / Android scheme），**非 Web 瀏覽器** |
| 對帳保留期 | 文件未載明（gap，須向客服確認） |

---

## info 欄位對照

| `vendor_infos.info` 鍵 | 說明 |
|---|---|
| `API_URL` | N918 API Base URL |
| `AGENT_CODE` | 等同文件的 `authcode`，用於簽名 |
| `SECRET_KEY` | 簽名用 secretKey |
| `DOWNLOAD_PAGE` | iOS / Android 下載頁 URL，供 `getGameUrl` 回傳 |

---

## 實作方法對照

| AbstractTransferWallet 方法 | N918 Action | 說明 |
|---|---|---|
| `getBalance()` | `getUserInfo` | 查餘額，回 `score`（非 `money`，見陷阱 2） |
| `transferToVendor()` | `setServerScore`（scoreNum > 0） | 上分，正值 |
| `transferToLocal()` | `setServerScore`（scoreNum < 0） | 下分，負值 |
| `getGameUrl()` | 非 API，Driver 自行組 App scheme | iOS: `lobbykissgame://...` / Android: `lobbykiss://...` |
| `getTransRecord()` | `getOrder` | 查單筆訂單狀態 |

---

## App 啟動方式（getGameUrl）

```
iOS:     lobbykissgame://account={username}&password=[REDACTED]
Android: lobbykiss://lobbykiss?account={username}&password=[REDACTED]
```

`password` 取自 `n918_accounts.password`。不提供 Web 版遊戲 URL。

---

## 成功判斷（最重要陷阱）

N918 的成功碼**不統一**，是本商最高風險點：

| Action | 成功碼 | 說明 |
|---|---|---|
| `addUser`、`editUser`、`disable` 等 | `code: 0` | 多數 API |
| `setServerScore`、`getUserInfo` | `code: 0` | 餘額/轉帳類也是 0（與文件部分範例矛盾，見陷阱 1） |
| `getBalance` / `setServerScore` 文件範例 | `code: 1` | 但實作以 `error` 欄位判斷 |

**Driver 實作使用 `error == '0'` 判斷**，而非 `code`，也非 `success: true`。文件範例均無 `error` 欄位，疑為文件版本落後於正式 API（見 api.md 落差說明）。

---

## 上下分設計（setServerScore）

```
transferToVendor → scoreNum = +{amount}（正值=上分=充值）
transferToLocal  → scoreNum = −{amount}（負值=下分=提現）
```

- 上下分共用同一個 action，以 `scoreNum` 正負區分方向
- Driver 同時在 HTTP header 加 `API: deposit`（上分）/ `API: withdraw`（下分）作標記
- `orderid`：50 字以內、系統唯一；重複傳回 `-1`（訂單存在），具天然冪等保護

---

## 平台陷阱

### 陷阱 1：`success` 欄位不存在於文件

Driver 以 `error == '0'` 判斷成功，文件中沒有 `error` 欄位，也沒有 `score` 欄位（文件用 `money`）。這是文件與正式 API 不同步的問題，以實測正式環境回應為準。

### 陷阱 2：餘額欄位名稱

- 文件範例：餘額欄位為 `money`
- Driver 實作：讀取 `score` 欄位
- `getUserInfo` 的 success response 欄位文件未列出（gap），須以實測為準

### 陷阱 3：`getOrder` / `kick` 簽名可能省略 `userName`

文件對 `getOrder` / `kick` 的簽名公式寫成 `md5((authcode + time + secretKey).tolower)`（**少了 userName**），與其他 API 公式不同。疑為文件筆誤，須向客服確認是否真的不含 userName。

### 陷阱 4：timestamp 位數

- 正式文件：**10 位秒級**（`time()`）
- sample 文件：**13 位毫秒級**
- Driver 採 `Carbon::now()->getTimestamp()`（10 位），與正式一致
- 接新環境前確認位數

---

## 無回調設計

Transfer Wallet 主動呼叫，無第三方回調，故無 `routes/external/sw.php` 中的 N918 controller。

---

## 注意事項

- 所有 API 使用 **GET 方法**，參數以 query string 傳遞
- IP 白名單必須在 N918 後台「API」頁設定
- `orderid` 重複會回 `-1`，具備冪等保護但須注意不要誤判為失敗
- `RandomUserName` API 可取廠商建議的隨機帳號
- 對帳資料保留期未知，須主動向客服詢問
