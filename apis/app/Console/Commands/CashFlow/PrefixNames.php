<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use App\Models\AgentCashFlow;
use App\Models\OrderType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 為指定渠道（ACF）與支付方式（OrderType）的 name 加上或移除前綴
 *
 * 用途：商戶自聚合殼（如 AllPay 的 D_ALL／W_ALL）獨立成專屬 cash_flow 時，舊配置會與新配置
 * 同名並存於後台與前台清單，需以 [Old] 之類的前綴區隔，避免營運與 QA 誤操作到舊渠道。
 *
 * ! `--remove` 是反向操作，用於「舊配置又要繼續服務」的情況：order_types.name 在該 order_type
 * ! 沒有 order_type_names 記錄時，會成為前台顯示名的最後一層 fallback
 * ! （AgentCashFlowOrderType::name：Context 語系 → EN → order_types.name），
 * ! 此時前綴會直接曝露給會員；acf.name 只走後台，可保留前綴當內部標記。
 *
 * ! 只接受明確列舉的 id，且每個 id 都必須屬於參數指定的 cash_flow——聚合殼下常有十餘家不同
 * ! 商戶共用同一個 cash_flow_id，若不做範疇驗證，一次誤操作會改到其他商戶的營運中渠道。
 */
class PrefixNames extends Command
{
    protected $signature = 'cash-flow:prefix-names
                            {cash_flow_id : 目標金流商 cash_flows.id（範疇防護：所有 id 必須屬於它）}
                            {--acf= : 要加前綴的 agent_cash_flows.id，逗號分隔}
                            {--order-type= : 要加前綴的 order_types.id，逗號分隔}
                            {--prefix=[Old] : 前綴本體，與原 name 之間固定以單一空格分隔（預設 [Old]）}
                            {--remove : 反向操作——移除既有前綴，退回原 name}
                            {--dry-run : 只預覽不寫入}
                            {--force : 跳過執行前確認}';

    protected $description = '為指定 ACF／OrderType 的 name 加上或移除前綴（商戶自聚合殼獨立時區隔舊配置）';

    public function handle(): int
    {
        $cashFlowId = (int) $this->argument('cash_flow_id');
        // Laravel signature 的預設值無法保留尾隨空格（` : ` 是描述分隔符），故一律去尾空白，
        // 由 applyPrefix() 以單一空格與原 name 相接——確保傳 `[Old]` 或 `[Old] ` 結果一致
        $prefix = rtrim((string) $this->option('prefix'));

        if ($prefix === '') {
            $this->error('--prefix 不可為空字串');

            return self::FAILURE;
        }

        $acfIds = $this->parseIds($this->option('acf'));
        $orderTypeIds = $this->parseIds($this->option('order-type'));

        if ($acfIds === [] && $orderTypeIds === []) {
            $this->error('請至少指定 --acf 或 --order-type');

            return self::FAILURE;
        }

        $targets = [];

        foreach ($this->resolveAgentCashFlows($cashFlowId, $acfIds) as $model) {
            $targets[] = ['type' => 'ACF', 'model' => $model];
        }

        foreach ($this->resolveOrderTypes($cashFlowId, $orderTypeIds) as $model) {
            $targets[] = ['type' => 'OrderType', 'model' => $model];
        }

        if ($targets === []) {
            return self::FAILURE;
        }

        return $this->applyPrefix($targets, $prefix);
    }

    /**
     * @return array<int, int>
     */
    private function parseIds(mixed $option): array
    {
        if ($option === null || $option === '') {
            return [];
        }

        $ids = array_map('intval', array_filter(
            explode(',', (string) $option),
            static fn (string $part): bool => trim($part) !== ''
        ));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * 取 ACF 並驗證每筆都屬於指定 cash_flow（缺一即中止，不做部分套用）
     *
     * @param  array<int, int>  $ids
     * @return array<int, AgentCashFlow>
     */
    private function resolveAgentCashFlows(int $cashFlowId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $models = AgentCashFlow::query()
            ->whereIn('id', $ids)
            ->where('cash_flow_id', $cashFlowId)
            ->orderBy('id')
            ->get();

        $missing = array_diff($ids, $models->pluck('id')->all());

        if ($missing !== []) {
            $this->error(sprintf(
                'ACF #%s 不存在或不屬於 cash_flow #%d，已中止（未套用任何變更）',
                implode(', ', $missing),
                $cashFlowId
            ));

            return [];
        }

        return $models->all();
    }

    /**
     * 取 OrderType 並驗證每筆都屬於指定 cash_flow
     *
     * @param  array<int, int>  $ids
     * @return array<int, OrderType>
     */
    private function resolveOrderTypes(int $cashFlowId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $models = OrderType::query()
            ->whereIn('id', $ids)
            ->where('cash_flow_id', $cashFlowId)
            ->orderBy('id')
            ->get();

        $missing = array_diff($ids, $models->pluck('id')->all());

        if ($missing !== []) {
            $this->error(sprintf(
                'OrderType #%s 不存在或不屬於 cash_flow #%d，已中止（未套用任何變更）',
                implode(', ', $missing),
                $cashFlowId
            ));

            return [];
        }

        return $models->all();
    }

    private function prefixed(string $prefix, string $name): string
    {
        return sprintf('%s %s', $prefix, $name);
    }

    /**
     * 移除開頭前綴並吃掉緊接的空白；無前綴時原樣回傳（由呼叫端判定是否略過）
     */
    private function unprefixed(string $prefix, string $name): string
    {
        if (!str_starts_with($name, $prefix)) {
            return $name;
        }

        return ltrim(substr($name, strlen($prefix)));
    }

    /**
     * @param  array<int, array{type: string, model: AgentCashFlow|OrderType}>  $targets
     */
    private function applyPrefix(array $targets, string $prefix): int
    {
        $isRemove = (bool) $this->option('remove');
        $pending = [];
        $rows = [];

        foreach ($targets as $target) {
            $model = $target['model'];
            $current = (string) $model->name;
            $hasPrefix = str_starts_with($current, $prefix);
            // 加前綴時「已有」即略過，移除時「沒有」即略過——兩個方向都冪等
            $skip = $isRemove ? !$hasPrefix : $hasPrefix;

            $rows[] = [
                $target['type'],
                $model->id,
                $current,
                $skip
                    ? ($isRemove ? '—（無此前綴，略過）' : '—（已有前綴，略過）')
                    : ($isRemove ? $this->unprefixed($prefix, $current) : $this->prefixed($prefix, $current)),
            ];

            if (!$skip) {
                $pending[] = $target;
            }
        }

        $this->table(['類型', 'ID', '現有 name', '變更後 name'], $rows);

        if ($pending === []) {
            $this->info($isRemove ? '全部都沒有此前綴，無需變更' : '全部已有前綴，無需變更');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn(sprintf('乾跑模式：以上 %d 筆未寫入', count($pending)));

            return self::SUCCESS;
        }

        $action = $isRemove ? '移除' : '加上';

        if (!$this->option('force')
            && !$this->confirm(sprintf('確認為以上 %d 筆%s前綴「%s」？', count($pending), $action, $prefix), false)) {
            $this->info('已取消');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($pending, $prefix, $isRemove): void {
                foreach ($pending as $target) {
                    $model = $target['model'];
                    $name = (string) $model->name;

                    $model->update([
                        'name' => $isRemove
                            ? $this->unprefixed($prefix, $name)
                            : $this->prefixed($prefix, $name),
                    ]);
                }
            });
        } catch (Throwable $e) {
            $this->error(sprintf('寫入失敗，已回滾：%s', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf('✅ 已為 %d 筆%s前綴「%s」', count($pending), $action, $prefix));

        return self::SUCCESS;
    }
}
