# Sky1388（vendor_id=28）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | Transfer Wallet（轉帳錢包）；Sky1388 端持有玩家餘額 |
| API 版本 | v0.3.7（2025-08-06） |
| Base URL | `http://<hostname>/api/wsv1_0/`（hostname 由 Sky1388 提供，**結尾含 trailing slash**） |
| HTTP Method | 文件支援 GET/POST；**APIs 實作一律使用 GET** |
| 驗證方式 | `agid` + `signature`（SHA1，見 api.md 簽名章節） |
| 成功判定 | `status === 'ok'`（經 `strtolower` 後比對） |
| 回調方向 | **無**（Transfer Wallet 採主動 pull，無 seamless 即時回調）；v0.3.7 新增 Confirm Transfer Callback 選用未啟用 |
| 實作 Driver | `app/Vendors/Sky1388/Driver.php`（`extends AbstractTransferWallet`） |

## 高風險陷阱

### 1. TRX_EXIST 應視為冪等成功（轉帳重送時）

`Driver::transfer()` 目前以 `status === 'error'` 判 `FAILED`、其餘非 `ok` 判 `TIMEOUT`。若上下分 timeout 後重送，Sky1388 回 `error_code=TRX_EXIST`，此時 `status='error'`，Driver 判為 `FAILED` → 錯誤還款。

**正確語意**：`TRX_EXIST` 在 transfer 重送場景 = 該筆已成功，應視為**冪等成功**。需補特判邏輯，或依賴 `checkTransferStatus` 查單確認。

### 2. TRX_INVALID 查單意義待確認

`checkTransferStatus` 遇 `error_code=TRX_INVALID`（交易不存在），目前以 `status === 'error'` 歸 `FAILED`。但「不存在」是否等同「從未成立、可安全判定最終失敗」，須向 Sky1388 客服確認：

- 若 = 完全未到達（可退款給玩家）→ 可標 `FAILED` 並退款。
- 若可能為「建立中 / 最終被 rollback」→ 不可立即退款，需再 retry 或人工確認。

**目前採保守策略（不退款），可接受但須向客服確認以解除不確定性。**

### 3. game_support 大小寫落差

文件 `user_play_game` 的 `game_support` 範例值為小寫 `h5`，但 Driver 送大寫 `H5`。多數系統大小寫不敏感，但若正式機只接受小寫 `h5`，H5 版 URL 將無法回傳。

### 4. 資料保留僅三天

遊戲歷史（`user_game_history` / `_v2` / `user_platform_game_history`）`start_date` 最多回溯**最近三天**（超過回 `MAX_LAST_THREE_DAYS`）。對帳工具需每日拉取，不可累積三天以上再跑。查全 agent（`username` 留空）時，單次查詢日期範圍不得超過 **10 分鐘**（`MAX_10_MINS`）。

### 5. amount 不可帶千分位逗號

`user_transfer` 的 `amount` 必須為純數字格式（`1000.00` ✅，`1,000.00` ❌）。確保序列化時不引入千分位格式。

## 錢包流程

```
玩家進入遊戲
   │
   ▼
user_register（首次，建立 Sky1388 帳號）
   │
   ▼
user_transfer  amount > 0（存款：代理錢包 → Sky1388）   ← transferToVendor
   │
   ▼
user_play_game / user_play_platform_game（取得遊戲 URL，前端開啟）
   │
   ▼
玩家遊戲（下注 / 派彩皆在 Sky1388 端，本系統不介入）
   │
   ▼
user_transfer  amount < 0（提款：Sky1388 → 代理錢包）   ← transferToLocal
   │
   ▼
（不確定結果時）user_transfer_detail 查單確認             ← checkTransferStatus
   │
   ▼
user_game_history(_v2) / user_report_summary（每日對帳，寫入 BetRecord）
```

- **存款**：`transferToVendor` 送 `amount = +vendorAmount`。
- **提款**：`transferToLocal` 送 `amount = Common::numMul($vendorAmount, -1)`（轉負值）。
- **查單**：以 `orderid = $transactionNo`（本系統交易號）查詢。
- **冪等鍵**：`orderid`（本系統交易號）；Sky1388 重複交易回 `TRX_EXIST`（見上方陷阱 §1）。

## 失敗碼判讀策略

Driver 目前採「**非 ok 即保守**」策略：

| 回應 | 目前判定 | 備註 |
|---|---|---|
| `status=ok` | SUCCESS | ✅ |
| `status=error` + 任意 `error_code` | FAILED（transfer 立即還款） | ⚠️ `TRX_EXIST` 應為冪等成功，見陷阱 §1 |
| `status=error` + `TRX_INVALID`（查單） | FAILED | ⚠️ 語意待客服確認 |
| 壞回應 / 非 ok 且非 error | TIMEOUT（不還款） | ✅ 保守正確 |

文件未提供 transfer 的完整可列舉失敗碼集合，「非 ok 即不確定（TIMEOUT）」符合「禁止永不失敗、誤判優先保守」原則，但 `TRX_EXIST` 例外需補特判。

## 整合落地建議

| Sky1388 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Sky1388/Driver.php`（`AbstractTransferWallet`，實作 `transferToVendor` / `transferToLocal` / `getBalance` / `checkTransferStatus` / `getGameUrl`） |
| Vendor 註冊 | `config/vendor.php`：`VendorIdEnum::SKY1388->value (28) => Vendors\Sky1388\Driver::class` |
| Account model | `App\Vendors\Models\Sky1388Account`（`username` = 玩家帳號） |
| BetRecord model | `App\Vendors\Models\Sky1388BetRecord`（`bet_time` 押注時間、`updated_at` 派彩時間，皆 UTC） |
| 回調 Controller | `App\Http\Controllers\External\TransferWallets\Sky1388Controller::confirmTransfer`（Confirm Transfer Callback，僅此一支；一般轉帳流程仍不需要 callback） |
| `vendor_infos.info` 欄位 | 見下表 |

### vendor_infos.info 欄位對應

| info key | 用途 |
|---|---|
| `ID` | `agid`（Agency id，送往 Sky1388） |
| `PASS` | agent 密碼 |
| `AGENCY_ID` | agency id |
| `API_KEY` | API key |
| `PRIVATE_KEY` | 簽章 privateKey（須向 Sky1388 索取） |
| `API_URL` | API base（Driver 內 `trim('/')` 後使用；正式 `http://example.invalid/api/wsv1_0/`） |
| `GAME_URL` | 遊戲 URL base（`https://example.invalid`） |
| `URL` | 前台 URL（`example.invalid`） |

> ⚠️ `PRIVATE_KEY` 與 `API_KEY` 是否為相同值須向 Sky1388 確認（兩者用途不同：`API_KEY` 用於身份、`PRIVATE_KEY` 用於簽章）。

### 語言對應

Driver `mapLanguageCode()` 轉換：

| 平台 lang | 送往 Sky1388 |
|---|---|
| `zh-TW` / `zh-CN` | `chs` |
| `id-ID` | `ind` |
| `th-TH` | `thi` |
| 其他 | `eng` |

> 注意：遊戲端 launch 參數的 `lang` 另有一套取值（`zh-cn`、`vi`、`khm`…），與 API 三碼不同，見 api.md [Game URL 啟動參數](#game-url-啟動參數)。

## Confirm Transfer Callback（v0.3.7 新增，選用）

Sky1388 → 代理方向的反向查驗：玩家上 / 下分時，Sky1388 呼叫代理後台確認該筆 `user_transfer` 是否來自代理系統（防偽造上下分）。**已實作並供 WTT（Wintoto）使用**：`routes/external/tw.php` 的 `POST external/api/tw/sky1388/confirm_transfer`，接收 `agid`/`order`/`amount`/`kiosk`/`time`，回傳 `{ "status": "0000", "err_message": "" }`。若其他白標的 Sky1388 商戶後台也開啟此防護，把同一組 URL 提供給該白標對應的 Sky1388 後台設定即可，不需要另外開發。詳細欄位見 api.md 相關章節。

## Gaps / 待客服確認

- **TRX_EXIST 語意**：transfer 重送時是否可安全視為冪等成功？需客服確認並補 Driver 特判。
- **TRX_INVALID 語意**：查單時此碼是否等同「從未成立、可安全退款」？
- **HTTP Method**：正式機是否同時接受 GET 和 POST？目前 Driver 全用 GET。
- **game_support 大小寫**：正式機是否要求小寫 `h5`？
- **PRIVATE_KEY vs API_KEY**：兩者是否為相同值？
- **amount 序列化**：確認 `VendorTransferData::vendorAmount` 序列化後不含千分位逗號。
- **對帳工具滾動頻率**：三天保留期要求對帳工具必須每日（或更高頻）拉取，需確認 cron 排程設定。
