# bet_record_recovery_logs — 強制使用規範

> **何時讀這份**：Phase D Step 8 寫補帳腳本前；或要 revert 既有補帳時。

採 **event-log 模式**：每個操作（recover / revert）都是一筆新 row，**不 update**。

---

## 1. Schema 關鍵欄位

| 欄位 | 必填 | 說明 |
|---|:---:|---|
| `vendor_id`, `bet_record_id` | ✅ | 對應到各 vendor 自己的 bet_records 表 |
| `bet_id` | ✅ | 冗余，便於跨 vendor 用 BetId 直接搜 |
| `member_id` | ✅ | NOT NULL 無 default。**寫補帳腳本時很容易漏**：BR.member_id 直接帶入即可。漏寫會在 INSERT 階段拋 1364 `Field 'member_id' doesn't have a default value` |
| `type` | ✅ | `recover` / `revert` |
| `performed_at`, `performed_by`, `reason` | ✅ | 誰、何時、為何 |
| `source` |   | 資料來源：CSV 檔名:row、客服票號、command 名稱等（nullable） |
| `before` / `after` | ✅ | JSON 快照，補帳前後狀態 |
| `balance_diff` |   | 玩家錢包淨變動（recover=正、revert=負）；NOT NULL default 0 |
| `parent_log_id` |   | revert 才填，指向被撤回的 recover；recover 為 NULL |

關鍵約束：`unique(parent_log_id)` 配合 NULL 多重 →
- 同一 bet_record 可多次補帳（多筆 recover with parent_log_id=NULL）
- 每筆 recover 至多被撤回一次

---

## 2. 寫入範本（recover）

> **⚠️ 通用 `bet_record_recovery_logs` 實際只支援 Type C** —— BR 已存在、INSERT 新 TBR、可選擇修 BR 欄位。
>
> 原因：`bet-record-recovery:revert` 命令的設計（見 `Revert.php`）：
> 1. **restore BR**：把 `before` JSON 的 fields 逐欄位寫回 BR（line 480-485）；對 `_meta` key 跳過
> 2. **delete TBR**：刪 `after._meta.total_bet_record_id` 指向的 row（line 514）
> 3. **沒有 delete BR 路徑**
>
> 所以：
> - ❌ **Type A 補建**（INSERT BR + INSERT TBR）**不可用通用範本**：revert 只刪 TBR、留下無 TBR 的孤兒 BR → 永久 drift
> - ❌ **Type B 修既有 TBR 金額** 不可用通用範本：revert 會 DELETE 既有合法 TBR
> - ✅ **Type C 結算**（既有 BR 翻 status + 建新 TBR）才能走通用 revert：restore BR fields + delete 新建 TBR = 對稱
>
> **§2.1（Type A 範本）保留作 vendor patch 的「資料層建構參考」**，但 audit 必須由 patch 內部自帶（含 `BR delete` 的 revert 命令），**不可寫入通用 `bet_record_recovery_logs`**。
>
> **§2.2 Type C 是唯一通用路徑**。
>
> **⚠️ 目前 repo 沒有完整的「Type B safe revert」範例**：
> - 反例型態：對既有 TBR 做 UPDATE 卻寫進通用 `bet_record_recovery_logs`、並把該 TBR id 放進 `after._meta.total_bet_record_id`。之後跑通用 revert 會刪掉這筆合法 TBR。新 patch 不可照這種寫法。
> - `app/Console/Commands/Vendor/KissH5SW/Maintenance/RepairOrphans.php` 是 Type A 補建 + 動 wallet 的範例（INSERT BR + INSERT TBR），可參考其 wallet/transaction 處理（§2.4），但 audit 機制亦需逐 patch 設計。
>
> 新增 Type A / Type B patch 時，必須**自帶 audit 表 + 獨立 revert 子命令**（含 backup_br / backup_tbr 完整快照、drift check、reversal symmetry），**禁止寫入通用 `bet_record_recovery_logs`**。

> **🛑 通用範本（Type C）預設不動玩家錢包**（`balance_diff = '0'`）。原因：
> - **TW vendor**：玩家投注時錢包餘額已轉入 vendor 端、平台錢包沒對應扣款；補帳補資料但不該再動平台錢包
> - **SW vendor**：玩家投注時平台錢包已扣（`deal_records` bet 事件）；補建 BR 是「補資料記錄」不一定要再派彩 — 是否派彩取決於該注單之前的 wallet 狀態是否已扣 / 已派，要逐 vendor 判斷
> - **跨情境風險**：對 TW 注單或已部分入帳的 SW 注單無條件加 `vendorTruth['win']` 進玩家錢包 → 雙重入帳，造成實際資金錯帳，且 transaction log 會變成審計事實難以還原
>
> 要在補帳路徑動 wallet，必須走 vendor-specific patch（見 §2.4）並明確指定 wallet_delta、wallet 類型、既有扣款/派彩狀態、revert 對稱邏輯。**不可在通用對帳 SOP 直接套通用範本動錢包**。
>
> **Type B「修既有 TBR 金額」不可用任何本檔範本** — 通用 revert 命令（`bet-record-recovery:revert`）用 `after._meta.total_bet_record_id` **直接 DELETE TBR**（見 `Revert.php:514`），假設「recover 必建立新 TBR」。若 recover 命中既有 TBR → 撤回時會把**原本合法的 TBR 刪掉**，造成 BR↔TBR 永久不一致並汙染 SBR。
>
> 既有 TBR 金額需要修正的場景，必須另寫專屬 patch（自帶 `backup_tbr` / `restore_tbr`、自帶獨立 revert 命令），**禁止寫入通用 `bet_record_recovery_logs`**。目前 repo 尚無完整安全範例（見上方註解）；新 patch 須自行設計 audit + revert。

### 2.1 範本 A：Type A 補建（vendor patch 的「資料層建構」樣板，**不可寫通用 recovery_logs**）

> 🛑 **本範本不可直接套通用對帳 SOP** — 通用 revert 命令沒有「刪除 INSERT BR」分支（見 §2.0 警告）。下方範本是給 vendor-specific patch 複製當 INSERT BR + INSERT TBR 的資料層樣板，patch 必須**自帶獨立的 revert 命令**處理 BR delete。

```php
// vendor-specific patch 內部使用;不可寫入通用 bet_record_recovery_logs
// ★ 執行前必須由 patch 解析該 vendor 的 settled status enum(見 §2.2 警告中各 vendor 對照)
//   $settledStatus 不可硬編 1;PTSW=3、PPSW=3、KissH5SW=1 等各 vendor 不同
DB::transaction(function () use ($vendorId, $vendorTruth, $brModelClass, $settledStatus) {
    $betIdStr = (string) $vendorTruth['bet_id'];

    // 1. 冪等檢查 + 隱式鎖:依賴 BR 表 unique(vendor_id, bet_id) 或 unique(bet_id)
    $existingBr = $brModelClass::query()
        ->where('bet_id', $betIdStr)
        ->lockForUpdate()
        ->first();
    if ($existingBr) {
        Log::warning('Type A insert hit existing BR — wrong path?', [
            'vendor_id' => $vendorId, 'bet_id' => $betIdStr, 'existing_id' => $existingBr->id,
        ]);
        return;
    }

    // 2. 解析 member_id(vendor 三方提供 username)
    $member = Member::query()->where('username', $vendorTruth['username'])->firstOrFail();

    // 3. INSERT BR(catch 1062 當冪等命中)
    try {
        $record = $brModelClass::create([
            'bet_id'      => $betIdStr,
            'member_id'   => $member->id,
            'status'      => $settledStatus,                    // ★ 不可硬編 1,依 vendor enum
            'bet'         => $vendorTruth['bet'],
            'valid_bet'   => $vendorTruth['valid_bet'],
            'win'         => $vendorTruth['win'],
            'win_lose'    => $vendorTruth['win_lose'],
            'bet_time'    => $vendorTruth['bet_time'],
            'payout_time' => $vendorTruth['payout_time'],
            // ... 其餘 vendor 卡定義的必填欄位
        ]);
    } catch (\Illuminate\Database\QueryException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return; // 並行已建,冪等
        }
        throw $e;
    }

    // 4. INSERT TBR
    $tbr = TotalBetRecord::create([
        'bet_record_id' => $record->id,
        'vendor_id'     => $vendorId,
        'member_id'     => $member->id,
        'valid_bet'     => $vendorTruth['valid_bet'],
        'win_lose'      => $vendorTruth['win_lose'],
        'payout_time'   => $vendorTruth['payout_time'],
        // ... 其餘 TBR 必填欄位
    ]);

    // 5. ★ 寫 patch 專屬的 audit log(不可寫通用 bet_record_recovery_logs)
    //    patch 必須在自己的 audit 表記錄 inserted_br_id + inserted_tbr_id,
    //    並提供獨立的 revert 命令同時 delete BR + delete TBR(配 drift check)
    //    範例:RepairOrphans.php 的 patch_audit_logs 與 revert 子命令
    $patchAuditService->log([
        'patch_name'       => 'fix-missing-br-2026-XX',
        'inserted_br_id'   => $record->id,
        'inserted_tbr_id'  => $tbr->id,
        'vendor_id'        => $vendorId,
        'bet_id'           => $betIdStr,
        'source'           => 'csv:202604_recover.csv:row_42',
    ]);

    // 6. wallet 變動見 §2.4(預設不動;TW / 已部分入帳 SW 動了會雙重入帳)
});
```

### 2.2 範本 C：Type C 結算（既有 BR 翻 status + INSERT TBR）

> **⚠️ 執行前必填**：`$settledStatus` 必須由**該 vendor 的 status enum** 明確指定，**不可硬編 `1`**。各 vendor 的 settled 數字不一致：
> - PTSW：`IN_PROGRESS=1`、`SETTLE=3`
> - PPSW：settled = `3`
> - KissH5SW：settled = `1`
> - 其他：見對應 `docs/reconcile/vendors/{name}.md` 與 `app/Enums/Vendor/{Vendor}StatusEnum.php`
>
> 用錯 status → BR 看似改了但仍非 settled → 之後 Step 2 對齊查 `status=settled` 會把這筆排除、月結也漏 → 補帳變空操作 + 留下 BR↔TBR drift。

```php
// 執行前先解析該 vendor 的 settled status enum;assert 後再呼叫範本
$settledStatus = match ($vendorId) {
    VendorIdEnum::PTSW->value      => PTSWStatusEnum::SETTLE->value,   // 3
    VendorIdEnum::PPSW->value      => 3,
    VendorIdEnum::KISSH5SW->value  => 1,
    default => throw new \RuntimeException(
        "vendor {$vendorId} 的 settled status 未對應;請查 docs/reconcile/vendors/{$name}.md 與 enum 後補上"
    ),
};

DB::transaction(function () use ($vendorId, $betRecordId, $vendorTruth, $reason, $batchSource, $settledStatus) {
    // 1. 鎖既有 BR(防並行 recover 重複加錢、防 revert 中途介入)
    $record = $brModelClass::query()
        ->where('id', $betRecordId)
        ->lockForUpdate()
        ->firstOrFail();

    // 2. 鎖內冪等檢查
    if (BetRecordRecoveryLog::isCurrentlyRecovered($vendorId, $record->id)) {
        return;
    }

    // 3. 顯式檢查 TBR 不存在(命中既有 = 該走 Type B 專屬 patch,不是本範本)
    $existingTbr = TotalBetRecord::query()
        ->where('bet_record_id', $record->id)
        ->where('vendor_id', $vendorId)
        ->lockForUpdate()
        ->first();
    if ($existingTbr) {
        throw new \RuntimeException(
            "BR {$record->id} 已有 TBR={$existingTbr->id};Type B 修既有 TBR 必須走專屬 patch"
        );
    }

    $before = $this->snapshot($record);

    // 4. 翻 status + 結算欄位
    //    ★ status 由 $settledStatus 帶入(不可硬編 1;各 vendor enum 不同,見上方警告)
    $record->update([
        'status'      => $settledStatus,
        'win'         => $vendorTruth['win'],
        'win_lose'    => $vendorTruth['win_lose'],
        'payout_time' => $vendorTruth['payout_time'],
        // bet / valid_bet 通常 BR 已有正確值;若需修正注意走 Type B
    ]);

    // 5. 建 TBR
    //    ★ 強烈建議優先用 `TotalBetRecordService` — 它會處理 game_code→game_id resolution、
    //      agent_group / customized_setting / currency 等衍生欄位,降低範本失誤風險
    //
    //    若手動 create,必須先解析 game_id(多數 vendor BR 只存 game_code,非 game_id):
    //      $gameId = Game::where('vendor_id', $vendorId)
    //          ->where('game_type', $record->game_code)   // 部分 vendor 欄位名是 game_code
    //          ->value('id');
    //      abort_if(! $gameId, 500, "game_code={$record->game_code} 找不到對應 game,abort");
    //
    //    以下欄位是 schema/對帳必填,缺任一會 INSERT 失敗或讓 SBR 聚合到錯分組
    $tbr = TotalBetRecord::create([
        'bet_record_id' => $record->id,                       // → BR.id (lineage)
        'vendor_id'     => $vendorId,
        'member_id'     => $record->member_id,                 // ← Step 2.5 分組驗證 key
        'game_id'       => $gameId,                            // ★ 必須由 game_code resolve(見上),不可直接 $record->game_id
        'reference_no'  => (string) $record->bet_id,           // ★ TBR↔BR lineage(R14 finding)
        'bet'           => $vendorTruth['bet'],                // raw bet,對齊 BR.raw_bet
        'valid_bet'     => $vendorTruth['valid_bet'],          // SBR.bet 從此聚合
        'win_lose'      => $vendorTruth['win_lose'],
        'rolling_chip'  => $vendorTruth['rolling_chip'] ?? 0,  // 0 視 vendor 而定
        'commission'    => $vendorTruth['commission']   ?? 0,
        'jackpot'       => $vendorTruth['jackpot']      ?? 0,
        'bet_time'      => $vendorTruth['bet_time'],
        'payout_time'   => $vendorTruth['payout_time'],
        'summary_time'  => $vendorTruth['payout_time'],        // SBR 寫入時刻,多數 vendor=payout_time
        // 其餘 vendor 卡定義的欄位(currency_id / agent_group_id / customized_setting_group_id 等)
    ]);

    // ★ 對 wrong-game 防線:Step 2.5 驗證之外,範本內額外 check BR.game_code ↔ TBR.game_id mapping
    //   confirm $gameId 對應的 Game.game_type 等於 $record->game_code(避免 resolve 錯)
    //   (若用 TotalBetRecordService 則不必,service 內有 mapping)

    // 6. ★ 通用範本不動 wallet(理由見 §2.0 警告)
    //    Type C「翻 status」尤其要小心:玩家投注時 wallet 已扣;
    //    若 settle 又派 win 進 wallet,等同對「結算還沒完成的注單」做了完整生命週期 — 對 TW 會雙重入帳

    // 7. ★寫 recovery_logs(before=未結算狀態快照;balance_diff=0 因不動 wallet)
    BetRecordRecoveryLog::create([
        'vendor_id'     => $vendorId,
        'bet_record_id' => $record->id,
        'bet_id'        => (string) $record->bet_id,
        'member_id'     => $record->member_id,
        'type'          => 'recover',
        'performed_by'  => Auth::id() ?? 'system',
        'reason'        => $reason,
        'source'        => $batchSource,
        'before'        => $before,
        'after'         => array_merge($record->fresh()->toArray(), [
            '_meta' => [
                'total_bet_record_id' => $tbr->id,
                'transaction_log_id'  => 0,                    // 通用範本不動 wallet → 0
                'is_multiple_wallet'  => false,
            ],
        ]),
        'balance_diff'  => '0',                                // 通用範本不動 wallet → 0
    ]);
});
```

---

### 2.4 wallet 派彩決策（vendor-specific patch 用，不可放通用 SOP）

要在補帳路徑動 wallet 必須**先回答**：

| 問題 | 為什麼 |
|---|---|
| 1. 該 vendor 是 SW 還是 TW？ | TW 的玩家錢包不在平台、補資料時不該動平台錢包；SW 才有平台錢包 |
| 2. 該注單 wallet 是否已扣？已派？ | 已扣未派（補 settlement）→ 派 win；已扣已派（純資料層補建）→ 不動；未扣未派（漏單）→ 看業務決策 |
| 3. 該 vendor 有沒有對應 `TransactionEnum` 類型？ | SW 用 `SEAMLESS_WALLET_BET_SETTLE` / TW 用 `TRANSFER_*` 系列；錯 type 會讓報表口徑錯亂 |
| 4. revert 反向 wallet 邏輯怎麼做？ | 通用 revert 用 `balance_diff` 反向扣回，但若 patch 的 wallet 變動跟 `balance_diff` 對不上，會反扣錯金額 |
| 5. 跨 vendor 多錢包配置？ | `isMultipleWallet=true/false` 必須跟該 vendor 實際配置一致，不一致會抓錯 wallet |

回答完上 5 題、業務確認後，在 vendor patch 加 wallet 段：

```php
// vendor-specific patch 才用;不可放通用 SOP
$vendorInfo = VendorInfo::query()->where('vendor_id', $vendorId)->firstOrFail();
$walletDto = (new WalletService)->getMemberLockedWallets($member, $vendorInfo);

$walletDelta = '...';  // ★ 必須業務明確指定、非自動從 vendorTruth['win']
$transactionLogId = 0;
if (Common::numCompare($walletDelta, '0', 4) !== 0) {
    $beforeBalance = (string) $walletDto->wallet->balance;
    $afterBalance  = Common::numAdd($beforeBalance, $walletDelta, 4);
    $walletDto->wallet->update(['balance' => $afterBalance]);

    $logService = new TransactionLogCreateService(
        member: $member,
        vendorInfo: $vendorInfo,
        isMultipleWallet: false,           // 依該 vendor 配置
        account: $vendorAccount,
    );
    $logService->setType(TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value);  // 依 SW/TW 換對應 enum
    $tx = $logService->create($walletDelta, $beforeBalance, $afterBalance);
    $transactionLogId = $tx->id;
}

// ★ 寫到 patch 自帶的 audit 表(不是通用 bet_record_recovery_logs):
//   - 通用 revert 不支援 delete BR / restore 既有 TBR(見 §2.0 警告)
//   - 若 patch wallet 變動寫進通用 log,未來通用 revert 會誤刪 TBR / 留孤兒 BR / 反扣錯金額
//   - patch 必須建自己的 audit table 記:
//     {batch_id, br_id, tbr_id, member_id, vendor_id, balance_before, balance_after,
//      wallet_delta, transaction_log_id, is_multiple_wallet, before_snapshot, after_snapshot}
//   - patch 必須提供獨立 revert 子命令:
//     反向 wallet 變動(用 balance_before)、反向 transaction log、視情境 delete 新 TBR 或 restore 舊 TBR、
//     視 Type A/B 處理 BR (delete BR / restore BR fields)
```

實際範例：`app/Console/Commands/Vendor/KissH5SW/Maintenance/RepairOrphans.php`（搜 `getMemberLockedWallets` 定位 wallet 段）（wallet/transaction 處理；audit 機制是 patch-specific）

### 2.3 鎖定 + 冪等的共同原則

> 本節原則適用於 **Type C 走通用 recovery_logs** 與 **Type A/B vendor patch** 兩種路徑。差異標註於各條。

1. **只有 Type C 可寫通用 `bet_record_recovery_logs`**；Type A / B 必須走 vendor-specific patch（見 §2.0 警告與 §2.1 範本警告）
2. **transaction 開頭就 `lockForUpdate()` 鎖 BR**：
   - Type C：by-id（BR 已存在）+ 鎖內呼叫 `isCurrentlyRecovered($vendorId, $record->id)`
   - Type A patch：by `bet_id` lock + 依賴 unique 索引 catch 1062；命中既有 BR 視為冪等
3. **TBR 必須顯式 SELECT + lockForUpdate**（不可用 `updateOrCreate` — 會掩蓋 Type B 走錯範本）
4. **`source` 欄位帶 batch 唯一識別**（如 `csv:filename:row_N` / `patch:ticket-1234:step-2`）— 撤回時可追蹤同一批
5. **鎖定順序固定**：`BR → TBR → wallet`（與 revert 一致以避免 deadlock）
6. **Type A patch 必須先確認 BR 表有 `unique(bet_id)` 或 `unique(vendor_id, bet_id)` 索引**；否則 catch 1062 路徑無效，並行兩個 worker 會各建一筆 BR
7. **通用 Type C `before` 欄位**：schema NOT NULL JSON、必填「未結算狀態快照」供 revert 倒回；vendor patch 自帶 audit 表時依 patch 設計（不受此 schema 限制）
8. **通用範本（Type C）不動 wallet**：跨 SW/TW vendor、各 vendor 補帳語意不同，預設 `balance_diff = '0'`、`transaction_log_id = 0`；要動 wallet 走 §2.4 vendor patch 流程
9. **要動 wallet 時必須走專案 service**：禁用 `$member->wallet()` + `$wallet->add()`（`MemberWallet` 無 `add()`，會 rollback）；改用 `WalletService::getMemberLockedWallets($member, $vendorInfo)` → `$walletDto->wallet->update(['balance' => $afterBalance])` → `TransactionLogCreateService::create($diff, $beforeBalance, $afterBalance)`。實際範例見 `app/Console/Commands/Vendor/KissH5SW/Maintenance/RepairOrphans.php`（搜 `getMemberLockedWallets` 定位 wallet 段）
10. **通用 Type C `after._meta` 必填三件套**：`total_bet_record_id`（revert 刪 TBR 用）、`transaction_log_id`（不動 wallet 時填 0、動 wallet 時填實際 id）、`is_multiple_wallet`（讓 revert 還原時用同樣 wallet 配置）
11. **Type A / B patch 必須自帶 revert 命令**：通用 `bet-record-recovery:revert` 不能處理 BR delete 或既有 TBR restore；patch 須在自己的 audit 表記錄 inserted_br_id / old_tbr_snapshot，並提供獨立 revert 子命令配 drift check
12. **無縫錢包（單一錢包）遊戲商補卡單，寫入路徑三件事都要做**：「卡單」指 BR 一直停在未結算、等不到結束回調。照遊戲商卡片的規範，補帳腳本的實寫分支要同時：
    - 更新 BR（`status`、`payout_time` 等必要欄位）
    - 建立 TBR（照第 3 點顯式 SELECT 確認不存在再 INSERT）
    - 補一筆對應結束事件的 `{tname}_deal_records`，內容照「回調沒失敗時該寫的樣子」，`raw` 沿用原回調內容（從 GCP log 或遊戲商報表取）。例：PTSW 是 `type=gameRoundResult`、`transaction_code=NULL`，沒有派彩時 `bet_amount`、`win_amount`、`jackpot` 為 0、`applied_at=NULL`。

    `after._meta` 除了第 10 點三件套，再記新 deal_record 的 id 與遊戲商結算請求 id。只補 BR 與 TBR，金額對得上，但用 deal_records 重建 BR 或做稽核的工具會把那局判成還沒結束。通用 revert（`bet-record-recovery:revert`）不會刪這筆 deal_record，人工撤回時要依 `after._meta` 一併刪掉。

---

## 3. Model helper

```php
BetRecordRecoveryLog::latestFor($vendorId, $betRecordId);
BetRecordRecoveryLog::isCurrentlyRecovered($vendorId, $betRecordId);
BetRecordRecoveryLog::suggestedNextAction($vendorId, $betRecordId);
$log->isReverted();
$log->revert();      // HasOne 對應 revert 事件
$log->recoverOf();   // BelongsTo 對應被撤回的 recover
```

---

## 4. 撤回（跨 vendor 通用命令）

> **⚠️ revert 命令安全規則**（與 SBR 實寫同等級）：
> 1. **所有 artisan 必須包 `docker exec`**：bare `php artisan` 可能打到 host / default `.env` / 錯白標
> 2. **必須顯式注入 `DB_CONNECTION=<env>`**：對應 [`whitelabel-container-exec`](../../whitelabel-container-exec/SKILL.md) skill
> 3. **`--execute` 是破壞性操作**：會反扣 `balance_diff`、還原 BR、刪除 `after._meta.total_bet_record_id` 指向的 TBR；執行前必須**文字 confirm 環境 + log-ids + reason**，操作者打「yes」才執行
> 4. **dry-run（無 `--execute`）也要包 docker wrapper**：dry-run 會讀 log 並預演;在錯環境跑會把錯資料當預演對象，引導出錯判斷

```bash
# Dry-run(強烈建議先跑、含呆帳預演與玩家彙總)
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan bet-record-recovery:revert --log-ids=123,124,125"

# 真執行(必填 --reason、必須先 confirm 環境/log-ids/reason)
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan bet-record-recovery:revert \
     --log-ids=123,124,125 \
     --execute --reason='客服票號 #5678'"

# 從 CSV 讀 log id(取第一欄)
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan bet-record-recovery:revert --log-id-csv=_local/revert_ids.csv"

# 加呆帳上限保險閥
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan bet-record-recovery:revert \
     --log-ids=... --execute --reason='...' --max-bad-debt=100"
```

revert 行為：
1. **drift check**：current 必須 = recover 的 after 快照（否則拒絕）
2. 鎖 record + 鎖錢包（**允許扣到負數**）
3. 用 before 快照還原 BR
4. 反向 TransactionLog（玩家錢包對應扣回）
5. 刪 TBR（用 `after._meta.total_bet_record_id` 定位）
6. 寫 type=revert 事件、`parent_log_id` 指向被撤的 recover

---

## 5. 不可違背的規則

| 規則 | 後果 |
|---|---|
| 直接 UPDATE BR/TBR 不寫 log | 失去可還原性，下次 revert 會 drift check 失敗 |
| 冪等檢查 (`isCurrentlyRecovered`) 在 `lockForUpdate` 之外 | 並行情境下兩個 worker 各自看到「未 recover」→ 重複加錢；檢查必須在鎖內 |
| 用 `TotalBetRecord::updateOrCreate()` 寫 TBR | 命中既有 TBR 走 update 路徑後，通用 revert 會 DELETE 該 TBR（誤刪原合法資料）；必須顯式 SELECT 確認不存在再 INSERT |
| 修既有 TBR 走通用 recover | 通用 revert 不支援 TBR restore；必須另寫專屬 patch 自帶 backup/restore |
| revert 後想再 recover 改舊 log | 必須建立**新的 recover**，parent_log_id 會分流 |
| 修 BR 與寫 log 不在同一 transaction | 部分失敗會留下不一致狀態 |
| 鎖定順序非 `BR → TBR → wallet` | 與 revert 鎖順不一致 → deadlock |
| `source` 欄位留空 | 同批重跑時無法精準去重；難追蹤一批補帳的源頭做 revert |

---

## 6. deal_records 沖銷事件與 applied_at NULL 陷阱

> 用 deal_records 重建 BR.bet 時必讀。適用 PTSW / PlayTech、TT 系列等單錢包 vendor。

部分單錢包 vendor 在 settlement / refund 流程**不直接 UPDATE BR**，而是往 `deal_records` 追加沖銷事件：

| 事件 type | bet_amount 語意 | applied_at | 對 BR.bet 的影響 |
|---|---|---|---|
| `bet` | 正值（placeBet 金額） | 真實時刻（落帳成功瞬間） | 加 |
| `gameRoundResult`（settle / refund 沖銷） | **負值**（-placeBet 金額，沖銷用） | 通常 **NULL**（settle 流程不更新此欄位） | 減 |

「該 BR 當下的 bet」= `SUM(deal_records.bet_amount)` **含正負兩種事件**，refund 注單最終會被沖銷為 0。

### 錯誤 SQL 範例與後果

```sql
-- ❌ 漏算 refund 沖銷：只取 placeBet 事件
SELECT SUM(bet_amount)
FROM deal_records
WHERE bet_record_id = ?
  AND type = 'bet'
  AND applied_at IS NOT NULL;
-- 後果：對 refund 注單，expected_bet 算成 placeBet 金額（例如 3.5），
-- 但實際 BR.bet 已被 settle 沖銷為 0 → 補帳補過頭

-- ❌ 用 applied_at 切時間 + 含 gameRoundResult：
SELECT SUM(bet_amount)
FROM deal_records
WHERE bet_record_id = ?
  AND applied_at >= ? AND applied_at < ?;
-- 後果：gameRoundResult 的 applied_at=NULL 全被排除，等同前一錯誤
```

### 正確 SQL 範例

兼顧沖銷事件 + 各 type 用各自可靠的時間欄位：

```sql
SELECT SUM(bet_amount) AS expected_bet
FROM deal_records
WHERE bet_record_id = ?
  AND (
        (type = 'bet'              AND applied_at IS NOT NULL
                                   AND applied_at >= ? AND applied_at <  ?)
     OR (type = 'gameRoundResult'  AND created_at >= ? AND created_at <  ?)
  );
```

### 寫補帳 SQL 前的檢核清單

- [ ] 是否含 `type='gameRoundResult'`（或 vendor 對應的沖銷 type）的負值事件
- [ ] 是否實際追過該 vendor 的 controller `settlement()` / `refund()` / `createDealRecord()` 路徑，確認 settle/refund 寫 deal_records 時**有沒有更新 applied_at**
- [ ] 對 applied_at 可能為 NULL 的事件，時間 filter 改用 `created_at`（或其他可靠時間欄位）
- [ ] dry-run 後手動抽 1-2 筆 refund 注單對比，確認 `expected_bet = 0`（而非 placeBet 金額）

### References

| 用途 | 路徑 |
|---|---|
| controller 沖銷寫入範例 | `app/Http/Controllers/External/SingleWallets/PlayTechController.php:513-519`（REFUND 路徑寫 `bet_amount = -X`） |
| 重建 BR.bet 的 reconcile 命令 | `app/Console/Commands/Vendor/PlayTech/ReconcileBetAccumulation.php`（`collectAffectedBrs()` 方法） |

---

## 7. 關鍵檔案

| 用途 | 路徑 |
|---|---|
| 撤回審計表 migration | `database/migrations/2026_05_06_000001_create_bet_record_recovery_logs_table.php` |
| 審計 Model | `app/Models/BetRecordRecoveryLog.php` |
| recovery type enum | `app/Enums/BetRecordRecoveryTypeEnum.php` |
| 跨 vendor 撤回命令 | `app/Console/Commands/BetRecordRecovery/Revert.php` |
| TBR Service | `app/Services/TotalBetRecordService.php` |
