<?php

declare(strict_types=1);

namespace App\Console\Commands\Order;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * 查詢指定代理群組與幣別下，各銀行代碼支援哪些代付渠道
 * php artisan withdrawal:check-bank-payout-support --agent_group_id=3 --currency_id=1
 */
class CheckBankPayoutChannelSupport extends Command
{
    protected $signature = 'withdrawal:check-bank-payout-support
        {--agent_group_id= : 代理群組 ID}
        {--currency_id= : 幣別 ID}';

    protected $description = '查詢指定代理群組與幣別下，各銀行代碼對應哪些代付渠道';

    public function handle(): int
    {
        $agentGroupId = $this->option('agent_group_id');
        $currencyId = $this->option('currency_id');

        if (!$agentGroupId) {
            $agentGroupId = $this->ask('請輸入代理群組 ID (agent_group_id)');
        }

        if (!$currencyId) {
            $currencyId = $this->ask('請輸入幣別 ID (currency_id)');
        }

        if (!$agentGroupId || !$currencyId) {
            $this->error('agent_group_id 與 currency_id 均不可為空');

            return self::FAILURE;
        }

        $agentGroupId = (int) $agentGroupId;
        $currencyId = (int) $currencyId;

        // 取得該幣別下所有啟用的銀行
        $banks = Bank::where('currency_id', $currencyId)
            ->where('status', 1)
            ->orderBy('code')
            ->get();

        if ($banks->isEmpty()) {
            $this->error("找不到 currency_id={$currencyId} 的銀行資料");

            return self::FAILURE;
        }

        $this->info("幣別 ID {$currencyId} 共找到 {$banks->count()} 間銀行");
        $this->newLine();

        // 取得代付渠道列表
        $channels = $this->fetchPayoutChannels($agentGroupId, $currencyId);

        $this->info("代理群組 {$agentGroupId} 共找到 {$channels->count()} 個代付渠道");
        $this->newLine();

        if ($channels->isEmpty()) {
            $this->warn('⚠️  沒有可用的代付渠道，無法進行銀行支援比對');

            return self::SUCCESS;
        }

        // 為每個渠道建立 BankCode_ELG 集合，加速後續查詢
        $channelBankCodeSets = $channels->map(function ($channel) {
            $availableBanks = $channel->info['available_banks'] ?? [];

            return collect($availableBanks)
                ->pluck('BankCode_ELG')
                ->filter(fn ($bankCode) => $bankCode !== null && $bankCode !== '')
                ->map(fn ($bankCode) => (string) $bankCode)
                ->unique()
                ->values();
        });

        // 以 bank_code 分組後再計算支援渠道，避免同 code 重複輸出
        $tableRows = $banks
            ->groupBy(fn (Bank $bank) => (string) $bank->code)
            ->map(function (Collection $groupedBanks, string $bankCode) use ($channels, $channelBankCodeSets) {
                $supported = $channels->filter(function ($channel, $index) use ($bankCode, $channelBankCodeSets) {
                    return $channelBankCodeSets->get($index)?->contains($bankCode) ?? false;
                });

                $bankNames = $groupedBanks
                    ->pluck('name')
                    ->filter(fn ($name) => is_string($name) && $name !== '')
                    ->unique()
                    ->values();

                return [
                    'bank_code' => $bankCode,
                    'bank_name' => $bankNames->implode(' / '),
                    'supported_count' => $supported->count(),
                    'supported_channels' => $supported->pluck('agent_cash_flow_id')->implode(', ') ?: '—',
                ];
            })
            ->values();

        $this->table(
            ['bank_code', 'bank_name', '可用渠道數', 'agent_cash_flow_id'],
            $tableRows->map(fn ($r) => [
                $r['bank_code'],
                $r['bank_name'],
                $r['supported_count'],
                $r['supported_channels'],
            ])
        );

        $this->newLine();

        $this->info('各代付渠道支援銀行清單：');
        $channelTableRows = $channels->values()->map(function ($channel, $index) use ($channelBankCodeSets) {
            $bankCodes = $channelBankCodeSets->values()->get($index, collect());
            $bankEntries = $bankCodes->map(fn ($bankCode) => (string) $bankCode);

            return [
                $channel->agent_cash_flow_id,
                $channel->agent_cash_flow_name,
                $bankEntries->count(),
                $bankEntries->isNotEmpty() ? $bankEntries->implode(PHP_EOL) : '—',
            ];
        })->all();

        $this->table(
            ['agent_cash_flow_id', 'channel_name', '支援銀行數', '支援 bank_code'],
            $channelTableRows,
        );

        $this->newLine();

        $unsupportedBanks = $tableRows->where('supported_count', 0);

        if ($unsupportedBanks->isNotEmpty()) {
            $this->warn("⚠️  以下 {$unsupportedBanks->count()} 間銀行沒有任何代付渠道支援：");
            $this->table(
                ['bank_code', 'bank_name'],
                $unsupportedBanks->map(fn ($row) => [$row['bank_code'], $row['bank_name']])
            );
        } else {
            $this->info('✅ 所有銀行均有至少一個代付渠道支援');
        }

        return self::SUCCESS;
    }

    /**
     * 取得代付渠道列表（同 CheckWithdrawalOrderPayoutChannels 的邏輯）
     */
    private function fetchPayoutChannels(int $agentGroupId, int $currencyId): Collection
    {
        return AgentCashFlow::query()
            ->with('cashFlow')
            ->whereHas('cashFlow', function ($query) {
                $query->where('type', 3) // 3: 代付
                    ->where('status', 1);
            })
            ->where('agent_group_id', $agentGroupId)
            ->where(function ($query) use ($currencyId) {
                $query->where('currency_id', $currencyId)
                    ->orWhereNull('currency_id');
            })
            ->where('status', 1)
            ->whereNotIn('cash_flow_id', [5, 6]) // 排除系統自動處理
            ->get()
            ->map(fn (AgentCashFlow $acf) => (object) [
                'cash_flow_id' => $acf->cash_flow_id,
                'cash_flow_name' => $acf->cashFlow->name,
                'agent_cash_flow_id' => $acf->id,
                'agent_cash_flow_name' => $acf->info['DisplayName'] ?? $acf->name,
                'info' => $acf->info,
                'currency_id' => $acf->currency_id,
            ]);
    }
}
