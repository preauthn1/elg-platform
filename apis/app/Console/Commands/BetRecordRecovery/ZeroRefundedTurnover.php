<?php

declare(strict_types=1);

namespace App\Console\Commands\BetRecordRecovery;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\BetRecordRecoveryLog;
use App\Models\TotalBetRecord;
use App\Vendors\AFBSW\BetRecordStatusEnum as AfbswBetRecordStatusEnum;
use App\Vendors\Models\AbstractBetRecord;
use App\Vendors\Models\AfbswBetRecord;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 把「已由三方退款（取消投注）、錢也已退回玩家，但投注量還留在報表裡」的注單歸零。
 *
 * 情境：2026-09-05 TPower 事故期間 AFB 送 refund 取消投注，當時的 AFBSwController::refund()
 * 只改 payout／win_lose／status，沒有歸零 bet／valid_bet，於是退款注單照原始投注額寫進
 * total_bet_records、聚合進 summary_bet_records——SR11／SR12、三層代理返利與提款流水門檻
 * 全把它當成有效投注（target/op_tickets#3067）。回調端的修正另案處理，本命令只回補已落地的歷史資料。
 *
 * 逐筆行為（execute 模式，單筆一個 transaction）：
 *   1. 鎖注單列，斷言仍為「已退款」且投注量尚未歸零
 *   2. 注單的投注／派彩／輸贏／禮碼欄位歸零，info 追加 _zero_turnover 快照
 *   3. 對應的 total_bet_records 鎖列後把 bet／valid_bet／win_lose／rolling_chip 歸零
 *   4. 寫 bet_record_recovery_logs（type=zero_turnover、before／after 快照、balance_diff=0）
 *
 * 不動的東西：玩家錢包、transaction_logs、summary_bet_records。
 * 退款金額當初已正確退回玩家，錢是對的、錯的只有投注量口徑，所以本命令一毛錢都不碰；
 * summary_bet_records 由既有的 summary_bet_record 命令補跑（已關帳的時段會自動走調帳模式），
 * 執行完會依各筆所屬的彙總時段印出該跑哪幾道指令。
 *
 * 安全機制：預設 dry-run；--execute 必填 --reason；execute 前驗寫入連線落在主庫；
 * 派彩／禮碼以外的金額欄位（jackpot／bonus／commission）非 0 時整筆拒絕，交人工判讀；
 * 冪等由「投注量是否已全為 0」與 recovery log 雙重判定。
 *
 * 使用方式：
 *   php artisan bet-record-recovery:zero-refunded-turnover --vendor=afbsw --all-refunded
 *   php artisan bet-record-recovery:zero-refunded-turnover --vendor=afbsw --bet-id=1788622749122374051
 *   php artisan bet-record-recovery:zero-refunded-turnover --vendor=afbsw --all-refunded --execute --reason="..."
 *
 * @knowledge rules/single-wallet-unsettled-refund
 */
class ZeroRefundedTurnover extends Command
{
    protected $signature = 'bet-record-recovery:zero-refunded-turnover
        {--vendor= : 遊戲商代碼（目前僅 afbsw）}
        {--bet-id= : 單筆注單 bet_id}
        {--bet-ids= : 多筆 bet_id（逗號分隔）}
        {--bet-id-file= : 從檔案讀 bet_id（每行一個；CSV 取第一欄、略過 bet_id 表頭與空行）}
        {--all-refunded : 掃該遊戲商全部已退款且投注量未歸零的注單（與 --bet-id 系列擇一）}
        {--limit=500 : --all-refunded 的筆數上限，超過即中止並要求縮小範圍}
        {--execute : 真執行；預設 dry-run}
        {--reason= : 執行原因（execute 模式必填）}
        {--by= : 執行者標記，預設 command 名稱}
        {--source= : 資料來源標記，預設為檔名或 cli}';

    protected $description = '把已退款注單殘留的投注量歸零，使其不再計入流水與返利（預設 dry-run）';

    private const string CMD_NAME = 'bet-record-recovery:zero-refunded-turnover';

    private bool $executeMode = false;

    private string $performedBy;

    private string $reason = '';

    private string $source = 'cli';

    /**
     * @var array{
     *   bet_record: class-string<AbstractBetRecord>,
     *   vendor_id: int,
     *   info_column: string,
     *   is_refunded: Closure(AbstractBetRecord): bool,
     * }
     */
    private array $profile;

    /** @var array<int, array{status: string, bet_id: string, bet_record_id: int|null, member_id: int|null, message: string}> */
    private array $report = [];

    /** @var array<string, array{vendor_id: int, date: string, hour: string}> 需要補跑彙總的時段 */
    private array $summaryBuckets = [];

    public function handle(): int
    {
        $this->executeMode = (bool) $this->option('execute');
        $this->performedBy = (string) ($this->option('by') ?: self::CMD_NAME);
        $this->reason = trim((string) $this->option('reason'));

        if ($this->executeMode && $this->reason === '') {
            $this->error('❌ --execute 模式必須提供 --reason="..."');

            return self::FAILURE;
        }

        try {
            $this->profile = $this->resolveVendorProfile((string) $this->option('vendor'));
        } catch (RuntimeException $e) {
            $this->error('❌ ' . $e->getMessage());

            return self::FAILURE;
        }

        try {
            $records = $this->resolveRecords();
        } catch (RuntimeException $e) {
            $this->error('❌ ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($records === []) {
            $this->warn('沒有符合條件的注單，結束。');

            return self::SUCCESS;
        }

        if ($this->executeMode && !$this->writeConnectionIsWritable()) {
            $this->error('❌ write 連線的 @@read_only=1（多半連到從庫，如本機 dbc 的 34xx port）。'
                . '帳號若持有 CONNECTION_ADMIN 會繞過 read_only 直接寫進從庫、造成複寫分歧；'
                . '請把寫入連線指向主庫後再執行。');

            return self::FAILURE;
        }

        $this->printHeader(count($records));

        foreach ($records as $record) {
            $this->process($record);
        }

        $this->printSummary();
        $this->printSummaryBetRecordHints();

        return $this->hasFailure() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * execute 模式的環境守衛：寫入必須落在主庫。
     * 從庫 read_only=1 對一般帳號會自然拒寫，但持有 CONNECTION_ADMIN／SUPER 的帳號會被放行，
     * 等於把修正寫進從庫；所以不能依賴伺服器擋，要在動手前自己驗。
     */
    private function writeConnectionIsWritable(): bool
    {
        $rows = DB::selectFromWriteConnection('SELECT @@read_only AS ro');

        return (int) ($rows[0]->ro ?? 1) === 0;
    }

    /**
     * @return array{
     *   bet_record: class-string<AbstractBetRecord>,
     *   vendor_id: int,
     *   info_column: string,
     *   is_refunded: Closure(AbstractBetRecord): bool,
     * }
     */
    private function resolveVendorProfile(string $vendor): array
    {
        return match (strtolower(trim($vendor))) {
            'afbsw' => [
                'bet_record' => AfbswBetRecord::class,
                'vendor_id' => VendorIdEnum::AFBSW->value,
                'info_column' => 'info',
                'is_refunded' => static fn (AbstractBetRecord $r): bool => (int) $r->status === AfbswBetRecordStatusEnum::REFOUND->value,
            ],
            default => throw new RuntimeException('Unsupported vendor: ' . ($vendor === '' ? '(empty)' : $vendor) . '，目前僅支援 afbsw'),
        };
    }

    /**
     * @return array<int, AbstractBetRecord>
     */
    private function resolveRecords(): array
    {
        $cls = $this->profile['bet_record'];
        $explicitSource = trim((string) $this->option('source'));

        if ((bool) $this->option('all-refunded')) {
            if ($this->hasExplicitBetIds()) {
                throw new RuntimeException('--all-refunded 與 --bet-id／--bet-ids／--bet-id-file 不可併用');
            }

            $limit = (int) $this->option('limit');
            $records = $cls::query()
                ->where('status', AfbswBetRecordStatusEnum::REFOUND->value)
                ->orderBy('id')
                ->limit($limit + 1)
                ->get()
                ->all();

            if (count($records) > $limit) {
                throw new RuntimeException('已退款注單超過 --limit=' . $limit . ' 筆，請縮小範圍或明確調高上限後再跑');
            }

            $this->source = $explicitSource !== '' ? $explicitSource : 'all-refunded';

            return $records;
        }

        $betIds = $this->resolveBetIds();
        if ($betIds === []) {
            throw new RuntimeException('必須提供 --all-refunded 或 --bet-id／--bet-ids／--bet-id-file 至少一項');
        }

        if ($explicitSource !== '') {
            $this->source = $explicitSource;
        }

        $records = [];
        foreach ($betIds as $betId) {
            $record = $cls::query()->where('bet_id', $betId)->first();
            if (!$record instanceof AbstractBetRecord) {
                $this->collect('not_found', $betId, null, 'bet_id 不存在');

                continue;
            }
            $records[] = $record;
        }

        return $records;
    }

    private function hasExplicitBetIds(): bool
    {
        return trim((string) $this->option('bet-id')) !== ''
            || trim((string) $this->option('bet-ids')) !== ''
            || trim((string) $this->option('bet-id-file')) !== '';
    }

    /**
     * @return string[]
     */
    private function resolveBetIds(): array
    {
        $ids = [];

        $single = trim((string) $this->option('bet-id'));
        if ($single !== '') {
            $ids[] = $single;
        }

        $multi = (string) $this->option('bet-ids');
        if ($multi !== '') {
            foreach (explode(',', $multi) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $ids[] = $part;
                }
            }
        }

        $file = trim((string) $this->option('bet-id-file'));
        if ($file !== '') {
            $path = str_starts_with($file, '/') ? $file : base_path($file);
            if (!is_file($path)) {
                throw new RuntimeException('找不到檔案：' . $path);
            }
            $this->source = basename($path);
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $first = trim(explode(',', $line)[0]);
                if ($first === '' || strcasecmp($first, 'bet_id') === 0) {
                    continue;
                }
                $ids[] = $first;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 回傳不可歸零的原因碼；null 代表可歸零。execute 模式在鎖內會再跑一次。
     */
    private function validate(AbstractBetRecord $record, ?TotalBetRecord $totalBetRecord): ?string
    {
        if (!($this->profile['is_refunded'])($record)) {
            return 'not_refunded';
        }

        if ($this->turnoverIsZero($record) && ($totalBetRecord === null || $this->totalTurnoverIsZero($totalBetRecord))) {
            return 'already_zero';
        }

        if ($totalBetRecord !== null && $this->hasUnexpectedAmounts($totalBetRecord)) {
            return 'unexpected_amounts';
        }

        return null;
    }

    private function turnoverIsZero(AbstractBetRecord $record): bool
    {
        foreach ($this->betRecordAmountColumns($record) as $column) {
            if (Common::numCompare(Common::numAdd('0', (string) $record->{$column}, 4), '0') !== 0) {
                return false;
            }
        }

        return true;
    }

    private function totalTurnoverIsZero(TotalBetRecord $totalBetRecord): bool
    {
        foreach (['bet', 'valid_bet', 'win_lose', 'rolling_chip'] as $column) {
            if (Common::numCompare(Common::numAdd('0', (string) $totalBetRecord->{$column}, 4), '0') !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * 退款注單不該帶 jackpot／bonus／commission；帶了代表這筆不是單純的取消投注，交人工判讀。
     */
    private function hasUnexpectedAmounts(TotalBetRecord $totalBetRecord): bool
    {
        foreach (['jackpot', 'bonus', 'commission'] as $column) {
            if (Common::numCompare(Common::numAdd('0', (string) $totalBetRecord->{$column}, 4), '0') !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 注單上要歸零的金額欄位（依各 vendor model 的欄位映射，未定義的欄位自動略過）。
     *
     * @return string[]
     */
    private function betRecordAmountColumns(AbstractBetRecord $record): array
    {
        return array_values(array_filter([
            $record->getBetColumn(),
            $record->getValidBetColumn(),
            $record->getWinColumn(),
            $record->getWinLoseColumn(),
            $record->getRollingChipColumn(),
        ]));
    }

    private function findTotalBetRecord(AbstractBetRecord $record, bool $lock = false): ?TotalBetRecord
    {
        $query = TotalBetRecord::query()
            ->where('vendor_id', $this->profile['vendor_id'])
            ->where('bet_record_id', $record->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function process(AbstractBetRecord $record): void
    {
        $betId = (string) $record->getBetId();
        $totalBetRecord = $this->findTotalBetRecord($record);

        $reason = $this->validate($record, $totalBetRecord);
        if ($reason !== null) {
            $this->collect($reason, $betId, $record, $this->describe($reason, $record));

            return;
        }

        if (!$this->executeMode) {
            $this->collect('would_zero', $betId, $record, $this->describeChange($record, $totalBetRecord));
            $this->rememberSummaryBucket($totalBetRecord);

            return;
        }

        try {
            DB::transaction(fn () => $this->zeroOne($record));
            $this->collect('zeroed', $betId, $record, '投注量已歸零');
            $this->rememberSummaryBucket($totalBetRecord);
        } catch (Throwable $e) {
            Log::error(self::CMD_NAME . ' failed', [
                'bet_id' => $betId,
                'bet_record_id' => $record->id,
                'member_id' => (int) $record->member_id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            $this->collect('failed', $betId, $record, '失敗：' . $e->getMessage());
        }
    }

    private function zeroOne(AbstractBetRecord $original): void
    {
        $cls = $this->profile['bet_record'];
        /** @var AbstractBetRecord|null $record */
        $record = $cls::query()->whereKey($original->id)->lockForUpdate()->first();
        if (!$record instanceof AbstractBetRecord) {
            throw new RuntimeException('注單已不存在');
        }

        $totalBetRecord = $this->findTotalBetRecord($record, lock: true);

        $reason = $this->validate($record, $totalBetRecord);
        if ($reason !== null) {
            throw new RuntimeException('鎖內狀態已變更：' . $reason);
        }

        if ($this->hasZeroTurnoverLog($record)) {
            throw new RuntimeException('已有 zero_turnover 事件紀錄');
        }

        $performedAt = now()->toDateTimeString();
        $before = $this->snapshot($record, $totalBetRecord);

        foreach ($this->betRecordAmountColumns($record) as $column) {
            $record->{$column} = 0;
        }
        $record->{$this->profile['info_column']} = $this->appendMarker($record, $performedAt);
        $record->save();

        if ($totalBetRecord instanceof TotalBetRecord) {
            $totalBetRecord->bet = 0;
            $totalBetRecord->valid_bet = 0;
            $totalBetRecord->win_lose = 0;
            $totalBetRecord->rolling_chip = 0;
            $totalBetRecord->save();
        }

        $after = $this->snapshot($record->refresh(), $totalBetRecord?->refresh());

        BetRecordRecoveryLog::create([
            'vendor_id' => $this->profile['vendor_id'],
            'bet_record_id' => $record->id,
            'bet_id' => (string) $record->getBetId(),
            'member_id' => (int) $record->member_id,
            'type' => BetRecordRecoveryTypeEnum::ZERO_TURNOVER->value,
            'performed_at' => $performedAt,
            'performed_by' => $this->performedBy,
            'reason' => $this->reason,
            'source' => $this->source,
            'before' => $before,
            'after' => $after,
            'balance_diff' => '0.0000',
            'parent_log_id' => null,
        ]);

        Log::info(self::CMD_NAME . ' zeroed', [
            'vendor_id' => $this->profile['vendor_id'],
            'bet_record_id' => $record->id,
            'bet_id' => (string) $record->getBetId(),
            'member_id' => (int) $record->member_id,
            'total_bet_record_id' => $totalBetRecord?->id,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function hasZeroTurnoverLog(AbstractBetRecord $record): bool
    {
        return BetRecordRecoveryLog::query()
            ->where('vendor_id', $this->profile['vendor_id'])
            ->where('bet_record_id', $record->id)
            ->where('type', BetRecordRecoveryTypeEnum::ZERO_TURNOVER->value)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AbstractBetRecord $record, ?TotalBetRecord $totalBetRecord): array
    {
        return [
            'status' => (int) $record->status,
            'bet' => $record->getBet(),
            'valid_bet' => $record->getValidBet(),
            'payout' => $record->getWin(),
            'win_lose' => $record->getWinLose(),
            'rolling_chip' => $record->getRollingChip(),
            'total_bet_record' => $totalBetRecord === null ? null : [
                'id' => $totalBetRecord->id,
                'bet' => (string) $totalBetRecord->bet,
                'valid_bet' => (string) $totalBetRecord->valid_bet,
                'win_lose' => (string) $totalBetRecord->win_lose,
                'rolling_chip' => (string) $totalBetRecord->rolling_chip,
            ],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function appendMarker(AbstractBetRecord $record, string $performedAt): array
    {
        $current = $record->{$this->profile['info_column']};
        if (!is_array($current)) {
            $current = $current === null ? [] : ['_original_info' => $current];
        }

        $current['_zero_turnover'] = [
            'at' => $performedAt,
            'by' => $this->performedBy,
            'reason' => $this->reason,
            'source' => $this->source,
        ];

        return $current;
    }

    /**
     * 記下該筆所屬的彙總時段，供結尾印出補跑指令。
     *
     * summary_bet_records 依 config/summary_bet_record.php 設定的時間欄位分桶（AFBSW 是 bet_time），
     * 取錯欄位會補到不存在的資料上，所以一律從 config 讀、並取 total_bet_records 上的值。
     */
    private function rememberSummaryBucket(?TotalBetRecord $totalBetRecord): void
    {
        if (!$totalBetRecord instanceof TotalBetRecord) {
            return;
        }

        $vendorId = $this->profile['vendor_id'];
        $timeField = (string) config('summary_bet_record.' . $vendorId . '.time_field', 'payout_time');
        $value = (string) ($totalBetRecord->{$timeField} ?? '');
        if ($value === '') {
            return;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return;
        }

        $bucket = [
            'vendor_id' => $vendorId,
            'date' => date('Y-m-d', $timestamp),
            'hour' => date('G', $timestamp),
        ];
        $this->summaryBuckets[$bucket['date'] . ' ' . $bucket['hour']] = $bucket;
    }

    private function describe(string $reason, AbstractBetRecord $record): string
    {
        return match ($reason) {
            'not_refunded' => '注單狀態為 ' . (int) $record->status . '，非已退款，不處理',
            'already_zero' => '投注量已是 0，無須處理',
            'unexpected_amounts' => 'total_bet_records 帶有 jackpot／bonus／commission，需人工判讀',
            default => $reason,
        };
    }

    private function describeChange(AbstractBetRecord $record, ?TotalBetRecord $totalBetRecord): string
    {
        $parts = ['注單 valid_bet ' . $record->getValidBet() . '→0.0000'];
        if ($totalBetRecord instanceof TotalBetRecord) {
            $parts[] = 'TBR#' . $totalBetRecord->id . ' valid_bet ' . (string) $totalBetRecord->valid_bet . '→0.0000';
        } else {
            $parts[] = '無對應 total_bet_records';
        }

        return implode('，', $parts);
    }

    private function collect(string $status, string $betId, ?AbstractBetRecord $record, string $message): void
    {
        $this->report[] = [
            'status' => $status,
            'bet_id' => $betId,
            'bet_record_id' => $record?->id,
            'member_id' => $record === null ? null : (int) $record->member_id,
            'message' => $message,
        ];
    }

    private function hasFailure(): bool
    {
        foreach ($this->report as $row) {
            if (in_array($row['status'], ['failed', 'not_found'], true)) {
                return true;
            }
        }

        return false;
    }

    private function printHeader(int $count): void
    {
        $this->info('========================================');
        $this->info(self::CMD_NAME);
        $this->info('模式：' . ($this->executeMode ? 'EXECUTE（真寫入）' : 'DRY-RUN（不寫入）'));
        $this->info('遊戲商：' . strtolower(trim((string) $this->option('vendor'))) . '（vendor_id=' . $this->profile['vendor_id'] . '）');
        $this->info('待處理注單：' . $count . ' 筆');
        if ($this->reason !== '') {
            $this->info('原因：' . $this->reason);
        }
        $this->info('========================================');
    }

    private function printSummary(): void
    {
        $rows = [];
        foreach ($this->report as $row) {
            $rows[] = [
                $row['status'],
                $row['bet_id'],
                $row['bet_record_id'] ?? '-',
                $row['member_id'] ?? '-',
                $row['message'],
            ];
        }

        $this->table(['status', 'bet_id', 'bet_record_id', 'member_id', 'message'], $rows);

        $counts = array_count_values(array_column($this->report, 'status'));
        foreach ($counts as $status => $count) {
            $this->line('  ' . $status . '：' . $count . ' 筆');
        }
    }

    /**
     * summary_bet_records 不會跟著 total_bet_records 自動變，必須補跑彙總。
     * 已關帳的時段（台北日界前）補跑時會自動走調帳模式，寫差值列而非覆蓋原列。
     */
    private function printSummaryBetRecordHints(): void
    {
        if ($this->summaryBuckets === []) {
            return;
        }

        $this->newLine();
        $this->warn('⚠️ summary_bet_records 尚未更新，請接著補跑以下彙總（建議先加 --dry-run 看差異）：');
        foreach ($this->summaryBuckets as $bucket) {
            $this->line(sprintf(
                '  php artisan summary_bet_record --date=%s --hour=%s --vendor=%d --timezone=UTC',
                $bucket['date'],
                $bucket['hour'],
                $bucket['vendor_id'],
            ));
        }
    }
}
