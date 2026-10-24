<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PTSW bet（投注）端點基本行為測試（非 live_ 開頭的一般下注情境）。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswBetTest.php --compact
 */
class PtswBetTest extends PtswTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 見 PtswSettlementTest::setUp() 註解。
        BotNotify::shouldReceive('send')->zeroOrMoreTimes();
    }

    #[TestDox('bet：一般下注（非 live_ 開頭）成功扣款並建立 IN_PROGRESS 的 PtswBetRecord')]
    #[Group('rules/vendor-ptsw#R-12')]
    public function test_bet_success_deducts_balance_and_creates_bet_record(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $betAmount = '30.0';

        $response = $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-bet-normal-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => 'slot_round_001',
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-bet-normal-1',
            'amount' => $betAmount,
            'transactionDate' => '2026-06-01T10:00:00Z',
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissing(['error']);

        $this->assertEquals(1, PtswBetRecord::count());
        $betRecord = PtswBetRecord::first();
        $this->assertEquals('slot_round_001', $betRecord->bet_id);
        $this->assertEquals('ubal', $betRecord->game_code);
        $this->assertEquals(BetRecordStatusEnum::IN_PROGRESS->value, $betRecord->status);
        $this->assertEquals(Common::numAdd('0', $betAmount, 4), $betRecord->bet);
        $this->assertEquals(Common::numAdd('0', $betAmount, 4), $betRecord->valid_bet);
        $this->assertEquals(Common::numMul($betAmount, '-1', 4), $betRecord->win_lose);

        $this->member->refresh();
        $this->assertEquals(
            Common::numSub($initialBalance, $betAmount, 4),
            $this->member->balance,
        );
    }

    #[TestDox('bet：餘額不足時回 ERR_INSUFFICIENT_FUNDS 且不扣款')]
    #[Group('rules/vendor-ptsw#R-11')]
    public function test_bet_insufficient_funds_returns_error_and_does_not_deduct_balance(): void
    {
        $initialBalance = '10.0000';
        $this->member->update(['balance' => $initialBalance]);

        $response = $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-bet-insufficient-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => 'slot_round_insufficient',
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-bet-insuf-1',
            'amount' => '50.0',
            'transactionDate' => '2026-06-01T10:00:00Z',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'requestId' => 'req-bet-insufficient-1',
            'error' => ['code' => 'ERR_INSUFFICIENT_FUNDS'],
        ]);

        $this->member->refresh();
        $this->assertEquals($initialBalance, $this->member->balance, '餘額不足時不應扣款');
    }

    #[TestDox('bet：不支援的 gameCodeName 回 ERR_TRANSACTION_DECLINED 且不建立任何紀錄')]
    #[Group('rules/vendor-ptsw#R-10')]
    public function test_bet_unsupported_game_code_returns_err_transaction_declined(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $response = $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-bet-unsupported-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => 'slot_round_unsupported',
            'gameCodeName' => 'not_a_real_game',
            'transactionCode' => 'tc-bet-unsupported-1',
            'amount' => '30.0',
            'transactionDate' => '2026-06-01T10:00:00Z',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'requestId' => 'req-bet-unsupported-1',
            'error' => ['code' => 'ERR_TRANSACTION_DECLINED'],
        ]);

        $this->assertEquals(0, PtswBetRecord::count(), '遊戲不支援時不應建立 PtswBetRecord');
    }

    #[TestDox('bet：相同 requestId 重送為冪等，不重複扣款也不重複建立紀錄')]
    #[Group('rules/vendor-ptsw#R-13')]
    public function test_bet_duplicate_request_id_is_idempotent(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $betAmount = '30.0';
        $payload = [
            'requestId' => 'req-bet-idem-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => 'slot_round_idem',
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-bet-idem-1',
            'amount' => $betAmount,
            'transactionDate' => '2026-06-01T10:00:00Z',
        ];

        $this->postJson("{$this->baseurl}/bet", $payload)
            ->assertStatus(200)
            ->assertJsonMissing(['error']);

        $this->postJson("{$this->baseurl}/bet", $payload)
            ->assertStatus(200)
            ->assertJsonMissing(['error']);

        $this->assertEquals(1, PtswBetRecord::count(), '相同 requestId 重送不應建立新的 PtswBetRecord');

        $this->member->refresh();
        $this->assertEquals(
            Common::numSub($initialBalance, $betAmount, 4),
            $this->member->balance,
            '相同 requestId 重送不應重複扣款',
        );
    }

    #[TestDox('bet：externalToken 錯誤時回 ERR_AUTHENTICATION_FAILED')]
    #[Group('rules/vendor-ptsw#R-4')]
    public function test_bet_invalid_token_returns_err_authentication_failed(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $response = $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-bet-bad-token',
            'username' => $this->username,
            'externalToken' => 'wrong-token',
            'gameRoundCode' => 'slot_round_bad_token',
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-bet-bad-token',
            'amount' => '30.0',
            'transactionDate' => '2026-06-01T10:00:00Z',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'requestId' => 'req-bet-bad-token',
            'error' => ['code' => 'ERR_AUTHENTICATION_FAILED'],
        ]);

        $this->assertEquals(0, PtswBetRecord::count());
    }
}
