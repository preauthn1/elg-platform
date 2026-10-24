<?php

declare(strict_types=1);

namespace App\Console\Commands\Order;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * 檢查提款訂單可用的代付渠道
 * php artisan withdrawal:check-payout-channels 1958788
 *
 * ⚠️ 本命令永遠只讀 ACF 的 available_banks JSON；實際出款（WithdrawalPayoutTrait::convertPayoutBankCode）
 * 在 provider 已進 payout_bank_lookup_providers allowlist 時改查 cash_flow_banks 表。兩者來源不同，
 * 這裡顯示「可用」不等於出款會成功；反過來，cash_flow_banks 有對映但 JSON 沒有時這裡會誤報不支援。
 */
class CheckWithdrawalOrderPayoutChannels extends Command
{
    protected $signature = 'withdrawal:check-payout-channels {order_id? : 提款訂單 ID}';

    protected $description = '檢查提款訂單可用的代付渠道';

    public function handle(): int
    {
        $orderId = $this->argument('order_id');

        if (!$orderId) {
            $orderId = $this->ask('請輸入提款訂單 ID');

            if (!$orderId) {
                $this->error('訂單 ID 不可為空');

                return self::FAILURE;
            }
        }

        $orderId = (int) $orderId;

        try {
            $order = WithdrawalOrder::with(['agent', 'member', 'bank'])->findOrFail($orderId);
        } catch (ModelNotFoundException $e) {
            $this->error("訂單 ID {$orderId} 不存在");

            return self::FAILURE;
        }

        // 查詢 bank_code 對應的銀行名稱，供後續顯示使用
        $bankName = Bank::where('code', $order->bank_code)->value('name') ?? $order->bank_code;

        // 從銀行取得 currency_id
        $currencyId = $order->bank?->currency_id;

        if (!$currencyId) {
            $this->error('無法取得訂單銀行的幣別資訊');

            return self::FAILURE;
        }

        if ($order->owner instanceof Member) {
            $order->owner->loadMissing('agent');
            $cashFlowGroupId = (int) $order->owner->agent->cash_flow_group_id;
        } else {
            $cashFlowGroupId = (int) $order->owner->cash_flow_group_id;
        }

        $this->displayOrderInfo($order, $bankName, $cashFlowGroupId);

        $allChannels = $this->fetchPayoutChannels($cashFlowGroupId, $currencyId);

        $this->info('查詢條件：');
        $this->line("  • agent_group_id: {$cashFlowGroupId}");
        $this->line("  • currency_id: {$currencyId}");
        $this->line('  • cash_flows.type: 3 (代付)');
        $this->newLine();

        $this->info("找到 {$allChannels->count()} 個可用的代付渠道");

        if ($allChannels->isEmpty()) {
            // 提供诊断信息
            $this->newLine();
            $this->warn('⚠️  未找到符合条件的代付渠道，进行诊断...');
            $this->diagnoseMissingChannels($cashFlowGroupId, $currencyId);
        }

        $this->newLine();

        $availableChannels = $this->filterAvailableChannels(
            $allChannels,
            $order->bank_code,
            $bankName,
            $order->amount
        );

        $this->displayResult($availableChannels);

        return self::SUCCESS;
    }

    /**
     * 顯示訂單資訊
     */
    private function displayOrderInfo(WithdrawalOrder $order, string $bankName, int $cashFlowGroupId): void
    {
        $this->info('訂單資訊');
        $this->line("  • 訂單 ID: {$order->id}");
        $this->line("  • 代理群組 ID: {$cashFlowGroupId}");
        $this->line("  • 銀行代碼: {$order->bank_code} ");
        $this->line("  • 銀行名稱: {$bankName}");
        $this->line("  • 幣別 ID: {$order->bank?->currency_id}");
        $this->line("  • 提款金額: {$order->amount}");
        $this->newLine();
    }

    /**
     * 診斷為何沒有找到代付渠道
     */
    private function diagnoseMissingChannels(int $cashFlowGroupId, int $currencyId): void
    {
        // 查詢該代理群組與幣別下所有 agent_cash_flows 記錄（不含代付條件過濾）
        $agentCashFlowRecords = AgentCashFlow::query()
            ->with('cashFlow')
            ->where('agent_group_id', $cashFlowGroupId)
            ->where('currency_id', $currencyId)
            ->get();

        if ($agentCashFlowRecords->isEmpty()) {
            $this->error('❌ 數據庫中不存在該代理群組和幣別的 agent_cash_flows 記錄');
            $this->line('   請先為該代理群組配置對應幣別的代付金流商');

            return;
        }

        $this->warn(sprintf('⚠️  找到 %d 條 agent_cash_flows 記錄，但沒有符合代付條件：', $agentCashFlowRecords->count()));
        $this->newLine();

        $resolveTypeLabel = fn (int $type): string => match ($type) {
            1 => '代收',
            2 => '人工代付',
            3 => '代付',
            default => '未知',
        };

        $resolveIssues = function (AgentCashFlow $agentCashFlow) use ($resolveTypeLabel): string {
            $issues = array_filter([
                $agentCashFlow->cashFlow->type != 3 ? sprintf('類型=%s(需要3)', $resolveTypeLabel((int) $agentCashFlow->cashFlow->type)) : null,
                $agentCashFlow->cashFlow->status != 1 ? 'CF停用' : null,
                $agentCashFlow->status != 1 ? 'ACF停用' : null,
                in_array($agentCashFlow->cash_flow_id, [5, 6]) ? '系統自動處理' : null,
            ]);

            return implode(', ', $issues);
        };

        $tableData = $agentCashFlowRecords->map(fn (AgentCashFlow $agentCashFlow) => [
            'ACF_ID' => $agentCashFlow->id,
            'CF_ID' => $agentCashFlow->cash_flow_id,
            'CF_Name' => $agentCashFlow->cashFlow->name,
            'Type' => $resolveTypeLabel((int) $agentCashFlow->cashFlow->type),
            'ACF_Status' => $agentCashFlow->status ? '啟用' : '停用',
            'CF_Status' => $agentCashFlow->cashFlow->status ? '啟用' : '停用',
            'Issue' => $resolveIssues($agentCashFlow),
        ])->toArray();

        $this->table(
            ['ACF_ID', 'CF_ID', 'CF_Name', 'Type', 'ACF_Status', 'CF_Status', 'Problem'],
            $tableData
        );

        $suggestions = array_filter([
            $agentCashFlowRecords->contains(fn (AgentCashFlow $agentCashFlow) => $agentCashFlow->cashFlow->type != 3) ? '   • 請為該代理群組配置 type=3 (代付) 的金流商' : null,
            $agentCashFlowRecords->contains(fn (AgentCashFlow $agentCashFlow) => $agentCashFlow->cashFlow->status != 1 || $agentCashFlow->status != 1) ? '   • 檢查並啟用相關的金流商配置' : null,
        ]);

        if (!empty($suggestions)) {
            $this->newLine();
            $this->line('💡 建議：');
            foreach ($suggestions as $suggestion) {
                $this->line($suggestion);
            }
        }
    }

    /**
     * 取得代付渠道列表
     */
    private function fetchPayoutChannels(int $cashFlowGroupId, int $currencyId): Collection
    {
        return AgentCashFlow::query()
            ->with('cashFlow')
            ->whereHas('cashFlow', function ($query) {
                $query->where('type', 3) // 3: 代付
                    ->where('status', 1);
            })
            ->where('agent_group_id', $cashFlowGroupId)
            ->where(function ($query) use ($currencyId) {
                $query->where('currency_id', $currencyId)
                    ->orWhereNull('currency_id'); // 包含 currency_id 為 NULL 的渠道（用於相容性）
            })
            ->where('status', 1)
            ->whereNotIn('cash_flow_id', [5, 6]) // 排除 AutoDeposit/AutoWithdrawal（由系統自動處理，不走代付流程）
            // ->tap(function ($query) {
            //     $rawSql = vsprintf(str_replace('?', '%s', $query->toSql()), $query->getBindings());
            //     $this->info('SQL:');
            //     $this->line($rawSql);
            //     $this->newLine();
            // })
            ->get()
            ->map(fn (AgentCashFlow $agentCashFlow) => (object) [
                'cash_flow_id' => $agentCashFlow->cash_flow_id,
                'cash_flow_name' => $agentCashFlow->cashFlow->name,
                'agent_cash_flow_id' => $agentCashFlow->id,
                'agent_cash_flow_name' => $agentCashFlow->info['DisplayName'] ?? $agentCashFlow->name,
                'info' => $agentCashFlow->info,  // 已由 Model cast 為 array
                'currency_id' => $agentCashFlow->currency_id,
            ]);
    }

    /**
     * 篩選可用的代付渠道
     */
    private function filterAvailableChannels(Collection $channels, string $targetBankCode, string $bankName, string $amount): Collection
    {
        if ($channels->isEmpty()) {
            return $channels;
        }

        // 與舊版邏輯一致：若未指定銀行代碼，直接回傳原渠道列表
        if (!Common::isNotEmptyString($targetBankCode)) {
            return $channels;
        }

        // 正規化金額，避免外部數值精度誤差
        $normalizedAmount = Common::numAdd('0', $amount, 2);

        $excludedRows = [];

        $availableChannels = $channels->filter(function ($channel) use ($targetBankCode, $bankName, $normalizedAmount, &$excludedRows) {
            if (!$channel->info) {
                return false;
            }

            // info 已由 AgentCashFlow Model cast 為 array，無需再 json_decode
            $setting = $channel->info;

            $reason = $this->resolveExcludeReason($setting, $targetBankCode, $bankName, $normalizedAmount);

            if ($reason !== null) {
                $excludedRows[] = [
                    $channel->cash_flow_id,
                    $channel->cash_flow_name,
                    $channel->agent_cash_flow_id,
                    $channel->agent_cash_flow_name,
                    $reason,
                ];

                return false;
            }

            return true;
        })->values();

        if (!empty($excludedRows)) {
            $this->newLine();
            $this->warn(sprintf('⚠️  排除了 %d 個不符合條件的代付渠道：', count($excludedRows)));
            $this->table(
                ['cash_flow_id', 'cash_flow_name', 'agent_cash_flow_id', 'agent_cash_flow_name', 'reason'],
                $excludedRows
            );
            $this->newLine();
        }

        return $availableChannels;
    }

    /**
     * 解析渠道被排除的原因，回傳原因字串；若符合條件則回傳 null
     */
    private function resolveExcludeReason(array $setting, string $targetBankCode, string $bankName, string $amount): ?string
    {
        if (!isset($setting['available_banks'])) {
            return '缺少 available_banks 設定';
        }

        if (!is_array($setting['available_banks'])) {
            return 'available_banks 格式錯誤';
        }

        if (isset($setting['min_amount'])) {
            $minAmount = Common::numAdd('0', (string) $setting['min_amount'], 2);
            if (Common::numCompare($minAmount, $amount, 2) === 1) {
                return sprintf('最小金額 %s > 訂單金額 %s', $minAmount, $amount);
            }
        }

        if (isset($setting['max_amount'])) {
            $maxAmount = Common::numAdd('0', (string) $setting['max_amount'], 2);
            if (Common::numCompare($maxAmount, $amount, 2) === -1) {
                return sprintf('最大金額 %s < 訂單金額 %s', $maxAmount, $amount);
            }
        }

        $supportedBankCodes = collect($setting['available_banks'])->pluck('BankCode_ELG');

        if (!$supportedBankCodes->contains($targetBankCode)) {
            return sprintf('不支援銀行 "%s" (%s)', $bankName, $targetBankCode);
        }

        return null;
    }

    /**
     * 顯示結果
     */
    private function displayResult($availableChannels): void
    {
        if ($availableChannels->isEmpty()) {
            $this->error('❌ 沒有找到可用的代付渠道');

            return;
        }

        $this->info('✅ 找到以下可用的代付渠道：');

        $this->table(
            ['cash_flow_id', 'cash_flow_name', 'agent_cash_flow_id', 'agent_cash_flow_name'],
            $availableChannels->map(fn ($channel) => [
                $channel->cash_flow_id,
                $channel->cash_flow_name,
                $channel->agent_cash_flow_id,
                $channel->agent_cash_flow_name,
            ])
        );
    }
}
