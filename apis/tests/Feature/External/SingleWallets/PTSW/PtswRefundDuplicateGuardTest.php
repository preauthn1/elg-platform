<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Models\TotalBetRecord;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\Models\PtswDealRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PTSW settlement 對「同一筆下注已退過款」的 REFUND 不得再退一次。
 *
 * 2026-09-05 TPower 事故補退款後發現的窗口（target/op_tickets#3089）：REFUND 分支只驗原下注扣過款，
 * 沒驗該下注是否已退過；PT 若對同局用新 transactionCode 重送 REFUND，deal record 冪等擋不到，
 * changeBalance 以注單前後 bet 差算入帳，玩家會多拿一次下注金額。
 *
 * 閘門以「同局＋同帳號＋同 pay.relatedTransactionCode 已有 REFUND 事件」判定，而非整局：
 * PTSW live 允許同局多筆下注，PT 取消整局時會對每筆下注各送一次 REFUND，整局閘會誤擋第二筆合法退款。
 * 唯一例外是 refund-unsettled 命令補的退款事件（raw 帶 _recovery）：命令以整局 BR.bet 退款、只補一筆事件，
 * 其 relatedTransactionCode 只指到一筆下注，故該事件視為整局已退。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswRefundDuplicateGuardTest.php --compact
 */
class PtswRefundDuplicateGuardTest extends PtswTestCase
{
    private const string RECOVERY_CMD = 'bet-record-recovery:refund-unsettled';

    /** @var string[] 測試中建立的暫存 meta 檔，tearDown 清掉 */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 對齊事故現場：TPower 的 PTSW vendor_info 未開禮碼（rolling_chip_status=0），無禮碼錢包
        $this->vendorInfo->update(['rolling_chip_status' => 0]);
        $this->member->update(['balance' => '40.0000']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    #[TestDox('settlement：同一筆下注已退過款，PT 用新 transactionCode 重送 REFUND 必須拒絕、錢包與注單不動')]
    #[Group('rules/vendor-ptsw#R-23')]
    public function test_second_refund_with_new_transaction_code_is_rejected(): void
    {
        $gameRoundCode = 'live_dup_refund_1';

        $this->bet(requestId: 'req-dup-bet-1', gameRoundCode: $gameRoundCode, transactionCode: 'tc-dup-bet-1', amount: '10.0', transactionDate: '2026-09-05T16:00:00Z')
            ->assertStatus(200)->assertJsonMissing(['error']);
        $this->assertSame('30.0000', $this->memberBalance());

        $this->refund(
            requestId: 'req-dup-refund-1a',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-1a',
            relatedTransactionCode: 'tc-dup-bet-1',
            amount: '10.0',
            transactionDate: '2026-09-05T16:00:30Z',
        )->assertStatus(200)->assertJsonMissing(['error']);
        $this->assertSame('40.0000', $this->memberBalance(), '第一次退款回到投注前');

        $betRecord = PtswBetRecord::query()->where('bet_id', $gameRoundCode)->firstOrFail();
        $this->assertSame('0.0000', (string) $betRecord->bet);
        $this->assertSame(BetRecordStatusEnum::REFUND->value, $betRecord->status);
        $firstPayoutTime = $betRecord->payout_time;

        // 同局、同 relatedTransactionCode，但 requestId 與 pay.transactionCode 都是新的：deal record 冪等擋不到
        $this->refund(
            requestId: 'req-dup-refund-1b',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-1b',
            relatedTransactionCode: 'tc-dup-bet-1',
            amount: '10.0',
            transactionDate: '2026-09-05T16:05:00Z',
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        $this->assertSame('40.0000', $this->memberBalance(), '同一筆下注不得退第二次');

        $betRecord->refresh();
        $this->assertSame('0.0000', (string) $betRecord->bet, 'BR.bet 不得被沖成負值');
        $this->assertSame('0.0000', (string) $betRecord->win_lose);
        $this->assertSame(BetRecordStatusEnum::REFUND->value, $betRecord->status);
        $this->assertEquals($firstPayoutTime, $betRecord->payout_time, '被拒的重送不得改寫 payout_time');
        $this->assertSame(0, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());
    }

    #[TestDox('settlement：relatedTransactionCode 只差大小寫的重送同樣視為同一筆下注、拒絕')]
    #[Group('rules/vendor-ptsw#R-23')]
    public function test_second_refund_with_case_variant_related_code_is_rejected(): void
    {
        $gameRoundCode = 'live_dup_refund_7';

        $this->bet(requestId: 'req-dup-bet-7', gameRoundCode: $gameRoundCode, transactionCode: 'tc-dup-bet-7', amount: '10.0', transactionDate: '2026-09-05T16:00:00Z')
            ->assertStatus(200)->assertJsonMissing(['error']);

        $this->refund(
            requestId: 'req-dup-refund-7a',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-7a',
            relatedTransactionCode: 'tc-dup-bet-7',
            amount: '10.0',
            transactionDate: '2026-09-05T16:00:30Z',
        )->assertStatus(200)->assertJsonMissing(['error']);
        $this->assertSame('40.0000', $this->memberBalance());

        // transaction_code 欄是 utf8mb4_unicode_ci：大寫的 relatedTransactionCode 在 hasAppliedBetForRefund 會比中同一筆下注，閘門也必須視為同一筆
        $this->refund(
            requestId: 'req-dup-refund-7b',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-7b',
            relatedTransactionCode: 'TC-DUP-BET-7',
            amount: '10.0',
            transactionDate: '2026-09-05T16:05:00Z',
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        $this->assertSame('40.0000', $this->memberBalance(), '大小寫變體不得繞過閘門');
    }

    #[TestDox('settlement：已成功退款的 pay.transactionCode 重送（同或不同 requestId）仍走既有冪等路徑回 200、錢包不動')]
    #[Group('rules/vendor-ptsw#R-19')]
    public function test_resending_applied_refund_transaction_code_stays_idempotent(): void
    {
        $gameRoundCode = 'live_dup_refund_2';

        $this->bet(requestId: 'req-dup-bet-2', gameRoundCode: $gameRoundCode, transactionCode: 'tc-dup-bet-2', amount: '10.0', transactionDate: '2026-09-05T16:10:00Z')
            ->assertStatus(200)->assertJsonMissing(['error']);

        $this->refund(
            requestId: 'req-dup-refund-2',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-2',
            relatedTransactionCode: 'tc-dup-bet-2',
            amount: '10.0',
            transactionDate: '2026-09-05T16:10:30Z',
        )->assertStatus(200)->assertJsonMissing(['error']);
        $this->assertSame('40.0000', $this->memberBalance());

        // 同 requestId 重放：requestId + bet_id 冪等早退
        $this->refund(
            requestId: 'req-dup-refund-2',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-2',
            relatedTransactionCode: 'tc-dup-bet-2',
            amount: '10.0',
            transactionDate: '2026-09-05T16:10:30Z',
        )->assertStatus(200)->assertJsonMissing(['error']);

        // 不同 requestId、同 pay.transactionCode 重放：bet_id + tc 冪等早退
        $this->refund(
            requestId: 'req-dup-refund-2b',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-2',
            relatedTransactionCode: 'tc-dup-bet-2',
            amount: '10.0',
            transactionDate: '2026-09-05T16:10:30Z',
        )->assertStatus(200)->assertJsonMissing(['error']);

        $this->assertSame('40.0000', $this->memberBalance(), '重放不得讓錢包產生任何變動');
        $this->assertSame(
            1,
            PtswDealRecord::query()->where('bet_id', $gameRoundCode)->where('type', 'gameRoundResult')->count(),
            '冪等重放不建新的 gameRoundResult 事件',
        );
    }

    #[TestDox('settlement：帶 gameRoundClose 的第二次 REFUND（新 tc）同樣被拒，注單留 REFUND 不改成 SETTLE、不建 TBR')]
    #[Group('rules/vendor-ptsw#R-23')]
    public function test_second_refund_with_round_close_is_rejected_and_keeps_refund_status(): void
    {
        $gameRoundCode = 'live_dup_refund_3';

        $this->bet(requestId: 'req-dup-bet-3', gameRoundCode: $gameRoundCode, transactionCode: 'tc-dup-bet-3', amount: '10.0', transactionDate: '2026-09-05T16:20:00Z')
            ->assertStatus(200)->assertJsonMissing(['error']);

        $this->refund(
            requestId: 'req-dup-refund-3a',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-3a',
            relatedTransactionCode: 'tc-dup-bet-3',
            amount: '10.0',
            transactionDate: '2026-09-05T16:20:30Z',
        )->assertStatus(200)->assertJsonMissing(['error']);
        $this->assertSame('40.0000', $this->memberBalance());

        $this->refund(
            requestId: 'req-dup-refund-3b',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-3b',
            relatedTransactionCode: 'tc-dup-bet-3',
            amount: '10.0',
            transactionDate: '2026-09-05T16:25:00Z',
            roundClose: ['date' => '2026-09-05 16:25:00.000', 'rngGeneratorId' => '1.0.3', 'rngSoftwareId' => '1.0.56'],
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        $this->assertSame('40.0000', $this->memberBalance(), '帶關局的重送退款同樣不得入帳');

        $betRecord = PtswBetRecord::query()->where('bet_id', $gameRoundCode)->firstOrFail();
        $this->assertSame('0.0000', (string) $betRecord->bet);
        $this->assertSame(BetRecordStatusEnum::REFUND->value, $betRecord->status, '被拒的關局不得把已退款注單改成 SETTLE');
        $this->assertSame(0, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());
    }

    #[TestDox('settlement：refund-unsettled 命令已補退款事件（applied_at NULL）的注單，再收到 PT 的 REFUND 必須拒絕')]
    #[Group('rules/vendor-ptsw#R-23')]
    public function test_refund_after_recovery_command_refund_is_rejected(): void
    {
        $gameRoundCode = 'live_dup_refund_4';

        // 事故形狀：下注已扣款（deal record applied），PT 端作廢後我方結算一直撞鎖回滾，注單卡在 IN_PROGRESS
        $this->bet(requestId: 'req-dup-bet-4', gameRoundCode: $gameRoundCode, transactionCode: 'tc-dup-bet-4', amount: '10.0', transactionDate: '2026-09-05T15:44:32Z')
            ->assertStatus(200)->assertJsonMissing(['error']);
        $this->assertSame('30.0000', $this->memberBalance());

        $output = $this->runRecoveryRefund(
            betId: $gameRoundCode,
            betTransactionCode: 'tc-dup-bet-4',
            refundTransactionCode: 'tc-dup-refund-4a',
            refundRequestId: 'req-dup-refund-4a',
            refundAmount: '10.0',
        );
        $this->assertStringContainsString('refunded', $output);
        $this->assertSame('40.0000', $this->memberBalance(), '命令已把下注退回');

        $recoveryEvent = PtswDealRecord::query()->where('bet_id', $gameRoundCode)->where('type', 'gameRoundResult')->firstOrFail();
        $this->assertNull($recoveryEvent->applied_at, '命令補的退款事件 applied_at 為 NULL，閘門不得依賴 applied_at');
        $this->assertSame('REFUND', $recoveryEvent->raw['pay']['type'] ?? null);

        $betRecord = PtswBetRecord::query()->where('bet_id', $gameRoundCode)->firstOrFail();
        $this->assertSame(BetRecordStatusEnum::REFUND->value, (int) $betRecord->status);
        $betAfterRecovery = (string) $betRecord->bet;

        // PT 日後補送同一局的 REFUND（新 tc、新 requestId）：既有冪等擋不到，必須由閘門拒絕
        $this->refund(
            requestId: 'req-dup-refund-4b',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-4b',
            relatedTransactionCode: 'tc-dup-bet-4',
            amount: '10.0',
            transactionDate: '2026-09-05T17:56:00Z',
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        $this->assertSame('40.0000', $this->memberBalance(), '命令已退過的下注不得再由回調退一次');

        $betRecord->refresh();
        $this->assertSame($betAfterRecovery, (string) $betRecord->bet, '被拒的退款不得改寫注單金額');
        $this->assertSame(BetRecordStatusEnum::REFUND->value, (int) $betRecord->status);
        $this->assertSame(0, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());
    }

    #[TestDox('settlement：multi-bet 局經 refund-unsettled 命令整局退款後，PT 對命令沒指到的另一筆下注送 REFUND 也必須拒絕')]
    #[Group('rules/vendor-ptsw#R-23')]
    public function test_refund_for_other_bet_after_recovery_refunded_whole_multi_bet_round_is_rejected(): void
    {
        $gameRoundCode = 'live_dup_refund_6';

        $this->bet(requestId: 'req-dup-bet-6a', gameRoundCode: $gameRoundCode, transactionCode: 'tc-dup-bet-6a', amount: '10.0', transactionDate: '2026-09-05T15:44:32Z')
            ->assertStatus(200)->assertJsonMissing(['error']);
        $this->bet(requestId: 'req-dup-bet-6b', gameRoundCode: $gameRoundCode, transactionCode: 'tc-dup-bet-6b', amount: '20.0', transactionDate: '2026-09-05T15:44:35Z')
            ->assertStatus(200)->assertJsonMissing(['error']);
        $this->assertSame('10.0000', $this->memberBalance());

        // 命令以整局 BR.bet（30）退款，但只補一筆 REFUND 事件，relatedTransactionCode 只指到 CSV 給的那筆下注 A
        $output = $this->runRecoveryRefund(
            betId: $gameRoundCode,
            betTransactionCode: 'tc-dup-bet-6a',
            refundTransactionCode: 'tc-dup-refund-6a',
            refundRequestId: 'req-dup-refund-6a',
            refundAmount: '30.0',
        );
        $this->assertStringContainsString('refunded', $output);
        $this->assertSame('40.0000', $this->memberBalance(), '命令已把整局本金退回');

        // PT 對下注 B 送 REFUND：B 的 tc 不在命令補的事件裡，單筆閘比不中，必須由整局級判斷擋下
        $this->refund(
            requestId: 'req-dup-refund-6b',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-6b',
            relatedTransactionCode: 'tc-dup-bet-6b',
            amount: '20.0',
            transactionDate: '2026-09-05T17:56:00Z',
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');
        $this->assertSame('40.0000', $this->memberBalance(), '整局本金已由命令退回，任何一筆下注都不得再退');

        $betRecord = PtswBetRecord::query()->where('bet_id', $gameRoundCode)->firstOrFail();
        $this->assertSame('30.0000', (string) $betRecord->bet, '命令保留原下注金額供稽核，被拒的退款不得改寫');
        $this->assertSame(BetRecordStatusEnum::REFUND->value, (int) $betRecord->status);
        $this->assertSame(0, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());
    }

    #[TestDox('settlement：multi-bet 局每筆下注各退一次都放行，任一筆再退第二次（新 tc）才拒絕')]
    #[Group('rules/vendor-ptsw#R-23')]
    public function test_multi_bet_round_allows_one_refund_per_bet_then_rejects_repeat(): void
    {
        $gameRoundCode = 'live_dup_refund_5';

        $this->bet(requestId: 'req-dup-bet-5a', gameRoundCode: $gameRoundCode, transactionCode: 'tc-dup-bet-5a', amount: '10.0', transactionDate: '2026-09-05T16:30:00Z')
            ->assertStatus(200)->assertJsonMissing(['error']);
        $this->bet(requestId: 'req-dup-bet-5b', gameRoundCode: $gameRoundCode, transactionCode: 'tc-dup-bet-5b', amount: '20.0', transactionDate: '2026-09-05T16:30:05Z')
            ->assertStatus(200)->assertJsonMissing(['error']);
        $this->assertSame('10.0000', $this->memberBalance());

        // PT 取消整局：對 A、B 各送一次 REFUND，第二筆不得被第一筆的退款事件擋掉
        $this->refund(
            requestId: 'req-dup-refund-5a',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-5a',
            relatedTransactionCode: 'tc-dup-bet-5a',
            amount: '10.0',
            transactionDate: '2026-09-05T16:31:00Z',
        )->assertStatus(200)->assertJsonMissing(['error']);
        $this->assertSame('20.0000', $this->memberBalance());

        $this->refund(
            requestId: 'req-dup-refund-5b',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-5b',
            relatedTransactionCode: 'tc-dup-bet-5b',
            amount: '20.0',
            transactionDate: '2026-09-05T16:31:01Z',
        )->assertStatus(200)->assertJsonMissing(['error']);
        $this->assertSame('40.0000', $this->memberBalance(), '同局第二筆下注的退款是合法的，必須放行');

        // A 再退一次（新 tc）：拒絕
        $this->refund(
            requestId: 'req-dup-refund-5c',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-dup-refund-5c',
            relatedTransactionCode: 'tc-dup-bet-5a',
            amount: '10.0',
            transactionDate: '2026-09-05T16:35:00Z',
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');
        $this->assertSame('40.0000', $this->memberBalance(), '已退過的下注不得再退');

        $betRecord = PtswBetRecord::query()->where('bet_id', $gameRoundCode)->firstOrFail();
        $this->assertSame('0.0000', (string) $betRecord->bet);
        $this->assertSame(BetRecordStatusEnum::REFUND->value, $betRecord->status);
    }

    private function bet(string $requestId, string $gameRoundCode, string $transactionCode, string $amount, string $transactionDate): TestResponse
    {
        return $this->postJson("{$this->baseurl}/bet", [
            'requestId' => $requestId,
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => $transactionCode,
            'amount' => $amount,
            'transactionDate' => $transactionDate,
        ]);
    }

    /**
     * @param  array<string, string>|null  $roundClose  帶 gameRoundClose 時 resolveSettlementOutcome 會把 REFUND 覆蓋成 SETTLE
     */
    private function refund(
        string $requestId,
        string $gameRoundCode,
        string $transactionCode,
        string $relatedTransactionCode,
        string $amount,
        string $transactionDate,
        ?array $roundClose = null,
    ): TestResponse {
        $payload = [
            'requestId' => $requestId,
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => $transactionCode,
                'transactionDate' => $transactionDate,
                'amount' => $amount,
                'type' => 'REFUND',
                'relatedTransactionCode' => $relatedTransactionCode,
            ],
        ];
        if ($roundClose !== null) {
            $payload['gameRoundClose'] = $roundClose;
        }

        return $this->postJson("{$this->baseurl}/settlement", $payload);
    }

    /**
     * 以 refund-unsettled 命令的 ptsw profile 對一局執行整局退款（execute 模式），回傳命令輸出供斷言。
     * meta CSV 表頭沿用 docs/reconcile/vendors/PTSW.md 記載的欄位：PT 原始退款回調的 tc／requestId／時間／金額。
     */
    private function runRecoveryRefund(
        string $betId,
        string $betTransactionCode,
        string $refundTransactionCode,
        string $refundRequestId,
        string $refundAmount,
    ): string {
        $headers = ['bet_id', 'bet_tc', 'refund_tc', 'refund_request_id', 'refund_transaction_date', 'round_close_date', 'refund_amount'];
        $cells = [
            $betId, $betTransactionCode, $refundTransactionCode, $refundRequestId,
            '2026-09-05 15:44:37.000', '2026-09-05 15:44:37.000', $refundAmount,
        ];

        $path = sys_get_temp_dir() . '/ptsw_dup_refund_meta_' . Str::random(8) . '.csv';
        file_put_contents($path, implode(',', $headers) . "\n" . implode(',', $cells) . "\n");
        $this->tempFiles[] = $path;

        $exitCode = Artisan::call(self::RECOVERY_CMD, [
            '--vendor' => 'ptsw',
            '--bet-id-file' => $path,
            '--execute' => true,
            '--reason' => '2026-09-05 TPower DDoS，PT 端已作廢、我方結算撞鎖回滾',
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);

        return $output;
    }

    private function memberBalance(): string
    {
        return (string) $this->member->refresh()->balance;
    }
}
