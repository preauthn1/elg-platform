# 設定、共用工具與帳號綁定

> 這份管三件事：`vendor_infos.info` 要放哪些 key、怎麼命名與寫入；`getGameUrl` 的回應物件、Common 工具方法與 Driver 出站請求的錯誤處理；遊戲帳號綁定的並發規則。寫 Driver、設定商戶或處理帳號綁定時讀。

## 設定與註冊（vendor_infos.info）

`vendor_infos.info` 是 JSON 欄位，儲存該遊戲商 API 串接所需的金鑰與參數。**不同遊戲商的 key 不同**，需對照 Driver 與回調 Controller 確認實際用到哪些。

### 確認所需 key 的方法

搜尋 Driver.php 與回調 Controller 中 `$this->vendorInfo->info` 或 `$info[` 的使用位置，列出所有用到的 key，對照廠商提供的憑證填入對應值。

### key 命名規範

- **命名依據 `api.md`**，使用文件中的參數名稱轉大寫，而非自訂名稱。例如遊戲商 API 參數為 `oc`，則 key 命名為 `OC`，而非 `OPERATOR_CODE`。
- `API_URL` 為例外，遊戲商不會以此為參數名，但**所有遊戲商必須加入此 key**，供 Driver 的 `sendRequest` 發送 HTTP 請求。

| Key | 說明 |
|-----|------|
| `API_URL` | 遊戲商 API 的 base URL（必填） |
| `OC` / `OPERATOR_CODE` 等 | Operator 代碼，依 `api.md` 命名 |
| `SECRET` | HMAC 簽名密鑰 |
| `API_KEY` | API 金鑰 |
| `CURRENCY` | 幣別代碼（與 `vendor_infos.currency` 欄位一致） |

### 建立或更新方式

**覆寫整個 info**：

```sql
UPDATE vendor_infos
SET info = '{"OPERATOR_CODE":"xxx","SECRET":"xxx","CURRENCY":"MYR"}'
WHERE vendor_id = {vendorId};
```

**僅更新單一 key**：

```sql
UPDATE vendor_infos
SET info = JSON_SET(info, '$.SECRET', 'new_secret')
WHERE vendor_id = {vendorId};
```

> **注意**：`SECRET` 未設定時回調一律拒收並記 warning（見 R17），正式環境必須填入正確值，否則該 vendor 的回調會全數被拒。

---

## 標準響應物件與共用工具

### VendorGameUrlResultData

至少提供 `pc`、`mobile` 其中一組，或提供 `app_infos`，三者不能同時為空。

```php
// PC + Mobile
VendorGameUrlResultData::from([
    'pc'     => 'https://example.invalid/launch?token=[REDACTED]',
    'mobile' => 'https://example.invalid/launch?token=[REDACTED]',
]);

// 原生 App
VendorGameUrlResultData::from([
    'app_infos' => [
        ['platform' => 'ios',     'url' => 'https://...'],
        ['platform' => 'android', 'url' => 'https://...'],
    ],
]);
```

---

### Common 工具方法

> 金額運算／比較（`Common::numAdd/Sub/Mul/Div/numCompare`）與遮罩規範為 CLAUDE.md 常駐紅線，不在此重述。

```php
// IP 白名單驗證（遊戲商回調 Controller 前置驗證呼叫）
Common::verifyIPWhitelistByVendorId($this->vendorId);

// 安全 JSON 編碼（example.invalid 落原始請求用）
Common::safeJsonEncode($data);
```

### Driver 出站請求的錯誤處理（一律拋，不自己轉包）

`app/Vendors` 下的出站 HTTP 一律 `Http::logAction('{VendorLabel}_{endpoint}')->...->throw()`，錯誤交由 `bootstrap/app.php` 的 render 與 `AlertGate` 統一處理。

| 情況 | 做法 |
|---|---|
| HTTP 層錯誤（4xx／5xx／連線失敗） | `->throw()` 原樣拋 `RequestException` |
| 三方回應**非成功碼**（維護中、帳號異常等業務性拒絕） | `throw new ApiException('...')` —— `AlertGate` 對 `ApiException` 靜音，不發 TG |
| 轉帳／`checkTransferStatus` 路徑 | **不可拋**，一律回 `VendorTransferResultEnum::TIMEOUT`／`FAILED` 讓對帳接手（[transfer-wallet.md](transfer-wallet.md)「轉帳結果回傳規則」） |

- 🚫 **禁止 `->throw(fn () => throw new ApiException(...))` 這種 callback 轉包**，也禁止 `catch (Exception|Throwable)` 後轉包：堆疊與原始回應會在轉包時遺失，告警也跟著瞎掉（`2722b9f73`、`b1551e903`）。
- 🚫 **業務性拒絕不要走未捕捉例外**：三方「維護中」「餘額不足」這類回應每次都發 TG，會把告警管道洗掉、真故障反而被淹沒（`1931e3629`、`2224743d9`、`5ce5eb708`、`2463450f8`、`d443bf4b4`）。
- `HTTP 400／4xx 帶業務碼 envelope` 的三方（Play8oy／SunCity 型）要先判 envelope 再決定是業務錯誤還是真故障，否則會被 `->throw()` 一律當成逾時（`1cf0d7d37`）。

---

## 遊戲帳號綁定並發規範

> 高併發下，`getVendorAccount()` 的帳號綁定流程若設計不當，會引發 MySQL Deadlock（errno 1213）。

### Schema 索引（設計階段必備）

見 [schema.md](schema.md)「{tname}_accounts 必要欄位與索引」，`(member_id, vendor_info_id, status)` 複合索引（`idx_xxx_bind_lookup`）為必要設計。

### 程式實作規則

| # | 規則 |
|---|------|
| 1 | 以 `Cache::lock("vendor_account_bind:{vendorInfoId}:{memberId}")` 序列化同會員並發請求 |
| 2 | Query 1（已綁定查詢）**禁止**使用 `lockForUpdate()`，避免放大 gap lock 範圍 |
| 3 | Query 2（帳號池分配）**必須**使用 `FOR UPDATE SKIP LOCKED` |
| 4 | 綁定寫入與關聯資料建立須包在同一 `DB::transaction` 內 |
