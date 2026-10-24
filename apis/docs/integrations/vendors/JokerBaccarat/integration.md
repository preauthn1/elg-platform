# JokerBaccaratSW（vendor_id=235）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill（方法論已遷市集）；本檔聚焦 GFF / TPOWER 直對 Joker Baccarat 單錢包差異。
> 2026-05-18 上線（ticket target/tickets#3081）。

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 235 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::JOKER_BACCARAT_SW` |
| `tname` | `jkbsw` |
| `third_party_code` | `JKBSW` |
| 架構 | Single Wallet Seamless（Lobby 模式） |
| 官方規格 | 同目錄 `api.md`（Merchant API + Seamless Callback + 3DES 簽章，雙錢包模式完整規格） |
| 主要 Controller | `app/Http/Controllers/External/SingleWallets/JokerBaccaratSwController.php` |
| Driver | `app/Vendors/JokerBaccaratSW/Driver.php` |
| Trait（簽章/HTTP） | `app/Vendors/JokerBaccaratSW/JokerBaccaratSWTrait.php` |
| 整合測試 | `tests/Feature/External/SingleWallets/JokerBaccaratSW/` |
| 簽章單元測試 | `tests/Unit/Vendors/JokerBaccaratSW/SignatureTest.php` |
| 對帳保留期 | **3 個月**（Game Log / Game Log Detail / Daily Summary） |

## 與舊版 vendor_id=177 (JOKER_BACCARAT) 的區別

| 項目 | 177 JOKER_BACCARAT | 235 JOKER_BACCARAT_SW（本卡） |
|---|---|---|
| 接入方式 | 經 PGSSW 聚合器路由 | GFF / TPOWER **直對接入** |
| `tname` | `pgssw` | `jkbsw` |
| 資料表 | 共用 `pgssw_*` 表 | 獨立 `jkbsw_accounts` / `jkbsw_bet_records` / `jkbsw_deal_records` |
| 簽章 | 走 PGSSW 規範 | 自家 3DES + MD5 衍生金鑰 |

兩者並存，請依新業務需求一律走 235。

## 錢包模式

Seamless Wallet：**不需** `transferToVendor` / `transferToLocal`；玩家錢包由代理保管，Joker 透過 callback 即時扣款 / 派彩。

## 3DES 簽章演算法（Merchant API only）

> Callback（`/balance/detail`、`/balance/transfer`、`/goodRoad`）**不帶 signature**，僅以 **IP 白名單**驗證。

PHP 實作摘要（見 `JokerBaccaratSWTrait::buildSignature()`）：

```php
$key   = md5($apiToken, true);                // ⚠ raw bytes (16) — 第二參數 true
$key24 = $key . substr($key, 0, 8);           // 補成 24 bytes
$json  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$cipher = openssl_encrypt($json, 'des-ede3', $key24, OPENSSL_RAW_DATA, '');
return base64_encode($cipher);
```

**踩坑點**：

- `md5(..., true)` 第二參數必須 `true`，否則拿到 32 字元 hex string 會 key 長度錯
- `JSON_UNESCAPED_SLASHES` 不可省，避免 `/` 變 `\/` 導致兩端 JSON 不一致
- 3DES key 補滿 24 bytes 避免不同 OpenSSL 版本行為差異

## Lobby vs Table 模式

本接入採 **Open Lobby**（`/merchant/game/open-lobby`，`category=all`）。6 張桌台（`001`~`006`）由玩家在 Joker 大廳內自選。

- Callback 不帶 `tableCode`
- BR `game_code` 統一寫死 `jkbsw`（對齊 `vendors.tname`），常數 `JokerBaccaratSwController::GAME_CODE`

## Callback Action 對照表

| Action | 意義 | Endpoint | 處理重點 |
|---|---|---|---|
| `1` Get Balance | 查餘額 | `/balance/detail` | 帳號狀態檢核；不需 `BetHelper::isMemberAbleToBet` |
| `2` Player Bet | 下注扣款 | `/balance/transfer` | `lockForUpdate` → 餘額檢查 → 扣款 → BR/DR |
| `3` Player Win | 派彩 | `/balance/transfer` | 加錢、更新 BR、寫 TBR；**百家樂和局退注亦屬此類** |
| `4` Refund | 系統退款 | `/balance/transfer` | 找不到原始 Bet 時回 `status:true` 並記 log |
| `5` Rollback | 對帳重同步 | `/balance/transfer` | 依 Joker 紀錄為準調整；用 `bet_record_recovery_logs` 留審計 |

## BR / DealRecord 資料結構（Round 層級彙總）

一個 `roundID` 可能伴隨**多筆 `transactionNo`**（例：同時下莊+對子），設計：

| 表 | 唯一鍵 | 寫入時機 |
|---|---|---|
| `jkbsw_bet_records.bet_id` | `roundID`（UNIQUE） | 累加 `bet` / `valid_bet` / `win`，**不為每筆 transaction 建獨立 BR** |
| `jkbsw_deal_records.reference_no` | `transactionNo`（UNIQUE） | 每筆 callback 寫一筆，作為冪等鍵 |

最終 BR 為 round 累加結果；明細追溯到 deal_records。

## 冪等模式（INSERT-first）

事務開頭就 `INSERT` deal_record；競態靠 `1062` Duplicate Key 攔截後回原 balance：

```php
DB::transaction(function () use ($payload) {
    JkbswDealRecord::create([...]);          // 衝突 → SQLSTATE 23000 / errorInfo[1] = 1062
    // 業務邏輯（餘額檢查、扣款、寫 BR / TBR）
    if ($insufficientBalance) {
        throw new DomainException('Insufficient balance');   // rollback
    }
});
```

- 業務錯誤一律 `throw new \DomainException(...)` → 觸發 rollback → 回 `status:false`
- 重送同 `transactionNo` → catch `errorInfo[1] === 1062` → 回該筆原本的 balance（HTTP 200, `status:true`）

## handleWin TBR 寫入

使用 `TotalBetRecordService::create()`（內部 `updateOrCreate(vendor_id, bet_record_id)`），自動將 BR 累加後的最新狀態同步到 TBR。

⚠ **不要**用 `createOrUpdate()`——那是 delta-style 設計，與本 vendor 的 round 累加模型不相容。

## 語言映射

`Driver::mapLanguage(LanguageEnum)`：

| Internal LanguageEnum | Joker code |
|---|---|
| `EN` | `EN` |
| `ZH_TW` / `ZH_CN` | `CN` |
| `MS_MY` / `ID_ID` | `MY` |
| `TH_TH` | `TH` |
| `VI_VN` | `VN` |
| `MY_MM` | `MM` |
| 其他 | `EN`（Joker 端 fallback 一致） |

> Joker 還支援 `JP`，但專案目前 LanguageEnum 無對應，視需要再補。

## vendor_infos.info 欄位

`App\Vendors\JokerBaccaratSW\VendorInfoDetail`：

| 欄位 | 用途 |
|---|---|
| `API_URL` | Merchant API base URL（環境別由客服提供） |
| `API_ID` | Portal BO 登入帳號（Signature 共用） |
| `API_TOKEN` | 3DES 衍生金鑰來源 |
| `CURRENCY` | 玩家錢包幣別（簽章帶入） |

> Staging 憑證見 ticket target/tickets#3081；勿寫入版本控制。
> BO `https://portal.jokerbaccarat.live`、Merchant API `https://merchant-api.jokerbaccarat.live`。

## Controller 模式

`JokerBaccaratSwController::callAction()` 集中處理：

1. `Common::verifyIPWhitelistByVendorId($this->vendorId)` — IP 白名單
2. 解析 `playerID` → 對應 `JkbswAccount` → 抓 member
3. 帳號狀態檢核（`status == 0` 直接回 `status:false`）
4. 通過後才 `parent::callAction($method, $parameters)` 進入個別 action handler

子方法（`balance` / `transfer` / `goodRoad`）只負責業務邏輯，不重複做白名單與帳號檢核。

## 對帳工具落地

- `artisan summary_bet_record` 命令對應 Service 類別：`App\Services\SummaryBetRecords\JokerBaccaratSW`，`$timeField = 'payout_time'`
- 對帳資料來源：Game Log（回合層級）/ Game Log Details（交易層級）/ Daily Summary（彙總）
- **3 個月保留期內**完成；超期需請客服撈

## 整合落地檢核清單

- [ ] IP 白名單雙向設定（APIs outbound IP → Joker；Joker callback IP → `Common::verifyIPWhitelistByVendorId`）
- [ ] `balance/detail` 帳號狀態檢核
- [ ] `balance/transfer` 四種 action（2/3/4/5）冪等實作
- [ ] BR 採 `roundID` 累加、DR 採 `transactionNo` 冪等
- [ ] TBR 在 action=3 Win 透過 `TotalBetRecordService::create()` 寫入
- [ ] 3DES 簽章單元測試覆蓋（向量比對）
- [ ] `goodRoad` 路由存在以免 404（功能可選）
