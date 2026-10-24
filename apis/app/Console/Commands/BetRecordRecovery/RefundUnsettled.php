<?php

declare(strict_types=1);

namespace App\Console\Commands\BetRecordRecovery;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\BetRecordStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\LockTimeoutException;
use App\Helpers\Common;
use App\Models\BetRecordRecoveryLog;
use App\Models\Member;
use App\Models\TotalBetRecord;
use App\Models\VendorInfo;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\FumaSW\Enums\BetTypeEnum as FumaSWBetTypeEnum;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\AbstractBetRecord;
use App\Vendors\Models\FumaswAccount;
use App\Vendors\Models\FumaswBetRecord;
use App\Vendors\Models\PtswAccount;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\Models\PtswDealRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum as PtswBetRecordStatusEnum;
use App\Vendors\PlayTechSW\GameRoundLock;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * 退回「已扣款但三方判定投注失敗、永不派彩」的單一錢包注單（跨 vendor 骨架：fumasw / ptsw）。
 *
 * ptsw（2026-09-05 同一事故，target/op_tickets#3083）：PT 端已作廢並反覆送 REFUND＋gameRoundClose，
 * 我方每次撞鎖回滾、PT 停止重送，注單停在 status=1。終態比照 fumasw（status 1→2、錢包退回、不建 TBR），
 * 另依 PTSW 補帳紀律補一筆 gameRoundResult 退款 deal record（PT 原始 tc／requestId 由 --bet-id-file 的
 * meta 欄提供：bet_id,bet_tc,refund_tc,refund_request_id,refund_transaction_date,round_close_date,refund_amount）。
 *
 * 情境：2026-09-05 TPower 疑似 DDoS，FumaSW action 2 在我方拖了 13～51 秒才 commit，
 * FumaSW 早已 timeout 判定投注失敗；FumaSW 協定沒有取消 action、也不會再送任何請求，
 * 注單停在 type=BET／status=0，錢只能由我方主動退。
 *
 * 逐筆行為（execute 模式，單筆一個 transaction）：
 *   1. 鎖注單列，斷言仍為 BET／未結算／同回合無派彩列／未進 TBR／未曾 refund
 *   2. 以 WalletService::getMemberLockedWallets() 取「下注時扣的那個錢包」並上鎖，退回 bet 金額
 *   3. 帳變流水走 TransactionLogCreateService，顯式 setType(SEAMLESS_WALLET_CANCEL)
 *   4. 注單 status 改 BetRecordStatusEnum::REFUND、payout_time 記取消時間、infos 追加 _refund 快照
 *   5. 寫 bet_record_recovery_logs（type=refund，before／after 快照、balance_diff=退款金額）
 *
 * 安全機制：預設 dry-run；--execute 必填 --reason；禮碼付款的注單一律拒絕（不硬處理禮碼錢包）；
 * 冪等由注單 status 與 recovery log 雙重判定。不動 TBR／SBR——這類注單本來就不在其中。
 *
 * 使用方式：
 *   php artisan bet-record-recovery:refund-unsettled --vendor=fumasw --bet-id=998090523480201195
 *   php artisan bet-record-recovery:refund-unsettled --vendor=fumasw --bet-id-file=storage/app/incident/xxx/bet_ids.txt
 *   php artisan bet-record-recovery:refund-unsettled --vendor=fumasw --bet-id-file=... --execute --reason="..."
 *
 * @knowledge rules/single-wallet-unsettled-refund
 */
class RefundUnsettled extends Command
{
    protected $signature = 'bet-record-recovery:refund-unsettled
        {--vendor= : 遊戲商代碼（fumasw / ptsw）}
        {--bet-id= : 單筆注單 bet_id}
        {--bet-ids= : 多筆 bet_id（逗號分隔）}
        {--bet-id-file= : 從檔案讀 bet_id（每行一個；CSV 取第一欄、略過空行；帶 bet_id 表頭時其餘欄位作為該筆 meta，ptsw 必填）}
        {--execute : 真執行；預設 dry-run}
        {--reason= : 退款原因（execute 模式必填）}
        {--by= : 執行者標記，預設 command 名稱}
        {--source= : 資料來源標記，預設為檔名或 cli}';

    protected $description = '退回三方判定投注失敗、永不派彩的單一錢包未結算注單（預設 dry-run）';

    private const string CMD_NAME = 'bet-record-recovery:refund-unsettled';

    private bool $executeMode = false;

    private string $performedBy;

    private string $reason = '';

    private string $source = 'cli';

    /**
     * @var array{
     *   bet_record: class-string<AbstractBetRecord>,
     *   account: class-string<AbstractAccount>,
     *   vendor_ids: int[],
     *   unsettled_status: int,
     *   refund_status: int,
     *   infos_column: string|null,
     *   required_meta: string[],
     *   is_bet: Closure(AbstractBetRecord): bool,
     *   has_settle: Closure(AbstractBetRecord): bool,
     *   after_refund: (Closure(AbstractBetRecord, AbstractAccount, array<string, string>, string): array<string, mixed>)|null,
     *   round_lock_key: (Closure(AbstractBetRecord, AbstractAccount): string)|null,
     * }
     */
    private array $profile;

    /** @var array<string, array<string, string>> bet_id → CSV meta 欄位（表頭檔才有） */
    private array $betMeta = [];

    /** @var array<int, array<string, mixed>> */
    private array $report = [];

    /** @var array<int, array<string, mixed>> */
    private array $playerSummary = [];

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

        $betIds = $this->resolveBetIds();
        if (empty($betIds)) {
            return self::FAILURE;
        }

        if ($this->executeMode && !$this->writeConnectionIsWritable()) {
            $this->error('❌ write 連線的 @@read_only=1（多半連到從庫，如本機 dbc 的 34xx port）。'
                . '帳號若持有 CONNECTION_ADMIN 會繞過 read_only 直接寫進從庫、造成複寫分歧；'
                . '請把 TP_DB_HOST_WRITE／TP_DB_PORT_WRITE 指向主庫（dbc 的 35xx port）後再執行。');

            return self::FAILURE;
        }

        $this->printHeader($betIds);

        $candidates = [];
        foreach ($betIds as $betId) {
            $candidate = $this->inspect($betId);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        $this->buildPlayerSummary($candidates);
        $this->printPlayerSummaryTable();

        foreach ($candidates as $candidate) {
            $this->process($candidate);
        }

        $this->printSummary();
        foreach ($this->writeReport() as $label => $path) {
            $this->info('📄 ' . $label . '：' . $path);
        }

        return self::SUCCESS;
    }

    /**
     * execute 模式的最後一道環境守衛：寫入必須落在主庫。
     * 從庫 read_only=1 對一般帳號會自然拒寫，但持有 CONNECTION_ADMIN／SUPER 的帳號會被放行，
     * 等於把退款寫進從庫；所以不能依賴伺服器擋，要在動手前自己驗。
     */
    private function writeConnectionIsWritable(): bool
    {
        $rows = DB::selectFromWriteConnection('SELECT @@read_only AS ro');

        return (int) ($rows[0]->ro ?? 1) === 0;
    }

    /**
     * @return array{
     *   bet_record: class-string<AbstractBetRecord>,
     *   account: class-string<AbstractAccount>,
     *   vendor_ids: int[],
     *   unsettled_status: int,
     *   refund_status: int,
     *   infos_column: string|null,
     *   required_meta: string[],
     *   is_bet: Closure(AbstractBetRecord): bool,
     *   has_settle: Closure(AbstractBetRecord): bool,
     *   after_refund: (Closure(AbstractBetRecord, AbstractAccount, array<string, string>, string): array<string, mixed>)|null,
     *   round_lock_key: (Closure(AbstractBetRecord, AbstractAccount): string)|null,
     * }
     */
    private function resolveVendorProfile(string $vendor): array
    {
        return match (strtolower(trim($vendor))) {
            'fumasw' => [
                'bet_record' => FumaswBetRecord::class,
                'account' => FumaswAccount::class,
                'vendor_ids' => [VendorIdEnum::FUMASW->value, VendorIdEnum::FUMASW_FREE->value],
                'unsettled_status' => BetRecordStatusEnum::PENDING,
                'refund_status' => BetRecordStatusEnum::REFUND,
                'infos_column' => 'infos',
                'required_meta' => [],
                // fumasw_bet_records.type 是 string 欄位，值為 BetTypeEnum 的整數
                'is_bet' => static fn (AbstractBetRecord $r): bool => (int) $r->type === FumaSWBetTypeEnum::BET,
                'has_settle' => static fn (AbstractBetRecord $r): bool => FumaswBetRecord::query()
                    ->where('round_id', $r->round_id)
                    ->where('username', $r->username)
                    ->where('type', '<>', FumaSWBetTypeEnum::BET)
                    ->exists(),
                'after_refund' => null,
                'round_lock_key' => null,
            ],
            'ptsw' => [
                'bet_record' => PtswBetRecord::class,
                'account' => PtswAccount::class,
                'vendor_ids' => [VendorIdEnum::PTSW->value],
                // PTSW 一局一列，IN_PROGRESS=1 即「已下注扣款、未結算」；退款終態沿用 REFUND=2
                'unsettled_status' => PtswBetRecordStatusEnum::IN_PROGRESS->value,
                'refund_status' => PtswBetRecordStatusEnum::REFUND->value,
                // ptsw_bet_records 無 infos 欄，退款快照只留在 bet_record_recovery_logs
                'infos_column' => null,
                // 補 deal record 需要 PT 原始退款回調的交易碼與 requestId，缺任一即不退
                'required_meta' => ['refund_tc', 'refund_request_id'],
                'is_bet' => static fn (AbstractBetRecord $r): bool => true,
                // 同局已有任何 gameRoundResult 事件（派彩／退款／關局）就不是「永遠結不了」的注單
                'has_settle' => static fn (AbstractBetRecord $r): bool => PtswDealRecord::query()
                    ->where('bet_id', $r->bet_id)
                    ->where('type', 'gameRoundResult')
                    ->exists(),
                'after_refund' => fn (AbstractBetRecord $record, AbstractAccount $account, array $meta, string $amount): array => [
                    'refund_deal_record_id' => $this->insertPtswRefundDealRecord(record: $record, account: $account, meta: $meta, amount: $amount)->id,
                ],
                // 與 PlayTechController 共用同一把同局 named lock：命令交易進行中若同局回調進來，回調在鎖後才開交易、看得到命令的退款事件
                'round_lock_key' => static fn (AbstractBetRecord $record, AbstractAccount $account): string => GameRoundLock::key((string) $record->getBetId(), (string) $account->username),
            ],
            default => throw new RuntimeException('Unsupported vendor: ' . ($vendor === '' ? '(empty)' : $vendor) . '，目前僅支援 fumasw / ptsw'),
        };
    }

    /**
     * 依 PTSW 補帳紀律補上「若回調沒失敗該寫入的」gameRoundResult 退款事件：
     * bet_amount 為負的退款額、win_amount 0、applied_at NULL（與 controller 的 refund 沖銷事件同形），
     * transaction_code／request_id 用 PT 原始回調值，raw 重組原始 payload 並標記 _recovery 供稽核。
     *
     * @param  array<string, string>  $meta
     */
    private function insertPtswRefundDealRecord(AbstractBetRecord $record, AbstractAccount $account, array $meta, string $amount): PtswDealRecord
    {
        $betId = (string) $record->getBetId();
        // pay.amount 只進 raw 供稽核：有 PT 原始回調值就用它，否則退回我方幣別的 BR.bet；來源寫進 _recovery 免得日後拿去當三方幣別重算
        $hasMetaAmount = isset($meta['refund_amount']) && $meta['refund_amount'] !== '';
        $refundAmount = $hasMetaAmount ? $meta['refund_amount'] : $amount;

        $raw = [
            'requestId' => $meta['refund_request_id'],
            'username' => (string) $account->username,
            'gameRoundCode' => $betId,
            'pay' => [
                'transactionCode' => $meta['refund_tc'],
                'transactionDate' => $meta['refund_transaction_date'] ?? null,
                'amount' => $refundAmount,
                'type' => 'REFUND',
                'relatedTransactionCode' => $meta['bet_tc'] ?? (string) $record->transaction_code,
            ],
            'action' => 'refund',
            '_recovery' => [
                'command' => self::CMD_NAME,
                'source' => $this->source,
                'reason' => $this->reason,
                'performed_by' => $this->performedBy,
                'pay_amount_source' => $hasMetaAmount ? 'meta.refund_amount' : 'bet_record.bet',
            ],
        ];
        if (!empty($meta['round_close_date'])) {
            $raw['gameRoundClose'] = ['date' => $meta['round_close_date']];
        }

        return PtswDealRecord::create([
            'request_id' => $meta['refund_request_id'],
            'username' => (string) $account->username,
            // 玩家多半早已登出、帳號 token 已清空；沿用注單當時的 session token 才與同局其他事件同形
            'token' => $record->token ?? $account->token,
            'bet_id' => $betId,
            'transaction_code' => $meta['refund_tc'],
            'type' => 'gameRoundResult',
            'bet_amount' => Common::numMul($amount, '-1', 4),
            'win_amount' => '0',
            'jackpot' => '0',
            'external_transaction_code' => Str::uuid()->toString(),
            'external_transaction_date' => now()->format('Y-m-d H:i:s.v'),
            'applied_at' => null,
            'raw' => $raw,
        ]);
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

        $file = (string) $this->option('bet-id-file');
        if ($file !== '') {
            $path = str_starts_with($file, '/') ? $file : base_path($file);
            if (!is_file($path)) {
                $this->error('找不到檔案：' . $path);

                return [];
            }
            $this->source = basename($path);
            $headers = null;
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $cells = array_map('trim', str_getcsv($line, ',', '"', '\\'));
                $first = $cells[0] ?? '';
                // Excel／本命令自己輸出的 CSV 首行帶 UTF-8 BOM，不剝掉會讓表頭判不到、BOM 黏進第一個 bet_id
                if (str_starts_with($first, "\xEF\xBB\xBF")) {
                    $first = $cells[0] = substr($first, 3);
                }
                if ($first === '') {
                    continue;
                }
                if (strcasecmp($first, 'bet_id') === 0) {
                    // 表頭：之後每列依欄名建 meta（ptsw 的 PT 原始退款回調資料由此提供）
                    $headers = array_map('strtolower', $cells);

                    continue;
                }
                $ids[] = $first;
                if ($headers !== null && count($headers) > 1) {
                    $this->betMeta[$first] = array_filter(
                        array_combine($headers, array_pad(array_slice($cells, 0, count($headers)), count($headers), '')) ?: [],
                        static fn (string $v): bool => $v !== '',
                    );
                }
            }
        }

        $explicitSource = trim((string) $this->option('source'));
        if ($explicitSource !== '') {
            $this->source = $explicitSource;
        }

        if (empty($ids)) {
            $this->error('❌ 必須提供 --bet-id / --bet-ids / --bet-id-file 至少一項');

            return [];
        }

        return array_values(array_unique($ids));
    }

    /**
     * 唯讀檢查一筆 bet_id；可退款者回傳候選資料，否則記錄跳過原因並回傳 null。
     *
     * @return array{bet_id: string, record: AbstractBetRecord, account: AbstractAccount, member: Member, vendor_info: VendorInfo, amount: string}|null
     */
    private function inspect(string $betId): ?array
    {
        $cls = $this->profile['bet_record'];
        $record = $cls::query()->where('bet_id', $betId)->first();
        if (!$record) {
            $this->collect('not_found', $betId, null, null, 'bet_id 不存在');

            return null;
        }

        $reason = $this->validate($record);
        if ($reason !== null) {
            $this->collect($reason, $betId, $record, null, $this->describe($reason, $record, $betId));

            return null;
        }

        if ($this->missingMeta($betId) !== []) {
            $this->collect('meta_missing', $betId, $record, null, $this->describe('meta_missing', $record, $betId));

            return null;
        }

        $accountCls = $this->profile['account'];
        $account = $accountCls::query()->with(['member', 'vendorInfo'])->where('username', $record->username)->first();
        $member = $account?->member;
        if (!$account instanceof AbstractAccount || !$member instanceof Member) {
            $this->collect('account_missing', $betId, $record, null, '查無遊戲帳號或對應會員');

            return null;
        }
        $vendorInfo = $account->vendorInfo;
        if (!$vendorInfo instanceof VendorInfo) {
            $this->collect('vendor_info_missing', $betId, $record, $member, '查無 vendor_info');

            return null;
        }

        $mismatch = $this->identityMismatch($betId, $record, $member);
        if ($mismatch !== null) {
            $this->collect('identity_mismatch', $betId, $record, $member, $mismatch);

            return null;
        }

        return [
            'bet_id' => $betId,
            'record' => $record,
            'account' => $account,
            'member' => $member,
            'vendor_info' => $vendorInfo,
            'amount' => Common::numAdd('0', $record->getBet(), 4),
        ];
    }

    /**
     * 清單若附 br_id／member_id，命中的注單列與會員必須完全一致；bet_id 欄是 case-insensitive collation，
     * 光靠 bet_id 命中不保證選對列，多這一道就擋掉整類「退到別的注單／別的會員」。
     */
    private function identityMismatch(string $betId, AbstractBetRecord $record, Member $member): ?string
    {
        $meta = $this->betMeta[$betId] ?? [];
        if (isset($meta['br_id']) && (int) $meta['br_id'] !== (int) $record->id) {
            return '清單 br_id=' . $meta['br_id'] . ' 與命中注單 id=' . $record->id . ' 不符，不退';
        }
        if (isset($meta['member_id']) && (int) $meta['member_id'] !== (int) $member->id) {
            return '清單 member_id=' . $meta['member_id'] . ' 與注單會員 id=' . $member->id . ' 不符，不退';
        }

        return null;
    }

    /**
     * 回傳不可退款的原因碼；null 代表可退。execute 模式在鎖內會再跑一次。
     */
    private function validate(AbstractBetRecord $record): ?string
    {
        if (!($this->profile['is_bet'])($record)) {
            return 'not_bet';
        }
        if ((int) $record->status !== $this->profile['unsettled_status']) {
            return 'not_unsettled';
        }
        if (($this->profile['has_settle'])($record)) {
            return 'has_settle';
        }
        if (TotalBetRecord::query()->whereIn('vendor_id', $this->profile['vendor_ids'])->where('bet_record_id', $record->id)->exists()) {
            return 'has_tbr';
        }
        if (Common::numCompare($record->getRollingChip(), '0', 4) > 0) {
            return 'rolling_chip_used';
        }
        if ($this->hasRefundLog($record)) {
            return 'already_refunded';
        }
        if (Common::numCompare(Common::numAdd('0', $record->getBet(), 4), '0', 4) <= 0) {
            return 'zero_amount';
        }

        return null;
    }

    private function hasRefundLog(AbstractBetRecord $record): bool
    {
        return BetRecordRecoveryLog::query()
            ->whereIn('vendor_id', $this->profile['vendor_ids'])
            ->where('bet_record_id', $record->id)
            ->where('type', BetRecordRecoveryTypeEnum::REFUND->value)
            ->exists();
    }

    /**
     * @return string[] 該 bet_id 缺少的 profile 必要 meta 欄名
     */
    private function missingMeta(string $betId): array
    {
        $meta = $this->betMeta[$betId] ?? [];

        return array_values(array_filter(
            $this->profile['required_meta'],
            static fn (string $key): bool => !isset($meta[$key]) || $meta[$key] === '',
        ));
    }

    private function describe(string $reason, AbstractBetRecord $record, string $betId): string
    {
        return match ($reason) {
            'not_bet' => '不是下注列（type=' . $record->type . '）',
            'not_unsettled' => '注單非未結算狀態（status=' . $record->status . '）',
            'has_settle' => '同回合已有派彩列，不得退款',
            'has_tbr' => '已進 total_bet_records，不屬本命令範圍',
            'rolling_chip_used' => '下注含禮碼（' . $record->getRollingChip() . '），本命令不處理禮碼錢包',
            'already_refunded' => 'bet_record_recovery_logs 已有 refund 紀錄',
            'zero_amount' => '下注金額為 0，無可退',
            'meta_missing' => '缺少必要 meta 欄位（' . implode('/', $this->missingMeta($betId)) . '），需用帶表頭的 --bet-id-file 提供',
            default => $reason,
        };
    }

    /**
     * @param  array{bet_id: string, record: AbstractBetRecord, account: AbstractAccount, member: Member, vendor_info: VendorInfo, amount: string}  $candidate
     */
    private function process(array $candidate): void
    {
        $record = $candidate['record'];
        $betId = (string) $record->getBetId();

        if (!$this->executeMode) {
            $this->collect('would_refund', $betId, $record, $candidate['member'], '預計退回 ' . $candidate['amount'] . '，status ' . $this->profile['unsettled_status'] . '→' . $this->profile['refund_status'], $candidate['amount']);

            return;
        }

        try {
            $diff = $this->withRoundLock($candidate, fn (): string => DB::transaction(fn () => $this->refundOne($candidate)));
            $this->collect('refunded', $betId, $record, $candidate['member'], '退款成功，錢包淨變動 ' . $diff, $diff);
        } catch (LockTimeoutException $e) {
            Log::warning(self::CMD_NAME . ' round locked', [
                'bet_id' => $betId,
                'bet_record_id' => $record->id,
                'member_id' => $candidate['member']->id,
            ]);
            $this->collect('failed', $betId, $record, $candidate['member'], '失敗：同局回調正在處理，named lock ' . GameRoundLock::TIMEOUT_SECONDS . ' 秒內未取得；確認 PT 已停送後重跑');
        } catch (Throwable $e) {
            Log::error(self::CMD_NAME . ' failed', [
                'bet_id' => $betId,
                'bet_record_id' => $record->id,
                'member_id' => $candidate['member']->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            $this->collect('failed', $betId, $record, $candidate['member'], '失敗：' . $e->getMessage());
        }
    }

    /**
     * profile 定義 round_lock_key 時（ptsw），退款交易包在與回調同一把同局 named lock 內：
     * 取鎖在 DB::transaction 之外、釋放晚於 commit，回調在鎖後才開交易就看得到命令的退款事件。
     *
     * @param  array{bet_id: string, record: AbstractBetRecord, account: AbstractAccount, member: Member, vendor_info: VendorInfo, amount: string}  $candidate
     * @param  Closure(): string  $refund
     *
     * @throws LockTimeoutException
     */
    private function withRoundLock(array $candidate, Closure $refund): string
    {
        $keyBuilder = $this->profile['round_lock_key'];
        if ($keyBuilder === null) {
            return $refund();
        }

        return GameRoundLock::synchronize($keyBuilder($candidate['record'], $candidate['account']), $refund);
    }

    /**
     * @param  array{bet_id: string, record: AbstractBetRecord, account: AbstractAccount, member: Member, vendor_info: VendorInfo, amount: string}  $candidate
     * @return string 錢包淨變動（正數）
     */
    private function refundOne(array $candidate): string
    {
        $cls = $this->profile['bet_record'];
        /** @var AbstractBetRecord|null $record */
        $record = $cls::query()->whereKey($candidate['record']->id)->lockForUpdate()->first();
        if (!$record) {
            throw new RuntimeException('注單已不存在');
        }

        $reason = $this->validate($record);
        if ($reason !== null) {
            throw new RuntimeException('鎖內狀態已變更：' . $reason);
        }

        $member = $candidate['member'];
        $vendorInfo = $candidate['vendor_info'];
        $account = $candidate['account'];
        $amount = Common::numAdd('0', $record->getBet(), 4);

        $walletDto = (new WalletService)->getMemberLockedWallets($member, $vendorInfo, $account);
        if (!empty($walletDto->wallet->lockBalance)) {
            throw new RuntimeException('遊戲帳號狀態為 0，錢包被鎖定不可異動');
        }

        $before = (string) $walletDto->wallet->balance;
        $after = Common::numAdd($before, $amount, 4);
        $walletDto->wallet->update(['balance' => $after]);

        $txService = new TransactionLogCreateService($member, $vendorInfo, $walletDto->isMultipleWallet, $account);
        $txService->setType(
            $walletDto->isMultipleWallet
                ? WalletTransactionEnum::SEAMLESS_WALLET_CANCEL->value
                : TransactionEnum::SEAMLESS_WALLET_CANCEL->value
        );
        $tx = $txService->create($amount, $before, $after);

        $beforeSnapshot = $this->snapshot($record);
        $refundedAt = now()->toDateTimeString();

        $record->status = $this->profile['refund_status'];
        $record->{$record->getPayoutTimeColumn() ?? 'payout_time'} = $refundedAt;
        $infosColumn = $this->profile['infos_column'];
        if ($infosColumn !== null) {
            $record->{$infosColumn} = $this->appendRefundMarker((string) ($record->{$infosColumn} ?? ''), $tx->id, $refundedAt);
        }
        $record->save();

        $extraMeta = [];
        if ($this->profile['after_refund'] !== null) {
            // meta 一律用清單原始 bet_id 當鍵：bet_id 欄 case-insensitive，DB 回讀值可能與清單大小寫不同
            $extraMeta = ($this->profile['after_refund'])($record, $account, $this->betMeta[$candidate['bet_id']] ?? [], $amount);
        }

        $afterSnapshot = $this->snapshot($record) + [
            '_meta' => [
                'transaction_log_id' => $tx->id,
                'is_multiple_wallet' => $walletDto->isMultipleWallet,
                'wallet_before' => $before,
                'wallet_after' => $after,
            ] + $extraMeta,
        ];

        BetRecordRecoveryLog::create([
            'vendor_id' => (int) $vendorInfo->vendor_id,
            'bet_record_id' => $record->id,
            'bet_id' => (string) $record->getBetId(),
            'member_id' => $member->id,
            'type' => BetRecordRecoveryTypeEnum::REFUND->value,
            'performed_at' => now(),
            'performed_by' => $this->performedBy,
            'reason' => $this->reason,
            'source' => $this->source,
            'before' => $beforeSnapshot,
            'after' => $afterSnapshot,
            'balance_diff' => $amount,
            'parent_log_id' => null,
        ]);

        Log::info(self::CMD_NAME . ' refunded', [
            'vendor_id' => (int) $vendorInfo->vendor_id,
            'bet_record_id' => $record->id,
            'bet_id' => (string) $record->getBetId(),
            'member_id' => $member->id,
            'balance_diff' => $amount,
            'transaction_log_id' => $tx->id,
            'wallet_before' => $before,
            'wallet_after' => $after,
        ]);

        return $amount;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AbstractBetRecord $record): array
    {
        $payoutColumn = $record->getPayoutTimeColumn() ?? 'payout_time';

        return [
            'status' => (int) $record->status,
            'bet' => $record->getBet(),
            'valid_bet' => $record->getValidBet(),
            'win_lose' => $record->getWinLose(),
            'rolling_chip' => $record->getRollingChip(),
            $payoutColumn => (string) $record->{$payoutColumn},
        ];
    }

    private function appendRefundMarker(string $infos, int $transactionLogId, string $refundedAt): string
    {
        $current = json_decode($infos !== '' ? $infos : '{}', true);
        if (!is_array($current)) {
            $current = ['_original_infos' => $infos];
        }
        $current['_refund'] = [
            'at' => $refundedAt,
            'by' => $this->performedBy,
            'reason' => $this->reason,
            'source' => $this->source,
            'transaction_log_id' => $transactionLogId,
        ];

        return json_encode($current, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  string[]  $betIds
     */
    private function printHeader(array $betIds): void
    {
        $this->info('========================================');
        $this->info('↩️  BetRecordRecovery Refund Unsettled');
        $this->info('========================================');
        $this->info('模式: ' . ($this->executeMode ? '⚠️  EXECUTE（真寫入）' : '🧪 DRY-RUN'));
        $this->info('vendor: ' . (string) $this->option('vendor') . '（vendor_id ' . implode('/', $this->profile['vendor_ids']) . '）');
        $this->info('待處理 bet_id 數量: ' . count($betIds));
        $this->info('來源: ' . $this->source);
        if ($this->executeMode) {
            $this->info('執行者: ' . $this->performedBy);
            $this->info('原因: ' . $this->reason);
        }
        $this->newLine();
    }

    /**
     * @param  array<int, array{bet_id: string, record: AbstractBetRecord, account: AbstractAccount, member: Member, vendor_info: VendorInfo, amount: string}>  $candidates
     */
    private function buildPlayerSummary(array $candidates): void
    {
        $byMember = [];
        foreach ($candidates as $c) {
            $mid = (int) $c['member']->id;
            if (!isset($byMember[$mid])) {
                $walletDto = (new WalletService)->getMemberWallets($c['member'], $c['vendor_info']);
                $byMember[$mid] = [
                    'member_id' => $mid,
                    'vendor_account' => (string) $c['account']->username,
                    'refunds' => 0,
                    'total_refund' => '0',
                    'start_balance' => (string) ($walletDto->wallet->balance ?? '0'),
                    'end_balance' => '0',
                ];
            }
            $byMember[$mid]['refunds']++;
            $byMember[$mid]['total_refund'] = Common::numAdd($byMember[$mid]['total_refund'], $c['amount'], 4);
        }
        foreach ($byMember as &$row) {
            $row['end_balance'] = Common::numAdd($row['start_balance'], $row['total_refund'], 4);
        }
        unset($row);

        $this->playerSummary = array_values($byMember);
    }

    private function printPlayerSummaryTable(): void
    {
        if (empty($this->playerSummary)) {
            return;
        }

        $rows = [];
        $total = '0';
        foreach ($this->playerSummary as $row) {
            $rows[] = [$row['member_id'], $row['vendor_account'], $row['refunds'], $row['start_balance'], $row['total_refund'], $row['end_balance']];
            $total = Common::numAdd($total, $row['total_refund'], 4);
        }
        $rows[] = ['---', '---', '---', '---', '---', '---'];
        $rows[] = ['總計（' . count($this->playerSummary) . ' 玩家）', '', array_sum(array_column($this->playerSummary, 'refunds')), '', $total, ''];

        $this->info('🧾 玩家退款預演（dry-run 為預估，正式執行時餘額可能因玩家活動有少量誤差）');
        $this->table(['member_id', '遊戲帳號', '筆數', 'start', '退款', 'end'], $rows);
        $this->newLine();
    }

    private function collect(string $status, string $betId, ?AbstractBetRecord $record, ?Member $member, string $message, string $balanceDiff = '0'): void
    {
        $this->report[] = [
            'status' => $status,
            'bet_id' => $betId,
            'bet_record_id' => $record?->id,
            'member_id' => $member?->id,
            'balance_diff' => $balanceDiff,
            'message' => $message,
        ];
    }

    private function printSummary(): void
    {
        $byStatus = [];
        foreach ($this->report as $r) {
            $byStatus[$r['status']] ??= ['count' => 0, 'sum' => '0'];
            $byStatus[$r['status']]['count']++;
            $byStatus[$r['status']]['sum'] = Common::numAdd($byStatus[$r['status']]['sum'], $r['balance_diff'], 4);
        }

        $this->newLine();
        $this->info('========================================');
        $this->info($this->executeMode ? '✅ Refund 結果' : '🧪 Refund Dry-run 結果');
        $this->info('========================================');
        $this->table(
            ['狀態', '筆數', '錢包變動合計'],
            array_map(static fn (string $s, array $v): array => [$s, $v['count'], $v['sum']], array_keys($byStatus), $byStatus)
        );
        $this->table(
            ['status', 'bet_id', 'bet_record_id', 'member_id', 'balance_diff', 'message'],
            array_map(static fn (array $r): array => [$r['status'], $r['bet_id'], $r['bet_record_id'] ?? '', $r['member_id'] ?? '', $r['balance_diff'], $r['message']], $this->report)
        );
    }

    /**
     * @return array<string, string>
     */
    private function writeReport(): array
    {
        $mode = $this->executeMode ? 'execute' : 'dryrun';
        $ts = now()->format('YmdHis');

        $detailPath = storage_path('logs/bet_record_refund_' . $mode . '_' . $ts . '.csv');
        $this->writeCsv($detailPath, ['status', 'bet_id', 'bet_record_id', 'member_id', 'balance_diff', 'message'], $this->report);

        $summaryPath = storage_path('logs/bet_record_refund_player_summary_' . $mode . '_' . $ts . '.csv');
        $this->writeCsv($summaryPath, ['member_id', 'vendor_account', 'refunds', 'start_balance', 'total_refund', 'end_balance'], $this->playerSummary);

        return ['逐筆明細' => $detailPath, '玩家彙總' => $summaryPath];
    }

    /**
     * @param  string[]  $headers
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            return;
        }
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $headers, ',', '"', '\\');
        foreach ($rows as $r) {
            $line = [];
            foreach ($headers as $h) {
                $line[] = $r[$h] ?? '';
            }
            fputcsv($fp, $line, ',', '"', '\\');
        }
        fclose($fp);
    }
}
