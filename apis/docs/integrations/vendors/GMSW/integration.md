# GMSW（聚合遊戲商，單一錢包，vendor_id=237–246）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill（方法論已遷市集）；本檔聚焦 GMSW 單錢包**聚合多子廠商**差異。
> 廠商原文規格見同目錄 `api.md`（來源：工單 `target/tickets#2220` showdoc，密碼 `123456`）。

## 速查表

| 項目 | 值 |
|---|---|
| 架構 | **聚合遊戲商（Aggregated Single Wallet）** |
| 子廠商數量 | **10 個**（各自有獨立 `vendor_id`，237–246 連續） |
| `tname` | `gmsw`（所有子廠商共用） |
| `third_party_code` | `GMSW`（所有子廠商共用） |
| 主要 Controller | `app/Http/Controllers/External/SingleWallets/GMSWController.php` |
| Driver | `app/Vendors/GMSW/Driver.php` |
| PLATFORM_VENDOR_MAP | `App\Vendors\GMSW\Driver::PLATFORM_VENDOR_MAP` |
| Migration | `database/migrations/2026_06_02_000001_create_gmsw_tables.php` |
| 官方規格 | 同目錄 `api.md`（GM 單一錢包 API，源自工單 `target/tickets#2220` showdoc） |

## 子廠商對照表（PLATFORM_VENDOR_MAP）

本地 `vendor_id` ↔ GM `platform`（Brand Code）整數對照（以 `Driver::PLATFORM_VENDOR_MAP` 為準）：

| `VendorIdEnum` case | `vendor_id` | GM `platform` | 子廠商 |
|---|---|---|---|
| `GMSW_PG` | 237 | `2` | PG Soft |
| `GMSW_JILI` | 238 | `3` | JILI |
| `GMSW_PP` | 239 | `4` | Pragmatic Play |
| `GMSW_OMG` | 240 | `5` | OMG |
| `GMSW_MINIGAME` | 241 | `6` | MiniGame（GM 端含 Spribe / MiniGame / Lottery / Casino） |
| `GMSW_HACKSAW` | 242 | `8` | Hacksaw |
| `GMSW_TADA` | 243 | `23` | TADA |
| `GMSW_CP` | 244 | `24` | CP |
| `GMSW_ASKME` | 245 | `25` | ASK |
| `GMSW_JDB` | 246 | `28` | JDB |

> ⚠️ GM 的 `platform=6 MiniGame` 在 GM 端**已涵蓋 Spribe / MiniGame / Lottery / Casino**（見工單 #2220 Brand Code 說明），本地不另開 vendor_id。
> 同一套 GM API 端點（APP_ID / KEY / API_URL）對應所有子廠商；不同代理在 `vendor_infos` 中為每個子廠商各建一筆記錄，但憑證內容相同。

## 聚合架構設計要點

GMSW 是一個 GM 平台對外暴露統一 API，平台內部再路由至不同遊戲廠商（PG、JILI、PP 等）。本地端以 **10 個 vendor_id（237–246）** 追蹤這 10 個子廠商，用途：

- 各自計算 summary_bet_records 與 turnover
- 各自設定 vendor_info（獨立的代理配置與幣別）
- 帳號層以 `username` 單一唯一鍵，同一玩家全局只有一筆帳號（Top55SW 相同模式）

### 為何不使用單一 vendor_id？

若用單一 vendor_id 則無法區分玩家的 PG 注單與 JILI 注單，對帳、返水、活動結算均需 GM 子廠商維度，強制拆分。

## 無固定 vendorId 的動態解析

Controller 不宣告 `protected int $vendorId`，改由請求參數動態解析：

### verifySession（`POST /sw/gm/api/luck/user/verify_session`）

以 `session_token` + `app_id` 解析 → `resolveBySessionToken()`

- session_token 在 `getGameUrl` 時寫入 `gmsw_accounts.session_token`，每次進遊戲刷新
- session_token 綁定在特定子廠商帳號上，查到即知道子廠商

### getBalance（`POST /sw/gm/api/luck/balance/get_balance`）

以 `uname` + `app_id` 解析 → `resolveByUsername()`

- 同一玩家在多個子廠商都有帳號，任一帳號皆可（餘額從 member wallet 讀，不因子廠商不同而異）
- 找到第一筆符合 app_id 的 vendor_info 下的帳號即可

### changeBalance（`POST /sw/gm/api/luck/balance/change_balance`）

以 `game_id` + `app_id` 解析子廠商 → `resolveByGameId()`，再以 `uname` 查帳號 → `resolveAccountByUsername()`

- `game_id`（GM 遊戲編號）存入 `games.game_type`，`games.vendor_id` 記錄對應子廠商
- 必須先 `resolveByGameId` 確定子廠商，再查對應 `vendor_info_id` 下的帳號

## 帳號表（gmsw_accounts）單一唯一鍵

```php
$table->vendorAccountColumns(uniqueUsername: true, ...);
```

每個玩家只有**一筆帳號**（`UNIQUE username`），與 Top55SW 相同模式。`vendor_info_id` 記錄首次建帳的子廠商，但不影響後續 callback 查詢。Driver 使用 `firstOrCreate` 建帳，避免玩家在不同子廠商重複進入遊戲時炸 1062。

## IP 白名單

`verifyIPWhitelistByVendorId()` 只接受單一 `int`，故 Controller 使用私有方法遍歷所有子廠商：

```php
private function verifyIPWhitelist(): bool
{
    foreach (GMSWDriver::PLATFORM_VENDOR_MAP as $vendorId => $platform) {
        if (Common::verifyIPWhitelistByVendorId($vendorId)) {
            return true;
        }
    }
    return false;
}
```

任一子廠商 IP 白名單通過即放行（GM 回調來自同一 IP 池）。GM 正式服白名單 IP（見工單 #2220）：中心 `0.0.0.0`、香港 `0.0.0.0`、巴西 `0.0.0.0`。

## 簽章驗證

`sign` header = `md5(urlParams + rawBody + KEY)`，其中：

- `urlParams`：若 query 有 `trace_id` 則為 `trace_id={value}`，否則為空字串
- `KEY` 從任一符合 `APP_ID` 的 vendor_info 取出（所有子廠商同一 operator 共用相同 KEY）

> 簽名演算法詳見同目錄 `api.md`（原圖見工單 #2220，商戶帳號內可見）。

## Callback Action 對照表（type 欄位）

| `type` | 意義 | 處理方法 |
|---|---|---|
| `1` | Bet 投注 | `handleBet()` — 扣款，建 BR，寫 TBR |
| `2` | Cancel 取消投注 | `handleCancel()` — 退款，更新 BR status=-1 |
| `3` | Win 派彩 | `handleWin()` — 加錢，累加 BR.win，寫 TBR |
| `4` | EndRound 結算確認 | `handleEndRound()` — 確認 BR status=1，寫 TBR |
| `5` | LuckwinBonus 寶箱 | `handleLuckwinBonus()` — 加錢，累加 BR.bonus，可無對應 bet |
| `6` | FutureFee 手續費 | `handleFutureFee()` — 扣款，建獨立 BR（`bet_id = sessionId_fee_orderId`） |

## BR / DealRecord 設計（Round 層級彙總）

| 表 | 唯一鍵 | 說明 |
|---|---|---|
| `gmsw_bet_records.bet_id` | `session_id`（UNIQUE） | 同 round 多次 bet/win 累加 |
| `gmsw_deal_records.reference_no` | `order_id`（UNIQUE） | 每筆 callback 一筆，冪等鍵 |

## 冪等模式（INSERT-first）

同 `game-integration` skill 的單錢包冪等規範：事務開頭先 INSERT `gmsw_deal_records`，業務錯誤 `throw DomainException` 觸發 rollback，重送靠 `1062` Duplicate Key catch：

```php
try {
    return DB::transaction(function () use (...) {
        GMSWDealRecord::create(['reference_no' => $orderId, ...]);  // 1062 → idempotent
        // 業務邏輯
        if ($insufficient) { throw new DomainException('Insufficient balance'); }
        // ...
    });
} catch (QueryException $e) {
    if (($e->errorInfo[1] ?? 0) === 1062) {
        return $this->idempotentBalanceResponse();   // 回原餘額
    }
    throw $e;
} catch (DomainException $e) {
    return $this->errorResponse(10001, $e->getMessage());
}
```

## vendor_infos.info 欄位

| 欄位 | 用途 |
|---|---|
| `API_URL` | GM API base URL |
| `APP_ID` | operator 識別碼（GM 稱 app_id） |
| `KEY` | HMAC-MD5 簽章密鑰 |
| `PLATFORM` | GM platform 整數（見上表），供 `getGameList` 過濾遊戲用 |

> 同一代理的 10 個子廠商的 `APP_ID`、`KEY`、`API_URL` 完全相同；`PLATFORM` 各自不同（例如 GMSW_PG 填 `2`、GMSW_JILI 填 `3`）。`PLATFORM=0` 時 `getGameList` 不過濾，可一次取得全部遊戲（測試用）。

## 上線步驟（聚合商專屬差異）

1. `VendorIdEnum` 已有 `GMSW_PG`（237）到 `GMSW_JDB`（246）共 10 個 case
2. `config/vendor.php` 已有 10 筆 Driver 註冊
3. Migration 已含單一唯一鍵 `username`（`uniqueUsername: true`）
4. `php artisan vendor:add_vendor_info` 需執行 **10 次**（每個子廠商一筆），憑證填相同 APP_ID / KEY / API_URL
5. 遊戲列表：`php artisan update_game_list --vendor-id=237`（GMSW_PG 為範例），GM 回傳的遊戲 `platform` 欄位會對應到正確的子 vendor_id；其餘子廠商依序執行
6. 驗查用 SQL（以 vendor_id=237 為例，需對 237–246 各執行）：

```sql
SET @VendorId = 237;
SELECT * FROM vendor_infos WHERE vendor_id = @VendorId AND status = 1;
SELECT * FROM games         WHERE vendor_id = @VendorId AND status = 1;
```

## 整合落地檢核清單

- [ ] 10 個子廠商 vendor_info 均已建立並設定相同 APP_ID / KEY / API_URL
- [ ] IP 白名單設定（GM callback IP → 10 個 vendor_id 均配置）
- [ ] `gmsw_accounts` 單一唯一鍵 `username` 已建立（migration `uniqueUsername: true`）
- [ ] `resolveByGameId` 路徑：`games.game_type` = GM `game_id`，`games.vendor_id` 對應正確子廠商
- [ ] type=1 Bet 先驗 `BetHelper::isMemberAbleToBet`，type=2~6 不需要
- [ ] type=5 LuckwinBonus 允許無對應 BetRecord（可無前置 Bet）
- [ ] type=6 FutureFee BR `bet_id` 使用 `{sessionId}_fee_{orderId}` 避免與正常 round 碰撞
- [ ] `summary_bet_record.php` 10 筆子廠商設定均為 `third_party_code => 'GMSW'`
