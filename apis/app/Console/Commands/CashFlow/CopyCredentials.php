<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use App\Models\AgentCashFlow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 將來源渠道（ACF）info 內的憑證欄位複製到目標渠道
 *
 * 用途：商戶自聚合殼獨立成專屬 cash_flow 時，新 acf 由 provider 配置檔建立而憑證刻意留空
 * （不入版控），需自同商戶的舊 acf 取得實值。以命令複製可讓憑證**完全不離開資料庫**，
 * 不經人工轉貼、不進命令行參數、不進任何輸出或 log。
 *
 * ! 舊系統與新 APIs 的 info 鍵名不同（PascalCase vs SCREAMING_SNAKE），故 --keys 支援
 * ! 「來源鍵:目標鍵」映射寫法。
 *
 * ! 本命令永不輸出憑證值，只顯示長度與 SHA-256 前 12 字指紋供人工核對來源是否正確。
 */
class CopyCredentials extends Command
{
    protected $signature = 'cash-flow:copy-credentials
                            {--from= : 來源 agent_cash_flows.id}
                            {--to= : 目標 agent_cash_flows.id，逗號分隔}
                            {--keys=MerchantNo:MERCHANT_NO,MerchantKey:MD5_KEY : 要複製的 info 鍵，逗號分隔；支援「來源鍵:目標鍵」映射}
                            {--expect-merchant= : 預期的來源商戶號（比對第一組映射的來源鍵值），防拿錯來源 acf}
                            {--overwrite : 目標對應鍵已有非空值時仍覆寫}
                            {--dry-run : 只預覽不寫入}
                            {--force : 跳過環境確認}';

    protected $description = '將來源 ACF 的 info 憑證欄位複製到目標 ACF（憑證不離 DB、不輸出值）';

    /** 指紋長度：足以辨識來源是否正確，又不足以回推原值 */
    private const int FINGERPRINT_LENGTH = 12;

    public function handle(): int
    {
        if (!$this->confirmExecutionEnvironment()) {
            return self::FAILURE;
        }

        $keyMap = $this->parseKeyMap((string) $this->option('keys'));

        if ($keyMap === []) {
            $this->error('--keys 格式無效');

            return self::FAILURE;
        }

        $source = AgentCashFlow::query()->find((int) $this->option('from'));

        if ($source === null) {
            $this->error(sprintf('來源 ACF #%s 不存在', (string) $this->option('from')));

            return self::FAILURE;
        }

        if (!$this->verifyExpectedMerchant($source, $keyMap)) {
            return self::FAILURE;
        }

        $targets = $this->resolveTargets();

        if ($targets === []) {
            return self::FAILURE;
        }

        return $this->copy($source, $targets, $keyMap);
    }

    /**
     * 允許的環境：mysql、dev、testing；其他（白標生產）需人工確認
     */
    private function confirmExecutionEnvironment(): bool
    {
        $connection = (string) config('database.default');

        if (in_array(strtolower($connection), ['mysql', 'dev', 'testing'], true) || $this->option('force')) {
            return true;
        }

        $this->warn(sprintf('⚠️  當前資料庫連線: %s', $connection));

        if (!$this->confirm('確定要在此環境執行？', false)) {
            $this->info('✓ 操作已取消');

            return false;
        }

        return true;
    }

    /**
     * 解析 --keys 為「來源鍵 => 目標鍵」；無冒號者來源與目標同名
     *
     * @return array<string, string>
     */
    private function parseKeyMap(string $option): array
    {
        $map = [];

        $pairs = array_filter(
            explode(',', $option),
            static fn (string $part): bool => trim($part) !== ''
        );

        foreach ($pairs as $pair) {
            [$from, $to] = array_pad(explode(':', trim($pair), 2), 2, null);
            $from = trim((string) $from);
            $to = trim((string) ($to ?? $from));

            if ($from === '' || $to === '') {
                return [];
            }

            $map[$from] = $to;
        }

        return $map;
    }

    /**
     * 比對來源商戶號，防拿錯 acf（同一聚合殼下常有多家商戶）
     *
     * @param  array<string, string>  $keyMap
     */
    private function verifyExpectedMerchant(AgentCashFlow $source, array $keyMap): bool
    {
        $expected = $this->option('expect-merchant');

        if ($expected === null || $expected === '') {
            return true;
        }

        $merchantSourceKey = array_key_first($keyMap);
        $actual = (string) ($source->info[$merchantSourceKey] ?? '');

        if ($actual !== (string) $expected) {
            $this->error(sprintf(
                '來源 ACF #%d 的 %s 為「%s」，與 --expect-merchant「%s」不符，已中止',
                $source->id,
                $merchantSourceKey,
                $actual,
                (string) $expected
            ));

            return false;
        }

        $this->info(sprintf('✓ 來源商戶號比對通過：%s = %s', $merchantSourceKey, $actual));

        return true;
    }

    /**
     * @return array<int, AgentCashFlow>
     */
    private function resolveTargets(): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', array_filter(
                explode(',', (string) $this->option('to')),
                static fn (string $part): bool => trim($part) !== ''
            )),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            $this->error('請指定 --to');

            return [];
        }

        $models = AgentCashFlow::query()->whereIn('id', $ids)->orderBy('id')->get();
        $missing = array_diff($ids, $models->pluck('id')->all());

        if ($missing !== []) {
            $this->error(sprintf('目標 ACF #%s 不存在，已中止（未套用任何變更）', implode(', ', $missing)));

            return [];
        }

        return $models->all();
    }

    /**
     * @param  array<int, AgentCashFlow>  $targets
     * @param  array<string, string>  $keyMap
     */
    private function copy(AgentCashFlow $source, array $targets, array $keyMap): int
    {
        $sourceInfo = $source->info ?? [];
        $values = [];

        foreach ($keyMap as $sourceKey => $targetKey) {
            $value = $sourceInfo[$sourceKey] ?? null;

            if (!is_string($value) || $value === '') {
                $this->error(sprintf('來源 ACF #%d 的 %s 為空或非字串，已中止', $source->id, $sourceKey));

                return self::FAILURE;
            }

            $values[$targetKey] = $value;
            $this->line(sprintf(
                '  來源 %s → %s：長度 %d、指紋 %s',
                $sourceKey,
                $targetKey,
                mb_strlen($value),
                $this->fingerprint($value)
            ));
        }

        $rows = [];
        $pending = [];

        foreach ($targets as $target) {
            $targetInfo = $target->info ?? [];
            $changes = [];

            foreach ($values as $targetKey => $value) {
                $current = $targetInfo[$targetKey] ?? null;
                $hasValue = is_string($current) && $current !== '';

                $action = match (true) {
                    $hasValue && !$this->option('overwrite') => '略過（已有值，未帶 --overwrite）',
                    $hasValue => sprintf('覆寫（原指紋 %s）', $this->fingerprint((string) $current)),
                    default => '寫入',
                };

                if ($action !== '略過（已有值，未帶 --overwrite）') {
                    $changes[$targetKey] = $value;
                }

                $rows[] = [$target->id, $target->name, $targetKey, $action];
            }

            if ($changes !== []) {
                $pending[] = ['model' => $target, 'changes' => $changes];
            }
        }

        $this->table(['目標 ACF', 'name', 'info 鍵', '動作'], $rows);

        if ($pending === []) {
            $this->info('無需變更');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn(sprintf('乾跑模式：以上 %d 筆 ACF 未寫入', count($pending)));

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($pending): void {
                foreach ($pending as $item) {
                    /** @var AgentCashFlow $model */
                    $model = $item['model'];
                    $model->update(['info' => array_merge($model->info ?? [], $item['changes'])]);
                }
            });
        } catch (Throwable $e) {
            $this->error(sprintf('寫入失敗，已回滾：%s', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf('✅ 已更新 %d 筆 ACF 的憑證欄位', count($pending)));

        return self::SUCCESS;
    }

    private function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, self::FINGERPRINT_LENGTH);
    }
}
