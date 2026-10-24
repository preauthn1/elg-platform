<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\KissH5SW\Maintenance;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\BetRecordRecoveryLog;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\TotalBetRecordService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\KissH5SWAccount;
use App\Vendors\Models\KissH5SWBetRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 修復 KissH5SW 孤兒注單：依 K5 原始 CSV 還原真實結果，並把差額補回玩家錢包。
 *
 * 【分類】Maintenance — ⚠️ **高風險寫入工具**（直接改玩家錢包餘額），需要時才跑。
 *
 * > 2026-07-28 從 `Patches/PrecisionBug2026May/` 移入本目錄。原分類假設「孤兒＝2026-05 精度事故的殘留」，
 * > 但實測 26 筆孤兒中 **20 筆與精度 bug 無關**（廠商端未發 GameResult，且仍在新產生）——來源未斷，
 * > 不符合 Patch 的「一次性」語意。精度事故專屬的配對修復分支仍保留（見下表）。
 *
 * ## 發生原因
 * 孤兒＝`type=N`、`status=0`、`Bet` 已扣款但 `GameResult` 未結算的注單。成因有二
 * （完整說明見 `docs/integrations/vendors/KissH5/integration.md` §5）：
 *
 * 1. **廠商端未發 GameResult**（主因，2026-07-28 盤點佔 20/26）——K5 服務異常時段的交易
 *    既未結算也未 Rollback，且**仍在新產生**
 * 2. **2026-05 精度 bug 的配對汙染**（佔 6/26，全在 TPower）——17 位 BetId 以 integer 進
 *    `where()` 時 MySQL 轉 float 誤命中相鄰注單，該筆結果被寫到別人身上
 *    （bug 本身已由 `f9e835c72` 修復，不再新增）
 *
 * ## 修正目的
 * 入口是所有 `type=N`、`status=0` 的孤兒；對每筆孤兒 O 依 {@see self::findPollutionFor()} 自動分流：
 *
 * | 判定 | 處理 | 審計 `source` |
 * |---|---|---|
 * | 找不到汙染記錄 | 單獨補 O，寫 1 筆 recovery log | `pure_orphan` |
 * | 存在汙染 P（`status=1`、`info[1].BetId == O.bet_id` 且 `info[0] != info[1]`） | 同 transaction 修 P ＋ O，兩端各還原為真實資料，寫 2 筆 log | `pair (paired_with_bet_record_id=…)` |
 *
 * **必要前置＝K5 直出的逐筆 CSV**（21 欄位精度完整版，`TranID` / `BetTranID` 為 17 位完整數字）。
 * xlsx 或從 xlsx 轉出的 CSV 因末位精度遺失無法精確配對；CSV 缺該 BetId 時只會記
 * `skip_csv_missing_orphan` 跳過，等於空跑。
 *
 * ## 觸發時機
 * `kissh5sw:export-orphans` 匯出非零、且已向 KissH5 取得對應原始逐筆資料之後。
 * **沒有 CSV 不要跑**——不會有任何作用。
 *
 * ## 冪等性與重跑安全性
 * - **預設 dry-run**；`--execute` 才寫入，且 `--execute` 必填 `--reason`
 * - 鎖內二次驗證（status／汙染條件／是否已被撤回）
 * - 雙重冪等：① status／汙染 SQL 過濾 ② {@see BetRecordRecoveryLog::isCurrentlyRecovered()}
 * - 配對缺一即跳過（CSV 必須同時有 P 與 O 的 row）
 * - 補帳可經 `bet-record-recovery:revert --log-ids=...` 撤回
 *
 * ⚠️ 本命令會 `update` 玩家錢包餘額、開 `TransactionLog`、建 TBR。
 * **execute 前務必先跑 dry-run 核對 Δwallet 金額與筆數。**
 *
 * ## 退役條件
 * 與 {@see ExportOrphans} 一致——只要 KissH5 仍在線上、孤兒仍可能產生，本命令就該留著。
 * 若日後平台停止接入 KissH5SW（`config/vendor.php` 移除 vendor_id 233），連同整個
 * `Vendor/KissH5SW/` 目錄一併移除。
 *
 * ## 關聯
 * - 機制與處置通則：`docs/integrations/vendors/KissH5/integration.md` §4（大整數精度）／§5（孤兒）
 * - 對帳流程與 2026-05 incident 始末：`docs/reconcile/vendors/KissH5SW.md`
 * - 偵測命令：`kissh5sw:export-orphans`
 * - 測試：`tests/Feature/Console/Commands/Vendor/KissH5SW/RepairOrphansTest.php`（7 情境）
 *
 * ## 用法
 *   php artisan kissh5sw:repair-orphans _local/kissh5sw/full_period.csv
 *   php artisan kissh5sw:repair-orphans _local/kissh5sw/full_period.csv --execute --reason="客服票號 #1234"
 */
class RepairOrphans extends Command
{
    protected $signature = 'kissh5sw:repair-orphans
        {csv* : K5 直出的逐筆 CSV（21 欄位精度完整版，可給多個檔）}
        {--execute : 真執行；預設 dry-run}
        {--reason= : 修復原因（execute 模式必填）}
        {--by= : 執行者標記，預設 command 名稱}';

    protected $description = '【高風險】修復 KissH5SW 孤兒注單：依 K5 原始 CSV 還原結果並補回玩家錢包（自動分流純孤兒／配對汙染）';

    private const string CMD_NAME = 'kissh5sw:repair-orphans';

    private bool $executeMode = false;

    private string $performedBy;

    private string $reason = '';

    /** @var array<int, array<string, mixed>> */
    private array $report = [];

    public function handle(): int
    {
        $this->executeMode = (bool) $this->option('execute');
        $this->performedBy = (string) ($this->option('by') ?: self::CMD_NAME);
        $this->reason = (string) $this->option('reason');

        if ($this->executeMode && trim($this->reason) === '') {
            $this->error('❌ --execute 模式必須提供 --reason="..."');

            return self::FAILURE;
        }

        $csvPaths = $this->resolveCsvPaths();
        if (empty($csvPaths)) {
            return self::FAILURE;
        }

        $this->printHeader($csvPaths);

        $vendorInfo = VendorInfo::where('vendor_id', VendorIdEnum::KISSH5SW->value)->first();
        if (!$vendorInfo) {
            $this->error('找不到 KissH5SW VendorInfo');

            return self::FAILURE;
        }

        $csvIndex = [];
        foreach ($csvPaths as $path) {
            foreach ($this->parseCsv($path) as $row) {
                $csvIndex[$row['bet_tran_id']] = $row;
            }
        }
        $this->info('CSV 唯一注單數（by BetTranID）：' . count($csvIndex));

        $orphans = $this->detectOrphans();
        $this->info('待處理孤兒數：' . $orphans->count());
        $this->newLine();

        if ($orphans->isEmpty()) {
            $this->info('✅ 沒有孤兒需要處理');
            $this->printSummary();
            $this->writeReport();

            return self::SUCCESS;
        }

        foreach ($orphans as $orphan) {
            $this->processOrphan($orphan, $csvIndex, $vendorInfo);
        }

        $this->printSummary();
        $reportPath = $this->writeReport();
        $this->info('📄 報告：' . $reportPath);

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function resolveCsvPaths(): array
    {
        $args = (array) $this->argument('csv');
        $paths = [];
        foreach ($args as $arg) {
            $p = str_starts_with((string) $arg, '/') ? (string) $arg : base_path((string) $arg);
            if (!is_file($p)) {
                $this->error('找不到 CSV：' . $p);

                return [];
            }
            $paths[] = $p;
        }

        return $paths;
    }

    private function printHeader(array $csvPaths): void
    {
        $this->info('========================================');
        $this->info('🔧 KissH5SW 精度 bug 注單修復');
        $this->info('========================================');
        $this->info('模式: ' . ($this->executeMode ? '⚠️  EXECUTE（真寫入）' : '🧪 DRY-RUN'));
        $this->info('CSV 檔案數: ' . count($csvPaths));
        foreach ($csvPaths as $p) {
            $this->line('  - ' . $p);
        }
        if ($this->executeMode) {
            $this->info('執行者: ' . $this->performedBy);
            $this->info('原因: ' . $this->reason);
        }
        $this->newLine();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsv(string $path): array
    {
        $fp = fopen($path, 'r');
        if ($fp === false) {
            return [];
        }

        $rows = [];
        $header = fgetcsv($fp, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($fp);

            return [];
        }
        $headerMap = array_flip(array_map(
            fn ($h) => trim(str_replace("\xEF\xBB\xBF", '', (string) $h)),
            $header
        ));
        foreach (['TranID', 'BetTranID', 'TranDateTime', 'PlayerId', 'ValidTurnover', 'Turnover', 'Payout', 'TranType'] as $required) {
            if (!isset($headerMap[$required])) {
                fclose($fp);
                throw new RuntimeException('CSV 缺欄位：' . $required);
            }
        }

        while (($cols = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
            if (count($cols) < count($header)) {
                continue;
            }
            $tranType = (int) $cols[$headerMap['TranType']];
            if ($tranType !== 2) {
                continue;
            }

            $rows[] = [
                'tran_id' => (string) $cols[$headerMap['TranID']],
                'bet_tran_id' => (string) $cols[$headerMap['BetTranID']],
                'tran_date_utc' => $this->parseTranDateUtc((string) $cols[$headerMap['TranDateTime']]),
                'player_id' => (string) $cols[$headerMap['PlayerId']],
                'valid_bet' => $this->absStr((string) $cols[$headerMap['ValidTurnover']]),
                'bet' => $this->absStr((string) $cols[$headerMap['Turnover']]),
                'payout' => $this->normalizeNumber((string) $cols[$headerMap['Payout']]),
            ];
        }
        fclose($fp);

        return $rows;
    }

    private function normalizeNumber(string $s): string
    {
        $clean = str_replace([',', ' '], '', trim($s));
        if ($clean === '' || $clean === '-') {
            return '0.0000';
        }

        return Common::numAdd('0', $clean, 4);
    }

    private function absStr(string $s): string
    {
        $n = $this->normalizeNumber($s);

        return Common::numCompare($n, '0') < 0 ? Common::numMul($n, '-1', 4) : $n;
    }

    private function parseTranDateUtc(string $s): string
    {
        try {
            return Carbon::parse(trim($s))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '';
        }
    }

    private function detectOrphans(): \Illuminate\Support\Collection
    {
        return KissH5SWBetRecord::query()
            ->where('type', 'N')
            ->where('status', 0)
            ->orderBy('id')
            ->get();
    }

    private function findPollutionFor(KissH5SWBetRecord $orphan): ?KissH5SWBetRecord
    {
        return KissH5SWBetRecord::query()
            ->where('type', 'N')
            ->where('status', 1)
            ->whereRaw('JSON_LENGTH(info) >= 2')
            ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(info, "$[1].BetId")) = ?', [(string) $orphan->bet_id])
            ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(info, "$[0].BetId")) != JSON_UNQUOTE(JSON_EXTRACT(info, "$[1].BetId"))')
            ->first();
    }

    private function processOrphan(KissH5SWBetRecord $orphan, array $csvIndex, VendorInfo $vendorInfo): void
    {
        if (BetRecordRecoveryLog::isCurrentlyRecovered(VendorIdEnum::KISSH5SW->value, (int) $orphan->id)) {
            $this->collect('skip_orphan_already_recovered', null, $orphan, '孤兒已有生效中的補帳事件');

            return;
        }

        $oCsv = $csvIndex[(string) $orphan->bet_id] ?? null;
        if (!$oCsv) {
            $this->collect('skip_csv_missing_orphan', null, $orphan, "CSV 缺孤兒 BetId={$orphan->bet_id}");

            return;
        }

        $pollution = $this->findPollutionFor($orphan);

        if ($pollution === null) {
            $this->processSingle($orphan, $oCsv, $vendorInfo);

            return;
        }

        $pCsv = $csvIndex[(string) $pollution->bet_id] ?? null;
        if (!$pCsv) {
            $this->collect('skip_csv_missing_pollution', $pollution, $orphan, "CSV 缺汙染 BetId={$pollution->bet_id}");

            return;
        }

        if (BetRecordRecoveryLog::isCurrentlyRecovered(VendorIdEnum::KISSH5SW->value, (int) $pollution->id)) {
            $this->collect('skip_pollution_already_recovered', $pollution, $orphan, '汙染端已有生效中的補帳事件');

            return;
        }

        $this->processPair($orphan, $pollution, $oCsv, $pCsv, $vendorInfo);
    }

    private function processSingle(KissH5SWBetRecord $orphan, array $oCsv, VendorInfo $vendorInfo): void
    {
        $plan = [
            'target' => [
                'bet' => $oCsv['bet'],
                'valid_bet' => $oCsv['valid_bet'],
                'win' => $oCsv['payout'],
                'win_lose' => Common::numSub($oCsv['payout'], $oCsv['bet'], 4),
                'reference_no' => $oCsv['tran_id'],
                'payout_time' => $oCsv['tran_date_utc'],
                'status' => 1,
            ],
            'wallet_change' => $oCsv['payout'],
        ];

        if (!$this->executeMode) {
            $this->collect(
                'would_repair_pure',
                null,
                $orphan,
                'pure orphan: status 0→1 / Δwallet ' . $plan['wallet_change'],
                '0',
                $plan['wallet_change']
            );

            return;
        }

        try {
            DB::transaction(fn () => $this->applySingle($orphan, $plan, $vendorInfo, $oCsv));
            $this->collect('repaired_pure', null, $orphan, 'Δwallet ' . $plan['wallet_change'], '0', $plan['wallet_change']);
        } catch (Throwable $e) {
            Log::error(self::CMD_NAME . ' single failed', [
                'orphan_id' => $orphan->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            $this->collect('failed_pure', null, $orphan, '失敗：' . $e->getMessage());
        }
    }

    private function processPair(
        KissH5SWBetRecord $orphan,
        KissH5SWBetRecord $pollution,
        array $oCsv,
        array $pCsv,
        VendorInfo $vendorInfo,
    ): void {
        $pTarget = [
            'bet' => $pCsv['bet'],
            'valid_bet' => $pCsv['valid_bet'],
            'win' => $pCsv['payout'],
            'win_lose' => Common::numSub($pCsv['payout'], $pCsv['bet'], 4),
            'reference_no' => $pCsv['tran_id'],
            'payout_time' => $pCsv['tran_date_utc'],
            'status' => 1,
        ];
        $oTarget = [
            'bet' => $oCsv['bet'],
            'valid_bet' => $oCsv['valid_bet'],
            'win' => $oCsv['payout'],
            'win_lose' => Common::numSub($oCsv['payout'], $oCsv['bet'], 4),
            'reference_no' => $oCsv['tran_id'],
            'payout_time' => $oCsv['tran_date_utc'],
            'status' => 1,
        ];

        // P 錢包：扣回當前錯誤被加上的 win（=O 的 settle payout 給了 P），加上 P 真實 win
        $pWalletChange = Common::numSub($pCsv['payout'], (string) $pollution->win, 4);

        // O 錢包：原本 status=0 沒 settle 過，直接補上應得 win
        $oWalletChange = $oCsv['payout'];

        if (!$this->executeMode) {
            $this->collect(
                'would_repair_pair',
                $pollution,
                $orphan,
                "pair: P(member={$pollution->member_id}) Δwallet {$pWalletChange} / O(member={$orphan->member_id}) Δwallet {$oWalletChange}",
                $pWalletChange,
                $oWalletChange
            );

            return;
        }

        try {
            DB::transaction(fn () => $this->applyPair(
                $orphan, $pollution, $oTarget, $pTarget, $oWalletChange, $pWalletChange, $vendorInfo
            ));
            $this->collect(
                'repaired_pair',
                $pollution,
                $orphan,
                "pair Δwallet P={$pWalletChange} O={$oWalletChange}",
                $pWalletChange,
                $oWalletChange
            );
        } catch (Throwable $e) {
            Log::error(self::CMD_NAME . ' pair failed', [
                'pollution_id' => $pollution->id,
                'orphan_id' => $orphan->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            $this->collect('failed_pair', $pollution, $orphan, '失敗：' . $e->getMessage());
        }
    }

    private function applySingle(KissH5SWBetRecord $oSeed, array $plan, VendorInfo $vendorInfo, array $oCsv): void
    {
        $o = KissH5SWBetRecord::where('id', $oSeed->id)->lockForUpdate()->first();
        if (!$o) {
            throw new RuntimeException('orphan record 不見了');
        }
        if ((int) $o->status !== 0) {
            throw new RuntimeException('鎖內 re-check：orphan status 已不是 0');
        }
        if (BetRecordRecoveryLog::isCurrentlyRecovered(VendorIdEnum::KISSH5SW->value, (int) $o->id)) {
            throw new RuntimeException('鎖內 re-check：已被其他流程處理');
        }

        $member = Member::find($o->member_id);
        if (!$member) {
            throw new RuntimeException('找不到 member_id=' . $o->member_id);
        }
        $account = KissH5SWAccount::where('username', $o->username)->first();

        $before = $this->snapshotRecord($o);

        $transactionLogId = $this->applyWalletAndRecord(
            $o, $plan['target'], $plan['wallet_change'], $member, $vendorInfo, $account,
            'pure_orphan_repair', $oCsv
        );

        $totalSvc = new TotalBetRecordService(VendorIdEnum::KISSH5SW->value, $member->id, false);
        $total = $totalSvc->create($o, $transactionLogId);

        $after = $this->snapshotRecord($o);
        $after['_meta'] = [
            'transaction_log_id' => $transactionLogId,
            'total_bet_record_id' => $total->id,
            'is_multiple_wallet' => false,
            'pair_role' => 'pure_orphan',
            'paired_bet_record_id' => null,
        ];

        BetRecordRecoveryLog::create([
            'vendor_id' => VendorIdEnum::KISSH5SW->value,
            'bet_record_id' => $o->id,
            'bet_id' => (string) $o->bet_id,
            'member_id' => $member->id,
            'type' => BetRecordRecoveryTypeEnum::RECOVER->value,
            'performed_at' => now(),
            'performed_by' => $this->performedBy,
            'reason' => $this->reason,
            'source' => 'pure_orphan',
            'before' => $before,
            'after' => $after,
            'balance_diff' => $plan['wallet_change'],
            'parent_log_id' => null,
        ]);

        Log::info(self::CMD_NAME . ' single repaired', [
            'orphan_id' => $o->id,
            'member_id' => $member->id,
            'wallet_change' => $plan['wallet_change'],
            'transaction_log_id' => $transactionLogId,
        ]);
    }

    private function applyPair(
        KissH5SWBetRecord $oSeed,
        KissH5SWBetRecord $pSeed,
        array $oTarget,
        array $pTarget,
        string $oWalletChange,
        string $pWalletChange,
        VendorInfo $vendorInfo,
    ): void {
        $p = KissH5SWBetRecord::where('id', $pSeed->id)->lockForUpdate()->first();
        $o = KissH5SWBetRecord::where('id', $oSeed->id)->lockForUpdate()->first();
        if (!$p || !$o) {
            throw new RuntimeException('record 不見了');
        }

        // 鎖內二次驗證
        $latestP = $this->extractInfoBetId($p->info, 0);
        $latestPSettle = $this->extractInfoBetId($p->info, 1);
        if ($latestP === null || $latestPSettle === null || $latestP === $latestPSettle) {
            throw new RuntimeException('鎖內 re-check：pollution info 不再有 BetId 不一致');
        }
        if ((int) $o->status !== 0) {
            throw new RuntimeException('鎖內 re-check：orphan status 已不是 0');
        }
        if (BetRecordRecoveryLog::isCurrentlyRecovered(VendorIdEnum::KISSH5SW->value, (int) $p->id)
            || BetRecordRecoveryLog::isCurrentlyRecovered(VendorIdEnum::KISSH5SW->value, (int) $o->id)) {
            throw new RuntimeException('鎖內 re-check：已被其他流程處理');
        }

        $pMember = Member::find($p->member_id);
        $oMember = Member::find($o->member_id);
        if (!$pMember || !$oMember) {
            throw new RuntimeException('找不到 member（P=' . $p->member_id . ', O=' . $o->member_id . '）');
        }
        $pAccount = KissH5SWAccount::where('username', $p->username)->first();
        $oAccount = KissH5SWAccount::where('username', $o->username)->first();

        $pBefore = $this->snapshotRecord($p);
        $oBefore = $this->snapshotRecord($o);

        $pTransLogId = $this->applyWalletAndRecord(
            $p, $pTarget, $pWalletChange, $pMember, $vendorInfo, $pAccount,
            "pair_pollution#paired_with_orphan_record_id_{$o->id}", null
        );
        $oTransLogId = $this->applyWalletAndRecord(
            $o, $oTarget, $oWalletChange, $oMember, $vendorInfo, $oAccount,
            "pair_orphan#paired_with_pollution_record_id_{$p->id}", null
        );

        $totalSvcP = new TotalBetRecordService(VendorIdEnum::KISSH5SW->value, $pMember->id, false);
        $pTotal = $totalSvcP->create($p, $pTransLogId);

        $totalSvcO = new TotalBetRecordService(VendorIdEnum::KISSH5SW->value, $oMember->id, false);
        $oTotal = $totalSvcO->create($o, $oTransLogId);

        $pAfter = $this->snapshotRecord($p);
        $oAfter = $this->snapshotRecord($o);

        BetRecordRecoveryLog::create([
            'vendor_id' => VendorIdEnum::KISSH5SW->value,
            'bet_record_id' => $p->id,
            'bet_id' => (string) $p->bet_id,
            'member_id' => $pMember->id,
            'type' => BetRecordRecoveryTypeEnum::RECOVER->value,
            'performed_at' => now(),
            'performed_by' => $this->performedBy,
            'reason' => $this->reason,
            'source' => "pair (paired_with_bet_record_id={$o->id})",
            'before' => $pBefore,
            'after' => array_merge($pAfter, ['_meta' => [
                'transaction_log_id' => $pTransLogId,
                'total_bet_record_id' => $pTotal->id,
                'is_multiple_wallet' => false,
                'pair_role' => 'pollution',
                'paired_bet_record_id' => $o->id,
            ]]),
            'balance_diff' => $pWalletChange,
            'parent_log_id' => null,
        ]);

        BetRecordRecoveryLog::create([
            'vendor_id' => VendorIdEnum::KISSH5SW->value,
            'bet_record_id' => $o->id,
            'bet_id' => (string) $o->bet_id,
            'member_id' => $oMember->id,
            'type' => BetRecordRecoveryTypeEnum::RECOVER->value,
            'performed_at' => now(),
            'performed_by' => $this->performedBy,
            'reason' => $this->reason,
            'source' => "pair (paired_with_bet_record_id={$p->id})",
            'before' => $oBefore,
            'after' => array_merge($oAfter, ['_meta' => [
                'transaction_log_id' => $oTransLogId,
                'total_bet_record_id' => $oTotal->id,
                'is_multiple_wallet' => false,
                'pair_role' => 'paired_orphan',
                'paired_bet_record_id' => $p->id,
            ]]),
            'balance_diff' => $oWalletChange,
            'parent_log_id' => null,
        ]);

        Log::info(self::CMD_NAME . ' pair repaired', [
            'pollution_id' => $p->id,
            'orphan_id' => $o->id,
            'p_member_id' => $pMember->id,
            'o_member_id' => $oMember->id,
            'p_wallet_change' => $pWalletChange,
            'o_wallet_change' => $oWalletChange,
        ]);
    }

    private function applyWalletAndRecord(
        KissH5SWBetRecord $record,
        array $target,
        string $walletChange,
        Member $member,
        VendorInfo $vendorInfo,
        ?KissH5SWAccount $account,
        string $marker,
        ?array $csvRow,
    ): int {
        $walletDto = (new WalletService)->getMemberLockedWallets($member, $vendorInfo);
        $beforeBalance = (string) $walletDto->wallet->balance;
        $afterBalance = Common::numAdd($beforeBalance, $walletChange, 4);

        $record->status = $target['status'];
        $record->bet = $target['bet'];
        $record->valid_bet = $target['valid_bet'];
        $record->win = $target['win'];
        $record->win_lose = $target['win_lose'];
        $record->reference_no = $target['reference_no'];
        if ($target['payout_time'] !== '') {
            $record->payout_time = $target['payout_time'];
        }
        $record->info = $this->appendRepairMarker((string) ($record->info ?? ''), $marker, $csvRow);

        $transactionLogId = 0;
        if (Common::numCompare($walletChange, '0') !== 0) {
            $walletDto->wallet->update(['balance' => $afterBalance]);
            $logService = new TransactionLogCreateService($member, $vendorInfo, false, $account);
            $logService->setType(TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value);
            $tx = $logService->create($walletChange, $beforeBalance, $afterBalance);
            $transactionLogId = $tx->id;
        }

        $record->save();

        return $transactionLogId;
    }

    private function snapshotRecord(KissH5SWBetRecord $r): array
    {
        return [
            'status' => (int) $r->status,
            'reference_no' => (string) $r->reference_no,
            'bet' => (string) $r->bet,
            'valid_bet' => (string) $r->valid_bet,
            'win' => (string) $r->win,
            'win_lose' => (string) $r->win_lose,
            'payout_time' => $this->formatPayoutTime($r->payout_time),
        ];
    }

    private function formatPayoutTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function extractInfoBetId(?string $info, int $index): ?string
    {
        if ($info === null || $info === '') {
            return null;
        }
        $arr = json_decode($info, true);
        if (!is_array($arr) || !isset($arr[$index]['BetId'])) {
            return null;
        }

        return (string) $arr[$index]['BetId'];
    }

    private function appendRepairMarker(string $info, string $marker, ?array $csvRow): string
    {
        $current = json_decode($info ?: '[]', true) ?: [];
        $entry = [
            '_repaired' => [
                'at' => now()->toDateTimeString(),
                'by' => $this->performedBy,
                'reason' => $this->reason,
                'marker' => $marker,
            ],
        ];
        if ($csvRow !== null) {
            $entry['_repaired']['csv_data'] = [
                'TranID' => $csvRow['tran_id'],
                'BetTranID' => $csvRow['bet_tran_id'],
                'TranDateTime' => $csvRow['tran_date_utc'],
                'Bet' => $csvRow['bet'],
                'ValidBet' => $csvRow['valid_bet'],
                'Payout' => $csvRow['payout'],
            ];
        }
        $current[] = $entry;

        return json_encode($current, JSON_UNESCAPED_UNICODE);
    }

    private function collect(
        string $status,
        ?KissH5SWBetRecord $pollution,
        ?KissH5SWBetRecord $orphan,
        string $message,
        string $pWalletChange = '0',
        string $oWalletChange = '0',
    ): void {
        $this->report[] = [
            'status' => $status,
            'pollution_id' => $pollution?->id,
            'pollution_member_id' => $pollution?->member_id,
            'pollution_bet_id' => $pollution?->bet_id,
            'orphan_id' => $orphan?->id,
            'orphan_member_id' => $orphan?->member_id,
            'orphan_bet_id' => $orphan?->bet_id,
            'p_wallet_change' => $pWalletChange,
            'o_wallet_change' => $oWalletChange,
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
        $this->info($this->executeMode ? '✅ 執行結果' : '🧪 Dry-run 結果');
        $this->info('========================================');

        $rows = [];
        $totalP = '0';
        $totalO = '0';
        foreach ($byStatus as $status => $count) {
            $sumP = '0';
            $sumO = '0';
            foreach ($this->report as $r) {
                if ($r['status'] === $status) {
                    $sumP = Common::numAdd($sumP, $r['p_wallet_change'], 4);
                    $sumO = Common::numAdd($sumO, $r['o_wallet_change'], 4);
                }
            }
            $rows[] = [$status, $count, $sumP, $sumO];
            if (in_array($status, ['repaired_pair', 'repaired_pure', 'would_repair_pair', 'would_repair_pure'], true)) {
                $totalP = Common::numAdd($totalP, $sumP, 4);
                $totalO = Common::numAdd($totalO, $sumO, 4);
            }
        }
        $this->table(['狀態', '筆數', 'ΣΔ_P', 'ΣΔ_O'], $rows);
        $this->info('待修/已修玩家錢包淨變動：P 端=' . $totalP . ' / O 端=' . $totalO);
    }

    private function writeReport(): string
    {
        $mode = $this->executeMode ? 'execute' : 'dryrun';
        $path = storage_path('logs/kissh5sw_repair_orphans_' . $mode . '_' . now()->format('YmdHis') . '.csv');
        $fp = fopen($path, 'w');
        if ($fp === false) {
            return '';
        }
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'status',
            'pollution_id', 'pollution_member_id', 'pollution_bet_id',
            'orphan_id', 'orphan_member_id', 'orphan_bet_id',
            'p_wallet_change', 'o_wallet_change',
            'message',
        ]);
        foreach ($this->report as $r) {
            fputcsv($fp, [
                $r['status'],
                $r['pollution_id'], $r['pollution_member_id'], $r['pollution_bet_id'],
                $r['orphan_id'], $r['orphan_member_id'], $r['orphan_bet_id'],
                $r['p_wallet_change'], $r['o_wallet_change'],
                $r['message'],
            ]);
        }
        fclose($fp);

        return $path;
    }
}
