<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use App\Models\MemberBankAccount;
use App\Models\WithdrawalOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TrimAccountNoWhitespace extends Command
{
    protected $signature = 'fix:trim-account-no-whitespace
        {--dry-run : 只預覽變更，不寫入資料庫}
        {--from-id=1 : 起始 id（中斷後可用於續跑）}
        {--to-id= : 結束 id（預設各表最大 id）}
        {--window-size=5000 : 每個 id 視窗寬度（以 PK 範圍切窗，避免非索引全表掃描）}
        {--sleep-ms=0 : 每個視窗間休眠毫秒數，降低大表 DB 壓力}
        {--force-conflicts : 強制處理會造成同組織重複卡號的衝突列（預設跳過）}';

    protected $description = '清除 member_bank_accounts 與 withdrawal_orders 的 account_no 頭尾空白（PayEssence 代付回調 CheckString2 驗簽失敗根因）。以 id-window 分批避免大表鎖定，並對綁卡做衝突預檢與變更 manifest。';

    private bool $isDryRun = false;

    private int $windowSize = 5000;

    private int $sleepMs = 0;

    private bool $forceConflicts = false;

    private int $fromId = 1;

    private ?int $toIdOption = null;

    /** @var resource|null */
    private $manifest = null;

    /**
     * 重複綁定衝突鍵集合（一次預掃建立，window 內 O(1) 查詢，避免 per-row EXISTS 打 DB）。
     *
     * @var array<string, true>
     */
    private array $conflictKeys = [];

    public function handle(): int
    {
        $this->isDryRun = (bool) $this->option('dry-run');
        $this->windowSize = max(1, (int) $this->option('window-size'));
        $this->sleepMs = max(0, (int) $this->option('sleep-ms'));
        $this->forceConflicts = (bool) $this->option('force-conflicts');
        $this->fromId = max(1, (int) $this->option('from-id'));

        $toId = $this->option('to-id');
        $this->toIdOption = ($toId === null || $toId === '') ? null : (int) $toId;

        if ($this->isDryRun) {
            $this->warn('⚠️  DRY RUN 模式：不會修改資料庫');
        } else {
            $this->openManifest();
        }

        $this->info(sprintf('視窗寬度 %d，sleep %dms，起始 id %d', $this->windowSize, $this->sleepMs, $this->fromId));

        $this->processMemberBankAccounts();
        $this->processWithdrawalOrders();

        if ($this->manifest !== null) {
            fclose($this->manifest);
        }

        $this->info($this->isDryRun ? 'DRY RUN 完成。' : '任務完成。');

        return self::SUCCESS;
    }

    private function openManifest(): void
    {
        $dir = storage_path('app/patches');

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/trim-account-no-' . date('Ymd_His') . '.csv';
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException('無法建立 manifest 檔案：' . $path);
        }

        fputcsv($handle, ['table', 'id', 'old_account_no', 'new_account_no']);
        $this->manifest = $handle;
        $this->info('變更 manifest：' . $path);
    }

    private function recordManifest(string $table, int $id, string $old, string $new): void
    {
        if ($this->manifest !== null) {
            fputcsv($this->manifest, [$table, $id, $old, $new]);
        }
    }

    /**
     * member_bank_accounts：含「同組織重複卡號」衝突預檢。
     */
    private function processMemberBankAccounts(): void
    {
        $label = 'member_bank_accounts';
        $maxId = $this->toIdOption ?? (int) (MemberBankAccount::max('id') ?? 0);

        if ($maxId < $this->fromId) {
            $this->info(sprintf('%s：id 範圍無資料，略過。', $label));

            return;
        }

        // 一次預掃建立衝突鍵集合（取代 per-row EXISTS，大幅降低 DB 查詢次數）
        $this->conflictKeys = $this->buildConflictKeySet();
        $this->info(sprintf('%s：衝突鍵預掃完成（%d 組重複）', $label, count($this->conflictKeys)));

        $this->info(sprintf('%s：處理 id 範圍 [%d..%d]', $label, $this->fromId, $maxId));
        $trimmed = 0;
        $conflicts = 0;
        $start = $this->fromId;

        while ($start <= $maxId) {
            $end = min($start + $this->windowSize - 1, $maxId);

            $rows = MemberBankAccount::query()
                ->leftJoin('members', 'members.id', '=', 'member_bank_accounts.member_id')
                ->leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->whereBetween('member_bank_accounts.id', [$start, $end])
                ->whereRaw('LENGTH(member_bank_accounts.account_no) <> LENGTH(TRIM(member_bank_accounts.account_no))')
                ->select(
                    'member_bank_accounts.id',
                    'member_bank_accounts.account_no',
                    'member_bank_accounts.bank_id',
                    'agents.organization_id',
                    'agents.binding_scope_id',
                )
                ->get();

            $applyIds = [];

            foreach ($rows as $row) {
                $normalized = trim((string) $row->account_no);

                // 有組織歸屬才做重複綁定衝突預檢；孤兒卡（member/agent 缺失）無組織約束，直接 trim
                $hasScope = $row->organization_id !== null;

                if ($hasScope && $this->memberBankAccountConflicts($row, $normalized)) {
                    $conflicts++;
                    $this->warn(sprintf(
                        '  衝突跳過 id=%d bank_id=%d org=%s scope=%s normalized=[%s]',
                        $row->id,
                        $row->bank_id,
                        $row->organization_id,
                        $row->binding_scope_id,
                        $normalized,
                    ));

                    if (!$this->forceConflicts) {
                        continue;
                    }
                }

                $trimmed++;
                $this->recordManifest($label, (int) $row->id, (string) $row->account_no, $normalized);
                $applyIds[] = $row->id;
            }

            if (!$this->isDryRun && $applyIds !== []) {
                DB::transaction(function () use ($applyIds): void {
                    MemberBankAccount::whereIn('id', $applyIds)
                        ->update(['account_no' => DB::raw('TRIM(account_no)')]);
                });
            }

            $this->line(sprintf('  [%d..%d] 命中 %d，累計清理 %d / 衝突 %d', $start, $end, $rows->count(), $trimmed, $conflicts));
            $start = $end + 1;
            $this->throttle();
        }

        $this->info(sprintf('%s：%s清理 %d 筆，衝突 %d 筆。', $label, $this->isDryRun ? '預計' : '已', $trimmed, $conflicts));
    }

    /**
     * 一次預掃：找出所有「同組織 + 同 binding scope + 同 bank_id + TRIM 後相同」且 status=1 出現多次的群組。
     * 回傳衝突鍵集合，供 window 內 O(1) 判斷，取代 per-row EXISTS（VWorld 實測 360s→大幅下降）。
     *
     * @return array<string, true>
     */
    private function buildConflictKeySet(): array
    {
        $set = [];

        MemberBankAccount::query()
            ->join('members', 'members.id', '=', 'member_bank_accounts.member_id')
            ->join('agents', 'agents.id', '=', 'members.parent')
            ->where('member_bank_accounts.status', 1)
            ->groupBy('agents.organization_id', 'agents.binding_scope_id', 'member_bank_accounts.bank_id', DB::raw('TRIM(member_bank_accounts.account_no)'))
            ->havingRaw('COUNT(*) > 1')
            ->get([
                'agents.organization_id as org',
                'agents.binding_scope_id as binding',
                'member_bank_accounts.bank_id as bank_id',
                DB::raw('TRIM(member_bank_accounts.account_no) as norm'),
            ])
            ->each(function (object $r) use (&$set): void {
                $set[$this->conflictKey((int) $r->org, (int) $r->binding, (int) $r->bank_id, (string) $r->norm)] = true;
            });

        return $set;
    }

    /**
     * trim 後若與同組織 + 同 binding scope + 同 bank_id 的其他有效卡號相同，視為重複綁定衝突。
     */
    private function memberBankAccountConflicts(object $row, string $normalized): bool
    {
        return isset($this->conflictKeys[$this->conflictKey(
            (int) $row->organization_id,
            (int) $row->binding_scope_id,
            (int) $row->bank_id,
            $normalized,
        )]);
    }

    private function conflictKey(int $org, int $binding, int $bankId, string $norm): string
    {
        return $org . '|' . $binding . '|' . $bankId . '|' . $norm;
    }

    /**
     * withdrawal_orders：歷史訂單，帳號本就允許重複，不做衝突預檢；仍寫 manifest 供回溯。
     */
    private function processWithdrawalOrders(): void
    {
        $label = 'withdrawal_orders';
        $maxId = $this->toIdOption ?? (int) (WithdrawalOrder::max('id') ?? 0);

        if ($maxId < $this->fromId) {
            $this->info(sprintf('%s：id 範圍無資料，略過。', $label));

            return;
        }

        $this->info(sprintf('%s：處理 id 範圍 [%d..%d]', $label, $this->fromId, $maxId));
        $trimmed = 0;
        $start = $this->fromId;

        while ($start <= $maxId) {
            $end = min($start + $this->windowSize - 1, $maxId);

            $rows = WithdrawalOrder::query()
                ->whereBetween('id', [$start, $end])
                ->whereRaw('LENGTH(account_no) <> LENGTH(TRIM(account_no))')
                ->select('id', 'account_no')
                ->get();

            if ($rows->isNotEmpty()) {
                foreach ($rows as $row) {
                    $this->recordManifest($label, (int) $row->id, (string) $row->account_no, trim((string) $row->account_no));
                }

                if (!$this->isDryRun) {
                    $ids = $rows->pluck('id')->all();
                    DB::transaction(function () use ($ids): void {
                        WithdrawalOrder::whereIn('id', $ids)
                            ->update(['account_no' => DB::raw('TRIM(account_no)')]);
                    });
                }

                $trimmed += $rows->count();
            }

            $this->line(sprintf('  [%d..%d] 命中 %d，累計清理 %d', $start, $end, $rows->count(), $trimmed));
            $start = $end + 1;
            $this->throttle();
        }

        $this->info(sprintf('%s：%s清理 %d 筆。', $label, $this->isDryRun ? '預計' : '已', $trimmed));
    }

    private function throttle(): void
    {
        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }
    }
}
