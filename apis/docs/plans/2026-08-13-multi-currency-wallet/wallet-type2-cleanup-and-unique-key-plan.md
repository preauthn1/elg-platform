# 多幣別錢包 第五期前置 — type=2 錢包清除與唯一鍵改三元組 實作計劃

> 本檔內容自第一期實作計劃（`multi-currency-wallet-plan.md`，已於 2026-09-18 依「落實即刪除」規則刪除，原文見 `git show 05e3a4d30:docs/plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-plan.md`）原樣搬出，只搬三節：「改唯一鍵為什麼移出第一期」、Task 9、Task 10。**未改任何規則**；文中「本文件」「本計劃」「前段」指的是原第一期計劃。
>
> 執行順序以 [`multi-currency-wallet-roadmap.md`](multi-currency-wallet-roadmap.md) 為準：唯一鍵改三元組排在切換寫入之後、會員互轉自動開戶與活動按幣別之前；type=2 錢包清除獨立排期、必須在改唯一鍵之前，且與決議㉟ 的 VWorld 停用錢包銷帳共用同一份清除清單。決議清單 [`multi-currency-wallet-decisions.md`](multi-currency-wallet-decisions.md) 決議⑯ 的逐站判定四項與影響盤點 7.6 的止血與回滾同樣適用。

---

## 改唯一鍵為什麼移出第一期（2026-08-28 RB 拍板）

### 原本怎麼安排

第一期把「`member_wallets` 唯一鍵從（會員＋類型）改成（會員＋類型＋幣別）」放在 Task 10，而直接寫這張表的 stored procedure（下稱 SP）要到第四期才改。RB 於 2026-08-26 為這個時間差做過一次裁決：**讓 SP 維持寫 `currency_id = 0`，之後再校正**。

### 那個安排的已知代價

唯一鍵一旦改成三元組，同一個會員的同一種錢包會出現兩列：

| 寫入來源 | 填入的 `currency_id` | 為什麼 |
|---|---|---|
| APIs 經 `WalletService` | 該會員的組織幣別（例如 1） | Task 7 之後的正確值 |
| SP 直寫 | **0** | SP 的 `INSERT` 沒有這個欄位，落預設值 |

**兩列的鍵不同，餘額因此被分裂在兩列。** 原計劃接受這個代價，打算在第四期把 `currency_id = 0` 的列合併回正確幣別的列。

### 為什麼改

餘額分裂的成因**不是加欄位，是改唯一鍵**。只要唯一鍵還是兩元組：

- SP 以（會員＋類型）就能找到 `WalletService` 建的那一列，直接更新餘額；
- SP 不會碰 `currency_id`（該欄不在它的 `SET` 清單裡），`WalletService` 填的值原樣保留；
- **全程只有一列，餘額不會分裂。**

而加欄位本身對現有功能是完全透明的：`ALGORITHM=INSTANT`（Gate 0 於 2026-08-19 實測通過）、有預設值、沒有任何既有邏輯讀它。**新欄位在切換前確實「沒用到」。**

⇒ 把改唯一鍵推遲到所有寫入路徑都填對幣別之後，這個代價就不存在，第四期的「合併分裂列」也不用做。

### 新的執行順序

| 步驟 | 動作 | 對現有功能的影響 | 回退方式 |
|---|---|---|---|
| 1 | Task 6：`member_wallets` 加 `currency_id` | 無（透明） | 欄位留著或 DROP |
| 2 | Task 7：`WalletService` 填正確幣別 | 無（唯一鍵仍兩元組，不會撞鍵） | 改回不填 |
| 3 | **第四期**：14 支 SP 也改成填正確幣別 | 無（同上；填錯也不撞鍵，可逐支驗證） | 逐支還原 |
| 4 | **第四期**：稽核確認活躍列的 `currency_id = 0` 歸零 | 純唯讀 | — |
| 5 | **Task 10（移到此處）**：改唯一鍵為三元組 | 資料已正確，改鍵是「收緊」不是「改變語意」 | 🔴 仍是破壞性 DDL，需重建索引 |
| 6 | 第五期：開放多幣別 | 行為切換點 | 開關 |

### 這樣改的代價

1. **多幣別上線時點被推後** —— 唯一鍵沒改成三元組之前，同一會員同一類型只能有一個錢包。但開放多幣別本來就排在第五期。
2. **14 支 SP 的改造要排在改唯一鍵之前** —— 比原計劃提前，但這裡的改造是「在 `SET` 清單加一個欄位」，不是改寫入目標，且填錯不會撞鍵。
3. **Task 9（type=2 清除）失去急迫性** —— 它原本是「改唯一鍵前先減量」的前置。清除本身仍該做（垃圾資料會持續增長），但可與第一期解耦、獨立排期。

### 事實校正：直寫 `member_wallets` 的 SP 是 14 支不是 19 支

> ⚠️ **09-03 再補**：14 支是 `database/procedures/` 的 repo 口徑。六站生產 `information_schema.ROUTINES` 實查每站 16～19 支（TPower／VWorld 恰為 19，與早期「19 支」只是巧合），VWorld 多出 repo 沒有的 `SP_event_deposit_bak`／`SP_event_deposit_neil`／`ADMIN_reissue_vip_level_reward`（皆 INSERT）等副本。**Task 10 閘門的完成判準以六站實查清單為準，不是 repo 的 14 支**，見影響盤點第一節「09-03 六站生產實查」。

本文件多處寫「19 支」。2026-08-28 以 `INSERT INTO member_wallets` 與 `UPDATE member_wallets` 精確比對 `database/procedures/`，實得 **14 支**：

```
SP_event_deposit / SP_event_deposit_for_cs / SP_event_deposit_wallet_for_cs
SP_event_gift_point / SP_event_register / SP_event_register_tmp
SP_payout_event_invite / SP_payout_vip_level_rewards
SP_payout_three_hierarchy_bonus / SP_payout_slot_competition_auto
SP_payout_lottery_ticket_reward_auto / SP_payout_refresh_ranking_sub
SP_reset_refresh_ranking_wallet / SP_calc_member_vip_deposit_amount
```

受影響清單寫「寫入錢包的 SP 15 支」，差額是 `SP_event_first_deposit`——它只寫中央錢包，不寫 `member_wallets`。

### 🔴 改這 14 支時必須一併修正的既有缺陷（2026-08-28 RB 同意併入）

逐支驗證寫入欄位時發現，**其中 5 支的 `WHERE` 條件用的是主鍵 `id` 而不是 `member_id`**：

| 錯法 | 影響的 SP |
|---|---|
| `SELECT` 與 `UPDATE` 都用 `WHERE id = MemberId` | `SP_event_gift_point`、`SP_event_register`、`SP_event_register_tmp`、`SP_calc_member_vip_deposit_amount` |
| `SELECT` 用 `member_id`（正確）但 `UPDATE` 用 `id`，**且 `UPDATE` 寫入的是恆為 `NULL` 的 `BeforeBalance` 而非本分支的 `BeforeRollingChip`** | `SP_event_deposit`（`PointType = 2` 分支） |

其餘 9 支寫法正確。**最強旁證**：`SP_event_deposit` 用 `id`，但同系列的 `SP_event_deposit_for_cs` 與 `_wallet_for_cs` 用 `member_id`——同邏輯兩種寫法，看起來是某次修正只改了衍生版。

**目前的實際影響（2026-08-28 TPower 實測）**：

- `member_wallets.balance` 是 `NOT NULL`、`@@sql_mode` 含 `STRICT_ALL_TABLES` ⇒ 錯誤的更新**會報錯而不是寫入壞資料**
- 匹配 0 列時 `UPDATE` 無效果 ⇒ **獎金沒發但 log 照記**（靜默失敗）
- 矛盾檢測：近 7 天 521 個相關會員**零真實矛盾** ⇒ 無證據顯示正在被觸發
- 碰撞可觸發狀態：禮碼錢包 1,114 個會員（有餘額 35 筆、合計 400.89）

**處置（RB 2026-08-28 同意）**：非緊急，**併入本期改這 14 支 SP 時一併修正 `WHERE` 條件與變數誤用**。

⚠️ **動手前必須先過 PM**：修 `WHERE` 條件會改變現有行為（本來靜默失敗、改成真的發獎金），且這幾支屬活動類 SP，依專案的活動修改紀律不可自行決定。

📖 完整證據、逐支差異與未驗證項見記憶 `project_sp_member_wallets_wrong_where_clause.md`。

---


---

## Task 9：type=2 錢包備份與清除 — ⏸ 已移出第一期

**依據**：08-18 會議決議⑧（`member_wallets` type=1／2 整批清除，VWorld 1,053 個會員合計 121,991.62 元銷帳；執行前四件事：備份快照與清除記錄、知會業務與財務側留痕、排在改唯一鍵之前、同時關閉自動建立來源）。⚠️ **該決議的第三個前置「排在 1.1 改唯一鍵之前」的時序已因 Task 10 移出第一期而改變**——改唯一鍵移到第四期之後，本 task 只需排在它之前即可

> 🔴 **2026-08-28 RB 拍板：本 task 不在第一期範圍內，改為獨立排期。**
>
> **為什麼移出**：它原本的角色是「改唯一鍵之前先減量，避免垃圾資料帶進新結構」。改唯一鍵（Task 10）移到第四期之後，這個時間壓力就消失了。
>
> **移出之後仍然要做**：`getMemberAllWallets()` 每次呼叫就建一筆 type=2 的問題由 Task 8 止血（維持在第一期），但**存量**（TPower 69,548 筆／VWorld 74,785 筆）不會自己消失。
>
> **執行時點的唯一硬性要求**：必須在 Task 10 改唯一鍵**之前**完成——否則垃圾列會帶著 `currency_id = 0` 進入三元組唯一鍵，變成無法自動合併的孤兒列。
>
> **本 task 的定義完整保留在下方**，內容不需改寫，只是執行時點與第一期解耦。

**Files:**
- Create: `database/migrations/2026_08_XX_HHMMSS_create_deprecated_member_wallets_backup_table.php`
- Create: `app/Console/Commands/Patch/PurgeDeprecatedMemberWallets.php`
- Test: `tests/Feature/Console/PurgeDeprecatedMemberWalletsTest.php`

**Interfaces:**
- Consumes: Task 8（建立來源已關閉）
- Produces: `php artisan wallet:purge-deprecated --dry-run` / `--execute` — 備份 type=2 列到備份表後刪除

**RB 裁決（2026-08-26）**：**以建立資料表備份來解決留痕問題。**（此裁決仍有效，2026-08-28 改變的只有執行時點。）

**清除範圍（2026-08-26 實測）：**

| 站點 | type=2 筆數 | 有餘額筆數 | 餘額合計 |
|---|---|---|---|
| TPower | 69,548 | 0 | 0 |
| VWorld | 74,785 | **1,053** | **121,991.62** |

`wallet_type_id` 為 1 與 1004 的列在兩站**都是 0 筆**，所以實際清除對象只有 type=2。

🔴 **有餘額的 1,053 筆是資金銷帳**：備份表必須能單獨查出這批（會員、金額、時間），執行後知會業務／財務側。命令的報告輸出要把「零餘額」與「有餘額」分開統計，不可只報總筆數。

- [ ] **Step 1：寫測試**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\WalletEnum;
use App\Models\MemberWallet;
use Tests\TestCase;

/**
 * 廢除錢包清除命令測試
 *
 * php artisan test tests/Feature/Console/PurgeDeprecatedMemberWalletsTest.php
 */
class PurgeDeprecatedMemberWalletsTest extends TestCase
{
    public function test_dry_run_is_the_default_and_changes_nothing()
    {
        // Arrange
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME,
            'currency_id' => 0,
            'balance' => 0,
        ]);

        // Act & Assert
        $this->artisan('wallet:purge-deprecated')
            ->expectsOutputToContain('試跑')
            ->assertExitCode(0);

        $this->assertSame(1, MemberWallet::query()
            ->where('wallet_type_id', WalletEnum::CODE_GAME)
            ->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('deprecated_member_wallets_backup')->count());
    }

    public function test_execute_backs_up_before_deleting()
    {
        // Arrange
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $wallet = MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME,
            'currency_id' => 0,
            'balance' => '123.4500000000',
        ]);

        // Act
        $this->artisan('wallet:purge-deprecated --execute')
            ->expectsOutputToContain('有餘額 1 筆')
            ->assertExitCode(0);

        // Assert：原表已刪、備份表留有完整資料
        $this->assertSame(0, MemberWallet::query()
            ->where('wallet_type_id', WalletEnum::CODE_GAME)
            ->count());

        $backup = \Illuminate\Support\Facades\DB::table('deprecated_member_wallets_backup')
            ->where('id', $wallet->id)
            ->first();

        $this->assertNotNull($backup);
        $this->assertSame($member->id, $backup->member_id);
        $this->assertSame('123.4500000000', $backup->balance);
        $this->assertNotNull($backup->backed_up_at);
    }

    public function test_it_does_not_touch_kept_wallet_types()
    {
        // Arrange：禮碼錢包（保留類型）不可被清掉
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP,
            'currency_id' => $agent->organization->currency_id,
            'balance' => '500.0000000000',
        ]);

        // Act
        $this->artisan('wallet:purge-deprecated --execute')->assertExitCode(0);

        // Assert
        $this->assertSame(1, MemberWallet::query()
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP)
            ->count());
    }

    public function test_it_is_rerunnable()
    {
        // Act：連跑兩次不應報錯，也不該重複備份
        $this->artisan('wallet:purge-deprecated --execute')->assertExitCode(0);
        $this->artisan('wallet:purge-deprecated --execute')->assertExitCode(0);

        // Assert
        $this->assertSame(0, MemberWallet::query()
            ->whereIn('wallet_type_id', [WalletEnum::CODE_GAME, WalletEnum::CODE_SHOP])
            ->count());
    }
}
```

- [ ] **Step 2：跑測試確認失敗**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact --filter=PurgeDeprecatedMemberWalletsTest"
```

Expected: FAIL — 命令與備份表都不存在

- [ ] **Step 3：建備份表 migration**

```php
<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * 廢除錢包類型的備份表（多幣別錢包，已移出第一期，須在改唯一鍵之前完成）
     *
     * 決議⑧定調 type=1／2 整批清除；RB 2026-08-26 裁決以建立資料表備份解決留痕。
     * 本表保存清除前的完整列內容，其中有餘額者屬資金銷帳，須可單獨查出。
     *
     * 欄位刻意與 member_wallets 對齊（含原 id），以便日後需要時可原樣還原。
     */
    public function up(): void
    {
        SchemaMonitor::create('deprecated_member_wallets_backup', function (Blueprint $table) {
            $table->unsignedInteger('id')->comment('原 member_wallets.id');
            $table->integer('member_id')->index()->comment('玩家帳號');
            $table->integer('wallet_type_id')->index()->comment('錢包類型');
            $table->unsignedInteger('currency_id')->default(0)->comment('幣別');
            $table->decimal('balance', 30, 10)->default(0)->index()->comment('清除當時餘額；非 0 者屬資金銷帳');
            $table->dateTime('first_deposit')->nullable()->comment('首次儲值時間');
            $table->integer('is_bet')->default(0)->comment('當天是否投注過');
            $table->tinyInteger('status')->default(0)->comment('帳號狀態');
            $table->string('memo', 191)->nullable()->comment('方便技術記錄用');
            $table->timestamp('created_at')->nullable()->comment('原建立日期');
            $table->timestamp('updated_at')->nullable()->comment('原更新日期');
            $table->integer('admin')->default(0)->comment('原更新者');
            $table->timestamp('backed_up_at')->useCurrent()->comment('備份時間');

            $table->primary('id');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('deprecated_member_wallets_backup');
    }
};
```

⚠️ **`down()` 會刪掉稽核軌跡**。`SchemaMonitor::dropIfExists()` 刻意不吃列數白名單、超過門檻就停下（`app/Facades/SchemaMonitor.php:97-116`），這正是我們要的行為。正式站不要跑 `migrate:rollback`。

- [ ] **Step 4：寫清除命令**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use App\Enums\WalletEnum;
use App\Models\MemberWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 清除已廢除的錢包類型（多幣別錢包，已移出第一期，須在改唯一鍵之前完成）
 *
 * 決議⑬：type=2（遊戲錢包）與 1004（商城幣）廢除。清除前先完整備份到
 * deprecated_member_wallets_backup（RB 2026-08-26 裁決的留痕方式）。
 *
 * ⚠️ 執行前提：Task 8 已完成——建立來源關閉後才清，否則資料會再長回來。
 * ⚠️ 有餘額者屬資金銷帳，執行後須知會業務／財務側。
 */
class PurgeDeprecatedMemberWallets extends Command
{
    protected $signature = 'wallet:purge-deprecated
                            {--execute : 實際執行；未指定則為試跑}
                            {--chunk=1000 : 每批處理筆數}';

    protected $description = '備份並清除已廢除的錢包類型（type=2 遊戲錢包、1004 商城幣）';

    public function handle(): int
    {
        $types = array_map(
            static fn (WalletEnum $type): int => $type->value,
            WalletEnum::deprecatedTypes()
        );

        $isExecute = (bool) $this->option('execute');

        $total = MemberWallet::query()->whereIn('wallet_type_id', $types)->count();
        $withBalance = MemberWallet::query()
            ->whereIn('wallet_type_id', $types)
            ->where('balance', '<>', 0)
            ->count();
        $balanceSum = (string) MemberWallet::query()
            ->whereIn('wallet_type_id', $types)
            ->sum('balance');

        $this->info(($isExecute ? '執行' : '試跑') . "：共 {$total} 筆，其中有餘額 {$withBalance} 筆，餘額合計 {$balanceSum}");

        if ($withBalance > 0) {
            $this->warn("⚠️ 有餘額 {$withBalance} 筆屬資金銷帳，備份表可用 balance <> 0 單獨查出，執行後請知會業務／財務側");
        }

        if (!$isExecute) {
            $this->info('未指定 --execute，未變更任何資料');

            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('沒有需要清除的列');

            return self::SUCCESS;
        }

        $purged = 0;

        MemberWallet::query()
            ->whereIn('wallet_type_id', $types)
            ->chunkById((int) $this->option('chunk'), function ($wallets) use (&$purged) {
                DB::transaction(function () use ($wallets, &$purged) {
                    $rows = $wallets->map(static fn (MemberWallet $wallet): array => [
                        'id' => $wallet->id,
                        'member_id' => $wallet->member_id,
                        'wallet_type_id' => $wallet->wallet_type_id->value,
                        'currency_id' => $wallet->currency_id,
                        'balance' => $wallet->balance,
                        'first_deposit' => $wallet->first_deposit,
                        'is_bet' => $wallet->is_bet,
                        'status' => $wallet->status,
                        'memo' => $wallet->memo,
                        'created_at' => $wallet->created_at,
                        'updated_at' => $wallet->updated_at,
                        'admin' => $wallet->admin,
                    ])->all();

                    // 重跑時原 id 已在備份表，用 insertOrIgnore 保持冪等
                    DB::table('deprecated_member_wallets_backup')->insertOrIgnore($rows);

                    MemberWallet::query()
                        ->whereIn('id', $wallets->pluck('id'))
                        ->delete();

                    $purged += $wallets->count();
                });
            });

        $this->info("已備份並清除 {$purged} 筆");

        Log::info('廢除錢包清除完成', [
            'purged' => $purged,
            'with_balance' => $withBalance,
            'balance_sum' => $balanceSum,
        ]);

        return self::SUCCESS;
    }
}
```

⚠️ **這裡的 `insertOrIgnore` 是刻意的**，與 Task 3 移除的那個不同：備份表以原 `id` 為主鍵，重跑時同一列不該被備份兩次，「已存在就跳過」正是要的語意。但**不可用它取代刪除前的備份**——備份與刪除必須在同一個交易內（如上），否則備份失敗仍會刪。

⚠️ **`--execute` 屬寫入類命令**，依 `whitelabel-container-exec` 紀律，對任一白標實際執行前須先文字 confirm，且**每站分開跑、逐站確認筆數**。

- [ ] **Step 5：跑測試確認通過**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan migrate --env=testing"
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact --filter=PurgeDeprecatedMemberWalletsTest"
```

Expected: PASS（4 tests）

- [ ] **Step 6：全套測試 ＋ 靜態分析**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && composer pint && composer stan && composer phpmd"
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact"
```

- [ ] **Step 7：Commit**

```bash
cd /Users/rb/Workspace/APIs-multi-currency-wallet
git add database/migrations/ app/Console/Commands/Patch/ tests/Feature/Console/
git commit -m "feat(wallet): 新增廢除錢包備份表與清除命令 target/tickets#4250"
```

- [ ] **Step 8：正式站執行（ops，逐站確認）**

⚠️ 這一步**不是程式碼交付，是資料操作**，須在部署後由 RB 確認再跑。每站先 `--dry-run`（預設）核對筆數與實測數字相符，再加 `--execute`：

```bash
# 先試跑核對（以 TPower 為例，預期 69,548 筆／有餘額 0 筆）
docker exec -e DB_CONNECTION=TPower php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan wallet:purge-deprecated"
```

VWorld 預期 74,785 筆／**有餘額 1,053 筆／合計 121,991.62** ⇒ 這站執行後必須知會業務／財務側。

---

## Task 10：唯一鍵改三元組 — ⏸ 已移出第一期，移到第四期之後

**依據**：設計決策 2（唯一鍵改成「會員＋錢包類型＋幣別」三個一組）。⚠️ **08-28 RB 拍板移出第一期**，理由是餘額分裂的成因是改唯一鍵而非加欄位——推遲它可同時消除過渡期分裂與後續的合併分裂列

> 🔴 **2026-08-28 RB 拍板：本 task 不在第一期範圍內。它是整個第一期唯一一個會改變 `member_wallets` 既有結構語意的動作，移出後第一期對現有錢包完全透明。**
>
> 完整理由見本文件前段「改唯一鍵為什麼移出第一期」。一句話：**餘額分裂的成因是改唯一鍵，不是加欄位**；把這一步推到所有寫入路徑都填對幣別之後，分裂就不會發生。

**Files:**
- Create: `database/migrations/2026_08_XX_HHMMSS_change_member_wallets_unique_index.php`
- Test: `tests/Unit/Services/WalletServiceTest.php`

**Interfaces:**
- Consumes: Task 4 的守衛測試（確認無漏網直寫）、Task 7 的帶幣別寫入、Task 9（type=2 已停建並清除）、**第四期的 14 支 SP 改造**
- Produces: `member_wallets` 唯一鍵 `(member_id, wallet_type_id, currency_id)`

🔴 **前置條件（2026-08-28 更新，第 3 項為新增）**：
1. **Task 9 已在該站執行**：type=2 存量列已清除、建立來源已關閉（Task 8）。避免垃圾資料帶著 `currency_id = 0` 進入三元組唯一鍵，變成無法自動合併的孤兒列。
2. **Task 4 守衛測試綠**：確認 APIs 版控內已無繞過 `WalletService` 的寫入。
3. 🔴 **14 支直寫 SP 已全部改成填正確幣別，且稽核確認活躍列的 `currency_id = 0` 已歸零**（⚠️ 09-03：「14 支」是 repo 口徑；生產每站 16～19 支、含 repo 沒有的 INSERT 副本，本項以六站實查清單全數改完為準，否則副本會繼續寫幣別 0——影響盤點第一節）。「活躍列」須定義為**全表 `currency_id = 0` 歸零**或明訂殘列處置（零餘額殘列改鍵後仍會被二元組讀取撈到）。這是本次調整的核心：**在這一項滿足之前執行本 task，會直接造成會員餘額分裂。**
4. 🔴 **該站舊 repo 的 `member_wallets` 建立點已全部帶幣別並發版**（09-03 圓桌新增，timo＋rorin 交叉命中；**RB 2026-09-04 認可**，決議⑯）：四個舊 repo 加 `target/support` 至少 30 個建立點（單分支下界，見影響盤點第七節 7.1）若未改完，改鍵後每天約 700 筆分裂列（由近 30 天 21,246 筆推得的量級）、不報錯無告警。**本閘門一律逐站判定**（第 1 項本來就是逐站，第 3 項改為「該站 SP 清單已改完」）：該站舊 repo 對應分支已發版＋該站 SP 清單（主庫 `information_schema.EVENTS`＋SP 互相 `CALL` 交叉後仍有呼叫者的）已改完＋該站「同 `(member_id, wallet_type_id)` 多列」稽核為零＋該站兩元組唯一索引存在且 `SHOW CREATE TABLE` 欄位集合已比對。`DROP INDEX` 與 `ADD UNIQUE` 必須同一句 `ALTER TABLE`。止血與回滾程序見影響盤點 7.6；本 task 的 `down()` 草稿在重複列存在時會撞 1062 且索引名寫死，須另開單修正。

### 為什麼推翻 2026-08-26 的裁決

**原裁決**：讓 SP 維持寫 `currency_id = 0`，唯一鍵照常在第一期改，之後在第四期針對 `currency_id = 0` 的資料做校正與合併。

**原裁決的代價（當時已知情接受）**：唯一鍵改完後，同一會員的同一錢包類型會出現兩列——

| 來源 | `currency_id` | 說明 |
|---|---|---|
| APIs 經 `WalletService` | 該會員的組織幣別 | Task 7 之後的正確值 |
| 14 支 SP 直寫 | **0** | SP 的 `INSERT` 沒有這個欄位，落預設值 |

兩列的鍵不同，**餘額因此被分裂在兩列**。原本打算在第四期把 0 的那列合併回去（餘額相加後刪除）。

**為什麼改**：這個代價完全來自「唯一鍵已經是三元組，但寫入端還沒全部填值」這段時間差。只要唯一鍵還是兩元組，SP 以（會員＋類型）就能找到 `WalletService` 建的那一列直接更新餘額，而且它不會碰 `currency_id`（該欄不在它的 `SET` 清單裡）——**全程只有一列，不會分裂**。把改唯一鍵推遲到 SP 改完之後，這個代價與第四期的「合併分裂列」工作一起消失。

**因此以下三段舊敘述作廢**（保留在此供追溯，執行時不適用）：

- ~~「過渡期的已知後果：餘額被分裂在兩列，第四期再合併」~~ ⇒ 新順序下不會發生。
- ~~「這段期間不可用單筆錢包餘額直接對外顯示或做扣款判斷」~~ ⇒ 該限制源於分裂，分裂不發生就不需要這條限制。
- ~~「觀察期的每日校驗要額外統計 `currency_id = 0` 的列數與餘額合計，作為校正範圍的追蹤依據」~~ ⇒ 改為**前置檢查**：這個統計要在執行本 task **之前**歸零，是前置條件第 3 項的驗收方式，不是過渡期的追蹤指標。

⚠️ **實測修正**：線上唯一索引實名為 **`uni_member_wallets_member_id_wallet_type_id`**，與 migration 原始碼寫的 `unique_index` **不符**（`database/migrations/2025_06_19_091321_create_member_wallets_table.php:29`）。migration 必須**先偵測實際索引名**再 drop，不可寫死。

- [ ] **Step 1：寫測試——三元組唯一、跨幣別可並存**

```php
public function test_same_member_and_type_can_hold_multiple_currencies()
{
    // Arrange & Act：同會員、同類型、不同幣別
    $walletA = (new WalletService)->getOrCreateWallet(
        $this->member->id,
        WalletEnum::CODE_EVENT_V2,
        currencyId: 1
    );
    $walletB = (new WalletService)->getOrCreateWallet(
        $this->member->id,
        WalletEnum::CODE_EVENT_V2,
        currencyId: 7
    );

    // Assert：兩筆獨立錢包
    $this->assertNotSame($walletA->id, $walletB->id);
    $this->assertSame(2, MemberWallet::query()
        ->where('member_id', $this->member->id)
        ->where('wallet_type_id', WalletEnum::CODE_EVENT_V2)
        ->count());
}

public function test_duplicate_triple_is_rejected_by_database()
{
    // Arrange
    $currencyId = 7;
    MemberWallet::factory()->create([
        'member_id' => $this->member->id,
        'wallet_type_id' => WalletEnum::CODE_EVENT_V2,
        'currency_id' => $currencyId,
    ]);

    // Assert：資料庫本身要擋掉重複，不能靠應用層
    $this->expectException(\Illuminate\Database\QueryException::class);

    // Act：繞過 Service 直接插同一組三元組
    MemberWallet::query()->create([
        'member_id' => $this->member->id,
        'wallet_type_id' => WalletEnum::CODE_EVENT_V2,
        'currency_id' => $currencyId,
        'balance' => 0,
        'status' => 1,
        'admin' => 0,
    ]);
}
```

- [ ] **Step 2：跑測試確認失敗**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact --filter=WalletServiceTest"
```

Expected: FAIL — 舊的兩元組唯一鍵會擋掉「同會員同類型不同幣別」的第二筆

- [ ] **Step 3：寫 migration（先偵測索引實名）**

```php
<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 三元組唯一鍵的新名稱 */
    private const NEW_INDEX = 'uni_member_wallets_member_type_currency';

    /**
     * member_wallets 唯一鍵改為「會員＋類型＋幣別」三元組
     * （多幣別錢包，已移出第一期，排在 14 支直寫 SP 改造完成之後）
     *
     * ⚠️ 線上索引實名為 uni_member_wallets_member_id_wallet_type_id，與建表 migration
     * 原始碼寫的 unique_index 不符（各站可能不同），因此先偵測實際名稱再 drop。
     *
     * ⚠️ 改唯一鍵不能用 INSTANT（實測回 ERROR 1845），會走 INPLACE 重建索引。
     * member_wallets 兩站各 50.9 萬／34.3 萬列，量級可接受，但正式站仍須排維護窗口。
     */
    public function up(): void
    {
        $oldIndex = $this->detectExistingUniqueIndex();

        SchemaMonitor::table('member_wallets', function (Blueprint $table) use ($oldIndex) {
            if (!is_null($oldIndex)) {
                $table->dropUnique($oldIndex);
            }

            $table->unique(
                ['member_id', 'wallet_type_id', 'currency_id'],
                self::NEW_INDEX
            );
        }, maxRows: 5_000_000);
    }

    public function down(): void
    {
        SchemaMonitor::table('member_wallets', function (Blueprint $table) {
            $table->dropUnique(self::NEW_INDEX);
            $table->unique(['member_id', 'wallet_type_id'], 'unique_index');
        }, maxRows: 5_000_000);
    }

    /**
     * 偵測 member_wallets 上「只含 member_id 與 wallet_type_id」的唯一索引實名
     */
    private function detectExistingUniqueIndex(): ?string
    {
        $columnsByIndex = [];

        foreach (Schema::getIndexes('member_wallets') as $index) {
            if (!($index['unique'] ?? false)) {
                continue;
            }

            $columnsByIndex[$index['name']] = $index['columns'];
        }

        foreach ($columnsByIndex as $name => $columns) {
            sort($columns);

            if ($columns === ['member_id', 'wallet_type_id']) {
                return $name;
            }
        }

        return null;
    }
};
```

- [ ] **Step 4：跑 migration（測試庫）**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan migrate --env=testing"
```

- [ ] **Step 5：驗證索引實際狀態**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan db:table member_wallets --env=testing"
```

Expected: 出現 `uni_member_wallets_member_type_currency`（含三個欄位），舊的兩元組索引已消失

- [ ] **Step 6：跑測試確認通過**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact --filter=WalletServiceTest"
```

Expected: PASS

- [ ] **Step 7：全套測試**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact"
```

- [ ] **Step 8：Commit**

```bash
cd /Users/rb/Workspace/APIs-multi-currency-wallet
git add database/migrations/ tests/
git commit -m "feat(wallet): member_wallets 唯一鍵改為會員＋類型＋幣別三元組 target/tickets#4250"
```

---

