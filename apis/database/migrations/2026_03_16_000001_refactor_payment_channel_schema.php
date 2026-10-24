<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 金流重構 — 全 Phase Schema 異動（合併遷移）
 *
 * 對應文件：docs/金流重構計畫.md
 * 工作追蹤：docs/金流重構工作清單.md
 *
 * ┌─────────────────────────────────────────────────────────────────┐
 * │  異動資料表總覽                                                   │
 * ├──────────────────────────────────┬──────────────────────────────┤
 * │  資料表                           │  異動內容                     │
 * ├──────────────────────────────────┼──────────────────────────────┤
 * │  agent_cash_flows                │  新增複合索引 idx_group_status  │
 * │  agent_cash_flow_order_types     │  新增複合索引 idx_cashflow_status_type │
 * │                                  │  新增欄位 cost, cost_type      │
 * │  banks                           │  新增 deleted_at（軟刪除）      │
 * │  cash_flow_banks                 │  新增 min_amount, max_amount   │
 * │  agent_banks                     │  建立新表                      │
 * │  agent_bank_names                │  建立新表                      │
 * │  currencies                      │  新增 deposit_rate, withdrawal_rate │
 * │  orders                          │  ✗ 不異動（rate 已是建單快照）       │
 * │  withdrawal_orders               │  ✗ 不異動（rate 已是建單快照）       │
 * └──────────────────────────────────┴──────────────────────────────┘
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->phase1Indexes();
        $this->phase2Banks();
        $this->phase2CashFlowBanks();
        $this->phase2AgentBanks();
        $this->phase2AgentBankNames();
        $this->phase3OrderTypeCost();
        $this->phase4CurrencyRates();
    }

    public function down(): void
    {
        $this->downPhase4CurrencyRates();
        $this->downPhase3OrderTypeCost();
        SchemaMonitor::dropIfExists('agent_bank_names');
        SchemaMonitor::dropIfExists('agent_banks');
        $this->downPhase2CashFlowBanks();
        $this->downPhase2Banks();
        $this->downPhase1Indexes();
    }

    // =========================================================================
    // Phase 1 — 補齊支付渠道查詢複合索引
    // 對應：docs/金流重構計畫.md § 6. 簡化支付渠道查詢 > 必要索引
    // =========================================================================

    private function phase1Indexes(): void
    {
        // agent_cash_flows：新增 (agent_group_id, status) 複合索引
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            if (!$this->indexExists('agent_cash_flows', 'idx_group_status')) {
                $table->index(
                    ['agent_group_id', 'status'],
                    'idx_group_status',
                );
            }
        });

        // agent_cash_flow_order_types：新增 (agent_cash_flow_id, status, order_type_id) 複合索引
        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (!$this->indexExists('agent_cash_flow_order_types', 'idx_cashflow_status_type')) {
                $table->index(
                    ['agent_cash_flow_id', 'status', 'order_type_id'],
                    'idx_cashflow_status_type',
                );
            }
        });
    }

    private function downPhase1Indexes(): void
    {
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            if ($this->indexExists('agent_cash_flows', 'idx_group_status')) {
                $table->dropIndex('idx_group_status');
            }
        });

        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if ($this->indexExists('agent_cash_flow_order_types', 'idx_cashflow_status_type')) {
                $table->dropIndex('idx_cashflow_status_type');
            }
        });
    }

    // =========================================================================
    // Phase 2 — banks 表新增軟刪除支援
    // 對應：docs/金流重構計畫.md § 1. 標準銀行表
    // 目的：保護歷史訂單（withdrawal_orders.bank_id）外鍵完整性
    // =========================================================================

    private function phase2Banks(): void
    {
        SchemaMonitor::table('banks', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('banks', 'deleted_at')) {
                $table->softDeletes()->after('status');
            }
        });
    }

    private function downPhase2Banks(): void
    {
        SchemaMonitor::table('banks', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('banks', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }

    // =========================================================================
    // Phase 2 — cash_flow_banks 新增渠道層級限額
    // 對應：docs/金流重構計畫.md § 2. 三方代付與標準銀行關聯表
    // 目的：作為 agent_banks 個性化限額的上限校驗依據（由 Service 層執行）
    // =========================================================================

    private function phase2CashFlowBanks(): void
    {
        SchemaMonitor::table('cash_flow_banks', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('cash_flow_banks', 'min_amount')) {
                $table->decimal('min_amount', 20, 4)
                    ->default(0)
                    ->comment('渠道層級最低金額限制')
                    ->after('provider_bank_code');
            }

            if (!SchemaMonitor::hasColumn('cash_flow_banks', 'max_amount')) {
                $table->decimal('max_amount', 20, 4)
                    ->default(0)
                    ->comment('渠道層級最高金額限制（0 = 不限制）')
                    ->after('min_amount');
            }
        });
    }

    private function downPhase2CashFlowBanks(): void
    {
        SchemaMonitor::table('cash_flow_banks', function (Blueprint $table) {
            $columns = array_filter(
                ['min_amount', 'max_amount'],
                fn (string $col) => SchemaMonitor::hasColumn('cash_flow_banks', $col),
            );

            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }

    // =========================================================================
    // Phase 2 — 建立代理銀行表 agent_banks
    // 對應：docs/金流重構計畫.md § 3. 代理銀行表 agentBanks
    // 目的：取代 agent_cash_flows.info->min_amount/max_amount JSON 儲存方式
    //       快取 key 粒度：agent_banks:{agent_group_id}:{currency_id}:{order_type_id}
    // =========================================================================

    private function phase2AgentBanks(): void
    {
        SchemaMonitor::create('agent_banks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('agent_group_id')
                ->comment('代理群組 ID，對應 agent_groups.id');

            $table->unsignedBigInteger('bank_id')
                ->comment('標準銀行 ID，對應 banks.id');

            $table->unsignedBigInteger('cash_flow_id')
                ->comment('金流商戶 ID，對應 agent_cash_flows.id');

            $table->decimal('min_amount', 20, 4)
                ->default(0)
                ->comment('代理層級最低金額限制（不得超過 cash_flow_banks.min_amount）');

            $table->decimal('max_amount', 20, 4)
                ->default(0)
                ->comment('代理層級最高金額限制（0 = 不限制；不得超過 cash_flow_banks.max_amount）');

            $table->tinyInteger('status')
                ->default(1)
                ->comment('狀態：1=啟用, 0=停用');

            $table->timestamps();

            // 同一代理組別 + 銀行 + 金流商 只能有一筆設定
            $table->unique(
                ['agent_group_id', 'bank_id', 'cash_flow_id'],
                'uq_group_bank_cashflow',
            );

            // 前端查詢：以 (agent_group_id, cash_flow_id, status) 為過濾條件
            $table->index(
                ['agent_group_id', 'cash_flow_id', 'status'],
                'idx_group_cashflow_status',
            );
        });
    }

    // =========================================================================
    // Phase 2 — 建立代理銀行多語系名稱表 agent_bank_names
    // 對應：docs/金流重構計畫.md § 3. 代理銀行表 agentBanks
    // 目的：支援代理自訂銀行顯示名稱，由 Observer 管理快取失效
    // =========================================================================

    private function phase2AgentBankNames(): void
    {
        SchemaMonitor::create('agent_bank_names', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('agent_bank_id')
                ->comment('對應 agent_banks.id');

            $table->tinyInteger('language_id')
                ->comment('語系 ID，對應 languages.id');

            $table->string('name', 100)
                ->comment('該語系下的銀行顯示名稱');

            $table->timestamps();

            // 同一 agent_bank 同語系只能有一筆名稱
            $table->unique(
                ['agent_bank_id', 'language_id'],
                'uq_agent_bank_lang',
            );

            $table->index('agent_bank_id');
        });
    }

    // =========================================================================
    // Phase 3 — agent_cash_flow_order_types 新增渠道費率欄位
    // 對應：docs/金流重構計畫.md § 4. 渠道支援手續費率
    // ⚠️ agent_cash_flows.cost 標記為 deprecated，待份桶 & SR99 切換後移除
    // =========================================================================

    private function phase3OrderTypeCost(): void
    {
        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'cost')) {
                $table->decimal('cost', 10, 4)
                    ->default(0)
                    ->comment('費率數值（搭配 cost_type 使用）')
                    ->after('status');
            }

            if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'cost_type')) {
                $table->tinyInteger('cost_type')
                    ->default(1)
                    ->comment('費率類型：1=百分比, 2=固定金額')
                    ->after('cost');
            }
        });
    }

    private function downPhase3OrderTypeCost(): void
    {
        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            $columns = array_filter(
                ['cost', 'cost_type'],
                fn (string $col) => SchemaMonitor::hasColumn('agent_cash_flow_order_types', $col),
            );

            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }

    // =========================================================================
    // Phase 4 — currencies 表新增代收 / 代付獨立匯率欄位
    // 對應：docs/金流重構計畫.md § 5. 匯率統一在幣別管理
    // ⚠️ 主要貨幣應強制 deposit_rate = withdrawal_rate = 1（Service 層校驗）
    // ⚠️ 異動後透過 SyncExchangeRateJob（非同步 + Redis 分散式鎖）批量同步
    // =========================================================================

    private function phase4CurrencyRates(): void
    {
        SchemaMonitor::table('currencies', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('currencies', 'deposit_rate')) {
                $table->decimal('deposit_rate', 20, 8)
                    ->default(1)
                    ->comment('代收匯率（主要貨幣固定為 1）')
                    ->after('rate');
            }

            if (!SchemaMonitor::hasColumn('currencies', 'withdrawal_rate')) {
                $table->decimal('withdrawal_rate', 20, 8)
                    ->default(1)
                    ->comment('代付匯率（主要貨幣固定為 1）')
                    ->after('deposit_rate');
            }
        });
    }

    private function downPhase4CurrencyRates(): void
    {
        SchemaMonitor::table('currencies', function (Blueprint $table) {
            $columns = array_filter(
                ['deposit_rate', 'withdrawal_rate'],
                fn (string $col) => SchemaMonitor::hasColumn('currencies', $col),
            );

            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }

    // =========================================================================
    // 工具方法
    // =========================================================================

    /** 檢查索引是否已存在，避免重複建立 */
    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$index],
        )) > 0;
    }
};
