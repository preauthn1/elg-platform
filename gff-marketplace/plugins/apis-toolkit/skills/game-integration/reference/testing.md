# 遊戲商測試規範

> 為遊戲商整合（單一錢包回調、轉帳錢包 Driver、對帳命令）撰寫測試的規範與範例。`game-integration` skill 載入後同時生效。

## 測試目錄

| 類型 | 路徑 |
|---|---|
| 單一錢包回調 | `tests/Feature/External/SingleWallets/{VendorName}/` |
| 轉帳錢包回調 | `tests/Feature/External/TransferWallets/{VendorName}/` |
| Driver 單元測試 | `tests/Unit/Vendors/{VendorName}/`（若需要直接覆蓋 Driver 方法） |
| Vendor 相關 Artisan 命令 | `tests/Feature/Console/Commands/Vendor/{VendorName}/` |

每個 vendor 資料夾下應包含一個共用的 `{VendorName}TestCase.php` 作為基類，集中 fixture、Http::fake 預設、account / vendor_info seeder。

## 完整參考範例

| 範例 | 涵蓋 |
|---|---|
| `tests/Feature/External/SingleWallets/KissH5SW/` | 單錢包完整生命週期：bet、settle、bet+settle 合一、bonus、jackpot、rollback、getBalance、cancel bet+settle |
| `tests/Feature/External/SingleWallets/PTSW/` | refund / payout sessionLog、settlement no-pay、multi-bet 累加 |
| `tests/Feature/Console/Commands/Vendor/PlayTech/ReconcileBetAccumulationTest.php` | 對帳命令（多 bet 累加場景） |

新增遊戲商時，先複製鄰近 vendor 的 `TestCase` 與 `BetTest` / `SettleTest` 改寫，比從零寫快。

## Http::fake 標準格式

```php
Http::fake([
    '*' => Http::response([
        'code' => 0,
        'msg'  => 'success',
        'data' => [/* 廠商回應結構 */],
    ], 200),
]);
```

轉帳錢包 Driver 測試三種回應狀態都要覆蓋：成功 200 / 失敗碼 / 逾時或空 body。

## 測試覆蓋要求

### 單一錢包回調（每個 endpoint 一支測試檔）

依該 vendor 實際支援的 callback 動作建立對應測試檔。常見動作與必測情境：

**Bet（下注）**
- [ ] 正常下注，建立 `xxx_bet_records` 並扣餘額
- [ ] 同 round 多次 bet 累加（不可覆寫 bet_amount）
- [ ] 重複 callback（冪等性），第二次回 200 不重複扣款
- [ ] 餘額不足，回對應錯誤碼

**Settle / Payout（派彩）**
- [ ] 正常派彩，更新 bet_records 結算欄位
- [ ] settle 找不到對應 bet → 依 vendor 規範回 error 或無 pay
- [ ] 重複 settle，冪等性
- [ ] 建立 `total_bet_records`（預設結算才建，見 [single-wallet.md](single-wallet.md)「total_bet_record 建立時機」）
- [ ] **payout=0 的輸錢結算也要關聯 transaction_log**（斷言 `total_bet_record_transaction_logs` 有 pivot）
- [ ] **全禮碼投注結算不得關聯 transaction_log**（斷言無 pivot；尤其不可掛到同 session 其他回合的現金紀錄）

**Refund / Cancel（退款）**
- [ ] 正常退款，沖銷 bet（含 gameRoundResult deal_records）
- [ ] refund 找不到 bet → 依規範回應
- [ ] 重複 refund，冪等性

**GetBalance**
- [ ] 帳號正常回餘額
- [ ] 帳號停用回對應錯誤碼

**Rollback / Bonus / Jackpot（vendor 支援才寫）**
- 各自至少覆蓋成功 + 冪等

**CreateAccount（vendor 有建立帳號 API 才寫；無三方 API 的純本地寫入型免測）**
- [ ] 三方回成功 → `member_id` 由 `-999999999` 翻成 `0`，帳號進入帳號池
- [ ] 三方回失敗 / 逾時（`Http::fake` 模擬）→ 本地帳號維持佔位值 `member_id = -999999999`、沒有進帳號池（此路徑手動 QA 無法製造三方故障，只能靠測試驗，見 acceptance-contract.md B-3）

### 轉帳錢包 Driver（單元測試）

- [ ] `getBalance()` 正常回字串
- [ ] `getGameUrl()` 回 `VendorGameUrlResultData`
- [ ] `transferToVendor()` 成功 → `VendorTransferResultEnum::SUCCESS`
- [ ] `transferToVendor()` 廠商回失敗 → `FAILED`
- [ ] `transferToVendor()` 逾時 / 空 body → `TIMEOUT`（重要：錯回會造成餘額異常）
- [ ] `transferToLocal()` 同樣三種狀態

### 共通

- [ ] IP 白名單驗證失敗 → 拒絕
- [ ] 帳號狀態檢核（`account->status` 停用）
- [ ] Controller 已實作 `ShouldVerifyGameBettability`（單一錢包強制，無例外）
- [ ] 投注權限檢核（`$this->verifyBettability`，內部即 `BetHelper::assertMemberAbleToBet`）
- [ ] **驗資格的位置**：斷言它跑在鎖會員（`getMemberLockedWallets()`）**之後**——放在鎖前會讀到等鎖前的舊快照，而這種錯循序測試測不出來，只能靠查詢順序斷言釘住。藍本：`AcewinBettabilityTest`、`UUSlotSWBettabilityTest`、`Rcb988SWBettabilityTest`
- [ ] 下注資格不通過時回的錯誤碼與 `api.md` 一致（`BetException` 要被接住，不可讓它變成 500）

## 強制要求

- 所有測試檔案首行：`declare(strict_types=1);`
- 使用 `Http::fake()` 模擬所有外部請求，**禁止**真實 HTTP 呼叫
- 使用 Factory 建立 member、account、vendor_info，禁止硬編碼 ID
- 金額斷言一律 `assertSame('100.0000', $value)`，禁止用 `assertEquals` 比浮點
- 跨表 SUM 對帳測試請參考既有 `ReconcileBetAccumulationTest` 模式

## 執行測試

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan test tests/Feature/External/SingleWallets/{VendorName}/"
```

## 安全規則怎麼驗（[SKILL.md](../SKILL.md)「動錢回調安全紅線（速查表）」那 27 條的驗證方法）

紅線本身不會自我驗證。這一節收的是「**要怎麼寫測試才真的測得到那條規則**」——這批事故裡有多個缺陷是**測試綠著卻仍在生產爆掉**，成因都在下面。

### T1｜併發型缺陷必須用第二條 DB 連線測，循序重送測不到

- 🔴 stale snapshot、lost update 這類缺陷的成因是「**快照建立在對手 commit 之前**」。循序送兩次請求時第二次是全新交易、快照當然是新的，**測試會綠，生產照爆**。
- ✅ 正確做法：用 `DatabaseTransactions` 的測試交易當「先建快照的那一方」，另開**第二條 DB 連線**（clone default 設定）在快照建立之後才 commit 對手資料，清理掛 `beforeApplicationDestroyed`（要排在 trait rollBack 之後）。既有藍本：`PegasusSW/StaleSnapshotReadTest`、`Spadegaming/CancelStaleSnapshotTest`（一般讀版本實跑餘額 1000.05 vs 應為 850.05）。
- ⚠️ **有一類改既有列的競態在現行框架下無法端到端重現**（測試整包在一個交易裡、快照在 `setUp()` 就固定，第二條連線改既有列的異動測試既看不到也改不動）。那種情況改用反射把過期物件直接餵給待測方法，**不要為了「重現」耗時間**。

### T2｜R1 用 query log 斷言「交易內第一個 SQL」

「交易第一句必須是鎖會員列」是順序規則，只能用 query log 驗：斷言第一筆 SQL 形如 `select ... from members ... for update`。

⚠️ 寫完先確認**它對舊代碼會紅**（舊寫法第一句查的是 `agents`）——沒紅過的順序斷言等於沒寫。

### T3｜驗簽測試不可沿用產線同一支簽名 helper

用同一支 helper 產生「三方送來的簽名」再拿去驗，等於自己跟自己比對——**helper 寫錯時兩邊一起錯，測試照樣綠**。測試裡的預期簽名要獨立算（照 `api.md` 的公式在測試中重寫一次）。

### T4｜行為變更要用測試把當前決策釘住

把「照做」改成「拒絕」、把「放行」改成「拒收」這類決策，若**生產零發生且無三方文件佐證**，必須留一支測試釘住現行決策並在 docblock 寫明理由與證據。

日後三方確認語意不同時，要改決策就得先改那支測試，代價看得見（例：`AFBSwApiKeyTest`、`DstPlaySW GetAuthTest`）。

### T5｜刻意未修的缺口寫進 `integration.md` 的冪等表

已知但這次不修的缺口（例如某端點的冪等鍵取錯但歷史零發生），**要寫進該 vendor `integration.md` 的冪等表，不能只留在 commit message**。commit message 沒有人會回頭翻，冪等表才是下一個接手的人會讀的地方。

### T6｜守衛與偵測器本身要有反向驗證

- 🔴 **任何守衛都要證明它「抓得到」，不只證明它「不誤擋」**。加了規則卻沒驗過它會紅，等於不知道自己有沒有寫壞。
- ✅ 兩種做法：靜態偵測器（lint／架構測試）用**字串 fixture** 釘住行為，列出「必抓」與「必放」兩組（藍本 `WalletWriteEntrypointTest::test_detector_catches_known_bypass_shapes()`）；行為守衛則**暫時還原舊寫法確認測試變紅**再改回。
- ⚠️ 失效方向必須是誤擋而非放行——守衛靜默失效不會有任何訊號。

### T7｜fixture 不可測「現實不存在的狀態」

測試 fixture 若少設了生產一定會有的設定（例如 `vendor_infos.info` 少了 `API_KEY`），那支測試驗的是一個**現實不存在的狀態**，而且會在規則收緊時集體變紅、讓人誤以為是改動出錯。

建 fixture 前先確認生產的實際樣態；發現不一致時修 fixture，不要放寬規則去遷就它。
