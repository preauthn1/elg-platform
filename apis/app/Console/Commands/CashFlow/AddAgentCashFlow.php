<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\Concerns\MergesProviderInfoOverrides;
use App\Console\Commands\CashFlow\Concerns\ResolvesMainCurrency;
use App\Console\Commands\CashFlow\Concerns\ResolvesProviderConfigFile;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\CashFlowBank;
use App\Models\Currency;
use App\Models\OrderType;
use App\Services\CashFlow\Bank\AgentBankSyncService;
use App\Services\Database\TableBackupService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 新增代理金流配置命令（代收支援按 deposit channel 拆分）
 *
 * 前身為 AddAgentCashFlowByChannel；舊版單筆命令與 fix:split-agent-cash-flow-by-channel
 * 遷移補丁均已退場併入本命令，本命令為唯一入口：單筆模式行為等同原舊命令、
 * 單筆→拆分的就地遷移由拆分模式偵測既有單筆記錄時觸發（--migrate-single／互動確認）。
 *
 * 🔴 「唯一入口」不只是相對於已退場的舊命令：**平台層與代理層資料一律由
 * cash-flow:sync → 本命令這條管線建立，禁止手動 INSERT cash_flows 或 agent_cash_flows、
 * 也禁止在後台逐欄敲出一份新渠道**——手敲的那份沒有 provider 配置檔背書，
 * 之後 sync 與稽核都對不上。
 *
 * ⚠️ 憑證實值永不進配置檔、命令參數或任何輸出；跨渠道搬憑證走 cash-flow:copy-credentials
 * （值不離 DB，只顯示長度與指紋）。
 *
 * ⚙️ 代收（type=1）的建立形式由操作者確認，決定順序：
 *   1. --mode 選項（split=拆分模式 / single=單筆模式）——免詢問，供腳本明確指定；
 *   2. 互動模式一律詢問拆分形式，預設建議依 provider 配置（Data/providers/{Name}.php
 *      deposit.channels）目標幣別任一 channel 是否「存在 cost key」（cost=0 也算有配置）：
 *      帶 cost → 建議拆分模式，否則建議單筆模式；
 *   3. 非互動且未指定 --mode：依上述 cost key 判定自動分流（維持舊版自動路線）。
 *   ※ provider config 無目標幣別的 deposit channel 藍圖時無從拆分，一律單筆模式。
 *
 * 【拆分模式】一商戶對一通道——唯一能對各通道配置「不同 cost」的形式：
 *   - 依 channel 數量建立多筆 AgentCashFlow，name = "({CUR}) {channel.name}" 並納入查找唯一鍵，
 *     每筆 cost = channel.cost（config 無 cost 時為 0）；
 *   - 每筆只關聯該 channel 對應 key 的父層 OrderType，且不濾 order_types.status
 *     （依 config 藍圖預建配置，避免父層渠道停用時撞查無 OrderType 而整批 rollback；
 *     會員可見性由前端 OrderTypeService 的 status=1 主控）；
 *   - 偵測到既有單筆記錄（name = "({CUR}) {cashFlow.name}"，不論舊版命令或單筆模式建立）時，
 *     經操作者確認（互動詢問或 --migrate-single）就地遷移：rename 重用該列為 channel[0]、
 *     其餘 channel 新建並繼承 rate/info/status、依 channel.key 搬移既有 ACFOT（保留手動配置）、
 *     刪除孤兒 ACFOT；遷移後僅補建缺漏配置（不覆寫既有）。未確認或非互動未帶 flag 則中止。
 *
 * 【單筆模式】一商戶對多通道——傳統單筆建立行為（等同已退場的舊版 AddAgentCashFlow）：
 *   建立一筆 AgentCashFlow（不設定 cost 欄位，全通道共用單一 cost），
 *   關聯全部啟用中（status=1）的父層 OrderType。
 *
 * 代付（type=3）不受拆分形式影響，始終維持單筆 AgentCashFlow。
 *
 * ⚠️ 過渡階段設計：
 *   不同 deposit channel 手續費 cost 不同，但目前 cost 只能配置在 agent_cash_flows.cost，
 *   只能以「每個 channel 一筆 AgentCashFlow」承載各自的 cost。
 *   未來目標將 cost 欄位下放到 agent_cash_flow_order_types，屆時即可回歸「一個 cash_flow + 一個
 *   currency = 一筆 AgentCashFlow」的單純結構，本命令也將隨之退場或重構。
 *
 * 使用範例：
 *   php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=93 --currency=IDR
 *   php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=93 --currency=IDR --dry-run
 *   php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=93 --currency=IDR --mode=split
 *   php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=93 --currency=IDR --mode=split --migrate-single
 *
 *  白標代理：
 *    Dev:            4249
 *    TP Test:        71561
 *    TP:             71541
 *    Y2W Test:       100004
 *    Y2W:            100003
 *    Alfawin77 Test: 100004
 *    Alfawin77:      100003
 *    ME77:           100005
 *    VWSG Test:      53614
 *    VWSG:           49726
 *    VW Test:        37898
 *    VW:             66829
 *    Wintoto Test:   59610
 *    Wintoto:        59457
 *
 * @knowledge rules/payment-channel-management
 */
class AddAgentCashFlow extends Command
{
    use MergesProviderInfoOverrides;
    use ResolvesMainCurrency;
    use ResolvesProviderConfigFile;

    /** 代收建立形式：拆分模式（一商戶對一通道，每筆各自 cost） */
    private const string MODE_SPLIT = 'split';

    /** 代收建立形式：單筆模式（一商戶對多通道，單一 cost） */
    private const string MODE_SINGLE = 'single';

    /**
     * 命令簽名
     *
     * @var string
     */
    protected $signature = 'agent:add-agent-cash-flow
                            {--agent= : 代理 ID}
                            {--cashflow= : 金流商 ID}
                            {--currency= : 幣別 ID 或代碼，例如：VND、SGD、3、2（支援：MYR=1, SGD=2, VND=3, IDR=6, THB=9, USDT=10, HKD=16）}
                            {--status=1 : 上下架狀態（0=停用並隱藏後台顯示, 1=啟用）；代收套在支付通道關聯、代收金流商戶固定啟用，代付套在金流商戶}
                            {--mode= : 代收建立形式（split=拆分模式：一商戶對一通道各自 cost；single=單筆模式：一商戶對多通道單一 cost；未指定時互動詢問）}
                            {--migrate-single : 拆分模式偵測到既有單筆記錄時就地遷移為拆分形式（非互動模式必須帶此 flag 才會遷移）}
                            {--dry-run : 乾跑模式，不寫入資料庫}
                            {--force : 跳過環境確認}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '新增代理金流配置（代收可按 deposit channel 拆分：每通道一筆 AgentCashFlow、各自 cost）';

    // =========================================================================
    // 主流程：handle 步驟拆解（參數驗證 → 環境確認 → 目標解析 → 事務執行）
    // =========================================================================

    /**
     * 執行命令
     *
     * 執行流程：
     * 1. 解析命令參數（--agent, --cashflow, --currency, --status, --mode, --dry-run, --force）
     * 2. 檢查執行環境（避免在非預期資料庫執行）
     * 3. 驗證並取得 Agent、CashFlow、Currency 資料
     * 4. 在事務中建立或更新 AgentCashFlow 及其關聯資料
     * 5. 根據乾跑模式決定提交或回滾變更
     *
     * @return int 命令執行結果（SUCCESS 或 FAILURE）
     *
     * @throws Throwable
     */
    public function handle(): int
    {
        $this->info('代理金流配置命令開始執行...');
        $this->newLine();

        if (!$this->validateModeOption()) {
            return self::FAILURE;
        }

        // 轉換幣別參數（支援幣別代碼或 ID）
        // 若 --currency 有傳值但解析失敗，提前報錯；若完全未傳則進入互動模式（交由 resolveCurrency 處理）
        $optCurrency = $this->option('currency');
        $currencyId = $optCurrency !== null ? $this->parseCurrencyInput($optCurrency) : null;
        if ($optCurrency !== null && $currencyId === null) {
            $this->printInvalidCurrencyOption($optCurrency);

            return self::FAILURE;
        }

        // 乾跑模式警告
        $isTest = (bool) $this->option('dry-run');
        if ($isTest) {
            $this->warn('⚠️  乾跑模式已啟用，執行後將不會寫入資料庫');
            $this->newLine();
        }

        // 環境確認：若使用 --force 則略過確認
        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            return self::FAILURE;
        }

        $resolved = $this->resolveTargets($currencyId);
        if ($resolved === null) {
            return self::FAILURE;
        }

        [$agent, $cashFlow, $currency] = $resolved;

        if (!$isTest) {
            // 改動前先建備份表（RB 2026-08-25 指示本命令套用 TableBackupService 機制）：
            // 兩張表就是本命令的完整寫入面，皆為小型配置表、整表快照。
            // 疏失時走 db:backup-rollback 逐列還原；備份表 15 天後由排程清除。
            $captured = app(TableBackupService::class)->capture(
                fullTables: ['agent_cash_flows', 'agent_cash_flow_order_types'],
                meta: [
                    'command' => 'agent:add-agent-cash-flow',
                    'agent_id' => (int) $agent->id,
                    'cash_flow_id' => (int) $cashFlow->id,
                    'currency_id' => (int) $currency->id,
                ],
            );
            $this->info(sprintf(
                '🗄 備份表已建立（戳 %s）；回滾走 db:backup-rollback，%d 天後由排程清除。',
                $captured['stamp'],
                TableBackupService::RETENTION_DAYS,
            ));
            $this->newLine();
        }

        return $this->applyChangesInTransaction($agent, $cashFlow, $currency, $isTest);
    }

    /**
     * 驗證拆分形式參數（僅接受 split / single；未指定時由 resolveDepositMode 互動詢問或非互動 fallback 決定）
     */
    private function validateModeOption(): bool
    {
        $optMode = $this->option('mode');
        if ($optMode !== null && !in_array($optMode, [self::MODE_SPLIT, self::MODE_SINGLE], true)) {
            $this->error(sprintf('❌ 無效的 mode: %s（支援：split=拆分模式, single=單筆模式）', $optMode));

            return false;
        }

        return true;
    }

    /** 輸出無效 --currency 的錯誤訊息與支援的幣別清單 */
    private function printInvalidCurrencyOption(string $optCurrency): void
    {
        $this->error(sprintf('❌ 無效的幣別: %s', $optCurrency));
        $this->line('');
        $this->line('支援的幣別代碼與 ID：');
        $this->line('  MYR  = 1  (馬來西亞令吉)');
        $this->line('  SGD  = 2  (新加坡元)');
        $this->line('  VND  = 3  (越南盾)');
        $this->line('  IDR  = 6  (印尼盾)');
        $this->line('  THB  = 9  (泰銖)');
        $this->line('  USDT = 10 (泰達幣)');
        $this->line('  HKD  = 16 (港幣)');
    }

    /**
     * 依序解析並顯示 Agent / CashFlow / Currency（互動模式下維持逐項詢問的順序）
     *
     * @param  int|null  $currencyId  已解析的幣別 ID（--currency 未傳時為 null，交由 resolveCurrency 互動詢問）
     * @return array{0: Agent, 1: CashFlow, 2: Currency}|null 任一項解析失敗返回 null
     */
    private function resolveTargets(?int $currencyId): ?array
    {
        $agent = $this->resolveAgent($this->option('agent'));
        if (!$agent) {
            return null;
        }

        $this->info(sprintf('代理資訊: %s (ID: %d)', $agent->username, $agent->id));
        $this->info(sprintf('金流群組 ID: %d', $agent->cash_flow_group_id));
        $this->newLine();

        $cashFlow = $this->resolveCashFlow($this->option('cashflow'), $agent->cash_flow_group_id);
        if (!$cashFlow) {
            return null;
        }

        $this->info(sprintf('金流商資訊: %s (ID: %d)', $cashFlow->name, $cashFlow->id));
        $this->info(sprintf('金流類型: %s', match ($cashFlow->type) {
            1 => '代收',
            3 => '代付',
            default => '未知',
        }));
        $this->newLine();

        $currency = $this->resolveCurrency($currencyId);
        if (!$currency) {
            return null;
        }

        $this->info(sprintf('幣別資訊: %s (%s, ID: %d)', $currency->name, $currency->code, $currency->id));
        $this->newLine();

        return [$agent, $cashFlow, $currency];
    }

    /**
     * 在資料庫事務中建立／更新配置；乾跑模式於顯示變更預覽後回滾，否則提交
     */
    private function applyChangesInTransaction(Agent $agent, CashFlow $cashFlow, Currency $currency, bool $isTest): int
    {
        // 統計資訊
        $stats = [
            'agent_cash_flow_created' => 0,
            'agent_cash_flow_updated' => 0,
            'order_types_created' => 0,
            'banks_created' => 0,
        ];

        try {
            DB::beginTransaction();

            $this->comment('開始處理...');
            $this->newLine();

            if (!$this->makeAgentCashFlow($agent, $cashFlow, $currency, $stats)) {
                DB::rollBack();
                $this->error('處理失敗，已回滾所有變更');

                return self::FAILURE;
            }

            if ($isTest) {
                $this->newLine();
                $this->warn('⚙️  測試模式: 顯示變更預覽（實際未寫入資料庫）');
                $this->newLine();

                // 在回滾前顯示數據變更
                $this->displayDataChanges($agent, $cashFlow, $currency);

                $this->newLine();
                $this->warn('✓ 乾跑模式: 已回滾所有變更，未寫入資料庫');
                DB::rollBack();
            } else {
                $this->newLine();
                $this->info('✓ 所有變更已成功寫入資料庫');
                DB::commit();
            }

            $this->printStats($stats);

            return self::SUCCESS;

        } catch (Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error(sprintf('發生錯誤: %s', $e->getMessage()));
            $this->line(sprintf('錯誤位置: %s:%d', $e->getFile(), $e->getLine()));

            return self::FAILURE;
        }
    }

    /**
     * 檢查目前資料庫連線是否為允許執行的環境
     *
     * 允許的環境：mysql、dev、testing
     * 其他環境會詢問用戶是否確認繼續執行
     *
     * @return bool 是否繼續執行
     */
    protected function confirmExecutionEnvironment(): bool
    {
        $dbConnection = config('database.default');

        if (in_array(strtolower((string) $dbConnection), ['mysql', 'dev', 'testing'], true)) {
            return true;
        }

        $this->warn(sprintf('⚠️  當前資料庫連線: %s', $dbConnection));
        $confirm = $this->confirm('確定要在此環境執行？', false);

        if (!$confirm) {
            $this->info('✓ 操作已取消');
        }

        return $confirm;
    }

    // =========================================================================
    // 目標解析：Agent / CashFlow / Currency
    // =========================================================================

    /**
     * 解析並驗證 Agent
     *
     * @param  string|int|null  $agentId  代理 ID（選項或互動輸入）
     * @return Agent|null 代理物件，失敗返回 null
     */
    protected function resolveAgent(string|int|null $agentId): ?Agent
    {
        // 優先使用命令選項
        if ($agentId) {
            try {
                return Agent::findOrFail($agentId);
            } catch (ModelNotFoundException $e) {
                $this->error(sprintf('❌ 指定的代理不存在: %s', $agentId));

                return null;
            }
        }

        // 互動模式
        $inputId = $this->ask('請輸入代理 ID');
        if (!$inputId) {
            $this->error('❌ 代理 ID 不可為空');

            return null;
        }

        try {
            return Agent::findOrFail($inputId);
        } catch (ModelNotFoundException $e) {
            $this->error(sprintf('❌ 代理不存在或未找到: %s', $inputId));

            return null;
        }
    }

    /**
     * 解析並驗證 CashFlow
     *
     * @param  string|int|null  $cashFlowId  金流 ID（選項或互動輸入）
     * @param  int|null  $cashFlowGroupId  代理的金流群組 ID
     * @return CashFlow|null 金流物件，失敗返回 null
     */
    protected function resolveCashFlow(string|int|null $cashFlowId, ?int $cashFlowGroupId): ?CashFlow
    {
        if (empty($cashFlowGroupId)) {
            $this->error('❌ 代理未設定金流群組 ID');

            return null;
        }

        // 優先使用命令選項
        if ($cashFlowId) {
            $cashFlow = CashFlow::query()
                ->with(['agentCashFlows' => fn ($q) => $q->where('agent_group_id', $cashFlowGroupId)->where('status', 1)])
                ->find($cashFlowId);

            if (!$cashFlow) {
                $this->error(sprintf('❌ 指定的金流商不存在: %s', $cashFlowId));

                return null;
            }

            return $cashFlow;
        }

        // 互動模式
        $inputId = $this->ask('請輸入金流商 ID');
        if (!$inputId) {
            $this->error('❌ 金流商 ID 不可為空');

            return null;
        }

        $cashFlow = CashFlow::query()
            ->with(['agentCashFlows' => fn ($q) => $q->where('agent_group_id', $cashFlowGroupId)->where('status', 1)])
            ->find($inputId);

        if (!$cashFlow) {
            $this->error(sprintf('❌ 金流商不存在或未找到: %s', $inputId));

            return null;
        }

        return $cashFlow;
    }

    /**
     * 解析並驗證 Currency
     *
     * @param  int|null  $currencyId  幣別 ID
     * @return Currency|null 幣別物件，失敗返回 null
     */
    protected function resolveCurrency(?int $currencyId): ?Currency
    {
        // 優先使用命令選項
        if ($currencyId) {
            $currency = Currency::find($currencyId);

            if (!$currency) {
                $this->error(sprintf('❌ 指定的幣別不存在: %s', $currencyId));

                return null;
            }

            return $currency;
        }

        // 互動模式
        $inputId = $this->ask('請輸入幣別 ID 或代碼（如：VND, 3）', '1');
        if (!$inputId) {
            $this->error('❌ 幣別不可為空');

            return null;
        }

        $parsedId = $this->parseCurrencyInput($inputId);
        if ($parsedId === null) {
            $this->error(sprintf('❌ 無效的幣別: %s', $inputId));

            return null;
        }

        $currency = Currency::find($parsedId);

        if (!$currency) {
            $this->error(sprintf('❌ 幣別不存在: %s', $inputId));

            return null;
        }

        return $currency;
    }

    /**
     * 解析幣別輸入（支援幣別代碼或 ID）
     *
     * @param  string|int|null  $input  幣別輸入（如：VND, 3, MYR, 1）
     * @return int|null 幣別 ID，無效則返回 null
     */
    protected function parseCurrencyInput(string|int|null $input): ?int
    {
        if (!$input) {
            return null;
        }

        // 如果是數字，直接使用
        if (is_numeric($input)) {
            $currencyId = (int) $input;

            // 驗證幣別是否存在
            if (Currency::find($currencyId)) {
                return $currencyId;
            }

            return null;
        }

        // 否則當作幣別代碼處理
        $currencyCode = strtoupper(trim((string) $input));
        $currency = Currency::where('code', $currencyCode)->first();

        return $currency ? $currency->id : null;
    }

    // =========================================================================
    // 核心建立：單筆／拆分分流與 AgentCashFlow upsert
    // =========================================================================

    /**
     * 建立或更新 AgentCashFlow 及其關聯資料
     *
     * 流程：
     * 1. 查找或建立 AgentCashFlow
     * 2. 代收（type=1）：建立 OrderType 關聯和銀行選項
     * 3. 代付（type=3）：更新 available_banks 並同步銀行資料
     *
     * @param  Agent  $agent  代理物件
     * @param  CashFlow  $cashFlow  金流物件
     * @param  Currency  $currency  幣別物件
     * @param  array  $stats  統計資訊（引用傳遞）
     * @return bool 處理是否成功
     */
    protected function makeAgentCashFlow(Agent $agent, CashFlow $cashFlow, Currency $currency, array &$stats): bool
    {
        $currencyId = $currency->id;
        $currencyCode = $currency->code;

        $settings = $this->resolveCurrencySettings($agent, $cashFlow, $currency);
        if ($settings === null) {
            return false;
        }

        [$rate, $minAmount, $maxAmount] = $settings;

        $status = (int) $this->option('status');
        $this->line(sprintf(
            '  初始狀態: %s (%d)，套用於%s',
            $status === 0 ? '停用' : '啟用',
            $status,
            $cashFlow->type === 1 ? '支付通道關聯（代收金流商戶固定啟用）' : '金流商戶',
        ));
        $acfStatusAttributes = $this->agentCashFlowStatusAttributes($cashFlow, $status);

        [$providerCredentials, $providerDepositChannels, $providerWithdrawalChannels] = $this->loadProviderContext($cashFlow, $currencyCode);

        // 代付不吃 --mode：明確告知已忽略，避免操作者誤以為生效
        if ($cashFlow->type === 3 && $this->option('mode') !== null) {
            $this->warn('  ⚠️  --mode 僅適用代收（type=1），代付固定單筆 AgentCashFlow，已忽略');
        }

        // 代收（type=1）：拆分形式由操作者確認（--mode / 互動詢問 / 非互動 fallback），拆分模式依 channel 數量建立多筆 AgentCashFlow
        if ($cashFlow->type === 1 && $this->resolveDepositMode($providerDepositChannels) === self::MODE_SPLIT) {
            // 偵測既有單筆記錄：經操作者確認後就地遷移（rename 重用＋搬移 ACFOT），未確認則中止避免重複配置
            $migrated = $this->prepareSplitMigration($agent, $cashFlow, $currency, $providerDepositChannels);
            if ($migrated === null) {
                return false;
            }

            return $this->processChannelMode(
                agent: $agent,
                cashFlow: $cashFlow,
                currencyId: $currencyId,
                currencyCode: $currencyCode,
                rate: (float) $rate,
                minAmount: (float) $minAmount,
                maxAmount: (float) $maxAmount,
                status: $status,
                acfStatusAttributes: $acfStatusAttributes,
                providerCredentials: $providerCredentials,
                providerDepositChannels: $providerDepositChannels,
                stats: $stats,
                createMissingOnly: $migrated,
            );
        }

        // 單筆模式：一個 cash_flow + currency 一筆 AgentCashFlow（name 以金流商名組成）
        $agentCashFlow = $this->upsertAgentCashFlow(
            agent: $agent,
            findAttributes: [
                'cash_flow_id' => $cashFlow->id,
                'agent_group_id' => $agent->cash_flow_group_id,
                'currency_id' => $currencyId,
            ],
            createAttributes: [
                'cash_flow_info_id' => 0,
                'currency_id' => $currencyId,
                'rate' => $rate,
                'name' => sprintf('(%s) %s', $currencyCode, $cashFlow->name),
                ...$acfStatusAttributes,
                'admin' => 0,
            ],
            providerCredentials: $providerCredentials,
            stats: $stats,
        );

        // 處理代收（type = 1）
        if ($cashFlow->type === 1) {
            return $this->processDepositCashFlow($agentCashFlow, $cashFlow, $currencyId, $minAmount, $maxAmount, $providerDepositChannels, $stats, status: $status);
        }

        // 處理代付（type = 3）
        if ($cashFlow->type === 3) {
            return $this->processWithdrawalCashFlow($agentCashFlow, $cashFlow, $agent, $currencyId, $minAmount, $maxAmount, $providerWithdrawalChannels, $stats);
        }

        $this->warn(sprintf('  ⚠️  未知的金流類型: %d', $cashFlow->type));

        return false;
    }

    /**
     * 從 example.invalid 讀取幣別配置（匯率／限額），套用代理主幣匯率覆寫並驗證金額合理性
     *
     * @return array{0: int|float, 1: int|float, 2: int|float}|null [rate, minAmount, maxAmount]，金額配置異常時返回 null
     */
    private function resolveCurrencySettings(Agent $agent, CashFlow $cashFlow, Currency $currency): ?array
    {
        $currencyCode = $currency->code;

        // 從 CashFlow info 中讀取幣別配置
        $cashFlowInfo = $cashFlow->info ?? [];
        $currencyInfo = $cashFlowInfo[$currencyCode] ?? [];

        $rate = $currencyInfo['rate'] ?? 1;
        $minAmount = $currencyInfo['min_amount'] ?? 0;
        $maxAmount = $currencyInfo['max_amount'] ?? 0;

        // 代理主幣覆寫：若本筆幣別等於代理主幣，匯率強制為 1（代理層 fallback 至環境主幣）
        $agentMainCurrency = $this->resolveAgentMainCurrency((int) $agent->id);
        if ($agentMainCurrency !== '' && $currencyCode === $agentMainCurrency) {
            $rate = 1;
            $this->comment(sprintf('  ⚙️  代理主幣 %s 匹配，匯率強制為 1', $agentMainCurrency));
        }

        // 驗證金額合理性
        if ($minAmount < 0 || $maxAmount < 0) {
            $this->error(sprintf('❌ 金額不能為負數: min_amount=%s, max_amount=%s', $minAmount, $maxAmount));

            return null;
        }

        if ($maxAmount > 0 && $minAmount > $maxAmount) {
            $this->error(sprintf('❌ 金額配置異常: min_amount (%s) > max_amount (%s)', $minAmount, $maxAmount));

            return null;
        }

        $this->comment(sprintf('處理幣別: %s (%s)', $currency->name, $currencyCode));
        $this->line(sprintf('  匯率: %s, 最小金額: %s, 最大金額: %s', $rate, $minAmount, $maxAmount));

        return [$rate, $minAmount, $maxAmount];
    }

    /**
     * 依金流類型決定 --status 寫在金流商戶的哪些欄位
     *
     * 營運的上下架開關：代收在支付通道關聯（agent_cash_flow_order_types），代付在金流商戶（agent_cash_flows）；
     * 後台列表的 status 與 backend_display 也照這個分法讀（AgentCashFlowListService、API_Agent AgentOnlineDepositController）。
     * 因此代收金流商戶固定啟用、不碰 backend_display，--status 改由 upsertOrderTypeLink 寫到支付通道關聯；
     * 代付金流商戶的 backend_display 跟著 status 走，停用即隱藏。
     *
     * @return array<string, int>
     */
    private function agentCashFlowStatusAttributes(CashFlow $cashFlow, int $status): array
    {
        if ($cashFlow->type === 1) {
            return ['status' => 1];
        }

        return ['status' => $status, 'backend_display' => $status];
    }

    /**
     * 查找或建立／更新單筆 AgentCashFlow（單筆模式與拆分模式 per-channel 共用）
     *
     * - 不存在 → 建立（info 使用完整 provider credentials 結構）
     * - 已存在且 $createMissingOnly → 原樣沿用（遷移列的 rename/cost/status/info 已由遷移設定），只補缺漏 ACFOT
     * - 已存在且 $keyMatched（channel key 反查命中）→ 更新但保留營運值（name/cost/status），不提供「建立新的」
     * - 已存在 → 合併 info（只補充尚不存在的 key，現有值優先）；互動模式詢問「更新現有／建立新的」，非互動直接更新
     *
     * @param  array  $findAttributes  查找唯一鍵條件
     * @param  array  $createAttributes  建立／更新欄位（info 由本方法依情境填入）
     * @param  array  $stats  統計資訊（引用傳遞）
     * @param  AgentCashFlow|null  $keyMatched  拆分模式經 channel key 反查命中的既有列（優先於 name 查找）
     */
    private function upsertAgentCashFlow(
        Agent $agent,
        array $findAttributes,
        array $createAttributes,
        array $providerCredentials,
        array &$stats,
        bool $createMissingOnly = false,
        ?AgentCashFlow $keyMatched = null,
    ): AgentCashFlow {
        /** @var AgentCashFlow|null $existing */
        $existing = $keyMatched ?? $agent->agentCashFlows()->where($findAttributes)->first();

        if ($existing && $createMissingOnly) {
            $this->info(sprintf('  ✓ 沿用遷移後 AgentCashFlow (ID: %d)，僅補建缺漏配置', $existing->id));

            return $existing;
        }

        if (!$existing) {
            $createAttributes['info'] = $providerCredentials;
            /** @var AgentCashFlow $agentCashFlow */
            $agentCashFlow = $agent->agentCashFlows()->create(array_merge($findAttributes, $createAttributes));
            $stats['agent_cash_flow_created']++;
            $this->info(sprintf('  ✓ 已建立 AgentCashFlow (ID: %d)', $agentCashFlow->id));

            return $agentCashFlow;
        }

        // 合併 info：只補充 existingInfo 中尚不存在的 key，保留已配置的值
        $existingInfo = $existing->info ?? [];
        $newCredentials = array_filter(
            $providerCredentials,
            fn ($key) => !array_key_exists($key, $existingInfo),
            ARRAY_FILTER_USE_KEY,
        );
        $createAttributes['info'] = array_merge($newCredentials, $existingInfo);

        // key 反查命中＝營運已持有該列：name（顯示名）/cost（各站自設）/status（啟停）為營運值不覆寫，
        // 僅同步 config 側欄位（rate/info 補缺）；不提供「建立新的」——同 key 第二列即重複配置
        if ($keyMatched) {
            unset($createAttributes['name'], $createAttributes['cost'], $createAttributes['status'], $createAttributes['backend_display']);
            $existing->update($createAttributes);
            $stats['agent_cash_flow_updated']++;
            $this->info(sprintf(
                '  ✓ 已依 channel key 命中並更新 AgentCashFlow (ID: %d, name: "%s"；name/cost/status 保留營運值)',
                $existing->id,
                $existing->name,
            ));

            return $existing;
        }

        // 互動模式詢問用戶；非互動模式直接更新
        if ($this->input->isInteractive()) {
            $choice = $this->choice(
                sprintf('  AgentCashFlow 已存在 (ID: %d)，選擇操作：', $existing->id),
                ['更新現有', '建立新的'],
                0
            );

            if ($choice === '建立新的') {
                /** @var AgentCashFlow $agentCashFlow */
                $agentCashFlow = $agent->agentCashFlows()->create(array_merge($findAttributes, $createAttributes));
                $stats['agent_cash_flow_created']++;
                $this->info(sprintf('  ✓ 已建立新的 AgentCashFlow (ID: %d)', $agentCashFlow->id));

                return $agentCashFlow;
            }
        }

        $existing->update($createAttributes);
        $stats['agent_cash_flow_updated']++;
        $this->info(sprintf('  ✓ 已更新 AgentCashFlow (ID: %d)', $existing->id));

        return $existing;
    }

    // =========================================================================
    // Provider 配置：credentials 與渠道藍圖載入
    // =========================================================================

    /**
     * 載入 provider 配置上下文：credentials（含 info_overrides）與目標幣別的代收／代付渠道藍圖
     *
     * provider 完整配置一次性載入，credentials + 渠道限額共用同一份檔案；
     * info_overrides 位於 channel 拆分分流之前套用，單筆模式與 processChannelMode 兩條路徑都吃到覆寫。
     *
     * @return array{0: array, 1: array, 2: array} [providerCredentials, providerDepositChannels, providerWithdrawalChannels]
     */
    private function loadProviderContext(CashFlow $cashFlow, string $currencyCode): array
    {
        $providerConfig = $this->loadProviderConfigArray($cashFlow);
        $providerCredentials = $this->resolveProviderCredentials($providerConfig);

        // 依金流類型×幣別套用 info_overrides（如 DgPay 代付 PROVIDER_ID、SGD VERSION=V2）
        $providerCredentials = $this->applyInfoOverrides($providerCredentials, $providerConfig, (int) $cashFlow->type, $currencyCode);

        // 渠道限額作為 fallback，優先順序低於 example.invalid 與 example.invalid
        return [
            $providerCredentials,
            $this->filterChannelsByCurrency($providerConfig['deposit']['channels'] ?? [], $currencyCode),
            $this->filterChannelsByCurrency($providerConfig['withdrawal']['channels'] ?? [], $currencyCode),
        ];
    }

    /** 過濾出指定幣別的渠道藍圖（重新索引） */
    private function filterChannelsByCurrency(array $channels, string $currencyCode): array
    {
        return array_values(array_filter(
            $channels,
            fn (array $ch): bool => ($ch['currency'] ?? '') === $currencyCode
        ));
    }

    /**
     * 從 provider 完整配置解析 credentials（無配置或格式錯誤時回預設結構）
     */
    private function resolveProviderCredentials(array $providerConfig): array
    {
        $defaultCredentials = ['API_URL' => ''];

        if (empty($providerConfig) || !isset($providerConfig['credentials']) || !is_array($providerConfig['credentials'])) {
            return $defaultCredentials;
        }

        $credentials = array_merge($defaultCredentials, $providerConfig['credentials']);
        $this->line(sprintf('  ✓ 已載入 credentials 配置 (%d 個欄位)', count($credentials)));

        return $credentials;
    }

    /**
     * 載入 Provider 完整配置檔（原始 array）
     *
     * 包含 credentials、deposit.channels、withdrawal.channels 等所有頂層 key。
     * 由 loadProviderContext 調用一次後分別傳遞給各子方法，避免重複讀取檔案。
     * 檔案定位邏輯與 SyncCashFlowsCommand 共用 {@see ResolvesProviderConfigFile}。
     *
     * @param  CashFlow  $cashFlow  金流物件
     * @return array 完整配置陣列，若檔案不存在或格式錯誤則返回 []
     */
    private function loadProviderConfigArray(CashFlow $cashFlow): array
    {
        $providersDir = __DIR__ . '/Data/providers';

        if (!is_dir($providersDir)) {
            $this->warn(sprintf('  ⚠️  Provider 配置目錄不存在: %s', $providersDir));

            return [];
        }

        $thirdPartyCode = $cashFlow->third_party_code;

        if (empty($thirdPartyCode)) {
            $this->warn(sprintf('  ⚠️  金流商缺少 third_party_code (CashFlow ID: %d)', $cashFlow->id));

            return [];
        }

        $providerName = $this->resolveProviderFileName($thirdPartyCode, $this->listProviderFileNames($providersDir));

        if ($providerName === null) {
            $this->warn(sprintf('  ⚠️  找不到匹配的 Provider 配置文件 (third_party_code: %s)', $thirdPartyCode));

            return [];
        }

        $configPath = $providersDir . '/' . $providerName . '.php';

        if (!file_exists($configPath)) {
            $this->warn(sprintf('  ⚠️  配置文件不存在: %s (third_party_code: %s)', $configPath, $thirdPartyCode));

            return [];
        }

        $config = require $configPath;

        if (!is_array($config)) {
            $this->warn(sprintf('  ⚠️  配置文件格式錯誤: %s', $providerName));

            return [];
        }

        return $config;
    }

    // =========================================================================
    // 代收拆分形式判定
    // =========================================================================

    /**
     * 決定代收建立形式（拆分模式或單筆模式），由操作者確認
     *
     * 決定順序：
     *  1. --mode 選項（split / single）——免詢問，供腳本與非互動場景明確指定；
     *  2. 互動模式一律詢問拆分形式，預設建議依 config 是否帶 channel cost（帶 cost → 拆分模式）；
     *  3. 非互動且未指定 --mode：依 config cost 自動分流（維持舊版自動路線）。
     *
     * provider config 無目標幣別的 deposit channel 藍圖時無從拆分，一律走單筆模式。
     */
    private function resolveDepositMode(array $providerDepositChannels): string
    {
        if ($providerDepositChannels === []) {
            $this->comment('  ⚙️  provider config 無此幣別 deposit channel 藍圖，無從拆分，以單筆模式建立');

            return self::MODE_SINGLE;
        }

        $hasChannelCost = $this->hasChannelCostConfigured($providerDepositChannels);

        $optMode = $this->option('mode');
        if ($optMode !== null) {
            $this->comment(sprintf(
                '  ⚙️  拆分形式（--mode 指定）: %s',
                $optMode === self::MODE_SPLIT ? '拆分模式（一商戶對一通道）' : '單筆模式（一商戶對多通道）',
            ));

            return (string) $optMode;
        }

        if (!$this->input->isInteractive()) {
            $this->comment(sprintf(
                '  ⚙️  非互動模式未指定 --mode，依 config cost 自動分流: %s',
                $hasChannelCost ? '拆分模式（config 帶 channel cost）' : '單筆模式（config 無 channel cost）',
            ));

            return $hasChannelCost ? self::MODE_SPLIT : self::MODE_SINGLE;
        }

        $splitLabel = '拆分模式：一商戶對一通道（每通道一筆 AgentCashFlow，可各自配置不同 cost；config 無 cost 時為 0）';
        $singleLabel = '單筆模式：一商戶對多通道（一筆 AgentCashFlow 掛全部通道，僅單一 cost）';

        $this->newLine();
        $this->line(sprintf('  provider config %s channel cost 配置', $hasChannelCost ? '帶有' : '未帶'));
        $choice = $this->choice('請確認拆分形式', [$splitLabel, $singleLabel], $hasChannelCost ? 0 : 1);

        return $choice === $splitLabel ? self::MODE_SPLIT : self::MODE_SINGLE;
    }

    /**
     * 判斷 config 是否帶 channel cost（任一 channel 存在 cost key）
     *
     * 以 array_key_exists 判定：cost=0 也視為有配置。
     * 僅作為 resolveDepositMode 互動詢問的預設建議與非互動 fallback 的分流依據，
     * 不再單獨決定命令路線（拆分形式最終由操作者確認）。
     */
    private function hasChannelCostConfigured(array $providerChannels): bool
    {
        return collect($providerChannels)
            ->contains(fn (array $ch): bool => array_key_exists('cost', $ch));
    }

    // =========================================================================
    // 單筆 → 拆分就地遷移
    // =========================================================================

    /**
     * 拆分模式前置：偵測既有單筆記錄並依操作者確認執行就地遷移
     *
     * 回傳三態：
     *   - null  = 中止（偵測到單筆記錄但未確認遷移，或混合狀態需人工清理）
     *   - false = 無單筆記錄，直接走拆分建立
     *   - true  = 已完成就地遷移，後續走「只補缺漏、不覆寫既有」模式
     */
    private function prepareSplitMigration(Agent $agent, CashFlow $cashFlow, Currency $currency, array $providerChannels): ?bool
    {
        $legacy = $this->findLegacyRecord($agent, $cashFlow, $currency->id, $currency->code);

        // name 偵測 miss 時以形態補偵：掛 ≥2 個父層 OT key＝單筆形態（name 是營運欄位、改名即 miss）
        if (!$legacy) {
            $multiKeyIds = array_keys(array_filter(
                $this->mapScopedAcfParentOtKeys($agent, $cashFlow, $currency->id),
                fn (array $keys): bool => count($keys) >= 2,
            ));

            if (count($multiKeyIds) > 1) {
                $this->error(sprintf(
                    '❌ 偵測到多筆單筆形態記錄（各掛多個父層 OT，AgentCashFlow ID: %s），請先以 php artisan fix:acf-delete --acf=<id> --dry-run 清理多餘列（軟刪除、可還原）後再執行',
                    implode(', ', $multiKeyIds),
                ));

                return null;
            }

            if ($multiKeyIds !== []) {
                $legacy = AgentCashFlow::query()->find($multiKeyIds[0]);
            }
        }

        if (!$legacy) {
            return false;
        }

        $this->warn(sprintf('⚠️  偵測到既有單筆記錄 (AgentCashFlow ID: %d, name: "%s")', $legacy->id, $legacy->name));

        if (!$this->shouldMigrateSingleRecord()) {
            return null;
        }

        // 混合狀態防護：單筆記錄與 per-channel 記錄並存＝前次遷移不完整，禁止自動處理
        $mixed = $this->detectMixedState($legacy, $cashFlow, $agent, $currency, $providerChannels);
        if ($mixed !== []) {
            $this->error('❌ 偵測到混合狀態，下列 channel 記錄已存在，請先手動清理：');
            foreach ($mixed as $name) {
                $this->line(sprintf('   - %s', $name));
            }

            return null;
        }

        $channelOtIds = $this->buildChannelOtIds($cashFlow, $currency->id, $providerChannels);
        $plan = $this->buildMigrationPlan($legacy, $providerChannels, $channelOtIds);

        $this->displayMigrationPlan($plan, $providerChannels, $currency->code);
        $this->applyMigration($legacy, $providerChannels, $plan, $currency->code);

        return true;
    }

    /**
     * 偵測既有單筆記錄（name = "({CUR}) {cashFlow.name}"，不論舊版命令或單筆模式建立）
     */
    private function findLegacyRecord(Agent $agent, CashFlow $cashFlow, int $currencyId, string $currencyCode): ?AgentCashFlow
    {
        $legacyName = sprintf('(%s) %s', $currencyCode, $cashFlow->name);

        /** @var AgentCashFlow|null */
        return $agent->agentCashFlows()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('currency_id', $currencyId)
            ->where('name', $legacyName)
            ->first();
    }

    /**
     * 判斷是否執行單筆→拆分就地遷移（--migrate-single flag > 互動詢問 > 非互動中止）
     */
    private function shouldMigrateSingleRecord(): bool
    {
        if ((bool) $this->option('migrate-single')) {
            $this->comment('  ⚙️  --migrate-single 指定：就地遷移為拆分形式（每通道一筆）');

            return true;
        }

        if (!$this->input->isInteractive()) {
            $this->error('   非互動模式不自動遷移單筆記錄；請帶 --migrate-single 明確指定，或以互動模式執行。');

            return false;
        }

        $confirmed = $this->confirm('要就地遷移為拆分形式嗎？（rename 重用單筆列、搬移既有 ACFOT、遷移後補建缺漏渠道）', true);
        if (!$confirmed) {
            $this->info('✓ 已取消遷移，未進行任何變更');
        }

        return $confirmed;
    }

    /**
     * 偵測混合狀態：是否已有任一 channel 對應的記錄存在（非單筆那筆）
     *
     * @return array<int, string> 已存在 channel 記錄的 name 列表（空陣列代表無混合）
     */
    private function detectMixedState(
        AgentCashFlow $legacy,
        CashFlow $cashFlow,
        Agent $agent,
        Currency $currency,
        array $providerChannels,
    ): array {
        $expectedChannelNames = array_map(
            fn (array $ch): string => sprintf('(%s) %s', $currency->code, (string) ($ch['name'] ?? '')),
            $providerChannels,
        );

        return AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('currency_id', $currency->id)
            ->where('id', '!=', $legacy->id)
            ->whereIn('name', $expectedChannelNames)
            ->pluck('name')
            ->all();
    }

    /**
     * 為每個 channel 收集對應的 OrderType id（parent OT.key === channel.key + 其子層）
     *
     * @return array<string, array<int, int>> channelKey => [otIds]
     */
    private function buildChannelOtIds(CashFlow $cashFlow, int $currencyId, array $providerChannels): array
    {
        $parentOts = OrderType::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('currency_id', $currencyId)
            ->where('parent', 0)
            ->get()
            ->keyBy('key');

        $channelOtIds = [];

        foreach ($providerChannels as $channel) {
            $channelKey = (string) ($channel['key'] ?? '');
            $parentOt = $parentOts[$channelKey] ?? null;

            if (!$parentOt) {
                $this->warn(sprintf('  ⚠️  channel "%s" 找不到對應 parent OT，該 channel 將沒有可搬移的 ACFOT', $channelKey));
                $channelOtIds[$channelKey] = [];

                continue;
            }

            $childIds = OrderType::query()
                ->where('cash_flow_id', $cashFlow->id)
                ->where('currency_id', $currencyId)
                ->where('parent', $parentOt->id)
                ->pluck('id')
                ->all();

            $channelOtIds[$channelKey] = array_merge([$parentOt->id], $childIds);
        }

        return $channelOtIds;
    }

    /**
     * 規劃 ACFOT 重分配
     *
     * @return array{
     *     keep: array<int, int>,
     *     move: array<string, array<int, int>>,
     *     orphans: array<int, int>,
     *     firstChannelKey: string
     * } keep=保留在單筆列的 ACFOT id, move[channelKey]=要移動的 ACFOT id, orphans=要刪除的 ACFOT id
     */
    private function buildMigrationPlan(AgentCashFlow $legacy, array $providerChannels, array $channelOtIds): array
    {
        $firstChannelKey = (string) ($providerChannels[0]['key'] ?? '');

        $acfots = AgentCashFlowOrderType::query()
            ->where('agent_cash_flow_id', $legacy->id)
            ->get();

        $keep = [];
        $move = [];
        $orphans = [];

        foreach ($acfots as $acfot) {
            $assigned = false;

            foreach ($channelOtIds as $channelKey => $otIds) {
                if (!in_array($acfot->order_type_id, $otIds, true)) {
                    continue;
                }

                if ($channelKey === $firstChannelKey) {
                    $keep[] = $acfot->id;
                } else {
                    $move[$channelKey][] = $acfot->id;
                }

                $assigned = true;
                break;
            }

            if (!$assigned) {
                $orphans[] = $acfot->id;
            }
        }

        return [
            'keep' => $keep,
            'move' => $move,
            'orphans' => $orphans,
            'firstChannelKey' => $firstChannelKey,
        ];
    }

    /** 顯示遷移計畫 */
    private function displayMigrationPlan(array $plan, array $providerChannels, string $currencyCode): void
    {
        $this->newLine();
        $this->info('📋 遷移計畫：');

        $rows = [];
        foreach ($providerChannels as $i => $channel) {
            $key = (string) $channel['key'];
            $name = sprintf('(%s) %s', $currencyCode, (string) $channel['name']);
            $cost = $channel['cost'] ?? 0;
            $action = $i === 0 ? '重用單筆列' : '新建';
            $acfotCount = $i === 0 ? count($plan['keep']) : count($plan['move'][$key] ?? []);
            $rows[] = [$key, $name, $cost, $action, $acfotCount];
        }

        $this->table(['channel.key', 'name', 'cost', '動作', 'ACFOT 數'], $rows);

        $orphanCount = count($plan['orphans']);
        if ($orphanCount > 0) {
            $this->warn(sprintf('  ⚠️  孤兒 ACFOT %d 筆將被刪除：%s', $orphanCount, implode(', ', $plan['orphans'])));
        }
    }

    /** 實際執行遷移：rename 單筆列 / 建新記錄 / 搬 ACFOT / 刪孤兒（於 handle 的外層事務內執行，dry-run 一併回滾） */
    private function applyMigration(AgentCashFlow $legacy, array $providerChannels, array $plan, string $currencyCode): void
    {
        $firstChannel = $providerChannels[0];
        $legacy->update([
            'name' => sprintf('(%s) %s', $currencyCode, (string) $firstChannel['name']),
            'cost' => $firstChannel['cost'] ?? 0,
        ]);
        $this->line(sprintf('  ✓ 已 rename 單筆 AgentCashFlow id=%d → "%s"', $legacy->id, $legacy->name));

        foreach (array_slice($providerChannels, 1) as $channel) {
            $channelKey = (string) $channel['key'];
            $name = sprintf('(%s) %s', $currencyCode, (string) $channel['name']);

            $new = AgentCashFlow::create([
                'cash_flow_id' => $legacy->cash_flow_id,
                'agent_group_id' => $legacy->agent_group_id,
                'currency_id' => $legacy->currency_id,
                'cash_flow_info_id' => $legacy->cash_flow_info_id,
                'name' => $name,
                'cost' => $channel['cost'] ?? 0,
                'rate' => $legacy->rate,
                'info' => $legacy->info,
                'status' => $legacy->status,
                'admin' => $legacy->admin,
            ]);
            $this->line(sprintf('  ✓ 已建立 AgentCashFlow id=%d → "%s"', $new->id, $name));

            $acfotIds = $plan['move'][$channelKey] ?? [];
            if (!empty($acfotIds)) {
                AgentCashFlowOrderType::query()
                    ->whereIn('id', $acfotIds)
                    ->update(['agent_cash_flow_id' => $new->id]);
                $this->line(sprintf('    + 搬移 %d 筆 ACFOT', count($acfotIds)));
            }
        }

        if (!empty($plan['orphans'])) {
            $deleted = AgentCashFlowOrderType::query()->whereIn('id', $plan['orphans'])->delete();
            $this->line(sprintf('  ✓ 刪除孤兒 ACFOT %d 筆', $deleted));
        }
    }

    // =========================================================================
    // 代收處理：拆分模式逐 channel／OrderType 關聯
    // =========================================================================

    /**
     * 按 channel 拆分建立 AgentCashFlow（每個 channel 一筆，使用該 channel 的 cost）
     *
     * 與單筆模式差異：
     * - findAttributes 加入 name 作為唯一鍵（同 cash_flow_id+agent_group_id+currency_id 下以 name 區分）
     * - createAttributes 帶 cost = channel.cost ?? 0
     * - 只 attach 該 channel 對應 key 的 OrderType
     *
     * @param  bool  $createMissingOnly  單筆→拆分遷移後續跑：既有 AgentCashFlow／ACFOT 原樣保留
     *                                   （status/cost/info/手動限額已由遷移繼承），僅補建缺漏配置
     */
    private function processChannelMode(
        Agent $agent,
        CashFlow $cashFlow,
        int $currencyId,
        string $currencyCode,
        float $rate,
        float $minAmount,
        float $maxAmount,
        int $status,
        array $acfStatusAttributes,
        array $providerCredentials,
        array $providerDepositChannels,
        array &$stats,
        bool $createMissingOnly = false,
    ): bool {
        $allOk = true;

        // 既有列穩定識別：ACFOT → 父層 OT key（name 是營運顯示欄位、會被後台改名，不可作唯一識別）
        $acfParentKeys = $this->mapScopedAcfParentOtKeys($agent, $cashFlow, $currencyId);

        foreach ($providerDepositChannels as $channel) {
            $channelKey = (string) ($channel['key'] ?? '');
            $channelName = (string) ($channel['name'] ?? $channelKey);
            $channelCost = $channel['cost'] ?? 0;

            if ($channelKey === '') {
                $this->warn('  ⚠️  跳過缺少 key 的 channel');

                continue;
            }

            $perChannelName = sprintf('(%s) %s', $currencyCode, $channelName);

            $this->newLine();
            $this->comment(sprintf('處理 channel: %s (key=%s, cost=%s)', $channelName, $channelKey, $channelCost));

            // 僅掛單一父層 OT 且 key 相符者＝該 channel 的既有 per-channel 列（掛多鍵者屬單筆形態，走遷移路徑）
            $candidateIds = array_keys(array_filter(
                $acfParentKeys,
                fn (array $keys): bool => count($keys) === 1 && $keys[0] === $channelKey,
            ));

            if (count($candidateIds) > 1) {
                $this->error(sprintf(
                    '  ❌ channel key "%s" 對應多筆 AgentCashFlow (ID: %s)＝重複配置，請先以 php artisan fix:acf-delete --acf=<id> --dry-run 清理重複列（軟刪除、可還原）後再執行',
                    $channelKey,
                    implode(', ', $candidateIds),
                ));

                return false;
            }

            $keyMatched = $candidateIds !== [] ? AgentCashFlow::query()->find($candidateIds[0]) : null;

            $findAttributes = [
                'cash_flow_id' => $cashFlow->id,
                'agent_group_id' => $agent->cash_flow_group_id,
                'currency_id' => $currencyId,
                'name' => $perChannelName,
            ];

            // 遷移後續跑（createMissingOnly）：沿用遷移列原樣（rename/cost/status/info 已由遷移設定），只補缺漏 ACFOT
            $agentCashFlow = $this->upsertAgentCashFlow(
                agent: $agent,
                findAttributes: $findAttributes,
                createAttributes: [
                    'cash_flow_info_id' => 0,
                    'currency_id' => $currencyId,
                    'rate' => $rate,
                    'name' => $perChannelName,
                    'cost' => $channelCost,
                    ...$acfStatusAttributes,
                    'admin' => 0,
                ],
                providerCredentials: $providerCredentials,
                stats: $stats,
                createMissingOnly: $createMissingOnly,
                keyMatched: $keyMatched,
            );

            // key 反查命中＝營運已持有該列：ACFOT 亦僅補缺（firstOrCreate），
            // 既有列的 status／手動限額／settings 屬營運值，不得被 config 藍圖打回
            $ok = $this->processDepositCashFlow(
                $agentCashFlow,
                $cashFlow,
                $currencyId,
                $minAmount,
                $maxAmount,
                [$channel],
                $stats,
                $channelKey,
                $createMissingOnly || $keyMatched !== null,
                $status,
            );

            $allOk = $allOk && $ok;
        }

        return $allOk;
    }

    /**
     * 盤點 scope 內各 AgentCashFlow 經 ACFOT 掛的父層 OrderType key（去重）
     *
     * channel 的穩定識別是父層 OT key（功能性欄位，代收下單依它動態解析路由），
     * 不是 name（營運顯示欄位，後台可改名）。掛恰一鍵者＝per-channel 形態列，
     * 掛多鍵者＝單筆形態列（由遷移路徑處理）。
     *
     * @return array<int, array<int, string>> acfId => 該列去重後的父層 OT key 清單
     */
    private function mapScopedAcfParentOtKeys(Agent $agent, CashFlow $cashFlow, int $currencyId): array
    {
        $links = AgentCashFlowOrderType::query()
            ->join('order_types', 'order_types.id', '=', 'agent_cash_flow_order_types.order_type_id')
            ->join('agent_cash_flows', 'agent_cash_flows.id', '=', 'agent_cash_flow_order_types.agent_cash_flow_id')
            // SoftDeletes 全域 scope 只作用在基底 model（acfot），join 進來的 acf 須自行排除軟刪列
            ->whereNull('agent_cash_flows.deleted_at')
            ->where('agent_cash_flows.cash_flow_id', $cashFlow->id)
            ->where('agent_cash_flows.agent_group_id', $agent->cash_flow_group_id)
            ->where('agent_cash_flows.currency_id', $currencyId)
            ->where('order_types.cash_flow_id', $cashFlow->id)
            ->where('order_types.currency_id', $currencyId)
            ->where('order_types.parent', 0)
            ->distinct()
            ->get([
                'agent_cash_flow_order_types.agent_cash_flow_id as acf_id',
                'order_types.key as ot_key',
            ]);

        return $links
            ->groupBy('acf_id')
            ->map(fn (Collection $group): array => $group->pluck('ot_key')->map(fn ($key): string => (string) $key)->unique()->values()->all())
            ->all();
    }

    /**
     * 處理代收金流（type = 1）
     *
     * 建立 OrderType 關聯和銀行選項
     *
     * 限額優先順序：
     *  1. example.invalid['min/max_amount']  —— DB 手動覆寫,最高優先
     *  2. provider config 渠道（by key）    —— 來自 Data/providers/{Name}.php deposit.channels
     *  3. example.invalid[currency]           —— 幣別全域預設,最低優先
     *
     * @param  string|null  $channelKey  若提供,只處理 OrderType.key === $channelKey 的渠道（per-channel 模式）
     * @param  bool  $createMissingOnly  遷移後續跑：既有 ACFOT 原樣保留（含手動限額/settings/status），僅補建缺漏
     * @param  int  $status  支付通道關聯的上下架狀態（--status；0 同時隱藏後台顯示）
     */
    protected function processDepositCashFlow(
        AgentCashFlow $agentCashFlow,
        CashFlow $cashFlow,
        int $currencyId,
        float $minAmount,
        float $maxAmount,
        array $providerChannels,
        array &$stats,
        ?string $channelKey = null,
        bool $createMissingOnly = false,
        int $status = 1,
    ): bool {
        // 查詢指定幣別的父層 OrderType（per-channel 模式只取對應 key 的渠道）
        $orderTypesQuery = OrderType::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('parent', 0)
            ->where('currency_id', $currencyId);

        if ($channelKey !== null) {
            // 拆分模式：依 config channel 藍圖預建配置，不受 DB order_types.status 停用狀態影響
            // （避免 config 有 channel、但該站對應父層 OrderType 停用時撞 return false → 整批 rollback）。
            // 會員可見性由前端 OrderTypeService::getOrderTypes 的 order_types.status=1 主控，此處放寬不影響會員面。
            $orderTypesQuery->where('key', $channelKey);
        } else {
            // 單筆模式：維持只掛啟用中的渠道
            $orderTypesQuery->where('status', 1);
        }

        $orderTypes = $orderTypesQuery->get();

        if ($orderTypes->isEmpty()) {
            $this->warn(sprintf('  ⚠️  幣別 %d 在該金流商沒有可用的 OrderType', $currencyId));

            return false;
        }

        $this->line(sprintf('  處理 %d 個支付渠道...', $orderTypes->count()));

        foreach ($orderTypes as $orderType) {
            // 限額優先順序：1. example.invalid > 2. provider config 渠道（by key）> 3. example.invalid[currency]
            $orderTypeInfo = $this->decodeInfoArray($orderType->info);
            $channelConfig = collect($providerChannels)->firstWhere('key', (string) $orderType->key) ?? [];
            $parentMinAmount = $orderTypeInfo['min_amount'] ?? ($channelConfig['min_amount'] ?? $minAmount);
            $parentMaxAmount = $orderTypeInfo['max_amount'] ?? ($channelConfig['max_amount'] ?? $maxAmount);

            // 驗證父層金額合理性
            if ($parentMinAmount < 0 || $parentMaxAmount < 0) {
                $this->warn(sprintf('    ⚠️  跳過 %s: 金額不能為負數', $orderType->name));

                continue;
            }

            if ($parentMaxAmount > 0 && $parentMinAmount > $parentMaxAmount) {
                $this->warn(sprintf('    ⚠️  跳過 %s: min (%s) > max (%s)', $orderType->name, $parentMinAmount, $parentMaxAmount));

                continue;
            }

            // 建立或更新 AgentCashFlowOrderType；遷移後續跑僅補缺（保留既有列原值）
            if ($this->upsertOrderTypeLink($agentCashFlow, (int) $orderType->id, $parentMinAmount, $parentMaxAmount, $createMissingOnly, $stats, $status)) {
                $this->line(sprintf('    + %s (min: %s, max: %s)', $orderType->name, $parentMinAmount, $parentMaxAmount));
            } else {
                $this->line(sprintf('    = %s（既有，保留原配置）', $orderType->name));
            }

            // 處理銀行選項
            if ($orderType->choose_bank == 1) {
                $this->attachBankOptions($agentCashFlow, $orderType, $currencyId, $parentMinAmount, $parentMaxAmount, $stats, $createMissingOnly, $status);
            }
        }

        return true;
    }

    /**
     * 為 choose_bank=1 的 OrderType 建立子層銀行選項的 AgentCashFlowOrderType
     *
     * @param  AgentCashFlow  $agentCashFlow  代理金流物件
     * @param  OrderType  $orderType  父層 OrderType
     * @param  int  $currencyId  幣別 ID
     * @param  float  $parentMinAmount  父層最小金額
     * @param  float  $parentMaxAmount  父層最大金額
     * @param  array  $stats  統計資訊（引用傳遞）
     * @param  bool  $createMissingOnly  遷移後續跑：既有銀行選項 ACFOT 原樣保留，僅補建缺漏
     */
    protected function attachBankOptions(
        AgentCashFlow $agentCashFlow,
        OrderType $orderType,
        int $currencyId,
        float $parentMinAmount,
        float $parentMaxAmount,
        array &$stats,
        bool $createMissingOnly = false,
        int $status = 1,
    ): void {
        // 查詢子層 OrderType（銀行選項）
        $bankOptions = OrderType::query()
            ->where('parent', $orderType->id)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->get();

        if ($bankOptions->isEmpty()) {
            $this->warn(sprintf('      ⚠️  %s 沒有可用的銀行選項', $orderType->name));

            return;
        }

        foreach ($bankOptions as $bank) {
            // 優先順序：子層 example.invalid > 父層金額 > example.invalid[currency]
            $bankInfo = $this->decodeInfoArray($bank->info);
            $finalMinAmount = $bankInfo['min_amount'] ?? $parentMinAmount;
            $finalMaxAmount = $bankInfo['max_amount'] ?? $parentMaxAmount;

            // 驗證子層金額合理性
            if ($finalMinAmount < 0 || $finalMaxAmount < 0) {
                $this->warn(sprintf('        ⚠️  跳過 %s: 金額不能為負數', $bank->name));

                continue;
            }

            if ($finalMaxAmount > 0 && $finalMinAmount > $finalMaxAmount) {
                $this->warn(sprintf('        ⚠️  跳過 %s: min (%s) > max (%s)', $bank->name, $finalMinAmount, $finalMaxAmount));

                continue;
            }

            $this->upsertOrderTypeLink($agentCashFlow, (int) $bank->id, $finalMinAmount, $finalMaxAmount, $createMissingOnly, $stats, $status);
        }

        $this->line(sprintf('      + 已處理 %d 個銀行選項', $bankOptions->count()));
    }

    /**
     * 建立或更新單筆 AgentCashFlowOrderType（父層渠道與子層銀行選項共用）
     *
     * 遷移後續跑（$createMissingOnly）改用 firstOrCreate 僅補缺，保留既有列的
     * 手動限額／settings／status 原值；一般路徑 updateOrCreate 以最新限額覆寫。
     * 代收的上下架開關在這一層：status 取 --status，backend_display 跟著 status 走（停用即隱藏）。
     *
     * @param  int|float  $minAmount  最小限額
     * @param  int|float  $maxAmount  最大限額
     * @param  array  $stats  統計資訊（引用傳遞）
     * @return bool 是否為新建（false = 既有列被保留原值）
     */
    private function upsertOrderTypeLink(
        AgentCashFlow $agentCashFlow,
        int $orderTypeId,
        int|float $minAmount,
        int|float $maxAmount,
        bool $createMissingOnly,
        array &$stats,
        int $status = 1,
    ): bool {
        $values = [
            'cash_flow_id' => $agentCashFlow->cash_flow_id,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'available_amount' => '',
            'settings' => '{}',
            'sorts' => 0,
            'status' => $status,
            'backend_display' => $status,
        ];

        if ($createMissingOnly) {
            $acfot = $agentCashFlow->agentCashFlowOrderTypes()->firstOrCreate(
                ['agent_cash_flow_id' => $agentCashFlow->id, 'order_type_id' => $orderTypeId],
                $values,
            );

            if (!$acfot->wasRecentlyCreated) {
                return false;
            }

            $stats['order_types_created']++;

            return true;
        }

        $agentCashFlow->agentCashFlowOrderTypes()->updateOrCreate(
            ['agent_cash_flow_id' => $agentCashFlow->id, 'order_type_id' => $orderTypeId],
            $values,
        );
        $stats['order_types_created']++;

        return true;
    }

    /** example.invalid 兼容 string／array 兩種儲存形態，統一轉為 array */
    private function decodeInfoArray(mixed $info): array
    {
        if (is_string($info)) {
            return json_decode($info, true) ?? [];
        }

        return $info ?? [];
    }

    // =========================================================================
    // 代付處理：available_banks 與 banks 表同步
    // =========================================================================

    /**
     * 處理代付金流（type = 3）
     *
     * 更新 available_banks 並同步銀行資料
     *
     * 限額優先順序：
     *  1. example.invalid[currency]  —— DB 已配置，最高優先
     *  2. provider config 渠道     —— 來自 Data/providers/{Name}.php withdrawal.channels
     */
    protected function processWithdrawalCashFlow(
        AgentCashFlow $agentCashFlow,
        CashFlow $cashFlow,
        Agent $agent,
        int $currencyId,
        float $minAmount,
        float $maxAmount,
        array $providerChannels,
        array &$stats
    ): bool {
        // 取得幣別資訊用於顯示
        $currency = Currency::find($currencyId);
        $currencyCode = $currency ? $currency->code : $currencyId;

        $this->line(sprintf('  處理幣別: %s (ID: %d)', $currencyCode, $currencyId));

        // 查詢可用銀行（只查詢指定幣別）
        // 部分支付商的 provider_bank_code 可能為 0
        $sourceBanks = CashFlowBank::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->select('bank_code', 'provider_bank_code')
            ->get()
            ->filter(
                fn ($bank) => Common::isNotEmptyString($bank->bank_code) &&
                Common::isNotEmptyString($bank->provider_bank_code)
            );

        if ($sourceBanks->isEmpty()) {
            $this->warn(sprintf('  ⚠️  幣別 %s (%d) 沒有可用的銀行', $currencyCode, $currencyId));

            return false;
        }

        // 提前計算 agentGroupId（available_banks 建構及 banks 表同步共用）
        $agentGroupId = $agent->cash_flow_group_id;

        // 取得現有的 available_banks 以保留設定（如 status, sort 等）
        $currentInfo = $agentCashFlow->info ?? [];
        $existingBanksMap = collect($currentInfo['available_banks'] ?? [])->keyBy('BankCode_ELG')->all();

        // 渠道有效限額僅用於 example.invalid 頂層與 banks 表同步（available_banks 已不存 per-bank 限額）
        // 限額優先順序：example.invalid[currency] > provider config 渠道 > 0（不限制）
        [$effectiveMinAmount, $effectiveMaxAmount] = $this->resolveChannelAmounts($minAmount, $maxAmount, $providerChannels);

        // 查詢同群組同幣別已存在的 banks row，syncBanksToAgentGroup 會依此沿用既有限額
        $agentBanksMap = Bank::query()
            ->where('agent_group_id', $agentGroupId)
            ->where('currency_id', $currencyId)
            ->whereIn('code', $sourceBanks->pluck('bank_code')->all())
            ->get()
            ->keyBy('code');

        // 構建新的 available_banks，僅保留 BankCode_ELG / BankCode_Third 對應，限額由 banks 表負責
        $availableBanks = $this->buildAvailableBanks(
            sourceBanks: $sourceBanks,
            existingBanksMap: $existingBanksMap,
        );

        $bankCodesList = array_column($availableBanks, 'BankCode_ELG');
        $this->line(sprintf(
            '  找到 %d 個可用銀行 (%s 幣別): %s',
            count($availableBanks),
            $currencyCode,
            implode(', ', array_slice($bankCodesList, 0, 5)) . (count($bankCodesList) > 5 ? '...' : '')
        ));

        $newData = ['available_banks' => $availableBanks];

        if ($effectiveMinAmount > 0) {
            $newData['min_amount'] = $effectiveMinAmount;
        }

        if ($effectiveMaxAmount > 0) {
            $newData['max_amount'] = $effectiveMaxAmount;
        }

        $agentCashFlow->update(['info' => array_merge($currentInfo, $newData)]);

        // 同步銀行資料到代理群組
        $this->syncBanksToAgentGroup(
            bankCodes: array_column($availableBanks, 'BankCode_ELG'),
            agentGroupId: $agentGroupId,
            currencyId: $currencyId,
            effectiveMinAmount: $effectiveMinAmount,
            effectiveMaxAmount: $effectiveMaxAmount,
            agentBanksMap: $agentBanksMap,
            stats: $stats,
        );

        return true;
    }

    /**
     * 計算渠道有效限額
     *
     * 限額優先順序：傳入參數（DB 已配置）> provider config 渠道 > 0（不限制）
     *
     * @return array{0: int, 1: int} [effectiveMinAmount, effectiveMaxAmount]
     */
    private function resolveChannelAmounts(float $minAmount, float $maxAmount, array $providerChannels): array
    {
        $providerChannel = $providerChannels[0] ?? [];

        $effectiveMinAmount = $minAmount > 0 ? (int) $minAmount : (int) ($providerChannel['min_amount'] ?? 0);
        $effectiveMaxAmount = $maxAmount > 0 ? (int) $maxAmount : (int) ($providerChannel['max_amount'] ?? 0);

        return [$effectiveMinAmount, $effectiveMaxAmount];
    }

    /**
     * 構建 available_banks 陣列
     *
     * 遍歷所有來源銀行，逐筆呼叫 buildBankEntry 組裝最終結果
     */
    private function buildAvailableBanks(
        Collection $sourceBanks,
        array $existingBanksMap,
    ): array {
        return $sourceBanks->map(
            fn ($sourceBank) => $this->buildBankEntry(
                sourceBank: $sourceBank,
                existingBanksMap: $existingBanksMap,
            )
        )->values()->toArray();
    }

    /**
     * 組裝單筆銀行項目
     *
     * available_banks 僅作為 BankCode_ELG → BankCode_Third 的對應表，限額 source-of-truth 為 banks 表。
     * 若銀行已存在於 available_banks，委派 mergeExistingBankEntry 同步 provider 代碼並清理遺留限額欄位。
     */
    private function buildBankEntry(
        mixed $sourceBank,
        array $existingBanksMap,
    ): array {
        $bankCode = $sourceBank->bank_code;
        $providerBankCode = $sourceBank->provider_bank_code;

        if (isset($existingBanksMap[$bankCode])) {
            return $this->mergeExistingBankEntry(
                existingBank: $existingBanksMap[$bankCode],
                providerBankCode: $providerBankCode,
            );
        }

        return [
            'BankCode_ELG' => $bankCode,
            'BankCode_Third' => $providerBankCode,
        ];
    }

    /**
     * 合併已存在的銀行項目
     *
     * - 更新 BankCode_Third 為最新值
     * - 清除遺留的 per-bank 限額欄位（MinAmount/MaxAmount/min_amount/max_amount），限額由 banks 表負責
     */
    private function mergeExistingBankEntry(
        array $existingBank,
        string $providerBankCode,
    ): array {
        $existingBank['BankCode_Third'] = $providerBankCode;

        unset(
            $existingBank['MinAmount'],
            $existingBank['MaxAmount'],
            $existingBank['min_amount'],
            $existingBank['max_amount'],
        );

        return $existingBank;
    }

    /**
     * 將銀行同步到代理群組（agent_group_id）
     *
     * 以基準銀行（agent_group_id=0）為模板 updateOrCreate，
     * rate 優先沿用同群組既有設定；限額保留 banks 表非零值，否則使用渠道有效限額。
     */
    private function syncBanksToAgentGroup(
        array $bankCodes,
        int $agentGroupId,
        int $currencyId,
        int $effectiveMinAmount,
        int $effectiveMaxAmount,
        Collection $agentBanksMap,
        array &$stats,
    ): void {
        // 委派共用 AgentBankSyncService（code 四元組去重、code 穩定不覆寫、融合 !1769 active 優先/撞鍵防護）。
        // $agentBanksMap 保留維持呼叫端簽名相容，body 不再使用（限額由 service 內部從 active target 取）。
        unset($agentBanksMap);

        $result = app(AgentBankSyncService::class)->syncToAgentGroup(
            $bankCodes,
            $agentGroupId,
            $currencyId,
            $effectiveMinAmount,
            $effectiveMaxAmount,
        );

        if ($result['created'] > 0) {
            $this->line(sprintf('  ✓ 已新增 %d 個銀行', $result['created']));
            $stats['banks_created'] += $result['created'];
        }

        if ($result['updated'] > 0) {
            $this->line(sprintf('  ✓ 已更新 %d 個銀行', $result['updated']));
            $stats['banks_updated'] = ($stats['banks_updated'] ?? 0) + $result['updated'];
        }

        if ($result['skipped'] > 0) {
            $this->warn(sprintf('  ⚠️ 略過 %d 個銀行（碼被同範疇 active 列持有，待 dedup／人工合併）', $result['skipped']));
        }

        if ($result['created'] === 0 && $result['updated'] === 0 && $result['skipped'] === 0) {
            $this->line('  - 銀行無變更');
        }
    }

    // =========================================================================
    // 乾跑預覽與統計輸出
    // =========================================================================

    /**
     * 顯示數據變更預覽
     *
     * 在乾跑模式下，在回滾前查詢並顯示變更的數據
     *
     * @param  Agent  $agent  代理
     * @param  CashFlow  $cashFlow  金流商
     * @param  Currency  $currency  幣別
     */
    protected function displayDataChanges(Agent $agent, CashFlow $cashFlow, Currency $currency): void
    {
        $this->info('📋 數據變更預覽:');
        $this->newLine();

        // 查詢 AgentCashFlow
        $agentCashFlow = AgentCashFlow::query()
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('cash_flow_id', $cashFlow->id)
            ->where('currency_id', $currency->id)
            ->first();

        if (!$agentCashFlow) {
            $this->warn('  ⚠️  未找到 AgentCashFlow 數據');

            return;
        }

        // 顯示 available_banks 差異
        $this->displayAvailableBanksDifference($agentCashFlow, $agent, $cashFlow, $currency);

        // 根據金流類型顯示關聯數據
        if ($cashFlow->type === 1) {
            $this->displayOrderTypesTable($cashFlow, $currency);
        } elseif ($cashFlow->type === 3) {
            $this->displayBanksTable($agent, $cashFlow, $currency);
        }

        $this->newLine();
        $this->comment('💡 提示: 以上數據在事務中已創建，但尚未提交到資料庫');
    }

    /**
     * 顯示 info.available_banks 的前後差異
     */
    protected function displayAvailableBanksDifference(
        AgentCashFlow $agentCashFlow,
        Agent $agent,
        CashFlow $cashFlow,
        Currency $currency
    ): void {
        // 查詢資料庫中的舊數據（繞過事務）
        $oldData = DB::connection()
            ->table('agent_cash_flows')
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('cash_flow_id', $cashFlow->id)
            ->where('currency_id', $currency->id)
            ->first();

        // 獲取新舊 info
        $newInfo = $agentCashFlow->info ?? [];
        $oldInfo = $oldData ? json_decode($oldData->info, true) ?? [] : [];

        // 獲取 available_banks
        $newBanks = $newInfo['available_banks'] ?? [];
        $oldBanks = $oldInfo['available_banks'] ?? [];

        // 如果都為空，不顯示
        if (empty($newBanks) && empty($oldBanks)) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=yellow>Info.available_banks 差異:</>');

        // 轉換為易於比對的格式（以 BankCode_ELG 作為 key，此為 available_banks 的標準欄位）
        $oldBanksMap = collect($oldBanks)->keyBy('BankCode_ELG');
        $newBanksMap = collect($newBanks)->keyBy('BankCode_ELG');

        // 收集所有銀行代碼
        $allCodes = $oldBanksMap->keys()->merge($newBanksMap->keys())->unique()->sort()->values();

        if ($allCodes->isEmpty()) {
            $this->line('    <fg=gray>無銀行配置</>');

            return;
        }

        $rows = [];
        foreach ($allCodes as $code) {
            $row = $this->buildBankDiffRow((string) $code, $oldBanksMap->get($code), $newBanksMap->get($code));
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if (empty($rows)) {
            $this->line('    <fg=gray>無差異</>');
        } else {
            $this->table(
                ['Bank Code', 'Bank Name', '舊狀態', '新狀態', '變更'],
                $rows
            );
        }
    }

    /**
     * 組裝單一銀行的差異列（新增／刪除／修改）；無差異返回 null
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}|null
     */
    private function buildBankDiffRow(string $code, ?array $oldBank, ?array $newBank): ?array
    {
        if (!$oldBank && $newBank) {
            return [
                $code,
                $newBank['bank_name'] ?? '-',
                '-',
                $this->formatBankStatus($newBank),
                '<fg=green>+ 新增</>',
            ];
        }

        if ($oldBank && !$newBank) {
            return [
                $code,
                $oldBank['bank_name'] ?? '-',
                $this->formatBankStatus($oldBank),
                '-',
                '<fg=red>- 刪除</>',
            ];
        }

        if ($oldBank && $newBank) {
            $oldStatus = $this->formatBankStatus($oldBank);
            $newStatus = $this->formatBankStatus($newBank);

            // 無變更時不顯示
            if ($oldStatus !== $newStatus) {
                return [
                    $code,
                    $newBank['bank_name'] ?? '-',
                    $oldStatus,
                    $newStatus,
                    '<fg=cyan>~ 修改</>',
                ];
            }
        }

        return null;
    }

    /**
     * 格式化銀行狀態資訊
     */
    protected function formatBankStatus(array $bank): string
    {
        $parts = [];

        if (isset($bank['status'])) {
            $parts[] = $bank['status'] ? '啟用' : '停用';
        }

        if (isset($bank['min_amount']) || isset($bank['max_amount']) || isset($bank['MinAmount']) || isset($bank['MaxAmount'])) {
            $min = $bank['min_amount'] ?? $bank['MinAmount'] ?? '0';
            $max = $bank['max_amount'] ?? $bank['MaxAmount'] ?? '0';
            $parts[] = sprintf('限額:%s~%s', $min, $max);
        }

        return empty($parts) ? '-' : implode(', ', $parts);
    }

    /** 輸出代收渠道的 OrderType 表格（父層，parent=0） */
    protected function displayOrderTypesTable(CashFlow $cashFlow, Currency $currency): void
    {
        $orderTypes = OrderType::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('currency_id', $currency->id)
            ->where('parent', 0)
            ->get();

        if ($orderTypes->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line(sprintf('  <fg=cyan>OrderTypes (代收渠道):</> 共 %d 筆', $orderTypes->count()));

        $rows = $orderTypes->map(function ($ot) {
            return [
                $ot->id,
                $ot->name,
                $ot->key,
                $ot->parent ?: '-',
                $ot->status ? '啟用' : '停用',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Name', 'Key', 'Parent', 'Status'],
            $rows
        );
    }

    /** 輸出代付銀行的 Banks 表格（同群組同幣別） */
    protected function displayBanksTable(Agent $agent, CashFlow $cashFlow, Currency $currency): void
    {
        $banks = Bank::query()
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('currency_id', $currency->id)
            ->whereIn('name', function ($query) use ($cashFlow, $currency) {
                $query->select('banks.name')
                    ->from('banks')
                    ->join('cash_flow_banks', 'banks.id', '=', 'cash_flow_banks.bank_id')
                    ->where('cash_flow_banks.cash_flow_id', $cashFlow->id)
                    ->where('cash_flow_banks.currency_id', $currency->id)
                    ->where('banks.agent_group_id', 0);
            })
            ->get();

        if ($banks->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line(sprintf('  <fg=cyan>Banks (代付銀行):</> 共 %d 筆', $banks->count()));

        $rows = $banks->map(function ($bank) {
            return [
                $bank->id,
                $bank->name,
                $bank->code,
                $bank->min_amount,
                $bank->max_amount,
                $bank->status ? '啟用' : '停用',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Name', 'Code', 'Min Amount', 'Max Amount', 'Status'],
            $rows
        );
    }

    /** 輸出執行統計表格 */
    protected function printStats(array $stats): void
    {
        $this->newLine();
        $this->info('執行統計:');

        $this->table(
            ['項目', '數量'],
            [
                ['AgentCashFlow (新增)', $stats['agent_cash_flow_created']],
                ['AgentCashFlow (更新)', $stats['agent_cash_flow_updated']],
                ['OrderType 關聯', $stats['order_types_created']],
                ['銀行新增', $stats['banks_created']],
                ['銀行更新', $stats['banks_updated'] ?? 0],
            ]
        );
    }
}
