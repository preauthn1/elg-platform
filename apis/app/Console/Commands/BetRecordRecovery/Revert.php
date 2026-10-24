<?php

declare(strict_types=1);

namespace App\Console\Commands\BetRecordRecovery;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\BetRecordRecoveryLog;
use App\Models\Member;
use App\Models\TotalBetRecord;
use App\Models\TotalBetRecordTransactionLog;
use App\Models\TotalBetRecordWalletTransactionLog;
use App\Models\VendorInfo;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\AbstractBetRecord;
use App\Vendors\Models\KissH5SWAccount;
use App\Vendors\Models\KissH5SWBetRecord;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 撤回 bet_record_recovery_logs 中 type=recover 的事件（跨 vendor 通用）。
 *
 * 行為：
 *   1. 鎖 bet_record + 偵測 drift（current state vs after snapshot）
 *   2. 鎖玩家錢包 → 反扣 balance_diff（允許負數）
 *   3. 將 bet_record 還原成 before snapshot；info JSON 加 _reverted 標記
 *   4. 開反向 TransactionLog（type=SEAMLESS_WALLET_BET_SETTLE，金額負）
 *   5. 刪除 recover 階段建立的 TotalBetRecord（用 after._meta.total_bet_record_id）
 *   6. 寫 type=revert log，parent_log_id 指向被撤回的 recover
 *
 * 安全機制：
 *   - 預設 dry-run；--execute 才真寫入；--execute 必填 --reason
 *   - --force 才能在 drift 存在時繼續
 *   - --max-bad-debt 為呆帳上限，超過直接 abort
 *   - 鎖內二次 drift 與 isReverted 檢查
 *   - parent_log_id UNIQUE 阻擋重複 revert（DB 層保護）
 *
 * 使用方式：
 *   php artisan bet-record-recovery:revert --log-id=123
 *   php artisan bet-record-recovery:revert --log-ids=1,2,3
 *   php artisan bet-record-recovery:revert --log-id-csv=path.csv
 *   php artisan bet-record-recovery:revert --log-ids=1,2,3 --execute --reason="客服票號 #5678"
 */
class Revert extends Command
{
    protected $signature = 'bet-record-recovery:revert
        {--log-id= : 單筆 recover log id}
        {--log-ids= : 多筆 ids（逗號分隔）}
        {--log-id-csv= : 從 CSV 讀 log ids（取第一欄、跳過 header）}
        {--execute : 真執行；預設 dry-run}
        {--reason= : 撤回原因（execute 模式必填）}
        {--by= : 執行者標記，預設 command 名稱}
        {--force : 跳過 drift check（只在不得不時用）}
        {--max-bad-debt= : 呆帳合計超過此值 abort（單位為玩家本地幣值）}';

    protected $description = '撤回先前由 bet-record-recovery 流程建立的 recover log（跨 vendor 通用）';

    private const string CMD_NAME = 'bet-record-recovery:revert';

    private bool $executeMode = false;

    private bool $force = false;

    private string $performedBy;

    private string $reason = '';

    private ?string $maxBadDebt = null;

    /** @var array<int, array<string, mixed>> */
    private array $report = [];

    /** @var array<int, array<string, mixed>> */
    private array $playerSummary = [];

    public function handle(): int
    {
        $this->executeMode = (bool) $this->option('execute');
        $this->force = (bool) $this->option('force');
        $this->performedBy = (string) ($this->option('by') ?: self::CMD_NAME);
        $this->reason = (string) $this->option('reason');
        $this->maxBadDebt = $this->option('max-bad-debt') !== null
            ? (string) $this->option('max-bad-debt')
            : null;

        if ($this->executeMode && trim($this->reason) === '') {
            $this->error('❌ --execute 模式必須提供 --reason="..."');

            return self::FAILURE;
        }

        $logIds = $this->resolveLogIds();
        if (empty($logIds)) {
            return self::FAILURE;
        }

        $this->printHeader($logIds);

        $validLogs = $this->loadAndValidateLogs($logIds);

        if (empty($validLogs)) {
            $this->error('沒有可處理的 recover log');
            $this->printSummary();
            $this->writeReport();

            return self::FAILURE;
        }

        $this->buildPlayerSummary($validLogs);
        $this->printPlayerSummaryTable();

        $totalBadDebt = $this->totalBadDebt();
        if ($this->maxBadDebt !== null && Common::numCompare($totalBadDebt, $this->maxBadDebt) > 0) {
            $this->error(
                '❌ 預期呆帳 ' . $totalBadDebt . ' 超過 --max-bad-debt=' . $this->maxBadDebt . '，abort'
            );
            $this->writeReport();

            return self::FAILURE;
        }

        foreach ($validLogs as $log) {
            $this->processLog($log);
        }

        $this->printSummary();
        $reportPaths = $this->writeReport();
        foreach ($reportPaths as $label => $path) {
            $this->info('📄 ' . $label . '：' . $path);
        }

        return self::SUCCESS;
    }

    /**
     * @return int[]
     */
    private function resolveLogIds(): array
    {
        $ids = [];

        $single = $this->option('log-id');
        if ($single !== null && $single !== '') {
            $ids[] = (int) $single;
        }

        $multi = $this->option('log-ids');
        if ($multi !== null && $multi !== '') {
            foreach (explode(',', (string) $multi) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $ids[] = (int) $part;
                }
            }
        }

        $csv = $this->option('log-id-csv');
        if ($csv !== null && $csv !== '') {
            $path = str_starts_with((string) $csv, '/') ? (string) $csv : base_path((string) $csv);
            if (!is_file($path)) {
                $this->error('找不到 CSV：' . $path);

                return [];
            }
            $fp = fopen($path, 'r');
            if ($fp !== false) {
                fgetcsv($fp, 0, ',', '"', '\\'); // header
                while (($cols = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
                    $first = trim((string) ($cols[0] ?? ''));
                    if ($first !== '' && ctype_digit($first)) {
                        $ids[] = (int) $first;
                    }
                }
                fclose($fp);
            }
        }

        if (empty($ids)) {
            $this->error('❌ 必須提供 --log-id / --log-ids / --log-id-csv 至少一項');

            return [];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  int[]  $logIds
     * @return BetRecordRecoveryLog[]
     */
    private function loadAndValidateLogs(array $logIds): array
    {
        $valid = [];
        foreach ($logIds as $logId) {
            $log = BetRecordRecoveryLog::find($logId);
            if (!$log) {
                $this->collect('not_found', $logId, null, 'log id 不存在');

                continue;
            }
            if ($log->type !== BetRecordRecoveryTypeEnum::RECOVER) {
                $this->collect('not_recover', $logId, $log, 'log type=' . $log->type->value . '，不是 recover');

                continue;
            }
            if ($log->isReverted()) {
                $this->collect('already_reverted', $logId, $log, '此 recover 已被 revert 過');

                continue;
            }
            $valid[] = $log;
        }

        return $valid;
    }

    private function printHeader(array $logIds): void
    {
        $this->info('========================================');
        $this->info('🔁 BetRecordRecovery Revert');
        $this->info('========================================');
        $this->info('模式: ' . ($this->executeMode ? '⚠️  EXECUTE（真寫入）' : '🧪 DRY-RUN'));
        $this->info('待處理 log id 數量: ' . count($logIds));
        if ($this->force) {
            $this->warn('⚠️  --force：drift check 將被跳過');
        }
        if ($this->maxBadDebt !== null) {
            $this->info('呆帳上限: ' . $this->maxBadDebt);
        }
        if ($this->executeMode) {
            $this->info('執行者: ' . $this->performedBy);
            $this->info('原因: ' . $this->reason);
        }
        $this->newLine();
    }

    /**
     * @param  BetRecordRecoveryLog[]  $logs
     */
    private function buildPlayerSummary(array $logs): void
    {
        $byMember = [];
        foreach ($logs as $log) {
            $mid = (int) $log->member_id;
            $byMember[$mid] ??= [
                'member_id' => $mid,
                'username' => '',
                'vendor_id' => (int) $log->vendor_id,
                'reverts' => 0,
                'total_revert' => '0',
                'start_balance' => '0',
                'end_balance' => '0',
                'bad_debt' => '0',
                'went_negative' => false,
            ];
            $byMember[$mid]['reverts']++;
            $byMember[$mid]['total_revert'] = Common::numAdd(
                $byMember[$mid]['total_revert'],
                Common::numMul((string) $log->balance_diff, '-1', 4),
                4
            );
        }

        foreach ($byMember as &$row) {
            $member = Member::find($row['member_id']);
            $row['username'] = $member?->username ?? ('member#' . $row['member_id']);

            $vendorInfo = VendorInfo::where('vendor_id', $row['vendor_id'])->first();
            if (!$member || !$vendorInfo) {
                continue;
            }
            $walletDto = (new WalletService)->getMemberWallets($member, $vendorInfo);
            $row['start_balance'] = (string) ($walletDto->wallet->balance ?? '0');
            $row['end_balance'] = Common::numAdd($row['start_balance'], $row['total_revert'], 4);
            if (Common::numCompare($row['end_balance'], '0') < 0) {
                $row['went_negative'] = true;
                $row['bad_debt'] = Common::numMul($row['end_balance'], '-1', 4);
            }
        }
        unset($row);

        $this->playerSummary = array_values($byMember);
    }

    private function totalBadDebt(): string
    {
        $total = '0';
        foreach ($this->playerSummary as $row) {
            $total = Common::numAdd($total, $row['bad_debt'], 4);
        }

        return $total;
    }

    private function printPlayerSummaryTable(): void
    {
        if (empty($this->playerSummary)) {
            return;
        }

        $rows = [];
        foreach ($this->playerSummary as $row) {
            $rows[] = [
                $row['username'],
                $row['reverts'],
                $row['start_balance'],
                $row['total_revert'],
                $row['end_balance'],
                $row['went_negative'] ? '✓' : '',
                $row['bad_debt'],
            ];
        }

        $totalReverts = array_sum(array_column($this->playerSummary, 'reverts'));
        $rows[] = ['---', '---', '---', '---', '---', '---', '---'];
        $rows[] = [
            '總計（' . count($this->playerSummary) . ' 玩家）',
            $totalReverts,
            '',
            '',
            '',
            '',
            $this->totalBadDebt(),
        ];

        $this->info('🧾 玩家額度變更預演（dry-run 為預估，正式執行時餘額可能因玩家活動有少量誤差）');
        $this->table(
            ['Username', 'reverts', 'start', 'change', 'end', '負數?', 'bad_debt'],
            $rows
        );
        $this->newLine();
    }

    private function processLog(BetRecordRecoveryLog $log): void
    {
        $betRecord = $this->resolveBetRecord((int) $log->vendor_id, (int) $log->bet_record_id);
        if (!$betRecord) {
            $this->collect('record_missing', (int) $log->id, $log, '查無 bet_record');

            return;
        }

        $drifts = $this->detectDrift($log, $betRecord);
        if (!empty($drifts) && !$this->force) {
            $this->collect(
                'refused_drift',
                (int) $log->id,
                $log,
                'drift: ' . json_encode($drifts, JSON_UNESCAPED_UNICODE)
            );

            return;
        }

        if (!$this->executeMode) {
            $negDiff = Common::numMul((string) $log->balance_diff, '-1', 4);
            $note = '預計 status ' . ($log->after['status'] ?? '?') . '→' . ($log->before['status'] ?? '?')
                . ' / 錢包 ' . $negDiff
                . (empty($drifts) ? '' : ' / drift=' . count($drifts) . '（force 啟用）');
            $this->collect('would_revert', (int) $log->id, $log, $note, $negDiff);

            return;
        }

        try {
            $balanceDiff = DB::transaction(fn () => $this->revertOne($log));
            $this->collect('reverted', (int) $log->id, $log, '撤回成功，錢包淨變動 ' . $balanceDiff, $balanceDiff);
        } catch (Throwable $e) {
            Log::error(self::CMD_NAME . ' failed', [
                'log_id' => $log->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            $this->collect('failed', (int) $log->id, $log, '失敗：' . $e->getMessage());
        }
    }

    private function resolveBetRecord(int $vendorId, int $betRecordId): ?AbstractBetRecord
    {
        $cls = $this->resolveBetRecordModel($vendorId);

        return $cls::find($betRecordId);
    }

    private function lockBetRecord(int $vendorId, int $betRecordId): ?AbstractBetRecord
    {
        $cls = $this->resolveBetRecordModel($vendorId);

        return $cls::query()->where('id', $betRecordId)->lockForUpdate()->first();
    }

    /**
     * @return class-string<AbstractBetRecord>
     */
    private function resolveBetRecordModel(int $vendorId): string
    {
        return match ($vendorId) {
            VendorIdEnum::KISSH5SW->value => KissH5SWBetRecord::class,
            default => throw new RuntimeException('Unsupported vendor_id: ' . $vendorId),
        };
    }

    private function resolveAccount(int $vendorId, AbstractBetRecord $record): ?AbstractAccount
    {
        return match ($vendorId) {
            VendorIdEnum::KISSH5SW->value => KissH5SWAccount::where('username', $record->username)->first(),
            default => null,
        };
    }

    /**
     * @return array<string, array{expected: ?string, actual: ?string}>
     */
    private function detectDrift(BetRecordRecoveryLog $log, AbstractBetRecord $record): array
    {
        $drifts = [];
        foreach ((array) $log->after as $field => $expected) {
            if ($field === '_meta') {
                continue;
            }
            $actual = $record->{$field} ?? null;
            if ($actual instanceof DateTimeInterface) {
                $actual = $actual->format('Y-m-d H:i:s');
            }
            $expectedStr = $expected === null ? null : (string) $expected;
            $actualStr = $actual === null ? null : (string) $actual;
            if ($expectedStr !== $actualStr) {
                $drifts[$field] = ['expected' => $expectedStr, 'actual' => $actualStr];
            }
        }

        return $drifts;
    }

    private function revertOne(BetRecordRecoveryLog $log): string
    {
        $vendorId = (int) $log->vendor_id;
        $vendorInfo = VendorInfo::where('vendor_id', $vendorId)->first();
        if (!$vendorInfo) {
            throw new RuntimeException('找不到 VendorInfo for vendor_id=' . $vendorId);
        }

        $record = $this->lockBetRecord($vendorId, (int) $log->bet_record_id);
        if (!$record) {
            throw new RuntimeException('record 已不存在');
        }

        if ($log->fresh()->isReverted()) {
            throw new RuntimeException('已被其他流程 revert');
        }

        $drifts = $this->detectDrift($log, $record);
        if (!empty($drifts) && !$this->force) {
            throw new RuntimeException('drift detected under lock: ' . json_encode($drifts, JSON_UNESCAPED_UNICODE));
        }

        $member = Member::find($record->member_id);
        if (!$member) {
            throw new RuntimeException('找不到 member_id=' . $record->member_id);
        }

        $account = $this->resolveAccount($vendorId, $record);
        $walletDto = (new WalletService)->getMemberLockedWallets($member, $vendorInfo);

        $beforeWalletBalance = (string) $walletDto->wallet->balance;
        $walletChange = Common::numMul((string) $log->balance_diff, '-1', 4);
        $afterWalletBalance = Common::numAdd($beforeWalletBalance, $walletChange, 4);

        foreach ((array) $log->before as $field => $value) {
            if ($field === '_meta') {
                continue;
            }
            $record->{$field} = $value;
        }
        $record->info = $this->appendRevertMarker((string) ($record->info ?? ''), $log);

        $transactionLogId = 0;
        if (Common::numCompare($walletChange, '0') !== 0) {
            $walletDto->wallet->update(['balance' => $afterWalletBalance]);

            $isMultipleWallet = $walletDto->isMultipleWallet;
            $logService = new TransactionLogCreateService($member, $vendorInfo, $isMultipleWallet, $account);
            $txType = $isMultipleWallet
                ? WalletTransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value
                : TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value;
            $logService->setType($txType);

            $tx = $logService->create($walletChange, $beforeWalletBalance, $afterWalletBalance);
            $transactionLogId = $tx->id;
        }

        $record->save();

        $meta = (array) ($log->after['_meta'] ?? []);
        $totalBetRecordId = isset($meta['total_bet_record_id']) ? (int) $meta['total_bet_record_id'] : 0;
        $isMultipleWallet = (bool) ($meta['is_multiple_wallet'] ?? $walletDto->isMultipleWallet);
        if ($totalBetRecordId > 0) {
            if ($isMultipleWallet) {
                TotalBetRecordWalletTransactionLog::where('total_bet_record_id', $totalBetRecordId)->delete();
            } else {
                TotalBetRecordTransactionLog::where('total_bet_record_id', $totalBetRecordId)->delete();
            }
            TotalBetRecord::where('id', $totalBetRecordId)->delete();
        }

        BetRecordRecoveryLog::create([
            'vendor_id' => $vendorId,
            'bet_record_id' => $record->id,
            'bet_id' => (string) $log->bet_id,
            'member_id' => $member->id,
            'type' => BetRecordRecoveryTypeEnum::REVERT->value,
            'performed_at' => now(),
            'performed_by' => $this->performedBy,
            'reason' => $this->reason,
            'source' => 'revert of recovery_log#' . $log->id,
            'before' => (array) $log->after,
            'after' => array_merge((array) $log->before, [
                '_meta' => [
                    'transaction_log_id' => $transactionLogId,
                    'reverted_total_bet_record_id' => $totalBetRecordId,
                ],
            ]),
            'balance_diff' => $walletChange,
            'parent_log_id' => $log->id,
        ]);

        Log::info(self::CMD_NAME . ' reverted', [
            'recover_log_id' => $log->id,
            'bet_record_id' => $record->id,
            'bet_id' => $log->bet_id,
            'member_id' => $member->id,
            'balance_diff' => $walletChange,
            'transaction_log_id' => $transactionLogId,
            'after_balance' => $afterWalletBalance,
            'went_negative' => Common::numCompare($afterWalletBalance, '0') < 0,
        ]);

        return $walletChange;
    }

    private function appendRevertMarker(string $info, BetRecordRecoveryLog $log): string
    {
        $current = json_decode($info ?: '[]', true) ?: [];
        $current[] = [
            '_reverted' => [
                'at' => now()->toDateTimeString(),
                'by' => $this->performedBy,
                'reason' => $this->reason,
                'recover_log_id' => $log->id,
            ],
        ];

        return json_encode($current, JSON_UNESCAPED_UNICODE);
    }

    private function collect(string $status, ?int $logId, ?BetRecordRecoveryLog $log, string $message, string $balanceDiff = '0'): void
    {
        $this->report[] = [
            'status' => $status,
            'log_id' => $logId,
            'vendor_id' => $log?->vendor_id,
            'bet_record_id' => $log?->bet_record_id,
            'bet_id' => $log?->bet_id,
            'member_id' => $log?->member_id,
            'balance_diff' => $balanceDiff,
            'message' => $message,
        ];
    }

    private function printSummary(): void
    {
        $byStatus = [];
        foreach ($this->report as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
        }
        $this->newLine();
        $this->info('========================================');
        $this->info($this->executeMode ? '✅ Revert 結果' : '🧪 Revert Dry-run 結果');
        $this->info('========================================');

        $rows = [];
        foreach ($byStatus as $status => $count) {
            $sumDiff = '0';
            foreach ($this->report as $r) {
                if ($r['status'] === $status) {
                    $sumDiff = Common::numAdd($sumDiff, $r['balance_diff'], 4);
                }
            }
            $rows[] = [$status, $count, $sumDiff];
        }
        $this->table(['狀態', '筆數', '錢包變動合計'], $rows);
        $this->info('預期呆帳合計：' . $this->totalBadDebt());
    }

    /**
     * @return array<string, string>
     */
    private function writeReport(): array
    {
        $mode = $this->executeMode ? 'execute' : 'dryrun';
        $ts = now()->format('YmdHis');

        $detailPath = storage_path('logs/bet_record_revert_' . $mode . '_' . $ts . '.csv');
        $this->writeCsv(
            $detailPath,
            ['status', 'log_id', 'vendor_id', 'bet_record_id', 'bet_id', 'member_id', 'balance_diff', 'message'],
            $this->report
        );

        $summaryPath = storage_path('logs/bet_record_revert_player_summary_' . $mode . '_' . $ts . '.csv');
        $this->writeCsv(
            $summaryPath,
            ['username', 'member_id', 'vendor_id', 'reverts', 'start_balance', 'total_revert', 'end_balance', 'went_negative', 'bad_debt'],
            array_map(
                fn (array $r) => array_merge($r, ['went_negative' => $r['went_negative'] ? 'yes' : 'no']),
                $this->playerSummary
            )
        );

        return ['逐筆明細' => $detailPath, '玩家彙總' => $summaryPath];
    }

    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            return;
        }
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $headers);
        foreach ($rows as $r) {
            $line = [];
            foreach ($headers as $h) {
                $line[] = $r[$h] ?? '';
            }
            fputcsv($fp, $line);
        }
        fclose($fp);
    }
}
