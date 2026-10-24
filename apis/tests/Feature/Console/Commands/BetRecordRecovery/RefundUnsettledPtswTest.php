<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\BetRecordRecovery;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\BetRecordRecoveryLog;
use App\Models\Member;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\PtswAccount;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\Models\PtswDealRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use App\Vendors\PlayTechSW\GameRoundLock;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/BetRecordRecovery/RefundUnsettledPtswTest.php --compact
 *
 * 情境來源：2026-09-05 TPower DDoS，PTSW 下注已扣款但 PT 端已作廢並反覆送 REFUND＋gameRoundClose，
 * 我方每次撞鎖回滾、PT 停止重送，注單停在 status=1（target/op_tickets#3083）。
 * 終態比照 FUMA：status 1→2、錢包退回、不建 TBR；另補一筆 gameRoundResult 退款 deal record 維持事件流完整。
 */
class RefundUnsettledPtswTest extends TestCase
{
    private const string CMD = 'bet-record-recovery:refund-unsettled';

    private Agent $agent;

    private Member $member;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    private PtswAccount $account;

    /** @var string[] 測試中建立的暫存 meta 檔，tearDown 清掉 */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::PTSW->value);
        $this->vendorInfo = VendorInfo::query()->where('vendor_id', $this->vendor->id)->firstOrFail();
        $this->vendorInfo->account_prefix = 'TPOWMY';
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = ['API_URL' => '', 'COUNTRY_CODE' => 'MY'];
        $this->vendorInfo->save();

        $this->account = PtswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => 'TPOWMY_00000001',
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 0,
            'token' => 'test_token_ptsw',
        ]);

        $this->member->update(['balance' => '10.0000']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /**
     * 建一筆「已扣款、PT 端已作廢、我方永遠結不了」的注單：BR status=1 + bet deal record 已 applied。
     */
    private function seedStuckBet(string $betId, string $bet, string $betTc): PtswBetRecord
    {
        $record = PtswBetRecord::create([
            'member_id' => $this->member->id,
            'username' => $this->account->username,
            'token' => $this->account->token,
            'bet_id' => $betId,
            'transaction_code' => $betTc,
            'game_code' => 'gpas_dsparks_pop',
            'bet' => $bet,
            'valid_bet' => $bet,
            'win_lose' => Common::numMul($bet, '-1', 4),
            'rolling_chips' => '0.0000',
            'jackpot' => '0.0000',
            'bet_time' => '2026-09-05 15:44:32',
            'payout_time' => null,
            'status' => BetRecordStatusEnum::IN_PROGRESS->value,
        ]);

        PtswDealRecord::create([
            'request_id' => (string) Str::uuid(),
            'username' => $this->account->username,
            'token' => $this->account->token,
            'bet_id' => $betId,
            'transaction_code' => $betTc,
            'type' => 'bet',
            'bet_amount' => $bet,
            'win_amount' => '0',
            'jackpot' => '0',
            'external_transaction_code' => (string) Str::uuid(),
            'external_transaction_date' => '2026-09-05 15:44:32.000',
            'applied_at' => '2026-09-05 15:46:22',
            'raw' => ['gameRoundCode' => $betId, 'transactionCode' => $betTc, 'amount' => $bet],
        ]);

        return $record;
    }

    /**
     * 產生命令要吃的 meta CSV（PT 原始退款回調的 tc／requestId／時間）。
     * 列內另帶 br_id／member_id 時會多出對應欄位，模擬事故清單附的身分核對欄。
     *
     * @param  array<int, array<string, string>>  $rows  每列至少含 bet_id/bet_tc/refund_tc/refund_request_id/amount
     */
    private function writeMetaCsv(array $rows, bool $withBom = false): string
    {
        $extra = [];
        foreach ($rows as $r) {
            foreach (['br_id', 'member_id'] as $k) {
                if (isset($r[$k]) && !in_array($k, $extra, true)) {
                    $extra[] = $k;
                }
            }
        }
        $headers = array_merge(
            ['bet_id', 'bet_tc', 'refund_tc', 'refund_request_id', 'refund_transaction_date', 'round_close_date', 'refund_amount'],
            $extra,
        );

        $path = sys_get_temp_dir() . '/ptsw_refund_meta_' . Str::random(8) . '.csv';
        $lines = [implode(',', $headers)];
        foreach ($rows as $r) {
            $cells = [
                $r['bet_id'], $r['bet_tc'], $r['refund_tc'], $r['refund_request_id'],
                '2026-09-05 15:44:37.000', '2026-09-05 15:44:37.000', $r['amount'],
            ];
            foreach ($extra as $k) {
                $cells[] = $r[$k] ?? '';
            }
            $lines[] = implode(',', $cells);
        }
        file_put_contents($path, ($withBom ? "\xEF\xBB\xBF" : '') . implode("\n", $lines) . "\n");
        $this->tempFiles[] = $path;

        return $path;
    }

    private function memberBalance(): string
    {
        return (string) $this->member->refresh()->balance;
    }

    #[Group('rules/single-wallet-unsettled-refund#R-2')]
    public function test_dry_run_reports_refundable_bet_without_writing(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_1#aaaa', '0.6000', 'tc-bet-1');
        $csv = $this->writeMetaCsv([[
            'bet_id' => $record->bet_id, 'bet_tc' => 'tc-bet-1', 'refund_tc' => 'tc-refund-1',
            'refund_request_id' => 'req-refund-1', 'amount' => '0.6',
        ]]);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'ptsw', '--bet-id-file' => $csv]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('would_refund', $output);
        $this->assertSame('10.0000', $this->memberBalance());
        $this->assertSame(BetRecordStatusEnum::IN_PROGRESS->value, (int) $record->refresh()->status);
        $this->assertSame(0, BetRecordRecoveryLog::where('bet_record_id', $record->id)->count());
        $this->assertSame(0, PtswDealRecord::where('bet_id', $record->bet_id)->where('type', 'gameRoundResult')->count());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-11')]
    #[Group('rules/single-wallet-unsettled-refund#R-12')]
    #[Group('rules/single-wallet-unsettled-refund#R-14')]
    public function test_execute_refunds_wallet_marks_record_writes_audit_and_refund_deal_record(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_2#bbbb', '2.5000', 'tc-bet-2');
        $csv = $this->writeMetaCsv([[
            'bet_id' => $record->bet_id, 'bet_tc' => 'tc-bet-2', 'refund_tc' => 'tc-refund-2',
            'refund_request_id' => 'req-refund-2', 'amount' => '2.5',
        ]]);

        $exitCode = Artisan::call(self::CMD, [
            '--vendor' => 'ptsw',
            '--bet-id-file' => $csv,
            '--execute' => true,
            '--reason' => '2026-09-05 TPower DDoS，PT 端已作廢、我方結算撞鎖回滾',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame('12.5000', $this->memberBalance());

        $record->refresh();
        $this->assertSame(BetRecordStatusEnum::REFUND->value, (int) $record->status);
        $this->assertSame('2.5000', (string) $record->bet, '原下注金額保留供稽核');
        $this->assertNotNull($record->payout_time, 'payout_time 應記退款時間');

        $tx = TransactionLog::where('member_id', $this->member->id)->first();
        $this->assertNotNull($tx);
        $this->assertSame(TransactionEnum::SEAMLESS_WALLET_CANCEL->value, (int) $tx->type);
        $this->assertSame(VendorIdEnum::PTSW->value, (int) $tx->vendor_id);
        $this->assertSame('2.5000', (string) $tx->point);
        $this->assertSame('10.0000', (string) $tx->before_balance);
        $this->assertSame('12.5000', (string) $tx->balance);

        $log = BetRecordRecoveryLog::where('bet_record_id', $record->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(BetRecordRecoveryTypeEnum::REFUND, $log->type);
        $this->assertSame(VendorIdEnum::PTSW->value, (int) $log->vendor_id);
        $this->assertSame('2.5000', (string) $log->balance_diff);
        $this->assertSame(BetRecordStatusEnum::IN_PROGRESS->value, (int) $log->before['status']);
        $this->assertSame(BetRecordStatusEnum::REFUND->value, (int) $log->after['status']);
        $this->assertSame($tx->id, (int) $log->after['_meta']['transaction_log_id']);

        // 事件流完整：補一筆 PT 原始退款回調對應的 gameRoundResult deal record（bet_amount 為負、未 applied）
        $deal = PtswDealRecord::where('bet_id', $record->bet_id)->where('type', 'gameRoundResult')->first();
        $this->assertNotNull($deal, '退款後必須補 gameRoundResult 退款事件');
        $this->assertSame('tc-refund-2', (string) $deal->transaction_code);
        $this->assertSame('req-refund-2', (string) $deal->request_id);
        $this->assertSame('-2.5000', (string) $deal->bet_amount);
        $this->assertSame('0.0000', (string) $deal->win_amount);
        $this->assertNull($deal->applied_at);
        $this->assertSame('REFUND', $deal->raw['pay']['type'] ?? null);
        $this->assertSame('tc-bet-2', $deal->raw['pay']['relatedTransactionCode'] ?? null);
        $this->assertSame((int) $deal->id, (int) ($log->after['_meta']['refund_deal_record_id'] ?? 0));
        $this->assertSame('meta.refund_amount', $deal->raw['_recovery']['pay_amount_source'] ?? null, 'raw 要標明 pay.amount 取值來源，避免日後誤當三方幣別重算');

        $this->assertSame(0, TotalBetRecord::where('vendor_id', VendorIdEnum::PTSW->value)->count(), '退款不建 TBR');
    }

    #[Group('rules/single-wallet-unsettled-refund#R-14')]
    public function test_refund_deal_record_keeps_bet_time_session_token_after_player_logged_out(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_6#ffff', '1.2000', 'tc-bet-6');
        $record->update(['token' => 'sess-token-at-bet-time']);
        // 玩家早已登出：現行帳號 token 已清空，只有注單保有下注當時的 session token
        $this->account->update(['token' => null]);
        $csv = $this->writeMetaCsv([[
            'bet_id' => $record->bet_id, 'bet_tc' => 'tc-bet-6', 'refund_tc' => 'tc-refund-6',
            'refund_request_id' => 'req-refund-6', 'amount' => '1.2',
        ]]);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'ptsw', '--bet-id-file' => $csv, '--execute' => true, '--reason' => 't']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame('11.2000', $this->memberBalance());
        $deal = PtswDealRecord::where('bet_id', $record->bet_id)->where('type', 'gameRoundResult')->first();
        $this->assertNotNull($deal);
        $this->assertSame('sess-token-at-bet-time', (string) $deal->token, '補的事件要沿用注單當時的 token，不可寫成 NULL');
    }

    #[Group('rules/single-wallet-unsettled-refund#R-14')]
    public function test_meta_lookup_uses_csv_bet_id_even_when_database_value_differs_in_case(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_7#ABCD', '0.5000', 'tc-bet-7');
        // bet_id 欄是 utf8mb4_unicode_ci：小寫查得到同一列，meta 也必須跟著對上，不能只因大小寫變成 failed
        $csv = $this->writeMetaCsv([[
            'bet_id' => 'gpas_stuck_7#abcd', 'bet_tc' => 'tc-bet-7', 'refund_tc' => 'tc-refund-7',
            'refund_request_id' => 'req-refund-7', 'amount' => '0.5',
        ]]);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'ptsw', '--bet-id-file' => $csv, '--execute' => true, '--reason' => 't']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('refunded', $output);
        $this->assertStringNotContainsString('failed', $output);
        $this->assertSame('10.5000', $this->memberBalance());
        $this->assertSame(1, PtswDealRecord::where('bet_id', $record->bet_id)->where('type', 'gameRoundResult')->count());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-5')]
    public function test_reads_meta_csv_whose_header_starts_with_utf8_bom(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_8#bom1', '0.7000', 'tc-bet-8');
        $csv = $this->writeMetaCsv([[
            'bet_id' => $record->bet_id, 'bet_tc' => 'tc-bet-8', 'refund_tc' => 'tc-refund-8',
            'refund_request_id' => 'req-refund-8', 'amount' => '0.7',
        ]], withBom: true);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'ptsw', '--bet-id-file' => $csv]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('would_refund', $output);
        $this->assertStringNotContainsString('meta_missing', $output, 'BOM 不得讓表頭判定失敗');
        $this->assertStringNotContainsString('not_found', $output, 'BOM 不得黏進第一個 bet_id');
    }

    #[Group('rules/single-wallet-unsettled-refund#R-6')]
    public function test_skips_when_csv_identity_columns_disagree_with_database(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_9#iden', '0.9000', 'tc-bet-9');
        $csv = $this->writeMetaCsv([[
            'bet_id' => $record->bet_id, 'bet_tc' => 'tc-bet-9', 'refund_tc' => 'tc-refund-9',
            'refund_request_id' => 'req-refund-9', 'amount' => '0.9',
            'br_id' => (string) ($record->id + 1000), 'member_id' => (string) $this->member->id,
        ]]);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'ptsw', '--bet-id-file' => $csv, '--execute' => true, '--reason' => 't']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('identity_mismatch', $output, '清單附的 br_id 與命中列不符就不能退');
        $this->assertSame('10.0000', $this->memberBalance());
        $this->assertSame(BetRecordStatusEnum::IN_PROGRESS->value, (int) $record->refresh()->status);
        $this->assertSame(0, PtswDealRecord::where('bet_id', $record->bet_id)->where('type', 'gameRoundResult')->count());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-15')]
    public function test_request_id_collision_rolls_back_wallet_record_and_audit_together(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_10#dup', '1.5000', 'tc-bet-10');
        // 另一局已用掉同一個 request_id：補 deal record 會撞 unique，整筆必須回滾、錢包不得單邊落地
        PtswDealRecord::create([
            'request_id' => 'req-refund-10',
            'username' => $this->account->username,
            'token' => $this->account->token,
            'bet_id' => 'gpas_other#zzzz',
            'transaction_code' => 'tc-other-10',
            'type' => 'gameRoundResult',
            'bet_amount' => '0',
            'win_amount' => '0',
            'jackpot' => '0',
            'external_transaction_code' => (string) Str::uuid(),
            'external_transaction_date' => '2026-09-05 15:50:00.000',
            'applied_at' => null,
            'raw' => [],
        ]);
        $csv = $this->writeMetaCsv([[
            'bet_id' => $record->bet_id, 'bet_tc' => 'tc-bet-10', 'refund_tc' => 'tc-refund-10',
            'refund_request_id' => 'req-refund-10', 'amount' => '1.5',
        ]]);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'ptsw', '--bet-id-file' => $csv, '--execute' => true, '--reason' => 't']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('failed', $output);
        $this->assertSame('10.0000', $this->memberBalance(), '撞 unique 後錢包必須回滾');
        $this->assertSame(BetRecordStatusEnum::IN_PROGRESS->value, (int) $record->refresh()->status);
        $this->assertSame(0, BetRecordRecoveryLog::where('bet_record_id', $record->id)->count());
        $this->assertSame(0, TransactionLog::where('member_id', $this->member->id)->count());
        $this->assertSame(0, PtswDealRecord::where('bet_id', $record->bet_id)->where('type', 'gameRoundResult')->count());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-7')]
    public function test_skips_bet_that_already_has_settlement_event(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_3#cccc', '1.0000', 'tc-bet-3');
        PtswDealRecord::create([
            'request_id' => (string) Str::uuid(),
            'username' => $this->account->username,
            'token' => $this->account->token,
            'bet_id' => $record->bet_id,
            'transaction_code' => 'tc-settle-3',
            'type' => 'gameRoundResult',
            'bet_amount' => '0',
            'win_amount' => '3.0000',
            'jackpot' => '0',
            'external_transaction_code' => (string) Str::uuid(),
            'external_transaction_date' => '2026-09-05 15:50:00.000',
            'applied_at' => null,
            'raw' => [],
        ]);
        $csv = $this->writeMetaCsv([[
            'bet_id' => $record->bet_id, 'bet_tc' => 'tc-bet-3', 'refund_tc' => 'tc-refund-3',
            'refund_request_id' => 'req-refund-3', 'amount' => '1',
        ]]);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'ptsw', '--bet-id-file' => $csv, '--execute' => true, '--reason' => 't']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('has_settle', $output);
        $this->assertSame('10.0000', $this->memberBalance());
        $this->assertSame(BetRecordStatusEnum::IN_PROGRESS->value, (int) $record->refresh()->status);
    }

    #[Group('rules/single-wallet-unsettled-refund#R-9')]
    public function test_skips_bet_without_refund_meta(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_4#dddd', '1.0000', 'tc-bet-4');

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'ptsw', '--bet-id' => $record->bet_id, '--execute' => true, '--reason' => 't']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('meta_missing', $output);
        $this->assertSame('10.0000', $this->memberBalance());
        $this->assertSame(BetRecordStatusEnum::IN_PROGRESS->value, (int) $record->refresh()->status);
        $this->assertSame(0, PtswDealRecord::where('bet_id', $record->bet_id)->where('type', 'gameRoundResult')->count());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-10')]
    public function test_second_execution_never_refunds_twice(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_5#eeee', '0.8000', 'tc-bet-5');
        $csv = $this->writeMetaCsv([[
            'bet_id' => $record->bet_id, 'bet_tc' => 'tc-bet-5', 'refund_tc' => 'tc-refund-5',
            'refund_request_id' => 'req-refund-5', 'amount' => '0.8',
        ]]);
        $args = ['--vendor' => 'ptsw', '--bet-id-file' => $csv, '--execute' => true, '--reason' => 't'];

        Artisan::call(self::CMD, $args);
        $this->assertSame('10.8000', $this->memberBalance());

        Artisan::call(self::CMD, $args);
        $output = Artisan::output();

        $this->assertStringContainsString('not_unsettled', $output);
        $this->assertSame('10.8000', $this->memberBalance(), '第二次執行不得重複退款');
        $this->assertSame(1, BetRecordRecoveryLog::where('bet_record_id', $record->id)->count());
        $this->assertSame(1, TransactionLog::where('member_id', $this->member->id)->count());
        $this->assertSame(1, PtswDealRecord::where('bet_id', $record->bet_id)->where('type', 'gameRoundResult')->count());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-16')]
    #[Group('rules/vendor-ptsw#R-26')]
    public function test_does_not_refund_while_callback_holds_the_game_round_lock(): void
    {
        $record = $this->seedStuckBet('gpas_stuck_11#lock', '1.1000', 'tc-bet-11');
        $csv = $this->writeMetaCsv([[
            'bet_id' => $record->bet_id, 'bet_tc' => 'tc-bet-11', 'refund_tc' => 'tc-refund-11',
            'refund_request_id' => 'req-refund-11', 'amount' => '1.1',
        ]]);

        // 模擬 PT 回調正在處理同局：另一條 DB session 持有 PlayTechController 用的同一把 named lock。
        // 命令若不取同一把鎖，交易內的 read view 看不到回調剛 commit 的退款事件，會與回調各退一次（target/op_tickets#3089 審查發現）。
        $lockKey = GameRoundLock::key($record->bet_id, $this->account->username);
        $holder = $this->openLockHolderConnection();
        $this->assertSame(1, (int) $holder->selectOne('SELECT GET_LOCK(?, 0) AS l', [$lockKey], false)->l, '前置：第二條 session 必須先拿到鎖');

        try {
            $exitCode = Artisan::call(self::CMD, ['--vendor' => 'ptsw', '--bet-id-file' => $csv, '--execute' => true, '--reason' => 't']);
            $output = Artisan::output();
        } finally {
            $holder->selectOne('SELECT RELEASE_LOCK(?) AS r', [$lockKey], false);
            DB::purge('lock_holder');
        }

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('failed', $output);
        $this->assertStringContainsString('named lock', $output, '失敗原因要講明是同局鎖未取得，讓 ops 知道可重跑');
        $this->assertSame('10.0000', $this->memberBalance(), '同局回調持鎖期間命令不得退款');
        $this->assertSame(BetRecordStatusEnum::IN_PROGRESS->value, (int) $record->refresh()->status);
        $this->assertSame(0, BetRecordRecoveryLog::where('bet_record_id', $record->id)->count());
        $this->assertSame(0, TransactionLog::where('member_id', $this->member->id)->count());
        $this->assertSame(0, PtswDealRecord::where('bet_id', $record->bet_id)->where('type', 'gameRoundResult')->count());
    }

    /**
     * 開第二條 DB session（複製預設連線設定）：MySQL named lock 以 session 為單位，同一條連線再 GET_LOCK 會直接成功，
     * 只有另一條 session 持鎖才能模擬回調與命令的競爭。
     */
    private function openLockHolderConnection(): Connection
    {
        $default = (string) config('database.default');
        config(['database.connections.lock_holder' => config("database.connections.{$default}")]);

        return DB::connection('lock_holder');
    }
}
